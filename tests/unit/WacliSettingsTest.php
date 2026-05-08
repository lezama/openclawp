<?php
/**
 * Pure-PHP tests for the wacli settings normalizer.
 *
 * @package OpenclaWP\Tests
 */

declare( strict_types=1 );

namespace OpenclaWP\Tests\Unit;

use OpenclaWP_Wacli_Rest;
use PHPUnit\Framework\TestCase;

/**
 * @covers OpenclaWP_Wacli_Rest::normalize_allowed_jids
 */
final class WacliSettingsTest extends TestCase {

	public function test_empty_input_returns_empty_string(): void {
		$this->assertSame( '', OpenclaWP_Wacli_Rest::normalize_allowed_jids( '' ) );
		$this->assertSame( '', OpenclaWP_Wacli_Rest::normalize_allowed_jids( "  \n  ,  " ) );
	}

	public function test_trims_whitespace_and_drops_blanks(): void {
		$result = OpenclaWP_Wacli_Rest::normalize_allowed_jids( "  111@s.whatsapp.net   \n  \n 222@g.us " );
		$this->assertSame( '111@s.whatsapp.net,222@g.us', $result );
	}

	public function test_accepts_newlines_or_commas_as_separators(): void {
		$nl    = OpenclaWP_Wacli_Rest::normalize_allowed_jids( "111@s.whatsapp.net\n222@g.us\n333@newsletter" );
		$comma = OpenclaWP_Wacli_Rest::normalize_allowed_jids( '111@s.whatsapp.net,222@g.us,333@newsletter' );
		$mix   = OpenclaWP_Wacli_Rest::normalize_allowed_jids( "111@s.whatsapp.net , 222@g.us\n333@newsletter" );

		$this->assertSame( '111@s.whatsapp.net,222@g.us,333@newsletter', $nl );
		$this->assertSame( $nl, $comma );
		$this->assertSame( $nl, $mix );
	}

	public function test_deduplicates_repeated_jids(): void {
		$result = OpenclaWP_Wacli_Rest::normalize_allowed_jids( "111@s.whatsapp.net\n111@s.whatsapp.net\n222@g.us\n111@s.whatsapp.net" );
		$this->assertSame( '111@s.whatsapp.net,222@g.us', $result );
	}
}
