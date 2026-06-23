<?php
/**
 * REST surface for the in-chat first-run setup wizard.
 *
 *     GET  /openclawp/v1/setup/state
 *       → { completed: bool,
 *           providers: [{ slug, label, installed: bool }],
 *           exampleAgentEnabled: bool }
 *
 *     POST /openclawp/v1/setup/enable-example-agent
 *       Body: { "enabled": true|false }
 *       Toggles the `openclawp_setup_enable_example_agent` option that the
 *       existing PHP wizard bridges into the `openclawp_register_example_agent`
 *       filter.
 *
 *     POST /openclawp/v1/setup/complete
 *       Sets `openclawp_setup_completed = '1'`. Idempotent — calling twice
 *       leaves the option as `'1'`.
 *
 * The card-driven wizard rendered inside `ChatSurface.jsx` walks the same
 * three-step flow as `OpenclaWP_Setup_Wizard` (the PHP fallback) and writes
 * to the same two options, so the wizard surfaces stay in sync however the
 * user landed on them.
 *
 * @package OpenclaWP
 * @since   0.10.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * REST routes that back the in-chat setup wizard cards.
 */
final class OpenclaWP_Setup_Rest {

	private const NAMESPACE = 'openclawp/v1';

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/setup/state',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_state' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/setup/enable-example-agent',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_enable_example_agent' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
				'args'                => array(
					'enabled' => array(
						'type'     => 'boolean',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/setup/complete',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_complete' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
			)
		);
	}

	/**
	 * All routes are gated on `manage_options` — first-run setup is an admin
	 * task. We deliberately don't fan out through `openclawp_rest_permission_callback`
	 * here: opening setup to non-admins doesn't make sense regardless of how
	 * a site's wider permission filter is configured.
	 */
	public static function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Snapshot of setup state the wizard cards render from.
	 *
	 * `providers` mirrors `OpenclaWP_Setup_Wizard::detect_providers()` but
	 * strips the `classes` / `install_url` arrays the card UI doesn't need —
	 * keeping the response narrow makes future provider additions easier.
	 */
	public static function get_state(): WP_REST_Response {
		$providers = array();
		foreach ( OpenclaWP_Setup_Wizard::detect_providers() as $provider ) {
			$providers[] = array(
				'slug'      => (string) $provider['slug'],
				'label'     => (string) $provider['label'],
				'installed' => ! empty( $provider['installed'] ),
			);
		}

		return rest_ensure_response(
			array(
				'completed'           => '1' === (string) get_option( OpenclaWP_Setup_Wizard::OPTION_COMPLETED, '' ),
				'providers'           => $providers,
				'exampleAgentEnabled' => '1' === (string) get_option( OpenclaWP_Setup_Wizard::OPTION_ENABLE_EXAMPLE, '' ),
			)
		);
	}

	/**
	 * Toggle the bundled example agent. The agent registrar reads this option
	 * via the `openclawp_register_example_agent` filter bridged by
	 * `OpenclaWP_Setup_Wizard::filter_register_example_agent()`.
	 */
	public static function post_enable_example_agent( WP_REST_Request $request ): WP_REST_Response {
		$enabled = (bool) $request->get_param( 'enabled' );
		update_option( OpenclaWP_Setup_Wizard::OPTION_ENABLE_EXAMPLE, $enabled ? '1' : '0' );

		return rest_ensure_response(
			array(
				'enabled' => $enabled,
			)
		);
	}

	/**
	 * Mark setup as complete. Mirrors the PHP wizard's "done" step and its
	 * "Skip setup" link — both bounce through here so the welcome notice
	 * disappears regardless of which surface finished setup.
	 */
	public static function post_complete(): WP_REST_Response {
		update_option( OpenclaWP_Setup_Wizard::OPTION_COMPLETED, '1' );

		return rest_ensure_response(
			array(
				'completed' => true,
			)
		);
	}
}
