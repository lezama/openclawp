<?php
/**
 * Admin menu — renders the openclawp/chat block.
 *
 * The chat UI itself lives in the block at blocks/chat/. The admin page is
 * just a thin wrapper that renders that block, so the same surface works
 * embedded in any post or front-end template via shortcode/programmatic
 * insertion.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Admin {

	private const PAGE_SLUG = 'openclawp';

	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
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

		// Explicit "Chat" submenu mirrors the parent so the auto-added first
		// item reads "Chat" instead of "openclaWP". Uses the same slug as the
		// parent, so WordPress replaces the auto-added entry rather than
		// adding a second one.
		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Chat', 'openclawp' ),
			__( 'Chat', 'openclawp' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_chat_page' )
		);
	}

	public static function render_chat_page(): void {
		?>
		<div class="wrap openclawp-wrap">
			<h1><?php esc_html_e( 'openclaWP — Chat', 'openclawp' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Talk to a registered agent. Sessions persist as openclawp_session posts.', 'openclawp' ); ?>
			</p>
			<?php echo do_blocks( '<!-- wp:openclawp/chat /-->' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- do_blocks output is safe. ?>
		</div>
		<?php
	}
}
