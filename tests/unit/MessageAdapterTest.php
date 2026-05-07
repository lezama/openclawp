<?php
/**
 * Pure-PHP unit tests for OpenclaWP_Message_Adapter.
 *
 * Covers the parts of the adapter that don't depend on the WP AI Client SDK
 * being loaded (last_assistant_text, content extraction, the no-SDK fallback
 * branch of to_ai_client_messages). The full Message DTO round-trip is
 * exercised in tests/smoke.php inside a real WordPress.
 *
 * @package OpenclaWP\Tests
 */

declare( strict_types=1 );

namespace OpenclaWP\Tests\Unit;

use OpenclaWP_Message_Adapter;
use PHPUnit\Framework\TestCase;

/**
 * @covers OpenclaWP_Message_Adapter
 */
final class MessageAdapterTest extends TestCase {

	public function test_to_ai_client_messages_returns_input_unchanged_when_sdk_absent(): void {
		// Without wp-ai-client loaded, the adapter passes the transcript through.
		$input  = array( array( 'role' => 'user', 'content' => 'hi' ) );
		$output = OpenclaWP_Message_Adapter::to_ai_client_messages( $input );
		$this->assertSame( $input, $output );
	}

	public function test_last_assistant_text_returns_most_recent_assistant_message(): void {
		$messages = array(
			array( 'role' => 'user',      'content' => 'q1' ),
			array( 'role' => 'assistant', 'content' => 'a1' ),
			array( 'role' => 'user',      'content' => 'q2' ),
			array( 'role' => 'assistant', 'content' => 'a2-most-recent' ),
		);

		$this->assertSame( 'a2-most-recent', OpenclaWP_Message_Adapter::last_assistant_text( $messages ) );
	}

	public function test_last_assistant_text_is_empty_when_no_assistant_messages(): void {
		$messages = array(
			array( 'role' => 'user', 'content' => 'still waiting' ),
		);

		$this->assertSame( '', OpenclaWP_Message_Adapter::last_assistant_text( $messages ) );
	}

	public function test_last_assistant_text_skips_empty_assistant_then_returns_earlier_one(): void {
		$messages = array(
			array( 'role' => 'assistant', 'content' => 'kept' ),
			array( 'role' => 'user',      'content' => 'q' ),
			array( 'role' => 'assistant', 'content' => '' ),
		);

		$this->assertSame( 'kept', OpenclaWP_Message_Adapter::last_assistant_text( $messages ) );
	}

	public function test_last_assistant_text_concatenates_array_content(): void {
		$messages = array(
			array(
				'role'    => 'assistant',
				'content' => array(
					array( 'text' => 'one ' ),
					'two',
				),
			),
		);

		$this->assertSame( 'one two', OpenclaWP_Message_Adapter::last_assistant_text( $messages ) );
	}

	public function test_last_assistant_text_handles_string_content(): void {
		$messages = array(
			array( 'role' => 'assistant', 'content' => 'plain string reply' ),
		);

		$this->assertSame( 'plain string reply', OpenclaWP_Message_Adapter::last_assistant_text( $messages ) );
	}
}
