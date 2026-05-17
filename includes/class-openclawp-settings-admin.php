<?php
/**
 * wp-admin → openclaWP → Settings.
 *
 * Site-wide settings page. Currently houses one option:
 *
 *   - `confirmation_threshold` — global default for tool-call confirmation
 *     gating (#40). One of `none | destructive (default) | write | external`.
 *
 * Per-user state ("Always allow" entries) lives in user-meta and is managed
 * inline on the same page for the current user.
 *
 * @package OpenclaWP
 * @since   0.8.0
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Settings_Admin {

	public const PAGE_SLUG     = 'openclawp-settings';
	public const OPTION_KEY    = 'openclawp_options';
	public const OPTION_GROUP  = 'openclawp_options_group';
	public const NONCE_ACTION  = 'openclawp_user_always_allow';
	public const NONCE_FIELD   = 'openclawp_user_always_allow_nonce';

	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_submenu' ), 25 );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_openclawp_remove_always_allow', array( __CLASS__, 'handle_remove_always_allow' ) );
	}

	public static function register_submenu(): void {
		add_submenu_page(
			OpenclaWP_Admin::PAGE_SLUG,
			__( 'Settings', 'openclawp' ),
			__( 'Settings', 'openclawp' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_options' ),
				'default'           => array(
					'confirmation_threshold' => OpenclaWP_Tool_Effects::DEFAULT_THRESHOLD,
				),
				'show_in_rest'      => false,
			)
		);
	}

	public static function sanitize_options( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$threshold = isset( $input['confirmation_threshold'] ) ? (string) $input['confirmation_threshold'] : '';
		return array(
			'confirmation_threshold' => OpenclaWP_Tool_Effects::normalize_threshold( $threshold ),
		);
	}

	public static function handle_remove_always_allow(): void {
		if ( ! current_user_can( 'read' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'openclawp' ) );
		}
		check_admin_referer( self::NONCE_ACTION );

		$ability = isset( $_POST['ability'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['ability'] ) ) : '';
		if ( '' !== $ability ) {
			OpenclaWP_Tool_Effects::remove_always_allow( get_current_user_id(), $ability );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'removed' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render_page(): void {
		$options   = (array) get_option( self::OPTION_KEY, array() );
		$threshold = OpenclaWP_Tool_Effects::normalize_threshold( $options['confirmation_threshold'] ?? '' );

		$user_id  = get_current_user_id();
		$allow    = OpenclaWP_Tool_Effects::get_always_allow_list( $user_id );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'openclaWP — Settings', 'openclawp' ); ?></h1>

			<?php if ( isset( $_GET['removed'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect flag. ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( '"Always allow" entry removed.', 'openclawp' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_GROUP );
				?>
				<h2><?php esc_html_e( 'Tool-call confirmation', 'openclawp' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Decide when the agent must pause for a human before running a tool. Read-only abilities are never gated.', 'openclawp' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="openclawp-threshold"><?php esc_html_e( 'Confirmation level', 'openclawp' ); ?></label></th>
						<td>
							<select id="openclawp-threshold" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[confirmation_threshold]">
								<?php foreach ( self::threshold_choices() as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $threshold, $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Default: destructive — ask before deleting / uninstalling / dropping anything, plus calls to external systems.', 'openclawp' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( '"Always allow" for your user', 'openclawp' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Tools you have pre-authorised. The agent will run these without prompting you again. Clear an entry to require confirmation next time.', 'openclawp' ); ?>
			</p>
			<?php if ( empty( $allow ) ) : ?>
				<p><em><?php esc_html_e( 'No tools pre-authorised yet.', 'openclawp' ); ?></em></p>
			<?php else : ?>
				<table class="widefat striped" style="max-width: 720px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Ability', 'openclawp' ); ?></th>
							<th><?php esc_html_e( 'Effect', 'openclawp' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $allow as $ability_name ) : ?>
							<tr>
								<td><code><?php echo esc_html( $ability_name ); ?></code></td>
								<td><code><?php echo esc_html( OpenclaWP_Tool_Effects::for_ability( $ability_name ) ); ?></code></td>
								<td>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
										<input type="hidden" name="action" value="openclawp_remove_always_allow" />
										<input type="hidden" name="ability" value="<?php echo esc_attr( $ability_name ); ?>" />
										<?php wp_nonce_field( self::NONCE_ACTION ); ?>
										<button type="submit" class="button-link-delete">
											<?php esc_html_e( 'Remove', 'openclawp' ); ?>
										</button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @return array<string,string>
	 */
	private static function threshold_choices(): array {
		return array(
			OpenclaWP_Tool_Effects::THRESHOLD_NONE        => __( 'None — never ask', 'openclawp' ),
			OpenclaWP_Tool_Effects::THRESHOLD_DESTRUCTIVE => __( 'Destructive (default) — ask before delete / uninstall / drop, plus external calls', 'openclawp' ),
			OpenclaWP_Tool_Effects::THRESHOLD_WRITE       => __( 'Write — ask before any mutation (create, update, delete, send)', 'openclawp' ),
			OpenclaWP_Tool_Effects::THRESHOLD_EXTERNAL    => __( 'External — ask for anything that is not a pure read', 'openclawp' ),
		);
	}
}
