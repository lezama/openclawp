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

	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_submenu' ), 20 );
		add_action( 'admin_init', array( __CLASS__, 'handle_post' ) );
	}

	public static function register_submenu(): void {
		add_submenu_page(
			OpenclaWP_Admin::PAGE_SLUG,
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

	public static function render_page(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$action  = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : '';
		$created = isset( $_GET['created'] ) ? (int) $_GET['created'] : 0;
		$rotated = isset( $_GET['rotated'] ) ? (int) $_GET['rotated'] : 0;
		$error   = isset( $_GET['error'] ) ? sanitize_key( (string) $_GET['error'] ) : '';
		// phpcs:enable

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'openclaWP — MCP Servers', 'openclawp' ) . '</h1>';
		echo '<p class="description">' . esc_html(
			__( 'Each MCP server exposes one registered agent\'s tool surface over JSON-RPC at /openclawp/v1/mcp/{slug}, so Claude Code / Cursor / VS Code can call it. Bearer tokens are shown once at creation — copy them then.', 'openclawp' )
		) . '</p>';

		if ( '' !== $error ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html( sprintf( __( 'Could not create server: %s', 'openclawp' ), $error ) )
			);
		}
		if ( $created > 0 ) {
			self::render_token_flash( $created, __( 'Server created. Copy this bearer token now — it will not be shown again:', 'openclawp' ) );
		}
		if ( $rotated > 0 ) {
			self::render_token_flash( $rotated, __( 'Token rotated. Copy the new bearer — the previous token is no longer valid:', 'openclawp' ) );
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
			$endpoint = rest_url( OpenclaWP_Mcp_Rest::NAMESPACE . '/mcp/' . $post->post_name );
			$enabled  = OpenclaWP_Mcp_Server_Store::is_enabled( $post );
			$last4    = OpenclaWP_Mcp_Server_Store::token_last4( $post );

			echo '<tr>';
			echo '<td><strong>' . esc_html( $post->post_title ) . '</strong><br /><code>' . esc_html( $post->post_name ) . '</code></td>';
			echo '<td><code>' . esc_html( $endpoint ) . '</code></td>';
			echo '<td><code>' . esc_html( OpenclaWP_Mcp_Server_Store::agent_slug( $post ) ) . '</code></td>';
			echo '<td><code>op_…' . esc_html( $last4 ) . '</code> ';
			self::action_button( 'rotate', $post->ID, __( 'Rotate', 'openclawp' ), 'button-link' );
			echo '</td>';
			echo '<td>' . ( $enabled ? esc_html__( 'enabled', 'openclawp' ) : '<em>' . esc_html__( 'disabled', 'openclawp' ) . '</em>' ) . '</td>';
			echo '<td>';
			self::action_button( 'toggle', $post->ID, $enabled ? __( 'Disable', 'openclawp' ) : __( 'Enable', 'openclawp' ), 'button-secondary', array( 'enabled' => $enabled ? '' : '1' ) );
			echo ' ';
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

	private static function render_token_flash( int $post_id, string $intro ): void {
		$token = OpenclaWP_Mcp_Server_Store::pop_flashed_token( $post_id );
		if ( null === $token ) {
			return;
		}
		echo '<div class="notice notice-success"><p>' . esc_html( $intro ) . '</p>';
		echo '<p><code style="font-size: 14px; padding: 6px; background: #f0f0f1;">' . esc_html( $token ) . '</code></p>';
		echo '</div>';
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
