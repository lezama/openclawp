<?php
/**
 * "WhatsApp" submenu under openclaWP — pair via QR rendered in the browser.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Wacli_Admin {

	private const PARENT_SLUG = 'openclawp';
	private const PAGE_SLUG   = 'openclawp-whatsapp';

	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_submenu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function register_submenu(): void {
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'WhatsApp', 'openclawp' ),
			__( 'WhatsApp', 'openclawp' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function enqueue_assets( $hook ): void {
		if ( 'openclawp_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}
		$asset_dir = OPENCLAWP_PATH . 'assets/';
		$asset_url = plugins_url( 'assets/', OPENCLAWP_PLUGIN_FILE );

		// Bundled tiny QR generator (5KB). Pinned: davidshimjs/qrcodejs equivalent.
		wp_enqueue_script(
			'openclawp-qrcode',
			$asset_url . 'qrcode.min.js',
			array(),
			'1.0.0',
			true
		);

		wp_enqueue_script(
			'openclawp-wacli-admin',
			$asset_url . 'wacli-admin.js',
			array( 'openclawp-qrcode', 'wp-api-fetch' ),
			OPENCLAWP_VERSION,
			true
		);

		wp_localize_script(
			'openclawp-wacli-admin',
			'openclaWPWacli',
			array(
				'restNamespace' => 'openclawp/v1',
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'pollInterval'  => 2000,
				'agent'         => (string) get_option( 'openclawp_wacli_agent', '' ),
				'allowedJids'   => (string) get_option( OpenclaWP_Wacli_Transport::ALLOWED_OPTION, '' ),
			)
		);

		wp_enqueue_style(
			'openclawp-wacli-admin',
			$asset_url . 'wacli-admin.css',
			array(),
			OPENCLAWP_VERSION
		);
	}

	public static function render_page(): void {
		$binary = OpenclaWP_Wacli_Process::resolve_binary();
		?>
		<div class="wrap openclawp-wrap openclawp-wacli">
			<h1><?php esc_html_e( 'openclaWP — WhatsApp', 'openclawp' ); ?></h1>

			<?php if ( '' === $binary ) : ?>
				<div class="notice notice-error">
					<p><?php
					printf(
						/* translators: %s: install command */
						esc_html__( 'wacli binary not found. Install with %s, then reload this page.', 'openclawp' ),
						'<code>brew install steipete/tap/wacli</code>'
					);
					?></p>
				</div>
			<?php endif; ?>

			<div class="openclawp-wacli-state" id="openclawp-wacli-state" data-binary="<?php echo esc_attr( $binary ); ?>">
				<p class="openclawp-wacli-loading"><?php esc_html_e( 'Loading state…', 'openclawp' ); ?></p>
			</div>

			<form class="openclawp-wacli-settings card" id="openclawp-wacli-settings-form">
				<h2><?php esc_html_e( 'Settings', 'openclawp' ); ?></h2>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="openclawp-wacli-agent"><?php esc_html_e( 'Target agent', 'openclawp' ); ?></label></th>
						<td>
							<select name="agent" id="openclawp-wacli-agent">
								<option value=""><?php esc_html_e( '— select an agent —', 'openclawp' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Incoming WhatsApp messages are forwarded to this agent via the openclawp/chat ability. Register an agent on wp_agents_api_init.', 'openclawp' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="openclawp-wacli-allowed"><?php esc_html_e( 'Allowed chats', 'openclawp' ); ?></label></th>
						<td>
							<textarea name="allowed_jids" id="openclawp-wacli-allowed" rows="4" cols="60" class="large-text code" placeholder="15551234567@s.whatsapp.net&#10;120363xxxxxxxxxxxx@g.us"></textarea>
							<p class="description">
								<?php
								echo wp_kses(
									__( 'One JID per line (commas also work). DMs end in <code>@s.whatsapp.net</code>, groups in <code>@g.us</code>, channels in <code>@newsletter</code>. Leave empty to allow every inbound chat (risky in shared accounts). Find a JID after pairing with <code>wacli chats list --json</code>.', 'openclawp' ),
									array( 'code' => array() )
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="openclawp-wacli-binary"><?php esc_html_e( 'wacli binary', 'openclawp' ); ?></label></th>
						<td>
							<input type="text" name="binary" id="openclawp-wacli-binary" class="regular-text code" placeholder="<?php echo esc_attr( $binary ?: 'wacli' ); ?>" />
							<p class="description"><?php
								echo wp_kses(
									sprintf(
										/* translators: %s: detected wacli path */
										__( 'Optional. Auto-detected: %s. Override only if PHP cannot resolve it from PATH.', 'openclawp' ),
										'<code>' . esc_html( $binary ?: 'not found' ) . '</code>'
									),
									array( 'code' => array() )
								);
							?></p>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'openclawp' ); ?></button>
					<span class="openclawp-wacli-save-indicator" aria-live="polite"></span>
				</p>
			</form>
		</div>
		<?php
	}
}
