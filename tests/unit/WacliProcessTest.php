<?php
/**
 * Pure-PHP unit tests for OpenclaWP_Wacli_Process state machine.
 *
 * Covers the deterministic event-folding code path. Process spawning,
 * file-system polling, and `posix_kill` checks live in tests/smoke.php
 * inside a real WordPress + a real wacli binary.
 *
 * @package OpenclaWP\Tests
 */

declare( strict_types=1 );

namespace OpenclaWP\Tests\Unit;

use OpenclaWP_Wacli_Process;
use PHPUnit\Framework\TestCase;

/**
 * @covers OpenclaWP_Wacli_Process
 */
final class WacliProcessTest extends TestCase {

	private function base_state(): array {
		return array(
			'mode'          => OpenclaWP_Wacli_Process::MODE_PAIRING,
			'pid'           => 1234,
			'events_file'   => '',
			'qr_payload'    => '',
			'qr_seen_at'    => 0,
			'paired_jid'    => '',
			'started_at'    => 0,
			'last_event'    => '',
			'last_event_at' => 0,
			'error'         => '',
		);
	}

	public function test_qr_event_updates_payload_and_keeps_pairing_mode(): void {
		$next = OpenclaWP_Wacli_Process::apply_event(
			$this->base_state(),
			array( 'type' => 'qr', 'code' => '2@xyz', 'ts' => 1715000000 )
		);

		$this->assertSame( '2@xyz', $next['qr_payload'] );
		$this->assertSame( 1715000000, $next['qr_seen_at'] );
		$this->assertSame( OpenclaWP_Wacli_Process::MODE_PAIRING, $next['mode'] );
		$this->assertSame( 'qr', $next['last_event'] );
	}

	public function test_qr_event_accepts_payload_or_qr_field_aliases(): void {
		$with_payload = OpenclaWP_Wacli_Process::apply_event( $this->base_state(), array( 'type' => 'qr_code', 'payload' => 'abc' ) );
		$with_qr      = OpenclaWP_Wacli_Process::apply_event( $this->base_state(), array( 'type' => 'pair_qr', 'qr' => 'def' ) );

		$this->assertSame( 'abc', $with_payload['qr_payload'] );
		$this->assertSame( 'def', $with_qr['qr_payload'] );
	}

	public function test_paired_event_advances_to_syncing_and_clears_qr(): void {
		$state = $this->base_state();
		$state['qr_payload'] = '2@xyz';

		$next = OpenclaWP_Wacli_Process::apply_event( $state, array( 'type' => 'paired', 'jid' => '12345@s.whatsapp.net' ) );

		$this->assertSame( OpenclaWP_Wacli_Process::MODE_SYNCING, $next['mode'] );
		$this->assertSame( '12345@s.whatsapp.net', $next['paired_jid'] );
		$this->assertSame( '', $next['qr_payload'] );
		$this->assertSame( '', $next['error'] );
	}

	public function test_error_event_marks_failed_and_records_message(): void {
		$next = OpenclaWP_Wacli_Process::apply_event(
			$this->base_state(),
			array( 'type' => 'error', 'message' => 'bad credentials' )
		);

		$this->assertSame( OpenclaWP_Wacli_Process::MODE_FAILED, $next['mode'] );
		$this->assertSame( 'bad credentials', $next['error'] );
	}

	public function test_unknown_event_only_updates_last_event_marker(): void {
		$state = $this->base_state();
		$state['qr_payload'] = '2@xyz';

		$next = OpenclaWP_Wacli_Process::apply_event( $state, array( 'type' => 'heartbeat', 'ts' => 1715000050 ) );

		$this->assertSame( '2@xyz', $next['qr_payload'] ); // untouched
		$this->assertSame( OpenclaWP_Wacli_Process::MODE_PAIRING, $next['mode'] );
		$this->assertSame( 'heartbeat', $next['last_event'] );
		$this->assertSame( 1715000050, $next['last_event_at'] );
	}

	public function test_merge_events_into_state_replays_full_ndjson_file(): void {
		$file = tempnam( sys_get_temp_dir(), 'wacli-test-' );
		file_put_contents(
			$file,
			implode(
				"\n",
				array(
					'{"type":"qr","code":"first","ts":100}',
					'not-json-noise',
					'{"type":"qr","code":"second","ts":200}',
					'{"type":"paired","jid":"+15551234567@s.whatsapp.net","ts":300}',
				)
			)
		);

		$state = OpenclaWP_Wacli_Process::merge_events_into_state(
			array(
				'mode'          => OpenclaWP_Wacli_Process::MODE_PAIRING,
				'pid'           => 1,
				'events_file'   => $file,
				'qr_payload'    => '',
				'qr_seen_at'    => 0,
				'paired_jid'    => '',
				'started_at'    => 0,
				'last_event'    => '',
				'last_event_at' => 0,
				'error'         => '',
			)
		);

		@unlink( $file );

		// Final state: paired, with the latest paired_jid; qr cleared.
		$this->assertSame( OpenclaWP_Wacli_Process::MODE_SYNCING, $state['mode'] );
		$this->assertSame( '+15551234567@s.whatsapp.net', $state['paired_jid'] );
		$this->assertSame( '', $state['qr_payload'] );
		$this->assertSame( 'paired', $state['last_event'] );
	}
}
