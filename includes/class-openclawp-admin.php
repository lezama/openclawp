<?php
/**
 * Admin menu — renders the openclawp/chat block.
 *
 * The chat UI itself lives in the block at blocks/chat/. The admin page is
 * just a thin wrapper that renders that block, so the same surface works
 * embedded in any post or front-end template via shortcode/programmatic
 * insertion.
 *
 * Beneath the chat we render a "Discover" panel — a one-line index of
 * every openclaWP capability surface (Channels, Workflows, MCP Servers,
 * …) with its current population state and a link. With the rest of the
 * submenu hide-when-empty (see {@see OpenclaWP_Admin_Menu_Visibility}),
 * this panel is the discovery anchor for surfaces that haven't been set
 * up yet.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Admin {

	public const PAGE_SLUG = 'openclawp';

	/**
	 * User-meta key for "I've dismissed the Discover panel".
	 *
	 * Per-user so power users can hide it without affecting the next admin
	 * who opens this site. Cleared via the Settings page.
	 */
	public const DISCOVER_DISMISSED_META = 'openclawp_discover_dismissed';

	public const ACTION_DISCOVER_DISMISS = 'openclawp_discover_dismiss';
	public const ACTION_DISCOVER_RESTORE = 'openclawp_discover_restore';

	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_' . self::ACTION_DISCOVER_DISMISS, array( __CLASS__, 'handle_discover_dismiss' ) );
		add_action( 'admin_post_' . self::ACTION_DISCOVER_RESTORE, array( __CLASS__, 'handle_discover_restore' ) );
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

		// Same slug as the parent so the auto-added first item reads "Chat".
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
				<?php esc_html_e( 'Talk to a registered agent. Your conversation history is saved automatically.', 'openclawp' ); ?>
			</p>
			<?php echo do_blocks( '<!-- wp:openclawp/chat /-->' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- do_blocks output is safe. ?>
			<?php self::render_discover_panel(); ?>
		</div>
		<?php
	}

	/**
	 * Render the "Discover capabilities" panel beneath the chat block.
	 *
	 * Lists every capability surface with a short description, its current
	 * population count (or "Not set up yet" hint), and a link. The panel is
	 * dismissible per user — once dismissed, it stays hidden until the user
	 * restores it from the Settings page.
	 */
	public static function render_discover_panel(): void {
		if ( self::discover_is_dismissed( get_current_user_id() ) ) {
			return;
		}

		$capabilities = self::discover_capabilities();
		if ( empty( $capabilities ) ) {
			return;
		}

		$dismiss_url = wp_nonce_url(
			add_query_arg(
				array( 'action' => self::ACTION_DISCOVER_DISMISS ),
				admin_url( 'admin-post.php' )
			),
			self::ACTION_DISCOVER_DISMISS
		);

		?>
		<div class="postbox openclawp-discover" style="margin-top:24px;">
			<div class="postbox-header">
				<h2 class="hndle" style="padding:8px 12px;">
					<?php esc_html_e( 'Discover capabilities', 'openclawp' ); ?>
				</h2>
			</div>
			<div class="inside">
				<p class="description" style="margin-top:0;">
					<?php esc_html_e( 'Each row is one thing openclaWP can do. Empty rows are not in the sidebar yet — set one up and it appears.', 'openclawp' ); ?>
				</p>
				<table class="widefat striped openclawp-discover-table">
					<tbody>
						<?php foreach ( $capabilities as $capability ) : ?>
							<?php self::render_discover_row( $capability ); ?>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p style="margin-bottom:0;text-align:right;">
					<a href="<?php echo esc_url( $dismiss_url ); ?>" class="button-link">
						<?php esc_html_e( 'Hide this panel', 'openclawp' ); ?>
					</a>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render one row of the Discover table.
	 *
	 * @param array<string,mixed> $capability Capability descriptor.
	 */
	private static function render_discover_row( array $capability ): void {
		$slug         = (string) ( $capability['slug'] ?? '' );
		$label        = (string) ( $capability['label'] ?? '' );
		$description  = (string) ( $capability['description'] ?? '' );
		$create_label = (string) ( $capability['create_label'] ?? __( 'Set up', 'openclawp' ) );
		$open_label   = (string) ( $capability['open_label'] ?? __( 'Open', 'openclawp' ) );
		$count_cb     = $capability['count_callback'] ?? null;

		if ( '' === $slug || '' === $label ) {
			return;
		}

		$count = null;
		if ( is_callable( $count_cb ) ) {
			$result = call_user_func( $count_cb, $slug );
			if ( is_int( $result ) ) {
				$count = $result;
			}
		}

		$has_content = is_int( $count ) && $count > 0;
		$url         = admin_url( 'admin.php?page=' . $slug );

		if ( $has_content ) {
			$state_label = sprintf(
				/* translators: %d: number of items configured for this capability surface. */
				_n( '%d registered', '%d registered', $count, 'openclawp' ),
				$count
			);
			$cta_label = $open_label;
		} else {
			$state_label = __( 'Not set up yet', 'openclawp' );
			$cta_label   = $create_label;
		}

		?>
		<tr class="openclawp-discover-row openclawp-discover-row--<?php echo esc_attr( $has_content ? 'populated' : 'empty' ); ?>">
			<td style="width:24%;vertical-align:top;">
				<strong><?php echo esc_html( $label ); ?></strong>
			</td>
			<td style="vertical-align:top;">
				<?php echo esc_html( $description ); ?>
			</td>
			<td style="width:18%;vertical-align:top;">
				<?php if ( $has_content ) : ?>
					<span class="openclawp-discover-count"><?php echo esc_html( $state_label ); ?></span>
				<?php else : ?>
					<em class="openclawp-discover-empty"><?php echo esc_html( $state_label ); ?></em>
				<?php endif; ?>
			</td>
			<td style="width:14%;vertical-align:top;text-align:right;">
				<a class="button button-secondary" href="<?php echo esc_url( $url ); ?>">
					<?php echo esc_html( $cta_label ); ?>
				</a>
			</td>
		</tr>
		<?php
	}

	/**
	 * Default list of capability descriptors rendered by the Discover panel.
	 *
	 * Each descriptor is an associative array:
	 *
	 *  - `slug`           — Submenu slug (`openclawp-mcp-servers`, etc.).
	 *  - `label`          — Translated short title (e.g. "MCP Servers").
	 *  - `description`    — One-line, plain-English description.
	 *  - `count_callback` — Callable `fn(string $slug): ?int` returning the
	 *                      current entry count (or `null` if unknown). The
	 *                      default uses the same population check that gates
	 *                      the menu, so the panel and the sidebar can't
	 *                      disagree.
	 *  - `create_label`   — CTA shown when the row is empty.
	 *  - `open_label`     — CTA shown when the row is populated.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function discover_capabilities(): array {
		$count_callback = array( OpenclaWP_Admin_Menu_Visibility::class, 'surface_count' );

		$default = array(
			array(
				'slug'           => 'openclawp-channels',
				'label'          => __( 'Channels', 'openclawp' ),
				'description'    => __( 'Connect your agent to WhatsApp, Telegram, and other messaging apps so external users can chat with it.', 'openclawp' ),
				'count_callback' => $count_callback,
				'create_label'   => __( 'Connect a channel', 'openclawp' ),
				'open_label'     => __( 'Manage', 'openclawp' ),
			),
			array(
				'slug'           => 'openclawp-workflows',
				'label'          => __( 'Workflows', 'openclawp' ),
				'description'    => __( 'Reusable recipes that run on a trigger — chain steps that read data, call services, or ask an AI agent to reason.', 'openclawp' ),
				'count_callback' => $count_callback,
				'create_label'   => __( 'Create your first workflow', 'openclawp' ),
				'open_label'     => __( 'Manage', 'openclawp' ),
			),
			array(
				'slug'           => 'openclawp-custom-tools',
				'label'          => __( 'Custom Tools', 'openclawp' ),
				'description'    => __( "Give your agent new capabilities. Each tool becomes callable on the agent's next turn — no PHP required.", 'openclawp' ),
				'count_callback' => $count_callback,
				'create_label'   => __( 'Build a tool', 'openclawp' ),
				'open_label'     => __( 'Manage', 'openclawp' ),
			),
			array(
				'slug'           => 'openclawp-routines',
				'label'          => __( 'Routines', 'openclawp' ),
				'description'    => __( 'Scheduled or event-driven runs of an agent against a fixed prompt.', 'openclawp' ),
				'count_callback' => $count_callback,
				'create_label'   => __( 'Set up a routine', 'openclawp' ),
				'open_label'     => __( 'Manage', 'openclawp' ),
			),
			array(
				'slug'           => 'openclawp-usage',
				'label'          => __( 'Usage', 'openclawp' ),
				'description'    => __( 'Per-turn token and cost dashboard for every agent run on this site.', 'openclawp' ),
				'count_callback' => $count_callback,
				'create_label'   => __( 'Open', 'openclawp' ),
				'open_label'     => __( 'View usage', 'openclawp' ),
			),
			array(
				'slug'           => 'openclawp-knowledge-base',
				'label'          => __( 'Knowledge Base', 'openclawp' ),
				'description'    => __( 'Index posts, pages, and URLs so the agent can quote your own content back at users.', 'openclawp' ),
				'count_callback' => $count_callback,
				'create_label'   => __( 'Add sources', 'openclawp' ),
				'open_label'     => __( 'Manage', 'openclawp' ),
			),
			array(
				'slug'           => 'openclawp-mcp-servers',
				'label'          => __( 'MCP Servers', 'openclawp' ),
				'description'    => __( "Expose your agent's tools to external AI clients like Claude Code, Cursor, or VS Code.", 'openclawp' ),
				'count_callback' => $count_callback,
				'create_label'   => __( 'Add MCP server', 'openclawp' ),
				'open_label'     => __( 'Manage', 'openclawp' ),
			),
			array(
				'slug'           => 'openclawp-whatsapp',
				'label'          => __( 'WhatsApp', 'openclawp' ),
				'description'    => __( 'Pair the agent with a WhatsApp business account (Cloud API) or self-hosted gateway.', 'openclawp' ),
				'count_callback' => $count_callback,
				'create_label'   => __( 'Connect WhatsApp', 'openclawp' ),
				'open_label'     => __( 'Manage', 'openclawp' ),
			),
			array(
				'slug'           => 'openclawp-connected-clients',
				'label'          => __( 'Connected Clients', 'openclawp' ),
				'description'    => __( 'OAuth clients (browser extensions, third-party apps) authorised to talk to your agent.', 'openclawp' ),
				'count_callback' => $count_callback,
				'create_label'   => __( 'Register a client', 'openclawp' ),
				'open_label'     => __( 'Manage', 'openclawp' ),
			),
			array(
				'slug'           => 'openclawp-decisions',
				'label'          => __( 'Tool activity', 'openclawp' ),
				'description'    => __( 'Log of every tool the agent called, when, with what arguments, and what it returned.', 'openclawp' ),
				'count_callback' => $count_callback,
				'create_label'   => __( 'Open', 'openclawp' ),
				'open_label'     => __( 'View log', 'openclawp' ),
			),
			array(
				'slug'           => 'openclawp-mcp-clients',
				'label'          => __( 'MCP Clients', 'openclawp' ),
				'description'    => __( 'Outbound MCP connections — let your agent call tools hosted on another MCP server.', 'openclawp' ),
				'count_callback' => $count_callback,
				'create_label'   => __( 'Add MCP client', 'openclawp' ),
				'open_label'     => __( 'Manage', 'openclawp' ),
			),
		);

		/**
		 * Filter the list of capability descriptors rendered in the
		 * "Discover" panel on the Chat admin page.
		 *
		 * Use this to add a host-specific row (e.g. a billing dashboard)
		 * or to replace the default copy. Each descriptor must be an
		 * associative array; see {@see self::discover_capabilities()} for
		 * the recognised keys.
		 *
		 * @since 0.10.0
		 *
		 * @param array<int,array<string,mixed>> $capabilities Default capability descriptors.
		 */
		$capabilities = (array) apply_filters( 'openclawp_discover_panel_capabilities', $default );

		// Normalise — drop rows without a slug + label so a bad filter can't
		// break the page render.
		$out = array();
		foreach ( $capabilities as $capability ) {
			if ( ! is_array( $capability ) ) {
				continue;
			}
			$slug  = isset( $capability['slug'] ) ? (string) $capability['slug'] : '';
			$label = isset( $capability['label'] ) ? (string) $capability['label'] : '';
			if ( '' === $slug || '' === $label ) {
				continue;
			}
			$out[] = $capability;
		}
		return $out;
	}

	/**
	 * Whether the current user has dismissed the Discover panel.
	 */
	public static function discover_is_dismissed( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		return (bool) get_user_meta( $user_id, self::DISCOVER_DISMISSED_META, true );
	}

	/**
	 * admin-post handler: persist the user's "hide this panel" choice.
	 */
	public static function handle_discover_dismiss(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'openclawp' ) );
		}
		check_admin_referer( self::ACTION_DISCOVER_DISMISS );

		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			update_user_meta( $user_id, self::DISCOVER_DISMISSED_META, 1 );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * admin-post handler: clear the user's "hide this panel" choice.
	 *
	 * Invoked from a footer link on the Settings page so a user who hid
	 * the panel can bring it back.
	 */
	public static function handle_discover_restore(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'openclawp' ) );
		}
		check_admin_referer( self::ACTION_DISCOVER_RESTORE );

		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			delete_user_meta( $user_id, self::DISCOVER_DISMISSED_META );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}
}
