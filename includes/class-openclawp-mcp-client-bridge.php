<?php
/**
 * Bridge external MCP servers into the local ability registry.
 *
 * For every enabled `openclawp_mcp_client` row, this class walks the cached
 * tools list and registers each (non-disabled, allowlisted) tool as a WP
 * ability named `mcp/<server-slug>/<tool>`. The ability's
 * `execute_callback` opens a fresh MCP session and proxies the call back to
 * the external server.
 *
 * The bridge intentionally relies on cached tool metadata persisted at
 * "re-test" time — we never block the request to a tool-list call. When the
 * cache is empty (the admin hasn't tested the server yet) the bridge skips
 * registration; the admin page shows "no tools discovered yet" and prompts
 * the admin to run Re-test.
 *
 * Pairs with #1 (tool-discovery meta-tools): bridged abilities live under
 * the `mcp` category so a future catalog layer can filter them in/out as a
 * group.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Mcp_Client_Bridge {

	public const ABILITY_PREFIX   = 'mcp/';
	public const ABILITY_CATEGORY = 'openclawp-mcp-clients';

	public static function register(): void {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_bridged_abilities' ) );
	}

	public static function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( self::ABILITY_CATEGORY ) ) {
			return;
		}
		wp_register_ability_category(
			self::ABILITY_CATEGORY,
			array(
				'label'       => __( 'openclaWP MCP clients', 'openclawp' ),
				'description' => __( 'Tools bridged in from external MCP servers (Fetch, Context7, GitHub, …).', 'openclawp' ),
			)
		);
	}

	public static function register_bridged_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		if ( ! class_exists( 'OpenclaWP_Mcp_Client_Store' ) ) {
			return;
		}

		foreach ( OpenclaWP_Mcp_Client_Store::enabled() as $post ) {
			self::register_for_server( $post );
		}
	}

	/**
	 * Build the list of (ability_name, server_post_id, server_tool_name)
	 * triples for the abilities this bridge would register. Pure-PHP helper
	 * exposed so tests can assert prefix wiring without registering any
	 * abilities in a real WordPress.
	 *
	 * @param array<int,array{name:string,description:string,inputSchema:array}> $cached_tools
	 * @param array<string,mixed>                                                $config
	 * @param string                                                             $server_slug
	 *
	 * @return array<int,array{ability_name:string,tool_name:string,description:string,input_schema:array}>
	 */
	public static function plan_ability_list( array $cached_tools, array $config, string $server_slug ): array {
		$server_slug = sanitize_title( $server_slug );
		if ( '' === $server_slug ) {
			return array();
		}

		$allowlist = is_array( $config['allowlist'] ?? null ) ? array_values( array_map( 'strval', $config['allowlist'] ) ) : array();
		$disabled  = is_array( $config['disabled'] ?? null ) ? array_values( array_map( 'strval', $config['disabled'] ) ) : array();

		$plan = array();
		foreach ( $cached_tools as $tool ) {
			$name = isset( $tool['name'] ) ? (string) $tool['name'] : '';
			if ( '' === $name ) {
				continue;
			}
			if ( in_array( $name, $disabled, true ) ) {
				continue;
			}
			if ( ! empty( $allowlist ) && ! in_array( $name, $allowlist, true ) ) {
				continue;
			}

			$ability_name = self::ABILITY_PREFIX . $server_slug . '/' . self::sanitize_tool_segment( $name );

			$plan[] = array(
				'ability_name' => $ability_name,
				'tool_name'    => $name,
				'description'  => isset( $tool['description'] ) ? (string) $tool['description'] : '',
				'input_schema' => isset( $tool['inputSchema'] ) && is_array( $tool['inputSchema'] )
					? $tool['inputSchema']
					: array(
						'type'       => 'object',
						'properties' => array(),
					),
			);
		}
		return $plan;
	}

	/**
	 * Sanitize a server-native tool name into a path segment safe for
	 * ability names. Lowercases and strips characters outside
	 * `[a-z0-9\-_]`. The original (pre-sanitized) name is what we actually
	 * send to the server in `tools/call`.
	 */
	public static function sanitize_tool_segment( string $name ): string {
		$lower = strtolower( $name );
		$clean = preg_replace( '/[^a-z0-9\-_]/', '-', $lower );
		return is_string( $clean ) ? trim( $clean, '-' ) : '';
	}

	/**
	 * Register every bridged tool for one server row.
	 */
	private static function register_for_server( \WP_Post $post ): void {
		$config = OpenclaWP_Mcp_Client_Store::config( $post );
		$tools  = OpenclaWP_Mcp_Client_Store::tools( $post );
		$slug   = OpenclaWP_Mcp_Client_Store::slug( $post );

		$plan = self::plan_ability_list( $tools, $config, $slug );
		if ( empty( $plan ) ) {
			return;
		}

		$post_id = (int) $post->ID;

		foreach ( $plan as $entry ) {
			$ability_name = $entry['ability_name'];
			if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $ability_name ) ) {
				continue;
			}

			$server_tool_name = $entry['tool_name'];
			$input_schema     = $entry['input_schema'];

			wp_register_ability(
				$ability_name,
				array(
					'label'               => sprintf(
						/* translators: 1: server label, 2: server-native tool name. */
						__( '%1$s — %2$s', 'openclawp' ),
						$post->post_title,
						$server_tool_name
					),
					'description'         => $entry['description'],
					'category'            => self::ABILITY_CATEGORY,
					'input_schema'        => $input_schema,
					'output_schema'       => array(
						'type'                 => 'object',
						'additionalProperties' => true,
					),
					'execute_callback'    => static function ( array $args = array() ) use ( $post_id, $server_tool_name ) {
						return self::execute_bridged_tool( $post_id, $server_tool_name, $args );
					},
					'permission_callback' => static function (): bool {
						/**
						 * Filters whether the current user may invoke bridged MCP tools.
						 *
						 * @param bool $allowed Default: manage_options.
						 */
						return (bool) apply_filters( 'openclawp_mcp_client_permission', current_user_can( 'manage_options' ) );
					},
					'meta'                => array(
						'effect' => OpenclaWP_Tool_Effects::EFFECT_EXTERNAL,
					),
				)
			);
		}
	}

	/**
	 * Execute a single bridged tool: re-open a transport session and forward
	 * the call. Errors are surfaced as WP_Errors so the loop's tool-call
	 * mediator can degrade gracefully ("the X tool is unavailable…").
	 *
	 * @param int                 $post_id     Server post ID.
	 * @param string              $tool_name   Server-native tool name.
	 * @param array<string,mixed> $arguments   Tool-call arguments.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function execute_bridged_tool( int $post_id, string $tool_name, array $arguments ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'mcp_client_missing_server', 'MCP server row not found' );
		}
		if ( ! OpenclaWP_Mcp_Client_Store::is_enabled( $post ) ) {
			return new \WP_Error( 'mcp_client_disabled', 'MCP server is disabled' );
		}

		$config = OpenclaWP_Mcp_Client_Store::config( $post );
		$result = OpenclaWP_Mcp_Client_Transport::call_tool( $config, $tool_name, $arguments );
		if ( is_wp_error( $result ) ) {
			OpenclaWP_Mcp_Client_Store::set_last_error( $post_id, $result->get_error_message() );
			return $result;
		}

		return $result;
	}
}
