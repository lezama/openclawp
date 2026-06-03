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
	 * `MessageRoleEnum::model()`. System and tool messages are dropped — the
	 * loop's tool-mediation path isn't wired through this adapter yet.
	 * Returns the original transcript unchanged if WP AI Client is missing.
	 *
	 * @param array<int,array<string,mixed>> $messages Transcript messages.
	 * @return array
	 */
	public static function to_ai_client_messages( array $messages ): array {
		if ( ! class_exists( '\\WordPress\\AiClient\\Messages\\DTO\\Message' ) ) {
			return $messages;
		}

		$out = array();
		foreach ( $messages as $message ) {
			$role = (string) ( $message['role'] ?? '' );
			$text = self::extract_text( $message['content'] ?? '' );

			if ( '' === $text ) {
				continue;
			}

			if ( 'user' === $role ) {
				$role_enum = \WordPress\AiClient\Messages\Enums\MessageRoleEnum::user();
			} elseif ( 'assistant' === $role || 'model' === $role ) {
				$role_enum = \WordPress\AiClient\Messages\Enums\MessageRoleEnum::model();
			} else {
				continue;
			}

			$out[] = new \WordPress\AiClient\Messages\DTO\Message(
				$role_enum,
				array( new \WordPress\AiClient\Messages\DTO\MessagePart( $text ) )
			);
		}

		return $out;
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
