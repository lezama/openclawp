<?php
/**
 * REST routes under /openclawp/v1/.
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
			'/agents',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'list_agents' ),
					'permission_callback' => $permission_callback,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/chat',
			array(
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
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/chat/sessions',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'list_sessions' ),
					'permission_callback' => $permission_callback,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/chat/(?P<session_id>[A-Za-z0-9-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_chat' ),
					'permission_callback' => $permission_callback,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'delete_chat' ),
					'permission_callback' => $permission_callback,
				),
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

	public static function list_agents(): WP_REST_Response {
		$agents = wp_get_agents();
		$out    = array();
		foreach ( $agents as $slug => $agent ) {
			$out[] = array(
				'slug'        => (string) $slug,
				'label'       => $agent instanceof WP_Agent ? $agent->get_label() : (string) $slug,
				'description' => $agent instanceof WP_Agent ? $agent->get_description() : '',
			);
		}
		return rest_ensure_response( $out );
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

		if ( ! self::session_is_visible_to_current_user( $session ) ) {
			return new WP_Error( 'openclawp_forbidden', __( 'Forbidden.', 'openclawp' ), array( 'status' => 403 ) );
		}

		return rest_ensure_response( $session );
	}

	public static function delete_chat( WP_REST_Request $request ) {
		$session_id = (string) $request->get_param( 'session_id' );
		$store      = OpenclaWP_Conversation_Store::instance();
		$session    = $store->get_session( $session_id );

		if ( null !== $session && ! self::session_is_visible_to_current_user( $session ) ) {
			return new WP_Error( 'openclawp_forbidden', __( 'Forbidden.', 'openclawp' ), array( 'status' => 403 ) );
		}

		$ok = $store->delete_session( $session_id );
		return rest_ensure_response( array( 'deleted' => $ok ) );
	}

	public static function list_sessions(): WP_REST_Response {
		$query = new WP_Query(
			array(
				'post_type'              => OpenclaWP_Conversation_Store::POST_TYPE,
				'post_status'            => 'any',
				'author'                 => get_current_user_id(),
				'posts_per_page'         => 50,
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'suppress_filters'       => true,
			)
		);

		$out = array();
		foreach ( $query->posts as $post ) {
			$out[] = array(
				'session_id' => (string) get_post_meta( $post->ID, '_openclawp_session_id', true ),
				'title'      => (string) $post->post_title,
				'agent_slug' => self::resolve_session_agent_slug( $post->ID ),
				'updated_at' => (string) $post->post_modified_gmt,
			);
		}

		return rest_ensure_response( $out );
	}

	private static function resolve_session_agent_slug( int $post_id ): string {
		$metadata_raw = get_post_meta( $post_id, '_openclawp_metadata', true );
		if ( is_string( $metadata_raw ) && '' !== $metadata_raw ) {
			$decoded = json_decode( $metadata_raw, true );
			if ( is_array( $decoded ) && isset( $decoded['agent_slug'] ) ) {
				return (string) $decoded['agent_slug'];
			}
		}
		return '';
	}

	private static function session_is_visible_to_current_user( array $session ): bool {
		$current = get_current_user_id();
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return (int) ( $session['user_id'] ?? 0 ) === $current;
	}
}
