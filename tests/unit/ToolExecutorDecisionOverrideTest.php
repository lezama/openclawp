<?php
/**
 * Unit tests for OpenclaWP tool decision overrides.
 *
 * @package OpenclaWP\Tests
 */

declare( strict_types=1 );

namespace AgentsAPI\AI\Tools;

if ( ! interface_exists( WP_Agent_Tool_Executor::class ) ) {
	interface WP_Agent_Tool_Executor {
		public function executeWP_Agent_Tool_Call( array $tool_call, array $tool_definition, array $context = array() ): array;
	}
}

namespace OpenclaWP\Tests\Unit;

use OpenclaWP_Tool_Executor;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @covers OpenclaWP_Tool_Executor
 */
final class ToolExecutorDecisionOverrideTest extends TestCase {

	public function test_decision_override_without_parameters_allows_matching_ability(): void {
		$this->assertTrue(
			self::override_allows(
				array( 'ability' => 'carpeta/delete-potreros' ),
				'carpeta/delete-potreros',
				array( 'ids' => array( 1 ), 'confirm' => true )
			)
		);
	}

	public function test_decision_override_with_matching_parameters_allows_call(): void {
		$this->assertTrue(
			self::override_allows(
				array(
					'ability'    => 'carpeta/delete-potreros',
					'parameters' => array(
						'confirm' => true,
						'ids'     => array( 1312, 1313 ),
					),
				),
				'carpeta/delete-potreros',
				array(
					'ids'     => array( 1312, 1313 ),
					'confirm' => true,
				)
			)
		);
	}

	public function test_decision_override_with_different_parameters_does_not_allow_call(): void {
		$this->assertFalse(
			self::override_allows(
				array(
					'ability'    => 'carpeta/delete-potreros',
					'parameters' => array(
						'ids'     => array( 1312, 1313 ),
						'confirm' => true,
					),
				),
				'carpeta/delete-potreros',
				array(
					'ids'     => array( 1312, 9999 ),
					'confirm' => true,
				)
			)
		);
	}

	private static function override_allows( array $override, string $ability, array $parameters ): bool {
		$method = new ReflectionMethod( OpenclaWP_Tool_Executor::class, 'decision_override_allows' );
		$method->setAccessible( true );

		return (bool) $method->invoke( null, $override, $ability, $parameters );
	}
}
