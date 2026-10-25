<?php
/**
 * Pure-PHP tests for the bounded history window.
 *
 * @package OpenclaWP\Tests
 */

declare( strict_types=1 );

namespace OpenclaWP\Tests\Unit;

use OpenclaWP_History_Window;
use PHPUnit\Framework\TestCase;

/**
 * @covers OpenclaWP_History_Window
 */
final class HistoryWindowTest extends TestCase {

	private const SETTINGS = array(
		'keep_turns'        => 2,
		'summarize_every'   => 2,
		'tool_result_chars' => 200,
		'summary_chars'     => 500,
	);

	protected function tearDown(): void {
		$GLOBALS['openclawp_test_filters'] = array();
		parent::tearDown();
	}

	/**
	 * One turn: user text, a tool call, its result, the assistant reply.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function turn( int $n, string $result = 'ok' ): array {
		return array(
			array( 'type' => 'text', 'role' => 'user', 'content' => "question $n" ),
			array( 'type' => 'tool_call', 'role' => 'assistant', 'content' => '', 'payload' => array( 'tool_name' => 'client/lookup', 'parameters' => array( 'n' => $n ) ) ),
			array( 'type' => 'tool_result', 'role' => 'user', 'content' => '', 'payload' => array( 'tool_name' => 'client/lookup', 'result' => array( 'data' => $result ) ) ),
			array( 'type' => 'text', 'role' => 'assistant', 'content' => "answer $n" ),
		);
	}

	/** @return array<int,array<string,mixed>> */
	private static function transcript( int $turns, string $result = 'ok' ): array {
		$out = array();
		for ( $n = 1; $n <= $turns; $n++ ) {
			$out = array_merge( $out, self::turn( $n, $result ) );
		}
		return $out;
	}

	public function test_turn_starts_are_user_text_messages_only(): void {
		$this->assertSame( array( 0, 4, 8 ), OpenclaWP_History_Window::turn_starts( self::transcript( 3 ) ) );
	}

	public function test_no_cut_until_window_plus_batch_is_exceeded(): void {
		// keep 2 + batch 2 = 4 turns fit without a summary.
		$this->assertNull( OpenclaWP_History_Window::next_cut( self::transcript( 4 ), 0, self::SETTINGS ) );
	}

	public function test_cut_leaves_exactly_keep_turns_at_a_turn_start(): void {
		// 5 turns → summarize turns 1-3, keep 4 and 5 (index 12).
		$this->assertSame( 12, OpenclaWP_History_Window::next_cut( self::transcript( 5 ), 0, self::SETTINGS ) );
	}

	public function test_cut_counts_only_turns_after_the_current_summary(): void {
		// Summary already covers up to turn 4 (index 12): 3 pending turns fit.
		$this->assertNull( OpenclaWP_History_Window::next_cut( self::transcript( 7 ) , 12, self::SETTINGS ) );
		// 5 pending turns → move to turn 7 (index 24).
		$this->assertSame( 24, OpenclaWP_History_Window::next_cut( self::transcript( 8 ), 8, self::SETTINGS ) );
	}

	public function test_window_off_sends_everything(): void {
		$settings = array( 'keep_turns' => 0 ) + self::SETTINGS;
		$messages = self::transcript( 9 );
		$this->assertNull( OpenclaWP_History_Window::next_cut( $messages, 0, $settings ) );
		$this->assertSame( $messages, OpenclaWP_History_Window::for_model( $messages, 'old', 8, $settings ) );
	}

	public function test_for_model_drops_summarized_turns_and_prepends_summary(): void {
		$window = OpenclaWP_History_Window::for_model( self::transcript( 5 ), 'They agreed on 12 lambs.', 12, self::SETTINGS );

		$this->assertCount( 8, $window );
		$this->assertStringStartsWith( "[Earlier in this conversation]\nThey agreed on 12 lambs.", $window[0]['content'] );
		$this->assertStringEndsWith( 'question 4', $window[0]['content'] );
		$this->assertSame( 'answer 5', $window[7]['content'] );
	}

	public function test_for_model_ignores_a_summary_that_would_split_a_turn(): void {
		$messages = self::transcript( 3 );
		$window   = OpenclaWP_History_Window::for_model( $messages, 'stale', 6, self::SETTINGS );

		$this->assertCount( 12, $window );
		$this->assertSame( 'question 1', $window[0]['content'] );
	}

	public function test_older_tool_results_are_cut_but_the_current_turn_stays_whole(): void {
		$big    = str_repeat( 'x', 5000 );
		$window = OpenclaWP_History_Window::for_model( self::transcript( 2, $big ), '', 0, self::SETTINGS );

		$old = $window[2]['payload']['result'];
		$this->assertIsString( $old );
		$this->assertStringContainsString( '[cut: 200 of', $old );
		$this->assertLessThan( 400, strlen( $old ) );

		$this->assertSame( array( 'data' => $big ), $window[6]['payload']['result'] );
	}

	public function test_short_tool_results_are_untouched(): void {
		$message = self::turn( 1 )[2];
		$this->assertSame( $message, OpenclaWP_History_Window::shorten_tool_result( $message, 200 ) );
	}

	public function test_render_for_summary_is_plain_text_with_short_tool_results(): void {
		$text = OpenclaWP_History_Window::render_for_summary( self::turn( 1, str_repeat( 'y', 3000 ) ) );

		$this->assertStringContainsString( 'User: question 1', $text );
		$this->assertStringContainsString( 'Tool call: client/lookup {"n":1}', $text );
		$this->assertStringContainsString( 'Assistant: answer 1', $text );
		$this->assertLessThan( 1000, strlen( $text ) );
	}

	public function test_settings_filter_can_turn_the_window_off(): void {
		$GLOBALS['openclawp_test_filters']['openclawp_history_window'] = static fn ( array $s ) => array( 'keep_turns' => 0 ) + $s;
		$this->assertSame( 0, OpenclaWP_History_Window::settings( 'any' )['keep_turns'] );
	}

	public function test_default_settings(): void {
		$settings = OpenclaWP_History_Window::settings( 'any' );
		$this->assertSame( 8, $settings['keep_turns'] );
		$this->assertSame( 4, $settings['summarize_every'] );
	}

	public function test_summary_instruction_asks_for_dated_facts_without_status(): void {
		$instruction = OpenclaWP_History_Window::summary_instruction( 1200 );

		$this->assertStringContainsString( 'at most 1200 characters', $instruction );
		$this->assertStringContainsString( 'dated record', $instruction );
		$this->assertStringContainsString( 'Never say whether a request, task, purchase or payment is pending', $instruction );
	}
}
