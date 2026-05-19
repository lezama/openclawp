<?php
/**
 * wp-admin → openclaWP → Routines page.
 *
 * A *routine* is a persistent, scheduled invocation of an agent that
 * reuses the same conversation session across every wake. Where the
 * earlier `Tasks` page surfaced raw Action Scheduler rows, this page
 * groups them per routine: one row per registered routine, with its
 * agent, schedule, next wake, and most recent completed wake.
 *
 * Built on agents-api's `wp_register_routine()` primitive
 * ({@see \AgentsAPI\AI\Routines\WP_Agent_Routine}).
 *
 * @package OpenclaWP
 * @since   0.6.0
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Routines_Admin {

	public const PAGE_SLUG = 'openclawp-routines';

	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_submenu' ), 17 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function register_submenu(): void {
		// Hide-when-empty: until something has called `wp_register_routine()`
		// the surface has nothing to render, so skip the sidebar entry. The
		// `wp_get_routines()` lookup lives on the menu-visibility helper.
		$parent = OpenclaWP_Admin_Menu_Visibility::parent_for_slug( self::PAGE_SLUG );
		add_submenu_page(
			$parent,
			__( 'Routines', 'openclawp' ),
			__( 'Routines', 'openclawp' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function enqueue_assets( $hook ): void {
		if ( self::hook_suffix() !== $hook ) {
			return;
		}

		$build_url  = plugins_url( 'blocks/routines/build/', OPENCLAWP_PLUGIN_FILE );
		$build_path = OPENCLAWP_PATH . 'blocks/routines/build/';
		$asset_file = $build_path . 'view.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				wp_die( esc_html__( 'openclaWP Routines: missing build artefacts. Run `npm run build` in the plugin directory.', 'openclawp' ) );
			}
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'openclawp-routines-admin',
			$build_url . 'view.js',
			array_unique(
				array_merge(
					array( 'wp-api-fetch' ),
					(array) ( $asset['dependencies'] ?? array() )
				)
			),
			(string) ( $asset['version'] ?? OPENCLAWP_VERSION ),
			true
		);

		// `@wordpress/dataviews` is bundled into our view.js (wp-scripts
		// doesn't externalise it on WP 7.0). The matching CSS is copied
		// out of `node_modules/@wordpress/dataviews/build-style/style.css`
		// at build time (see `npm run build:routines`) and lives next to
		// view.js.
		wp_enqueue_style(
			'openclawp-routines-admin',
			$build_url . 'view.css',
			array( 'wp-components' ),
			(string) ( $asset['version'] ?? OPENCLAWP_VERSION )
		);

		wp_localize_script(
			'openclawp-routines-admin',
			'openclaWPRoutines',
			array(
				'restNamespace' => 'openclawp/v1',
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'routinesUrl'   => esc_url_raw( rest_url( 'openclawp/v1/routines' ) ),
				'pollInterval'  => 5000,
			)
		);
	}

	public static function render_page(): void {
		?>
		<div class="wrap openclawp-routines">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Routines', 'openclawp' ); ?></h1>
			<hr class="wp-header-end" />

			<p class="description">
				<?php
				echo wp_kses(
					__(
						'A <strong>routine</strong> is a recurring agent invocation with a persistent conversation session — the agent wakes on a schedule, picks up where it left off, and goes back to sleep. Register routines from PHP with <code>wp_register_routine()</code>; this page surfaces every registered routine alongside its Action Scheduler timing.',
						'openclawp'
					),
					array( 'strong' => array(), 'code' => array() )
				);
				?>
			</p>

			<div id="openclawp-routines-root">
				<p><?php esc_html_e( 'Loading routines…', 'openclawp' ); ?></p>
			</div>
		</div>
		<?php
	}

	private static function hook_suffix(): string {
		return 'openclawp_page_' . self::PAGE_SLUG;
	}
}
