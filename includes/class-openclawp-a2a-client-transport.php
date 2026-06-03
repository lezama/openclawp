<?php
/**
 * Outbound A2A client transport.
 *
 * The mirror of {@see OpenclaWP_Mcp_Client_Transport}, but for the A2A wire
 * the {@see OpenclaWP_Agenttic_Bridge} speaks rather than MCP. Implements just
 * enough of the agenttic / A2A JSON-RPC protocol for openclaWP to act as a
 * *client* of another agent:
 *
 *   - `fetch_card()`  — GET a peer's `.well-known/agent-card.json` (discovery).
 *   - `send_message()` — POST a `message/send` JSON-RPC request and parse the
 *                        returned Task envelope down to the agent's reply text.
 *
 * Every outbound call carries the canonical agents-api cross-site caller-chain
 * headers (`X-Agents-Api-*`, see {@see WP_Agent_Caller_Context}) so the peer
 * can audit the call as an agent-to-agent delegation and enforce its own
 * depth/trust policy. We build those headers here; the peer is responsible for
 * deciding whether to trust them.
 *
 * Streaming (`message/stream`) is intentionally not consumed on the client
 * side yet — a synchronous `message/send` is enough for the tool-call path,
 * where the loop blocks on the peer's reply anyway.
 *
 * @package OpenclaWP
 * @since 0.7.0
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_A2a_Client_Transport {

	private const READ_TIMEOUT_SECONDS = 30;

	// Canonical agents-api caller-chain header names. Mirrors
	// WP_Agent_Caller_Context::HEADER_* — duplicated as literals so the client
	// works even when the agents-api value object isn't autoloaded (the peer's
	// authenticator parses these names regardless).
	private const HEADER_CALLER_AGENT = 'X-Agents-Api-Caller-Agent';
	private const HEADER_CALLER_USER  = 'X-Agents-Api-Caller-User';
	private const HEADER_CALLER_HOST  = 'X-Agents-Api-Caller-Host';
	private const HEADER_CHAIN_DEPTH  = 'X-Agents-Api-Chain-Depth';
	private const HEADER_CHAIN_ROOT   = 'X-Agents-Api-Chain-Root';

	/**
	 * Fetch a peer's agent card.
	 *
	 * @param string              $card_url Absolute URL of the agent card.
	 * @param array<string,string> $headers  Optional auth headers.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function fetch_card( string $card_url, array $headers = array() ) {
		if ( '' === $card_url ) {
			return new \WP_Error( 'a2a_client_no_card_url', 'agent card url is required' );
		}

		$response = wp_remote_get(
			$card_url,
			array(
				'timeout' => self::READ_TIMEOUT_SECONDS,
				'headers' => array_merge( array( 'Accept' => 'application/json' ), $headers ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error( 'a2a_client_card_http_status', sprintf( 'agent card returned HTTP %d: %s', $code, substr( $body, 0, 200 ) ) );
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'a2a_client_card_bad_json', 'agent card returned non-JSON body' );
		}

		return $decoded;
	}

	/**
	 * Send a single text message to a peer agent and return its reply.
	 *
	 * @param string              $endpoint    Absolute URL of the peer's JSON-RPC message endpoint.
	 * @param string              $text        User-message text to send.
	 * @param string|null         $session_id  Optional peer-side session to continue.
	 * @param array<string,mixed> $caller      Caller-context descriptor: keys `agent`, `user_id`, `session_id`, `chain_depth`, `chain_root`.
	 * @param array<string,string> $headers    Optional extra headers (e.g. Authorization).
	 *
	 * @return array{reply:string,session_id:string,task:array<string,mixed>}|\WP_Error
	 */
	public static function send_message( string $endpoint, string $text, ?string $session_id, array $caller = array(), array $headers = array() ) {
		if ( '' === $endpoint ) {
			return new \WP_Error( 'a2a_client_no_endpoint', 'peer endpoint url is required' );
		}
		if ( '' === trim( $text ) ) {
			return new \WP_Error( 'a2a_client_empty_message', 'message text is required' );
		}

		$params = array(
			'id'      => 'task-' . wp_generate_password( 12, false, false ),
			'message' => array(
				'role'      => 'user',
				'parts'     => array(
					array(
						'type' => 'text',
						'text' => $text,
					),
				),
				'messageId' => wp_generate_password( 12, false, false ),
				'kind'      => 'message',
			),
		);
		if ( null !== $session_id && '' !== $session_id ) {
			$params['sessionId'] = $session_id;
		}

		$request_headers = array_merge(
			array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
			self::caller_headers( $caller ),
			$headers
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => self::READ_TIMEOUT_SECONDS,
				'headers' => $request_headers,
				'body'    => wp_json_encode(
					array(
						'jsonrpc' => '2.0',
						'id'      => 'req-' . wp_generate_password( 8, false, false ),
						'method'  => 'message/send',
						'params'  => $params,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error( 'a2a_client_http_status', sprintf( 'peer returned HTTP %d: %s', $code, substr( $body, 0, 200 ) ) );
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'a2a_client_bad_json', 'peer returned non-JSON body' );
		}

		return self::parse_task_response( $decoded );
	}

	/**
	 * Parse an A2A JSON-RPC response envelope into the reply/session/task
	 * triple. Pure — exposed for unit tests.
	 *
	 * @param array<string,mixed> $decoded Decoded JSON-RPC envelope.
	 *
	 * @return array{reply:string,session_id:string,task:array<string,mixed>}|\WP_Error
	 */
	public static function parse_task_response( array $decoded ) {
		if ( isset( $decoded['error'] ) && is_array( $decoded['error'] ) ) {
			$message = isset( $decoded['error']['message'] ) ? (string) $decoded['error']['message'] : 'unknown error';
			return new \WP_Error( 'a2a_client_rpc_error', sprintf( 'peer error: %s', $message ) );
		}

		$task = isset( $decoded['result'] ) && is_array( $decoded['result'] ) ? $decoded['result'] : array();
		if ( empty( $task ) ) {
			return new \WP_Error( 'a2a_client_no_result', 'peer response had no result' );
		}

		$reply   = '';
		$status  = isset( $task['status'] ) && is_array( $task['status'] ) ? $task['status'] : array();
		$message = isset( $status['message'] ) && is_array( $status['message'] ) ? $status['message'] : array();
		$parts   = isset( $message['parts'] ) && is_array( $message['parts'] ) ? $message['parts'] : array();
		foreach ( $parts as $part ) {
			if ( is_array( $part ) && 'text' === ( $part['type'] ?? '' ) && isset( $part['text'] ) ) {
				$reply = (string) $part['text'];
				break;
			}
		}

		return array(
			'reply'      => $reply,
			'session_id' => isset( $task['sessionId'] ) ? (string) $task['sessionId'] : '',
			'task'       => $task,
		);
	}

	/**
	 * Build the canonical cross-site caller-chain headers for an outbound call.
	 * Pure — exposed for unit tests.
	 *
	 * A call originating in this site is one hop above top-of-chain, so the
	 * peer receives `chain_depth >= 1` with this site as the remote caller
	 * host. When this site is itself mid-chain (it was called by another
	 * agent), pass the inbound `chain_depth` / `chain_root` through `$caller`
	 * so the depth ceiling stays meaningful end-to-end.
	 *
	 * @param array<string,mixed> $caller Keys: `agent`, `user_id`, `chain_depth`, `chain_root`.
	 *
	 * @return array<string,string>
	 */
	public static function caller_headers( array $caller ): array {
		$agent = isset( $caller['agent'] ) ? (string) $caller['agent'] : '';
		if ( '' === $agent ) {
			// Without a caller agent slug there is no auditable chain to send;
			// let the call go out as an anonymous top-of-chain request.
			return array();
		}

		$inbound_depth = isset( $caller['chain_depth'] ) ? max( 0, (int) $caller['chain_depth'] ) : 0;
		$depth         = $inbound_depth + 1;
		$root          = isset( $caller['chain_root'] ) && '' !== (string) $caller['chain_root']
			? (string) $caller['chain_root']
			: self::generate_request_id();
		$host          = function_exists( 'home_url' ) ? (string) home_url() : '';
		$user_id       = isset( $caller['user_id'] ) ? max( 0, (int) $caller['user_id'] ) : 0;

		return array(
			self::HEADER_CALLER_AGENT => $agent,
			self::HEADER_CALLER_USER  => (string) $user_id,
			self::HEADER_CALLER_HOST  => $host,
			self::HEADER_CHAIN_DEPTH  => (string) $depth,
			self::HEADER_CHAIN_ROOT   => $root,
		);
	}

	private static function generate_request_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}
		return 'req-' . wp_generate_password( 24, false, false );
	}
}
