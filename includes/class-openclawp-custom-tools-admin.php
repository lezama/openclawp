<?php
/**
 * wp-admin → openclaWP → Custom Tools page.
 *
 * Server-rendered list + wizard form mirroring the MCP-servers and routines
 * admin pattern. `?action=` query-string dispatches to render functions;
 * POSTs are nonce-protected and re-redirect with query flags so the URL
 * stays stable on reload.
 *
 * v0 ships HTTP tools only — WP hook and WP-CLI types are filed as
 * follow-ups. The wizard surfaces a "Start from template" dropdown
 * (Slack webhook, Open-Meteo, GitHub issue) so non-dev admins have a
 * known-working example to mutate.
 *
 * @package OpenclaWP
 * @since   0.7.0
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Custom_Tools_Admin {

	public const PAGE_SLUG = 'openclawp-custom-tools';

	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_submenu' ), 18 );
		add_action( 'admin_init', array( __CLASS__, 'handle_post' ) );
	}

	public static function register_submenu(): void {
		add_submenu_page(
			OpenclaWP_Admin::PAGE_SLUG,
			__( 'Custom Tools', 'openclawp' ),
			__( 'Custom Tools', 'openclawp' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function handle_post(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
		if ( empty( $_POST['openclawp_tool_action'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$action = sanitize_key( (string) $_POST['openclawp_tool_action'] );
		check_admin_referer( 'openclawp_tool_' . $action );

		switch ( $action ) {
			case 'create':
				$args = self::parse_form_payload( $_POST );
				$id   = OpenclaWP_Custom_Tools_Store::create( $args );
				if ( is_wp_error( $id ) ) {
					self::redirect(
						array(
							'error'   => $id->get_error_code(),
							'message' => $id->get_error_message(),
						)
					);
				}
				self::redirect( array( 'created' => $id ) );
				break;

			case 'update':
				$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
				if ( $post_id > 0 ) {
					$args   = self::parse_form_payload( $_POST );
					$result = OpenclaWP_Custom_Tools_Store::update( $post_id, $args );
					if ( is_wp_error( $result ) ) {
						self::redirect(
							array(
								'tool'    => $post_id,
								'error'   => $result->get_error_code(),
								'message' => $result->get_error_message(),
							)
						);
					}
				}
				self::redirect(
					array(
						'tool'    => $post_id,
						'updated' => 1,
					)
				);
				break;

			case 'toggle':
				$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
				$enabled = ! empty( $_POST['enabled'] );
				if ( $post_id > 0 ) {
					OpenclaWP_Custom_Tools_Store::toggle_enabled( $post_id, $enabled );
				}
				self::redirect();
				break;

			case 'delete':
				$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
				if ( $post_id > 0 ) {
					OpenclaWP_Custom_Tools_Store::delete( $post_id );
				}
				self::redirect();
				break;

			case 'test':
				$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
				$raw     = isset( $_POST['test_input'] ) ? wp_unslash( (string) $_POST['test_input'] ) : '';
				$input   = json_decode( $raw, true );
				if ( ! is_array( $input ) ) {
					self::redirect(
						array(
							'tool'    => $post_id,
							'error'   => 'invalid_test_input',
							'message' => 'Test input must be a JSON object.',
						)
					);
				}
				$post = $post_id > 0 ? get_post( $post_id ) : null;
				if ( null === $post || OpenclaWP_Custom_Tools_Store::POST_TYPE !== $post->post_type ) {
					self::redirect(
						array(
							'tool'  => $post_id,
							'error' => 'not_found',
						)
					);
				}
				$spec   = OpenclaWP_Custom_Tools_Store::get_spec( $post );
				$result = OpenclaWP_Custom_Tools_Executor::execute( $spec, $input );

				// Stash the result in a per-user transient so the redirect lands
				// on a clean URL and we don't bloat the query string.
				set_transient(
					self::test_result_key( $post_id, get_current_user_id() ),
					is_wp_error( $result )
						? array(
							'error'   => $result->get_error_code(),
							'message' => $result->get_error_message(),
						)
						: $result,
					120
				);
				self::redirect(
					array(
						'tool'   => $post_id,
						'tested' => 1,
					)
				);
				break;
		}
		// phpcs:enable
	}

	/**
	 * Pull the wizard form payload off $_POST into the shape the store wants.
	 *
	 * @param array $post Raw $_POST.
	 * @return array{label:string,slug:string,description:string,spec:array}
	 */
	private static function parse_form_payload( array $post ): array {
		$label       = isset( $post['label'] ) ? sanitize_text_field( wp_unslash( (string) $post['label'] ) ) : '';
		$slug        = isset( $post['slug'] ) ? sanitize_title( wp_unslash( (string) $post['slug'] ) ) : '';
		$description = isset( $post['description'] ) ? sanitize_textarea_field( wp_unslash( (string) $post['description'] ) ) : '';

		$input_schema_raw = isset( $post['input_schema'] ) ? wp_unslash( (string) $post['input_schema'] ) : '';
		$input_schema     = json_decode( $input_schema_raw, true );
		if ( ! is_array( $input_schema ) ) {
			$input_schema = array(
				'type'       => 'object',
				'properties' => new \stdClass(),
			);
		}

		$method    = isset( $post['method'] ) ? sanitize_text_field( wp_unslash( (string) $post['method'] ) ) : 'GET';
		$url       = isset( $post['url'] ) ? esc_url_raw( wp_unslash( (string) $post['url'] ), array( 'http', 'https' ) ) : '';
		$body_type = isset( $post['body_type'] ) ? sanitize_key( wp_unslash( (string) $post['body_type'] ) ) : 'none';
		$body      = isset( $post['body'] ) ? wp_unslash( (string) $post['body'] ) : '';

		$headers_raw = isset( $post['headers'] ) ? wp_unslash( (string) $post['headers'] ) : '';
		$headers     = self::parse_headers_textarea( $headers_raw );

		$auth_mode    = isset( $post['auth_mode'] ) ? sanitize_key( wp_unslash( (string) $post['auth_mode'] ) ) : OpenclaWP_Custom_Tools_Store::AUTH_NONE;
		$token_option = isset( $post['token_option'] ) ? sanitize_key( wp_unslash( (string) $post['token_option'] ) ) : '';

		$effect = isset( $post['effect'] ) ? sanitize_key( wp_unslash( (string) $post['effect'] ) ) : OpenclaWP_Custom_Tools_Store::EFFECT_READ;

		$output_mode    = isset( $post['output_mode'] ) ? sanitize_key( wp_unslash( (string) $post['output_mode'] ) ) : OpenclaWP_Custom_Tools_Store::OUTPUT_RAW;
		$output_path    = isset( $post['output_path'] ) ? sanitize_text_field( wp_unslash( (string) $post['output_path'] ) ) : '';
		$output_pattern = isset( $post['output_pattern'] ) ? wp_unslash( (string) $post['output_pattern'] ) : '';
		$output_group   = isset( $post['output_group'] ) ? (int) $post['output_group'] : 0;

		$roles = isset( $post['allowed_roles'] ) && is_array( $post['allowed_roles'] )
			? array_map( static fn( $r ): string => sanitize_key( (string) $r ), $post['allowed_roles'] )
			: array( 'administrator' );

		$spec = array(
			'type'          => OpenclaWP_Custom_Tools_Store::TYPE_HTTP,
			'input_schema'  => $input_schema,
			'http'          => array(
				'method'    => $method,
				'url'       => $url,
				'headers'   => $headers,
				'body_type' => $body_type,
				'body'      => $body,
			),
			'auth'          => array(
				'mode'         => $auth_mode,
				'token_option' => $token_option,
			),
			'effect'        => $effect,
			'output'        => array(
				'mode'    => $output_mode,
				'path'    => $output_path,
				'pattern' => $output_pattern,
				'group'   => $output_group,
			),
			'allowed_roles' => $roles,
		);

		return array(
			'label'       => $label,
			'slug'        => $slug,
			'description' => $description,
			'spec'        => $spec,
		);
	}

	/**
	 * Parse a `Name: value` per-line headers textarea into an assoc array.
	 *
	 * @return array<string, string>
	 */
	private static function parse_headers_textarea( string $raw ): array {
		$out   = array();
		$lines = preg_split( '/\r\n|\n|\r/', $raw );
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$colon = strpos( $line, ':' );
			if ( false === $colon ) {
				continue;
			}
			$name  = trim( substr( $line, 0, $colon ) );
			$value = trim( substr( $line, $colon + 1 ) );
			if ( '' === $name ) {
				continue;
			}
			$out[ $name ] = $value;
		}
		return $out;
	}

	public static function render_page(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$action  = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : '';
		$tool_id = isset( $_GET['tool'] ) ? (int) $_GET['tool'] : 0;
		$created = isset( $_GET['created'] ) ? (int) $_GET['created'] : 0;
		$updated = ! empty( $_GET['updated'] );
		$tested  = ! empty( $_GET['tested'] );
		$error   = isset( $_GET['error'] ) ? sanitize_key( (string) $_GET['error'] ) : '';
		$message = isset( $_GET['message'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['message'] ) ) : '';
		// phpcs:enable

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'openclaWP — Custom Tools', 'openclawp' ) . '</h1>';
		echo '<p class="description">' . esc_html__(
			'Give your agent new capabilities without writing PHP. Each tool you save here becomes callable by the agent on its next turn. Today\'s version supports HTTP-request tools; WordPress hook and WP-CLI tools are coming.',
			'openclawp'
		) . '</p>';

		if ( '' !== $error ) {
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
				esc_html( $error ),
				esc_html( $message )
			);
		}
		if ( $created > 0 ) {
			$edit_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tool=' . $created );
			printf(
				'<div class="notice notice-success"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'Tool created.', 'openclawp' ),
				esc_url( $edit_url ),
				esc_html__( 'Edit it →', 'openclawp' )
			);
		}
		if ( $updated ) {
			printf(
				'<div class="notice notice-success"><p>%s</p></div>',
				esc_html__( 'Tool updated.', 'openclawp' )
			);
		}

		if ( 'new' === $action ) {
			self::render_create();
		} elseif ( $tool_id > 0 ) {
			$post = get_post( $tool_id );
			if ( null === $post || OpenclaWP_Custom_Tools_Store::POST_TYPE !== $post->post_type ) {
				echo '<p>' . esc_html__( 'Tool not found.', 'openclawp' ) . '</p>';
			} else {
				self::render_edit( $post, $tested );
			}
		} else {
			self::render_list();
		}

		echo '</div>';
	}

	private static function render_list(): void {
		$tools      = OpenclaWP_Custom_Tools_Store::all();
		$create_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=new' );

		printf(
			'<p><a href="%s" class="page-title-action">%s</a></p>',
			esc_url( $create_url ),
			esc_html__( 'New tool', 'openclawp' )
		);

		if ( empty( $tools ) ) {
			echo '<p>' . esc_html__( 'No custom tools yet. Create one from the wizard.', 'openclawp' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Tool', 'openclawp' ) . '</th>';
		echo '<th>' . esc_html__( 'Ability', 'openclawp' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'openclawp' ) . '</th>';
		echo '<th>' . esc_html__( 'Effect', 'openclawp' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'openclawp' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'openclawp' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $tools as $post ) {
			$spec     = OpenclaWP_Custom_Tools_Store::get_spec( $post );
			$enabled  = OpenclaWP_Custom_Tools_Store::is_enabled( $post );
			$ability  = OpenclaWP_Custom_Tools_Registrar::ability_name_for_slug( $post->post_name );
			$edit_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tool=' . $post->ID );

			echo '<tr>';
			printf(
				'<td><strong><a href="%s">%s</a></strong><br /><code>%s</code></td>',
				esc_url( $edit_url ),
				esc_html( $post->post_title ),
				esc_html( $post->post_name )
			);
			echo '<td><code>' . esc_html( $ability ) . '</code></td>';
			echo '<td>' . esc_html( strtoupper( (string) ( $spec['http']['method'] ?? '' ) ) ) . ' / ' . esc_html( (string) ( $spec['type'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $spec['effect'] ?? '' ) ) . '</td>';
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
		$templates  = OpenclaWP_Custom_Tools_Templates::all();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$selected = isset( $_GET['template'] ) ? sanitize_key( (string) $_GET['template'] ) : '';
		// phpcs:enable
		$prefill = isset( $templates[ $selected ] ) ? $templates[ $selected ] : null;

		echo '<h2>' . esc_html__( 'New custom tool', 'openclawp' ) . '</h2>';

		// Template picker.
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" style="margin-bottom:24px;">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '" />';
		echo '<input type="hidden" name="action" value="new" />';
		echo '<label><strong>' . esc_html__( 'Start from template:', 'openclawp' ) . '</strong> ';
		echo '<select name="template" onchange="this.form.submit()">';
		echo '<option value="">' . esc_html__( '— Blank tool —', 'openclawp' ) . '</option>';
		foreach ( $templates as $id => $tpl ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $id ),
				selected( $selected, $id, false ),
				esc_html( $tpl['label'] )
			);
		}
		echo '</select></label>';
		echo '</form>';

		$spec = $prefill ? OpenclaWP_Custom_Tools_Store::normalise_spec( $prefill['spec'] ) : OpenclaWP_Custom_Tools_Store::default_spec();
		if ( is_wp_error( $spec ) ) {
			$spec = OpenclaWP_Custom_Tools_Store::default_spec();
		}

		self::render_form(
			$action_url,
			'create',
			array(
				'label'       => $prefill['label'] ?? '',
				'slug'        => $prefill['slug'] ?? '',
				'description' => $prefill['description'] ?? '',
				'spec'        => $spec,
			)
		);
	}

	private static function render_edit( \WP_Post $post, bool $tested ): void {
		$action_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		$spec       = OpenclaWP_Custom_Tools_Store::get_spec( $post );
		$ability    = OpenclaWP_Custom_Tools_Registrar::ability_name_for_slug( $post->post_name );

		echo '<h2>' . esc_html__( 'Edit tool', 'openclawp' ) . ' — <code>' . esc_html( $ability ) . '</code></h2>';

		self::render_form(
			$action_url,
			'update',
			array(
				'label'       => $post->post_title,
				'slug'        => $post->post_name,
				'description' => $post->post_content,
				'spec'        => $spec,
				'post_id'     => $post->ID,
			)
		);

		echo '<hr />';
		echo '<h2>' . esc_html__( 'Test panel', 'openclawp' ) . '</h2>';
		echo '<p class="description">' . esc_html__(
			'Provide a sample JSON object of arguments. We run the tool against the real endpoint and show the raw response + the agent-visible output.',
			'openclawp'
		) . '</p>';

		$last_result = $tested
			? get_transient( self::test_result_key( $post->ID, get_current_user_id() ) )
			: false;
		if ( $tested && false !== $last_result ) {
			delete_transient( self::test_result_key( $post->ID, get_current_user_id() ) );
		}

		echo '<form method="post" action="' . esc_url( $action_url ) . '">';
		wp_nonce_field( 'openclawp_tool_test' );
		echo '<input type="hidden" name="openclawp_tool_action" value="test" />';
		echo '<input type="hidden" name="post_id" value="' . (int) $post->ID . '" />';
		echo '<p><label for="openclawp_test_input"><strong>' . esc_html__( 'Sample input (JSON object)', 'openclawp' ) . '</strong></label></p>';
		echo '<textarea id="openclawp_test_input" name="test_input" rows="6" class="large-text code">' . esc_textarea( '{}' ) . '</textarea>';
		echo '<p><button type="submit" class="button button-secondary">' . esc_html__( 'Run tool', 'openclawp' ) . '</button></p>';
		echo '</form>';

		if ( false !== $last_result && is_array( $last_result ) ) {
			echo '<h3>' . esc_html__( 'Last test result', 'openclawp' ) . '</h3>';
			echo '<pre class="code" style="background:#f0f0f1;padding:12px;overflow:auto;max-height:400px;">';
			echo esc_html( (string) wp_json_encode( $last_result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			echo '</pre>';
		}
	}

	/**
	 * Shared wizard form for create + edit.
	 *
	 * @param array{label:string,slug:string,description:string,spec:array,post_id?:int} $values
	 */
	private static function render_form( string $action_url, string $mode, array $values ): void {
		$spec           = $values['spec'];
		$is_edit        = 'update' === $mode;
		$nonce_key      = 'openclawp_tool_' . $mode;
		$post_id        = (int) ( $values['post_id'] ?? 0 );
		$all_roles      = wp_roles()->get_names();
		$selected_roles = array_values( (array) ( $spec['allowed_roles'] ?? array( 'administrator' ) ) );
		?>
		<form method="post" action="<?php echo esc_url( $action_url ); ?>">
			<?php wp_nonce_field( $nonce_key ); ?>
			<input type="hidden" name="openclawp_tool_action" value="<?php echo esc_attr( $mode ); ?>" />
			<?php if ( $is_edit ) : ?>
				<input type="hidden" name="post_id" value="<?php echo (int) $post_id; ?>" />
			<?php endif; ?>

			<table class="form-table">
				<tr>
					<th scope="row"><label for="openclawp_tool_label"><?php esc_html_e( 'Label', 'openclawp' ); ?></label></th>
					<td><input type="text" id="openclawp_tool_label" name="label" class="regular-text" required value="<?php echo esc_attr( $values['label'] ); ?>" /></td>
				</tr>
				<?php if ( ! $is_edit ) : ?>
				<tr>
					<th scope="row"><label for="openclawp_tool_slug"><?php esc_html_e( 'Slug', 'openclawp' ); ?></label></th>
					<td>
						<input type="text" id="openclawp_tool_slug" name="slug" class="regular-text" pattern="[a-z0-9\-]+" required value="<?php echo esc_attr( $values['slug'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Used for the registered ability name (openclawp/tool-{slug}). Lowercase letters, digits, hyphens only. Cannot be changed after creation.', 'openclawp' ); ?></p>
					</td>
				</tr>
				<?php endif; ?>
				<tr>
					<th scope="row"><label for="openclawp_tool_description"><?php esc_html_e( 'Description (agent-visible)', 'openclawp' ); ?></label></th>
					<td>
						<textarea id="openclawp_tool_description" name="description" rows="3" class="large-text"><?php echo esc_textarea( $values['description'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'What the LLM sees when deciding whether to call this tool. Write it like a function docstring.', 'openclawp' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="openclawp_tool_input_schema"><?php esc_html_e( 'Input schema (JSON Schema)', 'openclawp' ); ?></label></th>
					<td>
						<textarea id="openclawp_tool_input_schema" name="input_schema" rows="8" class="large-text code"><?php echo esc_textarea( (string) wp_json_encode( $spec['input_schema'], JSON_PRETTY_PRINT ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Parameters the agent must provide. Standard JSON Schema. Use parameters via {{name}} in the request below.', 'openclawp' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'HTTP request', 'openclawp' ); ?></th>
					<td>
						<p>
							<label><?php esc_html_e( 'Method', 'openclawp' ); ?>
								<select name="method">
									<?php foreach ( array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ) as $m ) : ?>
										<option value="<?php echo esc_attr( $m ); ?>" <?php selected( $spec['http']['method'] ?? 'GET', $m ); ?>><?php echo esc_html( $m ); ?></option>
									<?php endforeach; ?>
								</select>
							</label>
						</p>
						<p>
							<label for="openclawp_tool_url"><?php esc_html_e( 'URL', 'openclawp' ); ?></label><br />
							<input type="url" id="openclawp_tool_url" name="url" class="large-text code" required value="<?php echo esc_attr( (string) ( $spec['http']['url'] ?? '' ) ); ?>" />
							<span class="description"><?php esc_html_e( 'Use {{parameter}} placeholders from the input schema. Values are URL-encoded automatically.', 'openclawp' ); ?></span>
						</p>
						<p>
							<label for="openclawp_tool_headers"><?php esc_html_e( 'Headers (one per line, Name: value)', 'openclawp' ); ?></label><br />
							<textarea id="openclawp_tool_headers" name="headers" rows="3" class="large-text code"><?php echo esc_textarea( self::format_headers_textarea( (array) ( $spec['http']['headers'] ?? array() ) ) ); ?></textarea>
						</p>
						<p>
							<label><?php esc_html_e( 'Body type', 'openclawp' ); ?>
								<select name="body_type">
									<?php
									foreach ( array(
										'none' => __( 'None', 'openclawp' ),
										'json' => __( 'JSON', 'openclawp' ),
										'form' => __( 'Form (urlencoded)', 'openclawp' ),
										'raw'  => __( 'Raw', 'openclawp' ),
									) as $v => $l ) :
										?>
										<option value="<?php echo esc_attr( $v ); ?>" <?php selected( $spec['http']['body_type'] ?? 'none', $v ); ?>><?php echo esc_html( $l ); ?></option>
									<?php endforeach; ?>
								</select>
							</label>
						</p>
						<p>
							<label for="openclawp_tool_body"><?php esc_html_e( 'Body template', 'openclawp' ); ?></label><br />
							<textarea id="openclawp_tool_body" name="body" rows="5" class="large-text code"><?php echo esc_textarea( (string) ( $spec['http']['body'] ?? '' ) ); ?></textarea>
							<span class="description"><?php esc_html_e( 'For JSON bodies: write the JSON shape directly. {{parameter}} tokens are substituted as typed values (no string injection).', 'openclawp' ); ?></span>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Authentication', 'openclawp' ); ?></th>
					<td>
						<p>
							<label><input type="radio" name="auth_mode" value="<?php echo esc_attr( OpenclaWP_Custom_Tools_Store::AUTH_NONE ); ?>" <?php checked( $spec['auth']['mode'] ?? '', OpenclaWP_Custom_Tools_Store::AUTH_NONE ); ?> /> <?php esc_html_e( 'None', 'openclawp' ); ?></label>
							&nbsp;&nbsp;
							<label><input type="radio" name="auth_mode" value="<?php echo esc_attr( OpenclaWP_Custom_Tools_Store::AUTH_BEARER ); ?>" <?php checked( $spec['auth']['mode'] ?? '', OpenclaWP_Custom_Tools_Store::AUTH_BEARER ); ?> /> <?php esc_html_e( 'Bearer token from WP option', 'openclawp' ); ?></label>
						</p>
						<p>
							<label for="openclawp_tool_token_option"><?php esc_html_e( 'Token option key', 'openclawp' ); ?></label>
							<input type="text" id="openclawp_tool_token_option" name="token_option" class="regular-text" value="<?php echo esc_attr( (string) ( $spec['auth']['token_option'] ?? '' ) ); ?>" />
							<span class="description"><?php esc_html_e( 'Name of the WP option that stores the token. The literal token never appears in the tool spec or agent input.', 'openclawp' ); ?></span>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="openclawp_tool_effect"><?php esc_html_e( 'Effect', 'openclawp' ); ?></label></th>
					<td>
						<select id="openclawp_tool_effect" name="effect">
							<?php
							foreach ( array(
								OpenclaWP_Custom_Tools_Store::EFFECT_READ        => __( 'Read', 'openclawp' ),
								OpenclaWP_Custom_Tools_Store::EFFECT_WRITE       => __( 'Write', 'openclawp' ),
								OpenclaWP_Custom_Tools_Store::EFFECT_DESTRUCTIVE => __( 'Destructive', 'openclawp' ),
							) as $v => $l ) :
								?>
								<option value="<?php echo esc_attr( $v ); ?>" <?php selected( $spec['effect'] ?? '', $v ); ?>><?php echo esc_html( $l ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Hint for the confirmation gate (destructive tools may require user confirmation before firing).', 'openclawp' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Output handling', 'openclawp' ); ?></th>
					<td>
						<p>
							<label><input type="radio" name="output_mode" value="<?php echo esc_attr( OpenclaWP_Custom_Tools_Store::OUTPUT_RAW ); ?>" <?php checked( $spec['output']['mode'] ?? '', OpenclaWP_Custom_Tools_Store::OUTPUT_RAW ); ?> /> <?php esc_html_e( 'Return raw response body', 'openclawp' ); ?></label>
						</p>
						<p>
							<label><input type="radio" name="output_mode" value="<?php echo esc_attr( OpenclaWP_Custom_Tools_Store::OUTPUT_JSONPATH ); ?>" <?php checked( $spec['output']['mode'] ?? '', OpenclaWP_Custom_Tools_Store::OUTPUT_JSONPATH ); ?> /> <?php esc_html_e( 'Extract JSONPath', 'openclawp' ); ?></label>
							&nbsp;
							<input type="text" name="output_path" class="regular-text code" placeholder="$.foo.bar[0]" value="<?php echo esc_attr( (string) ( $spec['output']['path'] ?? '' ) ); ?>" />
						</p>
						<p>
							<label><input type="radio" name="output_mode" value="<?php echo esc_attr( OpenclaWP_Custom_Tools_Store::OUTPUT_REGEX ); ?>" <?php checked( $spec['output']['mode'] ?? '', OpenclaWP_Custom_Tools_Store::OUTPUT_REGEX ); ?> /> <?php esc_html_e( 'Regex capture', 'openclawp' ); ?></label>
							&nbsp;
							<input type="text" name="output_pattern" class="regular-text code" placeholder="(?<token>[A-Z0-9]+)" value="<?php echo esc_attr( (string) ( $spec['output']['pattern'] ?? '' ) ); ?>" />
							&nbsp;
							<label><?php esc_html_e( 'Group', 'openclawp' ); ?>
								<input type="number" name="output_group" class="small-text" min="0" max="20" value="<?php echo esc_attr( (string) ( $spec['output']['group'] ?? 0 ) ); ?>" />
							</label>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Allowed roles', 'openclawp' ); ?></th>
					<td>
						<?php foreach ( $all_roles as $role_slug => $role_label ) : ?>
							<label style="display:inline-block;margin-right:12px;">
								<input type="checkbox" name="allowed_roles[]" value="<?php echo esc_attr( $role_slug ); ?>" <?php checked( in_array( $role_slug, $selected_roles, true ) ); ?> />
								<?php echo esc_html( $role_label ); ?>
							</label>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'WordPress roles allowed to invoke this tool. Defaults to administrator only.', 'openclawp' ); ?></p>
					</td>
				</tr>
			</table>
			<p class="submit">
				<button type="submit" class="button button-primary"><?php echo esc_html( $is_edit ? __( 'Save tool', 'openclawp' ) : __( 'Create tool', 'openclawp' ) ); ?></button>
				<a class="button" href="<?php echo esc_url( $action_url ); ?>"><?php esc_html_e( 'Cancel', 'openclawp' ); ?></a>
			</p>
		</form>
		<?php
	}

	private static function format_headers_textarea( array $headers ): string {
		$lines = array();
		foreach ( $headers as $name => $value ) {
			$lines[] = (string) $name . ': ' . (string) $value;
		}
		return implode( "\n", $lines );
	}

	private static function action_button( string $action, int $post_id, string $label, string $css_class, array $extra = array() ): void {
		printf(
			'<form method="post" action="%s" style="display:inline">',
			esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) )
		);
		wp_nonce_field( 'openclawp_tool_' . $action );
		printf( '<input type="hidden" name="openclawp_tool_action" value="%s" />', esc_attr( $action ) );
		printf( '<input type="hidden" name="post_id" value="%d" />', (int) $post_id );
		foreach ( $extra as $key => $value ) {
			printf( '<input type="hidden" name="%s" value="%s" />', esc_attr( $key ), esc_attr( (string) $value ) );
		}
		printf( '<button type="submit" class="%s">%s</button>', esc_attr( $css_class ), esc_html( $label ) );
		echo '</form>';
	}

	private static function test_result_key( int $post_id, int $user_id ): string {
		return sprintf( '_openclawp_tool_test_result_%d_%d', $user_id, $post_id );
	}

	private static function redirect( array $query = array() ): void {
		$base = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		$url  = empty( $query ) ? $base : add_query_arg( $query, $base );
		wp_safe_redirect( $url );
		exit;
	}
}
