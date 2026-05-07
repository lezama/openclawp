<?php
/**
 * Admin menu + chat page.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Admin {

	private const PAGE_SLUG    = 'openclawp';
	private const SCRIPT_HANDLE = 'openclawp-admin-chat';

	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function register_menu(): void {
		add_menu_page(
			__( 'openclaWP', 'openclawp' ),
			__( 'openclaWP', 'openclawp' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_chat_page' ),
			'dashicons-format-chat',
			60
		);

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Chat', 'openclawp' ),
			__( 'Chat', 'openclawp' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_chat_page' )
		);
	}

	public static function enqueue_assets( string $hook ): void {
		if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			self::SCRIPT_HANDLE,
			plugins_url( 'assets/admin-chat.css', OPENCLAWP_PLUGIN_FILE ),
			array(),
			OPENCLAWP_VERSION
		);

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'assets/admin-chat.js', OPENCLAWP_PLUGIN_FILE ),
			array( 'wp-api-fetch' ),
			OPENCLAWP_VERSION,
			true
		);

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'openclaWPConfig',
			array(
				'restNamespace' => 'openclawp/v1',
				'nonce'         => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	public static function render_chat_page(): void {
		?>
		<div class="wrap openclawp-wrap">
			<h1><?php esc_html_e( 'openclaWP — Chat', 'openclawp' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Talk to a registered agent. Sessions persist as openclawp_session posts.', 'openclawp' ); ?>
			</p>

			<div class="openclawp-toolbar">
				<label for="openclawp-agent">
					<?php esc_html_e( 'Agent', 'openclawp' ); ?>
				</label>
				<select id="openclawp-agent"></select>

				<button type="button" id="openclawp-new-session" class="button">
					<?php esc_html_e( 'New session', 'openclawp' ); ?>
				</button>

				<span id="openclawp-session-id" class="openclawp-session-id"></span>
			</div>

			<div id="openclawp-transcript" class="openclawp-transcript" aria-live="polite"></div>

			<form id="openclawp-form" class="openclawp-form">
				<textarea
					id="openclawp-input"
					rows="3"
					placeholder="<?php esc_attr_e( 'Type a message…', 'openclawp' ); ?>"
					required
				></textarea>
				<button type="submit" class="button button-primary">
					<?php esc_html_e( 'Send', 'openclawp' ); ?>
				</button>
			</form>
		</div>
		<?php
	}
}
