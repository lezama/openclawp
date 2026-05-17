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
use AgentsAPI\Core\Database\Chat\WP_Agent_Conversation_Store;
use AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope;

final class OpenclaWP_Runner {

	/**
	 * Run a single chat exchange.
	 *
	 * @param string      $agent_slug   Registered agent slug.
	 * @param string      $message      Latest user message.
	 * @param string|null $session_id   Existing session UUID, or null to create one.
	 * @param int         $user_id      WP user ID owning the session.
	 * @param array       $runtime_context Channel/runtime context for tool calls.
	 *
	 * @return array{session_id:string,reply:string,completed:bool,messages:array,error?:string}
	 */
	public static function run_turn( string $agent_slug, string $message, ?string $session_id, int $user_id, array $runtime_context = array() ): array {
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
			$session_id = $store->create_session(
				$workspace,
				$user_id,
				$agent_slug,
				self::session_metadata_from_runtime_context( $runtime_context ),
				'chat'
			);
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

		$preflight = apply_filters(
			'openclawp_pre_chat_turn',
			null,
			array(
				'agent_slug'      => $agent_slug,
				'message'         => $message,
				'session_id'      => $session_id,
				'user_id'         => $user_id,
				'runtime_context' => $runtime_context,
				'session'         => $session,
			)
		);
		if ( is_wp_error( $preflight ) ) {
			return array(
				'session_id' => $session_id,
				'reply'      => '',
				'completed'  => true,
				'messages'   => $session['messages'],
				'error'      => $preflight->get_error_message(),
			);
		}
		if ( is_array( $preflight ) ) {
			$reply          = (string) ( $preflight['reply'] ?? '' );
			$final_messages = isset( $preflight['messages'] ) && is_array( $preflight['messages'] )
				? $preflight['messages']
				: self::append_preflight_messages( $session['messages'], $message, $reply );

			$store->update_session( $session_id, $final_messages );

			$result = array(
				'session_id' => $session_id,
				'reply'      => $reply,
				'completed'  => (bool) ( $preflight['completed'] ?? true ),
				'messages'   => $final_messages,
			);

			// Pass-through channel-specific payloads (e.g. WhatsApp interactive
			// buttons / lists) so the caller's transport layer can act on them.
			foreach ( array( 'interactive', 'attachments', 'metadata' ) as $passthrough_key ) {
				if ( isset( $preflight[ $passthrough_key ] ) ) {
					$result[ $passthrough_key ] = $preflight[ $passthrough_key ];
				}
			}

			return $result;
		}

		// Stamp session/agent/user/channel onto every span emitted during
		// this turn. Tracer is a no-op when no OTLP endpoint is configured,
		// so this is cheap when disabled.
		if ( class_exists( 'OpenclaWP_Tracer' ) ) {
			$channel = '';
			if ( isset( $runtime_context['channel'] ) && is_string( $runtime_context['channel'] ) ) {
				$channel = $runtime_context['channel'];
			} elseif ( isset( $runtime_context['client_context']['channel'] ) && is_string( $runtime_context['client_context']['channel'] ) ) {
				$channel = $runtime_context['client_context']['channel'];
			}
			OpenclaWP_Tracer::set_runtime_context(
				array(
					'openclawp.session.id' => $session_id,
					'openclawp.agent.slug' => $agent_slug,
					'openclawp.user.id'    => (string) $user_id,
					'openclawp.channel'    => $channel,
				)
			);
		}

		$messages   = $session['messages'];
		$messages[] = array(
			'role'    => 'user',
			'content' => $message,
		);

		$tools         = OpenclaWP_Tools_Resolver::for_agent( $agent );
		$has_tools     = ! empty( $tools['name_to_ability'] ) || ! empty( $tools['delegate_targets'] );
		$tool_executor = $has_tools
			? new OpenclaWP_Tool_Executor(
				$tools['name_to_ability'],
				$tools['delegate_targets'] ?? array(),
				$runtime_context
			)
			: null;
		$max_turns     = (int) ( ( $agent->get_default_config()['max_turns'] ?? 5 ) );

		$turn_runner = self::build_turn_runner(
			$agent,
			$session,
			$session_id,
			$tools['declarations_for_provider']
		);

		// Don't pass on_event — OpenclaWP_Event_Sink already subscribes to the canonical
		// `agents_api_loop_event` action. Doubling up would log every event twice.
		$loop_options = array(
			'max_turns'             => max( 1, $max_turns ),
			'transcript_lock'       => $store,
			'transcript_session_id' => $session_id,
			'transcript_lock_ttl'   => 300,
		);
		if ( null !== $tool_executor ) {
			// Canonical's loop defaults `should_continue` to continue-always when
			// `tool_executor` + `tool_declarations` are both present (agents-api
			// PR #97), so we don't need to override it.
			$loop_options['tool_executor']     = $tool_executor;
			$loop_options['tool_declarations'] = $tools['declarations'];
			$loop_options['context']           = self::tool_context_from_runtime_context( $runtime_context );
		}

		$result = WP_Agent_Conversation_Loop::run( $messages, $turn_runner, $loop_options );

		$final_messages = isset( $result['messages'] ) && is_array( $result['messages'] ) ? $result['messages'] : $messages;

		// Slug already stored on the session at create-time (third arg to
		// `create_session`). No need to mirror it back into metadata —
		// the metadata payload is for *additional* per-update annotations,
		// not the durable agent identity.
		$store->update_session( $session_id, $final_messages );

		return array(
			'session_id' => $session_id,
			'reply'      => OpenclaWP_Message_Adapter::last_assistant_text( $final_messages ),
			'completed'  => true,
			'messages'   => $final_messages,
		);
	}

