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

	private array $runtime_context;

	public function __construct( array $name_to_ability, array $delegate_targets = array(), array $runtime_context = array() ) {
		$this->name_to_ability  = $name_to_ability;
		$this->delegate_targets = $delegate_targets;
		$this->runtime_context  = $runtime_context;
	}

	public function executeWP_Agent_Tool_Call( array $tool_call, array $tool_definition, array $context = array() ): array {
		unset( $tool_definition );

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

		$parameters = (array) apply_filters( 'openclawp_tool_parameters', $parameters, $ability_name, $tool_call, $context, $this->runtime_context );

		// Confirmation gate. When the ability's effect crosses the active
		// threshold AND the user hasn't pre-authorised it via "Always allow",
		// we DON'T execute. Instead we emit a structured "awaiting decision"
		// tool result that the agent loop sees as a recoverable failure, and
		// the UI surfaces as a confirmation card. The loop can resume on a
		// follow-up turn (after the user clicks Allow/Deny) by re-running
		// the same tool call — the second pass either finds the always-allow
		// flag set, or carries an `openclawp_decision_override` runtime hint
		// that the gate honours.
		$gate = $this->maybe_gate( $ability_name, $declared_name, $parameters );
		if ( null !== $gate ) {
			return $gate;
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
	 * Decide whether this tool call needs human confirmation. Returns either
	 * `null` (proceed with execution) or a synthetic tool-result that should
	 * stand in for the actual call.
	 *
	 * The synthetic result carries `awaiting_decision: { decision_id, ability,
	 * effect, parameters }` so the chat UI / SSE subscribers / async channels
	 * can render a confirmation card without parsing prose. It deliberately
	 * does NOT block the PHP request — the loop sees a tool failure and can
	 * either reason about it ("I tried to delete the post but need your OK")
	 * or short-circuit on the next turn after the user resolves it.
	 *
	 * @return array<string,mixed>|null
	 */
	private function maybe_gate( string $ability_name, string $declared_name, array $parameters ): ?array {
		if ( ! class_exists( 'OpenclaWP_Tool_Effects' ) ) {
			return null;
		}

		$effect    = OpenclaWP_Tool_Effects::for_ability( $ability_name );
		$threshold = OpenclaWP_Tool_Effects::active_threshold( $this->runtime_context );

		if ( ! OpenclaWP_Tool_Effects::requires_confirmation( $effect, $threshold ) ) {
			return null;
		}

		$user_id = isset( $this->runtime_context['user_id'] )
			? (int) $this->runtime_context['user_id']
			: ( function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0 );

		// `always allow` short-circuits the gate.
		if ( OpenclaWP_Tool_Effects::user_allows_always( $user_id, $ability_name ) ) {
			return null;
		}

		// One-shot override carried by the runtime context — set when the
		// follow-up turn after a user decision wants to let THIS particular
		// invocation through (matched by ability + decision_id).
		$override = $this->runtime_context['openclawp_decision_override'] ?? null;
		if ( is_array( $override ) && ( $override['ability'] ?? '' ) === $ability_name ) {
			return null;
		}

		$session_id = (string) ( $this->runtime_context['session_id'] ?? '' );
		$agent_slug = (string) ( $this->runtime_context['agent_slug'] ?? '' );

		$record = class_exists( 'OpenclaWP_Decisions_Store' )
			? OpenclaWP_Decisions_Store::create_pending(
				array(
					'session_id' => $session_id,
					'user_id'    => $user_id,
					'agent_slug' => $agent_slug,
					'ability'    => $ability_name,
					'effect'     => $effect,
					'threshold'  => $threshold,
					'parameters' => $parameters,
				)
			)
			: null;

		$decision_id = $record['decision_id'] ?? '';

		$awaiting = array(
			'decision_id' => $decision_id,
			'ability'     => $ability_name,
			'effect'      => $effect,
			'threshold'   => $threshold,
			'parameters'  => $parameters,
			'session_id'  => $session_id,
			'agent_slug'  => $agent_slug,
		);

		/**
		 * Fires when a tool call is gated for user confirmation.
		 *
		 * Subscribers can use this to push a card to a chat UI or to send a
		 * numbered-reply message on an async channel (WhatsApp / Telegram).
		 *
		 * @since 0.8.0
		 *
		 * @param array $awaiting Decision payload (see shape above).
		 */
		do_action( 'openclawp_tool_call_gated', $awaiting );

		return array(
			'success'           => false,
			'tool_name'         => $declared_name,
			'error'             => sprintf(
				'awaiting_user_decision: tool "%s" (effect=%s) requires confirmation. The user has been prompted; the call did not run.',
				$ability_name,
				$effect
			),
			'awaiting_decision' => $awaiting,
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
