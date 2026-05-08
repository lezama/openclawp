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
 *             'status'          => OpenclaWP_Channels_Admin::STATUS_CONNECTED,
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

	public const PAGE_SLUG = 'openclawp-channels';

	public const STATUS_CONNECTED      = 'connected';
	public const STATUS_PAIRING        = 'pairing';
	public const STATUS_FAILED         = 'failed';
	public const STATUS_NOT_CONFIGURED = 'not-configured';
	public const STATUS_UNKNOWN        = 'unknown';

	/** @var array<int,array<string,mixed>>|null Per-request memoization. */
	private static ?array $channels_cache = null;

	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_submenu' ), 15 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function register_submenu(): void {
		add_submenu_page(
			OpenclaWP_Admin::PAGE_SLUG,
			__( 'Channels', 'openclawp' ),
			__( 'Channels', 'openclawp' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function enqueue_assets( $hook ): void {
		if ( self::hook_suffix() !== $hook ) {
			return;
		}
		wp_enqueue_style(
			'openclawp-channels-admin',
			plugins_url( 'assets/channels-admin.css', OPENCLAWP_PLUGIN_FILE ),
			array(),
			OPENCLAWP_VERSION
		);
		$active = self::active_channel();
		if ( null !== $active && is_callable( $active['detail_assets'] ) ) {
			call_user_func( $active['detail_assets'] );
		}
	}

	public static function render_page(): void {
		$active = self::active_channel();
		if ( null !== $active ) {
			self::render_detail( $active );
			return;
		}
		self::render_list();
	}

	/**
	 * Get all channels registered via the `openclawp_channels` filter.
	 * Memoized for the lifetime of the request — the filter is invoked
	 * multiple times across the page render path.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_channels(): array {
		if ( null !== self::$channels_cache ) {
			return self::$channels_cache;
		}

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
					'name'            => '',
					'subtitle'        => '',
					'description'     => '',
					'status'          => self::STATUS_UNKNOWN,
					'detail_renderer' => null,
					'detail_assets'   => null,
					'detail_url'      => '',
				)
			);
		}

		self::$channels_cache = $out;
		return $out;
	}

	private static function hook_suffix(): string {
		return 'openclawp_page_' . self::PAGE_SLUG;
	}

	/**
	 * Resolve the currently-viewed channel from the `channel` query arg.
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
					<?php echo esc_html( self::status_label( $channel['status'] ) ); ?>
				</span>
			</header>
			<?php if ( '' !== $channel['description'] ) : ?>
				<p class="openclawp-channel-card__description"><?php echo esc_html( $channel['description'] ); ?></p>
			<?php endif; ?>
			<p class="openclawp-channel-card__actions">
				<a class="button button-primary" href="<?php echo esc_url( self::detail_url( $channel ) ); ?>">
					<?php esc_html_e( 'Configure', 'openclawp' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	private static function detail_url( array $channel ): string {
		if ( '' !== $channel['detail_url'] ) {
			return $channel['detail_url'];
		}
		return add_query_arg(
			array( 'page' => self::PAGE_SLUG, 'channel' => $channel['id'] ),
			admin_url( 'admin.php' )
		);
	}

	private static function render_detail( array $channel ): void {
		$back_url = add_query_arg( array( 'page' => self::PAGE_SLUG ), admin_url( 'admin.php' ) );
		?>
		<div class="wrap openclawp-wrap openclawp-channel-detail">
			<p class="openclawp-channel-detail__back">
				<a href="<?php echo esc_url( $back_url ); ?>">&larr; <?php esc_html_e( 'All channels', 'openclawp' ); ?></a>
			</p>
			<h1><?php echo esc_html( $channel['name'] ); ?></h1>
			<?php if ( '' !== $channel['subtitle'] ) : ?>
				<p class="description"><?php echo esc_html( $channel['subtitle'] ); ?></p>
			<?php endif; ?>
			<?php
			if ( is_callable( $channel['detail_renderer'] ) ) {
				call_user_func( $channel['detail_renderer'] );
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
		$labels = array(
			self::STATUS_CONNECTED      => __( 'Connected', 'openclawp' ),
			self::STATUS_PAIRING        => __( 'Pairing', 'openclawp' ),
			self::STATUS_FAILED         => __( 'Failed', 'openclawp' ),
			self::STATUS_NOT_CONFIGURED => __( 'Not configured', 'openclawp' ),
		);
		return $labels[ $status ] ?? __( 'Unknown', 'openclawp' );
	}
}
