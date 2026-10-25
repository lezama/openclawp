<?php
/**
 * Pure-PHP tests for the Claude Agent bridge runner.
 *
 * @package OpenclaWP\Tests
 */

declare( strict_types=1 );

namespace OpenclaWP\Tests\Unit;

use OpenclaWP_Claude_Agent_Runner;
use PHPUnit\Framework\TestCase;
use WP_Agent;

/**
 * @covers OpenclaWP_Claude_Agent_Runner
 */
final class ClaudeAgentRunnerTest extends TestCase {

	protected function tearDown(): void {
		$GLOBALS['openclawp_test_filters'] = array();
		$GLOBALS['openclawp_test_actions'] = array();
		parent::tearDown();
	}

	private static function agent( string $provider = 'claude-agent' ): WP_Agent {
		return new WP_Agent(
			'campo',
			array(
				'description'    => 'Asistente del campo.',
				'default_config' => array(
					'provider' => $provider,
					'model'    => 'sonnet',
				),
			)
		);
	}

	/** @return array<int,array<string,mixed>> */
	private static function transcript(): array {
		return array(
			array( 'type' => 'text', 'role' => 'user', 'content' => '¿Llovió?' ),
			array( 'type' => 'tool_call', 'role' => 'assistant', 'content' => '', 'payload' => array( 'tool_name' => 'client/carpeta__lluvia', 'parameters' => array( 'dia' => 'ayer' ) ), 'metadata' => array( 'tool_call_id' => 'c1' ) ),
			array( 'type' => 'tool_result', 'role' => 'user', 'content' => '', 'payload' => array( 'success' => true, 'tool_name' => 'client/carpeta__lluvia', 'result' => array( 'mm' => 23 ) ), 'metadata' => array( 'tool_call_id' => 'c1' ) ),
			array( 'type' => 'text', 'role' => 'assistant', 'content' => 'Llovieron 23 mm.' ),
			array( 'type' => 'text', 'role' => 'user', 'content' => '¿Y hoy?' ),
		);
	}

	public function test_applies_only_to_claude_agent_provider(): void {
		$this->assertTrue( OpenclaWP_Claude_Agent_Runner::applies( self::agent() ) );
		$this->assertFalse( OpenclaWP_Claude_Agent_Runner::applies( self::agent( 'anthropic' ) ) );
	}

	public function test_filter_can_route_any_agent_to_the_bridge(): void {
		$GLOBALS['openclawp_test_filters']['openclawp_use_claude_agent'] = static fn () => true;
		$this->assertTrue( OpenclaWP_Claude_Agent_Runner::applies( self::agent( 'anthropic' ) ) );
	}

	public function test_render_prompt_separates_history_from_latest_message(): void {
		$prompt = OpenclaWP_Claude_Agent_Runner::render_prompt( self::transcript() );

		$this->assertStringStartsWith( "<conversation_so_far>\nUser: ¿Llovió?\nTool call: carpeta__lluvia {\"dia\":\"ayer\"}\nTool result: {\"mm\":23}\nAssistant: Llovieron 23 mm.\n</conversation_so_far>", $prompt );
		$this->assertStringEndsWith( "<latest_user_message>\n¿Y hoy?\n</latest_user_message>", $prompt );
		$this->assertStringNotContainsString( 'already_done_this_turn', $prompt );
	}

	public function test_render_prompt_includes_tool_work_already_done_this_turn(): void {
		$prompt = OpenclaWP_Claude_Agent_Runner::render_prompt( array_slice( self::transcript(), 0, 3 ) );

		$this->assertStringStartsWith( "<latest_user_message>\n¿Llovió?\n</latest_user_message>", $prompt );
		$this->assertStringContainsString( "<already_done_this_turn>\nTool call: carpeta__lluvia", $prompt );
	}

	public function test_results_for_returns_pending_results_as_text(): void {
		$results = OpenclaWP_Claude_Agent_Runner::results_for( self::transcript(), array( 'c1' ) );
		$this->assertSame( array( 'c1' => array( 'content' => '{"mm":23}', 'is_error' => false ) ), $results );
	}

