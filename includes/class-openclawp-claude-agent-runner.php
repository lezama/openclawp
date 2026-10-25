<?php
/**
 * Turn runner backed by a Claude subscription, through the Claude Agent SDK.
 *
 * An agent whose `default_config.provider` is `claude-agent` (or for which the
 * `openclawp_use_claude_agent` filter returns true) does not call a metered
 * API. Its turns go to the local bridge in `services/claude-agent/`, which
 * runs the official Agent SDK under the site owner's Claude login.
 *
 * openclaWP's conversation loop stays in charge. The bridge never executes a
 * tool: when the model asks for one, this runner returns it as a normal tool
 * call, the loop runs it through OpenclaWP_Tool_Executor (abilities,
 * confirmation gate, events, transcript), and the next runner call hands the
 * results back to the same paused SDK run. If that run is gone (bridge
 * restart, idle timeout), the runner starts a fresh one from the transcript.
 *
 * Settings: OPENCLAWP_CLAUDE_AGENT_URL / option `openclawp_claude_agent_url`
 * (default http://127.0.0.1:8787) and OPENCLAWP_CLAUDE_AGENT_TOKEN / option
 * `openclawp_claude_agent_token`, the bridge's shared secret.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Claude_Agent_Runner {

	public const PROVIDER = 'claude-agent';

	/**
	 * Open SDK runs, by openclaWP session: the bridge conversation id and the
	 * tool-call ids it is waiting on. Lives for one PHP request, which is
	 * exactly one conversation-loop run.
	 *
	 * @var array<string,array{conversation_id:string,pending:string[]}>
	 */
	private static array $open = array();

	public static function register(): void {
		add_filter( 'openclawp_turn_runner_factory', array( __CLASS__, 'wrap_factory' ), 5, 3 );
	}

	public static function applies( WP_Agent $agent ): bool {
		$config = $agent->get_default_config();
		$use    = self::PROVIDER === (string) ( $config['provider'] ?? '' );

		/**
		 * Filters whether an agent runs through the Claude Agent bridge.
		 *
		 * @param bool     $use   True when the agent's provider is `claude-agent`.
		 * @param WP_Agent $agent Agent.
		 */
		return (bool) apply_filters( 'openclawp_use_claude_agent', $use, $agent );
	}

	/**
	 * @param callable $factory Inner factory.
	 * @param array    $session Session snapshot.
	 * @param array    $history Summary state from OpenclaWP_History_Window::prepare().
	 */
	public static function wrap_factory( $factory, $session = array(), $history = array() ) {
		return static function ( WP_Agent $agent ) use ( $factory, $session, $history ) {
			if ( ! self::applies( $agent ) ) {
				return call_user_func( $factory, $agent );
			}
			return self::turn_runner( $agent, (array) $session, (array) $history );
		};
	}

	/** @return array{url:string,token:string,timeout:int} */
	public static function settings(): array {
		$url   = defined( 'OPENCLAWP_CLAUDE_AGENT_URL' ) ? (string) OPENCLAWP_CLAUDE_AGENT_URL : (string) get_option( 'openclawp_claude_agent_url', '' );
		$token = defined( 'OPENCLAWP_CLAUDE_AGENT_TOKEN' ) ? (string) OPENCLAWP_CLAUDE_AGENT_TOKEN : (string) get_option( 'openclawp_claude_agent_token', '' );

		return array(
			'url'     => rtrim( '' !== $url ? $url : 'http://127.0.0.1:8787', '/' ),
			'token'   => $token,
			'timeout' => (int) apply_filters( 'openclawp_claude_agent_timeout', 180 ),
		);
	}

	private static function turn_runner( WP_Agent $agent, array $session, array $history ): callable {
		$session_id = (string) ( $session['session_id'] ?? '' );

		return static function ( array $messages, array $context ) use ( $agent, $session_id, $history ): array {
			$tools = self::tool_declarations( $agent );
			$start = microtime( true );

			$response = null;
			$open     = self::$open[ $session_id ] ?? null;
			if ( null !== $open ) {
				$results = self::results_for( $messages, $open['pending'] );
				if ( null !== $results ) {
					$response = self::post(
						array(
							'conversation_id' => $open['conversation_id'],
							'results'         => $results,
						)
					);
					// The bridge lost the run: start over from the transcript.
					if ( isset( $response['http_status'] ) && 404 === $response['http_status'] ) {
						$response = null;
					}
				}
				unset( self::$open[ $session_id ] );
			}

			if ( null === $response ) {
				$settings = OpenclaWP_History_Window::settings( $agent->get_slug() );
				$windowed = OpenclaWP_History_Window::for_model(
					$messages,
					(string) ( $history['summary'] ?? '' ),
					(int) ( $history['upto'] ?? 0 ),
					$settings
				);
				$windowed = OpenclaWP_Runner::messages_with_client_context( $windowed, $context );
				$response = self::post(
					array(
						'system'    => $agent->get_description(),
						'prompt'    => self::render_prompt( $windowed ),
						'tools'     => $tools,
						'model'     => self::model( $agent ),
						'max_turns' => max( 1, (int) ( $agent->get_default_config()['max_turns'] ?? 25 ) ),
					)
				);
			}

			return self::to_turn_result( $agent, $session_id, $messages, $response, ! empty( $tools ), $start );
		};
	}

	/**
	 * One plain completion, no tools: for side calls such as the history
	 * summary. Returns the text, or null on failure (logged).
	 */
	public static function complete( WP_Agent $agent, string $session_id, string $system, string $prompt ): ?string {
		$start    = microtime( true );
		$response = self::post(
			array(
				'system'    => $system,
				'prompt'    => $prompt,
				'tools'     => array(),
				'model'     => self::model( $agent ),
				'max_turns' => 1,
			)
		);
		$ok = 'done' === ( $response['status'] ?? '' );
		self::emit_telemetry( $agent, $session_id, $response, $ok, $start );
		if ( ! $ok ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Matches openclaWP's telemetry channel.
			error_log( sprintf( '[openclawp] claude_agent_error agent=%s session=%s error=%s', $agent->get_slug(), $session_id, (string) ( $response['error'] ?? '' ) ) );
			return null;
		}
		$text = trim( (string) ( $response['text'] ?? '' ) );
		return '' !== $text ? $text : null;
	}

	/**
	 * Tool declarations in the bridge's shape, with the provider-safe names
	 * the model uses.
	 *
	 * @return array<int,array{name:string,description:string,input_schema:array}>
	 */
	public static function tool_declarations( WP_Agent $agent ): array {
		$out = array();
		foreach ( OpenclaWP_Tools_Resolver::for_agent( $agent )['declarations'] as $name => $decl ) {
			$out[] = array(
				'name'         => OpenclaWP_Tools_Resolver::provider_name( (string) $name ),
				'description'  => (string) ( $decl['description'] ?? '' ),
				'input_schema' => is_array( $decl['parameters'] ?? null ) ? $decl['parameters'] : array( 'type' => 'object' ),
			);
		}
		return $out;
	}

	private static function model( WP_Agent $agent ): string {
		$model = (string) ( $agent->get_default_config()['model'] ?? '' );
		return ( '' === $model || 'auto' === $model ) ? 'sonnet' : $model;
	}

	/**
	 * Tool results for the ids the bridge is waiting on, or null when the
	 * transcript does not carry all of them (the loop moved on some other way).
	 *
	 * @param array<int,array<string,mixed>> $messages Transcript.
	 * @param string[]                       $pending  Tool-call ids.
	 * @return array<string,array{content:string,is_error:bool}>|null
	 */
	public static function results_for( array $messages, array $pending ): ?array {
		$results = array();
		foreach ( $messages as $message ) {
			if ( 'tool_result' !== ( $message['type'] ?? '' ) ) {
				continue;
			}
			$meta = is_array( $message['metadata'] ?? null ) ? $message['metadata'] : array();
			$id   = (string) ( $meta['tool_call_id'] ?? '' );
			if ( ! in_array( $id, $pending, true ) ) {
				continue;
			}
			$payload        = is_array( $message['payload'] ?? null ) ? $message['payload'] : array();
			$failed         = array_key_exists( 'success', $payload ) && ! $payload['success'];
			$value          = array_key_exists( 'result', $payload ) ? $payload['result'] : ( $payload['error'] ?? ( $message['content'] ?? '' ) );
			$results[ $id ] = array(
				'content'  => is_string( $value ) ? $value : (string) wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'is_error' => $failed,
			);
		}
		return count( $results ) === count( $pending ) ? $results : null;
	}

	/**
	 * The transcript as one prompt: earlier turns as context, then the latest
	 * user message and anything already done in this turn.
	 *
	 * @param array<int,array<string,mixed>> $messages Model-facing transcript.
	 */
	public static function render_prompt( array $messages ): string {
		$messages = array_values( $messages );
		$current  = 0;
		foreach ( OpenclaWP_History_Window::turn_starts( $messages ) as $start ) {
			$current = $start;
		}

		$earlier = self::render_lines( array_slice( $messages, 0, $current ) );
		$latest  = array_slice( $messages, $current );
		$message = self::text_of( $latest[0]['content'] ?? '' );
		$done    = self::render_lines( array_slice( $latest, 1 ) );

		$prompt = '';
		if ( '' !== $earlier ) {
			$prompt .= "<conversation_so_far>\n" . $earlier . "\n</conversation_so_far>\n\n";
		}
		$prompt .= "<latest_user_message>\n" . $message . "\n</latest_user_message>";
		if ( '' !== $done ) {
			$prompt .= "\n\n<already_done_this_turn>\n" . $done . "\n</already_done_this_turn>";
		}
		return $prompt;
	}

	/** @param array<int,array<string,mixed>> $messages */
	private static function render_lines( array $messages ): string {
		$lines = array();
		foreach ( $messages as $message ) {
			$type    = (string) ( $message['type'] ?? 'text' );
			$payload = is_array( $message['payload'] ?? null ) ? $message['payload'] : array();
			if ( 'tool_call' === $type ) {
				$lines[] = 'Tool call: ' . OpenclaWP_Tools_Resolver::provider_name( (string) ( $payload['tool_name'] ?? '' ) ) . ' ' . wp_json_encode( $payload['parameters'] ?? array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
				continue;
			}
			if ( 'tool_result' === $type ) {
				$value   = array_key_exists( 'result', $payload ) ? $payload['result'] : ( $payload['error'] ?? ( $message['content'] ?? '' ) );
				$lines[] = 'Tool result: ' . ( is_string( $value ) ? $value : wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
				continue;
			}
			$text = self::text_of( $message['content'] ?? '' );
			if ( '' !== $text ) {
				$lines[] = ( 'user' === ( $message['role'] ?? '' ) ? 'User: ' : 'Assistant: ' ) . $text;
			}
		}
		return implode( "\n", $lines );
	}

	/**
	 * Map a bridge response onto the shape the conversation loop expects.
	 *
	 * @param array<int,array<string,mixed>> $messages Transcript.
	 * @param array<string,mixed>            $response Bridge response.
	 */
	public static function to_turn_result( WP_Agent $agent, string $session_id, array $messages, array $response, bool $mediation, float $start = 0.0 ): array {
		$status = (string) ( $response['status'] ?? 'error' );
		$text   = (string) ( $response['text'] ?? '' );

		if ( 'tool_calls' === $status && $mediation ) {
			$calls = array();
			$ids   = array();
			foreach ( (array) ( $response['tool_calls'] ?? array() ) as $call ) {
				$id      = (string) ( $call['id'] ?? '' );
				$ids[]   = $id;
				$calls[] = array(
					'id'         => $id,
					'name'       => OpenclaWP_Tools_Resolver::loop_name( (string) ( $call['name'] ?? '' ) ),
					'parameters' => is_array( $call['parameters'] ?? null ) ? $call['parameters'] : array(),
				);
			}
			self::$open[ $session_id ] = array(
				'conversation_id' => (string) ( $response['conversation_id'] ?? '' ),
				'pending'         => $ids,
			);
			$out = array(
				'messages'   => $messages,
				'tool_calls' => $calls,
			);
			if ( '' !== $text ) {
				$out['content'] = $text;
			}
			return $out;
		}

		if ( 'done' !== $status ) {
			$error = (string) ( $response['error'] ?? 'no response' );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Matches openclaWP's telemetry channel.
			error_log( sprintf( '[openclawp] claude_agent_error agent=%s session=%s error=%s', $agent->get_slug(), $session_id, $error ) );
			$text = '[claude-agent error: ' . $error . ']';
		}

		self::emit_telemetry( $agent, $session_id, $response, 'done' === $status, $start );

		if ( $mediation ) {
			return array(
				'messages'   => $messages,
				'tool_calls' => array(),
				'content'    => $text,
			);
		}
		$messages[] = array(
			'role'    => 'assistant',
			'content' => $text,
		);
		return array(
			'messages'               => $messages,
			'tool_execution_results' => array(),
			'conversation_complete'  => true,
		);
	}

	private static function emit_telemetry( WP_Agent $agent, string $session_id, array $response, bool $success, float $start ): void {
		$usage = is_array( $response['usage'] ?? null ) ? $response['usage'] : array();
		$model = '';
		foreach ( array_keys( (array) ( $response['model_usage'] ?? array() ) ) as $name ) {
			$model = (string) $name;
		}
		$input  = (int) ( $usage['input_tokens'] ?? 0 ) + (int) ( $usage['cache_read_input_tokens'] ?? 0 ) + (int) ( $usage['cache_creation_input_tokens'] ?? 0 );
		$output = (int) ( $usage['output_tokens'] ?? 0 );

		do_action(
			'openclawp_chat_turn_completed',
			array(
				'agent_slug'  => $agent->get_slug(),
				'session_id'  => $session_id,
				'provider'    => self::PROVIDER,
				'model'       => $model,
				'token_usage' => array(
					'input'  => $input,
					'output' => $output,
					'total'  => $input + $output,
				),
				'duration_ms' => $start > 0 ? (int) round( ( microtime( true ) - $start ) * 1000 ) : 0,
				'success'     => $success,
				'error'       => $success ? null : (string) ( $response['error'] ?? 'error' ),
			)
		);
	}

	/**
	 * @param array<string,mixed> $body Request body.
	 * @return array<string,mixed> Bridge response, or an error shape with `http_status`.
	 */
	private static function post( array $body ): array {
		$settings = self::settings();
		if ( '' === $settings['token'] ) {
			return array(
				'status' => 'error',
				'error'  => 'claude-agent bridge token is not configured',
			);
		}

		$response = wp_remote_post(
			$settings['url'] . '/v1/turn',
			array(
				'timeout' => $settings['timeout'],
				'headers' => array(
					'Authorization' => 'Bearer ' . $settings['token'],
					'Content-Type'  => 'application/json',
				),
				'body'    => (string) wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'status' => 'error',
				'error'  => 'bridge unreachable: ' . $response->get_error_message(),
			);
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$decoded = is_array( $decoded ) ? $decoded : array();
		if ( 200 !== $code ) {
			return array(
				'status'      => 'error',
				'http_status' => $code,
				'error'       => sprintf( 'bridge returned %d: %s', $code, (string) ( $decoded['error'] ?? '' ) ),
			);
		}
		return $decoded;
	}

	private static function text_of( $content ): string {
		if ( is_string( $content ) ) {
			return trim( $content );
		}
		$out = '';
		foreach ( (array) $content as $part ) {
			if ( is_string( $part ) ) {
				$out .= $part;
			} elseif ( is_array( $part ) && isset( $part['text'] ) ) {
				$out .= (string) $part['text'];
			}
		}
		return trim( $out );
	}
}
