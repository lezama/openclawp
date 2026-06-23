<?php
/**
 * CPT-backed MCP server store.
 *
 * Each MCP server registration is one `openclawp_mcp_server` post. The
 * server's slug doubles as the URL segment (`/openclawp/v1/mcp/{slug}`)
 * and the post_name; the agent slug it exposes, optional tool allowlist,
 * and bearer-token hash live in post-meta. Post status carries
 * enabled/disabled (`publish` / `draft`) so we don't need an extra meta
 * lookup at request time.
 *
 * The plaintext bearer token is recoverable for 15 minutes after create
 * or regenerate via a per-user flash transient — only its
 * `wp_hash_password()` hash + last four chars persist. Acknowledging the
 * disclosure (or letting the transient expire) purges the plaintext;
 * regenerate then produces a new token + new hash and immediately
 * invalidates the old one.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Mcp_Server_Store {

	public const POST_TYPE = 'openclawp_mcp_server';

	public const META_AGENT      = '_openclawp_mcp_agent';
	public const META_ALLOWLIST  = '_openclawp_mcp_tool_allowlist';
	public const META_TOKEN_HASH = '_openclawp_mcp_token_hash';
	public const META_TOKEN_LAST4 = '_openclawp_mcp_token_last4';

	/**
	 * How long the post-create / post-regenerate plaintext token stays
	 * recoverable to the admin who triggered it. Long enough to copy
	 * into a config file even after an accidental refresh, short enough
	 * not to be a meaningful security hole.
	 */
	public const TOKEN_FLASH_TTL = 15 * MINUTE_IN_SECONDS;

	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'openclaWP MCP Servers', 'openclawp' ),
					'singular_name' => __( 'openclaWP MCP Server', 'openclawp' ),
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
	 * Find a server post by URL slug. Returns null when nothing matches.
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
	 * Return all servers, newest first. Used by the admin list view.
	 *
	 * @return array<int, \WP_Post>
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
	 * Create a server row. Returns `[post_id, plaintext_token]` — the token
	 * is shown to the admin once; only its hash is persisted.
	 *
	 * @return array{post_id:int, token:string}|\WP_Error
	 */
	public static function create( string $label, string $slug, string $agent_slug, array $tool_allowlist = array() ) {
		$slug  = sanitize_title( $slug );
		$label = trim( $label );

		if ( '' === $slug || '' === $label || '' === $agent_slug ) {
			return new \WP_Error( 'invalid_input', 'label, slug, and agent_slug are required' );
		}

		if ( null !== self::find_by_slug( $slug ) ) {
			return new \WP_Error( 'slug_taken', sprintf( 'an MCP server with slug `%s` already exists', $slug ) );
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

		update_post_meta( (int) $post_id, self::META_AGENT, $agent_slug );
		update_post_meta( (int) $post_id, self::META_ALLOWLIST, array_values( array_map( 'strval', $tool_allowlist ) ) );

		$token = self::generate_token();
		self::set_token( (int) $post_id, $token );

		return array(
			'post_id' => (int) $post_id,
			'token'   => $token,
		);
	}

	/**
	 * Rotate a server's bearer token. Returns the new plaintext token.
	 */
	public static function rotate_token( int $post_id ): string {
		$token = self::generate_token();
		self::set_token( $post_id, $token );
		return $token;
	}

	/**
	 * Slug-keyed wrapper around `rotate_token()`. Returns the new
	 * plaintext token, or null when the slug does not resolve.
	 */
	public static function regenerate_token( string $slug ): ?string {
		$post = self::find_by_slug( $slug );
		if ( null === $post ) {
			return null;
		}
		return self::rotate_token( $post->ID );
	}

	public static function toggle_enabled( int $post_id, bool $enabled ): bool {
		$new_status = $enabled ? 'publish' : 'draft';
		$result     = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => $new_status,
			),
			true
		);
		return ! is_wp_error( $result );
	}

	public static function delete( int $post_id ): bool {
		return false !== wp_delete_post( $post_id, true );
	}

	/**
	 * Verify a presented bearer against the stored hash. Constant-time via
	 * `wp_check_password()`.
	 */
	public static function verify_token( \WP_Post $post, string $presented ): bool {
		$hash = (string) get_post_meta( $post->ID, self::META_TOKEN_HASH, true );
		if ( '' === $hash || '' === $presented ) {
			return false;
		}
		return wp_check_password( $presented, $hash );
	}

	public static function agent_slug( \WP_Post $post ): string {
		return (string) get_post_meta( $post->ID, self::META_AGENT, true );
	}

	/**
	 * @return array<int, string>
	 */
	public static function tool_allowlist( \WP_Post $post ): array {
		$raw = get_post_meta( $post->ID, self::META_ALLOWLIST, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		return array_values( array_map( 'strval', $raw ) );
	}

	public static function token_last4( \WP_Post $post ): string {
		return (string) get_post_meta( $post->ID, self::META_TOKEN_LAST4, true );
	}

	public static function is_enabled( \WP_Post $post ): bool {
		return 'publish' === $post->post_status;
	}

	/**
	 * Stash a plaintext token in a per-user flash transient so the admin
	 * page can recover it after a create / regenerate. TTL is
	 * `TOKEN_FLASH_TTL` so an accidental refresh isn't terminal — long
	 * enough to copy into a config file, short enough that an
	 * unattended browser tab isn't a meaningful exposure.
	 */
	public static function flash_token( int $post_id, string $token ): void {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}
		set_transient(
			self::flash_key( $user_id, $post_id ),
			$token,
			self::TOKEN_FLASH_TTL
		);
	}

	/**
	 * Non-destructive read. The token stays in the transient (until it
	 * expires or the admin explicitly acknowledges) so refreshing the
	 * disclosure page keeps showing it.
	 */
	public static function peek_flashed_token( int $post_id ): ?string {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return null;
		}
		$value = get_transient( self::flash_key( $user_id, $post_id ) );
		if ( false === $value ) {
			return null;
		}
		return (string) $value;
	}

	/**
	 * Admin confirmed they've saved the token — purge the plaintext so
	 * subsequent refreshes can no longer reveal it.
	 */
	public static function acknowledge_token( int $post_id ): void {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}
		delete_transient( self::flash_key( $user_id, $post_id ) );
	}

	/**
	 * Legacy single-use accessor. Retained so older callers keep
	 * working; new code should pair `peek_flashed_token()` with
	 * `acknowledge_token()` so accidental refreshes aren't terminal.
	 */
	public static function pop_flashed_token( int $post_id ): ?string {
		$value = self::peek_flashed_token( $post_id );
		if ( null === $value ) {
			return null;
		}
		self::acknowledge_token( $post_id );
		return $value;
	}

	private static function flash_key( int $user_id, int $post_id ): string {
		return sprintf( '_openclawp_mcp_token_flash_%d_%d', $user_id, $post_id );
	}

	private static function set_token( int $post_id, string $plaintext ): void {
		update_post_meta( $post_id, self::META_TOKEN_HASH, wp_hash_password( $plaintext ) );
		update_post_meta( $post_id, self::META_TOKEN_LAST4, substr( $plaintext, -4 ) );
		self::flash_token( $post_id, $plaintext );
	}

	private static function generate_token(): string {
		// 40 characters, alphanumeric — long enough for high entropy,
		// short enough that admins can copy/paste comfortably.
		return 'op_' . wp_generate_password( 40, false, false );
	}
}
