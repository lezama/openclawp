<?php
/**
 * Unit tests for Agenttic client-context forwarding.
 *
 * @package OpenclaWP\Tests
 */

declare( strict_types=1 );

namespace OpenclaWP\Tests\Unit;

use OpenclaWP_Agenttic_Bridge;
use OpenclaWP_Runner;
use PHPUnit\Framework\TestCase;

/**
 * @covers OpenclaWP_Agenttic_Bridge
 * @covers OpenclaWP_Runner
 */
final class AgentticClientContextTest extends TestCase {

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
}
