<?php
/**
 * Test-only helper that assembles the provider payload openclaWP would send,
 * stopping before the HTTP call.
 *
 * Mirrors `OpenclaWP_Runner::build_turn_runner()` — same agent description as
 * system instruction, same transcript shape, same tool catalog from
 * `OpenclaWP_Tools_Resolver::for_agent()`, same model preference resolution —
 * but returns a deterministic associative array instead of invoking the
 * provider. The output is intentionally JSON-serializable so the integration
 * test can diff it against a snapshot file.
 *
 * Why this lives in tests/ and not includes/:
 *   - It's not used at runtime — the real path goes through wp-ai-client.
 *   - Production has no reason to assemble a payload it never sends.
 *
 * @package OpenclaWP\Tests\Integration\PromptAssembly
 */

declare( strict_types=1 );

namespace OpenclaWP\Tests\Integration\PromptAssembly;

use OpenclaWP_Tools_Resolver;
use WP_Agent;

/**
 * Pure-PHP payload assembler. No WordPress globals, no provider calls.
 */
final class PromptPayloadAssembler {

	/**
	 * Build a snapshot-safe payload for (agent, channel, user_message).
	 *
	 * @param WP_Agent $agent           Registered agent.
	 * @param string   $channel         Channel slug. Drives `client_context`.
	 * @param string   $user_message    Canonical user message to seed the transcript.
	 * @param array    $prior_messages  Optional prior transcript messages.
	 *
	 * @return array<string, mixed> Deterministic JSON-serializable payload.
	 */
	public static function assemble( WP_Agent $agent, string $channel, string $user_message, array $prior_messages = array() ): array {
		$tools = OpenclaWP_Tools_Resolver::for_agent( $agent );

		// Mirror Runner::run_turn(): append the user's latest message to the
		// transcript before the turn runner builds the provider payload.
		$messages   = $prior_messages;
		$messages[] = array(
			'role'    => 'user',
			'content' => $user_message,
		);

		$config = $agent->get_default_config();

		// Tool declarations from the resolver are already in canonical's
		// shape (executor=client, scope=run). Drop executor/scope from the
		// snapshot — they're constants and only add noise.
		$tool_catalog = array();
		foreach ( $tools['declarations'] as $name => $decl ) {
			$tool_catalog[] = array(
				'name'        => (string) ( $decl['name'] ?? $name ),
				'source'      => (string) ( $decl['source'] ?? 'openclawp' ),
				'description' => (string) ( $decl['description'] ?? '' ),
				'parameters'  => is_array( $decl['parameters'] ?? null ) ? $decl['parameters'] : array(),
			);
		}

		return array(
			'agent_slug'        => $agent->get_slug(),
			'channel'           => $channel,
			'system_instruction' => $agent->get_description(),
			'model_preference'  => self::resolve_model_preference( $config ),
			'messages'          => self::normalize_messages( $messages ),
			'tool_catalog'      => $tool_catalog,
			'delegate_targets'  => $tools['delegate_targets'],
			'runtime_context'   => self::client_context_for_channel( $channel ),
			'max_turns'         => (int) ( $config['max_turns'] ?? 5 ),
		);
	}

	/**
	 * Mirror of `OpenclaWP_Runner::resolve_model_preference()`. Re-implemented
	 * here so test snapshots are explicit about the rule, not implicit via a
	 * private method.
	 *
	 * @param array<string, mixed> $config Agent default_config.
	 * @return array{provider:string,model:string}|array{model:string}|null
	 */
	private static function resolve_model_preference( array $config ) {
		$model    = isset( $config['model'] ) && is_string( $config['model'] ) ? trim( $config['model'] ) : '';
		$provider = isset( $config['provider'] ) && is_string( $config['provider'] ) ? trim( $config['provider'] ) : '';

		if ( '' === $model || 'auto' === $model ) {
			return null;
		}

		if ( '' !== $provider && 'auto' !== $provider ) {
			return array(
				'provider' => $provider,
				'model'    => $model,
			);
		}

		return array( 'model' => $model );
	}

	/**
	 * Canonical client_context map per channel. Matches what
	 * OpenclaWP_Whatsapp passes and what the chat block forwards.
	 *
	 * @return array<string, mixed>
	 */
	private static function client_context_for_channel( string $channel ): array {
		switch ( $channel ) {
			case 'whatsapp':
				return array(
					'client_context' => array(
						'source'                   => 'channel',
						'connector_id'             => 'whatsapp',
						'client_name'              => 'whatsapp',
						'external_provider'        => 'whatsapp',
						'external_conversation_id' => '<PHONE>',
						'external_message_id'      => '<MSG_ID>',
						'sender_id'                => '<PHONE>',
						'room_kind'                => 'dm',
					),
				);
			case 'telegram':
				return array(
					'client_context' => array(
						'source'                   => 'channel',
						'connector_id'             => 'telegram',
						'client_name'              => 'telegram',
						'external_provider'        => 'telegram',
						'external_conversation_id' => '<CHAT_ID>',
						'external_message_id'      => '<MSG_ID>',
						'sender_id'                => '<CHAT_ID>',
						'room_kind'                => 'dm',
					),
				);
			case 'mcp':
				return array(
					'client_context' => array(
						'source'      => 'mcp',
						'connector_id' => 'mcp',
						'client_name' => 'mcp',
					),
				);
			case 'chat':
			default:
				return array(
					'client_context' => array(
						'source'      => 'block',
						'connector_id' => 'chat-block',
						'client_name' => 'chat-block',
					),
				);
		}
	}

	/**
	 * Strip non-string content variations so snapshots stay byte-stable.
	 *
	 * @param array<int,array<string,mixed>> $messages Transcript.
	 * @return array<int,array{role:string,content:string}>
	 */
	private static function normalize_messages( array $messages ): array {
		$out = array();
		foreach ( $messages as $message ) {
			$out[] = array(
				'role'    => (string) ( $message['role'] ?? '' ),
				'content' => (string) ( $message['content'] ?? '' ),
			);
		}
		return $out;
	}
}
