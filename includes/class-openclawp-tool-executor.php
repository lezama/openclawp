<?php
/**
 * Bridge between the agents-api conversation loop and the WP Abilities API.
 *
 * The loop's tool-mediation path needs an implementation of
 * `WP_Agent_Tool_Executor` to actually run a tool call. We map every call
 * to a registered ability via `wp_get_ability()`. The agent declares which
 * abilities it can invoke via its `default_config['tools']` field; the
 * runner builds the declaration list from there and passes both to the loop.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

use AgentsAPI\AI\Tools\WP_Agent_Tool_Executor;

final class OpenclaWP_Tool_Executor implements WP_Agent_Tool_Executor {

	/**
	 * Maps the simple function name used in declarations (e.g. `get_time`) back
	 * to a fully namespaced ability name (e.g. `openclawp/get_time`). LLM
	 * providers don't accept `/` in function names, so we declare with a
	 * sanitized name and resolve back here.
	 *
	 * @var array<string, string>
	 */
	private array $name_to_ability;

	public function __construct( array $name_to_ability ) {
		$this->name_to_ability = $name_to_ability;
	}

	public function executeWP_Agent_Tool_Call( array $tool_call, array $tool_definition, array $context = array() ): array {
		unset( $tool_definition, $context );

		$declared_name = (string) ( $tool_call['tool_name'] ?? '' );
		$parameters    = isset( $tool_call['parameters'] ) && is_array( $tool_call['parameters'] ) ? $tool_call['parameters'] : array();

		$ability_name = $this->name_to_ability[ $declared_name ] ?? '';
		if ( '' === $ability_name || ! function_exists( 'wp_get_ability' ) ) {
			return array(
				'success'   => false,
				'tool_name' => $declared_name,
				'error'     => sprintf( 'Unknown tool "%s".', $declared_name ),
			);
		}

		$ability = wp_get_ability( $ability_name );
		if ( null === $ability ) {
			return array(
				'success'   => false,
				'tool_name' => $declared_name,
				'error'     => sprintf( 'Ability "%s" is not registered.', $ability_name ),
			);
		}

		$result = $ability->execute( $parameters );
		if ( is_wp_error( $result ) ) {
			return array(
				'success'   => false,
				'tool_name' => $declared_name,
				'error'     => $result->get_error_message(),
			);
		}

		return array(
			'success'   => true,
			'tool_name' => $declared_name,
			'result'    => $result,
		);
	}
}
