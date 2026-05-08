<?php
/**
 * Pure-PHP tests for OpenclaWP_Wacli_Channel's option-resolution logic.
 *
 * The full validate() / handle() path needs WP loaded — see tests/smoke.php.
 *
 * @package OpenclaWP\Tests
 */

declare( strict_types=1 );

namespace OpenclaWP\Tests\Unit;

use OpenclaWP_Wacli_Channel;
use PHPUnit\Framework\TestCase;

/**
 * @covers OpenclaWP_Wacli_Channel::resolve_self_message_mode
 */
final class WacliChannelTest extends TestCase {

	public function test_explicit_block_mode_wins(): void {
		$this->assertSame(
			OpenclaWP_Wacli_Channel::MODE_BLOCK,
			OpenclaWP_Wacli_Channel::resolve_self_message_mode( 'block', false )
		);
	}

	public function test_explicit_allow_mode_wins(): void {
		$this->assertSame(
			OpenclaWP_Wacli_Channel::MODE_ALLOW,
			OpenclaWP_Wacli_Channel::resolve_self_message_mode( 'allow', false )
		);
	}

	public function test_explicit_only_mode_wins(): void {
		$this->assertSame(
			OpenclaWP_Wacli_Channel::MODE_ONLY,
			OpenclaWP_Wacli_Channel::resolve_self_message_mode( 'only', false )
		);
	}

	public function test_unknown_mode_falls_through_to_legacy_default(): void {
		$this->assertSame(
			OpenclaWP_Wacli_Channel::MODE_BLOCK,
			OpenclaWP_Wacli_Channel::resolve_self_message_mode( 'gibberish', false )
		);
	}

	public function test_legacy_allow_boolean_promotes_to_allow_mode_when_enum_is_unset(): void {
		$this->assertSame(
			OpenclaWP_Wacli_Channel::MODE_ALLOW,
			OpenclaWP_Wacli_Channel::resolve_self_message_mode( '', true )
		);
	}

	public function test_no_options_set_defaults_to_block(): void {
		$this->assertSame(
			OpenclaWP_Wacli_Channel::MODE_BLOCK,
			OpenclaWP_Wacli_Channel::resolve_self_message_mode( '', false )
		);
	}

	public function test_explicit_enum_overrides_legacy_boolean(): void {
		// Even if a stale `allow_self_messages=1` is on the books, the new
		// enum value is authoritative. Avoids two opposing knobs disagreeing.
		$this->assertSame(
			OpenclaWP_Wacli_Channel::MODE_ONLY,
			OpenclaWP_Wacli_Channel::resolve_self_message_mode( 'only', true )
		);
		$this->assertSame(
			OpenclaWP_Wacli_Channel::MODE_BLOCK,
			OpenclaWP_Wacli_Channel::resolve_self_message_mode( 'block', true )
		);
	}
}
