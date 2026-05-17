<?php
/**
 * Tests for the Telegram Bot API webhook authentication, allowlist gating,
 * and message extraction helpers.
 *
 * Webhook handler integration (full REST dispatch) is exercised in the
 * smoke test inside a real WordPress; these are the pure-PHP gates the
 * dispatcher relies on.
 *
 * @package OpenclaWP\Tests
 */

require_once OPENCLAWP_PATH . 'includes/class-openclawp-telegram.php';

final class TelegramWebhookTest extends \PHPUnit\Framework\TestCase {

	private const SECRET = 'my-shared-secret';

	/* --------------------- secret_token verification --------------------- */

	public function test_secret_match_passes(): void {
		$this->assertTrue( OpenclaWP_Telegram::verify_secret( self::SECRET, self::SECRET ) );
	}

	public function test_secret_mismatch_returns_401(): void {
		// HMAC mismatch → 401 is the contract guaranteed to the caller.
		$this->assertFalse( OpenclaWP_Telegram::verify_secret( 'wrong-secret', self::SECRET ) );
	}

	public function test_empty_expected_fails_closed(): void {
		// Unconfigured plugin must reject everything — never accept inbound
		// traffic on an empty stored secret.
		$this->assertFalse( OpenclaWP_Telegram::verify_secret( 'anything', '' ) );
	}

	public function test_empty_header_fails_closed(): void {
		// A request that omits the header entirely must be rejected even
		// when the plugin has a secret configured.
		$this->assertFalse( OpenclaWP_Telegram::verify_secret( '', self::SECRET ) );
	}

	/* --------------------------- allowlist gate -------------------------- */

	public function test_allowlist_empty_blocks_everyone(): void {
		$this->assertFalse( OpenclaWP_Telegram::is_allowed( 12345, '' ) );
	}

	public function test_allowlist_wildcard_allows_anyone(): void {
		$this->assertTrue( OpenclaWP_Telegram::is_allowed( 12345, '*' ) );
	}

	public function test_allowlist_matches_exact_chat_id(): void {
		$this->assertTrue( OpenclaWP_Telegram::is_allowed( 12345, '12345' ) );
	}

	public function test_allowlist_matches_within_csv(): void {
		$this->assertTrue( OpenclaWP_Telegram::is_allowed( 999, '1,2,999, 7' ) );
	}

	public function test_allowlist_rejects_unlisted_chat(): void {
		$this->assertFalse( OpenclaWP_Telegram::is_allowed( 42, '1,2,3' ) );
	}

	public function test_allowlist_does_not_match_prefix(): void {
		// `123` must not match `1234` — guard against substring confusion.
		$this->assertFalse( OpenclaWP_Telegram::is_allowed( 1234, '123' ) );
	}

	/* ------------------------- message extraction ------------------------ */

	public function test_extracts_text_message(): void {
		$payload = array(
			'update_id' => 1,
			'message'   => array(
				'message_id' => 100,
				'from'       => array( 'id' => 5001 ),
				'chat'       => array(
					'id'   => 5001,
					'type' => 'private',
				),
				'text'       => 'hola',
			),
		);
		$message = OpenclaWP_Telegram::extract_message( $payload );
		$this->assertIsArray( $message );
		$this->assertSame( 5001, $message['chat_id'] );
		$this->assertSame( 100, $message['message_id'] );
		$this->assertSame( 'hola', $message['text'] );
	}

	public function test_extract_message_returns_null_for_non_text(): void {
		// Photo / voice / sticker etc. must ack 200 (caller drops to
		// "unsupported"), never crash.
		$payload = array(
			'message' => array(
				'message_id' => 1,
				'chat'       => array( 'id' => 1 ),
				'photo'      => array( array( 'file_id' => 'abc' ) ),
			),
		);
		$this->assertNull( OpenclaWP_Telegram::extract_message( $payload ) );
	}

	public function test_extract_message_returns_null_when_no_message_field(): void {
		// Channel posts, edited messages, callback queries — anything that
		// isn't a fresh `message` update is skipped in v1.
		$this->assertNull( OpenclaWP_Telegram::extract_message( array( 'update_id' => 1 ) ) );
	}

	/* --------------------------- log redaction --------------------------- */

	public function test_redact_token_strips_bot_token_from_text(): void {
		$token = '987654:SECRETTOKEN';
		$text  = 'POST https://api.telegram.org/bot' . $token . '/sendMessage failed';
		$this->assertStringNotContainsString( $token, OpenclaWP_Telegram::redact_token( $text, $token ) );
	}
}
