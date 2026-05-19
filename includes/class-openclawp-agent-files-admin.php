<?php
/**
 * wp-admin → openclaWP → Agent files page.
 *
 * List + edit/create forms for `openclawp_agent_file` posts. The page
 * follows the Knowledge Base admin layout — a single `?action=` query
 * dispatches to a render function and POSTs go through admin-post.php for
 * nonce protection.
 *
 * The submenu uses the shared hide-when-empty helper
 * ({@see OpenclaWP_Admin_Menu_Visibility::parent_for_slug()}) so the entry
 * stays out of the sidebar until at least one file exists. Deep links keep
 * working either way because the page handler is always registered.
 *
 * @package OpenclaWP
 * @since   0.11.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Agent files admin page.
 */
final class OpenclaWP_Agent_Files_Admin {

	public const PAGE_SLUG = 'openclawp-agent-files';

	public const ACTION_SAVE   = 'openclawp_agent_files_save';
	public const ACTION_DELETE = 'openclawp_agent_files_delete';

	/**
	 * Hook admin_menu and admin_post handlers.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_submenu' ), 18 );
		add_action( 'admin_post_' . self::ACTION_SAVE, array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_' . self::ACTION_DELETE, array( __CLASS__, 'handle_delete' ) );
	}

	/**
	 * Register the Agent files submenu under the openclaWP top-level menu.
	 *
	 * Hidden from the sidebar until at least one file exists. The page
	 * handler is always wired up so deep links continue to resolve.
	 */
	public static function register_submenu(): void {
		$parent = OpenclaWP_Admin_Menu_Visibility::parent_for_slug( self::PAGE_SLUG );
		add_submenu_page(
			$parent,
			__( 'Agent files', 'openclawp' ),
			__( 'Agent files', 'openclawp' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Dispatch on `?action=` to list / new / edit views.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage agent files.', 'openclawp' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view dispatch.
		$action  = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : '';
		$file_id = isset( $_GET['file'] ) ? (int) $_GET['file'] : 0;
		$notice  = isset( $_GET['af_notice'] ) ? sanitize_key( (string) $_GET['af_notice'] ) : '';
		// phpcs:enable

		echo '<div class="wrap openclawp-agent-files">';
		echo '<h1>' . esc_html__( 'openclaWP — Agent files', 'openclawp' ) . '</h1>';
		echo '<p class="description">' . esc_html__(
			'Author markdown documents (AGENTS.md, SOUL.md, BOOTSTRAP.md, …) that customise agent behaviour — no PHP required. Files with an agent slug apply to that agent only; files with no slug apply to every agent.',
			'openclawp'
		) . '</p>';

		self::render_notice( $notice );

		if ( 'new' === $action ) {
			self::render_edit_form( null );
		} elseif ( 'edit' === $action && $file_id > 0 ) {
			$post = get_post( $file_id );
			if ( null === $post || OpenclaWP_Agent_Files_Store::POST_TYPE !== $post->post_type ) {
				echo '<p>' . esc_html__( 'Agent file not found.', 'openclawp' ) . '</p>';
			} else {
				self::render_edit_form( $post );
			}
		} else {
			self::render_list();
		}

		echo '</div>';
	}

	/**
	 * Render the list view (table of files + "New file" CTA).
	 */
	private static function render_list(): void {
		$files      = OpenclaWP_Agent_Files_Store::all();
		$create_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=new' );

		printf(
			'<p><a href="%s" class="page-title-action">%s</a></p>',
			esc_url( $create_url ),
			esc_html__( 'New agent file', 'openclawp' )
		);

		if ( empty( $files ) ) {
			echo '<p>' . esc_html__( 'No agent files yet. Create one to start customising your agents.', 'openclawp' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Title', 'openclawp' ) . '</th>';
		echo '<th>' . esc_html__( 'Agent', 'openclawp' ) . '</th>';
		echo '<th>' . esc_html__( 'Last modified', 'openclawp' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'openclawp' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $files as $post ) {
			$slot     = (string) get_post_meta( $post->ID, OpenclaWP_Agent_Files_Store::META_AGENT_SLUG, true );
			$edit_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=edit&file=' . $post->ID );

			echo '<tr>';
			printf(
				'<td><strong><a href="%s">%s</a></strong></td>',
				esc_url( $edit_url ),
				esc_html( $post->post_title )
			);
			echo '<td>';
			if ( '' === $slot ) {
				echo '<em>' . esc_html__( 'All agents', 'openclawp' ) . '</em>';
			} else {
				echo '<code>' . esc_html( $slot ) . '</code>';
			}
			echo '</td>';
			echo '<td>' . esc_html( get_the_modified_date( '', $post ) . ' ' . get_the_modified_time( '', $post ) ) . '</td>';
			echo '<td>';
			printf(
				'<a class="button button-secondary" href="%s">%s</a> ',
				esc_url( $edit_url ),
				esc_html__( 'Edit', 'openclawp' )
			);
			self::render_delete_button( $post->ID );
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Render the create/edit form. Pass `null` for a fresh file.
	 */
	private static function render_edit_form( ?\WP_Post $post ): void {
		$is_edit    = $post instanceof \WP_Post;
		$title      = $is_edit ? $post->post_title : '';
		$body       = $is_edit ? $post->post_content : '';
		$slot       = $is_edit ? (string) get_post_meta( $post->ID, OpenclaWP_Agent_Files_Store::META_AGENT_SLUG, true ) : '';
		$post_id    = $is_edit ? (int) $post->ID : 0;
		$save_url   = admin_url( 'admin-post.php' );
		$cancel_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );

		$agents = self::available_agents();

		echo '<h2>' . esc_html( $is_edit ? __( 'Edit agent file', 'openclawp' ) : __( 'New agent file', 'openclawp' ) ) . '</h2>';

		?>
		<form method="post" action="<?php echo esc_url( $save_url ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE ); ?>" />
			<?php if ( $is_edit ) : ?>
				<input type="hidden" name="post_id" value="<?php echo (int) $post_id; ?>" />
			<?php endif; ?>
			<?php wp_nonce_field( self::ACTION_SAVE ); ?>

			<table class="form-table">
				<tr>
					<th scope="row"><label for="openclawp_agent_file_title"><?php esc_html_e( 'Title (file name)', 'openclawp' ); ?></label></th>
					<td>
						<input type="text" id="openclawp_agent_file_title" name="title" class="regular-text" required value="<?php echo esc_attr( $title ); ?>" placeholder="AGENTS.md" />
						<p class="description"><?php esc_html_e( 'The file name the agent will see, e.g. AGENTS.md, SOUL.md, BOOTSTRAP.md.', 'openclawp' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="openclawp_agent_file_slug"><?php esc_html_e( 'Agent', 'openclawp' ); ?></label></th>
					<td>
						<select id="openclawp_agent_file_slug" name="agent_slug">
							<option value=""><?php esc_html_e( 'All agents (global)', 'openclawp' ); ?></option>
							<?php foreach ( $agents as $agent ) : ?>
								<option value="<?php echo esc_attr( $agent['slug'] ); ?>" <?php selected( $slot, $agent['slug'] ); ?>>
									<?php echo esc_html( $agent['label'] ); ?> (<code><?php echo esc_html( $agent['slug'] ); ?></code>)
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Leave on "All agents" to apply this file to every registered agent.', 'openclawp' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="openclawp_agent_file_body"><?php esc_html_e( 'Markdown body', 'openclawp' ); ?></label></th>
					<td>
						<textarea id="openclawp_agent_file_body" name="body" rows="20" class="large-text code"><?php echo esc_textarea( $body ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Plain markdown. The agent will read this verbatim once the runtime wiring lands.', 'openclawp' ); ?></p>
					</td>
				</tr>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary">
					<?php echo esc_html( $is_edit ? __( 'Save file', 'openclawp' ) : __( 'Create file', 'openclawp' ) ); ?>
				</button>
				<a class="button" href="<?php echo esc_url( $cancel_url ); ?>"><?php esc_html_e( 'Cancel', 'openclawp' ); ?></a>
			</p>
		</form>
		<?php
	}

	/**
	 * Inline form holding a single delete button.
	 */
	private static function render_delete_button( int $post_id ): void {
		printf(
			'<form method="post" action="%s" style="display:inline" onsubmit="return confirm(%s);">',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr( wp_json_encode( __( 'Delete this agent file?', 'openclawp' ) ) )
		);
		wp_nonce_field( self::ACTION_DELETE );
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( self::ACTION_DELETE ) );
		printf( '<input type="hidden" name="post_id" value="%d" />', (int) $post_id );
		printf(
			'<button type="submit" class="button-link-delete">%s</button>',
			esc_html__( 'Delete', 'openclawp' )
		);
		echo '</form>';
	}

	/**
	 * Persist a created or updated agent file.
	 */
	public static function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage agent files.', 'openclawp' ) );
		}
		check_admin_referer( self::ACTION_SAVE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked above.
		$post_id    = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$title      = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['title'] ) ) : '';
		$agent_slug = isset( $_POST['agent_slug'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['agent_slug'] ) ) : '';
		$body       = isset( $_POST['body'] ) ? wp_unslash( (string) $_POST['body'] ) : '';
		// phpcs:enable

		if ( '' === trim( $title ) ) {
			wp_safe_redirect( add_query_arg( 'af_notice', 'missing_title', self::page_url() ) );
			exit;
		}

		$payload = array(
			'post_type'    => OpenclaWP_Agent_Files_Store::POST_TYPE,
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_content' => $body,
		);

		if ( $post_id > 0 ) {
			$payload['ID'] = $post_id;
			$result        = wp_update_post( $payload, true );
		} else {
			$result = wp_insert_post( $payload, true );
		}

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( 'af_notice', 'error', self::page_url() ) );
			exit;
		}

		$resolved_id = $post_id > 0 ? $post_id : (int) $result;
		if ( '' === $agent_slug ) {
			delete_post_meta( $resolved_id, OpenclaWP_Agent_Files_Store::META_AGENT_SLUG );
		} else {
			update_post_meta( $resolved_id, OpenclaWP_Agent_Files_Store::META_AGENT_SLUG, $agent_slug );
		}

		wp_safe_redirect( add_query_arg( 'af_notice', $post_id > 0 ? 'updated' : 'created', self::page_url() ) );
		exit;
	}

	/**
	 * Delete a single agent file.
	 */
	public static function handle_delete(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage agent files.', 'openclawp' ) );
		}
		check_admin_referer( self::ACTION_DELETE );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above.
		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;

		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( $post instanceof \WP_Post && OpenclaWP_Agent_Files_Store::POST_TYPE === $post->post_type ) {
				wp_delete_post( $post_id, true );
			}
		}

