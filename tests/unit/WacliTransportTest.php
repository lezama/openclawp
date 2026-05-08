<?php
/**
 * Pure-PHP unit tests for OpenclaWP_Wacli_Transport signature verification.
 *
 * Webhook delivery, REST routing, and proc_open paths are exercised in
 * tests/smoke.php inside a real WordPress.
 *
 * @package OpenclaWP\Tests
 */

declare( strict_types=1 );

namespace OpenclaWP\Tests\Unit;

use OpenclaWP_Wacli_Transport;
use PHPUnit\Framework\TestCase;

/**
 * @covers OpenclaWP_Wacli_Transport
 */
final class WacliTransportTest extends TestCase {

	private const SECRET = 'topsecret';

	public function test_valid_signature_passes(): void {
		$body   = '{"chat_jid":"1@s.whatsapp.net","display_text":"hi"}';
		$sig    = 'sha256=' . hash_hmac( 'sha256', $body, self::SECRET );
		$result = OpenclaWP_Wacli_Transport::verify_signature( $body, $sig, self::SECRET );

		$this->assertTrue( $result );
	}

	public function test_wrong_secret_fails(): void {
		$body   = '{"a":1}';
		$sig    = 'sha256=' . hash_hmac( 'sha256', $body, 'wrong-secret' );
		$result = OpenclaWP_Wacli_Transport::verify_signature( $body, $sig, self::SECRET );

		$this->assertFalse( $result );
	}

	public function test_tampered_body_fails(): void {
		$body         = '{"a":1}';
		$tampered     = '{"a":2}';
		$sig          = 'sha256=' . hash_hmac( 'sha256', $body, self::SECRET );
		$this->assertFalse( OpenclaWP_Wacli_Transport::verify_signature( $tampered, $sig, self::SECRET ) );
	}

	public function test_missing_header_fails(): void {
		$this->assertFalse( OpenclaWP_Wacli_Transport::verify_signature( '{}', '', self::SECRET ) );
	}

	public function test_unsupported_algo_fails(): void {
		$body = '{}';
		$sig  = 'md5=' . hash_hmac( 'md5', $body, self::SECRET );
		$this->assertFalse( OpenclaWP_Wacli_Transport::verify_signature( $body, $sig, self::SECRET ) );
	}

	public function test_malformed_header_fails(): void {
		$this->assertFalse( OpenclaWP_Wacli_Transport::verify_signature( '{}', 'sha256', self::SECRET ) );
		$this->assertFalse( OpenclaWP_Wacli_Transport::verify_signature( '{}', 'just-a-hex', self::SECRET ) );
	}

	public function test_case_insensitive_algo_label(): void {
		$body   = '{"x":true}';
		$sig    = 'SHA256=' . hash_hmac( 'sha256', $body, self::SECRET );
		$this->assertTrue( OpenclaWP_Wacli_Transport::verify_signature( $body, $sig, self::SECRET ) );
	}

	public function test_normalize_message_maps_pascalcase_to_snake_case(): void {
		$payload = array(
			'Chat'      => '1@s.whatsapp.net',
			'ID'        => 'MSG_1',
			'SenderJID' => '1@s.whatsapp.net',
			'FromMe'    => false,
			'Text'      => 'hola',
			'PushName'  => 'Test',
			'Timestamp' => '2026-05-08T12:00:00Z',
		);

		$normalized = OpenclaWP_Wacli_Transport::normalize_message( $payload );

		$this->assertSame( '1@s.whatsapp.net', $normalized['chat_jid'] );
		$this->assertSame( 'MSG_1', $normalized['msg_id'] );
		$this->assertSame( '1@s.whatsapp.net', $normalized['sender_jid'] );
		$this->assertFalse( $normalized['from_me'] );
		$this->assertSame( 'hola', $normalized['text'] );
		$this->assertSame( 'Test', $normalized['push_name'] );
		$this->assertSame( '2026-05-08T12:00:00Z', $normalized['timestamp'] );
		// Original PascalCase keys preserved for downstream filters.
		$this->assertSame( '1@s.whatsapp.net', $normalized['Chat'] );
	}

	public function test_normalize_message_does_not_overwrite_existing_snake_case(): void {
		$payload = array(
			'Chat'     => 'pascal@s.whatsapp.net',
			'chat_jid' => 'snake@s.whatsapp.net',
		);

		$normalized = OpenclaWP_Wacli_Transport::normalize_message( $payload );

		$this->assertSame( 'snake@s.whatsapp.net', $normalized['chat_jid'] );
	}

	public function test_normalize_message_passes_through_unknown_keys(): void {
		$payload    = array( 'random_field' => 42, 'Unknown' => 'value' );
		$normalized = OpenclaWP_Wacli_Transport::normalize_message( $payload );

		$this->assertSame( 42, $normalized['random_field'] );
		$this->assertSame( 'value', $normalized['Unknown'] );
	}
}
