<?php
/**
 * REST endpoint for resolving a pending tool-call decision.
 *
 *     POST /openclawp/v1/decisions/<decision_id>
 *       { "action": "allow" | "deny" | "always" }
 *
 * Records the resolution in the audit log, optionally writes an "always
 * allow" entry for (user, ability), and then re-runs the underlying chat
 * turn with a runtime hint (`openclawp_decision_override`) that lets the
 * gated tool call through this one time. The response mirrors the regular
 * chat POST so the chat UI can render the follow-up reply inline.
 *
 * Resume strategy: we don't try to restart the loop mid-call. The loop has
 * already returned (with the "awaiting decision" tool result) by the time
 * the user clicks. We feed the decision back as a new user turn whose body
 * tells the assistant the outcome — e.g. "[The user approved the previous
 * tool call. Continue.]" — and pin the override so the executor doesn't
 * gate the retry.
 *
 * @package OpenclaWP
 * @since   0.8.0
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Decisions_Rest {

	private const NAMESPACE = 'openclawp/v1';

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/decisions/(?P<decision_id>[A-Za-z0-9-]+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_decision' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
				'args'                => array(
					'decision_id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'action'      => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( 'allow', 'deny', 'always' ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/decisions/pending/(?P<session_id>[A-Za-z0-9-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_pending_for_session' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
			)
		);
	}

	public static function get_pending_for_session( WP_REST_Request $request ) {
		$session_id = (string) $request->get_param( 'session_id' );
		if ( '' === $session_id ) {
			return rest_ensure_response( array( 'pending' => null ) );
		}

		$rows = OpenclaWP_Decisions_Store::recent(
			array(
				'status' => OpenclaWP_Decisions_Store::STATUS_PENDING,
				'limit'  => 50,
			)
		);

		foreach ( $rows as $row ) {
			if ( (string) $row['session_id'] === $session_id ) {
				return rest_ensure_response( array( 'pending' => $row ) );
			}
		}

		return rest_ensure_response( array( 'pending' => null ) );
	}

	public static function check_permission( WP_REST_Request $request ) {
		$default = current_user_can( 'manage_options' );
		$allowed = (bool) apply_filters( 'openclawp_rest_permission_callback', $default, $request );
		if ( ! $allowed ) {
			return new WP_Error( 'openclawp_forbidden', __( 'You do not have permission to use openclaWP.', 'openclawp' ), array( 'status' => 403 ) );
		}
		return true;
	}

	public static function post_decision( WP_REST_Request $request ) {
		$decision_id = (string) $request->get_param( 'decision_id' );
		$action      = (string) $request->get_param( 'action' );

		$record = OpenclaWP_Decisions_Store::get( $decision_id );
		if ( null === $record ) {
			return new WP_Error( 'openclawp_decision_not_found', __( 'Decision not found.', 'openclawp' ), array( 'status' => 404 ) );
		}
		if ( OpenclaWP_Decisions_Store::STATUS_PENDING !== $record['status'] ) {
			return new WP_Error(
				'openclawp_decision_already_resolved',
				sprintf(
					/* translators: %s: existing status */
					__( 'Decision was already resolved (%s).', 'openclawp' ),
					$record['status']
				),
				array( 'status' => 409 )
			);
		}

		// Authorise: the user who owns the conversation, or any
		// manage_options admin, can resolve.
		$current_user_id = get_current_user_id();
		if ( ! current_user_can( 'manage_options' ) && (int) $record['user_id'] !== (int) $current_user_id ) {
			return new WP_Error( 'openclawp_forbidden', __( 'Forbidden.', 'openclawp' ), array( 'status' => 403 ) );
		}

		$status_map = array(
			'allow'  => OpenclaWP_Decisions_Store::STATUS_ALLOWED,
			'deny'   => OpenclaWP_Decisions_Store::STATUS_DENIED,
			'always' => OpenclaWP_Decisions_Store::STATUS_ALWAYS,
		);
		$status = $status_map[ $action ] ?? OpenclaWP_Decisions_Store::STATUS_DENIED;

		OpenclaWP_Decisions_Store::resolve( $decision_id, $status, $current_user_id );

		if ( 'always' === $action ) {
			OpenclaWP_Tool_Effects::add_always_allow( (int) $record['user_id'], (string) $record['ability'] );
		}

		// On deny we don't replay the tool — we just inform the agent so
		// it can reason about the rejection and reply to the user.
		if ( 'deny' === $action ) {
			$follow_up_message = sprintf(
				/* translators: %s: ability name */
				__( '[The user denied the previous tool call to %s. Apologise briefly and ask what they would like to do instead.]', 'openclawp' ),
				(string) $record['ability']
			);
			$result = OpenclaWP_Runner::run_turn(
				(string) $record['agent_slug'],
				$follow_up_message,
				(string) $record['session_id'],
				$current_user_id
			);
		} else {
			$follow_up_message = sprintf(
				/* translators: %s: ability name */
				__( '[The user approved the previous tool call. Re-issue the same call to %s and continue.]', 'openclawp' ),
				(string) $record['ability']
			);
			$runtime = array(
				'openclawp_decision_override' => array(
					'decision_id' => $decision_id,
					'ability'     => (string) $record['ability'],
				),
			);
			$result = OpenclaWP_Runner::run_turn(
				(string) $record['agent_slug'],
				$follow_up_message,
				(string) $record['session_id'],
				$current_user_id,
				$runtime
			);
		}

		if ( ! empty( $result['error'] ) ) {
			return new WP_Error( 'openclawp_chat_failed', (string) $result['error'], array( 'status' => 400 ) );
		}

		return rest_ensure_response(
			array(
				'decision_id' => $decision_id,
				'status'      => $status,
				'session_id'  => (string) ( $result['session_id'] ?? $record['session_id'] ),
				'reply'       => (string) ( $result['reply'] ?? '' ),
				'completed'   => (bool) ( $result['completed'] ?? true ),
			)
		);
	}
}
