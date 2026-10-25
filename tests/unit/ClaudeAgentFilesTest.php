<?php
/**
 * Files a turn sends to the claude-agent bridge.
 *
 * @package OpenclaWP\Tests
 */

declare( strict_types=1 );

namespace OpenclaWP\Tests\Unit;

use OpenclaWP_Agenttic_Bridge;
use OpenclaWP_Claude_Agent_Runner;
use PHPUnit\Framework\TestCase;

final class ClaudeAgentFilesTest extends TestCase {

	public function test_a2a_file_parts_become_attachments(): void {
		$attachments = OpenclaWP_Agenttic_Bridge::attachments_from_message(
			array(
				'parts' => array(
					array( 'type' => 'text', 'text' => 'un recibo' ),
					array( 'type' => 'file', 'file' => array( 'media_id' => 42, 'mimeType' => 'image/JPEG' ) ),
					array( 'kind' => 'file', 'file' => array( 'media_id' => 43, 'mime_type' => 'application/pdf' ) ),
					array( 'type' => 'file', 'file' => array( 'uri' => 'https://example.com/x.png', 'mimeType' => 'image/png' ) ),
					array( 'type' => 'file', 'file' => array( 'media_id' => 44 ) ),
				),
			)
		);

		$this->assertSame(
			array(
				array( 'type' => 'image', 'media_id' => 42, 'mime_type' => 'image/jpeg' ),
				array( 'type' => 'file', 'media_id' => 43, 'mime_type' => 'application/pdf' ),
			),
			$attachments,
			'Only Media Library ids with a type travel; a URL is never fetched.'
		);
	}

	public function test_a_local_image_goes_in_base64_and_other_types_stay_out(): void {
		$path = tempnam( sys_get_temp_dir(), 'ocw' );
		file_put_contents( $path, 'imagen' );

		$this->assertSame( array( 'media_type' => 'image/jpeg', 'data' => base64_encode( 'imagen' ) ), OpenclaWP_Claude_Agent_Runner::file_for_bridge( $path, 'image/JPEG' ) );
		$this->assertNull( OpenclaWP_Claude_Agent_Runner::file_for_bridge( $path, 'audio/ogg' ) );
		$this->assertNull( OpenclaWP_Claude_Agent_Runner::file_for_bridge( $path . '-no-existe', 'image/png' ) );

		unlink( $path );
	}
}
