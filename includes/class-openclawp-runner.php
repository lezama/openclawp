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

				$ai_messages = self::to_ai_client_messages( $messages );
				$builder     = wp_ai_client_prompt( $ai_messages );

				$generated = $builder->generate_text_result();

				$reply = '';
				if ( is_wp_error( $generated ) ) {
					$reply = '[provider error: ' . $generated->get_error_message() . ']';
				} elseif ( is_object( $generated ) && method_exists( $generated, 'toText' ) ) {
					$reply = (string) $generated->toText();
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

	/**
	 * Convert the loop's transcript shape (`[{role, content}, ...]`) into a list of
	 * WP AI Client `Message` DTOs.
	 *
	 * Roles map: user → user, assistant → model. Tool / system messages are
	 * skipped for the MVP — the loop's tool mediation isn't wired yet.
	 *
	 * @param array<int,array<string,mixed>> $messages Transcript messages.
	 * @return array
	 */
	private static function to_ai_client_messages( array $messages ): array {
		if ( ! class_exists( '\\WordPress\\AiClient\\Messages\\DTO\\Message' ) ) {
			return $messages;
		}

		$out = array();
		foreach ( $messages as $message ) {
			$role    = (string) ( $message['role'] ?? '' );
			$content = $message['content'] ?? '';

			$text = '';
			if ( is_string( $content ) ) {
				$text = $content;
			} elseif ( is_array( $content ) ) {
				$text = '';
				foreach ( $content as $part ) {
					if ( is_string( $part ) ) {
						$text .= $part;
					} elseif ( is_array( $part ) && isset( $part['text'] ) ) {
						$text .= (string) $part['text'];
					}
				}
			}

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
