<?php
/**
 * Unit tests for OpenclaWP_A2a_Client_Transport.
 *
 * Covers the pure parsing/header helpers (parse_task_response, caller_headers)
 * and the wp_remote_* round-trip via the stubbed transport in
 * tests/bootstrap.php (which captures outbound requests and lets a test set
 * the canned response). No real network, no WordPress.
 *
 * @package OpenclaWP\Tests
 */

declare( strict_types=1 );

namespace OpenclaWP\Tests\Unit;

use OpenclaWP_A2a_Client_Transport;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * @covers OpenclaWP_A2a_Client_Transport
 */
final class A2aClientTransportTest extends TestCase {

	protected function setUp(): void {
		unset(
			$GLOBALS['openclawp_test_http_capture'],
			$GLOBALS['openclawp_test_http_response'],
			$GLOBALS['openclawp_test_http_get_capture'],
			$GLOBALS['openclawp_test_http_get_response']
		);
	}

	// ---- parse_task_response -------------------------------------------

	public function test_parse_task_response_extracts_reply_and_session(): void {
		$envelope = array(
			'jsonrpc' => '2.0',
			'id'      => 'req-1',
			'result'  => array(
				'id'        => 'task-1',
				'sessionId' => 'sess-99',
				'status'    => array(
					'state'   => 'completed',
					'message' => array(
						'role'  => 'agent',
						'parts' => array(
							array( 'type' => 'text', 'text' => 'Hello from the peer.' ),
						),
					),
				),
			),
		);

		$parsed = OpenclaWP_A2a_Client_Transport::parse_task_response( $envelope );

		$this->assertIsArray( $parsed );
		$this->assertSame( 'Hello from the peer.', $parsed['reply'] );
		$this->assertSame( 'sess-99', $parsed['session_id'] );
		$this->assertSame( 'task-1', $parsed['task']['id'] );
	}

	public function test_parse_task_response_surfaces_rpc_error(): void {
		$parsed = OpenclaWP_A2a_Client_Transport::parse_task_response(
			array(
				'jsonrpc' => '2.0',
				'id'      => 'req-1',
				'error'   => array( 'code' => -32603, 'message' => 'peer blew up' ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $parsed );
		$this->assertStringContainsString( 'peer blew up', $parsed->get_error_message() );
	}

	public function test_parse_task_response_errors_on_missing_result(): void {
		$parsed = OpenclaWP_A2a_Client_Transport::parse_task_response( array( 'jsonrpc' => '2.0', 'id' => 'x' ) );
		$this->assertInstanceOf( WP_Error::class, $parsed );
	}

	// ---- caller_headers ------------------------------------------------

	public function test_caller_headers_describe_a_chained_remote_call(): void {
		$headers = OpenclaWP_A2a_Client_Transport::caller_headers(
			array( 'agent' => 'openclawp-coordinator', 'user_id' => 7 )
		);

		// A call originating here is one hop above top-of-chain.
		$this->assertSame( 'openclawp-coordinator', $headers['X-Agents-Api-Caller-Agent'] );
		$this->assertSame( '7', $headers['X-Agents-Api-Caller-User'] );
		$this->assertSame( '1', $headers['X-Agents-Api-Chain-Depth'] );
		// caller_host must be an absolute URL, never "self", for chained calls.
		$this->assertNotSame( 'self', $headers['X-Agents-Api-Caller-Host'] );
		$this->assertStringStartsWith( 'http', $headers['X-Agents-Api-Caller-Host'] );
		$this->assertNotSame( '', $headers['X-Agents-Api-Chain-Root'] );
	}

	public function test_caller_headers_increment_inbound_depth(): void {
		$headers = OpenclaWP_A2a_Client_Transport::caller_headers(
			array( 'agent' => 'a', 'chain_depth' => 3, 'chain_root' => 'root-abc' )
		);

		$this->assertSame( '4', $headers['X-Agents-Api-Chain-Depth'] );
		$this->assertSame( 'root-abc', $headers['X-Agents-Api-Chain-Root'] );
	}

	public function test_caller_headers_empty_without_agent(): void {
		$this->assertSame( array(), OpenclaWP_A2a_Client_Transport::caller_headers( array() ) );
	}

	public function test_caller_headers_round_trip_through_agents_api_when_available(): void {
		if ( ! class_exists( 'WP_Agent_Caller_Context' ) ) {
			$this->markTestSkipped( 'agents-api WP_Agent_Caller_Context not autoloaded in this run.' );
		}

		$headers = OpenclaWP_A2a_Client_Transport::caller_headers(
			array( 'agent' => 'openclawp-coordinator', 'user_id' => 7 )
		);

		// The peer parses these with from_headers(); a chained context must
		// survive the round-trip without throwing.
		$context = \WP_Agent_Caller_Context::from_headers( $headers );
		$this->assertSame( 'openclawp-coordinator', $context->caller_agent_id );
		$this->assertSame( 1, $context->chain_depth );
		$this->assertTrue( $context->is_cross_site() );
	}

	// ---- send_message (round-trip via stub) ----------------------------

	public function test_send_message_posts_jsonrpc_and_returns_reply(): void {
		$GLOBALS['openclawp_test_http_response'] = array(
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'body'     => wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 'req-1',
					'result'  => array(
						'id'        => 'task-1',
						'sessionId' => 'sess-1',
						'status'    => array(
							'message' => array(
								'parts' => array( array( 'type' => 'text', 'text' => 'pong' ) ),
							),
						),
					),
				)
			),
		);

		$result = OpenclaWP_A2a_Client_Transport::send_message(
			'https://peer.test/wp-json/openclawp/v1/agenttic/openclawp-loop-demo',
			'ping',
			null,
			array( 'agent' => 'openclawp-coordinator' ),
			array( 'Authorization' => 'Bearer xyz' )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'pong', $result['reply'] );

		// Assert the outbound request shape.
		$captured = $GLOBALS['openclawp_test_http_capture'][0] ?? null;
		$this->assertNotNull( $captured );
		$this->assertSame( 'https://peer.test/wp-json/openclawp/v1/agenttic/openclawp-loop-demo', $captured['url'] );

		$sent = json_decode( $captured['args']['body'], true );
		$this->assertSame( '2.0', $sent['jsonrpc'] );
		$this->assertSame( 'message/send', $sent['method'] );
		$this->assertSame( 'ping', $sent['params']['message']['parts'][0]['text'] );

		// Caller-context + auth headers ride along.
		$this->assertSame( 'openclawp-coordinator', $captured['args']['headers']['X-Agents-Api-Caller-Agent'] );
		$this->assertSame( 'Bearer xyz', $captured['args']['headers']['Authorization'] );
	}

