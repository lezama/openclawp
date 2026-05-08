<?php
/**
 * REST routes for the wacli pairing flow (admin-only).
 *
 * Webhook delivery from wacli itself lives on OpenclaWP_Wacli_Transport;
 * these routes drive the wp-admin UI and require manage_options.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Wacli_Rest {

	private const NAMESPACE = 'openclawp/v1';

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/wacli/state',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_state' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/wacli/connect',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'connect' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/wacli/disconnect',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'disconnect' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/wacli/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_settings' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'update_settings' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
					'args'                => array(
						'agent'        => array( 'type' => 'string' ),
						'allowed_jids' => array( 'type' => 'string' ),
						'binary'       => array( 'type' => 'string' ),
					),
				),
			)
		);
	}

	public static function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	public static function get_state(): WP_REST_Response {
		$state = OpenclaWP_Wacli_Process::get_state();
		// Don't leak the absolute events file path to the browser.
		unset( $state['events_file'] );
		return new WP_REST_Response( $state, 200 );
	}

	public static function connect(): WP_REST_Response {
		$result = OpenclaWP_Wacli_Process::start_auth();
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response(
				array(
					'error'   => $result->get_error_code(),
					'message' => $result->get_error_message(),
				),
				400
			);
		}
		return self::get_state();
	}

	public static function disconnect(): WP_REST_Response {
		OpenclaWP_Wacli_Process::stop();
		return self::get_state();
	}

	public static function get_settings(): WP_REST_Response {
		return new WP_REST_Response( self::settings_snapshot(), 200 );
	}

	public static function update_settings( WP_REST_Request $request ): WP_REST_Response {
		$agents = self::available_agent_slugs();

		if ( null !== $request->get_param( 'agent' ) ) {
			$agent = sanitize_key( (string) $request->get_param( 'agent' ) );
			if ( '' === $agent || in_array( $agent, $agents, true ) ) {
				update_option( 'openclawp_wacli_agent', $agent, false );
			} else {
				return new WP_REST_Response(
					array(
						'error'   => 'unknown_agent',
						'message' => sprintf( 'Agent "%s" is not registered. Available: %s', $agent, implode( ', ', $agents ) ),
					),
					400
				);
			}
		}

		if ( null !== $request->get_param( 'allowed_jids' ) ) {
			$normalized = self::normalize_allowed_jids( (string) $request->get_param( 'allowed_jids' ) );
			update_option( 'openclawp_wacli_allowed_jids', $normalized, false );
		}

		if ( null !== $request->get_param( 'binary' ) ) {
			$binary = trim( (string) $request->get_param( 'binary' ) );
			update_option( 'openclawp_wacli_binary', $binary, false );
		}

		return new WP_REST_Response( self::settings_snapshot(), 200 );
	}

	/**
	 * Trim, split on commas/newlines, drop empties + duplicates, rejoin
	 * comma-separated. Public so unit tests can exercise it directly.
	 */
	public static function normalize_allowed_jids( string $raw ): string {
		$pieces = preg_split( '/[\s,]+/', $raw ) ?: array();
		$pieces = array_values(
			array_unique(
				array_filter(
					array_map( 'trim', $pieces ),
					static fn( $piece ) => '' !== $piece
				)
			)
		);
		return implode( ',', $pieces );
	}

	private static function settings_snapshot(): array {
		return array(
			'agent'             => (string) get_option( 'openclawp_wacli_agent', '' ),
			'allowed_jids'      => (string) get_option( 'openclawp_wacli_allowed_jids', '' ),
			'binary'            => (string) get_option( 'openclawp_wacli_binary', '' ),
			'binary_resolved'   => OpenclaWP_Wacli_Process::resolve_binary(),
			'available_agents'  => self::available_agent_slugs(),
		);
	}

	/**
	 * @return string[]
	 */
	private static function available_agent_slugs(): array {
		if ( ! function_exists( 'wp_get_agents' ) ) {
			return array();
		}
		$slugs = array();
		foreach ( wp_get_agents() as $agent ) {
			if ( method_exists( $agent, 'get_slug' ) ) {
				$slugs[] = $agent->get_slug();
			}
		}
		sort( $slugs );
		return $slugs;
	}
}
