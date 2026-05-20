<?php
/**
 * Pure-PHP tests for budget limit math.
 *
 * @package OpenclaWP\Tests
 */

declare( strict_types=1 );

namespace OpenclaWP\Tests\Unit;

use OpenclaWP_Budget_Guard;
use PHPUnit\Framework\TestCase;

/**
 * @covers OpenclaWP_Budget_Guard
 */
final class BudgetGuardTest extends TestCase {

	protected function tearDown(): void {
		$GLOBALS['openclawp_test_options'] = array();
		$GLOBALS['openclawp_test_filters'] = array();
		parent::tearDown();
	}

	public function test_limits_normalize_options(): void {
		$GLOBALS['openclawp_test_options']['openclawp_options'] = array(
			'budget_daily_usd'               => '1.25',
			'budget_monthly_turns'           => '100',
			'budget_max_tool_calls_per_turn' => '4',
		);

		$limits = OpenclaWP_Budget_Guard::limits();

		$this->assertSame( 1.25, $limits['daily_usd'] );
		$this->assertSame( 100, $limits['monthly_turns'] );
		$this->assertSame( 4, $limits['max_tool_calls_per_turn'] );
	}

	public function test_exceeded_limits_blocks_when_cost_reaches_cap(): void {
		$result = OpenclaWP_Budget_Guard::exceeded_limits(
			array( 'turns' => 1, 'est_cost_usd' => 5.0 ),
			array( 'daily_usd' => 5.0, 'daily_turns' => 0 ),
			'day',
			'site'
		);

		$this->assertSame( 'estimated_cost_usd', $result['metric'] );
		$this->assertSame( 5.0, $result['limit'] );
		$this->assertSame( 5.0, $result['actual'] );
	}

	public function test_exceeded_limits_uses_agent_scope_keys(): void {
		$result = OpenclaWP_Budget_Guard::exceeded_limits(
			array( 'turns' => 3, 'est_cost_usd' => 0.2 ),
			array( 'agent_daily_turns' => 3, 'daily_turns' => 99 ),
			'day',
			'agent'
		);

		$this->assertSame( 'turns', $result['metric'] );
		$this->assertSame( 3, $result['limit'] );
		$this->assertSame( 3, $result['actual'] );
	}

	public function test_tool_execute_limit_returns_wp_error(): void {
		$GLOBALS['openclawp_test_options']['openclawp_options'] = array(
			'budget_max_tool_calls_per_turn' => 2,
		);

		$result = OpenclaWP_Budget_Guard::pre_tool_execute(
			null,
			array(
				'tool_call_index' => 3,
				'runtime_context' => array(),
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'openclawp_tool_budget_exceeded', $result->get_error_code() );
	}
}
