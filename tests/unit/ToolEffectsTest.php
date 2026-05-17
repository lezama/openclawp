<?php
/**
 * Pure-PHP unit tests for OpenclaWP_Tool_Effects.
 *
 * Covers:
 *   - Threshold gating matrix (one per threshold: none, destructive, write, external).
 *   - Effect-name heuristic resolver (prefix-based).
 *   - Normalisation of arbitrary input.
 *
 * @package OpenclaWP\Tests
 */

declare( strict_types=1 );

namespace OpenclaWP\Tests\Unit;

use OpenclaWP_Tool_Effects;
use PHPUnit\Framework\TestCase;

/**
 * @covers OpenclaWP_Tool_Effects
 */
final class ToolEffectsTest extends TestCase {

	public function test_threshold_none_never_gates(): void {
		$threshold = OpenclaWP_Tool_Effects::THRESHOLD_NONE;
		foreach ( OpenclaWP_Tool_Effects::valid_effects() as $effect ) {
			$this->assertFalse(
				OpenclaWP_Tool_Effects::requires_confirmation( $effect, $threshold ),
				"Threshold=none must not gate effect={$effect}."
			);
		}
	}

	public function test_threshold_destructive_gates_destructive_and_external(): void {
		$threshold = OpenclaWP_Tool_Effects::THRESHOLD_DESTRUCTIVE;
		$this->assertFalse( OpenclaWP_Tool_Effects::requires_confirmation( OpenclaWP_Tool_Effects::EFFECT_READ, $threshold ) );
		$this->assertFalse( OpenclaWP_Tool_Effects::requires_confirmation( OpenclaWP_Tool_Effects::EFFECT_WRITE, $threshold ) );
		$this->assertTrue(  OpenclaWP_Tool_Effects::requires_confirmation( OpenclaWP_Tool_Effects::EFFECT_DESTRUCTIVE, $threshold ) );
		$this->assertTrue(  OpenclaWP_Tool_Effects::requires_confirmation( OpenclaWP_Tool_Effects::EFFECT_EXTERNAL, $threshold ) );
	}

	public function test_threshold_write_gates_write_destructive_and_external(): void {
		$threshold = OpenclaWP_Tool_Effects::THRESHOLD_WRITE;
		$this->assertFalse( OpenclaWP_Tool_Effects::requires_confirmation( OpenclaWP_Tool_Effects::EFFECT_READ, $threshold ) );
		$this->assertTrue(  OpenclaWP_Tool_Effects::requires_confirmation( OpenclaWP_Tool_Effects::EFFECT_WRITE, $threshold ) );
		$this->assertTrue(  OpenclaWP_Tool_Effects::requires_confirmation( OpenclaWP_Tool_Effects::EFFECT_DESTRUCTIVE, $threshold ) );
		$this->assertTrue(  OpenclaWP_Tool_Effects::requires_confirmation( OpenclaWP_Tool_Effects::EFFECT_EXTERNAL, $threshold ) );
	}

	public function test_threshold_external_gates_everything_except_read(): void {
		$threshold = OpenclaWP_Tool_Effects::THRESHOLD_EXTERNAL;
		$this->assertFalse( OpenclaWP_Tool_Effects::requires_confirmation( OpenclaWP_Tool_Effects::EFFECT_READ, $threshold ) );
		$this->assertTrue(  OpenclaWP_Tool_Effects::requires_confirmation( OpenclaWP_Tool_Effects::EFFECT_WRITE, $threshold ) );
		$this->assertTrue(  OpenclaWP_Tool_Effects::requires_confirmation( OpenclaWP_Tool_Effects::EFFECT_DESTRUCTIVE, $threshold ) );
		$this->assertTrue(  OpenclaWP_Tool_Effects::requires_confirmation( OpenclaWP_Tool_Effects::EFFECT_EXTERNAL, $threshold ) );
	}

	public function test_read_effect_is_never_gated_regardless_of_threshold(): void {
		foreach ( OpenclaWP_Tool_Effects::valid_thresholds() as $threshold ) {
			$this->assertFalse(
				OpenclaWP_Tool_Effects::requires_confirmation( OpenclaWP_Tool_Effects::EFFECT_READ, $threshold ),
				"read effect must not be gated under threshold={$threshold}."
			);
		}
	}

