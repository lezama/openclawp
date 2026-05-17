<?php
/**
 * Thin transport layer for talking to an external MCP server.
 *
 * Implements just enough of the MCP 2025-06-18 JSON-RPC protocol for
 * openclaWP's client mode:
 *
 *   - `initialize`
 *   - `tools/list`
 *   - `tools/call`
 *
 * Two transports are supported:
 *
 *   - **stdio** — launches the configured command via `proc_open`, frames
 *     newline-delimited JSON-RPC on stdin/stdout. This is the common case
 *     for bundled servers like `@modelcontextprotocol/server-fetch`.
 *   - **http** — POSTs JSON-RPC to the server URL with optional headers.
 *     Targets MCP's Streamable HTTP transport; sufficient for read-only
 *     servers that don't depend on server-pushed messages.
 *
 * openclaWP does not attempt to parse or validate the full MCP spec. We
 * stick to the request/response subset above; servers that need richer
 * features (sampling, roots, prompts/resources) should be wrapped through
 * the canonical agents-api MCP integration instead.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Mcp_Client_Transport {

	private const PROTOCOL_VERSION     = '2025-06-18';
	private const READ_TIMEOUT_SECONDS = 15;

	/**
	 * Probe a configured server: initialize + tools/list. Returns the tool
	 * list on success, a WP_Error on failure (timeout, non-zero exit,
	 * malformed JSON-RPC, etc.).
	 *
	 * @param array<string,mixed> $config Output of {@see OpenclaWP_Mcp_Client_Store::config()}.
	 *
	 * @return array<int,array{name:string,description:string,inputSchema:array}>|\WP_Error
	 */
	public static function discover_tools( array $config ) {
		$transport = (string) ( $config['transport'] ?? OpenclaWP_Mcp_Client_Store::TRANSPORT_STDIO );
		if ( OpenclaWP_Mcp_Client_Store::TRANSPORT_HTTP === $transport ) {
			return self::discover_http( $config );
		}
		return self::discover_stdio( $config );
	}

	/**
	 * Invoke a tool on a configured server.
	 *
	 * @param array<string,mixed> $config    Output of {@see OpenclaWP_Mcp_Client_Store::config()}.
	 * @param string              $tool_name Server-native tool name (pre-sanitization).
	 * @param array<string,mixed> $arguments Tool-call arguments.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function call_tool( array $config, string $tool_name, array $arguments ) {
		$transport = (string) ( $config['transport'] ?? OpenclaWP_Mcp_Client_Store::TRANSPORT_STDIO );
		if ( OpenclaWP_Mcp_Client_Store::TRANSPORT_HTTP === $transport ) {
			return self::call_http( $config, $tool_name, $arguments );
		}
		return self::call_stdio( $config, $tool_name, $arguments );
	}

	// -------------------------------------------------------------------
	// stdio
	// -------------------------------------------------------------------

	/**
	 * @param array<string,mixed> $config
	 * @return array<int,array{name:string,description:string,inputSchema:array}>|\WP_Error
	 */
	private static function discover_stdio( array $config ) {
		$session = self::open_stdio_session( $config );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		try {
			$init = self::send_stdio(
				$session,
				'initialize',
				array(
					'protocolVersion' => self::PROTOCOL_VERSION,
					'capabilities'    => new \stdClass(),
					'clientInfo'      => array(
						'name'    => 'openclawp',
						'version' => defined( 'OPENCLAWP_VERSION' ) ? OPENCLAWP_VERSION : '0.0.0',
					),
				)
			);
			if ( is_wp_error( $init ) ) {
				return $init;
			}

			// MCP requires a notifications/initialized after initialize.
			self::notify_stdio( $session, 'notifications/initialized', new \stdClass() );

			$list = self::send_stdio( $session, 'tools/list', new \stdClass() );
			if ( is_wp_error( $list ) ) {
				return $list;
			}

			return self::normalize_tools_list( $list );
		} finally {
			self::close_stdio_session( $session );
		}
	}

	/**
	 * @param array<string,mixed> $config
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function call_stdio( array $config, string $tool_name, array $arguments ) {
		$session = self::open_stdio_session( $config );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		try {
			$init = self::send_stdio(
				$session,
				'initialize',
				array(
					'protocolVersion' => self::PROTOCOL_VERSION,
					'capabilities'    => new \stdClass(),
					'clientInfo'      => array(
						'name'    => 'openclawp',
						'version' => defined( 'OPENCLAWP_VERSION' ) ? OPENCLAWP_VERSION : '0.0.0',
					),
				)
			);
			if ( is_wp_error( $init ) ) {
				return $init;
			}
			self::notify_stdio( $session, 'notifications/initialized', new \stdClass() );

			$result = self::send_stdio(
				$session,
				'tools/call',
				array(
					'name'      => $tool_name,
					'arguments' => empty( $arguments ) ? new \stdClass() : $arguments,
				)
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return is_array( $result ) ? $result : array( 'result' => $result );
		} finally {
			self::close_stdio_session( $session );
		}
	}

	/**
	 * @param array<string,mixed> $config
	 * @return array{process:resource, pipes:array<int,resource>, id:int}|\WP_Error
	 */
	private static function open_stdio_session( array $config ) {
		$command = (string) ( $config['command'] ?? '' );
		if ( '' === $command ) {
			return new \WP_Error( 'mcp_client_no_command', 'stdio transport requires a command' );
		}
		if ( ! function_exists( 'proc_open' ) ) {
			return new \WP_Error( 'mcp_client_no_proc_open', 'proc_open is disabled on this host' );
		}

		$args  = is_array( $config['args'] ?? null ) ? $config['args'] : array();
		$parts = array_merge( array( $command ), array_map( 'strval', $args ) );

		$descriptor_spec = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		$env = is_array( $config['env'] ?? null ) ? $config['env'] : array();
		// Inherit PATH so the user's `node` / `python` are resolvable.
		if ( ! isset( $env['PATH'] ) && isset( $_SERVER['PATH'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
			$env['PATH'] = (string) $_SERVER['PATH']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		}

		$process = @proc_open( $parts, $descriptor_spec, $pipes, null, $env ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_resource( $process ) ) {
			return new \WP_Error( 'mcp_client_spawn_failed', sprintf( 'could not spawn `%s`', $command ) );
		}

		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );

		return array(
			'process' => $process,
			'pipes'   => $pipes,
			'id'      => 0,
		);
	}

	/**
	 * @param array{process:resource, pipes:array<int,resource>, id:int} $session
	 */
	private static function close_stdio_session( array $session ): void {
		foreach ( $session['pipes'] as $pipe ) {
			if ( is_resource( $pipe ) ) {
				@fclose( $pipe ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
		if ( is_resource( $session['process'] ) ) {
			@proc_terminate( $session['process'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@proc_close( $session['process'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * Send a JSON-RPC request frame and block for the matching response.
	 *
	 * @param array{process:resource, pipes:array<int,resource>, id:int} $session
	 *
	 * @return mixed|\WP_Error
	 */
	private static function send_stdio( array &$session, string $method, $params ) {
		++$session['id'];
		$request = array(
			'jsonrpc' => '2.0',
			'id'      => $session['id'],
			'method'  => $method,
			'params'  => $params,
		);

		$payload = wp_json_encode( $request ) . "\n";
		$written = @fwrite( $session['pipes'][0], $payload ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $written ) {
			return new \WP_Error( 'mcp_client_write_failed', sprintf( 'write to stdin failed for %s', $method ) );
		}

		$deadline = microtime( true ) + self::READ_TIMEOUT_SECONDS;
		$buffer   = '';

		while ( microtime( true ) < $deadline ) {
			$chunk = @fgets( $session['pipes'][1] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $chunk || '' === $chunk ) {
				// Check if process died.
				$status = proc_get_status( $session['process'] );
				if ( ! $status['running'] ) {
					$stderr = @stream_get_contents( $session['pipes'][2] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					return new \WP_Error(
						'mcp_client_process_exited',
						sprintf( 'process exited (code %d) before %s response: %s', (int) $status['exitcode'], $method, is_string( $stderr ) ? trim( $stderr ) : '' )
					);
				}
				usleep( 50000 ); // 50ms
				continue;
			}

			$buffer .= $chunk;
			if ( "\n" !== substr( $chunk, -1 ) ) {
				continue;
			}

			$line   = trim( $buffer );
			$buffer = '';
			if ( '' === $line ) {
				continue;
			}

			$decoded = json_decode( $line, true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}
			// Ignore notifications and unrelated requests; wait for our id.
			if ( ! isset( $decoded['id'] ) || (int) $decoded['id'] !== $session['id'] ) {
				continue;
			}

			if ( isset( $decoded['error'] ) ) {
				$message = isset( $decoded['error']['message'] ) ? (string) $decoded['error']['message'] : 'unknown error';
				return new \WP_Error( 'mcp_client_rpc_error', sprintf( '%s: %s', $method, $message ) );
			}

			return $decoded['result'] ?? array();
		}

		return new \WP_Error(
			'mcp_client_timeout',
			sprintf( 'timed out waiting for %s response after %ds', $method, self::READ_TIMEOUT_SECONDS )
		);
	}

	/**
	 * Fire-and-forget JSON-RPC notification (no id, no response expected).
	 *
	 * @param array{process:resource, pipes:array<int,resource>, id:int} $session
	 */
	private static function notify_stdio( array $session, string $method, $params ): void {
		$payload = wp_json_encode(
			array(
				'jsonrpc' => '2.0',
				'method'  => $method,
				'params'  => $params,
			)
		) . "\n";
		@fwrite( $session['pipes'][0], $payload ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	// -------------------------------------------------------------------
	// http
	// -------------------------------------------------------------------

	/**
	 * @param array<string,mixed> $config
	 * @return array<int,array{name:string,description:string,inputSchema:array}>|\WP_Error
	 */
	private static function discover_http( array $config ) {
		$init = self::http_rpc(
			$config,
			1,
			'initialize',
			array(
				'protocolVersion' => self::PROTOCOL_VERSION,
				'capabilities'    => new \stdClass(),
				'clientInfo'      => array(
					'name'    => 'openclawp',
					'version' => defined( 'OPENCLAWP_VERSION' ) ? OPENCLAWP_VERSION : '0.0.0',
				),
			)
		);
		if ( is_wp_error( $init ) ) {
			return $init;
		}

		$list = self::http_rpc( $config, 2, 'tools/list', new \stdClass() );
		if ( is_wp_error( $list ) ) {
			return $list;
		}
		return self::normalize_tools_list( $list );
	}

	/**
	 * @param array<string,mixed> $config
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function call_http( array $config, string $tool_name, array $arguments ) {
		return self::http_rpc(
			$config,
			3,
			'tools/call',
			array(
				'name'      => $tool_name,
				'arguments' => empty( $arguments ) ? new \stdClass() : $arguments,
			)
		);
	}

	/**
	 * @param array<string,mixed> $config
	 * @return mixed|\WP_Error
	 */
	private static function http_rpc( array $config, int $id, string $method, $params ) {
		$url = (string) ( $config['url'] ?? '' );
		if ( '' === $url ) {
			return new \WP_Error( 'mcp_client_no_url', 'http transport requires a url' );
		}

		$headers = is_array( $config['headers'] ?? null ) ? $config['headers'] : array();
		$headers = array_merge(
			array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json, text/event-stream',
			),
			$headers
		);

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => self::READ_TIMEOUT_SECONDS,
				'headers' => $headers,
				'body'    => wp_json_encode(
					array(
						'jsonrpc' => '2.0',
						'id'      => $id,
						'method'  => $method,
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
			return new \WP_Error(
				'mcp_client_http_status',
				sprintf( '%s returned HTTP %d: %s', $method, $code, substr( $body, 0, 200 ) )
			);
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'mcp_client_bad_json', sprintf( '%s returned non-JSON body', $method ) );
		}

		if ( isset( $decoded['error'] ) ) {
			$message = isset( $decoded['error']['message'] ) ? (string) $decoded['error']['message'] : 'unknown error';
			return new \WP_Error( 'mcp_client_rpc_error', sprintf( '%s: %s', $method, $message ) );
		}

		return $decoded['result'] ?? array();
	}

	// -------------------------------------------------------------------
	// shared
	// -------------------------------------------------------------------

	/**
	 * Reshape a `tools/list` response into openclaWP's stored shape.
	 *
	 * @param mixed $result
	 * @return array<int,array{name:string,description:string,inputSchema:array}>
	 */
	public static function normalize_tools_list( $result ): array {
		if ( ! is_array( $result ) ) {
			return array();
		}
		$raw = isset( $result['tools'] ) && is_array( $result['tools'] ) ? $result['tools'] : array();
		$out = array();
		foreach ( $raw as $tool ) {
			if ( ! is_array( $tool ) ) {
				continue;
			}
			$name = isset( $tool['name'] ) ? (string) $tool['name'] : '';
			if ( '' === $name ) {
				continue;
			}
			$schema = isset( $tool['inputSchema'] ) && is_array( $tool['inputSchema'] )
				? $tool['inputSchema']
				: array(
					'type'       => 'object',
					'properties' => array(),
				);
			$out[]  = array(
				'name'        => $name,
				'description' => isset( $tool['description'] ) ? (string) $tool['description'] : '',
				'inputSchema' => $schema,
			);
		}
		return $out;
	}
}
