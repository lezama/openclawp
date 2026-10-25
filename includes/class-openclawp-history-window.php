<?php
/**
 * Bounded history for long-lived sessions.
 *
 * A WhatsApp or Telegram session never ends, and the runner used to resend the
 * whole transcript on every model call. One large tool result early on (a
 * 600 KB calendar dump, say) then rode along on every later turn. This class
 * keeps what reaches the model bounded without touching what is stored:
 *
 *   - Only the last `keep_turns` user turns go to the model verbatim. A turn
 *     starts at a user text message, so a tool call is never split from its
 *     result.
 *   - Older turns are folded into a short running summary, kept in the
 *     session metadata (`history_summary`, `history_summary_upto`), and the
 *     summary is prepended to the first kept user message.
 *   - Tool results from earlier turns inside the window are cut to
 *     `tool_result_chars`. The current turn's results stay whole.
 *
 * The stored transcript stays complete; only the model-facing copy shrinks.
 * Summaries are generated in batches (`summarize_every` turns at a time), so
 * most turns pay nothing extra.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_History_Window {

	public const META_SUMMARY = 'history_summary';
	public const META_UPTO    = 'history_summary_upto';

	/**
	 * Settings for an agent. `keep_turns` 0 turns the window off.
	 *
	 * @return array{keep_turns:int,summarize_every:int,tool_result_chars:int,summary_chars:int}
	 */
	public static function settings( string $agent_slug ): array {
		$defaults = array(
			'keep_turns'        => 8,
			'summarize_every'   => 4,
			'tool_result_chars' => 2000,
			'summary_chars'     => 2500,
		);

		/**
		 * Filters the history window for an agent.
		 *
		 * Return `keep_turns => 0` to send the full transcript, as before.
		 *
		 * @param array  $settings   keep_turns, summarize_every, tool_result_chars, summary_chars.
		 * @param string $agent_slug Agent slug.
		 */
		$settings = (array) apply_filters( 'openclawp_history_window', $defaults, $agent_slug );

		return array(
			'keep_turns'        => max( 0, (int) ( $settings['keep_turns'] ?? $defaults['keep_turns'] ) ),
			'summarize_every'   => max( 1, (int) ( $settings['summarize_every'] ?? $defaults['summarize_every'] ) ),
			'tool_result_chars' => max( 200, (int) ( $settings['tool_result_chars'] ?? $defaults['tool_result_chars'] ) ),
			'summary_chars'     => max( 500, (int) ( $settings['summary_chars'] ?? $defaults['summary_chars'] ) ),
		);
	}

	/**
	 * Indexes of the user text messages that open each turn.
	 *
	 * @param array<int,array<string,mixed>> $messages Transcript.
	 * @return int[]
	 */
	public static function turn_starts( array $messages ): array {
		$starts = array();
		foreach ( array_values( $messages ) as $i => $message ) {
			if ( 'user' === ( $message['role'] ?? '' ) && 'text' === (string) ( $message['type'] ?? 'text' ) ) {
				$starts[] = $i;
			}
		}
		return $starts;
	}

	/**
	 * Where the summary should end, if it is due for an update.
	 *
	 * Returns the index of the first message that stays verbatim, or null when
	 * the current summary is still enough. The summary moves only once more
	 * than `keep_turns + summarize_every` turns sit outside it, and then jumps
	 * forward to leave exactly `keep_turns`.
	 *
	 * @param array<int,array<string,mixed>> $messages Transcript, including the new user message.
	 */
	public static function next_cut( array $messages, int $upto, array $settings ): ?int {
		if ( $settings['keep_turns'] <= 0 ) {
			return null;
		}

		$pending = array_values(
			array_filter(
				self::turn_starts( $messages ),
				static fn ( int $start ): bool => $start >= $upto
			)
		);

		if ( count( $pending ) <= $settings['keep_turns'] + $settings['summarize_every'] ) {
			return null;
		}

		return $pending[ count( $pending ) - $settings['keep_turns'] ];
	}

	/**
	 * The copy of the transcript that goes to the model.
	 *
	 * @param array<int,array<string,mixed>> $messages Full transcript.
	 * @param string                         $summary  Running summary of messages before `$upto`.
	 * @param int                            $upto     First message not covered by the summary.
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_model( array $messages, string $summary, int $upto, array $settings ): array {
		$messages = array_values( $messages );
		if ( $settings['keep_turns'] <= 0 ) {
			return $messages;
		}

		// A summary that points past a turn start would split a tool call
		// from its result; fall back to sending from the start.
		$starts = self::turn_starts( $messages );
		if ( $upto > 0 && ! in_array( $upto, $starts, true ) ) {
			$upto    = 0;
			$summary = '';
		}

		$window = array_slice( $messages, $upto );

		$current_turn = 0;
		foreach ( self::turn_starts( $window ) as $start ) {
			$current_turn = $start;
		}

		foreach ( $window as $i => $message ) {
			if ( $i < $current_turn && 'tool_result' === ( $message['type'] ?? '' ) ) {
				$window[ $i ] = self::shorten_tool_result( $message, $settings['tool_result_chars'] );
			}
		}

		if ( '' !== trim( $summary ) && isset( $window[0] ) ) {
			$window[0] = self::prepend_text( $window[0], self::summary_block( $summary ) );
		}

		return $window;
	}

	/**
	 * Cut a tool result down to `$limit` characters of JSON.
	 *
	 * @param array<string,mixed> $message Stored tool_result message.
	 * @return array<string,mixed>
	 */
	public static function shorten_tool_result( array $message, int $limit ): array {
		$payload = is_array( $message['payload'] ?? null ) ? $message['payload'] : array();
		$result  = array_key_exists( 'result', $payload ) ? $payload['result'] : ( $message['content'] ?? '' );
		$text    = is_string( $result ) ? $result : (string) wp_json_encode( $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		if ( strlen( $text ) <= $limit ) {
			return $message;
		}

		$short = mb_strcut( $text, 0, $limit ) . sprintf( ' …[cut: %d of %d characters shown; call the tool again for the full result]', $limit, strlen( $text ) );

		if ( array_key_exists( 'result', $payload ) ) {
			$payload['result']  = $short;
			$message['payload'] = $payload;
		} else {
			$message['content'] = $short;
		}
		return $message;
	}

	/**
	 * Plain-text rendering of transcript messages for the summarizer.
	 *
	 * @param array<int,array<string,mixed>> $messages Messages to fold into the summary.
	 */
	public static function render_for_summary( array $messages ): string {
		$lines = array();
		foreach ( $messages as $message ) {
			$type    = (string) ( $message['type'] ?? 'text' );
			$payload = is_array( $message['payload'] ?? null ) ? $message['payload'] : array();

			if ( 'tool_call' === $type ) {
				$params  = wp_json_encode( $payload['parameters'] ?? array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
				$lines[] = 'Tool call: ' . (string) ( $payload['tool_name'] ?? '' ) . ' ' . mb_strcut( (string) $params, 0, 300 );
				continue;
			}
			if ( 'tool_result' === $type ) {
				$short   = self::shorten_tool_result( $message, 400 );
				$p       = is_array( $short['payload'] ?? null ) ? $short['payload'] : array();
				$result  = array_key_exists( 'result', $p ) ? $p['result'] : ( $short['content'] ?? '' );
				$lines[] = 'Tool result: ' . ( is_string( $result ) ? $result : (string) wp_json_encode( $result, JSON_UNESCAPED_UNICODE ) );
				continue;
			}

			$text = self::text_of( $message['content'] ?? '' );
			if ( '' === $text ) {
				continue;
			}
			$role    = 'user' === ( $message['role'] ?? '' ) ? 'User' : 'Assistant';
			$lines[] = $role . ': ' . $text;
		}
		return implode( "\n", $lines );
	}

	/**
	 * Fold `$messages` into `$previous` with one model call.
	 *
	 * Returns null when no model is available or the call fails, so the caller
	 * keeps the old summary and sends a longer window instead.
	 *
	 * @param array<int,array<string,mixed>> $messages Messages leaving the window.
	 */
	public static function summarize( WP_Agent $agent, string $session_id, string $previous, array $messages, array $settings ): ?string {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return null;
		}

		$instruction = sprintf(
			'You keep the running memory of a long chat between a user and an assistant. Merge the previous summary with the new messages into one updated summary, at most %d characters, written in the language the user writes in. Keep what the conversation needs later: decisions, commitments, open questions, names, numbers and dates the user gave, and what the user asked the assistant to remember. Drop greetings, tool mechanics and anything the assistant can look up again. Reply with the summary only.',
			$settings['summary_chars']
		);
		$prompt = "Previous summary:\n" . ( '' !== trim( $previous ) ? $previous : '(none)' )
			. "\n\nNew messages:\n" . self::render_for_summary( $messages );

		$telemetry = array(
			'agent_slug'  => $agent->get_slug(),
			'session_id'  => $session_id,
			'provider'    => '',
			'model'       => '',
			'token_usage' => array(),
			'duration_ms' => 0,
			'success'     => false,
			'error'       => null,
			'purpose'     => 'history_summary',
		);

		$builder = wp_ai_client_prompt( $prompt )->using_system_instruction( $instruction );
		$config  = $agent->get_default_config();
		$model   = isset( $config['model'] ) && is_string( $config['model'] ) ? trim( $config['model'] ) : '';
		if ( '' !== $model && 'auto' !== $model ) {
			$provider = isset( $config['provider'] ) && is_string( $config['provider'] ) ? trim( $config['provider'] ) : '';
			$builder  = $builder->using_model_preference( '' !== $provider && 'auto' !== $provider ? array( $provider, $model ) : $model );
		}

		$start  = microtime( true );
		$result = $builder->generate_text_result();
		$telemetry['duration_ms'] = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $result ) ) {
			$telemetry['error'] = (string) $result->get_error_code();
			do_action( 'openclawp_chat_turn_completed', $telemetry );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Matches openclaWP's telemetry channel.
			error_log( sprintf( '[openclawp] history_summary_failed agent=%s session=%s error=%s', $agent->get_slug(), $session_id, $result->get_error_message() ) );
			return null;
		}

		$telemetry['success']     = true;
		$telemetry['provider']    = method_exists( $result, 'getProviderMetadata' ) && is_object( $result->getProviderMetadata() ) ? (string) $result->getProviderMetadata()->getId() : '';
		$telemetry['model']       = method_exists( $result, 'getModelMetadata' ) && is_object( $result->getModelMetadata() ) ? (string) $result->getModelMetadata()->getId() : '';
		$usage                    = method_exists( $result, 'getTokenUsage' ) ? $result->getTokenUsage() : null;
		if ( is_object( $usage ) ) {
			$telemetry['token_usage'] = array(
				'input'  => (int) $usage->getPromptTokens(),
				'output' => (int) $usage->getCompletionTokens(),
				'total'  => (int) $usage->getTotalTokens(),
			);
		}
		do_action( 'openclawp_chat_turn_completed', $telemetry );

		try {
			$text = trim( (string) $result->toText() );
		} catch ( \Throwable $e ) {
			$text = '';
		}
		return '' !== $text ? $text : null;
	}

	/**
	 * Bring the session summary up to date before a turn.
	 *
	 * @param array<int,array<string,mixed>> $messages Transcript including the new user message.
	 * @param array<string,mixed>            $metadata Session metadata.
	 * @return array{summary:string,upto:int,metadata:array<string,mixed>}
	 */
	public static function prepare( WP_Agent $agent, string $session_id, array $messages, array $metadata ): array {
		$settings = self::settings( $agent->get_slug() );
		$summary  = (string) ( $metadata[ self::META_SUMMARY ] ?? '' );
		$upto     = max( 0, (int) ( $metadata[ self::META_UPTO ] ?? 0 ) );
		if ( $upto > count( $messages ) ) {
			$summary = '';
			$upto    = 0;
		}

		$cut = self::next_cut( $messages, $upto, $settings );
		if ( null !== $cut ) {
			$new = self::summarize( $agent, $session_id, $summary, array_slice( array_values( $messages ), $upto, $cut - $upto ), $settings );
			if ( null !== $new ) {
				$summary                          = $new;
				$upto                             = $cut;
				$metadata[ self::META_SUMMARY ]   = $summary;
				$metadata[ self::META_UPTO ]      = $upto;
			}
		}

		return array(
			'summary'  => $summary,
			'upto'     => $upto,
			'metadata' => $metadata,
		);
	}

	private static function summary_block( string $summary ): string {
		return "[Earlier in this conversation]\n" . trim( $summary ) . "\n[/Earlier in this conversation]";
	}

	/**
	 * @param array<string,mixed> $message User text message.
	 * @return array<string,mixed>
	 */
	private static function prepend_text( array $message, string $block ): array {
		$content = $message['content'] ?? '';
		if ( is_array( $content ) ) {
			array_unshift(
				$content,
				array(
					'type' => 'text',
					'text' => $block . "\n\n",
				)
			);
			$message['content'] = $content;
		} else {
			$message['content'] = $block . "\n\n" . (string) $content;
		}
		return $message;
	}

	private static function text_of( $content ): string {
		if ( is_string( $content ) ) {
			return trim( $content );
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
		return trim( $out );
	}
}
