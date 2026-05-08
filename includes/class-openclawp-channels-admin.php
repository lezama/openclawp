<?php
/**
 * Channels admin — list view + detail dispatcher.
 *
 * Renders `wp-admin → openclaWP → Channels`. Each registered channel
 * (WhatsApp via wacli, Telegram, Email, future) shows up as a card on the
 * list view. Clicking "Configure" opens its detail view, which is rendered
 * by the channel-specific module via the `detail_renderer` callback set in
 * the registration.
 *
 * Channels register themselves through the `openclawp_channels` filter:
 *
 *     add_filter( 'openclawp_channels', function ( array $channels ): array {
 *         $channels[] = array(
 *             'id'              => 'wacli',
 *             'name'            => 'WhatsApp',
 *             'subtitle'        => 'via openclaw/wacli',
 *             'description'     => '…',
 *             'status'          => 'connected', // or 'not-configured', 'failed'
 *             'detail_renderer' => array( OpenclaWP_Wacli_Admin::class, 'render_detail' ),
 *             'detail_assets'   => array( OpenclaWP_Wacli_Admin::class, 'enqueue_detail_assets' ),
 *         );
 *         return $channels;
 *     } );
 *
 * @package OpenclaWP
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Channels_Admin {

	public const PARENT_SLUG = 'openclawp';
	public const PAGE_SLUG   = 'openclawp-channels';

	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_submenu' ), 15 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function enqueue_assets( $hook ): void {
		if ( 'openclawp_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style(
			'openclawp-channels-admin',
			plugins_url( 'assets/channels-admin.css', OPENCLAWP_PLUGIN_FILE ),
			array(),
			OPENCLAWP_VERSION
		);
		// Detail-view assets defer to the active channel's `detail_assets`
		// callback. List view loads only the channels stylesheet above.
		self::maybe_enqueue_detail_assets( $hook );
	}

	public static function register_submenu(): void {
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Channels', 'openclawp' ),
			__( 'Channels', 'openclawp' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Single page renderer that branches: list view (default) or detail view
	 * (when a `?channel=<id>` query arg matches a registered channel).
	 */
	public static function render_page(): void {
		$active = self::active_channel();
		if ( null !== $active ) {
			self::render_detail( $active );
			return;
		}
		self::render_list();
	}

	/**
	 * Defer asset enqueue to whichever channel is currently being viewed in
	 * detail. List view doesn't enqueue anything channel-specific.
	 */
	public static function maybe_enqueue_detail_assets( $hook ): void {
		if ( 'openclawp_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}
		$active = self::active_channel();
		if ( null === $active ) {
			return;
		}
		if ( ! empty( $active['detail_assets'] ) && is_callable( $active['detail_assets'] ) ) {
			call_user_func( $active['detail_assets'] );
		}
	}

	/**
	 * Get all channels registered via the `openclawp_channels` filter,
	 * normalized to a predictable shape.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_channels(): array {
		/**
		 * Filter the list of channels exposed under wp-admin → openclaWP → Channels.
		 *
		 * @since 1.1.0
		 *
		 * @param array $channels Each entry is an associative array with at least
		 *                        `id`, `name`, `description`, `status`, and one of
		 *                        `detail_renderer` (callable) or `detail_url`.
		 */
		$channels = (array) apply_filters( 'openclawp_channels', array() );

		$out = array();
		foreach ( $channels as $channel ) {
			if ( ! is_array( $channel ) || empty( $channel['id'] ) ) {
				continue;
			}
			$out[] = wp_parse_args(
				$channel,
				array(
					'id'              => '',
					'name'            => '',
					'subtitle'        => '',
					'description'     => '',
					'status'          => 'unknown',
					'logo_url'        => '',
					'detail_renderer' => null,
					'detail_assets'   => null,
					'detail_url'      => '',
				)
			);
		}
		return $out;
	}

	/**
	 * Resolve the currently-viewed channel from the `channel` query arg.
	 * Returns the registered entry, or null if the arg is missing/invalid.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function active_channel(): ?array {
		$id = isset( $_GET['channel'] ) ? sanitize_key( wp_unslash( $_GET['channel'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation between admin views.
		if ( '' === $id ) {
			return null;
		}
		foreach ( self::get_channels() as $channel ) {
			if ( $channel['id'] === $id ) {
				return $channel;
			}
		}
		return null;
	}

	private static function render_list(): void {
		$channels = self::get_channels();
		?>
		<div class="wrap openclawp-wrap openclawp-channels">
			<h1><?php esc_html_e( 'Channels', 'openclawp' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Surfaces your agent listens on. Each channel pairs an external messaging surface (WhatsApp, Telegram, …) with the agents/chat dispatcher.', 'openclawp' ); ?>
			</p>

			<?php if ( empty( $channels ) ) : ?>
				<div class="notice notice-info inline">
					<p><?php esc_html_e( 'No channels registered. Activate a transport plugin to add one.', 'openclawp' ); ?></p>
				</div>
			<?php else : ?>
				<div class="openclawp-channels-grid">
					<?php foreach ( $channels as $channel ) : ?>
						<?php self::render_card( $channel ); ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_card( array $channel ): void {
		$detail_url = '' !== $channel['detail_url']
			? $channel['detail_url']
			: add_query_arg(
				array( 'page' => self::PAGE_SLUG, 'channel' => $channel['id'] ),
				admin_url( 'admin.php' )
			);

		$status_label = self::status_label( $channel['status'] );
		?>
		<div class="openclawp-channel-card card">
			<header class="openclawp-channel-card__header">
				<h2 class="openclawp-channel-card__title">
					<?php echo esc_html( $channel['name'] ); ?>
					<?php if ( '' !== $channel['subtitle'] ) : ?>
						<span class="openclawp-channel-card__subtitle"><?php echo esc_html( $channel['subtitle'] ); ?></span>
					<?php endif; ?>
				</h2>
				<span class="openclawp-channel-card__status openclawp-status--<?php echo esc_attr( $channel['status'] ); ?>">
					<?php echo esc_html( $status_label ); ?>
				</span>
			</header>
			<?php if ( '' !== $channel['description'] ) : ?>
				<p class="openclawp-channel-card__description"><?php echo esc_html( $channel['description'] ); ?></p>
			<?php endif; ?>
			<p class="openclawp-channel-card__actions">
				<a class="button button-primary" href="<?php echo esc_url( $detail_url ); ?>">
					<?php esc_html_e( 'Configure', 'openclawp' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	private static function render_detail( array $channel ): void {
		$back_url = add_query_arg( array( 'page' => self::PAGE_SLUG ), admin_url( 'admin.php' ) );
		?>
		<div class="wrap openclawp-wrap openclawp-channel-detail">
			<p class="openclawp-channel-detail__back">
				<a href="<?php echo esc_url( $back_url ); ?>">&larr; <?php esc_html_e( 'All channels', 'openclawp' ); ?></a>
			</p>
			<?php
			if ( is_callable( $channel['detail_renderer'] ) ) {
				call_user_func( $channel['detail_renderer'], $channel );
			} else {
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					esc_html__( 'This channel did not provide a detail view.', 'openclawp' )
				);
			}
			?>
		</div>
		<?php
	}

	private static function status_label( string $status ): string {
		switch ( $status ) {
			case 'connected':
				return __( 'Connected', 'openclawp' );
			case 'failed':
				return __( 'Failed', 'openclawp' );
			case 'pairing':
				return __( 'Pairing', 'openclawp' );
			case 'syncing':
				return __( 'Syncing', 'openclawp' );
			case 'not-configured':
				return __( 'Not configured', 'openclawp' );
		}
		return __( 'Unknown', 'openclawp' );
	}
}