	public function test_name_heuristic_detects_destructive_prefixes(): void {
		foreach ( array( 'openclawp/delete-post', 'openclawp/remove-user', 'openclawp/drop-table', 'core/uninstall-plugin', 'site/purge-cache' ) as $name ) {
			$this->assertSame(
				OpenclaWP_Tool_Effects::EFFECT_DESTRUCTIVE,
				OpenclaWP_Tool_Effects::guess_from_name( $name ),
				"Expected destructive effect for {$name}."
			);
		}
	}

	public function test_name_heuristic_detects_external_prefixes(): void {
		foreach ( array( 'whatsapp/send-message', 'channel/post-to-slack', 'api/call-stripe' ) as $name ) {
			$this->assertSame(
				OpenclaWP_Tool_Effects::EFFECT_EXTERNAL,
				OpenclaWP_Tool_Effects::guess_from_name( $name ),
				"Expected external effect for {$name}."
			);
		}
	}

	public function test_name_heuristic_detects_read_prefixes(): void {
		foreach ( array( 'openclawp/get-recent-posts', 'openclawp/list-comments', 'openclawp/count-comments', 'openclawp/read-meta', 'openclawp/find-orders', 'openclawp/search-pages' ) as $name ) {
			$this->assertSame(
				OpenclaWP_Tool_Effects::EFFECT_READ,
				OpenclaWP_Tool_Effects::guess_from_name( $name ),
				"Expected read effect for {$name}."
			);
		}
	}

	public function test_name_heuristic_detects_write_prefixes(): void {
		foreach ( array( 'openclawp/create-post', 'openclawp/update-option', 'openclawp/set-thumbnail', 'core/install-plugin', 'core/enable-theme' ) as $name ) {
			$this->assertSame(
				OpenclaWP_Tool_Effects::EFFECT_WRITE,
				OpenclaWP_Tool_Effects::guess_from_name( $name ),
				"Expected write effect for {$name}."
			);
		}
	}

	public function test_name_heuristic_defaults_unknown_to_write(): void {
		// "Closed by default" — unknown prefixes are treated as `write`,
		// not `read`, so a misnamed ability still triggers gating.
		$this->assertSame(
			OpenclaWP_Tool_Effects::EFFECT_WRITE,
			OpenclaWP_Tool_Effects::guess_from_name( 'plugin/do-the-thing' )
		);
	}

	public function test_normalize_coerces_unknown_to_write(): void {
		$this->assertSame( OpenclaWP_Tool_Effects::EFFECT_WRITE, OpenclaWP_Tool_Effects::normalize( '' ) );
		$this->assertSame( OpenclaWP_Tool_Effects::EFFECT_WRITE, OpenclaWP_Tool_Effects::normalize( 'banana' ) );
		$this->assertSame( OpenclaWP_Tool_Effects::EFFECT_WRITE, OpenclaWP_Tool_Effects::normalize( null ) );
		$this->assertSame( OpenclaWP_Tool_Effects::EFFECT_DESTRUCTIVE, OpenclaWP_Tool_Effects::normalize( 'DESTRUCTIVE' ) );
		$this->assertSame( OpenclaWP_Tool_Effects::EFFECT_EXTERNAL, OpenclaWP_Tool_Effects::normalize( ' external ' ) );
	}

	public function test_normalize_threshold_falls_back_to_default(): void {
		$this->assertSame( OpenclaWP_Tool_Effects::DEFAULT_THRESHOLD, OpenclaWP_Tool_Effects::normalize_threshold( '' ) );
		$this->assertSame( OpenclaWP_Tool_Effects::DEFAULT_THRESHOLD, OpenclaWP_Tool_Effects::normalize_threshold( 'banana' ) );
		$this->assertSame( OpenclaWP_Tool_Effects::THRESHOLD_NONE, OpenclaWP_Tool_Effects::normalize_threshold( 'none' ) );
		$this->assertSame( OpenclaWP_Tool_Effects::THRESHOLD_WRITE, OpenclaWP_Tool_Effects::normalize_threshold( 'WRITE' ) );
	}
}