		wp_safe_redirect( add_query_arg( 'af_notice', 'deleted', self::page_url() ) );
		exit;
	}

	/**
	 * Render a one-shot admin notice for the supplied flag.
	 */
	private static function render_notice( string $notice ): void {
		switch ( $notice ) {
			case 'created':
				printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__( 'Agent file created.', 'openclawp' ) );
				break;
			case 'updated':
				printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__( 'Agent file updated.', 'openclawp' ) );
				break;
			case 'deleted':
				printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__( 'Agent file deleted.', 'openclawp' ) );
				break;
			case 'missing_title':
				printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html__( 'Title is required.', 'openclawp' ) );
				break;
			case 'error':
				printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html__( 'Could not save the agent file.', 'openclawp' ) );
				break;
		}
	}

	/**
	 * URL of this admin page (used for handler redirects).
	 */
	private static function page_url(): string {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * List the registered agents for the Agent <select>.
	 *
	 * Degrades gracefully when `wp_get_agents()` isn't available (e.g. the
	 * agents-api isn't loaded) — the select is rendered with just the
	 * "All agents" option.
	 *
	 * @return array<int,array{slug:string,label:string}>
	 */
	private static function available_agents(): array {
		if ( ! function_exists( 'wp_get_agents' ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) wp_get_agents() as $agent ) {
			$slug  = '';
			$label = '';
			if ( is_object( $agent ) ) {
				$slug  = isset( $agent->slug ) ? (string) $agent->slug : '';
				$label = isset( $agent->label ) ? (string) $agent->label : '';
			} elseif ( is_array( $agent ) ) {
				$slug  = isset( $agent['slug'] ) ? (string) $agent['slug'] : '';
				$label = isset( $agent['label'] ) ? (string) $agent['label'] : '';
			}
			if ( '' === $slug ) {
				continue;
			}
			$out[] = array(
				'slug'  => $slug,
				'label' => '' === $label ? $slug : $label,
			);
		}
		usort( $out, static fn ( $a, $b ) => strcmp( $a['label'], $b['label'] ) );
		return $out;
	}
}