	public function test_results_for_flags_failed_tools(): void {
		$messages   = self::transcript();
		$messages[2]['payload'] = array( 'success' => false, 'tool_name' => 'client/carpeta__lluvia', 'error' => 'sin datos' );

		$results = OpenclaWP_Claude_Agent_Runner::results_for( $messages, array( 'c1' ) );
		$this->assertSame( array( 'c1' => array( 'content' => 'sin datos', 'is_error' => true ) ), $results );
	}

	public function test_results_for_is_null_when_a_pending_result_is_missing(): void {
		$this->assertNull( OpenclaWP_Claude_Agent_Runner::results_for( self::transcript(), array( 'c1', 'c2' ) ) );
	}

	public function test_tool_calls_map_to_loop_names(): void {
		$out = OpenclaWP_Claude_Agent_Runner::to_turn_result(
			self::agent(),
			's1',
			array(),
			array(
				'conversation_id' => 'r1',
				'status'          => 'tool_calls',
				'tool_calls'      => array( array( 'id' => 'c9', 'name' => 'carpeta__lluvia', 'parameters' => array( 'dia' => 'hoy' ) ) ),
				'text'            => 'Me fijo.',
			),
			true
		);

		$this->assertSame( array( array( 'id' => 'c9', 'name' => 'client/carpeta__lluvia', 'parameters' => array( 'dia' => 'hoy' ) ) ), $out['tool_calls'] );
		$this->assertSame( 'Me fijo.', $out['content'] );
	}

	public function test_done_returns_final_text_and_reports_usage(): void {
		$seen = null;
		$GLOBALS['openclawp_test_actions']['openclawp_chat_turn_completed'] = static function ( array $t ) use ( &$seen ): void {
			$seen = $t;
		};

		$out = OpenclaWP_Claude_Agent_Runner::to_turn_result(
			self::agent(),
			's1',
			array(),
			array(
				'status'      => 'done',
				'text'        => 'Hoy no llovió.',
				'usage'       => array( 'input_tokens' => 4, 'cache_read_input_tokens' => 3000, 'cache_creation_input_tokens' => 76, 'output_tokens' => 60 ),
				'model_usage' => array( 'claude-sonnet-5-5' => array() ),
			),
			true
		);

		$this->assertSame( array(), $out['tool_calls'] );
		$this->assertSame( 'Hoy no llovió.', $out['content'] );
		$this->assertSame( 'claude-agent', $seen['provider'] );
		$this->assertSame( 'claude-sonnet-5-5', $seen['model'] );
		$this->assertSame( array( 'input' => 3080, 'output' => 60, 'total' => 3140 ), $seen['token_usage'] );
		$this->assertTrue( $seen['success'] );
	}

	public function test_bridge_error_throws_and_reports_failed_telemetry(): void {
		$seen = null;
		$GLOBALS['openclawp_test_actions']['openclawp_chat_turn_completed'] = static function ( array $t ) use ( &$seen ): void {
			$seen = $t;
		};

		try {
			OpenclaWP_Claude_Agent_Runner::to_turn_result( self::agent(), 's1', array(), array( 'status' => 'error', 'error' => 'bridge unreachable' ), true );
			$this->fail( 'A bridge error must not come back as a reply.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'claude-agent: bridge unreachable', $e->getMessage() );
		}
		$this->assertFalse( $seen['success'] );
		$this->assertSame( 'bridge unreachable', $seen['error'] );
	}

	public function test_without_tools_returns_the_completed_legacy_shape(): void {
		$out = OpenclaWP_Claude_Agent_Runner::to_turn_result( self::agent(), 's1', array(), array( 'status' => 'done', 'text' => 'Hola.' ), false );

		$this->assertTrue( $out['conversation_complete'] );
		$this->assertSame( array( 'role' => 'assistant', 'content' => 'Hola.' ), $out['messages'][0] );
	}
}