	private static function session_metadata_from_runtime_context( array $runtime_context ): array {
		$client_context = isset( $runtime_context['client_context'] ) && is_array( $runtime_context['client_context'] )
			? $runtime_context['client_context']
			: array();

		return empty( $client_context ) ? array() : array( 'client_context' => $client_context );
	}

	private static function tool_context_from_runtime_context( array $runtime_context ): array {
		$client_context = isset( $runtime_context['client_context'] ) && is_array( $runtime_context['client_context'] )
			? $runtime_context['client_context']
			: array();
		$sender_id      = (string) ( $client_context['sender_id'] ?? $client_context['external_conversation_id'] ?? '' );

		$context = array(
			'client_context' => $client_context,
		);
		if ( '' !== $sender_id ) {
			$context['user_phone']         = $sender_id;
			$context['whatsapp_recipient'] = $sender_id;
		}

		return (array) apply_filters( 'openclawp_tool_runtime_context', $context, $runtime_context );
	}

	private static function append_preflight_messages( array $messages, string $message, string $reply ): array {
		$messages[] = array(
			'role'    => 'user',
			'content' => $message,
		);

		if ( '' !== $reply ) {
			$messages[] = array(
				'role'    => 'assistant',
				'content' => $reply,
			);
		}

		return $messages;
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
	private static function build_turn_runner( WP_Agent $agent, array $session, string $session_id, array $function_declarations ): callable {
		$default_factory = static function ( WP_Agent $agent_obj ) use ( $session_id, $function_declarations ): callable {
			return static function ( array $messages, array $context ) use ( $agent_obj, $session_id, $function_declarations ): array {
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

				$mediation_enabled = ! empty( $function_declarations );

				if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
					$telemetry['error'] = 'wp_ai_client_prompt_unavailable';
					self::emit_chat_telemetry( $telemetry );

					return self::make_turn_result(
						$messages,
						'[wp_ai_client_prompt() unavailable. Configure a WP AI Client connector or override openclawp_turn_runner_factory.]',
						array(),
						$mediation_enabled
					);
				}

				$ai_messages = OpenclaWP_Message_Adapter::to_ai_client_messages( $messages );

				$builder = wp_ai_client_prompt( $ai_messages );
				if ( $mediation_enabled ) {
					$builder = $builder->using_function_declarations( ...$function_declarations );
				}

				// WP_AI_Client_Prompt_Builder forwards these via __call, so
				// method_exists() returns false for them — call directly.
				$description = $agent_obj->get_description();
				if ( '' !== $description ) {
					$builder = $builder->using_system_instruction( $description );
				}

				// Respect the agent's declared provider + model when set. Without
				// this, wp-ai-client's auto-selection picks whichever model the
				// active provider plugin happens to surface — which on Ollama can
				// drift from what the site admin actually configured.
				$preference = self::resolve_model_preference( $agent_obj );
				if ( null !== $preference ) {
					$builder = $builder->using_model_preference( $preference );
				}

				$start_us  = microtime( true );
				$generated = $builder->generate_text_result();
				$telemetry['duration_ms'] = (int) round( ( microtime( true ) - $start_us ) * 1000 );

				if ( is_wp_error( $generated ) ) {
					$telemetry['error'] = (string) $generated->get_error_code();
					self::emit_chat_telemetry( $telemetry );

					return self::make_turn_result(
						$messages,
						'[provider error: ' . $generated->get_error_message() . ']',
						array(),
						$mediation_enabled
					);
				}

				$telemetry['success']     = true;
				$telemetry['provider']    = self::extract_provider_id( $generated );
				$telemetry['model']       = self::extract_model_id( $generated );
				$telemetry['token_usage'] = self::extract_token_usage( $generated );

				$tool_calls = self::extract_tool_calls( $generated );
				$telemetry['tool_call_count'] = count( $tool_calls );
				// `toText()` throws "No text content found in first candidate"
				// when the model responded with only tool calls (a normal
				// outcome on the first mediation turn for tool-heavy agents
				// like coordinators delegating to subagents). Swallow that
				// case as empty text — the loop's mediation path will pick
				// up the tool calls regardless.
				$assistant_txt = '';
				if ( method_exists( $generated, 'toText' ) ) {
					try {
						$assistant_txt = (string) $generated->toText();
					} catch ( \Throwable $e ) {
						$assistant_txt = '';
					}
				}

				self::emit_chat_telemetry( $telemetry );

				return self::make_turn_result( $messages, $assistant_txt, $tool_calls, $mediation_enabled );
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
	 * Resolve the agent's preferred provider + model into the shape
	 * `usingModelPreference()` accepts.
	 *
	 *  - Both `provider` and `model` set (and not 'auto') → `[provider, model]` tuple
	 *    (locks both axes; SDK will error rather than fall back).
	 *  - Only `model` set → plain string (matches across providers).
	 *  - Neither set or both 'auto' → null (don't pin; provider plugin's
	 *    auto-selection wins).
	 *
	 * @return array{0:string,1:string}|string|null
	 */
	private static function resolve_model_preference( WP_Agent $agent ) {
		$config   = $agent->get_default_config();
		$model    = isset( $config['model'] ) && is_string( $config['model'] ) ? trim( $config['model'] ) : '';
		$provider = isset( $config['provider'] ) && is_string( $config['provider'] ) ? trim( $config['provider'] ) : '';

		if ( '' === $model || 'auto' === $model ) {
			return null;
		}

		if ( '' !== $provider && 'auto' !== $provider ) {
			return array( $provider, $model );
		}

		return $model;
	}

	/**
	 * Build a turn-runner result that's correctly shaped for whichever loop
	 * path the caller is using.
	 *
	 * - Mediation enabled (tool declarations present): return `messages` +
	 *   `tool_calls` + optional `content`. The loop's mediation path appends
	 *   the assistant message and (when tool_calls is empty) marks
	 *   `conversation_complete = true`, breaking the loop after turn N.
	 * - Mediation disabled: return the legacy shape with the assistant message
	 *   already appended and `conversation_complete = true`.
	 *
	 * @param array<int,array<string,mixed>>     $messages          Current transcript.
	 * @param string                             $assistant_text    Assistant text reply (may be empty).
	 * @param array<int,array{name:string,parameters:array}> $tool_calls Tool calls (empty when none).
	 * @param bool                               $mediation_enabled Whether the loop is in mediation mode.
	 * @return array
	 */
	private static function make_turn_result( array $messages, string $assistant_text, array $tool_calls, bool $mediation_enabled ): array {
		if ( $mediation_enabled ) {
			$out = array(
				'messages'   => $messages,
				'tool_calls' => $tool_calls,
			);
			if ( '' !== $assistant_text ) {
				$out['content'] = $assistant_text;
			}
			return $out;
		}

		return array(
			'messages' => array_merge(
				$messages,
				array(
					array(
						'role'    => 'assistant',
						'content' => $assistant_text,
					),
				)
			),
			'tool_execution_results' => array(),
			'conversation_complete'  => true,
		);
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

	/**
	 * Extract tool calls from a GenerativeAiResult, in the shape
	 * `[ ['name' => '…', 'parameters' => […]], … ]` that
	 * `WP_Agent_Conversation_Loop::mediate_tool_calls()` consumes.
	 *
	 * @param mixed $result
	 * @return array<int, array{name:string, parameters:array}>
	 */
	private static function extract_tool_calls( $result ): array {
		if ( ! is_object( $result ) || ! method_exists( $result, 'toMessage' ) ) {
			return array();
		}

		try {
			$message = $result->toMessage();
		} catch ( \Throwable $e ) {
			return array();
		}

		if ( ! is_object( $message ) || ! method_exists( $message, 'getParts' ) ) {
			return array();
		}

		$out = array();
		foreach ( $message->getParts() as $part ) {
			if ( ! is_object( $part ) || ! method_exists( $part, 'getType' ) ) {
				continue;
			}
			$type = $part->getType();
			$type_value = is_object( $type ) && method_exists( $type, 'value' ) ? $type->value() : (string) $type;
			if ( 'function_call' !== $type_value && 'functionCall' !== $type_value ) {
				continue;
			}

			if ( ! method_exists( $part, 'getFunctionCall' ) ) {
				continue;
			}

			$fc = $part->getFunctionCall();
			if ( ! is_object( $fc ) ) {
				continue;
			}

			$name = method_exists( $fc, 'getName' ) ? (string) $fc->getName() : '';
			$args = method_exists( $fc, 'getArgs' ) ? $fc->getArgs() : array();

			if ( '' === $name ) {
				continue;
			}

			$out[] = array(
				'name'       => $name,
				'parameters' => is_array( $args ) ? $args : array(),
			);
		}

		return $out;
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
