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
		add_action( 'wp_agents_api_init', array( __CLASS__, 'maybe_register_loop_demo_agent' ), 10 );
		add_action( 'wp_agents_api_init', array( __CLASS__, 'maybe_register_site_introspection_agent' ), 10 );
	}

	public static function maybe_register_site_introspection_agent(): void {
		// Reuses the same opt-in filter as the site-introspection abilities so they
		// ship together — registering the agent without the abilities would be useless.
		if ( ! apply_filters( 'openclawp_register_site_introspection', false ) ) {
			return;
		}

		wp_register_agent(
			'openclawp-site-introspection',
			array(
				'label'          => __( 'openclaWP Site Introspection', 'openclawp' ),
				'description'    => __(
					'You are a helpful assistant that answers questions about this WordPress site. You have read-only access to four tools: openclawp__get-recent-posts (recent published posts), openclawp__count-comments (comment moderation totals), openclawp__get-active-plugins (currently active plugins), and openclawp__get-current-user (the human you are talking to). Always call the relevant tool before answering a factual question — never guess. Quote tool output values directly. Be concise.',
					'openclawp'
				),
				'owner_resolver' => static fn(): int => get_current_user_id(),
				'default_config' => array(
					'provider'  => 'auto',
					'model'     => 'auto',
					'tools'     => array(
						'openclawp/get-recent-posts',
						'openclawp/count-comments',
						'openclawp/get-active-plugins',
						'openclawp/get-current-user',
					),
					'max_turns' => 6,
				),
				'meta'           => array(
					'source_plugin'  => 'openclawp/openclawp.php',
					'source_type'    => 'site-introspection-demo-agent',
					'source_package' => 'lezama/openclawp',
					'source_version' => OPENCLAWP_VERSION,
				),
			)
		);
	}

	public static function maybe_register_loop_demo_agent(): void {
		// Reuses the same opt-in filter as the get_time ability so they ship
		// together — there's no point in registering one without the other.
		if ( ! apply_filters( 'openclawp_register_loop_demo', false ) ) {
			return;
		}

		wp_register_agent(
			'openclawp-loop-demo',
			array(
				'label'          => __( 'openclaWP Loop Demo', 'openclawp' ),
				'description'    => __(
					'You are a precise assistant. You have access to one tool: openclawp__get-time, which returns the current time. When the user asks for the time, the current date, or anything time-related, you MUST call openclawp__get-time first and use its result in your reply. Never guess the time.',
					'openclawp'
				),
				'owner_resolver' => static fn(): int => get_current_user_id(),
				'default_config' => array(
					'provider'  => 'auto',
					'model'     => 'auto',
					'tools'     => array( 'openclawp/get-time' ),
					'max_turns' => 5,
				),
				'meta'           => array(
					'source_plugin'  => 'openclawp/openclawp.php',
					'source_type'    => 'loop-demo-agent',
					'source_package' => 'lezama/openclawp',
					'source_version' => OPENCLAWP_VERSION,
				),
			)
		);
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
