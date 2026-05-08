<?php
/**
 * Workflow surface bootstrap.
 *
 * Registers the openclaWP CPT-backed Store + Run_Recorder, wires the
 * canonical `agents/run-workflow` dispatcher, and (optionally) registers
 * the bundled example workflow behind an opt-in filter.
 *
 * Called from {@see OpenclaWP_Bootstrap::init()} so the agents-api
 * substrate (`Automattic/agents-api` ≥ 0.103.0) is guaranteed loaded.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Workflow_Bootstrap {

	public static function register(): void {
		// Substrate may not be available on older agents-api versions —
		// skip cleanly so the rest of openclaWP keeps working.
		if ( ! class_exists( 'AgentsAPI\\AI\\Workflows\\WP_Agent_Workflow_Runner' ) ) {
			return;
		}

		add_action( 'init', array( 'OpenclaWP_Workflow_Store', 'register_post_type' ), 5 );
		add_action( 'init', array( 'OpenclaWP_Workflow_Run_Recorder', 'register_post_type' ), 5 );

		OpenclaWP_Workflow_Canonical_Handler::register();
		OpenclaWP_Workflow_Rest::register();

		if ( is_admin() ) {
			OpenclaWP_Workflow_Admin::register();
		}

		// The bundled example workflow targets the site-introspection agent
		// (it's the only registered agent with tools attached). Auto-pull
		// the matching abilities + agent in when the workflow filter is on
		// — otherwise the workflow runs but the agent has nothing to call.
		if ( apply_filters( 'openclawp_register_example_workflow', false ) ) {
			add_filter( 'openclawp_register_site_introspection', '__return_true' );
		}

		add_action( 'wp_agents_api_init', array( __CLASS__, 'maybe_register_example_workflow' ) );
	}

	/**
	 * Register the bundled example workflow when opted in.
	 *
	 * Targets `openclawp-site-introspection` (the agent that ships with
	 * read-only tools wired up). One agent step, on-demand trigger,
	 * returns a one-paragraph site status report. Useful as a smoke
	 * target — running it kicks off agents/chat with real tool calls
	 * and writes a row to the run recorder.
	 *
	 *     wp_get_ability( 'agents/run-workflow' )->execute(
	 *         array( 'workflow_id' => 'openclawp/site-summary' )
	 *     );
	 *
	 * Enabling this filter also turns on
	 * `openclawp_register_site_introspection` (see {@see register()}) so
	 * the target agent + its tool abilities are present.
	 */
	public static function maybe_register_example_workflow(): void {
		/**
		 * Whether to register the bundled `openclawp/site-summary`
		 * example workflow. Off by default — turn it on when you want
		 * a working end-to-end smoke target. Auto-pulls the matching
		 * site-introspection agent + abilities so the demo works
		 * out of the box.
		 *
		 * @since 0.2.0
		 *
		 * @param bool $enabled Default false.
		 */
		if ( ! apply_filters( 'openclawp_register_example_workflow', false ) ) {
			return;
		}
		if ( ! function_exists( 'wp_register_workflow' ) ) {
			return;
		}

		wp_register_workflow(
			array(
				'id'       => 'openclawp/site-summary',
				'version'  => '1.0.0',
				'inputs'   => array(
					'focus' => array(
						'type'        => 'string',
						'required'    => false,
						'description' => 'Optional aspect to emphasise (recent posts, comment moderation, plugins).',
					),
				),
				'steps'    => array(
					array(
						'id'      => 'summarize',
						'type'    => 'agent',
						'agent'   => 'openclawp-site-introspection',
						'message' => 'Give me a one-paragraph status update of this site${inputs.focus}. Use the read-only tools you have access to before answering — never guess.',
					),
				),
				'triggers' => array(
					array( 'type' => 'on_demand' ),
				),
				'meta'     => array(
					'source_plugin' => 'openclawp/openclawp.php',
					'source_type'   => 'example-workflow',
				),
			)
		);
	}
}
