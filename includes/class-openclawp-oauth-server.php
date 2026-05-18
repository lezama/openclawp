<?php
/**
 * OAuth 2.1 server for the MCP endpoint.
 *
 * Endpoints (all under /wp-json/openclawp/v1/oauth/):
 *
 *   - GET  authorize       — interactive: redirects the user to the consent
 *                            screen, then back to the client's redirect_uri
 *                            with `?code=…`.
 *   - POST token           — exchange `code` (with PKCE verifier) for an
 *                            access token. Confidential clients also send
 *                            HTTP Basic creds; public clients use PKCE only.
 *   - POST introspect      — RFC 7662 token introspection. Returns
 *                            `{active, scope, client_id, exp, …}`.
 *   - POST revoke          — RFC 7009 token revocation.
 *   - POST register        — RFC 7591 Dynamic Client Registration.
 *
 * Plus the discovery document:
 *
 *   - GET  /wp-json/openclawp/v1/.well-known/oauth-authorization-server
 *
 * The MCP server's authorization layer (`OpenclaWP_Mcp_Rest`) validates the
 * bearer at request time using `OpenclaWP_Oauth_Store::find_token_by_value()`
 * — this class doesn't get in that hot path.
 *
 * Why not league/oauth2-server? It's ~30 PHP files and pulls a Symfony
 * crypto dep tree (psr/http-message, psr/http-server-handler, defuse/php-encryption,
 * lcobucci/jwt — ~12 transitive packages, ~1.5MB). We need a narrow slice:
 * authorization_code + PKCE + DCR + introspection. Custom code keeps the
 * vendor weight down and avoids coupling MCP auth to a moving upstream API
 * surface. See PR body for the writeup.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Oauth_Server {

	public const NAMESPACE = 'openclawp/v1';

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'admin_post_openclawp_oauth_consent', array( __CLASS__, 'handle_consent_submit' ) );
		add_action( 'admin_post_nopriv_openclawp_oauth_consent', array( __CLASS__, 'handle_consent_submit' ) );
		add_action( 'admin_action_openclawp_oauth_consent_view', array( __CLASS__, 'render_consent_screen' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/oauth/authorize',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_authorize' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/oauth/token',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_token' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/oauth/introspect',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_introspect' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/oauth/revoke',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_revoke' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/oauth/register',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_register' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/.well-known/oauth-authorization-server',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_discovery' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	// -----------------------------------------------------------------------
	// .well-known/oauth-authorization-server
	// -----------------------------------------------------------------------

	public static function handle_discovery( \WP_REST_Request $request ) {
		$base = rest_url( self::NAMESPACE . '/oauth' );
		return new \WP_REST_Response(
			array(
				'issuer'                                      => home_url( '/' ),
				'authorization_endpoint'                      => esc_url_raw( $base . '/authorize' ),
				'token_endpoint'                              => esc_url_raw( $base . '/token' ),
				'introspection_endpoint'                      => esc_url_raw( $base . '/introspect' ),
				'revocation_endpoint'                         => esc_url_raw( $base . '/revoke' ),
				'registration_endpoint'                       => esc_url_raw( $base . '/register' ),
				'response_types_supported'                    => array( 'code' ),
				'grant_types_supported'                       => array( 'authorization_code' ),
				'code_challenge_methods_supported'            => array( 'S256', 'plain' ),
				'token_endpoint_auth_methods_supported'       => array( 'client_secret_basic', 'client_secret_post', 'none' ),
				'scopes_supported'                            => OpenclaWP_Oauth_Scope::all_scopes(),
			),
			200
		);
	}

	// -----------------------------------------------------------------------
	// GET /authorize — interactive consent
	// -----------------------------------------------------------------------

	public static function handle_authorize( \WP_REST_Request $request ) {
		$response_type = (string) $request->get_param( 'response_type' );
		$client_id     = (string) $request->get_param( 'client_id' );
		$redirect_uri  = (string) $request->get_param( 'redirect_uri' );
		$scope_str     = (string) $request->get_param( 'scope' );
		$state         = (string) $request->get_param( 'state' );
		$code_chal     = (string) $request->get_param( 'code_challenge' );
		$code_method   = (string) $request->get_param( 'code_challenge_method' );
		$resource      = (string) $request->get_param( 'resource' ); // RFC 8707 — informational here.

		if ( 'code' !== $response_type ) {
			return self::error_redirect( $redirect_uri, 'unsupported_response_type', 'only response_type=code is supported', $state );
		}

		$client = OpenclaWP_Oauth_Store::find_client( $client_id );
		if ( null === $client ) {
			// Per RFC 6749 §4.1.2.1: do NOT redirect for invalid client_id; show an error directly.
			return new \WP_REST_Response( array( 'error' => 'invalid_client' ), 400 );
		}

		if ( ! OpenclaWP_Oauth_Store::client_redirect_allowed( $client, $redirect_uri ) ) {
			return new \WP_REST_Response( array( 'error' => 'invalid_redirect_uri' ), 400 );
		}

		// Scope intersection — narrow the requested scope to what the client may use.
		$requested_scopes = OpenclaWP_Oauth_Scope::parse_scope_string( $scope_str );
		$allowed_scopes   = OpenclaWP_Oauth_Store::client_allowed_scopes( $client );
		$effective_scopes = array_values( array_intersect( $requested_scopes, $allowed_scopes ) );
		if ( empty( $effective_scopes ) ) {
			return self::error_redirect( $redirect_uri, 'invalid_scope', 'requested scope not in client\'s allowed scopes', $state );
		}

		if ( '' === $code_chal ) {
			return self::error_redirect( $redirect_uri, 'invalid_request', 'PKCE code_challenge is required', $state );
		}
		if ( '' === $code_method ) {
			$code_method = 'S256';
		}
		$code_method = strtoupper( $code_method );
		if ( ! in_array( $code_method, array( 'S256', 'PLAIN' ), true ) ) {
			return self::error_redirect( $redirect_uri, 'invalid_request', 'unsupported code_challenge_method', $state );
		}

		// Force login. If not logged in, redirect to wp-login.php with a redirect_to back to authorize.
		if ( ! is_user_logged_in() ) {
			$self = add_query_arg(
				array_filter(
					array(
						'response_type'         => 'code',
						'client_id'             => $client_id,
						'redirect_uri'          => $redirect_uri,
						'scope'                 => implode( ' ', $effective_scopes ),
						'state'                 => $state,
						'code_challenge'        => $code_chal,
						'code_challenge_method' => $code_method,
						'resource'              => $resource,
					),
					static fn( $v ): bool => '' !== $v && null !== $v
				),
				rest_url( self::NAMESPACE . '/oauth/authorize' )
			);
			$login = wp_login_url( $self );
			return new \WP_REST_Response( null, 302, array( 'Location' => $login ) );
		}

		// Redirect the user agent to the consent screen, which is a wp-admin page.
		$consent_url = add_query_arg(
			array_filter(
				array(
					'action'                => 'openclawp_oauth_consent_view',
					'client_id'             => $client_id,
					'redirect_uri'          => rawurlencode( $redirect_uri ),
					'scope'                 => rawurlencode( implode( ' ', $effective_scopes ) ),
					'state'                 => rawurlencode( $state ),
					'code_challenge'        => rawurlencode( $code_chal ),
					'code_challenge_method' => $code_method,
					'resource'              => rawurlencode( $resource ),
				),
				static fn( $v ): bool => '' !== $v && null !== $v
			),
			admin_url( 'admin.php' )
		);

		return new \WP_REST_Response( null, 302, array( 'Location' => $consent_url ) );
	}

	// -----------------------------------------------------------------------
	// Consent screen — rendered out-of-band via admin.php?action=...
	// -----------------------------------------------------------------------

	public static function render_consent_screen(): void {
		if ( ! is_user_logged_in() ) {
			$login = wp_login_url( ( is_ssl() ? 'https://' : 'http://' ) . ( $_SERVER['HTTP_HOST'] ?? '' ) . ( $_SERVER['REQUEST_URI'] ?? '' ) );
			wp_safe_redirect( $login );
			exit;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$client_id    = isset( $_GET['client_id'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['client_id'] ) ) : '';
		$redirect_uri = isset( $_GET['redirect_uri'] ) ? esc_url_raw( wp_unslash( (string) $_GET['redirect_uri'] ) ) : '';
		$scope_str    = isset( $_GET['scope'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['scope'] ) ) : '';
		$state        = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['state'] ) ) : '';
		$code_chal    = isset( $_GET['code_challenge'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['code_challenge'] ) ) : '';
		$code_method  = isset( $_GET['code_challenge_method'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['code_challenge_method'] ) ) : 'S256';
		// phpcs:enable

		$client = OpenclaWP_Oauth_Store::find_client( $client_id );
		if ( null === $client ) {
			wp_die( esc_html__( 'OAuth client not found.', 'openclawp' ), '', array( 'response' => 400 ) );
		}

		$scopes      = OpenclaWP_Oauth_Scope::parse_scope_string( $scope_str );
		$server_slug = OpenclaWP_Oauth_Store::client_mcp_server_slug( $client );
		$client_name = (string) $client->post_title;

		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<title><?php esc_html_e( 'openclaWP — Authorize client', 'openclawp' ); ?></title>
			<style>
				body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; max-width: 480px; margin: 4rem auto; padding: 2rem; background: #f6f7f7; }
				.card { background: #fff; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,.08); }
				h1 { margin: 0 0 1rem; font-size: 1.5rem; }
				ul.scopes { list-style: none; padding: 0; }
				ul.scopes li { padding: .5rem .75rem; background: #f0f0f1; margin: .25rem 0; border-radius: 4px; font-family: ui-monospace, monospace; }
				.row { display: flex; gap: .5rem; margin-top: 1.5rem; }
				button { flex: 1; padding: .75rem 1rem; font-size: 1rem; border-radius: 4px; cursor: pointer; border: 1px solid transparent; }
				button.allow { background: #2271b1; color: #fff; border-color: #2271b1; }
				button.deny  { background: #fff; color: #b32d2e; border-color: #b32d2e; }
				code { word-break: break-all; }
			</style>
		</head>
		<body>
			<div class="card">
				<h1><?php esc_html_e( 'Authorize this client?', 'openclawp' ); ?></h1>
				<p>
					<?php
					/* translators: %s is the client name. */
					echo esc_html( sprintf( __( '%s is requesting access to your openclaWP MCP server.', 'openclawp' ), $client_name ) );
					?>
				</p>
				<p>
					<strong><?php esc_html_e( 'MCP server:', 'openclawp' ); ?></strong>
					<code><?php echo esc_html( $server_slug ); ?></code>
				</p>
				<p><strong><?php esc_html_e( 'Requested scopes:', 'openclawp' ); ?></strong></p>
				<ul class="scopes">
					<?php foreach ( $scopes as $scope ) : ?>
						<li><?php echo esc_html( $scope ); ?> — <?php echo esc_html( self::scope_blurb( $scope ) ); ?></li>
					<?php endforeach; ?>
				</ul>
				<form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="openclawp_oauth_consent" />
					<?php wp_nonce_field( 'openclawp_oauth_consent' ); ?>
					<input type="hidden" name="client_id" value="<?php echo esc_attr( $client_id ); ?>" />
					<input type="hidden" name="redirect_uri" value="<?php echo esc_attr( $redirect_uri ); ?>" />
					<input type="hidden" name="scope" value="<?php echo esc_attr( implode( ' ', $scopes ) ); ?>" />
					<input type="hidden" name="state" value="<?php echo esc_attr( $state ); ?>" />
					<input type="hidden" name="code_challenge" value="<?php echo esc_attr( $code_chal ); ?>" />
					<input type="hidden" name="code_challenge_method" value="<?php echo esc_attr( $code_method ); ?>" />
					<div class="row">
						<button type="submit" name="decision" value="deny" class="deny"><?php esc_html_e( 'Deny', 'openclawp' ); ?></button>
						<button type="submit" name="decision" value="allow" class="allow"><?php esc_html_e( 'Allow', 'openclawp' ); ?></button>
					</div>
				</form>
			</div>
		</body>
		</html>
		<?php
		exit;
	}

	private static function scope_blurb( string $scope ): string {
		switch ( $scope ) {
			case OpenclaWP_Oauth_Scope::SCOPE_READ:
				return __( 'read-only abilities', 'openclawp' );
			case OpenclaWP_Oauth_Scope::SCOPE_WRITE:
				return __( 'read + write abilities', 'openclawp' );
			case OpenclaWP_Oauth_Scope::SCOPE_DESTRUCTIVE:
				return __( 'read + write + destructive abilities', 'openclawp' );
			case OpenclaWP_Oauth_Scope::SCOPE_EXTERNAL:
				return __( 'everything, including calls to external services', 'openclawp' );
		}
		return $scope;
	}

	public static function handle_consent_submit(): void {
		check_admin_referer( 'openclawp_oauth_consent' );

		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'You must be logged in to authorize an OAuth client.', 'openclawp' ), '', array( 'response' => 401 ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- check_admin_referer above.
		$client_id    = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['client_id'] ) ) : '';
		$redirect_uri = isset( $_POST['redirect_uri'] ) ? esc_url_raw( wp_unslash( (string) $_POST['redirect_uri'] ) ) : '';
		$scope_str    = isset( $_POST['scope'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['scope'] ) ) : '';
		$state        = isset( $_POST['state'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['state'] ) ) : '';
		$code_chal    = isset( $_POST['code_challenge'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['code_challenge'] ) ) : '';
		$code_method  = isset( $_POST['code_challenge_method'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['code_challenge_method'] ) ) : 'S256';
		$decision     = isset( $_POST['decision'] ) ? sanitize_key( (string) $_POST['decision'] ) : 'deny';
		// phpcs:enable

		$client = OpenclaWP_Oauth_Store::find_client( $client_id );
		if ( null === $client ) {
			wp_die( esc_html__( 'OAuth client not found.', 'openclawp' ), '', array( 'response' => 400 ) );
		}
		if ( ! OpenclaWP_Oauth_Store::client_redirect_allowed( $client, $redirect_uri ) ) {
			wp_die( esc_html__( 'redirect_uri does not match a registered URI for this client.', 'openclawp' ), '', array( 'response' => 400 ) );
		}

		if ( 'allow' !== $decision ) {
			$url = add_query_arg(
				array_filter(
					array(
						'error'             => 'access_denied',
						'error_description' => 'user denied consent',
						'state'             => $state,
					),
					static fn( $v ): bool => '' !== $v && null !== $v
				),
				$redirect_uri
			);
			wp_redirect( $url );
			exit;
		}

		$scopes = OpenclaWP_Oauth_Scope::parse_scope_string( $scope_str );
		$server_slug = OpenclaWP_Oauth_Store::client_mcp_server_slug( $client );

		$code = OpenclaWP_Oauth_Store::issue_authorization_code(
			$client_id,
			get_current_user_id(),
			$redirect_uri,
			$scopes,
			$server_slug,
			$code_chal,
			$code_method
		);

		$url = add_query_arg(
			array_filter(
				array(
					'code'  => $code,
					'state' => $state,
				),
				static fn( $v ): bool => '' !== $v && null !== $v
			),
			$redirect_uri
		);
		wp_redirect( $url );
		exit;
	}

	// -----------------------------------------------------------------------
	// POST /token
	// -----------------------------------------------------------------------

	public static function handle_token( \WP_REST_Request $request ) {
		$grant_type    = (string) $request->get_param( 'grant_type' );
		$code          = (string) $request->get_param( 'code' );
		$redirect_uri  = (string) $request->get_param( 'redirect_uri' );
		$client_id     = (string) $request->get_param( 'client_id' );
		$code_verifier = (string) $request->get_param( 'code_verifier' );
		$client_secret = (string) $request->get_param( 'client_secret' );

		// HTTP Basic for confidential clients.
		$auth_header = (string) $request->get_header( 'authorization' );
		if ( 0 === stripos( $auth_header, 'Basic ' ) ) {
			$decoded = base64_decode( trim( substr( $auth_header, 6 ) ), true );
			if ( is_string( $decoded ) && false !== strpos( $decoded, ':' ) ) {
				list( $basic_id, $basic_secret ) = explode( ':', $decoded, 2 );
				if ( '' === $client_id ) {
					$client_id = $basic_id;
				}
				if ( '' === $client_secret ) {
					$client_secret = $basic_secret;
				}
			}
		}

		if ( 'authorization_code' !== $grant_type ) {
			return self::token_error( 'unsupported_grant_type', 'only authorization_code is supported in v1' );
		}

		$client = OpenclaWP_Oauth_Store::find_client( $client_id );
		if ( null === $client ) {
			return self::token_error( 'invalid_client', 'unknown client_id' );
		}

		$auth_method = (string) get_post_meta( $client->ID, OpenclaWP_Oauth_Store::META_CLIENT_TOKEN_ENDPOINT_AUTH, true );
		if ( 'none' !== $auth_method ) {
			if ( ! OpenclaWP_Oauth_Store::verify_client_secret( $client, $client_secret ) ) {
				return self::token_error( 'invalid_client', 'client authentication failed' );
			}
		}

		$code_post = OpenclaWP_Oauth_Store::find_token_by_value( $code, OpenclaWP_Oauth_Store::KIND_CODE );
		if ( null === $code_post ) {
			return self::token_error( 'invalid_grant', 'authorization code invalid, expired, or already redeemed' );
		}

		if ( OpenclaWP_Oauth_Store::token_client_id( $code_post ) !== $client_id ) {
			OpenclaWP_Oauth_Store::consume_authorization_code( $code_post );
			return self::token_error( 'invalid_grant', 'code was issued to a different client' );
		}
		if ( OpenclaWP_Oauth_Store::token_redirect_uri( $code_post ) !== $redirect_uri ) {
			OpenclaWP_Oauth_Store::consume_authorization_code( $code_post );
			return self::token_error( 'invalid_grant', 'redirect_uri mismatch' );
		}

		$challenge        = OpenclaWP_Oauth_Store::token_code_challenge( $code_post );
		$challenge_method = OpenclaWP_Oauth_Store::token_code_challenge_method( $code_post );
		if ( ! OpenclaWP_Oauth_Store::verify_pkce( $code_verifier, $challenge, $challenge_method ) ) {
			OpenclaWP_Oauth_Store::consume_authorization_code( $code_post );
			return self::token_error( 'invalid_grant', 'PKCE verification failed' );
		}

		$scopes      = OpenclaWP_Oauth_Store::token_scopes( $code_post );
		$server_slug = (string) get_post_meta( $code_post->ID, OpenclaWP_Oauth_Store::META_TOKEN_MCP_SERVER, true );
		$user_id     = OpenclaWP_Oauth_Store::token_user_id( $code_post );

		OpenclaWP_Oauth_Store::consume_authorization_code( $code_post );

		$issued = OpenclaWP_Oauth_Store::issue_access_token( $client_id, $user_id, $scopes, $server_slug );
		OpenclaWP_Oauth_Store::touch_client( $client );

		return new \WP_REST_Response(
			array(
				'access_token' => $issued['token'],
				'token_type'   => 'Bearer',
				'expires_in'   => $issued['expires_in'],
				'scope'        => implode( ' ', $scopes ),
			),
			200,
			array( 'Cache-Control' => 'no-store', 'Pragma' => 'no-cache' )
		);
	}

	// -----------------------------------------------------------------------
	// POST /introspect (RFC 7662)
	// -----------------------------------------------------------------------

	public static function handle_introspect( \WP_REST_Request $request ) {
		$presented = (string) $request->get_param( 'token' );

		// Caller must auth as a confidential client. Reuse Basic creds from the token endpoint.
		$auth_header   = (string) $request->get_header( 'authorization' );
		$client_id     = '';
		$client_secret = '';
		if ( 0 === stripos( $auth_header, 'Basic ' ) ) {
			$decoded = base64_decode( trim( substr( $auth_header, 6 ) ), true );
			if ( is_string( $decoded ) && false !== strpos( $decoded, ':' ) ) {
				list( $client_id, $client_secret ) = explode( ':', $decoded, 2 );
			}
		}
		if ( '' === $client_id ) {
			$client_id     = (string) $request->get_param( 'client_id' );
			$client_secret = (string) $request->get_param( 'client_secret' );
		}

		$client = OpenclaWP_Oauth_Store::find_client( $client_id );
		if ( null === $client ) {
			return new \WP_REST_Response( array( 'active' => false ), 200 );
		}
		$auth_method = (string) get_post_meta( $client->ID, OpenclaWP_Oauth_Store::META_CLIENT_TOKEN_ENDPOINT_AUTH, true );
		if ( 'none' !== $auth_method ) {
			if ( ! OpenclaWP_Oauth_Store::verify_client_secret( $client, $client_secret ) ) {
				return new \WP_REST_Response( array( 'active' => false ), 200 );
			}
		}

		$token = OpenclaWP_Oauth_Store::find_token_by_value( $presented, OpenclaWP_Oauth_Store::KIND_ACCESS );
		if ( null === $token ) {
			return new \WP_REST_Response( array( 'active' => false ), 200 );
		}

		return new \WP_REST_Response(
			array(
				'active'     => true,
				'scope'      => implode( ' ', OpenclaWP_Oauth_Store::token_scopes( $token ) ),
				'client_id'  => OpenclaWP_Oauth_Store::token_client_id( $token ),
				'username'   => self::username_for( OpenclaWP_Oauth_Store::token_user_id( $token ) ),
				'token_type' => 'Bearer',
				'exp'        => (int) get_post_meta( $token->ID, OpenclaWP_Oauth_Store::META_TOKEN_EXPIRES_AT, true ),
				'aud'        => OpenclaWP_Oauth_Store::token_mcp_server_slug( $token ),
			),
			200
		);
	}

	private static function username_for( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '';
		}
		$user = get_userdata( $user_id );
		return $user ? (string) $user->user_login : '';
	}

	// -----------------------------------------------------------------------
	// POST /revoke (RFC 7009)
	// -----------------------------------------------------------------------

	public static function handle_revoke( \WP_REST_Request $request ) {
		$presented = (string) $request->get_param( 'token' );

		$token = OpenclaWP_Oauth_Store::find_token_by_value( $presented, OpenclaWP_Oauth_Store::KIND_ACCESS );
		if ( null !== $token ) {
			OpenclaWP_Oauth_Store::revoke_token( $token->ID );
		}
		// Per RFC 7009: always 200, never leak whether the token existed.
		return new \WP_REST_Response( null, 200 );
	}

	// -----------------------------------------------------------------------
	// POST /register (RFC 7591 Dynamic Client Registration)
	// -----------------------------------------------------------------------

	public static function handle_register( \WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new \WP_REST_Response( array( 'error' => 'invalid_client_metadata' ), 400 );
		}

		$redirects = array();
		foreach ( (array) ( $body['redirect_uris'] ?? array() ) as $uri ) {
			if ( is_string( $uri ) && '' !== $uri ) {
				$redirects[] = esc_url_raw( $uri );
			}
		}
		if ( empty( $redirects ) ) {
			return new \WP_REST_Response( array( 'error' => 'invalid_redirect_uri' ), 400 );
		}

		$client_name = isset( $body['client_name'] ) ? sanitize_text_field( (string) $body['client_name'] ) : 'DCR client';
		$scope_str   = isset( $body['scope'] ) ? sanitize_text_field( (string) $body['scope'] ) : OpenclaWP_Oauth_Scope::SCOPE_READ;
		$scopes      = OpenclaWP_Oauth_Scope::parse_scope_string( $scope_str );
		if ( empty( $scopes ) ) {
			$scopes = array( OpenclaWP_Oauth_Scope::SCOPE_READ );
		}

		/**
		 * Filter the upper bound on scopes a DCR client may request.
		 * Defaults to `mcp:read` and `mcp:write` only — never destructive or
		 * external — to keep self-service registration safe by default.
		 * Site owners can widen this via the admin UI per-client.
		 *
		 * @param array<int,string> $allowed_dcr_scopes
		 */
		$allowed_dcr_scopes = (array) apply_filters(
			'openclawp_oauth_allowed_dcr_scopes',
			array( OpenclaWP_Oauth_Scope::SCOPE_READ, OpenclaWP_Oauth_Scope::SCOPE_WRITE )
		);
		$scopes = array_values( array_intersect( $scopes, $allowed_dcr_scopes ) );
		if ( empty( $scopes ) ) {
			return new \WP_REST_Response( array( 'error' => 'invalid_scope' ), 400 );
		}

		$mcp_server_slug = isset( $body['mcp_server_slug'] )
			? sanitize_title( (string) $body['mcp_server_slug'] )
			: self::default_mcp_server_slug();

		if ( '' === $mcp_server_slug ) {
			return new \WP_REST_Response(
				array(
					'error'             => 'invalid_client_metadata',
					'error_description' => 'no MCP server is registered; create one in openclaWP -> MCP Servers first',
				),
				400
			);
		}

		$auth_method = 'none';
		if ( isset( $body['token_endpoint_auth_method'] ) ) {
			$method = sanitize_key( (string) $body['token_endpoint_auth_method'] );
			if ( in_array( $method, array( 'none', 'client_secret_basic', 'client_secret_post' ), true ) ) {
				$auth_method = $method;
			}
		}

		$created = OpenclaWP_Oauth_Store::create_client(
			array(
				'client_name'                => $client_name,
				'redirect_uris'              => $redirects,
				'allowed_scopes'             => $scopes,
				'mcp_server_slug'            => $mcp_server_slug,
				'token_endpoint_auth_method' => $auth_method,
				'created_via'                => 'dcr',
			)
		);
		if ( is_wp_error( $created ) ) {
			return new \WP_REST_Response(
				array(
					'error'             => 'invalid_client_metadata',
					'error_description' => $created->get_error_message(),
				),
				400
			);
		}

		$response = array(
			'client_id'                  => $created['client_id'],
			'client_id_issued_at'        => time(),
			'client_name'                => $client_name,
			'redirect_uris'              => $redirects,
			'scope'                      => implode( ' ', $scopes ),
			'token_endpoint_auth_method' => $auth_method,
			'grant_types'                => array( 'authorization_code' ),
			'response_types'             => array( 'code' ),
		);
		if ( 'none' !== $auth_method ) {
			$response['client_secret']            = $created['client_secret'];
			$response['client_secret_expires_at'] = 0;
		}

		return new \WP_REST_Response( $response, 201 );
	}

	private static function default_mcp_server_slug(): string {
		$servers = OpenclaWP_Mcp_Server_Store::all();
		foreach ( $servers as $server ) {
			if ( OpenclaWP_Mcp_Server_Store::is_enabled( $server ) ) {
				return (string) $server->post_name;
			}
		}
		return '';
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	private static function token_error( string $code, string $description ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'error'             => $code,
				'error_description' => $description,
			),
			400,
			array( 'Cache-Control' => 'no-store', 'Pragma' => 'no-cache' )
		);
	}

	private static function error_redirect( string $redirect_uri, string $error, string $description, string $state ): \WP_REST_Response {
		if ( '' === $redirect_uri ) {
			return new \WP_REST_Response( array( 'error' => $error, 'error_description' => $description ), 400 );
		}
		$url = add_query_arg(
			array_filter(
				array(
					'error'             => $error,
					'error_description' => $description,
					'state'             => $state,
				),
				static fn( $v ): bool => '' !== $v && null !== $v
			),
			$redirect_uri
		);
		return new \WP_REST_Response( null, 302, array( 'Location' => $url ) );
	}
}
