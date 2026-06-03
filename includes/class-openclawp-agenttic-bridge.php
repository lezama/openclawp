<?php
/**
 * JSON-RPC bridge for `@automattic/agenttic-client`.
 *
 * Translates the agenttic protocol (JSON-RPC 2.0 over HTTP, A2A-shaped Task
 * envelopes) into canonical `agents/chat` ability calls. The motivation is
 * code reuse: the agenttic React hooks (`useAgentChat`, `useAgentSession`,
 * etc.) are the right UI layer for openclaWP, and they speak this wire
 * format — so we expose the canonical chat dispatcher under that wire
 * format rather than reimplementing the React side against the openclaWP
 * REST shape.
 *
 * Wire shape, abridged (full schema in @automattic/agenttic-client v0.1.x):
 *
 *   POST /openclawp/v1/agenttic/<agent-slug>
 *   {
 *     "jsonrpc": "2.0",
 *     "id": "req-…",
 *     "method": "message/send",
 *     "params": {
 *       "id": "task-…",
 *       "sessionId": "…",            // optional
 *       "message": {
 *         "role": "user",
 *         "parts": [ { "type": "text", "text": "hello" }, … ],
 *         "messageId": "…",
 *         "kind": "message"
 *       }
 *     }
 *   }
 *
 *   Response (success):
 *   {
 *     "jsonrpc": "2.0",
 *     "id": "req-…",
 *     "result": {
 *       "id": "task-…",
 *       "sessionId": "…",
 *       "status": {
 *         "state": "completed",
 *         "message": {
 *           "role": "agent",
 *           "parts": [ { "type": "text", "text": "<reply>" } ],
 *           "messageId": "…",
 *           "kind": "message"
 *         },
 *         "timestamp": "<iso>"
 *       }
 *     }
 *   }
 *
 * v0 scope:
 *   - `message/send` (returns the Task in a JSON envelope).
 *   - `message/stream` (real SSE; one frame per loop event plus a final
 *     Task envelope). We subscribe to canonical's `agents_api_loop_event`
 *     action and openclaWP's `openclawp_chat_turn_completed` telemetry
 *     event for the duration of the synchronous `agents/chat` call, write
 *     each one as a `data: {…}\n\n` SSE frame, and close with the
 *     completed Task envelope. Progress frames carry `result.kind =
 *     "status-update"` so clients that only want the final result can
 *     filter by `result.kind === undefined` (the terminal frame has no
 *     `kind` — it's the same shape as the non-streaming `result`).
 *   - No `tasks/get`, no cancellation.
 *   - Single text part in / single text part out.
 *   - No artifacts. No tool-call surfacing in the response shape (tools
 *     execute inside the loop and only the final assistant text is returned;
 *     the React UI sees the same observability it gets today via
 *     `agents_api_loop_event` if the consumer wants to wire it in).
 *
 * @package OpenclaWP
 * @since 0.5.0
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Agenttic_Bridge {

	private const NAMESPACE = 'openclawp/v1';

	private const PARSE_ERROR      = -32700;
	private const INVALID_REQUEST  = -32600;
	private const METHOD_NOT_FOUND = -32601;
	private const INVALID_PARAMS   = -32602;
	private const INTERNAL_ERROR   = -32603;

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/agenttic/(?P<agent>[A-Za-z0-9_\-]+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
				'args'                => array(
					'agent' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_title',
					),
				),
			)
		);
	}

	public static function check_permission( WP_REST_Request $request ) {
		$default = current_user_can( 'manage_options' );

		/**
		 * Filters whether the current user may call the openclaWP agenttic
		 * bridge. Mirrors `openclawp_rest_permission_callback` so admins who
		 * already loosen the chat REST surface get the agenttic surface for
		 * free.
		 *
		 * @since 0.5.0
		 *
		 * @param bool            $allowed Default: manage_options.
		 * @param WP_REST_Request $request Current request.
		 */
		$allowed = (bool) apply_filters( 'openclawp_agenttic_bridge_permission', $default, $request );

		if ( ! $allowed ) {
			// JSON-RPC errors require a 200 envelope, but auth pre-empts the
			// JSON-RPC layer entirely — return a normal 401/403 here.
			return new WP_Error(
				'openclawp_forbidden',
				__( 'You do not have permission to use openclaWP.', 'openclawp' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		$agent_slug = (string) $request->get_param( 'agent' );

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return self::error_response( null, self::PARSE_ERROR, 'Invalid JSON-RPC envelope.' );
		}

		$rpc_id  = $body['id'] ?? null;
		$method  = isset( $body['method'] ) ? (string) $body['method'] : '';
		$params  = isset( $body['params'] ) && is_array( $body['params'] ) ? $body['params'] : array();
		$jsonrpc = isset( $body['jsonrpc'] ) ? (string) $body['jsonrpc'] : '';

		if ( '2.0' !== $jsonrpc ) {
			return self::error_response( $rpc_id, self::INVALID_REQUEST, 'jsonrpc must be "2.0".' );
		}

		$is_streaming = ( 'message/stream' === $method );

		if ( 'message/send' !== $method && ! $is_streaming ) {
			// v0 scope: `message/send` and `message/stream`. tasks/get and
			// tasks/cancel land when there's a concrete consumer.
			return self::error_response( $rpc_id, self::METHOD_NOT_FOUND, sprintf( 'Method "%s" is not supported. v0 supports message/send and message/stream.', $method ) );
		}

		$message = isset( $params['message'] ) && is_array( $params['message'] ) ? $params['message'] : array();
		$text    = self::extract_text( $message );
		if ( '' === $text ) {
			return self::error_response( $rpc_id, self::INVALID_PARAMS, 'message must contain at least one non-empty text part.' );
		}

		$session_id = isset( $params['sessionId'] ) && is_string( $params['sessionId'] ) ? $params['sessionId'] : null;
		$task_id    = isset( $params['id'] ) && is_string( $params['id'] ) ? $params['id'] : self::generate_task_id();

		// When the request carries agents-api caller-chain headers, this is an
		// agent-to-agent delegation. Parse them fail-closed (malformed headers
		// are a hard error, matching the substrate's request-edge contract) and
		// tag the turn so `agents/chat` records it as a peer-agent call (#180).
		$client_context = self::peer_client_context( $request );
		if ( is_wp_error( $client_context ) ) {
			return self::error_response( $rpc_id, self::INVALID_PARAMS, $client_context->get_error_message() );
		}

		if ( ! function_exists( 'wp_get_ability' ) ) {
			return self::error_response( $rpc_id, self::INTERNAL_ERROR, 'Abilities API is not loaded.' );
		}
		$chat = wp_get_ability( 'agents/chat' );
		if ( null === $chat ) {
			return self::error_response( $rpc_id, self::INTERNAL_ERROR, 'agents/chat ability is not registered.' );
		}

		// Streaming opens the SSE response BEFORE invoking the chat ability
		// so subscribed loop events can emit frames as the loop runs.
		$listeners_attached = false;
		if ( $is_streaming ) {
			self::start_sse_response();
			$listeners_attached = self::attach_streaming_listeners( $rpc_id, $task_id );
		}

		$execute_args = array(
			'agent'      => $agent_slug,
			'message'    => $text,
			'session_id' => $session_id,
		);
		if ( ! empty( $client_context ) ) {
			$execute_args['client_context'] = $client_context;
		}

		try {
			$result = $chat->execute( $execute_args );
		} finally {
			if ( $listeners_attached ) {
				self::detach_streaming_listeners();
			}
		}

		if ( is_wp_error( $result ) ) {
			if ( $is_streaming ) {
				self::emit_sse_frame(
					array(
						'jsonrpc' => '2.0',
						'id'      => $rpc_id,
						'error'   => array(
							'code'    => self::INTERNAL_ERROR,
							'message' => $result->get_error_message(),
							'data'    => array( 'code' => $result->get_error_code() ),
						),
					)
				);
				exit;
			}
			return self::error_response( $rpc_id, self::INTERNAL_ERROR, $result->get_error_message(), array( 'code' => $result->get_error_code() ) );
		}

		$reply       = isset( $result['reply'] ) ? (string) $result['reply'] : '';
		$session_out = isset( $result['session_id'] ) ? (string) $result['session_id'] : (string) $session_id;

		$task = array(
			'id'        => $task_id,
			'sessionId' => $session_out,
			'status'    => array(
				'state'     => 'completed',
				'message'   => array(
					'role'      => 'agent',
					'parts'     => array(
						array(
							'type' => 'text',
							'text' => $reply,
						),
					),
					'messageId' => self::generate_message_id(),
					'kind'      => 'message',
				),
				'timestamp' => gmdate( 'c' ),
			),
		);

		if ( $is_streaming ) {
			self::emit_sse_frame(
				array(
					'jsonrpc' => '2.0',
					'id'      => $rpc_id,
					'result'  => $task,
				)
			);
			exit;
		}

		return self::success_response( $rpc_id, $task );
	}

	/**
	 * Closures registered against loop events for the duration of one SSE
	 * response. Held so we can detach cleanly in the `finally` block,
	 * including when the chat ability throws.
	 *
	 * @var array<int, array{hook:string, callback:callable, priority:int}>
	 */
	private static array $streaming_listeners = array();

	private static function attach_streaming_listeners( $rpc_id, string $task_id ): bool {
		$loop_listener = static function ( string $event, array $payload = array() ) use ( $rpc_id, $task_id ): void {
			self::emit_status_update_frame( $rpc_id, $task_id, 'loop:' . $event, $payload );
		};
		$telemetry_listener = static function ( array $telemetry ) use ( $rpc_id, $task_id ): void {
			self::emit_status_update_frame( $rpc_id, $task_id, 'chat_turn_completed', $telemetry );
		};

		add_action( 'agents_api_loop_event', $loop_listener, 10, 2 );
		add_action( 'openclawp_chat_turn_completed', $telemetry_listener, 10, 1 );

		self::$streaming_listeners = array(
			array( 'hook' => 'agents_api_loop_event',          'callback' => $loop_listener,      'priority' => 10 ),
			array( 'hook' => 'openclawp_chat_turn_completed',  'callback' => $telemetry_listener, 'priority' => 10 ),
		);

		return true;
	}

	private static function detach_streaming_listeners(): void {
		foreach ( self::$streaming_listeners as $listener ) {
			remove_action( $listener['hook'], $listener['callback'], $listener['priority'] );
		}
		self::$streaming_listeners = array();
	}

	/**
	 * Emit a progress frame mid-loop. Clients distinguish progress from the
	 * terminal frame by checking `result.kind === 'status-update'` — the
	 * terminal frame is a complete Task with `status.state === 'completed'`
	 * and no `kind`.
	 */
	private static function emit_status_update_frame( $rpc_id, string $task_id, string $event, array $payload ): void {
		self::emit_sse_frame(
			array(
				'jsonrpc' => '2.0',
				'id'      => $rpc_id,
				'result'  => array(
					'kind'      => 'status-update',
					'taskId'    => $task_id,
					'event'     => $event,
					'payload'   => $payload,
					'timestamp' => gmdate( 'c' ),
				),
			)
		);
	}

	/**
	 * Open the SSE response. Sends headers, disables PHP output buffering
	 * and nginx response buffering, and idempotently no-ops if it's
	 * already been called for this request.
	 */
	private static function start_sse_response(): void {
		static $started = false;
		if ( $started ) {
			return;
		}
		$started = true;

		nocache_headers();
		header( 'Content-Type: text/event-stream; charset=utf-8' );
		header( 'Cache-Control: no-cache, no-store' );
		header( 'X-Accel-Buffering: no' ); // Disables nginx response buffering for live streams.

		// Disable PHP output buffering for the rest of the request so each
		// frame reaches the client at flush() time, not request shutdown.
		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}
	}

	/**
	 * Write one SSE frame and flush. Safe to call repeatedly during a
	 * single response — every call after the first reuses the open stream.
	 *
	 * @param array<string,mixed> $payload JSON-RPC envelope to emit.
	 */
	private static function emit_sse_frame( array $payload ): void {
		self::start_sse_response();
		echo 'data: ' . wp_json_encode( $payload ) . "\n\n";
		flush();
	}

	/**
	 * Pull the first non-empty `text` part out of an A2A Message.
	 */
	private static function extract_text( array $message ): string {
		$parts = isset( $message['parts'] ) && is_array( $message['parts'] ) ? $message['parts'] : array();
		foreach ( $parts as $part ) {
			if ( ! is_array( $part ) ) {
				continue;
			}
			if ( 'text' !== ( $part['type'] ?? '' ) ) {
				continue;
			}
			$text = isset( $part['text'] ) ? trim( (string) $part['text'] ) : '';
			if ( '' !== $text ) {
				return $text;
			}
		}
		return '';
	}

	/**
	 * Build a JSON-RPC success envelope. Always HTTP 200 — JSON-RPC carries
	 * its own status semantics.
	 */
	private static function success_response( $rpc_id, array $result ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $rpc_id,
				'result'  => $result,
			),
			200
		);
	}

	/**
	 * Build a JSON-RPC error envelope. Always HTTP 200; the error rides
	 * inside the `error` field per JSON-RPC 2.0.
	 */
	private static function error_response( $rpc_id, int $code, string $message, array $data = array() ): WP_REST_Response {
		$err = array(
			'code'    => $code,
			'message' => $message,
		);
		if ( ! empty( $data ) ) {
			$err['data'] = $data;
		}
		return new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $rpc_id,
				'error'   => $err,
			),
			200
		);
	}

	/**
	 * Build the `client_context` for an inbound A2A turn.
	 *
	 * Returns an empty array for a normal (non-peer) call so the chat ability
	 * sees no extra context. When agents-api caller-chain headers are present
	 * and describe a remote caller, returns a `peer-agent` client context
	 * carrying the caller agent slug and marking the turn as an explicit
	 * agent-to-agent delegation (#180). Malformed headers fail closed with a
	 * WP_Error, matching the substrate's request-edge contract (#81).
	 *
	 * @param WP_REST_Request $request Inbound request.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	private static function peer_client_context( WP_REST_Request $request ) {
		if ( ! class_exists( 'WP_Agent_Caller_Context' ) ) {
			return array();
		}

		try {
			$context = WP_Agent_Caller_Context::from_headers( $request );
		} catch ( \Throwable ) {
			return new WP_Error( 'openclawp_invalid_caller_context', 'Invalid agent caller-context headers.' );
		}

		if ( ! $context->is_cross_site() ) {
			return array();
		}

		return array(
			'source'            => 'peer-agent',
			'caller_agent'      => $context->caller_agent_id,
			// The caller's own session id isn't carried in caller-chain headers.
			'caller_session_id' => null,
			'peer_agent_call'   => true,
		);
	}

	private static function generate_task_id(): string {
		return 'task-' . wp_generate_password( 12, false, false );
	}

	private static function generate_message_id(): string {
		return wp_generate_password( 12, false, false );
	}
}
