<?php
/**
 * wp-admin surface for agency automation.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Agency_Admin {

	public const PAGE_SLUG = 'openclawp-agency';
	public const ACTION_GENERATE = 'openclawp_agency_generate_demo';
	public const NONCE_GENERATE  = 'openclawp_agency_generate_demo';

	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_submenu' ), 18 );
		add_action( 'admin_post_' . self::ACTION_GENERATE, array( __CLASS__, 'handle_generate_demo' ) );
	}

	public static function register_submenu(): void {
		add_submenu_page(
			OpenclaWP_Admin::PAGE_SLUG,
			__( 'Agency', 'openclawp' ),
			__( 'Agency', 'openclawp' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page(): void {
		$audit      = OpenclaWP_Automation_Audit::audit_current_site();
		$blueprints = OpenclaWP_Agency_Blueprints::list();
		$connectors = OpenclaWP_Agency_Connectors::all();
		$workspaces = OpenclaWP_Agency_Workspace_Store::all( 10 );
		$demos      = OpenclaWP_Agency_Demo_Store::recent( 10 );
		?>
		<div class="wrap openclawp-agency">
			<h1><?php esc_html_e( 'openclaWP - Agency automation', 'openclawp' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Generate client-specific automation agents, workflows, connector plans, and sales demos from reusable blueprints.', 'openclawp' ); ?>
			</p>

			<?php self::render_notices(); ?>

			<h2><?php esc_html_e( 'Top opportunities on this site', 'openclawp' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Blueprint', 'openclawp' ); ?></th>
						<th><?php esc_html_e( 'Score', 'openclawp' ); ?></th>
						<th><?php esc_html_e( 'Evidence', 'openclawp' ); ?></th>
						<th><?php esc_html_e( 'Connectors', 'openclawp' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( array_slice( (array) $audit['opportunities'], 0, 6 ) as $opportunity ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $opportunity['label'] ); ?></td>
							<td><?php echo esc_html( (string) $opportunity['score'] ); ?></td>
							<td><?php echo esc_html( implode( ' ', (array) $opportunity['evidence'] ) ); ?></td>
							<td><?php echo esc_html( implode( ', ', (array) $opportunity['recommended_connectors'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php self::render_generator_form( $blueprints, $connectors, $audit ); ?>

			<h2><?php esc_html_e( 'Blueprints', 'openclawp' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Use case', 'openclawp' ); ?></th>
						<th><?php esc_html_e( 'Category', 'openclawp' ); ?></th>
						<th><?php esc_html_e( 'Connectors', 'openclawp' ); ?></th>
						<th><?php esc_html_e( 'Demo prompts', 'openclawp' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $blueprints as $blueprint ) : ?>
						<tr>
							<td>
								<strong><?php echo esc_html( (string) $blueprint['label'] ); ?></strong><br />
								<span class="description"><?php echo esc_html( (string) $blueprint['description'] ); ?></span>
							</td>
							<td><?php echo esc_html( (string) $blueprint['category'] ); ?></td>
							<td><?php echo esc_html( implode( ', ', (array) $blueprint['recommended_connectors'] ) ); ?></td>
							<td><?php echo esc_html( implode( ' | ', array_slice( (array) $blueprint['demo_prompts'], 0, 2 ) ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Recent client workspaces', 'openclawp' ); ?></h2>
			<?php self::render_simple_list( $workspaces, 'name', 'site_url', __( 'No client workspaces yet.', 'openclawp' ) ); ?>

			<h2><?php esc_html_e( 'Recent generated demos', 'openclawp' ); ?></h2>
			<?php self::render_demo_list( $demos ); ?>

			<h2><?php esc_html_e( 'API quick paths', 'openclawp' ); ?></h2>
			<ul>
				<li><code>GET /wp-json/openclawp/v1/agency/blueprints</code></li>
				<li><code>GET /wp-json/openclawp/v1/agency/audit</code></li>
				<li><code>POST /wp-json/openclawp/v1/agency/workspaces</code></li>
				<li><code>POST /wp-json/openclawp/v1/agency/generate</code></li>
			</ul>
		</div>
		<?php
	}

	public static function handle_generate_demo(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to generate agency demos.', 'openclawp' ) );
		}
		check_admin_referer( self::NONCE_GENERATE );

		$workspace = array(
			'name'       => self::posted_text( 'client_name' ),
			'site_url'   => self::posted_text( 'site_url' ),
			'industry'   => self::posted_text( 'industry' ),
			'summary'    => self::posted_textarea( 'summary' ),
			'goals'      => OpenclaWP_Agency_Workspace_Store::sanitize_text_list( self::posted_textarea( 'goals' ) ),
			'channels'   => OpenclaWP_Agency_Workspace_Store::sanitize_key_list( self::posted_textarea( 'channels' ) ),
			'connectors' => OpenclaWP_Agency_Workspace_Store::sanitize_key_list( self::posted_array( 'connectors' ) ),
			'notes'      => self::posted_textarea( 'notes' ),
		);

		if ( '' === $workspace['name'] ) {
			$workspace['name'] = get_bloginfo( 'name' );
		}
		if ( '' === $workspace['site_url'] ) {
			$workspace['site_url'] = home_url( '/' );
		}

		$saved_workspace = OpenclaWP_Agency_Workspace_Store::save( $workspace );
		if ( is_wp_error( $saved_workspace ) ) {
			self::redirect_with_error( $saved_workspace );
		}

		$result = OpenclaWP_Agency_Generator::generate(
			array(
				'blueprint'    => self::posted_key( 'blueprint' ),
				'workspace_id' => (int) ( $saved_workspace['workspace_id'] ?? 0 ),
				'answers'      => self::parse_answers( self::posted_textarea( 'answers' ) ),
				'save'         => true,
			)
		);

		if ( is_wp_error( $result ) ) {
			self::redirect_with_error( $result );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                       => self::PAGE_SLUG,
					'openclawp_agency_generated' => (int) ( $result['demo_id'] ?? 1 ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * @param array<int,array<string,mixed>> $blueprints
	 * @param array<string,array<string,mixed>> $connectors
	 * @param array<string,mixed> $audit
	 */
	private static function render_generator_form( array $blueprints, array $connectors, array $audit ): void {
		$default_blueprint = self::default_blueprint_slug( $audit, $blueprints );
		?>
		<h2><?php esc_html_e( 'Generate a client demo package', 'openclawp' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_GENERATE ); ?>" />
			<?php wp_nonce_field( self::NONCE_GENERATE ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="openclawp-agency-blueprint"><?php esc_html_e( 'Use case', 'openclawp' ); ?></label></th>
					<td>
						<select id="openclawp-agency-blueprint" name="blueprint">
							<?php foreach ( $blueprints as $blueprint ) : ?>
								<option value="<?php echo esc_attr( (string) $blueprint['slug'] ); ?>" <?php selected( (string) $blueprint['slug'], $default_blueprint ); ?>>
									<?php echo esc_html( (string) $blueprint['label'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Defaults to the top audit opportunity for this site.', 'openclawp' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="openclawp-agency-client-name"><?php esc_html_e( 'Client name', 'openclawp' ); ?></label></th>
					<td><input id="openclawp-agency-client-name" class="regular-text" type="text" name="client_name" value="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" required /></td>
				</tr>
				<tr>
					<th scope="row"><label for="openclawp-agency-site-url"><?php esc_html_e( 'Client site URL', 'openclawp' ); ?></label></th>
					<td><input id="openclawp-agency-site-url" class="regular-text" type="url" name="site_url" value="<?php echo esc_attr( home_url( '/' ) ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="openclawp-agency-industry"><?php esc_html_e( 'Industry', 'openclawp' ); ?></label></th>
					<td><input id="openclawp-agency-industry" class="regular-text" type="text" name="industry" placeholder="<?php esc_attr_e( 'legal services, clinic, SaaS, ecommerce', 'openclawp' ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="openclawp-agency-summary"><?php esc_html_e( 'Client summary', 'openclawp' ); ?></label></th>
					<td><textarea id="openclawp-agency-summary" class="large-text" rows="3" name="summary" placeholder="<?php esc_attr_e( 'What the client sells, who they serve, and the operational pain to automate.', 'openclawp' ); ?>"></textarea></td>
				</tr>
				<tr>
					<th scope="row"><label for="openclawp-agency-goals"><?php esc_html_e( 'Goals', 'openclawp' ); ?></label></th>
					<td><textarea id="openclawp-agency-goals" class="large-text" rows="2" name="goals" placeholder="<?php esc_attr_e( 'qualify leads, reduce response time, route requests', 'openclawp' ); ?>"></textarea></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Available connectors', 'openclawp' ); ?></th>
					<td>
						<fieldset>
							<?php foreach ( $connectors as $connector ) : ?>
								<label style="display:inline-block;min-width:160px;margin:0 16px 8px 0;">
									<input type="checkbox" name="connectors[]" value="<?php echo esc_attr( (string) $connector['slug'] ); ?>" />
									<?php echo esc_html( (string) $connector['label'] ); ?>
								</label>
							<?php endforeach; ?>
						</fieldset>
						<p class="description"><?php esc_html_e( 'Unchecked packs are treated as required setup work in the generated plan.', 'openclawp' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="openclawp-agency-channels"><?php esc_html_e( 'Channels', 'openclawp' ); ?></label></th>
					<td><input id="openclawp-agency-channels" class="regular-text" type="text" name="channels" value="site-chat, email" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="openclawp-agency-answers"><?php esc_html_e( 'Blueprint answers', 'openclawp' ); ?></label></th>
					<td>
						<textarea id="openclawp-agency-answers" class="large-text code" rows="6" name="answers" placeholder="offer: Initial consultation&#10;qualification_fields: service, timeline, budget&#10;handoff_destination: sales inbox"></textarea>
						<p class="description"><?php esc_html_e( 'One key/value pair per line. Missing required keys are saved in the package for follow-up.', 'openclawp' ); ?></p>
						<?php self::render_answer_key_reference( $blueprints ); ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="openclawp-agency-notes"><?php esc_html_e( 'Internal notes', 'openclawp' ); ?></label></th>
					<td><textarea id="openclawp-agency-notes" class="large-text" rows="3" name="notes"></textarea></td>
				</tr>
			</table>

			<?php submit_button( __( 'Generate demo package', 'openclawp' ) ); ?>
		</form>
		<?php
	}

	/**
	 * @param array<int,array<string,mixed>> $items
	 */
	private static function render_simple_list( array $items, string $title_key, string $meta_key, string $empty ): void {
		if ( empty( $items ) ) {
			echo '<p class="description">' . esc_html( $empty ) . '</p>';
			return;
		}
		echo '<ul>';
		foreach ( $items as $item ) {
			echo '<li><strong>' . esc_html( (string) ( $item[ $title_key ] ?? '' ) ) . '</strong> ';
			echo '<span class="description">' . esc_html( (string) ( $item[ $meta_key ] ?? '' ) ) . '</span></li>';
		}
		echo '</ul>';
	}

	/**
	 * @param array<int,array<string,mixed>> $items
	 */
	private static function render_demo_list( array $items ): void {
		if ( empty( $items ) ) {
			echo '<p class="description">' . esc_html__( 'No demo packages yet.', 'openclawp' ) . '</p>';
			return;
		}

		echo '<ul>';
		foreach ( $items as $item ) {
			echo '<li style="margin-bottom:12px;">';
			echo '<strong>' . esc_html( (string) ( $item['title'] ?? '' ) ) . '</strong><br />';
			echo '<span class="description">' . esc_html( (string) ( $item['package_id'] ?? '' ) ) . '</span>';

			$missing = isset( $item['missing_answers'] ) && is_array( $item['missing_answers'] ) ? $item['missing_answers'] : array();
			if ( ! empty( $missing ) ) {
				echo '<br /><span>' . esc_html__( 'Missing answers:', 'openclawp' ) . ' ' . esc_html( implode( ', ', $missing ) ) . '</span>';
			}

			$required_connectors = self::required_connector_labels( $item );
			if ( ! empty( $required_connectors ) ) {
				echo '<br /><span>' . esc_html__( 'Connectors to configure:', 'openclawp' ) . ' ' . esc_html( implode( ', ', $required_connectors ) ) . '</span>';
			}

			$prompts = isset( $item['demo']['prompts'] ) && is_array( $item['demo']['prompts'] ) ? $item['demo']['prompts'] : array();
			if ( ! empty( $prompts ) ) {
				echo '<br /><span>' . esc_html__( 'Demo prompts:', 'openclawp' ) . ' ' . esc_html( implode( ' | ', array_slice( $prompts, 0, 2 ) ) ) . '</span>';
			}

			echo '</li>';
		}
		echo '</ul>';
	}

	private static function render_notices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin notice.
		if ( isset( $_GET['openclawp_agency_generated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Agency demo package generated and saved.', 'openclawp' ) . '</p></div>';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin notice.
		if ( isset( $_GET['openclawp_agency_error'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin notice.
			$message = sanitize_text_field( wp_unslash( (string) $_GET['openclawp_agency_error'] ) );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}
	}

	/**
	 * @param array<string,mixed> $audit
	 * @param array<int,array<string,mixed>> $blueprints
	 */
	private static function default_blueprint_slug( array $audit, array $blueprints ): string {
		$first = isset( $audit['opportunities'][0] ) && is_array( $audit['opportunities'][0] ) ? $audit['opportunities'][0] : array();
		$slug  = sanitize_key( (string) ( $first['blueprint_slug'] ?? $first['blueprint'] ?? $first['slug'] ?? '' ) );
		foreach ( $blueprints as $blueprint ) {
			if ( $slug === (string) $blueprint['slug'] ) {
				return $slug;
			}
		}
		return isset( $blueprints[0]['slug'] ) ? (string) $blueprints[0]['slug'] : 'lead-concierge';
	}

	/**
	 * @param array<int,array<string,mixed>> $blueprints
	 */
	private static function render_answer_key_reference( array $blueprints ): void {
		echo '<details style="margin-top:8px;"><summary>' . esc_html__( 'Answer keys by blueprint', 'openclawp' ) . '</summary>';
		echo '<ul>';
		foreach ( $blueprints as $blueprint ) {
			$keys = array();
			foreach ( (array) ( $blueprint['questions'] ?? array() ) as $question ) {
				if ( is_array( $question ) && ! empty( $question['id'] ) ) {
					$keys[] = sanitize_key( (string) $question['id'] );
				}
			}
			echo '<li><strong>' . esc_html( (string) $blueprint['label'] ) . '</strong>: ' . esc_html( implode( ', ', $keys ) ) . '</li>';
		}
		echo '</ul></details>';
	}

	/**
	 * @param array<string,mixed> $item
	 * @return array<int,string>
	 */
	private static function required_connector_labels( array $item ): array {
		$out = array();
		foreach ( (array) ( $item['connector_plan'] ?? array() ) as $connector ) {
			if ( ! is_array( $connector ) || 'available' === (string) ( $connector['status'] ?? '' ) ) {
				continue;
			}
			$out[] = (string) ( $connector['label'] ?? $connector['slug'] ?? '' );
		}
		return array_values( array_filter( $out ) );
	}

	/**
	 * @return array<string,string>
	 */
	private static function parse_answers( string $raw ): array {
		$answers = array();
		foreach ( preg_split( '/\R/', $raw ) ?: array() as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}
			$parts = preg_split( '/[:=]/', $line, 2 );
			if ( ! is_array( $parts ) || count( $parts ) < 2 ) {
				continue;
			}
			$key = sanitize_key( trim( (string) $parts[0] ) );
			if ( '' === $key ) {
				continue;
			}
			$answers[ $key ] = sanitize_textarea_field( trim( (string) $parts[1] ) );
		}
		return $answers;
	}

	private static function redirect_with_error( WP_Error $error ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                   => self::PAGE_SLUG,
					'openclawp_agency_error' => $error->get_error_message(),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private static function posted_key( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by caller.
		return isset( $_POST[ $key ] ) ? sanitize_key( wp_unslash( (string) $_POST[ $key ] ) ) : '';
	}

	private static function posted_text( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by caller.
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) : '';
	}

	private static function posted_textarea( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by caller.
		return isset( $_POST[ $key ] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST[ $key ] ) ) : '';
	}

	/**
	 * @return array<int,string>
	 */
	private static function posted_array( string $key ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by caller.
		$value = $_POST[ $key ] ?? array();
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_map( 'sanitize_text_field', wp_unslash( $value ) );
	}
}
