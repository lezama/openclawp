<?php
/**
 * First-run setup wizard.
 *
 * A fresh openclaWP install drops the user on an empty Chat page with no
 * obvious next step. This wizard walks the admin through configuring a
 * provider + first agent in three short steps, then hands them off to the
 * Chat page with something to talk to.
 *
 * Surface: `admin.php?page=openclawp-setup`, reachable from a dismissible
 * welcome notice rendered on every wp-admin page until the user completes
 * (or skips) the wizard. The notice goes away once
 * `openclawp_setup_completed` flips to `'1'`.
 *
 * Step 3 toggles the `openclawp_setup_enable_example_agent` option, which
 * this class then surfaces back to the agent registrar via the documented
 * `openclawp_register_example_agent` filter. The agent registrar itself
 * stays untouched.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

/**
 * First-run setup wizard surface + welcome notice + filter bridge.
 */
final class OpenclaWP_Setup_Wizard {

	public const PAGE_SLUG = 'openclawp-setup';

	public const OPTION_COMPLETED      = 'openclawp_setup_completed';
	public const OPTION_ENABLE_EXAMPLE = 'openclawp_setup_enable_example_agent';

	public const ACTION_STEP = 'openclawp_setup_step';
	public const NONCE_STEP  = 'openclawp_setup_step';

