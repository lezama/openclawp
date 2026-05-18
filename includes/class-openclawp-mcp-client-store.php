<?php
/**
 * CPT-backed store for external MCP server configurations (MCP-client mode).
 *
 * Each row represents an external MCP server that openclaWP connects to as a
 * client, so its tools can be bridged into the local ability registry under
 * `mcp/<server>/<tool>`. Mirrors the layout of
 * {@see OpenclaWP_Mcp_Server_Store}: post_name is the URL-safe slug used in
 * ability names, post_status carries enabled/disabled (publish/draft), and
 * the rest of the configuration lives in post-meta.
 *
 * The store is intentionally schema-thin: transport, command, args, env, and
 * the per-tool allowlist are stored as a single JSON blob to keep migrations
 * simple while the surface stabilizes. The cached tools list and last-error
 * are persisted so the admin can render status without re-probing on every
 * page load.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Mcp_Client_Store {

	public const POST_TYPE = 'openclawp_mcp_client';

	public const META_CONFIG     = '_openclawp_mcp_client_config';
	public const META_TOOLS      = '_openclawp_mcp_client_tools';
	public const META_LAST_ERROR = '_openclawp_mcp_client_last_error';
	public const META_LAST_OK_AT = '_openclawp_mcp_client_last_ok_at';

	public const TRANSPORT_STDIO = 'stdio';
	public const TRANSPORT_HTTP  = 'http';

	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'openclaWP MCP Clients', 'openclawp' ),
					'singular_name' => __( 'openclaWP MCP Client', 'openclawp' ),
				),
				'public'              => false,
				'show_ui'             => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'rewrite'             => false,
				'has_archive'         => false,
				'supports'            => array( 'title', 'author', 'custom-fields' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			)
		);
	}

	/**
	 * Default configuration shape — used by sanitization to ensure new rows
	 * always have the same set of keys.
	 *
	 * @return array<string,mixed>
	 */
	public static function default_config(): array {
		return array(
			'transport' => self::TRANSPORT_STDIO,
			'command'   => '',
			'args'      => array(),
			'env'       => array(),
			'url'       => '',
			'headers'   => array(),
			'allowlist' => array(), // list of tool names (server-native, pre-sanitization). Empty = all.
			'disabled'  => array(), // list of tool names explicitly disabled by the admin.
		);
	}

	/**
	 * Find a row by URL slug.
	 */
	public static function find_by_slug( string $slug ): ?\WP_Post {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return null;
		}
		$matches = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'draft' ),
				'name'           => $slug,
				'posts_per_page' => 1,
				'no_found_rows'  => true,
			)
		);
		return $matches[0] ?? null;
	}

	/**
	 * Return all client rows, newest first.
	 *
	 * @return array<int,\WP_Post>
	 */
	public static function all(): array {
		return (array) get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => 100,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);
	}

	/**
	 * Return only enabled rows (publish status). Used by the bridge.
	 *
	 * @return array<int,\WP_Post>
	 */
	public static function enabled(): array {
		return (array) get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'orderby'        => 'date',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
	}

	/**
	 * Create a new MCP client row.
	 *
	 * @param string              $label  Human-readable name.
	 * @param string              $slug   URL-safe slug, used as the ability prefix.
	 * @param array<string,mixed> $config Configuration (transport, command, args, env, …).
	 *
	 * @return array{post_id:int}|\WP_Error
	 */
	public static function create( string $label, string $slug, array $config ) {
		$label = trim( $label );
		$slug  = sanitize_title( $slug );

		if ( '' === $label || '' === $slug ) {
			return new \WP_Error( 'invalid_input', 'label and slug are required' );
		}
		if ( null !== self::find_by_slug( $slug ) ) {
			return new \WP_Error( 'slug_taken', sprintf( 'an MCP client with slug `%s` already exists', $slug ) );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $label,
				'post_name'   => $slug,
				'post_author' => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( (int) $post_id, self::META_CONFIG, self::sanitize_config( $config ) );

		return array( 'post_id' => (int) $post_id );
	}

	public static function update_config( int $post_id, array $config ): bool {
		return false !== update_post_meta( $post_id, self::META_CONFIG, self::sanitize_config( $config ) );
	}

	public static function toggle_enabled( int $post_id, bool $enabled ): bool {
		$result = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => $enabled ? 'publish' : 'draft',
			),
			true
		);
		return ! is_wp_error( $result );
	}

	public static function delete( int $post_id ): bool {
		return false !== wp_delete_post( $post_id, true );
	}

	public static function is_enabled( \WP_Post $post ): bool {
		return 'publish' === $post->post_status;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function config( \WP_Post $post ): array {
		$raw = get_post_meta( $post->ID, self::META_CONFIG, true );
		if ( ! is_array( $raw ) ) {
			return self::default_config();
		}
		return array_merge( self::default_config(), $raw );
	}

	/**
	 * Persist the cached tools list (output of a successful tools/list call).
	 *
	 * @param array<int,array{name:string,description:string,inputSchema:array}> $tools
	 */
	public static function set_tools( int $post_id, array $tools ): void {
		update_post_meta( $post_id, self::META_TOOLS, $tools );
		update_post_meta( $post_id, self::META_LAST_OK_AT, time() );
		delete_post_meta( $post_id, self::META_LAST_ERROR );
	}

	/**
	 * @return array<int,array{name:string,description:string,inputSchema:array}>
	 */
	public static function tools( \WP_Post $post ): array {
		$raw = get_post_meta( $post->ID, self::META_TOOLS, true );
		return is_array( $raw ) ? $raw : array();
	}

	public static function set_last_error( int $post_id, string $error ): void {
		update_post_meta( $post_id, self::META_LAST_ERROR, $error );
	}

	public static function last_error( \WP_Post $post ): string {
		$value = get_post_meta( $post->ID, self::META_LAST_ERROR, true );
		return is_string( $value ) ? $value : '';
	}

	public static function last_ok_at( \WP_Post $post ): int {
		$value = get_post_meta( $post->ID, self::META_LAST_OK_AT, true );
		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * Slug used as the ability prefix: `mcp/<slug>/<tool>`.
	 */
	public static function slug( \WP_Post $post ): string {
		return $post->post_name;
	}

	/**
	 * Coerce arbitrary input into the stored shape. Unknown keys are dropped.
	 *
	 * @param array<string,mixed> $config
	 * @return array<string,mixed>
	 */
	public static function sanitize_config( array $config ): array {
		$defaults = self::default_config();
		$out      = $defaults;

		$transport        = isset( $config['transport'] ) ? (string) $config['transport'] : self::TRANSPORT_STDIO;
		$out['transport'] = self::TRANSPORT_HTTP === $transport ? self::TRANSPORT_HTTP : self::TRANSPORT_STDIO;

		$out['command'] = isset( $config['command'] ) ? trim( (string) $config['command'] ) : '';
		$out['url']     = isset( $config['url'] ) ? esc_url_raw( (string) $config['url'] ) : '';

		$out['args']      = self::sanitize_string_list( $config['args'] ?? array() );
		$out['allowlist'] = self::sanitize_string_list( $config['allowlist'] ?? array() );
		$out['disabled']  = self::sanitize_string_list( $config['disabled'] ?? array() );
		$out['env']       = self::sanitize_string_map( $config['env'] ?? array() );
		$out['headers']   = self::sanitize_string_map( $config['headers'] ?? array() );

		return $out;
	}

	/**
	 * @param mixed $value
	 * @return array<int,string>
	 */
	private static function sanitize_string_list( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $item ) {
			$str = trim( (string) $item );
			if ( '' !== $str ) {
				$out[] = $str;
			}
		}
		return array_values( $out );
	}

	/**
	 * @param mixed $value
	 * @return array<string,string>
	 */
	private static function sanitize_string_map( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $key => $val ) {
			$k = trim( (string) $key );
			if ( '' === $k ) {
				continue;
			}
			$out[ $k ] = (string) $val;
		}
		return $out;
	}
}
