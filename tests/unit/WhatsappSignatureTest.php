<?php
/**
 * Tests for the WhatsApp Cloud API signature verifier.
 *
 * @package OpenclaWP\Tests
 */

require_once OPENCLAWP_PATH . 'includes/class-openclawp-whatsapp.php';

final class WhatsappSignatureTest extends \PHPUnit\Framework\TestCase {

	private const SECRET = 'test-secret';

	public function test_valid_signature_passes(): void {
		$body = '{"object":"whatsapp_business_account"}';
		$sig  = 'sha256=' . hash_hmac( 'sha256', $body, self::SECRET );

		$this->assertTrue( OpenclaWP_Whatsapp::verify_signature( $body, $sig, self::SECRET ) );
	}

	public function test_tampered_body_fails(): void {
		$body = '{"object":"whatsapp_business_account"}';
		$sig  = 'sha256=' . hash_hmac( 'sha256', $body, self::SECRET );

		$this->assertFalse(
			OpenclaWP_Whatsapp::verify_signature(
				'{"object":"whatsapp_business_account","tampered":true}',
				$sig,
				self::SECRET
			)
		);
	}

	public function test_empty_secret_fails_closed(): void {
		$this->assertFalse( OpenclaWP_Whatsapp::verify_signature( '{}', 'sha256=anything', '' ) );
	}

	public function test_malformed_header_fails(): void {
		$this->assertFalse( OpenclaWP_Whatsapp::verify_signature( '{}', 'sha1=anything', self::SECRET ) );
		$this->assertFalse( OpenclaWP_Whatsapp::verify_signature( '{}', 'not-a-signature', self::SECRET ) );
	}
}
