<?php
/**
 * Natural-language → workflow spec drafter.
 *
 * Takes a one-line description of what a workflow should do, calls the
 * canonical agents/chat dispatcher with a system prompt that explains the
 * spec contract and lists the abilities + agents available on this site,
 * extracts the JSON spec from the assistant's reply, and validates it via
 * the substrate's structural validator. Returns the spec + the
 * assistant's explanation alongside the raw reply for debugging.
 *
 * No state. Pure service class invoked from REST.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Spec;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Spec_Validator;

final class OpenclaWP_Workflow_Drafter {

	/**
	 * Draft a workflow from a natural-language prompt.
	 *
	 * @since 0.4.0
	 *
	 * @param string $prompt User-supplied description of the workflow.
	 * @param string $agent  Slug of the agent to use as the drafter's
	 *                       reasoning runtime. Empty falls back to the
	 *                       first available agent.
	 * @return array{spec:array,explanation:string,raw:string}|WP_Error
	 */
	public static function draft( string $prompt, string $agent = '' ) {
		$prompt = trim( $prompt );
		if ( '' === $prompt ) {
			return new WP_Error( 'empty_prompt', 'Drafter prompt is empty.' );
		}

		// Default to the bundled drafter agent. Caller can override per-call.
		if ( '' === $agent ) {
			$agent = OpenclaWP_Agent_Registrar::DRAFTER_AGENT_SLUG;
		}

		if ( ! function_exists( 'wp_get_ability' ) ) {
			return new WP_Error( 'abilities_api_missing', 'Abilities API is not loaded.' );
		}
		if ( ! function_exists( 'wp_get_agent' ) || null === wp_get_agent( $agent ) ) {
			return new WP_Error(
				'unknown_drafter_agent',
				sprintf( 'Drafter agent `%s` is not registered.', $agent )
			);
		}
		$chat = wp_get_ability( 'agents/chat' );
		if ( null === $chat ) {
			return new WP_Error( 'agents_chat_missing', 'agents/chat ability is not registered.' );
		}

		// The agent's `description` carries the workflow contract; we only
		// pass the user's intent + dynamic site discovery (abilities and
		// agents currently registered on this site) so the drafter can pick
		// real slugs instead of guessing.
		$user_turn = self::build_user_message( $prompt );

		$result = $chat->execute(
			array(
				'agent'      => $agent,
				'message'    => $user_turn,
				'session_id' => null,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$reply = (string) ( $result['reply'] ?? '' );
		if ( '' === $reply ) {
			return new WP_Error( 'empty_reply', 'Drafter agent returned an empty reply.' );
		}

		$json = self::extract_json_block( $reply );
		if ( null === $json ) {
			return new WP_Error(
				'no_json_found',
				'Drafter reply did not contain a JSON code fence.',
				array( 'reply' => $reply )
			);
		}

		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error(
				'invalid_json',
				'Drafter returned malformed JSON: ' . json_last_error_msg(),
				array( 'json' => $json, 'reply' => $reply )
			);
		}

		$errors = WP_Agent_Workflow_Spec_Validator::validate( $decoded );
		if ( ! empty( $errors ) ) {
			return new WP_Error(
				'invalid_spec',
				sprintf( 'Drafter produced an invalid spec: %s', $errors[0]['message'] ),
				array(
					'errors' => $errors,
					'spec'   => $decoded,
					'reply'  => $reply,
				)
			);
		}

		return array(
			'spec'        => $decoded,
			'explanation' => self::extract_explanation( $reply ),
			'raw'         => $reply,
		);
	}

	/**
	 * Build the user-side message: the dynamic site discovery (registered
	 * abilities + agents) followed by the user's natural-language prompt.
	 * The static spec contract lives in the agent's `description`.
	 */
	private static function build_user_message( string $prompt ): string {
		$abilities = self::list_abilities();
		$agents    = self::list_agents();

		$abilities_block = $abilities
			? "Available abilities (use these slugs in `ability` steps):\n- " . implode( "\n- ", $abilities )
			: 'No abilities are registered yet — use a placeholder like `my-plugin/my-ability` and call it out.';

		$agents_block = $agents
			? "Available agents (use these slugs in `agent` steps):\n- " . implode( "\n- ", $agents )
			: 'No agents are registered yet — use a placeholder like `my-plugin/my-agent`.';

		return <<<MSG
{$abilities_block}

{$agents_block}

---

Workflow description:
{$prompt}
MSG;
	}

	/**
	 * Pull a JSON object out of the assistant's reply. Tolerates either a
	 * fenced ```json block or a raw JSON object.
	 */
	private static function extract_json_block( string $reply ): ?string {
		if ( preg_match( '/```json\s*(.+?)```/is', $reply, $m ) ) {
			return trim( $m[1] );
		}
		if ( preg_match( '/```\s*(\{.+?\})\s*```/is', $reply, $m ) ) {
			return trim( $m[1] );
		}
		// Fallback: greedy first { … } block (best-effort).
		$open  = strpos( $reply, '{' );
		$close = strrpos( $reply, '}' );
		if ( false !== $open && false !== $close && $close > $open ) {
			return trim( substr( $reply, $open, $close - $open + 1 ) );
		}
		return null;
	}

	/**
	 * Return any prose AFTER the closing ``` of the JSON fence as the
	 * user-facing explanation. The reply is shaped like:
	 *
	 *     ```json
	 *     { ... }
	 *     ```
	 *     <explanation here>
	 *
	 * so we strip everything up to and including the closing fence.
	 */
	private static function extract_explanation( string $reply ): string {
		// Find the LAST closing ``` and take whatever follows it.
		$last = strrpos( $reply, '```' );
		if ( false === $last ) {
			return '';
		}
		$tail = substr( $reply, $last + 3 );
		return trim( $tail );
	}

	/**
	 * @return string[] Sorted slugs of registered abilities.
	 */
	private static function list_abilities(): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return array();
		}
		$slugs = array();
		foreach ( wp_get_abilities() as $ability ) {
			if ( method_exists( $ability, 'get_name' ) ) {
				$slugs[] = (string) $ability->get_name();
			}
		}
		sort( $slugs );
		// Keep the prompt small — first ~30 abilities is plenty of context.
		return array_slice( $slugs, 0, 30 );
	}

	/**
	 * @return string[] Sorted slugs of registered agents.
	 */
	private static function list_agents(): array {
		if ( ! function_exists( 'wp_get_agents' ) ) {
			return array();
		}
		$slugs = array();
		foreach ( wp_get_agents() as $agent ) {
			if ( method_exists( $agent, 'get_slug' ) ) {
				$slugs[] = (string) $agent->get_slug();
			}
		}
		sort( $slugs );
		return $slugs;
	}
}
