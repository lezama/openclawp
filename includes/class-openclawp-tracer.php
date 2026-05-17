<?php
/**
 * OpenTelemetry GenAI tracer.
 *
 * Buffers in-memory spans for the lifetime of a single request, then batch-POSTs
 * them as an OTLP/HTTP JSON payload to a configurable collector endpoint on
 * shutdown. No PHP OTel SDK dependency — the payload shape is just an
 * `ExportTraceServiceRequest` JSON document hand-rolled per the spec, and
 * `wp_remote_post()` is the wire.
 *
 * Default off. Opt in via either:
 *
 *   - env var `OPENCLAWP_OTEL_ENDPOINT` (full `/v1/traces` URL), or
 *   - WP option `openclawp_otel_endpoint`, or
 *   - the `openclawp_otel_endpoint` filter.
 *
 * Auth headers go through `OPENCLAWP_OTEL_AUTH_HEADER` / option /
 * `openclawp_otel_auth_header` (single `Header: value` string, e.g.
 * `Authorization: Bearer ...`).
 *
 * Attribute conventions
 * ---------------------
 *
 * Spans emit OpenTelemetry GenAI semantic-convention attributes verbatim
 * (`gen_ai.system`, `gen_ai.request.model`, `gen_ai.usage.input_tokens`,
 * `gen_ai.usage.output_tokens`, `gen_ai.response.finish_reasons`,
 * `gen_ai.operation.name`, `gen_ai.tool.name`). Site-specific identifiers go
 * under the `openclawp.*` namespace (`openclawp.session.id`,
 * `openclawp.agent.slug`, `openclawp.user.id`, `openclawp.channel`).
 *
 * Hook surface
 * ------------
 *
 * - `openclawp_chat_turn_completed` — root span per provider call (turn).
 * - `agents_api_loop_event` (`tool_call` / `tool_result`) — child span per
 *   tool execution, parented by the most recent turn root.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Tracer {

	/** GenAI semconv operation names. */
	private const OP_CHAT = 'chat';
	private const OP_EXECUTE_TOOL = 'execute_tool';

	/** OTLP span kind codes: 1=INTERNAL, 2=SERVER, 3=CLIENT. */
	private const SPAN_KIND_CLIENT   = 3;
	private const SPAN_KIND_INTERNAL = 1;

	/** OTLP status codes: 0=UNSET, 1=OK, 2=ERROR. */
	private const STATUS_OK    = 1;
	private const STATUS_ERROR = 2;

	/**
	 * Buffered spans pending flush. Each entry is a fully-formed OTLP span
	 * record (post-serialization), so flush is a single array merge.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private static array $spans = array();

	/**
	 * Per-request shared trace id (32 hex chars). All spans emitted within
	 * one PHP request share a trace id so a single turn + its tool calls
	 * appear as one trace in the backend.
	 */
	private static string $trace_id = '';

	/**
	 * Most recently opened turn span id (16 hex chars). Tool spans parent
	 * themselves to this; cleared when the turn ends.
	 */
	private static string $current_turn_span_id = '';

	/**
	 * Open tool spans keyed by `turn:tool_name` so `tool_result` can find
	 * its matching `tool_call` and close it with timing + status.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private static array $open_tool_spans = array();

	/** Whether we already registered the shutdown flush. */
	private static bool $shutdown_registered = false;

	/** Per-request overhead measurement (microseconds), summed across hooks. */
	private static int $overhead_us = 0;

	/**
	 * Inject runtime context attributes onto every span emitted during this
	 * request. Populated by the runner before a turn starts so we don't have
	 * to thread the values through every action callback.
	 *
	 * @var array<string,mixed>
	 */
	private static array $runtime_attributes = array();

	public static function register(): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		add_action( 'openclawp_chat_turn_completed', array( __CLASS__, 'on_turn_completed' ), 15, 1 );
		add_action( 'agents_api_loop_event', array( __CLASS__, 'on_loop_event' ), 15, 2 );
	}

	/**
	 * Set per-request context that should be stamped onto every span.
	 *
	 * Called by the runner before invoking the conversation loop. Values are
	 * cleared on shutdown after the flush.
	 *
	 * @param array<string,mixed> $attributes openclawp.* attribute pairs.
	 */
	public static function set_runtime_context( array $attributes ): void {
		self::$runtime_attributes = $attributes;
	}

	/**
	 * Resolve the OTLP endpoint from env/option/filter chain.
	 *
	 * @return string Empty when tracing is disabled.
	 */
	public static function endpoint(): string {
		$env = (string) getenv( 'OPENCLAWP_OTEL_ENDPOINT' );
		if ( '' === $env && function_exists( 'get_option' ) ) {
			$env = (string) get_option( 'openclawp_otel_endpoint', '' );
		}

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters the OTLP/HTTP JSON traces endpoint.
			 *
			 * @param string $endpoint Full URL ending in `/v1/traces` (or whatever
			 *                         path the collector exposes). Empty disables.
			 */
			$env = (string) apply_filters( 'openclawp_otel_endpoint', $env );
		}

		return trim( $env );
	}

	/**
	 * Resolve the optional auth header line ("Header: value").
	 */
	public static function auth_header(): string {
		$value = (string) getenv( 'OPENCLAWP_OTEL_AUTH_HEADER' );
		if ( '' === $value && function_exists( 'get_option' ) ) {
			$value = (string) get_option( 'openclawp_otel_auth_header', '' );
		}
		if ( function_exists( 'apply_filters' ) ) {
			$value = (string) apply_filters( 'openclawp_otel_auth_header', $value );
		}
		return trim( $value );
	}

	/**
	 * Tracer is enabled when a non-empty endpoint is configured. We
	 * intentionally do not parse-check the URL here — `wp_remote_post()`
	 * will surface that on flush.
	 */
	public static function is_enabled(): bool {
		return '' !== self::endpoint();
	}

	/**
	 * Turn completion subscriber — emits the root chat span.
	 *
	 * @param array<string,mixed> $telemetry Telemetry snapshot from the runner.
	 */
	public static function on_turn_completed( array $telemetry ): void {
		$start = self::hrtime_us();

		$now_ns       = self::current_time_unix_nano();
		$duration_ms  = (int) ( $telemetry['duration_ms'] ?? 0 );
		$duration_ns  = max( 0, $duration_ms ) * 1_000_000;
		$start_ns     = $duration_ns > 0 ? $now_ns - $duration_ns : $now_ns;

		$trace_id = self::ensure_trace_id();
		$span_id  = self::new_span_id();
		self::$current_turn_span_id = $span_id;

		$success      = (bool) ( $telemetry['success'] ?? false );
		$token_usage  = isset( $telemetry['token_usage'] ) && is_array( $telemetry['token_usage'] ) ? $telemetry['token_usage'] : array();
		$finish       = $success ? 'stop' : ( isset( $telemetry['error'] ) && null !== $telemetry['error'] ? 'error' : 'stop' );

		$attributes = self::merge_runtime_attributes(
			array(
				'gen_ai.system'                  => (string) ( $telemetry['provider'] ?? '' ),
				'gen_ai.operation.name'          => self::OP_CHAT,
				'gen_ai.request.model'           => (string) ( $telemetry['model'] ?? '' ),
				'gen_ai.response.finish_reasons' => array( $finish ),
				'gen_ai.usage.input_tokens'      => (int) ( $token_usage['input'] ?? 0 ),
				'gen_ai.usage.output_tokens'    => (int) ( $token_usage['output'] ?? 0 ),
				'openclawp.session.id'           => (string) ( $telemetry['session_id'] ?? '' ),
				'openclawp.agent.slug'           => (string) ( $telemetry['agent_slug'] ?? '' ),
				'openclawp.tool_call_count'      => (int) ( $telemetry['tool_call_count'] ?? 0 ),
			)
		);

		$error_message = $success ? null : ( isset( $telemetry['error'] ) ? (string) $telemetry['error'] : null );

		self::buffer_span(
			array(
				'trace_id'      => $trace_id,
				'span_id'       => $span_id,
				'parent_id'     => '',
				'name'          => 'chat ' . (string) ( $telemetry['model'] ?? 'unknown' ),
				'kind'          => self::SPAN_KIND_CLIENT,
				'start_unix_ns' => (string) $start_ns,
				'end_unix_ns'   => (string) $now_ns,
				'attributes'    => $attributes,
				'status_code'   => $success ? self::STATUS_OK : self::STATUS_ERROR,
				'status_msg'    => $error_message,
			)
		);

		self::register_shutdown_flush();

		self::$overhead_us += self::hrtime_us() - $start;
	}

	/**
	 * Loop event subscriber — converts `tool_call` / `tool_result` pairs into
	 * a single child span parented by the active turn span.
	 *
	 * @param string              $event   Event name.
	 * @param array<string,mixed> $payload Event payload.
	 */
	public static function on_loop_event( string $event, array $payload = array() ): void {
		if ( 'tool_call' !== $event && 'tool_result' !== $event ) {
			return;
		}

		$start = self::hrtime_us();

		$tool_name = (string) ( $payload['tool_name'] ?? '' );
		$turn      = (int) ( $payload['turn'] ?? 0 );
		if ( '' === $tool_name ) {
			return;
		}

		$key = $turn . ':' . $tool_name;

		if ( 'tool_call' === $event ) {
			self::$open_tool_spans[ $key ] = array(
				'span_id'       => self::new_span_id(),
				'start_unix_ns' => (string) self::current_time_unix_nano(),
				'tool_name'     => $tool_name,
				'turn'          => $turn,
				'parameters'    => isset( $payload['parameters'] ) && is_array( $payload['parameters'] ) ? $payload['parameters'] : array(),
			);
			self::$overhead_us += self::hrtime_us() - $start;
			return;
		}

		// tool_result.
		$open = self::$open_tool_spans[ $key ] ?? null;
		if ( null === $open ) {
			// Out-of-order event (no matching call) — skip silently.
			self::$overhead_us += self::hrtime_us() - $start;
			return;
		}
		unset( self::$open_tool_spans[ $key ] );

		$success = (bool) ( $payload['success'] ?? false );

		$attributes = self::merge_runtime_attributes(
			array(
				'gen_ai.operation.name' => self::OP_EXECUTE_TOOL,
				'gen_ai.tool.name'      => $tool_name,
				'openclawp.turn'        => $turn,
			)
		);

		self::buffer_span(
			array(
				'trace_id'      => self::ensure_trace_id(),
				'span_id'       => $open['span_id'],
				'parent_id'     => self::$current_turn_span_id,
				'name'          => 'execute_tool ' . $tool_name,
				'kind'          => self::SPAN_KIND_INTERNAL,
				'start_unix_ns' => $open['start_unix_ns'],
				'end_unix_ns'   => (string) self::current_time_unix_nano(),
				'attributes'    => $attributes,
				'status_code'   => $success ? self::STATUS_OK : self::STATUS_ERROR,
				'status_msg'    => $success ? null : 'tool_failed',
			)
		);

		self::$overhead_us += self::hrtime_us() - $start;
	}

	/**
	 * Flush every buffered span to the configured collector as a single
	 * OTLP/HTTP JSON `ExportTraceServiceRequest` payload.
	 *
	 * @return array{sent:int,response_code:int,error:?string}
	 */
	public static function flush(): array {
		$endpoint = self::endpoint();
		if ( '' === $endpoint || empty( self::$spans ) ) {
			return array( 'sent' => 0, 'response_code' => 0, 'error' => null );
		}

		$payload = self::build_otlp_payload( self::$spans );
		$body    = wp_json_encode( $payload );
		if ( ! is_string( $body ) ) {
			return array( 'sent' => 0, 'response_code' => 0, 'error' => 'json_encode_failed' );
		}

		$headers = array( 'Content-Type' => 'application/json' );
		$auth    = self::auth_header();
		if ( '' !== $auth ) {
			$parts = explode( ':', $auth, 2 );
			if ( 2 === count( $parts ) ) {
				$headers[ trim( $parts[0] ) ] = trim( $parts[1] );
			}
		}

		if ( ! function_exists( 'wp_remote_post' ) ) {
			return array( 'sent' => 0, 'response_code' => 0, 'error' => 'wp_remote_post_unavailable' );
		}

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers'  => $headers,
				'body'     => $body,
				'timeout'  => 2,
				'blocking' => false,
			)
		);

		$sent = count( self::$spans );
		self::$spans = array();

		if ( is_wp_error( $response ) ) {
			return array( 'sent' => $sent, 'response_code' => 0, 'error' => $response->get_error_code() );
		}

		$code = isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
		return array( 'sent' => $sent, 'response_code' => $code, 'error' => null );
	}

	/**
	 * Build the OTLP/HTTP JSON `ExportTraceServiceRequest` envelope. Public
	 * for test introspection — production code calls flush().
	 *
	 * @param array<int,array<string,mixed>> $spans Buffered spans.
	 * @return array<string,mixed>
	 */
	public static function build_otlp_payload( array $spans ): array {
		$serialized = array();
		foreach ( $spans as $span ) {
			$serialized[] = self::serialize_span( $span );
		}

		return array(
			'resourceSpans' => array(
				array(
					'resource'   => array(
						'attributes' => self::kv_attributes(
							array(
								'service.name'    => 'openclawp',
								'service.version' => defined( 'OPENCLAWP_VERSION' ) ? OPENCLAWP_VERSION : 'unknown',
							)
						),
					),
					'scopeSpans' => array(
						array(
							'scope' => array(
								'name'    => 'openclawp.tracer',
								'version' => defined( 'OPENCLAWP_VERSION' ) ? OPENCLAWP_VERSION : 'unknown',
							),
							'spans' => $serialized,
						),
					),
				),
			),
		);
	}

	/**
	 * Buffer-level introspection used by tests. Returns the raw, un-serialized
	 * span records held in memory.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function buffered_spans(): array {
		return self::$spans;
	}

	/**
	 * Reset all in-memory state. Used between tests; never called in prod.
	 */
	public static function reset(): void {
		self::$spans                = array();
		self::$trace_id             = '';
		self::$current_turn_span_id = '';
		self::$open_tool_spans      = array();
		self::$runtime_attributes   = array();
		self::$overhead_us          = 0;
	}

	/**
	 * Per-request overhead measurement in microseconds (test helper).
	 */
	public static function overhead_us(): int {
		return self::$overhead_us;
	}

	// ---------------------------------------------------------------------
	// Internals.
	// ---------------------------------------------------------------------

	private static function buffer_span( array $span ): void {
		self::$spans[] = $span;
	}

	private static function register_shutdown_flush(): void {
		if ( self::$shutdown_registered ) {
			return;
		}
		self::$shutdown_registered = true;
		if ( function_exists( 'add_action' ) ) {
			add_action( 'shutdown', array( __CLASS__, 'flush' ), 100 );
		}
	}

	private static function ensure_trace_id(): string {
		if ( '' === self::$trace_id ) {
			self::$trace_id = bin2hex( random_bytes( 16 ) );
		}
		return self::$trace_id;
	}

	private static function new_span_id(): string {
		return bin2hex( random_bytes( 8 ) );
	}

	private static function current_time_unix_nano(): int {
		// hrtime is monotonic; combine with microtime for wall-clock anchor.
		return (int) ( microtime( true ) * 1_000_000_000 );
	}

	private static function hrtime_us(): int {
		// hrtime(true) returns nanoseconds as int — convert to microseconds.
		return (int) ( hrtime( true ) / 1000 );
	}

	private static function merge_runtime_attributes( array $base ): array {
		foreach ( self::$runtime_attributes as $k => $v ) {
			if ( ! isset( $base[ $k ] ) ) {
				$base[ $k ] = $v;
			}
		}
		return $base;
	}

	/**
	 * Serialize one buffered span record into the OTLP span shape.
	 *
	 * @param array<string,mixed> $span Buffered span.
	 * @return array<string,mixed>
	 */
	private static function serialize_span( array $span ): array {
		$out = array(
			'traceId'           => (string) $span['trace_id'],
			'spanId'            => (string) $span['span_id'],
			'name'              => (string) $span['name'],
			'kind'              => (int) $span['kind'],
			'startTimeUnixNano' => (string) $span['start_unix_ns'],
			'endTimeUnixNano'   => (string) $span['end_unix_ns'],
			'attributes'        => self::kv_attributes( (array) $span['attributes'] ),
			'status'            => array( 'code' => (int) $span['status_code'] ),
		);

		if ( '' !== (string) $span['parent_id'] ) {
			$out['parentSpanId'] = (string) $span['parent_id'];
		}

		if ( ! empty( $span['status_msg'] ) ) {
			$out['status']['message'] = (string) $span['status_msg'];
		}

		return $out;
	}

	/**
	 * Convert a flat associative array into OTLP KeyValue pairs.
	 *
	 * @param array<string,mixed> $attrs Attribute map.
	 * @return array<int,array<string,mixed>>
	 */
	private static function kv_attributes( array $attrs ): array {
		$out = array();
		foreach ( $attrs as $key => $value ) {
			$out[] = array(
				'key'   => (string) $key,
				'value' => self::any_value( $value ),
			);
		}
		return $out;
	}

	/**
	 * OTLP AnyValue encoder. Handles strings, ints, bools, doubles, and
	 * string arrays (used by `gen_ai.response.finish_reasons`).
	 *
	 * @param mixed $value
	 * @return array<string,mixed>
	 */
	private static function any_value( $value ): array {
		if ( is_bool( $value ) ) {
			return array( 'boolValue' => $value );
		}
		if ( is_int( $value ) ) {
			return array( 'intValue' => (string) $value );
		}
		if ( is_float( $value ) ) {
			return array( 'doubleValue' => $value );
		}
		if ( is_array( $value ) ) {
			$values = array();
			foreach ( $value as $item ) {
				$values[] = self::any_value( $item );
			}
			return array( 'arrayValue' => array( 'values' => $values ) );
		}
		return array( 'stringValue' => (string) $value );
	}
}
