<?php
/**
 * Agent registrar.
 *
 * Hooks `wp_agents_api_init` and registers the bundled example agent. Downstream
 * plugins should add their own `wp_agents_api_init` callbacks (calling
 * `wp_register_agent()` directly) — the `openclawp_register_agents` action
 * exposed here is a convenience that fires inside the same hook.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Agent_Registrar {

	public static function register(): void {
		add_action( 'wp_agents_api_init', array( __CLASS__, 'register_default_agent' ), 10 );
		add_action( 'wp_agents_api_init', array( __CLASS__, 'fire_downstream_hook' ), 20 );
	}

	public static function register_default_agent(): void {
		wp_register_agent(
			'openclawp-default',
			array(
				'label'          => __( 'openclaWP Default', 'openclawp' ),
				'description'    => __( 'Default openclaWP example agent. Replaceable; use the openclawp_register_agents action to add your own.', 'openclawp' ),
				'owner_resolver' => static fn(): int => get_current_user_id(),
				'default_config' => array(
					'provider' => 'auto',
					'model'    => 'auto',
				),
				'meta'           => array(
					'source_plugin'  => 'openclawp/openclawp.php',
					'source_type'    => 'bundled-agent',
					'source_package' => 'automattic/openclawp',
					'source_version' => OPENCLAWP_VERSION,
				),
			)
		);
	}

	/**
	 * Convenience action that lets downstream code register agents without a separate
	 * add_action( 'wp_agents_api_init', ... ) hookup.
	 */
	public static function fire_downstream_hook(): void {
		/**
		 * Fires inside `wp_agents_api_init`. Call `wp_register_agent()` from here.
		 */
		do_action( 'openclawp_register_agents' );
	}
}
