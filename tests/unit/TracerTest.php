<?php
/**
 * Unit tests for OpenclaWP_Tracer.
 *
 * Drives the tracer through one synthetic turn + one tool call, asserts the
 * resulting OTLP/HTTP JSON payload satisfies GenAI semantic conventions, and
 * captures the outbound HTTP request against an in-process fake backend.
 *
 * @package OpenclaWP\Tests
 */

declare( strict_types=1 );

namespace OpenclaWP\Tests\Unit;

use OpenclaWP_Tracer;
use PHPUnit\Framework\TestCase;

/**
 * @covers OpenclaWP_Tracer
 */
final class TracerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		OpenclaWP_Tracer::reset();
		// Configure the endpoint so is_enabled() returns true.
		$GLOBALS['openclawp_test_filters']['openclawp_otel_endpoint']  = static fn () => 'https://collector.example/v1/traces';
		$GLOBALS['openclawp_test_filters']['openclawp_otel_auth_header'] = static fn () => 'Authorization: Bearer test-token';
		$GLOBALS['openclawp_test_http_capture'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['openclawp_test_filters']  = array();
		$GLOBALS['openclawp_test_http_capture'] = array();
		OpenclaWP_Tracer::reset();
		parent::tearDown();
	}

	public function test_endpoint_resolves_from_filter_chain(): void {
		$this->assertSame( 'https://collector.example/v1/traces', OpenclaWP_Tracer::endpoint() );
		$this->assertTrue( OpenclaWP_Tracer::is_enabled() );
	}

	public function test_disabled_when_no_endpoint_configured(): void {
		$GLOBALS['openclawp_test_filters']['openclawp_otel_endpoint'] = static fn () => '';
		$this->assertSame( '', OpenclaWP_Tracer::endpoint() );
		$this->assertFalse( OpenclaWP_Tracer::is_enabled() );
	}

	public function test_turn_root_span_uses_genai_semconv_attributes(): void {
		OpenclaWP_Tracer::set_runtime_context(
			array(
				'openclawp.session.id' => 'sess-abc',
				'openclawp.agent.slug' => 'support-bot',
				'openclawp.user.id'    => '42',
				'openclawp.channel'    => 'whatsapp',
			)
		);

		OpenclaWP_Tracer::on_turn_completed(
			array(
				'agent_slug'      => 'support-bot',
				'session_id'      => 'sess-abc',
				'provider'        => 'anthropic',
				'model'           => 'claude-opus-4-7',
				'token_usage'     => array( 'input' => 1200, 'output' => 350, 'total' => 1550 ),
				'duration_ms'     => 480,
				'success'         => true,
				'error'           => null,
				'tool_call_count' => 1,
			)
		);

		$spans = OpenclaWP_Tracer::buffered_spans();
		$this->assertCount( 1, $spans );
		$root = $spans[0];

		$attrs = $this->attrs_to_map( $root['attributes'] );

		// GenAI semconv required attributes.
		$this->assertSame( 'anthropic', $attrs['gen_ai.system'] );
		$this->assertSame( 'chat', $attrs['gen_ai.operation.name'] );
		$this->assertSame( 'claude-opus-4-7', $attrs['gen_ai.request.model'] );
		$this->assertSame( 1200, $attrs['gen_ai.usage.input_tokens'] );
		$this->assertSame( 350, $attrs['gen_ai.usage.output_tokens'] );
		$this->assertSame( array( 'stop' ), $attrs['gen_ai.response.finish_reasons'] );

		// openclawp namespaced attributes.
		$this->assertSame( 'sess-abc', $attrs['openclawp.session.id'] );
		$this->assertSame( 'support-bot', $attrs['openclawp.agent.slug'] );
		$this->assertSame( '42', $attrs['openclawp.user.id'] );
		$this->assertSame( 'whatsapp', $attrs['openclawp.channel'] );

		// Span name and kind.
		$this->assertSame( 'chat claude-opus-4-7', $root['name'] );
		$this->assertSame( 3, $root['kind'] ); // CLIENT.
		$this->assertSame( '', $root['parent_id'] );
		$this->assertSame( 1, $root['status_code'] ); // OK.
	}

	public function test_failed_turn_marks_span_as_error(): void {
		OpenclaWP_Tracer::on_turn_completed(
			array(
				'agent_slug'  => 'a',
				'session_id'  => 's',
				'provider'    => 'anthropic',
				'model'       => 'claude-haiku-4-5',
				'token_usage' => array(),
				'duration_ms' => 10,
				'success'     => false,
				'error'       => 'timeout',
			)
		);

		$root  = OpenclaWP_Tracer::buffered_spans()[0];
		$this->assertSame( 2, $root['status_code'] ); // ERROR.
		$this->assertSame( 'timeout', $root['status_msg'] );
	}

	public function test_tool_call_and_result_become_child_span(): void {
		OpenclaWP_Tracer::on_turn_completed(
			array(
				'agent_slug'  => 'a',
				'session_id'  => 's',
				'provider'    => 'anthropic',
				'model'       => 'claude-opus-4-7',
				'token_usage' => array( 'input' => 10, 'output' => 5 ),
				'duration_ms' => 100,
				'success'     => true,
				'error'       => null,
			)
		);

		OpenclaWP_Tracer::on_loop_event(
			'tool_call',
			array( 'turn' => 1, 'tool_name' => 'echo', 'parameters' => array( 'msg' => 'hi' ) )
		);
		OpenclaWP_Tracer::on_loop_event(
			'tool_result',
			array( 'turn' => 1, 'tool_name' => 'echo', 'success' => true )
		);

		$spans = OpenclaWP_Tracer::buffered_spans();
		$this->assertCount( 2, $spans );

		$root  = $spans[0];
		$child = $spans[1];

		$this->assertSame( $root['span_id'], $child['parent_id'] );
		$this->assertSame( $root['trace_id'], $child['trace_id'] );
		$this->assertSame( 'execute_tool echo', $child['name'] );
		$this->assertSame( 1, $child['kind'] ); // INTERNAL.

		$attrs = $this->attrs_to_map( $child['attributes'] );
		$this->assertSame( 'execute_tool', $attrs['gen_ai.operation.name'] );
		$this->assertSame( 'echo', $attrs['gen_ai.tool.name'] );
	}

	public function test_failed_tool_result_marks_child_span_as_error(): void {
		OpenclaWP_Tracer::on_turn_completed(
			array(
				'provider'    => 'anthropic',
				'model'       => 'claude-opus-4-7',
				'session_id'  => 's',
				'agent_slug'  => 'a',
				'token_usage' => array(),
				'duration_ms' => 1,
				'success'     => true,
				'error'       => null,
			)
		);

		OpenclaWP_Tracer::on_loop_event( 'tool_call', array( 'turn' => 1, 'tool_name' => 'broken' ) );
		OpenclaWP_Tracer::on_loop_event( 'tool_result', array( 'turn' => 1, 'tool_name' => 'broken', 'success' => false ) );

		$child = OpenclaWP_Tracer::buffered_spans()[1];
		$this->assertSame( 2, $child['status_code'] );
	}

	public function test_orphan_tool_result_is_silently_dropped(): void {
		OpenclaWP_Tracer::on_loop_event( 'tool_result', array( 'turn' => 1, 'tool_name' => 'never_called', 'success' => true ) );
		$this->assertCount( 0, OpenclaWP_Tracer::buffered_spans() );
	}

	public function test_flush_emits_otlp_http_json_envelope_to_endpoint(): void {
		OpenclaWP_Tracer::on_turn_completed(
			array(
				'agent_slug'  => 'a',
				'session_id'  => 's',
				'provider'    => 'anthropic',
				'model'       => 'claude-opus-4-7',
				'token_usage' => array( 'input' => 1, 'output' => 1 ),
				'duration_ms' => 1,
				'success'     => true,
				'error'       => null,
			)
		);

		$result = OpenclaWP_Tracer::flush();

		$this->assertSame( 1, $result['sent'] );
		$this->assertSame( 202, $result['response_code'] );

		$capture = $GLOBALS['openclawp_test_http_capture'];
		$this->assertCount( 1, $capture );
		$this->assertSame( 'https://collector.example/v1/traces', $capture[0]['url'] );
		$this->assertSame( 'application/json', $capture[0]['args']['headers']['Content-Type'] );
		$this->assertSame( 'Bearer test-token', $capture[0]['args']['headers']['Authorization'] );

		$decoded = json_decode( $capture[0]['args']['body'], true );
		$this->assertIsArray( $decoded );

		// OTLP envelope shape: resourceSpans[].scopeSpans[].spans[].
		$this->assertArrayHasKey( 'resourceSpans', $decoded );
		$resource_span = $decoded['resourceSpans'][0];
		$resource_attrs = $this->serialized_attrs_to_map( $resource_span['resource']['attributes'] );
		$this->assertSame( 'openclawp', $resource_attrs['service.name'] );

		$scope_span = $resource_span['scopeSpans'][0];
		$this->assertSame( 'openclawp.tracer', $scope_span['scope']['name'] );
		$this->assertCount( 1, $scope_span['spans'] );

		$span_attrs = $this->serialized_attrs_to_map( $scope_span['spans'][0]['attributes'] );
		$this->assertSame( 'anthropic', $span_attrs['gen_ai.system'] );
		$this->assertSame( 'claude-opus-4-7', $span_attrs['gen_ai.request.model'] );
		$this->assertSame( 'chat', $span_attrs['gen_ai.operation.name'] );
		$this->assertSame( 1, $span_attrs['gen_ai.usage.input_tokens'] );
		$this->assertSame( 1, $span_attrs['gen_ai.usage.output_tokens'] );
		$this->assertSame( array( 'stop' ), $span_attrs['gen_ai.response.finish_reasons'] );
	}

	public function test_overhead_under_5ms_per_turn(): void {
		OpenclaWP_Tracer::reset();
		// One turn + one tool call (typical shape).
		OpenclaWP_Tracer::on_turn_completed(
			array(
				'agent_slug'  => 'a',
				'session_id'  => 's',
				'provider'    => 'anthropic',
				'model'       => 'claude-opus-4-7',
				'token_usage' => array( 'input' => 100, 'output' => 50 ),
				'duration_ms' => 200,
				'success'     => true,
				'error'       => null,
			)
		);
		OpenclaWP_Tracer::on_loop_event( 'tool_call', array( 'turn' => 1, 'tool_name' => 't' ) );
		OpenclaWP_Tracer::on_loop_event( 'tool_result', array( 'turn' => 1, 'tool_name' => 't', 'success' => true ) );

		$overhead_us = OpenclaWP_Tracer::overhead_us();
		// Generous budget: <5ms = <5000us per turn.
		$this->assertLessThan( 5000, $overhead_us, sprintf( 'Tracer overhead %dus exceeds 5ms budget', $overhead_us ) );
	}

	// ------------------------------------------------------------------
	// Helpers.
	// ------------------------------------------------------------------

	/**
	 * Flatten the in-memory buffered span attribute map. Buffered spans
	 * store attributes as a flat associative array — same as the public
	 * runtime API — so this is just a passthrough cast.
	 *
	 * @param array<string,mixed> $attrs
	 * @return array<string,mixed>
	 */
	private function attrs_to_map( array $attrs ): array {
		return $attrs;
	}

	/**
	 * Flatten OTLP-serialized KeyValue array into a `name => native` map.
	 *
	 * @param array<int,array<string,mixed>> $kv_list
	 * @return array<string,mixed>
	 */
	private function serialized_attrs_to_map( array $kv_list ): array {
		$out = array();
		foreach ( $kv_list as $kv ) {
			$value = $kv['value'];
			if ( array_key_exists( 'stringValue', $value ) ) {
				$out[ $kv['key'] ] = $value['stringValue'];
			} elseif ( array_key_exists( 'intValue', $value ) ) {
				$out[ $kv['key'] ] = (int) $value['intValue'];
			} elseif ( array_key_exists( 'boolValue', $value ) ) {
				$out[ $kv['key'] ] = (bool) $value['boolValue'];
			} elseif ( array_key_exists( 'doubleValue', $value ) ) {
				$out[ $kv['key'] ] = (float) $value['doubleValue'];
			} elseif ( array_key_exists( 'arrayValue', $value ) ) {
				$items = array();
				foreach ( $value['arrayValue']['values'] as $item ) {
					if ( array_key_exists( 'stringValue', $item ) ) {
						$items[] = $item['stringValue'];
					}
				}
				$out[ $kv['key'] ] = $items;
			}
		}
		return $out;
	}
}
