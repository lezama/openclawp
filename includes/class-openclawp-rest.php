<?php
/**
 * REST routes under `openclawp/v1/`.
 *
 * Deliberately minimal: chat is the one verb worth a custom route. Listing
 * agents is server-rendered (the in-process registry is authoritative; an
 * extra network round-trip earns nothing). Listing/deleting sessions is
 * available via the REST API on the `openclawp_session` post type — consumers
 * query `/wp/v2/openclawp-sessions` directly (authenticated, author-scoped).
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Rest {

	private const NAMESPACE = 'openclawp/v1';

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		$permission_callback = array( __CLASS__, 'check_permission' );

		register_rest_route(
			self::NAMESPACE,
			'/chat',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_chat' ),
				'permission_callback' => $permission_callback,
				'args'                => array(
					'agent'      => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_title',
					),
					'message'    => array(
						'type'     => 'string',
						'required' => true,
					),
					'session_id' => array(
						'type'     => array( 'string', 'null' ),
						'required' => false,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/chat/(?P<session_id>[A-Za-z0-9-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_chat' ),
				'permission_callback' => $permission_callback,
			)
		);
	}

	public static function check_permission( WP_REST_Request $request ) {
		$default = current_user_can( 'manage_options' );

		/**
		 * Filters whether the current user may call openclaWP REST routes.
		 *
		 * @param bool            $allowed Default: manage_options.
		 * @param WP_REST_Request $request Current request.
		 */
		$allowed = (bool) apply_filters( 'openclawp_rest_permission_callback', $default, $request );

		if ( ! $allowed ) {
			return new WP_Error(
				'openclawp_forbidden',
				__( 'You do not have permission to use openclaWP.', 'openclawp' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	public static function post_chat( WP_REST_Request $request ) {
		$agent_slug = (string) $request->get_param( 'agent' );
		$message    = (string) $request->get_param( 'message' );
		$session_id = $request->get_param( 'session_id' );
		$session_id = is_string( $session_id ) ? $session_id : null;
		$user_id    = get_current_user_id();

		$result = OpenclaWP_Runner::run_turn( $agent_slug, $message, $session_id, $user_id );

		if ( ! empty( $result['error'] ) ) {
			return new WP_Error(
				'openclawp_chat_failed',
				$result['error'],
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response(
			array(
				'session_id' => $result['session_id'],
				'reply'      => $result['reply'],
				'completed'  => $result['completed'],
			)
		);
	}

	public static function get_chat( WP_REST_Request $request ) {
		$session_id = (string) $request->get_param( 'session_id' );
		$store      = OpenclaWP_Conversation_Store::instance();
		$session    = $store->get_session( $session_id );

		if ( null === $session ) {
			return new WP_Error( 'openclawp_session_not_found', __( 'Session not found.', 'openclawp' ), array( 'status' => 404 ) );
		}

		if ( ! self::current_user_owns( $session ) ) {
			return new WP_Error( 'openclawp_forbidden', __( 'Forbidden.', 'openclawp' ), array( 'status' => 403 ) );
		}

		return rest_ensure_response( $session );
	}

	private static function current_user_owns( array $session ): bool {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return (int) ( $session['user_id'] ?? 0 ) === get_current_user_id();
	}
}
