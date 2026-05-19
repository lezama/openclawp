<?php
/**
 * wp-admin → openclaWP → MCP Clients page.
 *
 * Lets admins register external MCP servers to consume tools from. The page
 * supports four actions:
 *
 *   - **create** — manual entry (label, slug, transport, command/args/env or url/headers).
 *   - **install_recipe** — one-click for a curated recipe (Fetch / Context7 / GitHub MCP).
 *   - **toggle_tool** — flip a single advertised tool on/off.
 *   - **retest** — re-probe the server (`initialize` + `tools/list`) and refresh the cached tool list.
 *   - **toggle / delete** — standard.
 *
 * Mirrors the structure of {@see OpenclaWP_Mcp_Admin}: a single
 * `openclawp_mcp_client_action` POST handler dispatches via switch.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Mcp_Clients_Admin {

	public const PAGE_SLUG = 'openclawp-mcp-clients';

	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_submenu' ), 25 );
		add_action( 'admin_init', array( __CLASS__, 'handle_post' ) );
	}

	public static function register_submenu(): void {
		// Hide-when-empty: surface the MCP Clients tab once at least one
		// external MCP server has been configured. The one-click recipes
		// can still be reached via the direct `?page=` URL. The check is
		// centralised on the menu-visibility helper.
		$parent = OpenclaWP_Admin_Menu_Visibility::parent_for_slug( self::PAGE_SLUG );
		add_submenu_page(
			$parent,
			__( 'MCP Clients', 'openclawp' ),
			__( 'MCP Clients', 'openclawp' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Curated one-click recipes. Each recipe maps to a server registration:
	 * a sensible default command (or URL), env hints, and a description.
	 *
	 * For v1 only Fetch ships as a true one-click — `npx -y @modelcontextprotocol/server-fetch`
	 * has no auth and works out of the box. Context7 and GitHub MCP need
	 * tokens, so we still register the row but warn the admin to fill in env
	 * before re-testing.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function recipes(): array {
		return array(
			'fetch'    => array(
				'label'       => __( 'Fetch (URL → markdown)', 'openclawp' ),
				'slug'        => 'fetch',
				'description' => __( 'Fetches a URL and returns its content as markdown — the official reference server. Runs locally via npx, no auth required.', 'openclawp' ),
				'transport'   => OpenclaWP_Mcp_Client_Store::TRANSPORT_STDIO,
				'command'     => 'npx',
				'args'        => array( '-y', '@modelcontextprotocol/server-fetch' ),
				'env'         => array(),
				'requires'    => array(),
			),
			'context7' => array(
				'label'       => __( 'Context7 (framework docs)', 'openclawp' ),
				'slug'        => 'context7',
				'description' => __( 'Version-pinned framework docs (Next.js, Astro, Tailwind, …). Requires a Context7 API key — set CONTEXT7_API_KEY in the env table below before re-testing.', 'openclawp' ),
				'transport'   => OpenclaWP_Mcp_Client_Store::TRANSPORT_STDIO,
				'command'     => 'npx',
				'args'        => array( '-y', '@upstash/context7-mcp' ),
				'env'         => array( 'CONTEXT7_API_KEY' => '' ),
				'requires'    => array( 'CONTEXT7_API_KEY' ),
			),
			'github'   => array(
				'label'       => __( 'GitHub MCP (repos, issues, PRs)', 'openclawp' ),
				'slug'        => 'github',
				'description' => __( 'Read & write GitHub repositories, issues, and PRs. Requires a personal access token — set GITHUB_PERSONAL_ACCESS_TOKEN in the env table below before re-testing.', 'openclawp' ),
				'transport'   => OpenclaWP_Mcp_Client_Store::TRANSPORT_STDIO,
				'command'     => 'npx',
				'args'        => array( '-y', '@modelcontextprotocol/server-github' ),
				'env'         => array( 'GITHUB_PERSONAL_ACCESS_TOKEN' => '' ),
				'requires'    => array( 'GITHUB_PERSONAL_ACCESS_TOKEN' ),
			),
		);
	}

	public static function handle_post(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
		if ( empty( $_POST['openclawp_mcp_client_action'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$action = sanitize_key( (string) $_POST['openclawp_mcp_client_action'] );
		check_admin_referer( 'openclawp_mcp_client_' . $action );

		switch ( $action ) {
			case 'create':
				self::handle_create();
				break;
			case 'install_recipe':
				self::handle_install_recipe();
				break;
			case 'update':
				self::handle_update();
				break;
			case 'retest':
				self::handle_retest();
				break;
			case 'toggle_tool':
				self::handle_toggle_tool();
				break;
			case 'toggle':
				$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
				$enabled = ! empty( $_POST['enabled'] );
				if ( $post_id > 0 ) {
					OpenclaWP_Mcp_Client_Store::toggle_enabled( $post_id, $enabled );
				}
				self::redirect();
				break;
			case 'delete':
				$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
				if ( $post_id > 0 ) {
					OpenclaWP_Mcp_Client_Store::delete( $post_id );
				}
				self::redirect();
				break;
		}
		// phpcs:enable
	}

	private static function handle_create(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$label  = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['label'] ) ) : '';
		$slug   = isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( (string) $_POST['slug'] ) ) : '';
		$config = self::collect_config_from_post();
		// phpcs:enable

		$result = OpenclaWP_Mcp_Client_Store::create( $label, $slug, $config );
		if ( is_wp_error( $result ) ) {
			self::redirect( array( 'error' => $result->get_error_code() ) );
		}
		self::redirect( array( 'created' => $result['post_id'] ) );
	}

	private static function handle_install_recipe(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$recipe_id = isset( $_POST['recipe'] ) ? sanitize_key( (string) $_POST['recipe'] ) : '';
		// phpcs:enable
		$recipes = self::recipes();
		if ( ! isset( $recipes[ $recipe_id ] ) ) {
			self::redirect( array( 'error' => 'unknown_recipe' ) );
		}
		$recipe = $recipes[ $recipe_id ];

		$result = OpenclaWP_Mcp_Client_Store::create(
			(string) $recipe['label'],
			(string) $recipe['slug'],
			array(
				'transport' => $recipe['transport'],
				'command'   => $recipe['command'],
				'args'      => $recipe['args'],
				'env'       => $recipe['env'],
			)
		);
		if ( is_wp_error( $result ) ) {
			self::redirect( array( 'error' => $result->get_error_code() ) );
		}
		self::redirect( array( 'created' => $result['post_id'] ) );
	}

	private static function handle_update(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		// phpcs:enable
		if ( $post_id <= 0 ) {
			self::redirect();
		}
		$config = self::collect_config_from_post();
		OpenclaWP_Mcp_Client_Store::update_config( $post_id, $config );
		self::redirect( array( 'updated' => $post_id ) );
	}

	private static function handle_retest(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		// phpcs:enable
		if ( $post_id <= 0 ) {
			self::redirect();
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			self::redirect();
		}

		$config = OpenclaWP_Mcp_Client_Store::config( $post );
		$tools  = OpenclaWP_Mcp_Client_Transport::discover_tools( $config );
		if ( is_wp_error( $tools ) ) {
			OpenclaWP_Mcp_Client_Store::set_last_error( $post_id, $tools->get_error_message() );
			self::redirect( array( 'retest_failed' => $post_id ) );
		}

		OpenclaWP_Mcp_Client_Store::set_tools( $post_id, $tools );
		self::redirect( array( 'retested' => $post_id ) );
	}

	private static function handle_toggle_tool(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$post_id   = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$tool_name = isset( $_POST['tool_name'] ) ? trim( wp_unslash( (string) $_POST['tool_name'] ) ) : '';
		$enable    = ! empty( $_POST['enable_tool'] );
		// phpcs:enable
		if ( $post_id <= 0 || '' === $tool_name ) {
			self::redirect();
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			self::redirect();
		}
		$config   = OpenclaWP_Mcp_Client_Store::config( $post );
		$disabled = is_array( $config['disabled'] ?? null ) ? $config['disabled'] : array();
		if ( $enable ) {
			$disabled = array_values( array_diff( $disabled, array( $tool_name ) ) );
		} elseif ( ! in_array( $tool_name, $disabled, true ) ) {
			$disabled[] = $tool_name;
		}
		$config['disabled'] = $disabled;
		OpenclaWP_Mcp_Client_Store::update_config( $post_id, $config );
		self::redirect();
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function collect_config_from_post(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$transport   = isset( $_POST['transport'] ) ? sanitize_key( (string) $_POST['transport'] ) : OpenclaWP_Mcp_Client_Store::TRANSPORT_STDIO;
		$command     = isset( $_POST['command'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['command'] ) ) : '';
		$args_raw    = isset( $_POST['args'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['args'] ) ) : '';
		$url         = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( (string) $_POST['url'] ) ) : '';
		$env_raw     = isset( $_POST['env'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['env'] ) ) : '';
		$headers_raw = isset( $_POST['headers'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['headers'] ) ) : '';
		// phpcs:enable

		return array(
			'transport' => $transport,
			'command'   => $command,
			'args'      => self::parse_lines( $args_raw ),
			'url'       => $url,
			'env'       => self::parse_key_value_lines( $env_raw ),
			'headers'   => self::parse_key_value_lines( $headers_raw ),
		);
	}

	/**
	 * Split a textarea on newlines into a trimmed list (empty lines dropped).
	 *
	 * @return array<int,string>
	 */
	private static function parse_lines( string $raw ): array {
		$lines = preg_split( '/\r\n|\n|\r/', $raw );
		if ( ! is_array( $lines ) ) {
			return array();
		}
		$out = array();
		foreach ( $lines as $line ) {
			$trim = trim( $line );
			if ( '' !== $trim ) {
				$out[] = $trim;
			}
		}
		return $out;
	}

	/**
	 * Parse `KEY=value` style env/headers textarea content.
	 *
	 * @return array<string,string>
	 */
	private static function parse_key_value_lines( string $raw ): array {
		$out = array();
		foreach ( self::parse_lines( $raw ) as $line ) {
			$pos = strpos( $line, '=' );
			if ( false === $pos ) {
				continue;
			}
			$key = trim( substr( $line, 0, $pos ) );
			$val = substr( $line, $pos + 1 );
			if ( '' === $key ) {
				continue;
			}
			$out[ $key ] = $val;
		}
		return $out;
	}

	public static function render_page(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$action        = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : '';
		$post_id       = isset( $_GET['post_id'] ) ? (int) $_GET['post_id'] : 0;
		$error         = isset( $_GET['error'] ) ? sanitize_key( (string) $_GET['error'] ) : '';
		$created       = isset( $_GET['created'] ) ? (int) $_GET['created'] : 0;
		$updated       = isset( $_GET['updated'] ) ? (int) $_GET['updated'] : 0;
		$retested      = isset( $_GET['retested'] ) ? (int) $_GET['retested'] : 0;
		$retest_failed = isset( $_GET['retest_failed'] ) ? (int) $_GET['retest_failed'] : 0;
		// phpcs:enable

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'openclaWP — MCP Clients', 'openclawp' ) . '</h1>';
		echo '<p class="description">' . esc_html(
			__( 'Connect to external MCP servers so their tools become available to your agents. Each enabled tool is registered as a WP ability under `mcp/<server>/<tool>`.', 'openclawp' )
		) . '</p>';

		if ( '' !== $error ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( sprintf( __( 'Could not save: %s', 'openclawp' ), $error ) ) );
		}
		if ( $created > 0 ) {
			printf( '<div class="notice notice-success"><p>%s</p></div>', esc_html__( 'Server added. Configure any required env vars below, then click Re-test to discover its tools.', 'openclawp' ) );
		}
		if ( $updated > 0 ) {
			printf( '<div class="notice notice-success"><p>%s</p></div>', esc_html__( 'Configuration saved. Click Re-test to refresh the tool list.', 'openclawp' ) );
		}
		if ( $retested > 0 ) {
			printf( '<div class="notice notice-success"><p>%s</p></div>', esc_html__( 'Tools refreshed.', 'openclawp' ) );
		}
		if ( $retest_failed > 0 ) {
			$post = get_post( $retest_failed );
			$err  = $post ? OpenclaWP_Mcp_Client_Store::last_error( $post ) : '';
			printf(
				'<div class="notice notice-error"><p>%s %s</p></div>',
				esc_html__( 'Re-test failed:', 'openclawp' ),
				esc_html( $err )
			);
		}

		switch ( $action ) {
			case 'new':
				self::render_create();
				break;
			case 'edit':
				self::render_edit( $post_id );
				break;
			default:
				self::render_list();
				break;
		}

		echo '</div>';
	}

	private static function render_list(): void {
		$servers    = OpenclaWP_Mcp_Client_Store::all();
		$create_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=new' );

		printf(
			'<p><a href="%s" class="page-title-action">%s</a></p>',
			esc_url( $create_url ),
			esc_html__( 'Add new', 'openclawp' )
		);

		self::render_recipes();

		if ( empty( $servers ) ) {
			echo '<p>' . esc_html__( 'No MCP clients configured yet. Pick a one-click recipe above or click "Add new".', 'openclawp' ) . '</p>';
			return;
		}

		echo '<h2>' . esc_html__( 'Configured servers', 'openclawp' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Server', 'openclawp' ) . '</th>';
		echo '<th>' . esc_html__( 'Transport', 'openclawp' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'openclawp' ) . '</th>';
		echo '<th>' . esc_html__( 'Tools', 'openclawp' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'openclawp' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $servers as $post ) {
			$config   = OpenclaWP_Mcp_Client_Store::config( $post );
			$tools    = OpenclaWP_Mcp_Client_Store::tools( $post );
			$enabled  = OpenclaWP_Mcp_Client_Store::is_enabled( $post );
			$last_err = OpenclaWP_Mcp_Client_Store::last_error( $post );
			$last_ok  = OpenclaWP_Mcp_Client_Store::last_ok_at( $post );
			$edit_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=edit&post_id=' . $post->ID );

			echo '<tr><td>';
			printf( '<strong><a href="%s">%s</a></strong><br /><code>%s</code>', esc_url( $edit_url ), esc_html( $post->post_title ), esc_html( $post->post_name ) );
			echo '</td>';

			echo '<td>' . esc_html( $config['transport'] ) . '</td>';

			echo '<td>';
			if ( ! $enabled ) {
				echo '<em>' . esc_html__( 'disabled', 'openclawp' ) . '</em>';
			} elseif ( '' !== $last_err ) {
				echo '<span style="color:#b32d2e;">' . esc_html__( 'error', 'openclawp' ) . '</span><br /><small>' . esc_html( $last_err ) . '</small>';
			} elseif ( $last_ok > 0 ) {
				printf(
					'<span style="color:#1d6620;">%s</span><br /><small>%s</small>',
					esc_html__( 'connected', 'openclawp' ),
					esc_html( sprintf( __( 'last ok %s ago', 'openclawp' ), human_time_diff( $last_ok ) ) )
				);
			} else {
				echo '<em>' . esc_html__( 'untested', 'openclawp' ) . '</em>';
			}
			echo '</td>';

			echo '<td>' . esc_html( (string) count( $tools ) ) . '</td>';

			echo '<td>';
			self::action_button( 'retest', $post->ID, __( 'Re-test', 'openclawp' ), 'button-secondary' );
			echo ' ';
			self::action_button( 'toggle', $post->ID, $enabled ? __( 'Disable', 'openclawp' ) : __( 'Enable', 'openclawp' ), 'button-secondary', array( 'enabled' => $enabled ? '' : '1' ) );
			echo ' ';
			self::action_button( 'delete', $post->ID, __( 'Delete', 'openclawp' ), 'button-link-delete' );
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	private static function render_recipes(): void {
		$recipes = self::recipes();
		echo '<h2>' . esc_html__( 'One-click servers', 'openclawp' ) . '</h2>';
		echo '<table class="widefat striped"><tbody>';
		foreach ( $recipes as $id => $recipe ) {
			$existing = OpenclaWP_Mcp_Client_Store::find_by_slug( (string) $recipe['slug'] );
			echo '<tr><td>';
			printf( '<strong>%s</strong>', esc_html( (string) $recipe['label'] ) );
			echo '<br /><small>' . esc_html( (string) $recipe['description'] ) . '</small>';
			echo '</td><td>';
			if ( $existing ) {
				echo '<em>' . esc_html__( 'already installed', 'openclawp' ) . '</em>';
			} else {
				echo '<form method="post" style="display:inline">';
				wp_nonce_field( 'openclawp_mcp_client_install_recipe' );
				echo '<input type="hidden" name="openclawp_mcp_client_action" value="install_recipe" />';
				printf( '<input type="hidden" name="recipe" value="%s" />', esc_attr( (string) $id ) );
				printf( '<button type="submit" class="button button-primary">%s</button>', esc_html__( 'Install', 'openclawp' ) );
				echo '</form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_create(): void {
		$action_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		?>
		<h2><?php esc_html_e( 'Add MCP client', 'openclawp' ); ?></h2>
		<form method="post" action="<?php echo esc_url( $action_url ); ?>">
			<?php wp_nonce_field( 'openclawp_mcp_client_create' ); ?>
			<input type="hidden" name="openclawp_mcp_client_action" value="create" />
			<?php self::render_config_fields( null, OpenclaWP_Mcp_Client_Store::default_config() ); ?>
			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Add server', 'openclawp' ); ?></button>
				<a class="button" href="<?php echo esc_url( $action_url ); ?>"><?php esc_html_e( 'Cancel', 'openclawp' ); ?></a>
			</p>
		</form>
		<?php
	}

	private static function render_edit( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			self::render_list();
			return;
		}
		$config     = OpenclaWP_Mcp_Client_Store::config( $post );
		$tools      = OpenclaWP_Mcp_Client_Store::tools( $post );
		$action_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		$disabled   = is_array( $config['disabled'] ?? null ) ? $config['disabled'] : array();
		?>
		<h2><?php printf( esc_html__( 'Edit: %s', 'openclawp' ), esc_html( $post->post_title ) ); ?></h2>
		<form method="post" action="<?php echo esc_url( $action_url ); ?>">
			<?php wp_nonce_field( 'openclawp_mcp_client_update' ); ?>
			<input type="hidden" name="openclawp_mcp_client_action" value="update" />
			<input type="hidden" name="post_id" value="<?php echo (int) $post->ID; ?>" />
			<?php self::render_config_fields( $post, $config ); ?>
			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save configuration', 'openclawp' ); ?></button>
				<a class="button" href="<?php echo esc_url( $action_url ); ?>"><?php esc_html_e( 'Back', 'openclawp' ); ?></a>
			</p>
		</form>

		<h3><?php esc_html_e( 'Advertised tools', 'openclawp' ); ?></h3>
		<?php if ( empty( $tools ) ) : ?>
			<p><em><?php esc_html_e( 'No tools discovered yet. Click "Re-test" on the list view to probe the server.', 'openclawp' ); ?></em></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Tool', 'openclawp' ); ?></th>
					<th><?php esc_html_e( 'Description', 'openclawp' ); ?></th>
					<th><?php esc_html_e( 'Ability name', 'openclawp' ); ?></th>
					<th><?php esc_html_e( 'Enabled', 'openclawp' ); ?></th>
				</tr></thead>
				<tbody>
					<?php foreach ( $tools as $tool ) : ?>
						<?php
						$name        = (string) ( $tool['name'] ?? '' );
						$description = (string) ( $tool['description'] ?? '' );
						$enabled     = ! in_array( $name, $disabled, true );
						$ability     = OpenclaWP_Mcp_Client_Bridge::ABILITY_PREFIX . $post->post_name . '/' . OpenclaWP_Mcp_Client_Bridge::sanitize_tool_segment( $name );
						?>
						<tr>
							<td><code><?php echo esc_html( $name ); ?></code></td>
							<td><small><?php echo esc_html( $description ); ?></small></td>
							<td><code><?php echo esc_html( $ability ); ?></code></td>
							<td>
								<form method="post" action="<?php echo esc_url( $action_url ); ?>" style="display:inline">
									<?php wp_nonce_field( 'openclawp_mcp_client_toggle_tool' ); ?>
									<input type="hidden" name="openclawp_mcp_client_action" value="toggle_tool" />
									<input type="hidden" name="post_id" value="<?php echo (int) $post->ID; ?>" />
									<input type="hidden" name="tool_name" value="<?php echo esc_attr( $name ); ?>" />
									<input type="hidden" name="enable_tool" value="<?php echo $enabled ? '' : '1'; ?>" />
									<button type="submit" class="button-link"><?php echo $enabled ? esc_html__( 'Disable', 'openclawp' ) : esc_html__( 'Enable', 'openclawp' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	private static function render_config_fields( ?\WP_Post $post, array $config ): void {
		$is_edit = $post instanceof \WP_Post;
		?>
		<table class="form-table">
			<?php if ( ! $is_edit ) : ?>
				<tr>
					<th scope="row"><label for="openclawp_mcp_client_label"><?php esc_html_e( 'Label', 'openclawp' ); ?></label></th>
					<td><input type="text" id="openclawp_mcp_client_label" name="label" class="regular-text" required /></td>
				</tr>
				<tr>
					<th scope="row"><label for="openclawp_mcp_client_slug"><?php esc_html_e( 'Slug', 'openclawp' ); ?></label></th>
					<td>
						<input type="text" id="openclawp_mcp_client_slug" name="slug" class="regular-text" pattern="[a-z0-9\-]+" required />
						<p class="description"><?php esc_html_e( 'Used as the ability prefix (mcp/<slug>/<tool>). Lowercase, digits, hyphens.', 'openclawp' ); ?></p>
					</td>
				</tr>
			<?php endif; ?>
			<tr>
				<th scope="row"><label for="openclawp_mcp_client_transport"><?php esc_html_e( 'Transport', 'openclawp' ); ?></label></th>
				<td>
					<select id="openclawp_mcp_client_transport" name="transport">
						<option value="<?php echo esc_attr( OpenclaWP_Mcp_Client_Store::TRANSPORT_STDIO ); ?>" <?php selected( $config['transport'], OpenclaWP_Mcp_Client_Store::TRANSPORT_STDIO ); ?>>stdio (local command)</option>
						<option value="<?php echo esc_attr( OpenclaWP_Mcp_Client_Store::TRANSPORT_HTTP ); ?>" <?php selected( $config['transport'], OpenclaWP_Mcp_Client_Store::TRANSPORT_HTTP ); ?>>http (streamable)</option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="openclawp_mcp_client_command"><?php esc_html_e( 'Command (stdio)', 'openclawp' ); ?></label></th>
				<td>
					<input type="text" id="openclawp_mcp_client_command" name="command" class="regular-text" value="<?php echo esc_attr( (string) $config['command'] ); ?>" />
					<p class="description"><?php esc_html_e( 'Executable, e.g. npx, python, /usr/local/bin/my-mcp-server.', 'openclawp' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="openclawp_mcp_client_args"><?php esc_html_e( 'Args (one per line)', 'openclawp' ); ?></label></th>
				<td>
					<textarea id="openclawp_mcp_client_args" name="args" rows="3" class="large-text code"><?php echo esc_textarea( implode( "\n", (array) $config['args'] ) ); ?></textarea>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="openclawp_mcp_client_env"><?php esc_html_e( 'Env (KEY=value, one per line)', 'openclawp' ); ?></label></th>
				<td>
					<textarea id="openclawp_mcp_client_env" name="env" rows="3" class="large-text code">
					<?php
					$lines = array();
					foreach ( (array) $config['env'] as $k => $v ) {
						$lines[] = $k . '=' . $v;
					}
					echo esc_textarea( implode( "\n", $lines ) );
					?>
					</textarea>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="openclawp_mcp_client_url"><?php esc_html_e( 'URL (http transport)', 'openclawp' ); ?></label></th>
				<td>
					<input type="url" id="openclawp_mcp_client_url" name="url" class="regular-text" value="<?php echo esc_attr( (string) $config['url'] ); ?>" />
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="openclawp_mcp_client_headers"><?php esc_html_e( 'Headers (KEY=value, one per line)', 'openclawp' ); ?></label></th>
				<td>
					<textarea id="openclawp_mcp_client_headers" name="headers" rows="3" class="large-text code">
					<?php
					$lines = array();
					foreach ( (array) $config['headers'] as $k => $v ) {
						$lines[] = $k . '=' . $v;
					}
					echo esc_textarea( implode( "\n", $lines ) );
					?>
					</textarea>
					<p class="description"><?php esc_html_e( 'For Authorization: Bearer …, etc.', 'openclawp' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	private static function action_button( string $action, int $post_id, string $label, string $css_class, array $extra = array() ): void {
		printf(
			'<form method="post" action="%s" style="display:inline">',
			esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) )
		);
		wp_nonce_field( 'openclawp_mcp_client_' . $action );
		printf( '<input type="hidden" name="openclawp_mcp_client_action" value="%s" />', esc_attr( $action ) );
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
