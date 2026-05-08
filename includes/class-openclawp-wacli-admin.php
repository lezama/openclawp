<?php
/**
 * WhatsApp (wacli) channel — registers itself with the Channels admin and
 * provides the detail-view renderer for `wp-admin → openclaWP → Channels →
 * Configure`.
 *
 * No longer registers a top-level submenu of its own; that lives in
 * OpenclaWP_Channels_Admin which discovers channels via the
 * `openclawp_channels` filter.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Wacli_Admin {

	public const CHANNEL_ID = 'wacli';

	public static function register(): void {
		add_filter( 'openclawp_channels', array( __CLASS__, 'register_channel' ) );
	}

	/**
	 * Register the wacli channel with the Channels admin.
	 *
	 * @param array $channels
	 * @return array
	 */
	public static function register_channel( array $channels ): array {
		$channels[] = array(
			'id'              => self::CHANNEL_ID,
			'name'            => __( 'WhatsApp', 'openclawp' ),
			'subtitle'        => __( 'via openclaw/wacli', 'openclawp' ),
			'description'     => __( 'Pair this site as a WhatsApp linked device using the openclaw/wacli CLI. Native whatsmeow protocol; no Beeper Desktop or Meta Business Account required.', 'openclawp' ),
			'status'          => self::current_status(),
			'detail_renderer' => array( __CLASS__, 'render_detail' ),
			'detail_assets'   => array( __CLASS__, 'enqueue_detail_assets' ),
		);
		return $channels;
	}

	/**
	 * Map the wacli process state machine to the Channels list status pill.
	 */
	private static function current_status(): string {
		if ( ! class_exists( 'OpenclaWP_Wacli_Process' ) ) {
			return 'not-configured';
		}
		$state = OpenclaWP_Wacli_Process::get_state();
		switch ( $state['mode'] ?? '' ) {
			case OpenclaWP_Wacli_Process::MODE_SYNCING:
				return 'connected';
			case OpenclaWP_Wacli_Process::MODE_PAIRING:
				return 'pairing';
			case OpenclaWP_Wacli_Process::MODE_FAILED:
				return 'failed';
		}
		return 'not-configured';
	}

	/**
	 * Enqueue the wacli admin JS + CSS + QR lib. Called by Channels_Admin
	 * only when the wacli detail view is the active page.
	 */
	public static function enqueue_detail_assets(): void {
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

	/**
	 * Channel detail-view renderer. Receives the registered channel array.
	 */
	public static function render_detail( array $channel ): void {
		unset( $channel );
		$binary = OpenclaWP_Wacli_Process::resolve_binary();
		?>
		<div class="openclawp-wacli">
			<h1><?php esc_html_e( 'WhatsApp', 'openclawp' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Pair this site as a WhatsApp linked device. Incoming messages from allowed chats are forwarded to your selected agent through the agents/chat dispatcher.', 'openclawp' ); ?>
			</p>

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
							<p class="description"><?php esc_html_e( 'Incoming WhatsApp messages are forwarded to this agent via the agents/chat dispatcher. Register an agent on wp_agents_api_init.', 'openclawp' ); ?></p>
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
