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

	/**
	 * Maps a `delegate-to-<slug>` declaration name to the subagent slug it
	 * dispatches to. Populated by {@see OpenclaWP_Tools_Resolver} when the
	 * parent agent declares `subagents`.
	 *
	 * @var array<string, string>
	 */
	private array $delegate_targets;

	public function __construct( array $name_to_ability, array $delegate_targets = array() ) {
		$this->name_to_ability  = $name_to_ability;
		$this->delegate_targets = $delegate_targets;
	}

	public function executeWP_Agent_Tool_Call( array $tool_call, array $tool_definition, array $context = array() ): array {
		unset( $tool_definition, $context );

		$declared_name = (string) ( $tool_call['tool_name'] ?? '' );
		$parameters    = isset( $tool_call['parameters'] ) && is_array( $tool_call['parameters'] ) ? $tool_call['parameters'] : array();

		// Subagent delegation takes precedence — a coordinator agent's
		// tool list is its delegate-to-<slug> entries plus whatever
		// abilities it declares directly. Both go through this executor.
		if ( isset( $this->delegate_targets[ $declared_name ] ) ) {
			return $this->execute_delegation( $declared_name, $this->delegate_targets[ $declared_name ], $parameters );
		}

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

	/**
	 * Dispatch a coordinator's tool call into the subagent's chat session.
	 *
	 * The subagent runs in its own ephemeral session — we don't reuse the
	 * coordinator's session id because the conversational context is the
	 * coordinator's, not the subagent's. The subagent receives the
	 * `prompt` parameter as its user turn and returns its assistant
	 * reply, which becomes this tool's result. Future revisions may
	 * support a `session_id` arg to pin the subagent to a long-lived
	 * thread for shared context.
	 *
	 * @param string $declared_name  The sanitised `delegate-to-<slug>` name.
	 * @param string $subagent_slug  The actual subagent slug.
	 * @param array  $parameters     Tool-call parameters from the LLM.
	 * @return array<string,mixed>
	 */
	private function execute_delegation( string $declared_name, string $subagent_slug, array $parameters ): array {
		$prompt = isset( $parameters['prompt'] ) ? trim( (string) $parameters['prompt'] ) : '';
		if ( '' === $prompt ) {
			return array(
				'success'   => false,
				'tool_name' => $declared_name,
				'error'     => 'Subagent delegation requires a non-empty `prompt`.',
			);
		}

		if ( ! function_exists( 'wp_get_ability' ) ) {
			return array(
				'success'   => false,
				'tool_name' => $declared_name,
				'error'     => 'Abilities API is unavailable.',
			);
		}

		$chat = wp_get_ability( 'agents/chat' );
		if ( null === $chat ) {
			return array(
				'success'   => false,
				'tool_name' => $declared_name,
				'error'     => 'agents/chat ability is not registered; cannot delegate to subagent.',
			);
		}

		// Permission widening: the parent's loop is already running inside
		// an authorised request (REST nonce, HMAC webhook, scheduled
		// dispatch). The subagent call is internal — re-running the
		// `manage_options` gate would gate-fail when the parent runs as
		// the cron user. Same pattern as the routine listener.
		$grant = static fn() => true;
		add_filter( 'agents_chat_permission', $grant );
		add_filter( 'openclawp_chat_ability_permission', $grant );
		try {
			$result = $chat->execute(
				array(
					'agent'      => $subagent_slug,
					'message'    => $prompt,
					// Subagent gets a fresh session per delegation — ephemeral
					// by design until we have a story for shared context.
					'session_id' => null,
				)
			);
		} finally {
			remove_filter( 'openclawp_chat_ability_permission', $grant );
			remove_filter( 'agents_chat_permission', $grant );
		}

		if ( is_wp_error( $result ) ) {
			return array(
				'success'   => false,
				'tool_name' => $declared_name,
				'error'     => sprintf( 'Subagent %s failed: %s', $subagent_slug, $result->get_error_message() ),
			);
		}

		$reply = isset( $result['reply'] ) ? (string) $result['reply'] : '';
		return array(
			'success'   => true,
			'tool_name' => $declared_name,
			'result'    => array(
				'subagent'   => $subagent_slug,
				'reply'      => $reply,
				'session_id' => $result['session_id'] ?? '',
			),
		);
	}
}
