<?php
/**
 * Conversation runner.
 *
 * Thin wrapper around `\AgentsAPI\AI\WP_Agent_Conversation_Loop::run()`. Owns
 * session lifecycle (create/load), turn-runner construction, and result
 * normalization. The provider call is the only provider-coupled bit and lives
 * inside the turn runner closure (`build_turn_runner()`).
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

use AgentsAPI\AI\WP_Agent_Conversation_Loop;
use AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope;

final class OpenclaWP_Runner {

	/**
	 * Run a single chat exchange.
	 *
	 * @param string      $agent_slug   Registered agent slug.
	 * @param string      $message      Latest user message.
	 * @param string|null $session_id   Existing session UUID, or null to create one.
	 * @param int         $user_id      WP user ID owning the session.
	 *
	 * @return array{session_id:string,reply:string,completed:bool,messages:array,error?:string}
	 */
	public static function run_turn( string $agent_slug, string $message, ?string $session_id, int $user_id ): array {
		$agent = wp_get_agent( $agent_slug );
		if ( null === $agent ) {
			return array(
				'session_id' => (string) $session_id,
				'reply'      => '',
				'completed'  => true,
				'messages'   => array(),
				'error'      => sprintf( 'Unknown agent slug: %s', $agent_slug ),
			);
		}

		$store = OpenclaWP_Conversation_Store::instance();

		if ( null === $session_id || '' === trim( $session_id ) ) {
			$workspace  = new WP_Agent_Workspace_Scope( 'site', (string) get_current_blog_id() );
			$session_id = $store->create_session( $workspace, $user_id, 0, array( 'agent_slug' => $agent_slug ), 'chat' );
			if ( '' === $session_id ) {
				return array(
					'session_id' => '',
					'reply'      => '',
					'completed'  => true,
					'messages'   => array(),
					'error'      => 'Failed to create conversation session.',
				);
			}
		}

		$session = $store->get_session( $session_id );
		if ( null === $session ) {
			return array(
				'session_id' => $session_id,
				'reply'      => '',
				'completed'  => true,
				'messages'   => array(),
				'error'      => 'Session not found.',
			);
		}

		$messages   = $session['messages'];
		$messages[] = array(
			'role'    => 'user',
			'content' => $message,
		);

		$turn_runner = self::build_turn_runner( $agent, $session, $session_id );

		// Don't pass on_event — OpenclaWP_Event_Sink already subscribes to the canonical
		// `agents_api_loop_event` action. Doubling up would log every event twice.
		$loop_options = array(
			'max_turns'             => 1,
			'transcript_lock'       => $store,
			'transcript_session_id' => $session_id,
			'transcript_lock_ttl'   => 120,
		);

		$result = WP_Agent_Conversation_Loop::run( $messages, $turn_runner, $loop_options );

		$final_messages = isset( $result['messages'] ) && is_array( $result['messages'] ) ? $result['messages'] : $messages;

		$store->update_session( $session_id, $final_messages, array( 'agent_slug' => $agent_slug ) );

		return array(
			'session_id' => $session_id,
			'reply'      => OpenclaWP_Message_Adapter::last_assistant_text( $final_messages ),
			'completed'  => true,
			'messages'   => $final_messages,
		);
	}

	/**
	 * Build the closure passed to the loop. Filterable so adopters can swap
	 * providers (e.g. Menta-flavored Gemini-OAuth) without touching this file.
	 *
	 * @param WP_Agent $agent      Registered agent definition.
	 * @param array    $session    Current session snapshot.
	 * @param string   $session_id Session UUID for telemetry attribution.
	 *
	 * @return callable
	 */
	private static function build_turn_runner( WP_Agent $agent, array $session, string $session_id ): callable {
		$default_factory = static function ( WP_Agent $agent_obj ) use ( $session_id ): callable {
			return static function ( array $messages, array $context ) use ( $agent_obj, $session_id ): array {
				$telemetry = array(
					'agent_slug'  => $agent_obj->get_slug(),
					'session_id'  => $session_id,
					'provider'    => '',
					'model'       => '',
					'token_usage' => array(),
					'duration_ms' => 0,
					'success'     => false,
					'error'       => null,
				);

				if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
					$telemetry['error'] = 'wp_ai_client_prompt_unavailable';
					self::emit_chat_telemetry( $telemetry );

					return array(
						'messages' => array_merge(
							$messages,
							array(
								array(
									'role'    => 'assistant',
									'content' => '[wp_ai_client_prompt() unavailable. Configure a WP AI Client connector or override openclawp_turn_runner_factory.]',
								),
							)
						),
						'tool_execution_results' => array(),
						'conversation_complete'  => true,
					);
				}

				$ai_messages = OpenclaWP_Message_Adapter::to_ai_client_messages( $messages );

				$start_us  = microtime( true );
				$generated = wp_ai_client_prompt( $ai_messages )->generate_text_result();
				$telemetry['duration_ms'] = (int) round( ( microtime( true ) - $start_us ) * 1000 );

				$reply = '';
				if ( is_wp_error( $generated ) ) {
					$reply              = '[provider error: ' . $generated->get_error_message() . ']';
					$telemetry['error'] = (string) $generated->get_error_code();
				} elseif ( is_object( $generated ) ) {
					$telemetry['success']     = true;
					$telemetry['provider']    = self::extract_provider_id( $generated );
					$telemetry['model']       = self::extract_model_id( $generated );
					$telemetry['token_usage'] = self::extract_token_usage( $generated );

					if ( method_exists( $generated, 'toText' ) ) {
						$reply = (string) $generated->toText();
					}
				} elseif ( is_string( $generated ) ) {
					$reply               = $generated;
					$telemetry['success'] = true;
				}

				self::emit_chat_telemetry( $telemetry );

				return array(
					'messages' => array_merge(
						$messages,
						array(
							array(
								'role'    => 'assistant',
								'content' => $reply,
							),
						)
					),
					'tool_execution_results' => array(),
					'conversation_complete'  => true,
				);
			};
		};

		/**
		 * Filters the factory that builds the turn runner closure.
		 *
		 * Return a `callable( WP_Agent $agent ): callable` factory. Use this hook
		 * to swap providers (Menta Gemini-OAuth, Anthropic, etc.) without forking
		 * openclaWP. The factory receives the agent and must return the closure
		 * passed into `WP_Agent_Conversation_Loop::run()`.
		 *
		 * @param callable $default_factory Default factory (wraps wp_ai_client_prompt).
		 * @param array    $session         Session snapshot.
		 */
		$factory = apply_filters( 'openclawp_turn_runner_factory', $default_factory, $session );

		return call_user_func( $factory, $agent );
	}

	/**
	 * Emit a structured telemetry event for one chat turn.
	 *
	 * Subscribers (the bundled event sink, plus anything else that hooks
	 * `openclawp_chat_turn_completed`) receive a snapshot of agent slug,
	 * session id, provider/model, token usage, wall duration, and
	 * success/error.
	 *
	 * @param array $telemetry Read-only snapshot. See doc comment in
	 *                         build_turn_runner() for the shape.
	 */
	private static function emit_chat_telemetry( array $telemetry ): void {
		/**
		 * Fires after each chat turn — with or without a successful provider call.
		 *
		 * Telemetry shape:
		 *   - agent_slug:  string
		 *   - session_id:  string (UUIDv4)
		 *   - provider:    string ("anthropic", "ollama", "")
		 *   - model:       string ("claude-opus-4-7", "gemma4:26b", "")
		 *   - token_usage: array{input?:int,output?:int,total?:int}
		 *   - duration_ms: int  (0 when the provider was never reached)
		 *   - success:     bool
		 *   - error:       ?string  (provider error code, or null on success)
		 *
		 * @param array $telemetry Read-only snapshot.
		 */
		do_action( 'openclawp_chat_turn_completed', $telemetry );
	}

	private static function extract_provider_id( $result ): string {
		if ( ! is_object( $result ) || ! method_exists( $result, 'getProviderMetadata' ) ) {
			return '';
		}
		$pm = $result->getProviderMetadata();
		if ( ! is_object( $pm ) ) {
			return '';
		}
		return method_exists( $pm, 'getId' ) ? (string) $pm->getId() : '';
	}

	private static function extract_model_id( $result ): string {
		if ( ! is_object( $result ) || ! method_exists( $result, 'getModelMetadata' ) ) {
			return '';
		}
		$mm = $result->getModelMetadata();
		if ( ! is_object( $mm ) ) {
			return '';
		}
		return method_exists( $mm, 'getId' ) ? (string) $mm->getId() : '';
	}

	private static function extract_token_usage( $result ): array {
		if ( ! is_object( $result ) || ! method_exists( $result, 'getTokenUsage' ) ) {
			return array();
		}
		$usage = $result->getTokenUsage();
		if ( ! is_object( $usage ) ) {
			return array();
		}
		$out = array();
		foreach ( array( 'getPromptTokens' => 'input', 'getCompletionTokens' => 'output', 'getTotalTokens' => 'total' ) as $method => $key ) {
			if ( method_exists( $usage, $method ) ) {
				$out[ $key ] = (int) $usage->{$method}();
			}
		}
		return $out;
	}
}