	public function test_send_message_includes_session_id_when_continuing(): void {
		$GLOBALS['openclawp_test_http_response'] = array(
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'body'     => wp_json_encode(
				array( 'jsonrpc' => '2.0', 'id' => 'r', 'result' => array( 'sessionId' => 'sess-1', 'status' => array( 'message' => array( 'parts' => array( array( 'type' => 'text', 'text' => 'ok' ) ) ) ) ) )
			),
		);

		OpenclaWP_A2a_Client_Transport::send_message(
			'https://peer.test/x',
			'again',
			'sess-1',
			array( 'agent' => 'a' )
		);

		$sent = json_decode( $GLOBALS['openclawp_test_http_capture'][0]['args']['body'], true );
		$this->assertSame( 'sess-1', $sent['params']['sessionId'] );
	}

	public function test_send_message_errors_on_http_failure_status(): void {
		$GLOBALS['openclawp_test_http_response'] = array(
			'response' => array( 'code' => 500, 'message' => 'Server Error' ),
			'body'     => 'boom',
		);

		$result = OpenclaWP_A2a_Client_Transport::send_message( 'https://peer.test/x', 'ping', null, array( 'agent' => 'a' ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'a2a_client_http_status', $result->get_error_code() );
	}

	public function test_send_message_requires_endpoint_and_text(): void {
		$this->assertInstanceOf( WP_Error::class, OpenclaWP_A2a_Client_Transport::send_message( '', 'hi', null ) );
		$this->assertInstanceOf( WP_Error::class, OpenclaWP_A2a_Client_Transport::send_message( 'https://peer.test/x', '   ', null ) );
	}

	// ---- fetch_card ----------------------------------------------------

	public function test_fetch_card_returns_decoded_json(): void {
		$GLOBALS['openclawp_test_http_get_response'] = array(
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'body'     => wp_json_encode( array( 'name' => 'Peer Agent', 'skills' => array() ) ),
		);

		$card = OpenclaWP_A2a_Client_Transport::fetch_card( 'https://peer.test/wp-json/openclawp/v1/agenttic/x/.well-known/agent-card.json' );
		$this->assertIsArray( $card );
		$this->assertSame( 'Peer Agent', $card['name'] );
	}

	public function test_fetch_card_errors_on_non_json(): void {
		$GLOBALS['openclawp_test_http_get_response'] = array(
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'body'     => '<html>not json</html>',
		);

		$card = OpenclaWP_A2a_Client_Transport::fetch_card( 'https://peer.test/card.json' );
		$this->assertInstanceOf( WP_Error::class, $card );
	}
}
