<?php
/**
 * Pure-PHP tests for attachment prompt context.
 *
 * @package OpenclaWP\Tests
 */

declare( strict_types=1 );

namespace OpenclaWP\Tests\Unit;

use OpenclaWP_Runner;
use PHPUnit\Framework\TestCase;

/**
 * @covers OpenclaWP_Runner
 */
final class RunnerAttachmentsTest extends TestCase {

	protected function tearDown(): void {
		$GLOBALS['openclawp_test_filters'] = array();
		parent::tearDown();
	}

	public function test_message_with_attachments_appends_provider_neutral_summary(): void {
		$message = OpenclaWP_Runner::message_with_attachments(
			'Analyze this',
			array(
				'attachments' => array(
					array(
						'type'      => 'image',
						'mime_type' => 'image/jpeg',
						'media_id'  => 'abc123',
						'caption'   => "Front page\nscreenshot",
					),
				),
			)
		);

		$this->assertStringContainsString( 'Analyze this', $message );
		$this->assertStringContainsString( 'Attachments:', $message );
		$this->assertStringContainsString( 'type=image', $message );
		$this->assertStringContainsString( 'mime_type=image/jpeg', $message );
		$this->assertStringContainsString( 'caption=Front page screenshot', $message );
	}

	public function test_attachment_context_filter_can_redact_lines(): void {
		$GLOBALS['openclawp_test_filters']['openclawp_message_attachments_context'] = static fn () => array( 'Attachment 1: redacted' );

		$message = OpenclaWP_Runner::message_with_attachments(
			'Analyze this',
			array(
				'attachments' => array(
					array(
						'type' => 'image',
						'url'  => 'https://signed.example/private',
					),
				),
			)
		);

		$this->assertStringContainsString( 'Attachment 1: redacted', $message );
		$this->assertStringNotContainsString( 'signed.example', $message );
	}
}
