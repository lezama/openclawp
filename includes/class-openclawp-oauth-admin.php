<?php
/**
 * wp-admin -> openclaWP -> Connected Clients.
 *
 * Lists registered OAuth clients (manually created + DCR-self-registered),
 * shows allowed scopes, MCP server binding, last-used. Per-row actions:
 * delete client (revokes all of its tokens), revoke a specific access token.
 * Pattern mirrors `OpenclaWP_Mcp_Admin`.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Oauth_Admin {

	public const PAGE_SLUG = 'openclawp-connected-clients';

	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_submenu' ), 21 );
		add_action( 'admin_init', array( __CLASS__, 'handle_post' ) );
	}

	public static function register_submenu(): void {
		// Hide-when-empty: only show in the sidebar once at least one OAuth
		// client (manually created or DCR-self-registered) exists. The page
		// itself remains reachable via its `?page=` URL. The population
		// check is centralised on the menu-visibility helper.
		$parent = OpenclaWP_Admin_Menu_Visibility::parent_for_slug( self::PAGE_SLUG );
		add_submenu_page(
			$parent,
			__( 'Connected Clients', 'openclawp' ),
			__( 'Connected Clients', 'openclawp' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function handle_post(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
		if ( empty( $_POST['openclawp_oauth_action'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$action = sanitize_key( (string) $_POST['openclawp_oauth_action'] );
		check_admin_referer( 'openclawp_oauth_' . $action );

		switch ( $action ) {
			case 'create':
				$args = array(
					'client_name'    => isset( $_POST['client_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['client_name'] ) ) : '',
					'redirect_uris'  => array_filter(
						array_map( 'trim', preg_split( '/\r?\n/', isset( $_POST['redirect_uris'] ) ? wp_unslash( (string) $_POST['redirect_uris'] ) : '' ) ?: array() ),
						static fn ( string $u ): bool => '' !== $u
					),
					'allowed_scopes' => isset( $_POST['scopes'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['scopes'] ) ) : array(),
					'mcp_server_slug' => isset( $_POST['mcp_server_slug'] ) ? sanitize_title( wp_unslash( (string) $_POST['mcp_server_slug'] ) ) : '',
					'token_endpoint_auth_method' => isset( $_POST['token_endpoint_auth_method'] ) ? sanitize_key( wp_unslash( (string) $_POST['token_endpoint_auth_method'] ) ) : 'client_secret_basic',
					'created_via'    => 'admin',
				);
				$result = OpenclaWP_Oauth_Store::create_client( $args );
				if ( is_wp_error( $result ) ) {
					self::redirect( array( 'error' => $result->get_error_code() ) );
				}
				set_transient(
					self::secret_flash_key( (int) $result['post_id'] ),
					array( 'client_id' => $result['client_id'], 'client_secret' => $result['client_secret'] ),
					60
				);
				self::redirect( array( 'created' => (int) $result['post_id'] ) );
				break;

			case 'delete':
				$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
				if ( $post_id > 0 ) {
					OpenclaWP_Oauth_Store::delete_client( $post_id );
				}
				self::redirect();
				break;

			case 'revoke_token':
				$token_id = isset( $_POST['token_id'] ) ? (int) $_POST['token_id'] : 0;
				if ( $token_id > 0 ) {
					OpenclaWP_Oauth_Store::revoke_token( $token_id );
				}
				self::redirect();
				break;
		}
		// phpcs:enable
	}

	public static function render_page(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$action  = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : '';
		$created = isset( $_GET['created'] ) ? (int) $_GET['created'] : 0;
		$error   = isset( $_GET['error'] ) ? sanitize_key( (string) $_GET['error'] ) : '';
		// phpcs:enable

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'openclaWP — Connected Clients', 'openclawp' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'OAuth 2.1 clients that hold tokens to an openclaWP MCP server. Includes clients registered manually here and clients self-registered via Dynamic Client Registration.', 'openclawp' ) . '</p>';

		if ( '' !== $error ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html( sprintf( __( 'Could not create client: %s', 'openclawp' ), $error ) )
			);
		}
		if ( $created > 0 ) {
			self::render_secret_flash( $created );
		}

		if ( 'new' === $action ) {
			self::render_create();
		} else {
			self::render_list();
		}

		echo '</div>';
	}

	private static function render_list(): void {
		$clients    = OpenclaWP_Oauth_Store::all_clients();
		$create_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=new' );

		printf(
			'<p><a href="%s" class="page-title-action">%s</a></p>',
			esc_url( $create_url ),
			esc_html__( 'Add new', 'openclawp' )
		);

		if ( empty( $clients ) ) {
			echo '<p>' . esc_html__( 'No OAuth clients registered yet.', 'openclawp' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Client', 'openclawp' ) . '</th>';
		echo '<th>' . esc_html__( 'MCP server', 'openclawp' ) . '</th>';
		echo '<th>' . esc_html__( 'Allowed scopes', 'openclawp' ) . '</th>';
		echo '<th>' . esc_html__( 'Registered via', 'openclawp' ) . '</th>';
		echo '<th>' . esc_html__( 'Last used', 'openclawp' ) . '</th>';
		echo '<th>' . esc_html__( 'Tokens', 'openclawp' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'openclawp' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $clients as $client ) {
			$scopes   = OpenclaWP_Oauth_Store::client_allowed_scopes( $client );
			$server   = OpenclaWP_Oauth_Store::client_mcp_server_slug( $client );
			$via      = (string) get_post_meta( $client->ID, OpenclaWP_Oauth_Store::META_CLIENT_CREATED_VIA, true );
			$last     = OpenclaWP_Oauth_Store::client_last_used( $client );
			$tokens   = OpenclaWP_Oauth_Store::tokens_for_client( OpenclaWP_Oauth_Store::client_id( $client ) );

			echo '<tr>';
			echo '<td><strong>' . esc_html( (string) $client->post_title ) . '</strong><br /><code>' . esc_html( (string) $client->post_name ) . '</code></td>';
			echo '<td><code>' . esc_html( $server ) . '</code></td>';
			echo '<td>';
			foreach ( $scopes as $s ) {
				echo '<code>' . esc_html( $s ) . '</code> ';
			}
			echo '</td>';
			echo '<td>' . esc_html( $via ) . '</td>';
			echo '<td>' . esc_html( $last > 0 ? gmdate( 'Y-m-d H:i:s', $last ) : __( 'never', 'openclawp' ) ) . '</td>';
			echo '<td>';
			if ( empty( $tokens ) ) {
				echo '<em>' . esc_html__( 'none', 'openclawp' ) . '</em>';
			} else {
				foreach ( $tokens as $tok ) {
					$last8   = OpenclaWP_Oauth_Store::token_last8( $tok );
					$revoked = OpenclaWP_Oauth_Store::is_revoked( $tok );
					echo '<div>op_at_…' . esc_html( $last8 ) . ' ';
					if ( $revoked ) {
						echo '<em>(' . esc_html__( 'revoked', 'openclawp' ) . ')</em>';
					} else {
						self::action_button( 'revoke_token', __( 'Revoke', 'openclawp' ), 'button-link-delete', array( 'token_id' => (string) $tok->ID ) );
					}
					echo '</div>';
				}
			}
			echo '</td>';
			echo '<td>';
			self::action_button( 'delete', __( 'Delete client', 'openclawp' ), 'button-link-delete', array( 'post_id' => (string) $client->ID ) );
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	private static function render_create(): void {
		$action_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		$servers    = OpenclaWP_Mcp_Server_Store::all();
		$scopes     = OpenclaWP_Oauth_Scope::all_scopes();
		?>
		<h2><?php esc_html_e( 'Add OAuth client', 'openclawp' ); ?></h2>
		<form method="post" action="<?php echo esc_url( $action_url ); ?>">
			<?php wp_nonce_field( 'openclawp_oauth_create' ); ?>
			<input type="hidden" name="openclawp_oauth_action" value="create" />
			<table class="form-table">
				<tr>
					<th scope="row"><label for="openclawp_oauth_client_name"><?php esc_html_e( 'Client name', 'openclawp' ); ?></label></th>
					<td><input type="text" id="openclawp_oauth_client_name" name="client_name" class="regular-text" required /></td>
				</tr>
				<tr>
					<th scope="row"><label for="openclawp_oauth_redirect_uris"><?php esc_html_e( 'Redirect URIs', 'openclawp' ); ?></label></th>
					<td>
						<textarea id="openclawp_oauth_redirect_uris" name="redirect_uris" rows="3" class="large-text code" required></textarea>
						<p class="description"><?php esc_html_e( 'One per line. Exact match required at /authorize.', 'openclawp' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="openclawp_oauth_mcp_server"><?php esc_html_e( 'MCP server', 'openclawp' ); ?></label></th>
					<td>
						<select id="openclawp_oauth_mcp_server" name="mcp_server_slug" required>
							<option value=""><?php esc_html_e( 'Select…', 'openclawp' ); ?></option>
							<?php foreach ( $servers as $server ) : ?>
								<option value="<?php echo esc_attr( (string) $server->post_name ); ?>"><?php echo esc_html( (string) $server->post_title ); ?> (<?php echo esc_html( (string) $server->post_name ); ?>)</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Allowed scopes', 'openclawp' ); ?></th>
					<td>
						<?php foreach ( $scopes as $scope ) : ?>
							<label style="display:block">
								<input type="checkbox" name="scopes[]" value="<?php echo esc_attr( $scope ); ?>" <?php checked( OpenclaWP_Oauth_Scope::SCOPE_READ === $scope ); ?> />
								<code><?php echo esc_html( $scope ); ?></code>
							</label>
						<?php endforeach; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="openclawp_oauth_auth_method"><?php esc_html_e( 'Token endpoint auth', 'openclawp' ); ?></label></th>
					<td>
						<select id="openclawp_oauth_auth_method" name="token_endpoint_auth_method">
							<option value="client_secret_basic">client_secret_basic</option>
							<option value="client_secret_post">client_secret_post</option>
							<option value="none">none (public client, PKCE only)</option>
						</select>
					</td>
				</tr>
			</table>
			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Create client', 'openclawp' ); ?></button>
				<a class="button" href="<?php echo esc_url( $action_url ); ?>"><?php esc_html_e( 'Cancel', 'openclawp' ); ?></a>
			</p>
		</form>
		<?php
	}

	private static function render_secret_flash( int $post_id ): void {
		$flash = get_transient( self::secret_flash_key( $post_id ) );
		if ( ! is_array( $flash ) ) {
			return;
		}
		delete_transient( self::secret_flash_key( $post_id ) );
		echo '<div class="notice notice-success"><p>' . esc_html__( 'Client created. Copy the client_id and client_secret now — the secret will not be shown again.', 'openclawp' ) . '</p>';
		echo '<p><strong>client_id:</strong> <code>' . esc_html( (string) $flash['client_id'] ) . '</code></p>';
		echo '<p><strong>client_secret:</strong> <code>' . esc_html( (string) $flash['client_secret'] ) . '</code></p>';
		echo '</div>';
	}

	private static function action_button( string $action, string $label, string $css_class, array $extra = array() ): void {
		printf(
			'<form method="post" action="%s" style="display:inline">',
			esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) )
		);
		wp_nonce_field( 'openclawp_oauth_' . $action );
		printf( '<input type="hidden" name="openclawp_oauth_action" value="%s" />', esc_attr( $action ) );
		foreach ( $extra as $key => $value ) {
			printf( '<input type="hidden" name="%s" value="%s" />', esc_attr( (string) $key ), esc_attr( (string) $value ) );
		}
		printf( '<button type="submit" class="%s">%s</button>', esc_attr( $css_class ), esc_html( $label ) );
		echo '</form>';
	}

	private static function secret_flash_key( int $post_id ): string {
		return sprintf( '_openclawp_oauth_secret_flash_%d_%d', get_current_user_id(), $post_id );
	}

	private static function redirect( array $query = array() ): void {
		$base = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		$url  = empty( $query ) ? $base : add_query_arg( $query, $base );
		wp_safe_redirect( $url );
		exit;
	}
}
