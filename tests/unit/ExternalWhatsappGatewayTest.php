<?php
/**
 * Tests for the generic external WhatsApp gateway adapter.
 *
 * Covers the pure-PHP pieces — HMAC verification, canonical-shape
 * normalization, and the inbound mapping filter contract — without
 * requiring a running WordPress. The full HTTP path (REST route +
 * runner dispatch + outbound POST) is exercised in tests/smoke.php
 * inside a real WordPress.
 *
 * @package OpenclaWP\Tests
 */

require_once OPENCLAWP_PATH . 'includes/class-openclawp-external-whatsapp.php';

final class ExternalWhatsappGatewayTest extends \PHPUnit\Framework\TestCase {

	private const SECRET = 'test-secret';

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['openclawp_test_filters'] = array();
	}

	/* ------------------------------ Signature ----------------------------- */

	public function test_valid_signature_passes(): void {
		$body = '{"from":"+15555550100","text":"hola","id":"m1","type":"text"}';
		$sig  = 'sha256=' . hash_hmac( 'sha256', $body, self::SECRET );

		$this->assertTrue( OpenclaWP_External_Whatsapp::verify_signature( $body, $sig, self::SECRET ) );
	}

	public function test_tampered_body_fails(): void {
		$body = '{"from":"+15555550100","text":"hola","id":"m1","type":"text"}';
		$sig  = 'sha256=' . hash_hmac( 'sha256', $body, self::SECRET );

		$this->assertFalse(
			OpenclaWP_External_Whatsapp::verify_signature(
				'{"from":"+15555550100","text":"TAMPERED","id":"m1","type":"text"}',
				$sig,
				self::SECRET
			)
		);
	}

	public function test_hmac_mismatch_fails(): void {
		$body     = '{"from":"+15555550100","text":"hola","id":"m1","type":"text"}';
		$bad_sig  = 'sha256=' . hash_hmac( 'sha256', $body, 'a-different-secret' );

		$this->assertFalse( OpenclaWP_External_Whatsapp::verify_signature( $body, $bad_sig, self::SECRET ) );
	}

	public function test_empty_secret_fails_closed(): void {
		// Bug-of-record check: an empty configured secret must NOT make
		// the verifier accept arbitrary signatures. Same fail-closed
		// posture as OpenclaWP_Whatsapp::verify_signature.
		$body = '{}';
		$sig  = 'sha256=' . hash_hmac( 'sha256', $body, '' );

		$this->assertFalse( OpenclaWP_External_Whatsapp::verify_signature( $body, $sig, '' ) );
		$this->assertFalse( OpenclaWP_External_Whatsapp::verify_signature( $body, 'sha256=anything', '' ) );
	}

	public function test_malformed_header_fails(): void {
		$this->assertFalse( OpenclaWP_External_Whatsapp::verify_signature( '{}', 'sha1=anything', self::SECRET ) );
		$this->assertFalse( OpenclaWP_External_Whatsapp::verify_signature( '{}', 'not-a-signature', self::SECRET ) );
		$this->assertFalse( OpenclaWP_External_Whatsapp::verify_signature( '{}', '', self::SECRET ) );
	}

	/* ----------------------------- Normalize ------------------------------ */

	public function test_canonical_text_message_normalizes(): void {
		$payload = array(
			'from' => '+15555550100',
			'text' => 'hola',
			'id'   => 'msg-uuid',
			'type' => 'text',
		);

		$normalized = OpenclaWP_External_Whatsapp::normalize_message( $payload );

		$this->assertSame(
			array( 'from' => '+15555550100', 'text' => 'hola', 'id' => 'msg-uuid' ),
			$normalized
		);
	}

	public function test_missing_type_defaults_to_text(): void {
		$payload = array(
			'from' => '+15555550100',
			'text' => 'hi',
			'id'   => 'm1',
		);

		$this->assertNotNull( OpenclaWP_External_Whatsapp::normalize_message( $payload ) );
	}

	public function test_image_type_returns_null_for_unsupported_v1(): void {
		// v1 = text only. Other types ack 200 and log "unsupported" at the
		// call site.
		$payload = array(
			'from' => '+15555550100',
			'id'   => 'm1',
			'type' => 'image',
		);

		$this->assertNull( OpenclaWP_External_Whatsapp::normalize_message( $payload ) );
	}

	public function test_missing_from_returns_null(): void {
		$payload = array( 'text' => 'orphan', 'id' => 'm1', 'type' => 'text' );

		$this->assertNull( OpenclaWP_External_Whatsapp::normalize_message( $payload ) );
	}

	public function test_empty_text_returns_null(): void {
		$payload = array( 'from' => '+15555550100', 'text' => '', 'id' => 'm1', 'type' => 'text' );

		$this->assertNull( OpenclaWP_External_Whatsapp::normalize_message( $payload ) );
	}

	/* -------------------------- Inbound-map filter ------------------------ */

	/**
	 * Evolution-api-shaped event: a non-canonical wrapper that a user-supplied
	 * filter mapper should be able to fold into the canonical shape, proving
	 * the hook works for any reverse-engineered gateway.
	 *
	 * The mapper logic here is the worked recipe that ships in
	 * docs/external-whatsapp-gateway.md — kept in sync to catch drift.
	 */
	public function test_evolution_api_payload_remaps_via_filter(): void {
		// Sample evolution-api shape — wrapped under `event` + `data`, sender
		// in `key.remoteJid`, text in `message.conversation`.
		$evolution_payload = array(
			'event' => 'messages.upsert',
			'data'  => array(
				'key' => array(
					'remoteJid' => '15555550100@s.whatsapp.net',
					'fromMe'    => false,
					'id'        => 'EVO-MSG-1',
				),
				'message' => array(
					'conversation' => 'hola desde evolution',
				),
			),
		);

		$GLOBALS['openclawp_test_filters']['openclawp_external_wa_inbound_map'] = static function ( $payload, $headers ) {
			// User-side mapper — adapt evolution-api → canonical shape.
			if ( ! is_array( $payload ) || 'messages.upsert' !== ( $payload['event'] ?? '' ) ) {
				return $payload;
			}
			$data = $payload['data'] ?? array();
			if ( ! empty( $data['key']['fromMe'] ) ) {
				return array(); // ignore our own echoes
			}
			$jid  = (string) ( $data['key']['remoteJid'] ?? '' );
			$from = '+' . preg_replace( '/[^0-9]/', '', explode( '@', $jid, 2 )[0] );
			$text = (string) ( $data['message']['conversation'] ?? '' );
			return array(
				'from' => $from,
				'text' => $text,
				'id'   => (string) ( $data['key']['id'] ?? '' ),
				'type' => 'text',
			);
		};

		$normalized = apply_filters( 'openclawp_external_wa_inbound_map', $evolution_payload, array() );
		$this->assertIsArray( $normalized );

		$message = OpenclaWP_External_Whatsapp::normalize_message( $normalized );

		$this->assertSame(
			array( 'from' => '+15555550100', 'text' => 'hola desde evolution', 'id' => 'EVO-MSG-1' ),
			$message
		);
	}

	public function test_inbound_map_filter_returning_empty_array_skips_dispatch(): void {
		// Evolution sends out our own echoes as messages.upsert with
		// `fromMe: true`. The mapper should yield an empty array which
		// the handler treats as "nothing to dispatch".
		$GLOBALS['openclawp_test_filters']['openclawp_external_wa_inbound_map'] = static fn ( $payload ) => array();

		$normalized = apply_filters( 'openclawp_external_wa_inbound_map', array( 'anything' => 1 ), array() );
		$this->assertSame( array(), $normalized );
	}

	/* -------------------------- Outbound-map filter ----------------------- */

	public function test_outbound_map_filter_reshapes_canonical(): void {
		// A user pointing at wacli's send endpoint might reshape `to`/`text`
		// into wacli's `{ "number": "...", "message": "..." }` shape.
		$GLOBALS['openclawp_test_filters']['openclawp_external_wa_outbound_map'] = static function ( $canonical, $session ) {
			return array(
				'number'  => $canonical['to'],
				'message' => $canonical['text'],
			);
		};

		$canonical = array( 'to' => '+15555550100', 'text' => 'reply', 'id' => 'inbound-1' );
		$shaped    = apply_filters( 'openclawp_external_wa_outbound_map', $canonical, array( 'session_id' => 'sess-1' ) );

		$this->assertSame(
			array( 'number' => '+15555550100', 'message' => 'reply' ),
			$shaped
		);
	}
}
