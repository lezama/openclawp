<?php
/**
 * REST endpoint backing the wp-admin → openclaWP → Tasks DataView.
 *
 * Read-only window into Action Scheduler's queue, scoped to the groups
 * agents-api / openclaWP register actions in. We deliberately don't expose
 * AS's full administrative surface (cancel, retry, run-now) — those land
 * once the UI calls for them and we agree on a permission model.
 *
 * @package OpenclaWP
 * @since   0.5.0
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Tasks_Rest {

	private const NAMESPACE = 'openclawp/v1';

	/**
	 * Action Scheduler groups we care about. Anything outside these stays
	 * invisible — admins who want a global queue view should use the
	 * built-in Tools → Scheduled Actions page.
	 */
	private const GROUPS = array( 'agents-api', 'openclawp' );

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/tasks',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_tasks' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'status'   => array(
						'type'        => 'string',
						'description' => 'Filter by AS action status (pending, in-progress, complete, failed, canceled). Empty = all.',
						'default'     => '',
					),
					'per_page' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 200,
						'default' => 50,
					),
				),
			)
		);
	}

	public static function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	public static function list_tasks( WP_REST_Request $request ): WP_REST_Response {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return new WP_REST_Response(
				array(
					'tasks'             => array(),
					'action_scheduler'  => false,
					'message'           => 'Action Scheduler is not loaded. Cron-triggered workflows and queued tasks need it — install it via composer or activate WooCommerce.',
				),
				200
			);
		}

		$status   = (string) $request->get_param( 'status' );
		$per_page = (int) $request->get_param( 'per_page' );

		// `as_get_scheduled_actions` accepts a `group` query param but only
		// matches one group at a time. Iterate our two groups, merge,
		// re-sort by scheduled date.
		$rows = array();
		foreach ( self::GROUPS as $group ) {
			$query = array(
				'group'    => $group,
				'per_page' => $per_page,
				'orderby'  => 'date',
				'order'    => 'DESC',
			);
			if ( '' !== $status ) {
				$query['status'] = $status;
			}

			$actions = as_get_scheduled_actions( $query );
			foreach ( $actions as $action_id => $action ) {
				$rows[] = self::shape_action( (int) $action_id, $action );
			}
		}

		// Most-recent-first across both groups.
		usort(
			$rows,
			static function ( $a, $b ) {
				return ( $b['scheduled_at'] ?? 0 ) <=> ( $a['scheduled_at'] ?? 0 );
			}
		);

		// Apply per_page cap after merge — each group already returned up to
		// per_page rows, so the union may exceed it.
		if ( count( $rows ) > $per_page ) {
			$rows = array_slice( $rows, 0, $per_page );
		}

		return new WP_REST_Response(
			array(
				'tasks'            => $rows,
				'action_scheduler' => true,
				'groups'           => self::GROUPS,
			),
			200
		);
	}

	/**
	 * Project an `ActionScheduler_Action` instance into a JSON-serialisable
	 * array. AS's object model is rich (schedule, args, claims, logs);
	 * we expose only what the DataView needs.
	 *
	 * @param int   $action_id
	 * @param mixed $action    `ActionScheduler_Action`-shaped instance.
	 * @return array<string,mixed>
	 */
	private static function shape_action( int $action_id, $action ): array {
		$status = '';
		if ( function_exists( 'as_get_datastore' ) && is_callable( array( ActionScheduler::store(), 'get_status' ) ) ) {
			try {
				$status = (string) ActionScheduler::store()->get_status( $action_id );
			} catch ( \Throwable $e ) {
				$status = '';
			}
		}

		$hook         = is_object( $action ) && method_exists( $action, 'get_hook' ) ? (string) $action->get_hook() : '';
		$group        = is_object( $action ) && method_exists( $action, 'get_group' ) ? (string) $action->get_group() : '';
		$args         = is_object( $action ) && method_exists( $action, 'get_args' ) ? (array) $action->get_args() : array();
		$schedule     = is_object( $action ) && method_exists( $action, 'get_schedule' ) ? $action->get_schedule() : null;
		$next         = $schedule && is_callable( array( $schedule, 'get_date' ) ) ? $schedule->get_date() : null;
		$scheduled_at = $next instanceof DateTimeInterface ? $next->getTimestamp() : 0;

		// Recurring schedules expose their interval; one-off actions do not.
		// Reading both protects against API drift between AS versions.
		$recurring = false;
		$interval  = 0;
		if ( $schedule && is_callable( array( $schedule, 'is_recurring' ) ) ) {
			$recurring = (bool) $schedule->is_recurring();
		}
		if ( $schedule && is_callable( array( $schedule, 'get_recurrence' ) ) ) {
			$interval = (int) $schedule->get_recurrence();
		}

		return array(
			'id'           => $action_id,
			'hook'         => $hook,
			'group'        => $group,
			'status'       => $status,
			'args'         => $args,
			'scheduled_at' => $scheduled_at,
			'scheduled_iso' => 0 === $scheduled_at ? '' : gmdate( 'c', $scheduled_at ),
			'recurring'    => $recurring,
			'interval_s'   => $interval,
		);
	}
}
