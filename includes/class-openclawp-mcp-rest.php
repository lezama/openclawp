<?php
/**
 * REST mount for openclaWP's MCP servers.
 *
 * Each enabled MCP server is reachable at `/openclawp/v1/mcp/{slug}` and
 * speaks JSON-RPC 2.0 over HTTP POST. v1 supports three core methods:
 * `initialize`, `tools/list`, `tools/call`. Resource and prompt
 * primitives return JSON-RPC error -32601 with a follow-up pointer.
 *
 * Auth (default — issue #45):
 *   - OAuth 2.1 bearer token issued by `OpenclaWP_Oauth_Server`.
 *   - Token must be active, unexpired, unrevoked, and bound to this MCP
 *     server slug (audience binding — analogue of RFC 8707).
 *   - Token scope decides which abilities the client may call; the
 *     `tools/list` response is filtered to match (clients only see what
 *     they can call) and `tools/call` is hard-gated per-call.
 *
 * Auth (legacy — opt-in via `define( 'OPENCLAWP_MCP_LEGACY_AUTH', true )`):
 *   - Bearer token compared against the per-server hash from
 *     `OpenclaWP_Mcp_Server_Store`. Logs a deprecation `error_log()` per
 *     request so the site owner notices the warning in real time.
 *
 * Admins logged into wp-admin with `manage_options` are allowed through
 * without a token so a curl from the same session works.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Mcp_Rest {

	public const NAMESPACE = 'openclawp/v1';
	public const ROUTE     = 'mcp/(?P<server>[A-Za-z0-9-]+)';
	public const PROTOCOL  = '2025-06-18';

	/**
	 * RFC 8594 Sunset date — the planned removal of this legacy endpoint.
	 * Bump in lockstep with the deprecation window described in docs/mcp.md.
	 */
	public const SUNSET = 'Wed, 01 Jul 2026 00:00:00 GMT';

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/' . self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
				'args'                => array(
					'server' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_title',
					),
				),
			)
		);
	}

	public static function check_permission( \WP_REST_Request $request ): bool {
		// Admins are trusted in their own session; useful for one-off curl from wp-admin.
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$server = OpenclaWP_Mcp_Server_Store::find_by_slug( (string) $request->get_param( 'server' ) );
		if ( null === $server || ! OpenclaWP_Mcp_Server_Store::is_enabled( $server ) ) {
			return false;
		}

		$presented = self::bearer_token( $request );
		if ( '' === $presented ) {
			return false;
		}

		// OAuth 2.1 path (default).
		$token = OpenclaWP_Oauth_Store::find_token_by_value( $presented, OpenclaWP_Oauth_Store::KIND_ACCESS );
		if ( null !== $token ) {
			// Audience binding: token must match this MCP server slug.
			if ( OpenclaWP_Oauth_Store::token_mcp_server_slug( $token ) !== $server->post_name ) {
				return false;
			}
			OpenclaWP_Oauth_Store::touch_token( $token );
			$request->set_param( '_openclawp_oauth_token_id', $token->ID );
			return true;
		}

		// Legacy bearer path — opt-in via constant.
		if ( self::legacy_auth_enabled() ) {
			if ( OpenclaWP_Mcp_Server_Store::verify_token( $server, $presented ) ) {
				// Surface a deprecation warning per the migration plan.
				error_log( 'openclaWP: legacy bearer MCP auth in use (set OPENCLAWP_MCP_LEGACY_AUTH=false once OAuth is rolled out)' );
				$request->set_param( '_openclawp_oauth_legacy', 1 );
				return true;
			}
		}

		return false;
	}

	private static function legacy_auth_enabled(): bool {
		if ( defined( 'OPENCLAWP_MCP_LEGACY_AUTH' ) ) {
			return (bool) constant( 'OPENCLAWP_MCP_LEGACY_AUTH' );
		}
		$env = getenv( 'OPENCLAWP_MCP_LEGACY_AUTH' );
		return false !== $env && '' !== $env && '0' !== $env && 'false' !== strtolower( (string) $env );
	}

	/**
	 * Dispatch one JSON-RPC envelope.
	 */
	public static function handle( \WP_REST_Request $request ) {
		self::log_deprecation_once( $request );

		$server = OpenclaWP_Mcp_Server_Store::find_by_slug( (string) $request->get_param( 'server' ) );
		if ( null === $server || ! OpenclaWP_Mcp_Server_Store::is_enabled( $server ) ) {
			return self::with_deprecation_headers(
				new \WP_REST_Response(
					self::jsonrpc_error( null, -32004, 'server not found or disabled' ),
					404
				)
			);
		}

		$body = $request->get_body();
		$rpc  = json_decode( (string) $body, true );
		if ( ! is_array( $rpc ) || ! isset( $rpc['jsonrpc'] ) || '2.0' !== $rpc['jsonrpc'] ) {
			return self::with_deprecation_headers(
				new \WP_REST_Response( self::jsonrpc_error( null, -32700, 'parse error' ), 400 )
			);
		}

		$id     = $rpc['id'] ?? null;
		$method = isset( $rpc['method'] ) ? (string) $rpc['method'] : '';
		$params = isset( $rpc['params'] ) && is_array( $rpc['params'] ) ? $rpc['params'] : array();

		// Notifications (no id) get accepted with 202 + empty body.
		$is_notification = ! array_key_exists( 'id', $rpc );

		return self::with_deprecation_headers(
			self::dispatch( $server, $id, $method, $params, $is_notification, $request )
		);
	}

	/**
	 * Stamp an outgoing response with the RFC 8594 `Sunset` + `Deprecation`
	 * headers and a `Link: rel="successor-version"` pointer to the official
	 * mcp-adapter route. Helps external clients flag the migration without
	 * needing to read release notes.
	 */
	private static function with_deprecation_headers( \WP_REST_Response $response ): \WP_REST_Response {
		$response->header( 'Sunset', self::SUNSET );
		$response->header( 'Deprecation', 'true' );
		$response->header(
			'Link',
			'<' . esc_url_raw( rest_url( self::NAMESPACE . '/mcp-adapter/' ) ) . '>; rel="successor-version"'
		);
		return $response;
	}

	/**
	 * Log a deprecation notice for the running PHP error log. Once per
	 * request — repeated tools/list polls from a long-lived MCP client
	 * shouldn't flood the log every minute.
	 */
	private static function log_deprecation_once( \WP_REST_Request $request ): void {
		static $logged = false;
		if ( $logged ) {
			return;
		}
		$logged = true;

		if ( function_exists( '_doing_it_wrong' ) ) {
			_doing_it_wrong(
				'OpenclaWP_Mcp_Rest::handle',
				sprintf(
					/* translators: %s: legacy MCP slug. */
					esc_html__( 'openclaWP legacy MCP JSON-RPC endpoint (%s) is deprecated. Migrate to the official mcp-adapter route under /openclawp/v1/mcp-adapter/{slug}.', 'openclawp' ),
					esc_html( (string) $request->get_param( 'server' ) )
				),
				'0.2.0'
			);
		}
	}

	private static function dispatch( \WP_Post $server, $id, string $method, array $params, bool $is_notification, \WP_REST_Request $request ): \WP_REST_Response {
		try {
			switch ( $method ) {
				case 'initialize':
					return self::ok( $id, self::initialize_result( $server ) );

				case 'notifications/initialized':
					return new \WP_REST_Response( null, 202 );

				case 'tools/list':
					return self::ok( $id, self::tools_list_result( $server, $request ) );

				case 'tools/call':
					$result = self::tools_call_result( $server, $params, $request );
					if ( $result instanceof \WP_REST_Response ) {
						return $result;
					}
					return self::ok( $id, $result );

				case 'resources/list':
				case 'resources/read':
				case 'prompts/list':
				case 'prompts/get':
					return new \WP_REST_Response(
						self::jsonrpc_error( $id, -32601, 'method not supported in v1', array(
							'followUp' => 'See openclaWP docs/mcp.md for the resource/prompt follow-up plan.',
						) ),
						200
					);
			}

			if ( $is_notification ) {
				return new \WP_REST_Response( null, 202 );
			}

			return new \WP_REST_Response(
				self::jsonrpc_error( $id, -32601, sprintf( 'method `%s` not found', $method ) ),
				200
			);
		} catch ( \Throwable $e ) {
			return new \WP_REST_Response(
				self::jsonrpc_error( $id, -32603, 'internal error', array( 'detail' => $e->getMessage() ) ),
				500
			);
		}
	}

	private static function initialize_result( \WP_Post $server ): array {
		return array(
			'protocolVersion' => self::PROTOCOL,
			'capabilities'    => array(
				'tools' => new \stdClass(),
			),
			'serverInfo'      => array(
				'name'    => 'openclawp/' . $server->post_name,
				'version' => defined( 'OPENCLAWP_VERSION' ) ? (string) OPENCLAWP_VERSION : '0.0.0',
			),
		);
	}

	private static function tools_list_result( \WP_Post $server, ?\WP_REST_Request $request = null ): array {
		$agent_slug = OpenclaWP_Mcp_Server_Store::agent_slug( $server );
		$agent      = function_exists( 'wp_get_agent' ) ? wp_get_agent( $agent_slug ) : null;
		if ( null === $agent ) {
			return array( 'tools' => array() );
		}
		$allowlist = OpenclaWP_Mcp_Server_Store::tool_allowlist( $server );
		$tools     = OpenclaWP_Mcp_Tool_Translator::translate( $agent, $allowlist );

		// Filter by token scope. If the caller is an admin session or legacy
		// bearer client, show everything.
		$scopes = self::request_scopes( $request );
		if ( null === $scopes ) {
			return array( 'tools' => $tools );
		}

		$resolved   = OpenclaWP_Tools_Resolver::for_agent( $agent );
		$name_map   = (array) ( $resolved['name_to_ability'] ?? array() );
		$filtered   = array();
		foreach ( $tools as $tool ) {
			$ability_name = (string) ( $name_map[ $tool['name'] ] ?? '' );
			if ( '' === $ability_name ) {
				// Subagent delegations route through `agents/chat`. They inherit
				// the parent's effect tier — treat as write to be conservative.
				$effect = OpenclaWP_Oauth_Scope::EFFECT_WRITE;
			} else {
				$effect = OpenclaWP_Oauth_Scope::effect_for_ability( $ability_name );
			}
			if ( OpenclaWP_Oauth_Scope::scopes_permit_effect( $scopes, $effect ) ) {
				$filtered[] = $tool;
			}
		}
		return array( 'tools' => $filtered );
	}

	/**
	 * Return the granted scopes for the current request, or null when the
	 * caller wasn't authenticated via OAuth (admin session / legacy bearer).
	 *
	 * @return array<int, string>|null
	 */
	private static function request_scopes( ?\WP_REST_Request $request ): ?array {
		if ( null === $request ) {
			return null;
		}
		$token_id = (int) $request->get_param( '_openclawp_oauth_token_id' );
		if ( $token_id <= 0 ) {
			return null;
		}
		$token = get_post( $token_id );
		if ( ! $token instanceof \WP_Post ) {
			return null;
		}
		return OpenclaWP_Oauth_Store::token_scopes( $token );
	}

	/**
	 * @return array{content:array, isError:bool}|\WP_REST_Response
	 */
	private static function tools_call_result( \WP_Post $server, array $params, ?\WP_REST_Request $request = null ) {
		$name = isset( $params['name'] ) ? (string) $params['name'] : '';
		if ( '' === $name ) {
			return new \WP_REST_Response(
				self::jsonrpc_error( null, -32602, 'tools/call requires a `name` param' ),
				200
			);
		}
		$arguments = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();

		$agent_slug = OpenclaWP_Mcp_Server_Store::agent_slug( $server );
		$agent      = function_exists( 'wp_get_agent' ) ? wp_get_agent( $agent_slug ) : null;
		if ( null === $agent ) {
			return new \WP_REST_Response(
				self::jsonrpc_error( null, -32602, sprintf( 'agent `%s` is not registered', $agent_slug ) ),
				200
			);
		}

		$resolved  = OpenclaWP_Tools_Resolver::for_agent( $agent );
		$allowlist = OpenclaWP_Mcp_Server_Store::tool_allowlist( $server );
		if ( ! empty( $allowlist ) && ! in_array( $name, $allowlist, true ) ) {
			return array(
				'isError' => true,
				'content' => array( array( 'type' => 'text', 'text' => sprintf( 'tool `%s` is not in this server\'s allowlist', $name ) ) ),
			);
		}

		// Scope enforcement (OAuth path only — admin sessions / legacy bearer
		// bypass scopes since they had no scope grant).
		$scopes = self::request_scopes( $request );
		if ( null !== $scopes ) {
			$name_map     = (array) ( $resolved['name_to_ability'] ?? array() );
			$ability_name = (string) ( $name_map[ $name ] ?? '' );
			$effect       = '' === $ability_name
				? OpenclaWP_Oauth_Scope::EFFECT_WRITE
				: OpenclaWP_Oauth_Scope::effect_for_ability( $ability_name );
			if ( ! OpenclaWP_Oauth_Scope::scopes_permit_effect( $scopes, $effect ) ) {
				return array(
					'isError' => true,
					'content' => array(
						array(
							'type' => 'text',
							'text' => sprintf(
								'insufficient_scope: tool `%s` requires effect `%s`, your token grants scopes [%s]',
								$name,
								$effect,
								implode( ' ', $scopes )
							),
						),
					),
				);
			}
		}

		$executor = new OpenclaWP_Tool_Executor(
			(array) ( $resolved['name_to_ability'] ?? array() ),
			(array) ( $resolved['delegate_targets'] ?? array() )
		);

		$result = $executor->executeWP_Agent_Tool_Call(
			array(
				'tool_name'  => $name,
				'parameters' => $arguments,
			),
			array()
		);

		$is_error = empty( $result['success'] );
		$body     = $is_error
			? ( isset( $result['error'] ) ? (string) $result['error'] : 'tool execution failed' )
			: (string) wp_json_encode( $result['result'] ?? null );

		return array(
			'isError' => $is_error,
			'content' => array(
				array(
					'type' => 'text',
					'text' => $body,
				),
			),
		);
	}

	private static function bearer_token( \WP_REST_Request $request ): string {
		$header = (string) $request->get_header( 'authorization' );
		if ( '' === $header ) {
			return '';
		}
		if ( 0 !== stripos( $header, 'Bearer ' ) ) {
			return '';
		}
		return trim( substr( $header, 7 ) );
	}

	private static function ok( $id, array $result ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => $result,
			),
			200
		);
	}

	private static function jsonrpc_error( $id, int $code, string $message, array $data = array() ): array {
		$error = array(
			'code'    => $code,
			'message' => $message,
		);
		if ( ! empty( $data ) ) {
			$error['data'] = $data;
		}
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => $error,
		);
	}
}
