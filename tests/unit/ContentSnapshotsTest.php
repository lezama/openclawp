<?php
/**
 * Pure-PHP tests for content snapshot helpers.
 *
 * @package OpenclaWP\Tests
 */

declare( strict_types=1 );

namespace OpenclaWP\Tests\Unit;

use OpenclaWP_Content_Snapshots;
use PHPUnit\Framework\TestCase;

/**
 * @covers OpenclaWP_Content_Snapshots
 */
final class ContentSnapshotsTest extends TestCase {

	public function test_normalize_changes_accepts_aliases_and_supported_statuses(): void {
		$changes = OpenclaWP_Content_Snapshots::normalize_changes(
			array(
				'title'   => 'New title',
				'content' => 'New body',
				'excerpt' => 'Summary',
				'status'  => 'PUBLISH',
				'ignored' => 'nope',
			)
		);

		$this->assertSame(
			array(
				'post_title'   => 'New title',
				'post_content' => 'New body',
				'post_excerpt' => 'Summary',
				'post_status'  => 'publish',
			),
			$changes
		);
	}

	public function test_invalid_status_is_dropped_from_changes(): void {
		$this->assertSame(
			array( 'post_title' => 'Keep me' ),
			OpenclaWP_Content_Snapshots::normalize_changes(
				array(
					'post_title'  => 'Keep me',
					'post_status' => 'trash<script>',
				)
			)
		);
	}

	public function test_diff_payload_reports_changed_fields_and_unified_diff(): void {
		$before = array(
			'post_title'   => 'Old',
			'post_status'  => 'draft',
			'post_excerpt' => '',
			'post_content' => "Line one\nLine two",
		);
		$after  = array(
			'post_title'   => 'New',
			'post_status'  => 'draft',
			'post_excerpt' => '',
			'post_content' => "Line one\nLine three",
		);

		$diff = OpenclaWP_Content_Snapshots::diff_payload( $before, $after );

		$this->assertSame( array( 'post_title', 'post_content' ), $diff['changed_fields'] );
		$this->assertStringContainsString( '@@ post_title @@', $diff['unified'] );
		$this->assertStringContainsString( '- Old', $diff['unified'] );
		$this->assertStringContainsString( '+ New', $diff['unified'] );
		$this->assertStringContainsString( '- Line two', $diff['unified'] );
		$this->assertStringContainsString( '+ Line three', $diff['unified'] );
	}

	public function test_payload_hash_is_stable_for_same_fields(): void {
		$payload = array(
			'post_title'   => 'Title',
			'post_content' => 'Body',
			'post_status'  => 'draft',
			'extra'        => 'ignored',
		);

		$this->assertSame(
			OpenclaWP_Content_Snapshots::payload_hash( $payload ),
			OpenclaWP_Content_Snapshots::payload_hash( array_reverse( $payload, true ) )
		);
	}
}
