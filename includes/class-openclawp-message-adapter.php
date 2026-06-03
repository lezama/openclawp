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
		foreach ( $messages as $message ) {
			$type = (string) ( $message['type'] ?? 'text' );

			if ( 'tool_call' === $type ) {
				$part = self::tool_call_part( $message );
				if ( null !== $part ) {
					$out[] = new \WordPress\AiClient\Messages\DTO\Message( $model_role, array( $part ) );
				}
				continue;
			}

			if ( 'tool_result' === $type ) {
				$part = self::tool_result_part( $message );
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
	 * @return object|null
	 */
	private static function tool_call_part( array $message ) {
		if ( ! class_exists( '\\WordPress\\AiClient\\Tools\\DTO\\FunctionCall' ) ) {
			return null;
		}
		$payload = is_array( $message['payload'] ?? null ) ? $message['payload'] : array();
		$meta    = is_array( $message['metadata'] ?? null ) ? $message['metadata'] : array();
		$name    = OpenclaWP_Tools_Resolver::provider_name( (string) ( $payload['tool_name'] ?? '' ) );
		if ( '' === $name ) {
			return null;
		}
		$id   = (string) ( $meta['tool_call_id'] ?? '' );
		$args = is_array( $payload['parameters'] ?? null ) ? $payload['parameters'] : array();

		$call = new \WordPress\AiClient\Tools\DTO\FunctionCall( '' !== $id ? $id : null, $name, $args );
		return new \WordPress\AiClient\Messages\DTO\MessagePart( $call );
	}

	/**
	 * Build a `MessagePart` wrapping the `FunctionResponse` for a stored
	 * `tool_result` transcript message, matched to its call by `tool_call_id`.
	 *
	 * @param array<string,mixed> $message Stored tool_result message.
	 * @return object|null
	 */
	private static function tool_result_part( array $message ) {
		if ( ! class_exists( '\\WordPress\\AiClient\\Tools\\DTO\\FunctionResponse' ) ) {
			return null;
		}
		$payload  = is_array( $message['payload'] ?? null ) ? $message['payload'] : array();
		$meta     = is_array( $message['metadata'] ?? null ) ? $message['metadata'] : array();
		$name     = OpenclaWP_Tools_Resolver::provider_name( (string) ( $payload['tool_name'] ?? '' ) );
		$id       = (string) ( $meta['tool_call_id'] ?? '' );
		$response = array_key_exists( 'result', $payload ) ? $payload['result'] : ( $message['content'] ?? '' );

		$resp = new \WordPress\AiClient\Tools\DTO\FunctionResponse( '' !== $id ? $id : null, '' !== $name ? $name : null, $response );
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
