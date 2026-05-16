<?php
/**
 * Optional demo routine registration.
 *
 * Mirrors the agent / workflow registrars: openclaWP ships zero default
 * routines, but admins who want a smoke-test routine can opt in:
 *
 *     add_filter( 'openclawp_register_example_routine', '__return_true' );
 *
 * The bundled routine wakes a registered agent every 5 minutes with a
 * stable session id, so wakes accumulate context the way a real routine
 * would. Best paired with the coordinator demo so the routine exercises
 * the subagent dispatch path:
 *
 *     add_filter( 'openclawp_register_coordinator_demo',     '__return_true' );
 *     add_filter( 'openclawp_register_loop_demo',             '__return_true' );
 *     add_filter( 'openclawp_register_site_introspection',    '__return_true' );
 *     add_filter( 'openclawp_register_example_routine',       '__return_true' );
 *
 * The routine targets the example agent by default; set the
 * `openclawp_example_routine_agent_slug` filter to point it elsewhere.
 *
 * @package OpenclaWP
 * @since   0.6.0
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Routine_Registrar {

	public const EXAMPLE_ROUTINE_ID = 'openclawp-routine-demo';

	public static function register(): void {
		// Routines depend on agents being registered, so run after
		// `wp_agents_api_init`. Plugin boot hits `init` early enough.
		add_action( 'init', array( __CLASS__, 'maybe_register_example_routine' ), 30 );
	}

	public static function maybe_register_example_routine(): void {
		/**
		 * Whether to register the bundled example routine.
		 *
		 * Off by default. Production installs should leave this off and
		 * register real routines via `wp_register_routine()` instead.
		 *
		 * @since 0.6.0
		 *
		 * @param bool $enabled Default false.
		 */
		if ( ! apply_filters( 'openclawp_register_example_routine', false ) ) {
			return;
		}

		if ( ! function_exists( 'wp_register_routine' ) ) {
			return;
		}
		if ( function_exists( 'wp_get_routine' ) && null !== wp_get_routine( self::EXAMPLE_ROUTINE_ID ) ) {
			return;
		}

		/**
		 * Filter the agent slug the example routine targets. Default is the
		 * coordinator if registered, otherwise the example agent — gives
		 * the demo a richer payload when the coordinator is on without
		 * forcing it on.
		 *
		 * @since 0.6.0
		 *
		 * @param string $slug
		 */
		$default_slug = function_exists( 'wp_get_agent' ) && null !== wp_get_agent( OpenclaWP_Agent_Registrar::COORDINATOR_AGENT_SLUG )
			? OpenclaWP_Agent_Registrar::COORDINATOR_AGENT_SLUG
			: OpenclaWP_Agent_Registrar::EXAMPLE_AGENT_SLUG;
		$agent_slug   = (string) apply_filters( 'openclawp_example_routine_agent_slug', $default_slug );

		wp_register_routine(
			self::EXAMPLE_ROUTINE_ID,
			array(
				'label'    => __( 'openclaWP Demo Routine', 'openclawp' ),
				'agent'    => $agent_slug,
				'interval' => 300,
				'prompt'   => __(
					'Wake check. Briefly note anything new since your last response. If nothing has changed, say so in one sentence.',
					'openclawp'
				),
				'meta'     => array(
					'source_plugin'  => 'openclawp/openclawp.php',
					'source_type'    => 'example-routine',
					'source_package' => 'lezama/openclawp',
					'source_version' => OPENCLAWP_VERSION,
				),
			)
		);
	}
}
