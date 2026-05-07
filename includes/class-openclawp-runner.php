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

		$turn_runner = self::build_turn_runner( $agent, $session );

		$loop_options = array(
			'max_turns'             => 1,
			'transcript_lock'       => $store,
			'transcript_session_id' => $session_id,
			'transcript_lock_ttl'   => 120,
			'on_event'              => array( 'OpenclaWP_Event_Sink', 'observe' ),
		);

		$result = WP_Agent_Conversation_Loop::run( $messages, $turn_runner, $loop_options );

		$final_messages = isset( $result['messages'] ) && is_array( $result['messages'] ) ? $result['messages'] : $messages;

		$store->update_session( $session_id, $final_messages, array( 'agent_slug' => $agent_slug ) );

		return array(
			'session_id' => $session_id,
			'reply'      => self::extract_assistant_text( $final_messages ),
			'completed'  => true,
			'messages'   => $final_messages,
		);
	}

	/**
	 * Build the closure passed to the loop. Filterable so adopters can swap
	 * providers (e.g. Menta-flavored Gemini-OAuth) without touching this file.
	 *
	 * @param WP_Agent $agent   Registered agent definition.
	 * @param array    $session Current session snapshot.
	 *
	 * @return callable
	 */
	private static function build_turn_runner( WP_Agent $agent, array $session ): callable {
		$default_factory = static function ( WP_Agent $agent_obj ): callable {
			return static function ( array $messages, array $context ) use ( $agent_obj ): array {
				if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
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

				$builder = wp_ai_client_prompt( $messages );

				$description = $agent_obj->get_description();
				if ( '' !== $description && method_exists( $builder, 'using_system_instruction' ) ) {
					$builder = $builder->using_system_instruction( $description );
				}

				$generated = $builder->generate_text_result();

				$reply = '';
				if ( is_object( $generated ) ) {
					if ( method_exists( $generated, 'to_text' ) ) {
						$reply = (string) $generated->to_text();
					} elseif ( method_exists( $generated, '__toString' ) ) {
						$reply = (string) $generated;
					} elseif ( method_exists( $generated, 'text' ) ) {
						$reply = (string) $generated->text();
					}
				} elseif ( is_string( $generated ) ) {
					$reply = $generated;
				}

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

	private static function extract_assistant_text( array $messages ): string {
		for ( $i = count( $messages ) - 1; $i >= 0; --$i ) {
			$message = $messages[ $i ];
			$role    = $message['role'] ?? '';
			if ( 'assistant' !== $role ) {
				continue;
			}
			$content = $message['content'] ?? '';
			if ( is_string( $content ) ) {
				return $content;
			}
			if ( is_array( $content ) ) {
				$out = '';
				foreach ( $content as $part ) {
					if ( is_array( $part ) && isset( $part['text'] ) ) {
						$out .= (string) $part['text'];
					} elseif ( is_string( $part ) ) {
						$out .= $part;
					}
				}
				return $out;
			}
		}
		return '';
	}
}