	/**
	 * Hook the admin page, welcome notice, form handler, and example-agent
	 * filter bridge.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_render_welcome_notice' ) );
		add_action( 'admin_post_' . self::ACTION_STEP, array( __CLASS__, 'handle_step' ) );
		add_filter( 'openclawp_register_example_agent', array( __CLASS__, 'filter_register_example_agent' ) );
	}

	/**
	 * Register the wizard page. It's intentionally not in the sidebar — the
	 * welcome notice is the only entry point, and once the wizard is complete
	 * there's nothing to come back to.
	 */
	public static function register_page(): void {
		add_submenu_page(
			'', // null parent → hidden from the menu.
			__( 'openclaWP Setup', 'openclawp' ),
			__( 'openclaWP Setup', 'openclawp' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Bridge the wizard's stored option to the documented filter the agent
	 * registrar reads. Keeps the registrar untouched while letting Step 3 do
	 * its job.
	 *
	 * @param bool $enabled Current filter value.
	 */
	public static function filter_register_example_agent( bool $enabled ): bool {
		if ( $enabled ) {
			return true;
		}
		return '1' === (string) get_option( self::OPTION_ENABLE_EXAMPLE, '' );
	}

	/**
	 * Dismissible welcome notice rendered on every admin screen until the
	 * wizard is complete. Skipped on the wizard itself so it doesn't compete
	 * with the wizard's own UI.
	 *
	 * The CTA points at the Chat page (`?page=openclawp`) so users land in
	 * the in-chat card wizard by default. The PHP wizard at
	 * `?page=openclawp-setup` remains reachable as a deep-link fallback.
	 */
	public static function maybe_render_welcome_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( '1' === (string) get_option( self::OPTION_COMPLETED, '' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page check.
		$current_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		if ( self::PAGE_SLUG === $current_page ) {
			return;
		}

		$chat_url = admin_url( 'admin.php?page=' . OpenclaWP_Admin::PAGE_SLUG );
		?>
		<div class="notice notice-info is-dismissible openclawp-setup-notice">
			<p>
				<strong><?php esc_html_e( 'Welcome to openclaWP', 'openclawp' ); ?></strong> —
				<?php esc_html_e( 'finish setup so you can talk to your first agent.', 'openclawp' ); ?>
				<a class="button button-primary" style="margin-left:8px;" href="<?php echo esc_url( $chat_url ); ?>">
					<?php esc_html_e( 'Start setup', 'openclawp' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the current wizard step. Each step is a self-contained card; the
	 * step is selected by `?step=` and defaults to step 1.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'openclawp' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only step selector.
		$step = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( (string) $_GET['step'] ) ) : '1';

		// Landing on the "done" step marks the wizard complete. Doing this
		// here (rather than only on form submit) means "Skip setup" links can
		// just bounce the user through `?step=done`.
		if ( 'done' === $step ) {
			update_option( self::OPTION_COMPLETED, '1' );
		}

		?>
		<div class="wrap">
			<div class="openclawp-setup-card" style="max-width:640px;margin:48px auto;background:#fff;border:1px solid #c3c4c7;padding:32px;box-shadow:0 1px 1px rgba(0,0,0,0.04);">
				<?php
				switch ( $step ) {
					case '2':
						self::render_step_provider();
						break;
					case '3':
						self::render_step_agent();
						break;
					case 'done':
						self::render_step_done();
						break;
					case '1':
					default:
						self::render_step_welcome();
						break;
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the "Step N of M" indicator above each card.
	 *
	 * @param int $current Current step number.
	 * @param int $total   Total step count.
	 */
	private static function render_step_indicator( int $current, int $total ): void {
		?>
		<p class="description" style="margin:0 0 8px;text-transform:uppercase;letter-spacing:0.05em;font-size:11px;">
			<?php
			printf(
				/* translators: 1: current step number, 2: total step count. */
				esc_html__( 'Step %1$d of %2$d', 'openclawp' ),
				(int) $current,
				(int) $total
			);
			?>
		</p>
		<?php
	}

	/**
	 * Render the "Skip setup" link that bounces the user to the done step.
	 */
	private static function render_skip_link(): void {
		$done_url = add_query_arg(
			array(
				'page' => self::PAGE_SLUG,
				'step' => 'done',
			),
			admin_url( 'admin.php' )
		);
		?>
		<a href="<?php echo esc_url( $done_url ); ?>" class="button-link" style="margin-left:12px;">
			<?php esc_html_e( 'Skip setup', 'openclawp' ); ?>
		</a>
		<?php
	}

	/**
	 * Step 1 — describe openclaWP and offer a "Get started" CTA.
	 */
	private static function render_step_welcome(): void {
		$next_url = add_query_arg(
			array(
				'page' => self::PAGE_SLUG,
				'step' => '2',
			),
			admin_url( 'admin.php' )
		);
		self::render_step_indicator( 1, 3 );
		?>
		<h1 style="margin-top:0;"><?php esc_html_e( 'Welcome to openclaWP', 'openclawp' ); ?></h1>
		<p>
			<?php
			esc_html_e(
				'openclaWP turns your WordPress site into a place where an agent lives, reads your content, talks back, and can be reached from outside WordPress through pluggable connectors. This 3-step wizard gets you to a working chat in under a minute.',
				'openclawp'
			);
			?>
		</p>
		<p style="margin-top:24px;">
			<a class="button button-primary" href="<?php echo esc_url( $next_url ); ?>">
				<?php esc_html_e( 'Get started', 'openclawp' ); ?>
			</a>
			<?php self::render_skip_link(); ?>
		</p>
		<?php
	}

	/**
	 * Step 2 — list detected AI provider plugins; require at least one
	 * installed before "Continue" is clickable.
	 */
	private static function render_step_provider(): void {
		$providers     = self::detect_providers();
		$any_installed = false;
		foreach ( $providers as $provider ) {
			if ( ! empty( $provider['installed'] ) ) {
				$any_installed = true;
				break;
			}
		}

		self::render_step_indicator( 2, 3 );
		?>
		<h1 style="margin-top:0;"><?php esc_html_e( 'Pick an AI provider', 'openclawp' ); ?></h1>
		<p>
			<?php
			esc_html_e(
				"openclaWP works with any AI provider that registers with the WordPress AI client. Pick one to continue — if you don't have one installed yet, the easiest path is Ollama (runs locally, no API key).",
				'openclawp'
			);
			?>
		</p>

		<table class="widefat striped" style="margin:16px 0;">
			<tbody>
				<?php foreach ( $providers as $provider ) : ?>
					<tr>
						<td style="width:30%;"><strong><?php echo esc_html( $provider['label'] ); ?></strong></td>
						<td>
							<?php if ( ! empty( $provider['installed'] ) ) : ?>
								<span style="color:#007017;font-weight:600;">
									<?php esc_html_e( 'Installed', 'openclawp' ); ?>
								</span>
							<?php else : ?>
								<a href="<?php echo esc_url( (string) $provider['install_url'] ); ?>" target="_blank" rel="noopener noreferrer">
									<?php esc_html_e( 'Install', 'openclawp' ); ?>
								</a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( ! $any_installed ) : ?>
			<div class="notice notice-warning inline" style="margin:16px 0;">
				<p>
					<?php
					esc_html_e(
						'No AI provider plugin detected yet. Install one above (Ollama is the quickest — it runs locally with no API key) and refresh this page to continue.',
						'openclawp'
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:24px;">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_STEP ); ?>" />
			<input type="hidden" name="step" value="2" />
			<?php wp_nonce_field( self::NONCE_STEP ); ?>
			<button type="submit" class="button button-primary" <?php disabled( ! $any_installed ); ?>>
				<?php esc_html_e( 'Continue', 'openclawp' ); ?>
			</button>
			<?php self::render_skip_link(); ?>
		</form>
		<?php
	}

	/**
	 * Step 3 — toggle the bundled example agent so the user has something to
	 * chat with right away.
	 */
	private static function render_step_agent(): void {
		$enabled = '1' === (string) get_option( self::OPTION_ENABLE_EXAMPLE, '' );
		self::render_step_indicator( 3, 3 );
		?>
		<h1 style="margin-top:0;"><?php esc_html_e( 'Enable the example agent', 'openclawp' ); ?></h1>
		<p>
			<?php
			esc_html_e(
				"Turn on the bundled example agent so there's something to chat with right away. You can register your own agents later via PHP, or via the Agent Files surface.",
				'openclawp'
			);
			?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:24px;">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_STEP ); ?>" />
			<input type="hidden" name="step" value="3" />
			<?php wp_nonce_field( self::NONCE_STEP ); ?>
			<p>
				<label>
					<input type="checkbox" name="enable_example_agent" value="1" <?php checked( $enabled ); ?> />
					<?php esc_html_e( 'Enable the bundled example agent (openclawp-example).', 'openclawp' ); ?>
				</label>
			</p>
			<p style="margin-top:24px;">
				<button type="submit" class="button button-primary">
					<?php esc_html_e( 'Finish setup', 'openclawp' ); ?>
				</button>
				<?php self::render_skip_link(); ?>
			</p>
		</form>
		<?php
	}

	/**
	 * Done — hand off to the Chat page.
	 */
	private static function render_step_done(): void {
		$chat_url = admin_url( 'admin.php?page=' . OpenclaWP_Admin::PAGE_SLUG );
		?>
		<h1 style="margin-top:0;"><?php esc_html_e( "You're ready", 'openclawp' ); ?></h1>
		<p>
			<?php
			esc_html_e(
				'Open the Chat page to start talking to your agent. The floating chat panel is also reachable from the admin toolbar on every wp-admin screen.',
				'openclawp'
			);
			?>
		</p>
		<p style="margin-top:24px;">
			<a class="button button-primary" href="<?php echo esc_url( $chat_url ); ?>">
				<?php esc_html_e( 'Open Chat', 'openclawp' ); ?>
			</a>
		</p>
		<?php
	}

	/**
	 * Handle each step's POST. Validates the nonce + capability, persists the
	 * step's payload, then redirects to the next step.
	 */
	public static function handle_step(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'openclawp' ) );
		}
		check_admin_referer( self::NONCE_STEP );

		$step = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( (string) $_POST['step'] ) ) : '1';

		switch ( $step ) {
			case '2':
				// Provider step has nothing to persist — the user just
				// acknowledges that a provider is installed. Move on.
				$next = '3';
				break;
			case '3':
				$enable = isset( $_POST['enable_example_agent'] ) ? '1' : '0';
				update_option( self::OPTION_ENABLE_EXAMPLE, $enable );
				$next = 'done';
				break;
			case '1':
			default:
				$next = '2';
				break;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => self::PAGE_SLUG,
					'step' => $next,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Detect installed WP AI Client connector / provider plugins by checking
	 * for class names each provider plugin exports. We match against multiple
	 * possible class names per provider because the WP AI Client connectors
	 * ecosystem hasn't fully standardised yet.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function detect_providers(): array {
		$providers = array(
			array(
				'slug'        => 'ollama',
				'label'       => __( 'Ollama (local)', 'openclawp' ),
				'install_url' => 'https://wordpress.org/plugins/search/ollama/',
				'classes'     => array(
					'WP_AI_Client_Ollama_Provider',
					'Ollama_AI_Provider',
					'WPAI_Ollama',
				),
			),
			array(
				'slug'        => 'anthropic',
				'label'       => __( 'Anthropic (Claude)', 'openclawp' ),
				'install_url' => 'https://wordpress.org/plugins/search/anthropic/',
				'classes'     => array(
					'WP_AI_Client_Anthropic_Provider',
					'Anthropic_AI_Provider',
					'WPAI_Anthropic',
				),
			),
			array(
				'slug'        => 'openai',
				'label'       => __( 'OpenAI', 'openclawp' ),
				'install_url' => 'https://wordpress.org/plugins/search/openai/',
				'classes'     => array(
					'WP_AI_Client_OpenAI_Provider',
					'OpenAI_AI_Provider',
					'WPAI_OpenAI',
				),
			),
			array(
				'slug'        => 'google',
				'label'       => __( 'Google (Gemini)', 'openclawp' ),
				'install_url' => 'https://wordpress.org/plugins/search/gemini/',
				'classes'     => array(
					'WP_AI_Client_Google_Provider',
					'Google_AI_Provider',
					'WPAI_Google',
				),
			),
		);

		foreach ( $providers as &$provider ) {
			$installed = false;
			foreach ( (array) $provider['classes'] as $class_name ) {
				if ( class_exists( $class_name ) ) {
					$installed = true;
					break;
				}
			}
			$provider['installed'] = $installed;
		}
		unset( $provider );

		return $providers;
	}
}
