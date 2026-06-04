<?php
/**
 * Unit tests for Agenttic client-context forwarding.
 *
 * @package OpenclaWP\Tests
 */

declare( strict_types=1 );

namespace {
	if ( ! class_exists( 'WP_REST_Request' ) ) {
		class WP_REST_Request {
			/** @param array<string,mixed> $params */
			public function __construct(
				private array $params = array(),
				private array $json = array(),
				private array $headers = array()
			) {}
			public function get_param( string $name ) {
				return $this->params[ $name ] ?? null;
			}
			public function get_json_params(): array {
				return $this->json;
			}
			public function get_header( string $name ) {
				return $this->headers[ strtolower( $name ) ] ?? null;
			}
		}
	}

	if ( ! class_exists( 'WP_REST_Response' ) ) {
		class WP_REST_Response {
			public function __construct( private $data = null, private int $status = 200 ) {}
			public function get_data() {
				return $this->data;
			}
			public function get_status(): int {
				return $this->status;
			}
		}
	}

	if ( ! function_exists( 'wp_get_ability' ) ) {
		function wp_get_ability( string $name ) {
			return $GLOBALS['openclawp_test_abilities_registry'][ $name ] ?? null;
		}
	}
}

namespace OpenclaWP\Tests\Unit {

	use OpenclaWP_Agenttic_Bridge;
	use OpenclaWP_Runner;
	use PHPUnit\Framework\TestCase;
	use WP_REST_Request;

/**
 * @covers OpenclaWP_Agenttic_Bridge
 * @covers OpenclaWP_Runner
 */
	final class AgentticClientContextTest extends TestCase {

		protected function tearDown(): void {
			unset( $GLOBALS['openclawp_test_abilities_registry'] );
			parent::tearDown();
		}

		public function test_bridge_extracts_agenttic_client_context_data_part(): void {
			$message = array(
				'role'  => 'user',
				'parts' => array(
					array(
						'type' => 'text',
						'text' => 'Sí, borrar',
					),
					array(
						'type' => 'data',
						'data' => array(
							'clientContext' => array(
								'carpeta_pending_confirmation' => array(
									'type' => 'delete-potreros',
									'ids'  => array( 1312, 1313 ),
								),
							),
						),
					),
				),
			);

			$context = OpenclaWP_Agenttic_Bridge::client_context_from_message( $message );

			$this->assertSame( 'agenttic', $context['source'] );
			$this->assertSame( 'agenttic-client', $context['client_name'] );
			$this->assertSame(
				array(
					'type' => 'delete-potreros',
					'ids'  => array( 1312, 1313 ),
				),
				$context['carpeta_pending_confirmation']
			);
		}

		public function test_runner_injects_client_context_into_model_messages_only(): void {
			$messages = array(
				array(
					'role'    => 'assistant',
					'content' => '¿Borro estos 2 potreros?',
				),
				array(
					'role'    => 'user',
					'content' => 'Sí, borrar',
				),
			);

			$for_model = OpenclaWP_Runner::messages_with_client_context(
				$messages,
				array(
					'client_context' => array(
						'carpeta_pending_confirmation' => array(
							'type' => 'delete-potreros',
							'ids'  => array( 1312, 1313 ),
						),
					),
				)
			);

			$this->assertSame( 'Sí, borrar', $messages[1]['content'] );
			$this->assertStringContainsString( 'Sí, borrar', $for_model[1]['content'] );
			$this->assertStringContainsString( '[Client context for this turn]', $for_model[1]['content'] );
			$this->assertStringContainsString( '"carpeta_pending_confirmation":{"type":"delete-potreros","ids":[1312,1313]}', $for_model[1]['content'] );
		}

		public function test_agenttic_bridge_invokes_openclawp_chat_with_product_client_context(): void {
			$captured = null;
			$GLOBALS['openclawp_test_abilities_registry'] = array(
				'openclawp/chat' => new class( $captured ) {
					public function __construct( private &$captured ) {}
					public function execute( array $args ): array {
						$this->captured = $args;
						return array(
							'session_id' => 'session-1',
							'reply'      => 'ok',
							'completed'  => true,
						);
					}
				},
			);

			$request = new WP_REST_Request(
				array( 'agent' => 'carpeta-bot' ),
				array(
					'jsonrpc' => '2.0',
					'id'      => 'req-1',
					'method'  => 'message/send',
					'params'  => array(
						'id'      => 'task-1',
						'message' => array(
							'role'  => 'user',
							'parts' => array(
								array(
									'type' => 'text',
									'text' => 'Sí, borrar',
								),
								array(
									'type' => 'data',
									'data' => array(
										'clientContext' => array(
											'carpeta_pending_confirmation' => array(
												'type' => 'delete-potreros',
												'ids'  => array( 1312, 1313 ),
											),
										),
									),
								),
							),
						),
					),
				)
			);

			$response = OpenclaWP_Agenttic_Bridge::handle( $request );

			$this->assertSame( 200, $response->get_status() );
			$this->assertSame( 'ok', $response->get_data()['result']['status']['message']['parts'][0]['text'] );
			$this->assertSame( 'carpeta-bot', $captured['agent'] );
			$this->assertSame( 'Sí, borrar', $captured['message'] );
			$this->assertSame(
				array(
					'type' => 'delete-potreros',
					'ids'  => array( 1312, 1313 ),
				),
				$captured['client_context']['carpeta_pending_confirmation']
			);
			$this->assertArrayNotHasKey( 'agents/chat', $GLOBALS['openclawp_test_abilities_registry'] );
		}
	}
}
