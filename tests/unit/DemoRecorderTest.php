<?php
/**
 * Unit tests for demo recording plans.
 *
 * @package OpenclaWP\Tests
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests demo recorder plan generation and endpoint handoff.
 *
 * @covers OpenclaWP_Demo_Recorder
 */
final class DemoRecorderTest extends TestCase {

	/**
	 * Reset HTTP stubs between tests.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['openclawp_test_http_capture'], $GLOBALS['openclawp_test_http_response'] );
		parent::tearDown();
	}

	/**
	 * Plan generation includes the browser storyboard and voice settings.
	 */
	public function test_create_plan_includes_browser_steps_and_voice_script(): void {
		$plan = OpenclaWP_Demo_Recorder::create_plan(
			array(
				'site_url'    => 'http://localhost:8894',
				'client_name' => 'Northstar Clinic',
				'industry'    => 'clinic',
				'blueprint'   => 'booking-agent',
				'voice'       => array(
					'enabled'  => true,
					'mode'     => 'local-tts',
					'voice'    => 'Alex',
					'rate_wpm' => 165,
				),
			)
		);

		$this->assertSame( 'agency-sales-demo-v1', $plan['scenario'] );
		$this->assertSame( 'http://localhost:8894/', $plan['site_url'] );
		$this->assertSame( 'Northstar Clinic', $plan['inputs']['client_name'] );
		$this->assertSame( 'booking-agent', $plan['inputs']['blueprint'] );
		$this->assertTrue( $plan['voice']['enabled'] );
		$this->assertSame( 'local-tts', $plan['voice']['mode'] );
		$this->assertSame( 'Alex', $plan['voice']['voice'] );
		$this->assertSame( 165, $plan['voice']['rate_wpm'] );
		$this->assertContains( 'create_plan', $plan['workflow']['steps'] );
		$this->assertContains( 'record_video', $plan['workflow']['steps'] );

		$step_ids = array_column( $plan['steps'], 'id' );
		$this->assertContains( 'existing-site', $step_ids );
		$this->assertContains( 'generate-package', $step_ids );
		$this->assertContains( 'chat-proof', $step_ids );
	}

	/**
	 * Recording requires either an explicit endpoint or saved option.
	 */
	public function test_record_video_requires_endpoint(): void {
		$result = OpenclaWP_Demo_Recorder::record_video( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'openclawp_demo_recorder_endpoint_missing', $result->get_error_code() );
	}

	/**
	 * Synchronous recording posts the generated plan and parses the response.
	 */
	public function test_record_video_posts_plan_to_recorder_endpoint(): void {
		$GLOBALS['openclawp_test_http_response'] = array(
			'response' => array(
				'code'    => 201,
				'message' => 'Created',
			),
			'body'     => wp_json_encode(
				array(
					'video' => '/tmp/openclawp-demo.mp4',
					'voice' => array( 'status' => 'muxed' ),
				)
			),
		);

		$result = OpenclaWP_Demo_Recorder::record_video(
			array(
				'endpoint'    => 'http://127.0.0.1:8765/record',
				'async'       => false,
				'site_url'    => 'http://localhost:8894',
				'client_name' => 'Northstar Clinic',
				'voice'       => array( 'enabled' => true ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['accepted'] );
		$this->assertSame( 201, $result['response_code'] );
		$this->assertSame( '/tmp/openclawp-demo.mp4', $result['recorder']['video'] );
		$this->assertCount( 1, $GLOBALS['openclawp_test_http_capture'] );

		$capture = $GLOBALS['openclawp_test_http_capture'][0];
		$this->assertSame( 'http://127.0.0.1:8765/record', $capture['url'] );
		$payload = json_decode( (string) $capture['args']['body'], true );
		$this->assertSame( 'openclawp', $payload['source'] );
		$this->assertFalse( $payload['async'] );
		$this->assertSame( 'Northstar Clinic', $payload['plan']['inputs']['client_name'] );
		$this->assertTrue( $payload['plan']['voice']['enabled'] );
	}

	/**
	 * Async recording queues the local worker without waiting on video render.
	 */
	public function test_record_video_defaults_to_async_queue_mode(): void {
		$result = OpenclaWP_Demo_Recorder::record_video(
			array(
				'endpoint'    => 'http://127.0.0.1:8765/record',
				'site_url'    => 'http://localhost:8894',
				'client_name' => 'Northstar Clinic',
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['accepted'] );
		$this->assertTrue( $result['async'] );
		$this->assertSame( 'queued', $result['recorder']['status'] );

		$payload = json_decode( (string) $GLOBALS['openclawp_test_http_capture'][0]['args']['body'], true );
		$this->assertTrue( $payload['async'] );
		$this->assertFalse( $GLOBALS['openclawp_test_http_capture'][0]['args']['blocking'] );
	}
}
