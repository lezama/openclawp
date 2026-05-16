<?php
/**
 * Pure-PHP unit tests for OpenclaWP_Usage_Recorder cost math.
 *
 * Covers estimate_cost() and the openclawp_model_pricing filter wiring.
 * Integration tests that require a real CPT (wp_insert_post, post_meta)
 * live in tests/smoke.php and run inside a Studio site.
 *
 * @package OpenclaWP\Tests
 */

declare( strict_types=1 );

namespace OpenclaWP\Tests\Unit;

use OpenclaWP_Usage_Recorder;
use PHPUnit\Framework\TestCase;

/**
 * @covers OpenclaWP_Usage_Recorder
 */
final class UsageRecorderTest extends TestCase {

	protected function tearDown(): void {
		// Reset the per-test filter map between cases.
		$GLOBALS['openclawp_test_filters'] = array();
		parent::tearDown();
	}

	public function test_known_anthropic_haiku_cost_matches_spec(): void {
		// claude-haiku-4-5: $0.001/1k input, $0.005/1k output.
		// 1000 in + 500 out = 0.001 + 0.0025 = 0.0035.
		$result = OpenclaWP_Usage_Recorder::estimate_cost( 'anthropic', 'claude-haiku-4-5', 1000, 500 );
		$this->assertTrue( $result['resolved'] );
		$this->assertSame( 0.0035, $result['cost'] );
	}

	public function test_known_anthropic_opus_cost_matches_spec(): void {
		// claude-opus-4-7: $0.015/1k input, $0.075/1k output.
		// 2000 in + 1000 out = 0.030 + 0.075 = 0.105.
		$result = OpenclaWP_Usage_Recorder::estimate_cost( 'anthropic', 'claude-opus-4-7', 2000, 1000 );
		$this->assertTrue( $result['resolved'] );
		$this->assertSame( 0.105, $result['cost'] );
	}

	public function test_ollama_wildcard_zero_cost(): void {
		$result = OpenclaWP_Usage_Recorder::estimate_cost( 'ollama', 'gemma4:e2b', 10000, 5000 );
		$this->assertTrue( $result['resolved'] );
		$this->assertSame( 0.0, $result['cost'] );
	}

	public function test_unpriced_model_resolves_to_zero_with_unresolved_flag(): void {
		$result = OpenclaWP_Usage_Recorder::estimate_cost( 'fake-provider', 'fake-model', 1000, 1000 );
		$this->assertFalse( $result['resolved'] );
		$this->assertSame( 0.0, $result['cost'] );
	}

	public function test_empty_provider_and_model_resolves_to_unresolved(): void {
		// Catch the no-provider branch (e.g., when wp_ai_client_prompt is unavailable).
		$result = OpenclaWP_Usage_Recorder::estimate_cost( '', '', 100, 100 );
		$this->assertFalse( $result['resolved'] );
		$this->assertSame( 0.0, $result['cost'] );
	}

	public function test_pricing_filter_overrides_default_rate(): void {
		// Override the table so anthropic|claude-haiku-4-5 = $0.002 / $0.010.
		$GLOBALS['openclawp_test_filters']['openclawp_model_pricing'] = static function ( $rates ) {
			$rates['anthropic|claude-haiku-4-5'] = array(
				'input_per_1k'  => 0.002,
				'output_per_1k' => 0.010,
			);
			return $rates;
		};

		$result = OpenclaWP_Usage_Recorder::estimate_cost( 'anthropic', 'claude-haiku-4-5', 1000, 500 );
		$this->assertTrue( $result['resolved'] );
		// 0.002 + 0.005 = 0.007.
		$this->assertSame( 0.007, $result['cost'] );
	}

	public function test_pricing_filter_can_add_new_provider(): void {
		$GLOBALS['openclawp_test_filters']['openclawp_model_pricing'] = static function ( $rates ) {
			$rates['custom|*'] = array(
				'input_per_1k'  => 0.5,
				'output_per_1k' => 1.0,
			);
			return $rates;
		};

		$result = OpenclaWP_Usage_Recorder::estimate_cost( 'custom', 'whatever-model', 1000, 1000 );
		$this->assertTrue( $result['resolved'] );
		$this->assertSame( 1.5, $result['cost'] );
	}

	public function test_provider_wildcard_falls_back_when_model_unknown(): void {
		// anthropic|some-future-model isn't in the table, but no anthropic|* wildcard
		// exists either, so this should be unresolved.
		$result = OpenclaWP_Usage_Recorder::estimate_cost( 'anthropic', 'claude-future', 1000, 1000 );
		$this->assertFalse( $result['resolved'] );
		$this->assertSame( 0.0, $result['cost'] );
	}

	public function test_cost_is_rounded_to_six_decimals(): void {
		// 1 input token on haiku = 0.000001. Verify the round() in estimate_cost.
		$result = OpenclaWP_Usage_Recorder::estimate_cost( 'anthropic', 'claude-haiku-4-5', 1, 1 );
		$this->assertTrue( $result['resolved'] );
		// 0.000001 + 0.000005 = 0.000006.
		$this->assertSame( 0.000006, $result['cost'] );
	}
}
