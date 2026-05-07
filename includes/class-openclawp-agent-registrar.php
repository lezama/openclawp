<?php
/**
 * Agent registrar.
 *
 * Hooks `wp_agents_api_init` and registers the bundled example agent. Downstream
 * plugins register their own agents the same way — by hooking
 * `wp_agents_api_init` (defined by `agents-api`, not by this plugin) and calling
 * `wp_register_agent()`. openclaWP intentionally exposes no agent-registration
 * hook of its own: doing so would couple downstream plugins to this consumer
 * instead of to the substrate.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Agent_Registrar {

	public static function register(): void {
		add_action( 'wp_agents_api_init', array( __CLASS__, 'register_default_agent' ), 10 );
	}

	public static function register_default_agent(): void {
		wp_register_agent(
			'openclawp-default',
			array(
				'label'          => __( 'openclaWP Default', 'openclawp' ),
				'description'    => __( 'Default openclaWP example agent. Replaceable; hook wp_agents_api_init in your own plugin and register your own agents.', 'openclawp' ),
				'owner_resolver' => static fn(): int => get_current_user_id(),
				'default_config' => array(
					'provider' => 'auto',
					'model'    => 'auto',
				),
				'meta'           => array(
					'source_plugin'  => 'openclawp/openclawp.php',
					'source_type'    => 'bundled-agent',
					'source_package' => 'lezama/openclawp',
					'source_version' => OPENCLAWP_VERSION,
				),
			)
		);
	}
}
