<?php
/**
 * Transcript ↔ wp-ai-client `Message` DTO adapter.
 *
 * Converts the conversation loop's transcript shape (`[{role, content}, ...]`)
 * into the list of `Message` objects `wp_ai_client_prompt()` accepts. Pure
 * functions; no WordPress globals or side effects.
 *
 * Candidate for extraction into a small `agents-api-wp-ai-client` companion
 * package — every consumer of `agents-api` that uses `wp_ai_client_prompt()`
 * needs the same conversion.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Message_Adapter {

	/**
	 * Convert a transcript array into a list of WP AI Client `Message` DTOs.
	 *
	 * Roles map: `user` → `MessageRoleEnum::user()`, `assistant`/`model` →
	 * `MessageRoleEnum::model()`. Tool-mediation turns round-trip as native
	 * function-call parts so the model sees its own calls and their results
	 * (without this, a tool-using turn re-issues the same call every turn
	 * because the result never reaches the model):
	 *   - `tool_call`   → model message carrying a `FunctionCall` part.
	 *   - `tool_result` → user message carrying a `FunctionResponse` part.
	 * Returns the original transcript unchanged if WP AI Client is missing.
	 *
	 * @param array<int,array<string,mixed>> $messages Transcript messages.
	 * @return array
	 */
	public static function to_ai_client_messages( array $messages ): array {
		if ( ! class_exists( '\\WordPress\\AiClient\\Messages\\DTO\\Message' ) ) {
			return $messages;
		}

		$user_role  = \WordPress\AiClient\Messages\Enums\MessageRoleEnum::user();
		$model_role = \WordPress\AiClient\Messages\Enums\MessageRoleEnum::model();

		$out = array();
		// Anthropic rejects any tool_use block whose `id` is not a non-empty
		// string ("messages.N.content.0.tool_use.id: Input should be a valid
		// string"). Some turns were persisted without a `tool_call_id`, so we
		// synthesize deterministic ids here and pair each tool_result to the
		// oldest unmatched tool_call by order (the loop appends call then result).
		$pending_call_ids = array();
		$synth_n          = 0;
		foreach ( $messages as $message ) {
			$type = (string) ( $message['type'] ?? 'text' );

			if ( 'tool_call' === $type ) {
				$meta = is_array( $message['metadata'] ?? null ) ? $message['metadata'] : array();
				$id   = (string) ( $meta['tool_call_id'] ?? '' );
				if ( '' === $id ) {
					$id = 'toolu_oc_' . ( ++$synth_n );
				}
				$part = self::tool_call_part( $message, $id );
				if ( null !== $part ) {
					$out[]              = new \WordPress\AiClient\Messages\DTO\Message( $model_role, array( $part ) );
					$pending_call_ids[] = $id;
				}
				continue;
			}

			if ( 'tool_result' === $type ) {
				$meta = is_array( $message['metadata'] ?? null ) ? $message['metadata'] : array();
				$id   = (string) ( $meta['tool_call_id'] ?? '' );
				if ( '' === $id ) {
					// No stored id — pair to the oldest unmatched tool_call. A
					// tool_result with no matching tool_use is invalid, so drop it.
					if ( empty( $pending_call_ids ) ) {
						continue;
					}
					$id = array_shift( $pending_call_ids );
				} else {
					$pos = array_search( $id, $pending_call_ids, true );
					if ( false !== $pos ) {
						array_splice( $pending_call_ids, $pos, 1 );
					}
				}
				$part = self::tool_result_part( $message, $id );
				if ( null !== $part ) {
					$out[] = new \WordPress\AiClient\Messages\DTO\Message( $user_role, array( $part ) );
				}
				continue;
			}

			$role = (string) ( $message['role'] ?? '' );
			$text = self::extract_text( $message['content'] ?? '' );
			if ( '' === $text ) {
				continue;
			}

			if ( 'user' === $role ) {
				$out[] = new \WordPress\AiClient\Messages\DTO\Message( $user_role, array( new \WordPress\AiClient\Messages\DTO\MessagePart( $text ) ) );
			} elseif ( 'assistant' === $role || 'model' === $role ) {
				$out[] = new \WordPress\AiClient\Messages\DTO\Message( $model_role, array( new \WordPress\AiClient\Messages\DTO\MessagePart( $text ) ) );
			}
		}

		return $out;
	}

	/**
	 * Build a `MessagePart` wrapping the `FunctionCall` for a stored `tool_call`
	 * transcript message. The function name is mapped back to the provider-safe
	 * form the model originally used (the stored name carries the `client/`
	 * loop prefix). Returns null when the DTO is unavailable or the name is empty.
	 *
	 * @param array<string,mixed> $message Stored tool_call message.
	 * @param string              $id      Resolved (never-empty) tool_use id.
	 * @return object|null
	 */
	private static function tool_call_part( array $message, string $id ) {
		if ( ! class_exists( '\\WordPress\\AiClient\\Tools\\DTO\\FunctionCall' ) ) {
			return null;
		}
		$payload = is_array( $message['payload'] ?? null ) ? $message['payload'] : array();
		$name    = OpenclaWP_Tools_Resolver::provider_name( (string) ( $payload['tool_name'] ?? '' ) );
		if ( '' === $name ) {
			return null;
		}
		$args = self::function_call_args( $payload['parameters'] ?? array() );

		$call = new \WordPress\AiClient\Tools\DTO\FunctionCall( $id, $name, $args );
		return new \WordPress\AiClient\Messages\DTO\MessagePart( $call );
	}

	/**
	 * Return provider-safe function-call args for transcript replay.
	 *
	 * Anthropic requires `tool_use.input` to be a JSON object. PHP encodes an
	 * empty array as `[]`, so a no-argument tool call must be carried as an
	 * empty object when replayed through WP AI Client.
	 *
	 * @param mixed $parameters Stored tool parameters.
	 * @return mixed
	 */
	private static function function_call_args( $parameters ) {
		if ( array() === $parameters ) {
			return new \stdClass();
		}

		return is_array( $parameters ) ? $parameters : array();
	}

	/**
	 * Build a `MessagePart` wrapping the `FunctionResponse` for a stored
	 * `tool_result` transcript message, matched to its call by `tool_call_id`.
	 *
	 * @param array<string,mixed> $message Stored tool_result message.
	 * @param string              $id      Resolved (never-empty) tool_use id to match its call.
	 * @return object|null
	 */
	private static function tool_result_part( array $message, string $id ) {
		if ( ! class_exists( '\\WordPress\\AiClient\\Tools\\DTO\\FunctionResponse' ) ) {
			return null;
		}
		$payload  = is_array( $message['payload'] ?? null ) ? $message['payload'] : array();
		$name     = OpenclaWP_Tools_Resolver::provider_name( (string) ( $payload['tool_name'] ?? '' ) );
		$response = array_key_exists( 'result', $payload ) ? $payload['result'] : ( $message['content'] ?? '' );

		$resp = new \WordPress\AiClient\Tools\DTO\FunctionResponse( $id, '' !== $name ? $name : null, $response );
		return new \WordPress\AiClient\Messages\DTO\MessagePart( $resp );
	}

	/**
	 * Walk the transcript backwards and return the most recent assistant text.
	 *
	 * @param array<int,array<string,mixed>> $messages Transcript messages.
	 * @return string Empty string when no assistant message is present.
	 */
	public static function last_assistant_text( array $messages ): string {
		for ( $i = count( $messages ) - 1; $i >= 0; --$i ) {
			$message = $messages[ $i ];
			if ( 'assistant' !== ( $message['role'] ?? '' ) ) {
				continue;
			}
			// Skip internal-infrastructure envelopes from agents-api. Tool
			// calls, tool results, deltas, errors etc. all share role
			// 'assistant' but carry a typed payload that's NOT user-facing.
			// Without this filter, an LLM turn that finishes on a tool_call
			// without a subsequent text reply leaks "Calling <tool_name>"
			// out to the WhatsApp recipient as if it were the bot's answer.
			$type = (string) ( $message['type'] ?? 'text' );
			if ( '' !== $type && 'text' !== $type ) {
				continue;
			}
			$text = self::extract_text( $message['content'] ?? '' );
			if ( '' !== $text ) {
				return $text;
			}
		}
		return '';
	}

	/**
	 * Pull a flat string out of a message's `content` field, which may be a
	 * scalar string or a list of parts.
	 *
	 * @param mixed $content Raw content.
	 * @return string
	 */
	private static function extract_text( $content ): string {
		if ( is_string( $content ) ) {
			return $content;
		}
		if ( ! is_array( $content ) ) {
			return '';
		}

		$out = '';
		foreach ( $content as $part ) {
			if ( is_string( $part ) ) {
				$out .= $part;
			} elseif ( is_array( $part ) && isset( $part['text'] ) ) {
				$out .= (string) $part['text'];
			}
		}
		return $out;
	}
}
