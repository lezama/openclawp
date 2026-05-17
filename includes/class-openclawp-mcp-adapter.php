<?php
/**
 * Thin shim that exposes openclaWP's per-agent MCP servers through the
 * official WordPress/mcp-adapter (shipping with WordPress 7.0).
 *
 * The legacy hand-rolled JSON-RPC handler in `OpenclaWP_Mcp_Rest` registered
 * three methods (`initialize`, `tools/list`, `tools/call`) directly on a REST
 * route. WP 7.0 ships an official MCP transport adapter — once it's loaded,
 * we delegate the whole wire protocol to it and only contribute:
 *
 *   1. The per-agent tool catalog (which abilities each `openclawp_mcp_server`
 *      CPT row maps to, intersected with the post's tool allowlist).
 *   2. The bearer-token permission callback that re-uses the existing
 *      `OpenclaWP_Mcp_Server_Store::verify_token()` constant-time check.
 *   3. On-the-fly synthetic abilities for `delegate-to-<subagent>` tool
 *      declarations so coordinator agents can still dispatch to subagents
 *      through the MCP surface.
 *
 * Per-agent scoping: each MCP server post resolves to exactly one registered
 * `WP_Agent`. The adapter is told to expose only that agent's tool surface —
 * agents never see each other's abilities even though abilities live in a
 * single site-wide registry.
 *
 * Adapter detection is loose on purpose: WP 7.0 ships the adapter under the
 * `WordPress\McpAdapter` namespace, but the bootstrap class name has not yet
 * stabilised. We probe a few candidate hooks/functions and skip silently if
 * none are present, leaving the legacy path live (behind `OPENCLAWP_MCP_LEGACY`)
 * for sites that aren't on WP 7.0 yet.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Mcp_Adapter {

	/**
	 * Namespace under which we register synthetic delegate abilities.
	 * Each one is scoped per (server, subagent) so two MCP servers
	 * exposing the same subagent don't collide.
	 */
	public const DELEGATE_ABILITY_PREFIX = 'openclawp-mcp/delegate--';

	/**
	 * Track which synthetic delegate abilities we've already registered in
	 * this request so repeated lookups don't re-call `wp_register_ability()`.
	 *
	 * @var array<string, true>
	 */
	private static array $delegate_registered = array();

	public static function register(): void {
		// The adapter listens on these hooks (one of them). We attach to all
		// the plausible ones and bail at runtime if none fired.
		add_action( 'mcp_adapter_init', array( __CLASS__, 'on_adapter_init' ) );
		add_action( 'wp_mcp_adapter_init', array( __CLASS__, 'on_adapter_init' ) );
		add_action( 'init', array( __CLASS__, 'maybe_register_eagerly' ), 30 );
	}

	/**
	 * If the adapter exposes a global registration helper (not a hook), call
	 * it directly. Used by hosts that boot the adapter early enough that
	 * neither `mcp_adapter_init` nor `wp_mcp_adapter_init` fires before our
	 * own `plugins_loaded:20`.
	 */
	public static function maybe_register_eagerly(): void {
		if ( ! self::adapter_available() ) {
			return;
		}
		self::on_adapter_init();
	}

	public static function on_adapter_init(): void {
		if ( ! self::adapter_available() ) {
			return;
		}

		foreach ( OpenclaWP_Mcp_Server_Store::all() as $server ) {
			if ( ! OpenclaWP_Mcp_Server_Store::is_enabled( $server ) ) {
				continue;
			}
			self::register_server_with_adapter( $server );
		}
	}

	/**
	 * Feature-detect the WP/mcp-adapter. Public so callers (and tests) can
	 * branch on it; the adapter is optional on WP < 7.0.
	 */
	public static function adapter_available(): bool {
		// Filter override for tests + future-proofing as the adapter's API
		// stabilises in WP core.
		$forced = apply_filters( 'openclawp_mcp_adapter_available', null );
		if ( null !== $forced ) {
			return (bool) $forced;
		}

		return (
			function_exists( 'wp_register_mcp_server' )
			|| class_exists( '\\WP_MCP_Adapter' )
			|| class_exists( '\\WordPress\\McpAdapter\\Adapter' )
		);
	}

	/**
	 * Register one openclaWP MCP server post with the adapter.
	 *
	 * @return bool True on success, false if the adapter rejected the
	 *              registration or the agent/abilities couldn't be resolved.
	 */
	public static function register_server_with_adapter( \WP_Post $server ): bool {
		$abilities = self::abilities_for_server( $server );
		if ( empty( $abilities ) ) {
			return false;
		}

		$server_id  = 'openclawp/' . $server->post_name;
		$agent_slug = OpenclaWP_Mcp_Server_Store::agent_slug( $server );

		$args = array(
			'id'                  => $server_id,
			'namespace'           => 'openclawp/v1',
			'route'               => 'mcp-adapter/' . $server->post_name,
			'name'                => 'openclawp/' . $server->post_name,
			'title'               => $server->post_title,
			'description'         => sprintf(
				/* translators: %s: agent slug. */
				__( 'openclaWP MCP server exposing the `%s` agent.', 'openclawp' ),
				$agent_slug
			),
			'abilities'           => $abilities,
			'permission_callback' => static function ( $request ) use ( $server ) {
				return self::check_permission( $server, $request );
			},
		);

		/**
		 * Filter the registration args passed to the WP mcp-adapter.
		 *
		 * Lets host plugins override transport route, swap the permission
		 * callback, or extend the ability list before the adapter sees it.
		 *
		 * @param array    $args   Adapter registration args.
		 * @param \WP_Post $server The openclawp_mcp_server CPT row.
		 */
		$args = (array) apply_filters( 'openclawp_mcp_adapter_register_args', $args, $server );

		if ( function_exists( 'wp_register_mcp_server' ) ) {
			$result = wp_register_mcp_server( $server_id, $args );
			return ! is_wp_error( $result );
		}

		// Future-proofing: if WP 7.0 ships an `\WP_MCP_Adapter` static
		// registration entry point, route through it. We branch defensively
		// so we don't hard-bind to an in-flight API.
		if ( class_exists( '\\WP_MCP_Adapter' ) && method_exists( '\\WP_MCP_Adapter', 'register_server' ) ) {
			/** @phpstan-ignore-next-line — dynamic dispatch into core class. */
			\WP_MCP_Adapter::register_server( $server_id, $args );
			return true;
		}

		return false;
	}

	/**
	 * Pure helper: given a `OpenclaWP_Tools_Resolver::for_agent()` output and
	 * an optional sanitized-name allowlist, return the list of canonical
	 * ability names plus their MCP-shape tool definitions.
	 *
	 * Extracted so the adapter path can be parity-tested against
	 * {@see OpenclaWP_Mcp_Tool_Translator::translate_declarations()} without
	 * booting a real WordPress.
	 *
	 * @param array              $resolved  Output of `OpenclaWP_Tools_Resolver::for_agent()`.
	 * @param array<int, string> $allowlist Optional sanitized-name allowlist.
	 *
	 * @return array{abilities: array<int, string>, tools: array<int, array{name:string, description:string, inputSchema:array, ability:string}>}
	 */
	public static function project_resolved_for_adapter( array $resolved, array $allowlist = array() ): array {
		$declarations  = (array) ( $resolved['declarations'] ?? array() );
		$name_to_abil  = (array) ( $resolved['name_to_ability'] ?? array() );
		$delegates     = (array) ( $resolved['delegate_targets'] ?? array() );
		$allowlist_set = array_flip( $allowlist );

		$abilities = array();
		$tools     = array();
		foreach ( $declarations as $declared_name => $declaration ) {
			$declared_name = (string) $declared_name;
			if ( ! empty( $allowlist ) && ! isset( $allowlist_set[ $declared_name ] ) ) {
				continue;
			}

			if ( isset( $delegates[ $declared_name ] ) ) {
				// Synthetic per-server ability — name depends on the server
				// post; for the pure helper we surface the canonical pattern
				// with a placeholder server segment.
				$ability_name = self::DELEGATE_ABILITY_PREFIX . 'X--' . preg_replace( '/[^a-z0-9-]/', '', strtolower( (string) $delegates[ $declared_name ] ) );
			} else {
				$ability_name = (string) ( $name_to_abil[ $declared_name ] ?? '' );
			}

			if ( '' === $ability_name ) {
				continue;
			}
			$abilities[] = $ability_name;
			$tools[]     = array(
				'name'        => $declared_name,
				'description' => (string) ( $declaration['description'] ?? '' ),
				'inputSchema' => is_array( $declaration['parameters'] ?? null )
					? (array) $declaration['parameters']
					: array( 'type' => 'object', 'properties' => array() ),
				'ability'     => $ability_name,
			);
		}

		return array(
			'abilities' => array_values( array_unique( $abilities ) ),
			'tools'     => $tools,
		);
	}

	/**
	 * Resolve the full list of ability names this server exposes. Equivalent
	 * to what the legacy `tools/list` returned, except expressed as canonical
	 * ability names rather than sanitized provider-safe names.
	 *
	 * Subagent delegation: coordinator agents declare subagents, not
	 * abilities, for that path. We synthesize one ability per server-scoped
	 * delegate (registered on first lookup) so the adapter sees a uniform
	 * list of ability names.
	 *
	 * @return array<int, string> List of canonical ability names.
	 */
	public static function abilities_for_server( \WP_Post $server ): array {
		$agent_slug = OpenclaWP_Mcp_Server_Store::agent_slug( $server );
		$agent      = function_exists( 'wp_get_agent' ) ? wp_get_agent( $agent_slug ) : null;
		if ( null === $agent ) {
			return array();
		}

		$resolved      = OpenclaWP_Tools_Resolver::for_agent( $agent );
		$declarations  = (array) ( $resolved['declarations'] ?? array() );
		$name_to_abil  = (array) ( $resolved['name_to_ability'] ?? array() );
		$delegates     = (array) ( $resolved['delegate_targets'] ?? array() );
		$allowlist     = OpenclaWP_Mcp_Server_Store::tool_allowlist( $server );
		$allowlist_set = array_flip( $allowlist );

		$abilities = array();

		foreach ( $declarations as $declared_name => $declaration ) {
			if ( ! empty( $allowlist ) && ! isset( $allowlist_set[ (string) $declared_name ] ) ) {
				continue;
			}

			if ( isset( $delegates[ $declared_name ] ) ) {
				$ability = self::ensure_delegate_ability( $server, (string) $declared_name, (string) $delegates[ $declared_name ], (array) $declaration );
				if ( '' !== $ability ) {
					$abilities[] = $ability;
				}
				continue;
			}

			$ability_name = (string) ( $name_to_abil[ $declared_name ] ?? '' );
			if ( '' !== $ability_name ) {
				$abilities[] = $ability_name;
			}
		}

		return array_values( array_unique( $abilities ) );
	}

	/**
	 * Permission callback for an adapter-registered server. Same rules as
	 * the legacy REST route: admins in their own wp-admin session bypass,
	 * everyone else needs the per-server bearer token.
	 *
	 * @param \WP_Post                $server  The MCP server CPT row.
	 * @param \WP_REST_Request|object $request Adapter passes the active REST request.
	 */
	public static function check_permission( \WP_Post $server, $request ): bool {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		if ( ! OpenclaWP_Mcp_Server_Store::is_enabled( $server ) ) {
			return false;
		}

		$header = '';
		if ( is_object( $request ) && method_exists( $request, 'get_header' ) ) {
			$header = (string) $request->get_header( 'authorization' );
		}
		if ( '' === $header || 0 !== stripos( $header, 'Bearer ' ) ) {
			return false;
		}
		$presented = trim( substr( $header, 7 ) );
		if ( '' === $presented ) {
			return false;
		}
		return OpenclaWP_Mcp_Server_Store::verify_token( $server, $presented );
	}

	/**
	 * Lazily register a synthetic ability that wraps a `delegate-to-<sub>`
	 * declaration. The adapter operates on the abilities API; abilities are
	 * the only callable primitive it knows about. To keep the adapter path
	 * symmetrical with the legacy path (which had the executor dispatch
	 * delegations through canonical `agents/chat`), we register one
	 * server-scoped ability per delegate and let the existing
	 * `OpenclaWP_Tool_Executor` drive the actual subagent turn.
	 *
	 * Scoping the ability per server (rather than per subagent) means two
	 * MCP servers exposing the same subagent stay independent — disabling
	 * one doesn't pull the other's delegate ability out from under it.
	 *
	 * @return string Canonical ability name (e.g.
	 *                `openclawp-mcp/delegate--{server}--{subagent}`) or `''`
	 *                if registration was unavailable.
	 */
	private static function ensure_delegate_ability( \WP_Post $server, string $declared_name, string $subagent_slug, array $declaration ): string {
		$ability_name = self::DELEGATE_ABILITY_PREFIX . sanitize_title( $server->post_name . '--' . $subagent_slug );

		if ( isset( self::$delegate_registered[ $ability_name ] ) ) {
			return $ability_name;
		}

		if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_has_ability' ) ) {
			return '';
		}

		if ( wp_has_ability( $ability_name ) ) {
			self::$delegate_registered[ $ability_name ] = true;
			return $ability_name;
		}

		$parameters    = is_array( $declaration['parameters'] ?? null )
			? (array) $declaration['parameters']
			: array( 'type' => 'object', 'properties' => array( 'prompt' => array( 'type' => 'string' ) ), 'required' => array( 'prompt' ) );
		$description   = (string) ( $declaration['description'] ?? sprintf( 'Delegate to subagent %s.', $subagent_slug ) );
		$delegate_name = $declared_name;
		$server_id     = (int) $server->ID;

		wp_register_ability(
			$ability_name,
			array(
				'label'               => $declaration['description'] ?? sprintf( 'Delegate to %s', $subagent_slug ),
				'description'         => $description,
				'category'            => 'openclawp',
				'input_schema'        => $parameters,
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'reply'      => array( 'type' => 'string' ),
						'session_id' => array( 'type' => 'string' ),
						'completed'  => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => static function ( array $args ) use ( $server_id, $delegate_name ) {
					$server_post = get_post( $server_id );
					if ( ! $server_post instanceof \WP_Post ) {
						return new \WP_Error( 'openclawp_mcp_delegate', 'MCP server row disappeared.' );
					}
					$agent_slug = OpenclaWP_Mcp_Server_Store::agent_slug( $server_post );
					$agent      = function_exists( 'wp_get_agent' ) ? wp_get_agent( $agent_slug ) : null;
					if ( null === $agent ) {
						return new \WP_Error( 'openclawp_mcp_delegate', 'Parent agent is not registered.' );
					}
					$resolved = OpenclaWP_Tools_Resolver::for_agent( $agent );
					$executor = new OpenclaWP_Tool_Executor(
						(array) ( $resolved['name_to_ability'] ?? array() ),
						(array) ( $resolved['delegate_targets'] ?? array() )
					);

					$result = $executor->executeWP_Agent_Tool_Call(
						array(
							'tool_name'  => $delegate_name,
							'parameters' => $args,
						),
						array()
					);

					if ( empty( $result['success'] ) ) {
						return new \WP_Error( 'openclawp_mcp_delegate', (string) ( $result['error'] ?? 'delegation failed' ) );
					}
					return is_array( $result['result'] ?? null ) ? $result['result'] : array( 'reply' => (string) ( $result['result'] ?? '' ) );
				},
				'permission_callback' => '__return_true',
			)
		);

		self::$delegate_registered[ $ability_name ] = true;
		return $ability_name;
	}
}
