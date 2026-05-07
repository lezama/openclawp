<?php
/**
 * Optional example agent.
 *
 * openclaWP intentionally ships zero default agents. A plugin that wants the
 * smallest possible smoke-test agent for development can opt in:
 *
 *     add_filter( 'openclawp_register_example_agent', '__return_true' );
 *
 * Production installs and any plugin shipping a real agent should leave this
 * filter alone and register agents directly on `wp_agents_api_init` (defined
 * by `agents-api`).
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Agent_Registrar {

	public const EXAMPLE_AGENT_SLUG = 'openclawp-example';

	public static function register(): void {
		add_action( 'wp_agents_api_init', array( __CLASS__, 'maybe_register_example_agent' ), 10 );
	}

	public static function maybe_register_example_agent(): void {
		/**
		 * Whether to register the bundled example agent (`openclawp-example`).
		 *
		 * Off by default. Production installs should leave this off and register
		 * real agents directly on `wp_agents_api_init`.
		 *
		 * @param bool $enabled Default false.
		 */
		if ( ! apply_filters( 'openclawp_register_example_agent', false ) ) {
			return;
		}

		wp_register_agent(
			self::EXAMPLE_AGENT_SLUG,
			array(
				'label'          => __( 'openclaWP Example', 'openclawp' ),
				'description'    => __( 'Bundled example agent for smoke-testing openclaWP. Opt in via the openclawp_register_example_agent filter.', 'openclawp' ),
				'owner_resolver' => static fn(): int => get_current_user_id(),
				'default_config' => array(
					'provider' => 'auto',
					'model'    => 'auto',
				),
				'meta'           => array(
					'source_plugin'  => 'openclawp/openclawp.php',
					'source_type'    => 'example-agent',
					'source_package' => 'lezama/openclawp',
					'source_version' => OPENCLAWP_VERSION,
				),
			)
		);
	}
}
