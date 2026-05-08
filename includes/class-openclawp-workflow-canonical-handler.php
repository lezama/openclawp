<?php
/**
 * Wires openclaWP as the runtime behind the canonical agents/run-workflow
 * dispatcher.
 *
 * agents-api ships the dispatcher in `Automattic/agents-api` (PR #114) but
 * no runtime — the substrate calls back through the
 * `wp_agent_workflow_handler` filter for actual execution. This class
 * registers a callable that:
 *
 *   1. Resolves the spec — either pulled from {@see OpenclaWP_Workflow_Store}
 *      by `workflow_id`, or constructed inline from the `spec` payload.
 *   2. Runs it via {@see WP_Agent_Workflow_Runner} with the openclaWP
 *      run-recorder wired in for durable run history.
 *   3. Returns the canonical output shape the dispatcher expects.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Runner;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Spec;

final class OpenclaWP_Workflow_Canonical_Handler {

	public static function register(): void {
		add_filter( 'wp_agent_workflow_handler', array( __CLASS__, 'register_handler' ), 10, 2 );
	}

	/**
	 * Filter callback. Returns the openclaWP runtime as the workflow
	 * handler unless an earlier filter already registered one.
	 *
	 * @param callable|null $existing
	 * @param array         $input
	 * @return callable|null
	 */
	public static function register_handler( $existing, array $input ) {
		unset( $input );
		if ( null !== $existing ) {
			return $existing;
		}
		return array( __CLASS__, 'execute' );
	}

	/**
	 * Translate the canonical agents/run-workflow input into a real run.
	 *
	 * @param array $input Canonical input.
	 * @return array|WP_Error Canonical output.
	 */
	public static function execute( array $input ) {
		$spec = self::resolve_spec( $input );
		if ( $spec instanceof WP_Error ) {
			return $spec;
		}

		$inputs  = (array) ( $input['inputs'] ?? array() );
		$options = (array) ( $input['options'] ?? array() );

		$runner = new WP_Agent_Workflow_Runner(
			OpenclaWP_Workflow_Run_Recorder::instance()
		);

		$result = $runner->run( $spec, $inputs, $options );
		return $result->to_array();
	}

	/**
	 * Resolve the spec to run. `workflow_id` first (in-memory registry, then
	 * the persistent store); inline `spec` second; both missing yields a
	 * structured error.
	 *
	 * @param array $input
	 * @return WP_Agent_Workflow_Spec|WP_Error
	 */
	private static function resolve_spec( array $input ) {
		$workflow_id = isset( $input['workflow_id'] ) ? (string) $input['workflow_id'] : '';

		if ( '' !== $workflow_id ) {
			if ( function_exists( 'wp_get_workflow' ) ) {
				$registered = wp_get_workflow( $workflow_id );
				if ( null !== $registered ) {
					return $registered;
				}
			}
			$stored = OpenclaWP_Workflow_Store::instance()->find( $workflow_id );
			if ( null !== $stored ) {
				return $stored;
			}
			return new WP_Error(
				'unknown_workflow',
				sprintf( 'no workflow registered or stored with id `%s`', $workflow_id )
			);
		}

		$inline = $input['spec'] ?? null;
		if ( is_array( $inline ) ) {
			$spec = WP_Agent_Workflow_Spec::from_array( $inline );
			if ( $spec instanceof WP_Error ) {
				return $spec;
			}
			return $spec;
		}

		return new WP_Error(
			'workflow_target_missing',
			'agents/run-workflow input must include either `workflow_id` or `spec`'
		);
	}
}
