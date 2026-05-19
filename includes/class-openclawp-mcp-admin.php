<?php
/**
 * wp-admin → openclaWP → MCP servers page.
 *
 * Lists registered MCP servers, lets admins create / regenerate-token /
 * enable-disable / delete. Mirrors the workflows admin pattern: a single
 * `?action=` query-string dispatches to render functions; bearer tokens
 * are shown exactly once via the store's flash-transient affordance.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Mcp_Admin {

	public const PAGE_SLUG = 'openclawp-mcp-servers';

	public const ACTION_REGENERATE  = 'openclawp_mcp_regenerate_token';
	public const ACTION_ACKNOWLEDGE = 'openclawp_mcp_acknowledge_token';

	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_submenu' ), 20 );
		add_action( 'admin_init', array( __CLASS__, 'handle_post' ) );
		add_action( 'admin_post_' . self::ACTION_REGENERATE, array( __CLASS__, 'handle_regenerate_token' ) );
		add_action( 'admin_post_' . self::ACTION_ACKNOWLEDGE, array( __CLASS__, 'handle_acknowledge_token' ) );
	}

	public static function register_submenu(): void {
		// Hide-when-empty: until at least one MCP server exists the menu
		// item is skipped, but the admin URL still resolves so deep links
		// (and the "Add new" affordance from the parent menu) keep working.
		// The population check lives on the menu-visibility helper so the
		// Discover panel on the Chat page sees the same state.
		$parent = OpenclaWP_Admin_Menu_Visibility::parent_for_slug( self::PAGE_SLUG );
		add_submenu_page(
			$parent,
			__( 'MCP Servers', 'openclawp' ),
			__( 'MCP Servers', 'openclawp' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function handle_post(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
		if ( empty( $_POST['openclawp_mcp_action'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$action = sanitize_key( (string) $_POST['openclawp_mcp_action'] );
		check_admin_referer( 'openclawp_mcp_' . $action );

		switch ( $action ) {
			case 'create':
				$label      = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['label'] ) ) : '';
				$slug       = isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( (string) $_POST['slug'] ) ) : '';
				$agent_slug = isset( $_POST['agent_slug'] ) ? sanitize_key( wp_unslash( (string) $_POST['agent_slug'] ) ) : '';
				$result     = OpenclaWP_Mcp_Server_Store::create( $label, $slug, $agent_slug );
				if ( is_wp_error( $result ) ) {
					self::redirect( array( 'error' => $result->get_error_code() ) );
				}
				self::redirect( array( 'created' => $result['post_id'] ) );
				break;

			case 'rotate':
				$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
				if ( $post_id > 0 ) {
					OpenclaWP_Mcp_Server_Store::rotate_token( $post_id );
				}
				self::redirect( array( 'rotated' => $post_id ) );
				break;

			case 'toggle':
				$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
				$enabled = ! empty( $_POST['enabled'] );
				if ( $post_id > 0 ) {
					OpenclaWP_Mcp_Server_Store::toggle_enabled( $post_id, $enabled );
				}
				self::redirect();
				break;

			case 'delete':
				$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
				if ( $post_id > 0 ) {
					OpenclaWP_Mcp_Server_Store::delete( $post_id );
				}
				self::redirect();
				break;
		}
		// phpcs:enable
	}

	/**
	 * Slug-keyed regenerate routed through `admin-post.php`. Mirrors the
	 * post-create disclosure flow: rotate the bearer, then redirect back
	 * to the list view with `?regenerated=<slug>` so the next render
	 * shows the new token + client config snippets.
	 */
	public static function handle_regenerate_token(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to regenerate this token.', 'openclawp' ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$slug = isset( $_REQUEST['slug'] ) ? sanitize_title( wp_unslash( (string) $_REQUEST['slug'] ) ) : '';
		// phpcs:enable
		check_admin_referer( self::ACTION_REGENERATE . '_' . $slug );

		$token = OpenclaWP_Mcp_Server_Store::regenerate_token( $slug );
		if ( null === $token ) {
			self::redirect( array( 'error' => 'unknown_slug' ) );
		}
		self::redirect( array( 'regenerated' => $slug ) );
	}

	/**
	 * "I've saved this" — purge the flash transient so subsequent
	 * refreshes can no longer reveal the plaintext token, then bounce
	 * back to the list view.
	 */
	public static function handle_acknowledge_token(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to acknowledge this token.', 'openclawp' ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$post_id = isset( $_REQUEST['post_id'] ) ? (int) $_REQUEST['post_id'] : 0;
		// phpcs:enable
		check_admin_referer( self::ACTION_ACKNOWLEDGE . '_' . $post_id );

		if ( $post_id > 0 ) {
			OpenclaWP_Mcp_Server_Store::acknowledge_token( $post_id );
		}
		self::redirect();
	}

	public static function render_page(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$action      = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : '';
		$created     = isset( $_GET['created'] ) ? (int) $_GET['created'] : 0;
		$rotated     = isset( $_GET['rotated'] ) ? (int) $_GET['rotated'] : 0;
		$regenerated = isset( $_GET['regenerated'] ) ? sanitize_title( wp_unslash( (string) $_GET['regenerated'] ) ) : '';
		$error       = isset( $_GET['error'] ) ? sanitize_key( (string) $_GET['error'] ) : '';
		// phpcs:enable

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'openclaWP — MCP Servers', 'openclawp' ) . '</h1>';
		echo '<p class="description">' . esc_html(
			__( 'Let external AI clients like Claude Code, Cursor, or VS Code call one of your agent\'s tools. Each server gets its own URL and a bearer token. Tokens are recoverable for 15 minutes after creation or regeneration — after that, regenerate to get a fresh one.', 'openclawp' )
		) . '</p>';

		if ( OpenclaWP_Bootstrap::legacy_mcp_enabled() ) {
			echo '<div class="notice notice-warning inline"><p>'
				. esc_html__( 'The legacy JSON-RPC endpoint at /openclawp/v1/mcp/{slug} is enabled via OPENCLAWP_MCP_LEGACY. It is deprecated and will be removed in the next minor release. Migrate external clients to the mcp-adapter route above.', 'openclawp' )
				. '</p></div>';
		}

		if ( '' !== $error ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html( sprintf( __( 'Could not create server: %s', 'openclawp' ), $error ) )
			);
		}
		if ( $created > 0 ) {
			self::render_token_disclosure( $created, __( 'Server created. Copy this bearer token now — it stays recoverable on this page for 15 minutes:', 'openclawp' ) );
		}
		if ( $rotated > 0 ) {
			self::render_token_disclosure( $rotated, __( 'Token regenerated. Copy the new bearer — the previous token is no longer valid:', 'openclawp' ) );
		}
		if ( '' !== $regenerated ) {
			$server = OpenclaWP_Mcp_Server_Store::find_by_slug( $regenerated );
			if ( null !== $server ) {
				self::render_token_disclosure( $server->ID, __( 'Token regenerated. Copy the new bearer — the previous token is no longer valid:', 'openclawp' ) );
			}
		}

		if ( 'new' === $action ) {
			self::render_create();
		} else {
			self::render_list();
		}

		echo '</div>';
	}

	private static function render_list(): void {
		$servers    = OpenclaWP_Mcp_Server_Store::all();
		$create_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=new' );

		printf(
			'<p><a href="%s" class="page-title-action">%s</a></p>',
			esc_url( $create_url ),
			esc_html__( 'Add new', 'openclawp' )
		);

		if ( empty( $servers ) ) {
			echo '<p>' . esc_html__( 'No MCP servers registered yet.', 'openclawp' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Label', 'openclawp' ) . '</th>';
		echo '<th>' . esc_html__( 'Endpoint', 'openclawp' ) . '</th>';
		echo '<th>' . esc_html__( 'Agent', 'openclawp' ) . '</th>';
		echo '<th>' . esc_html__( 'Token', 'openclawp' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'openclawp' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'openclawp' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $servers as $post ) {
			$adapter_endpoint = rest_url( OpenclaWP_Mcp_Rest::NAMESPACE . '/mcp-adapter/' . $post->post_name );
			$legacy_endpoint  = rest_url( OpenclaWP_Mcp_Rest::NAMESPACE . '/mcp/' . $post->post_name );
			$legacy_enabled   = OpenclaWP_Bootstrap::legacy_mcp_enabled();
			$enabled          = OpenclaWP_Mcp_Server_Store::is_enabled( $post );
			$last4            = OpenclaWP_Mcp_Server_Store::token_last4( $post );

			$regenerate_url = wp_nonce_url(
				add_query_arg(
					array(
						'action' => self::ACTION_REGENERATE,
						'slug'   => $post->post_name,
					),
					admin_url( 'admin-post.php' )
				),
				self::ACTION_REGENERATE . '_' . $post->post_name
			);

			echo '<tr>';
			echo '<td><strong>' . esc_html( $post->post_title ) . '</strong><br /><code>' . esc_html( $post->post_name ) . '</code></td>';
			echo '<td><code>' . esc_html( $adapter_endpoint ) . '</code>';
			if ( $legacy_enabled ) {
				echo '<br /><small><em>' . esc_html__( 'legacy (deprecated):', 'openclawp' ) . '</em> <code>' . esc_html( $legacy_endpoint ) . '</code></small>';
			}
			echo '</td>';
			echo '<td><code>' . esc_html( OpenclaWP_Mcp_Server_Store::agent_slug( $post ) ) . '</code></td>';
			echo '<td><code>op_…' . esc_html( $last4 ) . '</code></td>';
			echo '<td>' . ( $enabled ? esc_html__( 'enabled', 'openclawp' ) : '<em>' . esc_html__( 'disabled', 'openclawp' ) . '</em>' ) . '</td>';
			echo '<td>';
			self::action_button( 'toggle', $post->ID, $enabled ? __( 'Disable', 'openclawp' ) : __( 'Enable', 'openclawp' ), 'button-secondary', array( 'enabled' => $enabled ? '' : '1' ) );
			echo ' <a href="' . esc_url( $regenerate_url ) . '" class="button-link">' . esc_html__( 'Regenerate token', 'openclawp' ) . '</a> ';
			self::action_button( 'delete', $post->ID, __( 'Delete', 'openclawp' ), 'button-link-delete' );
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	private static function render_create(): void {
		$action_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		$agents     = function_exists( 'wp_get_agents' ) ? wp_get_agents() : array();
		?>
		<h2><?php esc_html_e( 'Add MCP server', 'openclawp' ); ?></h2>
		<form method="post" action="<?php echo esc_url( $action_url ); ?>">
			<?php wp_nonce_field( 'openclawp_mcp_create' ); ?>
			<input type="hidden" name="openclawp_mcp_action" value="create" />
			<table class="form-table">
				<tr>
					<th scope="row"><label for="openclawp_mcp_label"><?php esc_html_e( 'Label', 'openclawp' ); ?></label></th>
					<td><input type="text" id="openclawp_mcp_label" name="label" class="regular-text" required /></td>
				</tr>
				<tr>
					<th scope="row"><label for="openclawp_mcp_slug"><?php esc_html_e( 'Slug', 'openclawp' ); ?></label></th>
					<td>
						<input type="text" id="openclawp_mcp_slug" name="slug" class="regular-text" pattern="[a-z0-9\-]+" required />
						<p class="description"><?php esc_html_e( 'URL segment — appears in /openclawp/v1/mcp/{slug}. Lowercase letters, digits, hyphens only.', 'openclawp' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="openclawp_mcp_agent"><?php esc_html_e( 'Agent', 'openclawp' ); ?></label></th>
					<td>
						<?php if ( empty( $agents ) ) : ?>
							<p><em><?php esc_html_e( 'No agents registered. Register an agent via wp_register_agent() first.', 'openclawp' ); ?></em></p>
						<?php else : ?>
							<select id="openclawp_mcp_agent" name="agent_slug" required>
								<option value=""><?php esc_html_e( 'Select an agent…', 'openclawp' ); ?></option>
								<?php foreach ( $agents as $agent_slug => $agent ) : ?>
									<option value="<?php echo esc_attr( (string) $agent_slug ); ?>"><?php echo esc_html( (string) $agent_slug ); ?></option>
								<?php endforeach; ?>
							</select>
						<?php endif; ?>
					</td>
				</tr>
			</table>
			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Create server', 'openclawp' ); ?></button>
				<a class="button" href="<?php echo esc_url( $action_url ); ?>"><?php esc_html_e( 'Cancel', 'openclawp' ); ?></a>
			</p>
		</form>
		<?php
	}

	/**
	 * Show the recoverable plaintext token + opinionated client config
	 * snippets + an explicit "I've saved this" acknowledge gate. The
	 * read is non-destructive so an accidental refresh within the
	 * 15-minute window keeps showing the token; only the explicit
	 * acknowledge (or transient expiry) purges it.
	 */
	private static function render_token_disclosure( int $post_id, string $intro ): void {
		$token = OpenclaWP_Mcp_Server_Store::peek_flashed_token( $post_id );
		if ( null === $token ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$slug      = (string) $post->post_name;
		$endpoint  = rest_url( OpenclaWP_Mcp_Rest::NAMESPACE . '/mcp-adapter/' . $slug );
		$ack_url   = admin_url( 'admin-post.php' );
		$ack_nonce = self::ACTION_ACKNOWLEDGE . '_' . $post_id;

		echo '<div class="notice notice-success"><p>' . esc_html( $intro ) . '</p>';
		echo '<p><code style="font-size: 14px; padding: 6px; background: #f0f0f1;">' . esc_html( $token ) . '</code></p>';
		echo '<p class="description">' . esc_html__( 'Recoverable for 15 minutes after creation or regeneration. Acknowledging below purges it immediately.', 'openclawp' ) . '</p>';

		self::render_client_snippets( $slug, $endpoint, $token );

		echo '<details style="margin-top:12px;"><summary><strong>' . esc_html__( "I've saved this — go to the server list", 'openclawp' ) . '</strong></summary>';
		echo '<form method="post" action="' . esc_url( $ack_url ) . '" style="margin-top:8px;">';
		wp_nonce_field( $ack_nonce );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_ACKNOWLEDGE ) . '" />';
		echo '<input type="hidden" name="post_id" value="' . (int) $post_id . '" />';
		echo '<button type="submit" class="button button-secondary">' . esc_html__( 'Purge token and continue', 'openclawp' ) . '</button>';
		echo '</form></details>';
		echo '</div>';
	}

	/**
	 * Render copy-pasteable config snippets for the named clients in
	 * the page subtitle. Stacked cards (no JS dependency beyond the
	 * inline Copy button) so we don't drag in a tabs bundle.
	 */
	private static function render_client_snippets( string $slug, string $endpoint, string $token ): void {
		$snippets = array(
			array(
				'label'    => __( 'Claude Code (.mcp.json)', 'openclawp' ),
				'language' => 'json',
				'body'     => self::snippet_claude_code( $slug, $endpoint, $token ),
			),
			array(
				'label'    => __( 'Cursor (.cursor/mcp.json)', 'openclawp' ),
				'language' => 'json',
				'body'     => self::snippet_cursor( $slug, $endpoint, $token ),
			),
			array(
				'label'    => __( 'VS Code (Continue / Cline) — JSON', 'openclawp' ),
				'language' => 'json',
				'body'     => self::snippet_vscode( $slug, $endpoint, $token ),
			),
		);

		echo '<div class="openclawp-mcp-snippets" style="margin-top:12px;display:flex;flex-direction:column;gap:8px;">';
		echo '<p><strong>' . esc_html__( 'Client config snippets', 'openclawp' ) . '</strong></p>';

		foreach ( $snippets as $i => $snippet ) {
			$dom_id = 'openclawp-mcp-snippet-' . (int) $i . '-' . sanitize_html_class( $slug );
			echo '<div class="card" style="padding:10px;border:1px solid #ccd0d4;background:#fff;">';
			echo '<p style="margin:0 0 6px;display:flex;align-items:center;justify-content:space-between;gap:8px;">';
			echo '<strong>' . esc_html( $snippet['label'] ) . '</strong>';
			printf(
				'<button type="button" class="button button-small" onclick="%s">%s</button>',
				esc_attr(
					sprintf(
						'var el=document.getElementById(%s);if(el){navigator.clipboard.writeText(el.textContent).then(function(){this.textContent=%s}.bind(this))}return false;',
						(string) wp_json_encode( $dom_id ),
						(string) wp_json_encode( __( 'Copied', 'openclawp' ) )
					)
				),
				esc_html__( 'Copy', 'openclawp' )
			);
			echo '</p>';
			echo '<pre id="' . esc_attr( $dom_id ) . '" style="margin:0;padding:8px;background:#f6f7f7;overflow:auto;white-space:pre;font-size:12px;">';
			echo esc_html( $snippet['body'] );
			echo '</pre>';
			echo '</div>';
		}

		echo '<p class="description">' . esc_html__( 'VS Code MCP support varies by extension (Continue, Cline, MCP Inspector, etc.); the JSON above matches the most common HTTP-transport shape — adjust the wrapper key if your extension uses a different schema.', 'openclawp' ) . '</p>';
		echo '</div>';
	}

	private static function snippet_claude_code( string $slug, string $endpoint, string $token ): string {
		return (string) wp_json_encode(
			array(
				'mcpServers' => array(
					$slug => array(
						'transport' => 'http',
						'url'       => $endpoint,
						'headers'   => array( 'Authorization' => 'Bearer ' . $token ),
					),
				),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);
	}

	private static function snippet_cursor( string $slug, string $endpoint, string $token ): string {
		// Same shape as Claude Code — Cursor's .cursor/mcp.json mirrors the
		// `mcpServers` map convention.
		return self::snippet_claude_code( $slug, $endpoint, $token );
	}

	private static function snippet_vscode( string $slug, string $endpoint, string $token ): string {
		return self::snippet_claude_code( $slug, $endpoint, $token );
	}

	private static function action_button( string $action, int $post_id, string $label, string $css_class, array $extra = array() ): void {
		printf(
			'<form method="post" action="%s" style="display:inline">',
			esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) )
		);
		wp_nonce_field( 'openclawp_mcp_' . $action );
		printf( '<input type="hidden" name="openclawp_mcp_action" value="%s" />', esc_attr( $action ) );
		printf( '<input type="hidden" name="post_id" value="%d" />', (int) $post_id );
		foreach ( $extra as $key => $value ) {
			printf( '<input type="hidden" name="%s" value="%s" />', esc_attr( $key ), esc_attr( (string) $value ) );
		}
		printf( '<button type="submit" class="%s">%s</button>', esc_attr( $css_class ), esc_html( $label ) );
		echo '</form>';
	}

	private static function redirect( array $query = array() ): void {
		$base = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		$url  = empty( $query ) ? $base : add_query_arg( $query, $base );
		wp_safe_redirect( $url );
		exit;
	}
}
