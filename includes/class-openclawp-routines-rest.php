<?php
/**
 * REST endpoint backing the wp-admin → openclaWP → Routines DataView.
 *
 * For each registered routine ({@see \AgentsAPI\AI\Routines\WP_Agent_Routine}),
 * surfaces:
 *   - the declarative bits (label, agent, trigger, prompt, session_id)
 *   - the next scheduled wake (from Action Scheduler, group `agents-api`)
 *   - the most recent completed wake (status + scheduled_at).
 *
 * Read-only. Run-now / pause / resume row actions land alongside their
 * matching REST endpoints and a permission story.
 *
 * @package OpenclaWP
 * @since   0.6.0
 */

defined( 'ABSPATH' ) || exit;

use AgentsAPI\AI\Routines\WP_Agent_Routine;
use AgentsAPI\AI\Routines\WP_Agent_Routine_Action_Scheduler_Bridge;

final class OpenclaWP_Routines_Rest {

	private const NAMESPACE = 'openclawp/v1';

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/routines',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_routines' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);
	}

	public static function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	public static function list_routines(): WP_REST_Response {
		if ( ! function_exists( 'wp_get_routines' ) ) {
			return new WP_REST_Response(
				array(
					'routines'         => array(),
					'action_scheduler' => false,
					'message'          => 'Routines substrate is not loaded. Update agents-api to a release that ships the Routines primitives.',
				),
				200
			);
		}

		$as_available = WP_Agent_Routine_Action_Scheduler_Bridge::is_available();
		$rows         = array();

		foreach ( wp_get_routines() as $routine ) {
			$rows[] = self::shape_routine( $routine, $as_available );
		}

		return new WP_REST_Response(
			array(
				'routines'         => $rows,
				'action_scheduler' => $as_available,
			),
			200
		);
	}

	/**
	 * Project a routine + its AS timing into a JSON-friendly row.
	 */
	private static function shape_routine( WP_Agent_Routine $routine, bool $as_available ): array {
		$next_wake_at  = 0;
		$last_run      = null;

		if ( $as_available ) {
			$args = array( 'routine_id' => $routine->get_id() );

			$pending = as_get_scheduled_actions(
				array(
					'hook'     => WP_Agent_Routine_Action_Scheduler_Bridge::SCHEDULED_HOOK,
					'group'    => WP_Agent_Routine_Action_Scheduler_Bridge::GROUP,
					'status'   => 'pending',
					'args'     => $args,
					'per_page' => 1,
					'orderby'  => 'date',
					'order'    => 'ASC',
				)
			);
			foreach ( $pending as $action ) {
				$schedule = is_object( $action ) && method_exists( $action, 'get_schedule' ) ? $action->get_schedule() : null;
				$date     = $schedule && is_callable( array( $schedule, 'get_date' ) ) ? $schedule->get_date() : null;
				if ( $date instanceof DateTimeInterface ) {
					$next_wake_at = $date->getTimestamp();
				}
				break;
			}

			// Most-recent completed (or failed) action gives us "last run".
			foreach ( array( 'complete', 'failed' ) as $terminal_status ) {
				$recent = as_get_scheduled_actions(
					array(
						'hook'     => WP_Agent_Routine_Action_Scheduler_Bridge::SCHEDULED_HOOK,
						'group'    => WP_Agent_Routine_Action_Scheduler_Bridge::GROUP,
						'status'   => $terminal_status,
						'args'     => $args,
						'per_page' => 1,
						'orderby'  => 'date',
						'order'    => 'DESC',
					)
				);
				foreach ( $recent as $action ) {
					$schedule = is_object( $action ) && method_exists( $action, 'get_schedule' ) ? $action->get_schedule() : null;
					$date     = $schedule && is_callable( array( $schedule, 'get_date' ) ) ? $schedule->get_date() : null;
					$at       = $date instanceof DateTimeInterface ? $date->getTimestamp() : 0;
					if ( null === $last_run || $at > ( $last_run['at'] ?? 0 ) ) {
						$last_run = array(
							'status' => $terminal_status,
							'at'     => $at,
							'at_iso' => 0 === $at ? '' : gmdate( 'c', $at ),
						);
					}
					break;
				}
			}
		}

		$trigger = WP_Agent_Routine::TRIGGER_INTERVAL === $routine->get_trigger_type()
			? array( 'type' => 'interval', 'value' => $routine->get_interval_seconds() )
			: array( 'type' => 'expression', 'value' => $routine->get_expression() );

		return array(
			'id'             => $routine->get_id(),
			'label'          => $routine->get_label(),
			'agent'          => $routine->get_agent_slug(),
			'session_id'     => $routine->get_session_id(),
			'prompt'         => $routine->get_prompt(),
			'trigger'        => $trigger,
			'next_wake_at'   => $next_wake_at,
			'next_wake_iso'  => 0 === $next_wake_at ? '' : gmdate( 'c', $next_wake_at ),
			'last_run'       => $last_run,
		);
	}
}
