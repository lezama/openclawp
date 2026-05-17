<?php
/**
 * Storage for OAuth 2.1 clients, authorization codes, and tokens.
 *
 * Backed by two CPTs:
 *
 *   - `openclawp_oauth_client` — one per registered OAuth client. Created
 *     manually or via Dynamic Client Registration (RFC 7591). Stores
 *     `client_id` (post_name), `client_secret_hash` (post-meta), redirect URIs,
 *     allowed scopes, MCP server slug binding, last-used timestamp.
 *
 *   - `openclawp_oauth_token` — one per access token (and one per pending
 *     authorization code, distinguished by `_openclawp_token_kind` meta).
 *     The opaque token value is stored ONLY as a SHA-256 hash; the plaintext
 *     is returned to the caller exactly once at issue time.
 *
 * Why CPTs and not custom tables? The existing MCP server store uses the same
 * pattern (`openclawp_mcp_server` CPT). Consistency > novelty.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Oauth_Store {

	public const POST_TYPE_CLIENT = 'openclawp_oauth_client';
	public const POST_TYPE_TOKEN  = 'openclawp_oauth_token';

	// Client meta keys.
	public const META_CLIENT_SECRET_HASH    = '_openclawp_oauth_client_secret_hash';
	public const META_CLIENT_REDIRECTS      = '_openclawp_oauth_redirect_uris';
	public const META_CLIENT_SCOPES         = '_openclawp_oauth_allowed_scopes';
	public const META_CLIENT_MCP_SERVER     = '_openclawp_oauth_mcp_server_slug';
	public const META_CLIENT_NAME           = '_openclawp_oauth_client_name';
	public const META_CLIENT_TOKEN_ENDPOINT_AUTH = '_openclawp_oauth_token_endpoint_auth_method';
	public const META_CLIENT_LAST_USED      = '_openclawp_oauth_client_last_used';
	public const META_CLIENT_CREATED_VIA    = '_openclawp_oauth_client_created_via';

	// Token meta keys.
	public const META_TOKEN_KIND       = '_openclawp_token_kind';        // 'code' | 'access'.
	public const META_TOKEN_CLIENT_ID  = '_openclawp_token_client_id';
	public const META_TOKEN_USER_ID    = '_openclawp_token_user_id';
	public const META_TOKEN_SCOPES     = '_openclawp_token_scopes';
	public const META_TOKEN_MCP_SERVER = '_openclawp_token_mcp_server_slug';
	public const META_TOKEN_EXPIRES_AT = '_openclawp_token_expires_at';
	public const META_TOKEN_LAST_USED  = '_openclawp_token_last_used';
	public const META_TOKEN_REVOKED    = '_openclawp_token_revoked';
	public const META_TOKEN_REDIRECT   = '_openclawp_token_redirect_uri';
	public const META_TOKEN_CODE_CHALLENGE        = '_openclawp_token_code_challenge';
	public const META_TOKEN_CODE_CHALLENGE_METHOD = '_openclawp_token_code_challenge_method';
	public const META_TOKEN_VALUE_LAST8 = '_openclawp_token_last8';

	public const KIND_CODE   = 'code';
	public const KIND_ACCESS = 'access';

	public const CODE_TTL    = 600;    // 10 minutes — RFC 6749 §4.1.2 recommends short-lived.
	public const ACCESS_TTL  = 3600;   // 1 hour. Refresh tokens deferred to follow-up.

	public static function register_post_types(): void {
		register_post_type(
			self::POST_TYPE_CLIENT,
			array(
				'labels'              => array(
					'name'          => __( 'openclaWP OAuth Clients', 'openclawp' ),
					'singular_name' => __( 'openclaWP OAuth Client', 'openclawp' ),
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

		register_post_type(
			self::POST_TYPE_TOKEN,
			array(
				'labels'              => array(
					'name'          => __( 'openclaWP OAuth Tokens', 'openclawp' ),
					'singular_name' => __( 'openclaWP OAuth Token', 'openclawp' ),
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
	 * Hash an opaque token value. SHA-256 over the value — fast and collision-
	 * resistant for the random 40-byte values issued by `issue_*`. Constant-
	 * time comparison happens at verify time via `hash_equals()`.
	 */
	public static function hash_token( string $plaintext ): string {
		return hash( 'sha256', $plaintext );
	}

	// -----------------------------------------------------------------------
	// Clients
	// -----------------------------------------------------------------------

	/**
	 * Create an OAuth client. Returns `[client_id, client_secret]` — the
	 * secret is shown to the caller exactly once. Only the hash persists.
	 *
	 * @param array{
	 *     client_name: string,
	 *     redirect_uris: array<int, string>,
	 *     allowed_scopes: array<int, string>,
	 *     mcp_server_slug: string,
	 *     token_endpoint_auth_method?: string,
	 *     created_via?: string
	 * } $args
	 *
	 * @return array{client_id:string, client_secret:string, post_id:int}|\WP_Error
	 */
	public static function create_client( array $args ) {
		$client_name   = trim( (string) ( $args['client_name'] ?? '' ) );
		$redirects     = array_values(
			array_filter(
				array_map( 'esc_url_raw', (array) ( $args['redirect_uris'] ?? array() ) ),
				static fn( string $u ): bool => '' !== $u
			)
		);
		$scopes        = OpenclaWP_Oauth_Scope::parse_scope_string(
			implode( ' ', (array) ( $args['allowed_scopes'] ?? array() ) )
		);
		$mcp_server    = sanitize_title( (string) ( $args['mcp_server_slug'] ?? '' ) );
		$auth_method   = sanitize_key( (string) ( $args['token_endpoint_auth_method'] ?? 'client_secret_basic' ) );
		$created_via   = sanitize_key( (string) ( $args['created_via'] ?? 'admin' ) );

		if ( '' === $client_name ) {
			return new \WP_Error( 'invalid_client_metadata', 'client_name is required' );
		}
		if ( empty( $redirects ) ) {
			return new \WP_Error( 'invalid_redirect_uri', 'at least one redirect_uri is required' );
		}
		if ( empty( $scopes ) ) {
			return new \WP_Error( 'invalid_scope', 'at least one valid scope (mcp:read|mcp:write|mcp:destructive|mcp:external) is required' );
		}
		if ( '' === $mcp_server ) {
			return new \WP_Error( 'invalid_client_metadata', 'mcp_server_slug is required' );
		}

		$client_id     = 'op_client_' . wp_generate_password( 24, false, false );
		$client_secret = 'op_secret_' . wp_generate_password( 40, false, false );

		$post_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE_CLIENT,
				'post_status' => 'publish',
				'post_title'  => $client_name,
				'post_name'   => $client_id,
				'post_author' => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( (int) $post_id, self::META_CLIENT_NAME, $client_name );
		update_post_meta( (int) $post_id, self::META_CLIENT_SECRET_HASH, self::hash_token( $client_secret ) );
		update_post_meta( (int) $post_id, self::META_CLIENT_REDIRECTS, $redirects );
		update_post_meta( (int) $post_id, self::META_CLIENT_SCOPES, $scopes );
		update_post_meta( (int) $post_id, self::META_CLIENT_MCP_SERVER, $mcp_server );
		update_post_meta( (int) $post_id, self::META_CLIENT_TOKEN_ENDPOINT_AUTH, $auth_method );
		update_post_meta( (int) $post_id, self::META_CLIENT_CREATED_VIA, $created_via );

		return array(
			'post_id'       => (int) $post_id,
			'client_id'     => $client_id,
			'client_secret' => $client_secret,
		);
	}

	public static function find_client( string $client_id ): ?\WP_Post {
		$client_id = sanitize_title( $client_id );
		if ( '' === $client_id ) {
			return null;
		}
		$matches = get_posts(
			array(
				'post_type'      => self::POST_TYPE_CLIENT,
				'post_status'    => array( 'publish' ),
				'name'           => $client_id,
				'posts_per_page' => 1,
				'no_found_rows'  => true,
			)
		);
		return $matches[0] ?? null;
	}

	public static function verify_client_secret( \WP_Post $client, string $presented_secret ): bool {
		if ( '' === $presented_secret ) {
			return false;
		}
		$hash = (string) get_post_meta( $client->ID, self::META_CLIENT_SECRET_HASH, true );
		if ( '' === $hash ) {
			return false;
		}
		return hash_equals( $hash, self::hash_token( $presented_secret ) );
	}

	/**
	 * @return array<int, \WP_Post>
	 */
	public static function all_clients(): array {
		return (array) get_posts(
			array(
				'post_type'      => self::POST_TYPE_CLIENT,
				'post_status'    => array( 'publish' ),
				'posts_per_page' => 200,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);
	}

	public static function delete_client( int $post_id ): bool {
		// Revoke all of this client's tokens too.
		$tokens = get_posts(
			array(
				'post_type'      => self::POST_TYPE_TOKEN,
				'post_status'    => array( 'publish' ),
				'posts_per_page' => 500,
				'meta_key'       => self::META_TOKEN_CLIENT_ID,
				'meta_value'     => self::client_id( get_post( $post_id ) ),
				'no_found_rows'  => true,
				'fields'         => 'ids',
			)
		);
		foreach ( (array) $tokens as $tok_id ) {
			wp_delete_post( (int) $tok_id, true );
		}
		return false !== wp_delete_post( $post_id, true );
	}

	public static function client_id( ?\WP_Post $client ): string {
		return null === $client ? '' : (string) $client->post_name;
	}

	/**
	 * @return array<int, string>
	 */
	public static function client_redirect_uris( \WP_Post $client ): array {
		$raw = get_post_meta( $client->ID, self::META_CLIENT_REDIRECTS, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		return array_values( array_map( 'strval', $raw ) );
	}

	/**
	 * @return array<int, string>
	 */
	public static function client_allowed_scopes( \WP_Post $client ): array {
		$raw = get_post_meta( $client->ID, self::META_CLIENT_SCOPES, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		return array_values( array_map( 'strval', $raw ) );
	}

	public static function client_mcp_server_slug( \WP_Post $client ): string {
		return (string) get_post_meta( $client->ID, self::META_CLIENT_MCP_SERVER, true );
	}

	public static function touch_client( \WP_Post $client ): void {
		update_post_meta( $client->ID, self::META_CLIENT_LAST_USED, time() );
	}

	public static function client_last_used( \WP_Post $client ): int {
		return (int) get_post_meta( $client->ID, self::META_CLIENT_LAST_USED, true );
	}

	public static function client_redirect_allowed( \WP_Post $client, string $candidate ): bool {
		if ( '' === $candidate ) {
			return false;
		}
		$allowed = self::client_redirect_uris( $client );
		// Exact match per RFC 6749 §3.1.2.3 — no partial / prefix matching.
		return in_array( $candidate, $allowed, true );
	}

	// -----------------------------------------------------------------------
	// Codes & tokens
	// -----------------------------------------------------------------------

	/**
	 * Issue a short-lived authorization code. Returns the plaintext code; the
	 * hash is persisted. PKCE challenge + method are bound to the code.
	 */
	public static function issue_authorization_code(
		string $client_id,
		int $user_id,
		string $redirect_uri,
		array $scopes,
		string $mcp_server_slug,
		string $code_challenge,
		string $code_challenge_method
	): string {
		$code = 'op_code_' . wp_generate_password( 40, false, false );

		$post_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE_TOKEN,
				'post_status' => 'publish',
				'post_title'  => 'oauth code',
				'post_author' => $user_id,
			)
		);
		if ( is_wp_error( $post_id ) || 0 === (int) $post_id ) {
			return '';
		}
		$post_id = (int) $post_id;

		update_post_meta( $post_id, self::META_TOKEN_KIND, self::KIND_CODE );
		update_post_meta( $post_id, self::META_TOKEN_CLIENT_ID, $client_id );
		update_post_meta( $post_id, self::META_TOKEN_USER_ID, $user_id );
		update_post_meta( $post_id, self::META_TOKEN_SCOPES, array_values( $scopes ) );
		update_post_meta( $post_id, self::META_TOKEN_MCP_SERVER, $mcp_server_slug );
		update_post_meta( $post_id, self::META_TOKEN_REDIRECT, $redirect_uri );
		update_post_meta( $post_id, self::META_TOKEN_EXPIRES_AT, time() + self::CODE_TTL );
		update_post_meta( $post_id, self::META_TOKEN_CODE_CHALLENGE, $code_challenge );
		update_post_meta( $post_id, self::META_TOKEN_CODE_CHALLENGE_METHOD, $code_challenge_method );
		update_post_meta( $post_id, self::META_TOKEN_VALUE_LAST8, substr( $code, -8 ) );
		add_post_meta( $post_id, '_openclawp_token_value_hash', self::hash_token( $code ), true );

		return $code;
	}

	/**
	 * Find a stored token row by plaintext value & kind. Returns null if not
	 * found, expired, or revoked. Uses meta_query against the hash.
	 */
	public static function find_token_by_value( string $plaintext, string $kind ): ?\WP_Post {
		if ( '' === $plaintext ) {
			return null;
		}
		$hash = self::hash_token( $plaintext );

		$matches = get_posts(
			array(
				'post_type'      => self::POST_TYPE_TOKEN,
				'post_status'    => array( 'publish' ),
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => '_openclawp_token_value_hash',
						'value' => $hash,
					),
					array(
						'key'   => self::META_TOKEN_KIND,
						'value' => $kind,
					),
				),
			)
		);

		$post = $matches[0] ?? null;
		if ( null === $post ) {
			return null;
		}

		if ( self::is_revoked( $post ) ) {
			return null;
		}
		if ( self::is_expired( $post ) ) {
			return null;
		}

		return $post;
	}

	public static function consume_authorization_code( \WP_Post $code_post ): void {
		// One-time use — delete after redeem.
		wp_delete_post( $code_post->ID, true );
	}

	/**
	 * Issue an access token. Returns the plaintext token; only the hash persists.
	 */
	public static function issue_access_token(
		string $client_id,
		int $user_id,
		array $scopes,
		string $mcp_server_slug
	): array {
		$token = 'op_at_' . wp_generate_password( 48, false, false );

		$post_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE_TOKEN,
				'post_status' => 'publish',
				'post_title'  => 'oauth access token',
				'post_author' => $user_id,
			)
		);
		if ( is_wp_error( $post_id ) || 0 === (int) $post_id ) {
			return array(
				'token'      => '',
				'expires_in' => 0,
				'post_id'    => 0,
			);
		}
		$post_id    = (int) $post_id;
		$expires_at = time() + self::ACCESS_TTL;

		update_post_meta( $post_id, self::META_TOKEN_KIND, self::KIND_ACCESS );
		update_post_meta( $post_id, self::META_TOKEN_CLIENT_ID, $client_id );
		update_post_meta( $post_id, self::META_TOKEN_USER_ID, $user_id );
		update_post_meta( $post_id, self::META_TOKEN_SCOPES, array_values( $scopes ) );
		update_post_meta( $post_id, self::META_TOKEN_MCP_SERVER, $mcp_server_slug );
		update_post_meta( $post_id, self::META_TOKEN_EXPIRES_AT, $expires_at );
		update_post_meta( $post_id, self::META_TOKEN_VALUE_LAST8, substr( $token, -8 ) );
		add_post_meta( $post_id, '_openclawp_token_value_hash', self::hash_token( $token ), true );

		return array(
			'token'      => $token,
			'expires_in' => self::ACCESS_TTL,
			'post_id'    => $post_id,
		);
	}

	public static function touch_token( \WP_Post $token ): void {
		update_post_meta( $token->ID, self::META_TOKEN_LAST_USED, time() );
	}

	public static function revoke_token( int $post_id ): bool {
		update_post_meta( $post_id, self::META_TOKEN_REVOKED, 1 );
		return true;
	}

	public static function is_revoked( \WP_Post $token ): bool {
		return (bool) (int) get_post_meta( $token->ID, self::META_TOKEN_REVOKED, true );
	}

	public static function is_expired( \WP_Post $token ): bool {
		$expires = (int) get_post_meta( $token->ID, self::META_TOKEN_EXPIRES_AT, true );
		return $expires > 0 && $expires <= time();
	}

	/**
	 * @return array<int, string>
	 */
	public static function token_scopes( \WP_Post $token ): array {
		$raw = get_post_meta( $token->ID, self::META_TOKEN_SCOPES, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		return array_values( array_map( 'strval', $raw ) );
	}

	public static function token_client_id( \WP_Post $token ): string {
		return (string) get_post_meta( $token->ID, self::META_TOKEN_CLIENT_ID, true );
	}

	public static function token_user_id( \WP_Post $token ): int {
		return (int) get_post_meta( $token->ID, self::META_TOKEN_USER_ID, true );
	}

	public static function token_mcp_server_slug( \WP_Post $token ): string {
		return (string) get_post_meta( $token->ID, self::META_TOKEN_MCP_SERVER, true );
	}

	public static function token_redirect_uri( \WP_Post $token ): string {
		return (string) get_post_meta( $token->ID, self::META_TOKEN_REDIRECT, true );
	}

	public static function token_code_challenge( \WP_Post $token ): string {
		return (string) get_post_meta( $token->ID, self::META_TOKEN_CODE_CHALLENGE, true );
	}

	public static function token_code_challenge_method( \WP_Post $token ): string {
		return (string) get_post_meta( $token->ID, self::META_TOKEN_CODE_CHALLENGE_METHOD, true );
	}

	public static function token_last8( \WP_Post $token ): string {
		return (string) get_post_meta( $token->ID, self::META_TOKEN_VALUE_LAST8, true );
	}

	public static function token_last_used( \WP_Post $token ): int {
		return (int) get_post_meta( $token->ID, self::META_TOKEN_LAST_USED, true );
	}

	public static function tokens_for_client( string $client_id ): array {
		return (array) get_posts(
			array(
				'post_type'      => self::POST_TYPE_TOKEN,
				'post_status'    => array( 'publish' ),
				'posts_per_page' => 200,
				'no_found_rows'  => true,
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => self::META_TOKEN_CLIENT_ID,
						'value' => $client_id,
					),
					array(
						'key'   => self::META_TOKEN_KIND,
						'value' => self::KIND_ACCESS,
					),
				),
			)
		);
	}

	/**
	 * Verify a PKCE code_verifier against a stored challenge. Supports `S256`
	 * (RFC 7636 §4.2) and `plain`. OAuth 2.1 SHOULD require S256; we keep
	 * `plain` for symmetry with clients that downgrade in dev.
	 */
	public static function verify_pkce( string $verifier, string $challenge, string $method ): bool {
		if ( '' === $verifier || '' === $challenge ) {
			return false;
		}
		$method = '' === $method ? 'S256' : strtoupper( $method );
		if ( 'PLAIN' === $method ) {
			return hash_equals( $challenge, $verifier );
		}
		if ( 'S256' === $method ) {
			$derived = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
			return hash_equals( $challenge, $derived );
		}
		return false;
	}
}
