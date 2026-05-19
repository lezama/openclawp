<?php
/**
 * Floating admin-bar chat panel.
 *
 * Adds an `openclawp-chat-toggle` node to the wp-admin toolbar and enqueues
 * a small React drawer on every admin screen. Clicking the toolbar item
 * slides the panel in from the right; the drawer hosts the shared
 * `<ChatSurface>` (the same conversation UI used on `/wp-admin/admin.php
 * ?page=openclawp` and inside the `wp:openclawp/chat` block).
 *
 * The panel is enabled by default for users with `manage_options`. Hosts
 * can disable it via the `openclawp_admin_bar_panel_enabled` filter.
 *
 * @package OpenclaWP
 * @since   0.10.0
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Admin_Bar_Panel {

	private const HANDLE       = 'openclawp-admin-bar-panel';
	private const BUILD_DIR    = 'blocks/chat-panel/build';
	private const TOGGLE_NODE  = 'openclawp-chat-toggle';
	private const TOGGLE_EVENT = 'openclawp:panel:toggle';

	public static function register(): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		add_action( 'admin_bar_menu', array( __CLASS__, 'add_toolbar_node' ), 90 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_footer', array( __CLASS__, 'render_mount_point' ) );
		add_action( 'admin_footer', array( __CLASS__, 'print_toggle_script' ), 20 );
	}

	/**
	 * Whether the floating panel should be wired this request.
	 *
	 * Filter `openclawp_admin_bar_panel_enabled` returns the gate; default
	 * is `true` for users with `manage_options`. Hosts that want a
	 * different permission model can return false (or true for broader
	 * audiences) here.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		if ( ! is_admin() ) {
			return false;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		/**
		 * Filter whether the openclaWP floating admin-bar chat panel renders.
		 *
		 * @since 0.10.0
		 *
		 * @param bool $enabled Default true (subject to `manage_options`).
		 */
		return (bool) apply_filters( 'openclawp_admin_bar_panel_enabled', true );
	}

	/**
	 * Add the `openclawp-chat-toggle` node to the admin bar.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar Toolbar instance.
	 */
	public static function add_toolbar_node( $wp_admin_bar ): void {
		if ( ! ( $wp_admin_bar instanceof WP_Admin_Bar ) ) {
			return;
		}

		$title = '<span class="ab-icon dashicons dashicons-format-chat" aria-hidden="true"></span>'
			. '<span class="ab-label screen-reader-text">'
			. esc_html__( 'Open openclaWP chat', 'openclawp' )
			. '</span>';

		$wp_admin_bar->add_node(
			array(
				'id'     => self::TOGGLE_NODE,
				'title'  => $title,
				'href'   => '#',
				'parent' => 'top-secondary',
				'meta'   => array(
					'class' => 'openclawp-admin-bar-toggle',
				),
			)
		);
	}

	/**
	 * Enqueue the panel script + style on every admin screen.
	 */
	public static function enqueue_assets(): void {
		$asset_file = OPENCLAWP_PATH . self::BUILD_DIR . '/view.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			// Build hasn't been run yet — fail silently rather than 500ing.
			return;
		}

		$asset = include $asset_file; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- vetted, plugin-owned path.
		if ( ! is_array( $asset ) ) {
			return;
		}

		wp_enqueue_script(
			self::HANDLE,
			plugins_url( self::BUILD_DIR . '/view.js', OPENCLAWP_PATH . 'openclawp.php' ),
			isset( $asset['dependencies'] ) ? (array) $asset['dependencies'] : array(),
			isset( $asset['version'] ) ? (string) $asset['version'] : OPENCLAWP_VERSION,
			true
		);

		wp_localize_script(
			self::HANDLE,
			'openclaWPConfig',
			array(
				'restNamespace' => 'openclawp/v1',
				'bridgeUrl'     => esc_url_raw( rest_url( 'openclawp/v1/agenttic' ) ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
			)
		);

		$style_file = OPENCLAWP_PATH . self::BUILD_DIR . '/view.css';
		if ( is_readable( $style_file ) ) {
			wp_enqueue_style(
				self::HANDLE,
				plugins_url( self::BUILD_DIR . '/view.css', OPENCLAWP_PATH . 'openclawp.php' ),
				array(),
				isset( $asset['version'] ) ? (string) $asset['version'] : OPENCLAWP_VERSION
			);
		}
	}

	/**
	 * Print the React mount point in the admin footer.
	 *
	 * Carries the filtered agent list + REST nonce as data attributes — the
	 * same shape the chat block emits. The list is filtered identically to
	 * `blocks/chat/render.php` (specialty agents excluded, then run through
	 * the `openclawp_chat_block_agents` filter).
	 */
	public static function render_mount_point(): void {
		if ( ! function_exists( 'wp_get_agents' ) ) {
			return;
		}

		$agents          = self::filtered_chat_agents();
		$default_agent   = '';
		$payload         = array();
		foreach ( $agents as $slug => $agent_obj ) {
			$payload[] = array(
				'slug'  => (string) $slug,
				'label' => $agent_obj instanceof WP_Agent
					? (string) $agent_obj->get_label()
					: (string) $slug,
			);
		}

		?>
		<div
			id="openclawp-admin-bar-panel-root"
			data-agents="<?php echo esc_attr( (string) wp_json_encode( $payload ) ); ?>"
			data-default-agent="<?php echo esc_attr( $default_agent ); ?>"
			data-rest-namespace="openclawp/v1"
			data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
		></div>
		<?php
	}

	/**
	 * Inline script that wires the admin-bar `<a>` to dispatch the toggle
	 * event the React panel listens for.
	 *
	 * Kept inline (one event-listener add) to avoid an extra HTTP request
	 * for ~10 lines of JS.
	 */
	public static function print_toggle_script(): void {
		$event = self::TOGGLE_EVENT;
		?>
		<script id="openclawp-admin-bar-toggle-script">
			( function () {
				var link = document.querySelector( '#wp-admin-bar-<?php echo esc_attr( self::TOGGLE_NODE ); ?> a' );
				if ( ! link ) {
					return;
				}
				link.addEventListener( 'click', function ( event ) {
					event.preventDefault();
					document.dispatchEvent( new CustomEvent( <?php echo wp_json_encode( $event ); ?> ) );
				} );
			}() );
		</script>
		<?php
	}

	/**
	 * The registered agent list, filtered the same way the chat block
	 * filters it (workflow drafter and other specialty agents removed,
	 * then run through `openclawp_chat_block_agents`).
	 *
	 * @return array<string, WP_Agent>
	 */
	private static function filtered_chat_agents(): array {
		$agents = wp_get_agents();
		$agents = array_filter(
			$agents,
			static function ( $agent_obj ) {
				if ( $agent_obj instanceof WP_Agent && method_exists( $agent_obj, 'get_meta' ) ) {
					$meta = $agent_obj->get_meta();
					if ( isset( $meta['source_type'] ) && 'workflow-drafter' === $meta['source_type'] ) {
						return false;
					}
				}
				return true;
			}
		);
		/** This filter is documented in blocks/chat/render.php */
		return (array) apply_filters( 'openclawp_chat_block_agents', $agents );
	}
}
