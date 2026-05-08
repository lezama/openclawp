<?php
/**
 * wp-admin → openclaWP → Workflows page.
 *
 * v0: a list of registered + stored workflows; clicking one shows the
 * spec preview, a run-now form built from `inputs` schema, and a
 * recent-runs list with per-run step trace. CRUD (create / edit) lands
 * in a follow-up — for now specs come from PHP registration and the
 * REST endpoint accepts uploads through `wp/v2/openclawp-workflows`.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Workflow_Admin {

	public const PAGE_SLUG = 'openclawp-workflows';

	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_submenu' ), 16 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function register_submenu(): void {
		add_submenu_page(
			OpenclaWP_Admin::PAGE_SLUG,
			__( 'Workflows', 'openclawp' ),
			__( 'Workflows', 'openclawp' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function enqueue_assets( $hook ): void {
		if ( self::hook_suffix() !== $hook ) {
			return;
		}

		$asset_url = plugins_url( 'assets/', OPENCLAWP_PLUGIN_FILE );

		wp_enqueue_style(
			'openclawp-workflow-admin',
			$asset_url . 'workflow-admin.css',
			array(),
			OPENCLAWP_VERSION
		);

		wp_enqueue_script(
			'openclawp-workflow-admin',
			$asset_url . 'workflow-admin.js',
			array( 'wp-api-fetch' ),
			OPENCLAWP_VERSION,
			true
		);

		wp_localize_script(
			'openclawp-workflow-admin',
			'openclaWPWorkflows',
			array(
				'restNamespace' => 'openclawp/v1',
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'pollInterval'  => 3000,
				'activeId'      => isset( $_GET['workflow'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['workflow'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
				'listUrl'       => admin_url( 'admin.php?page=' . self::PAGE_SLUG ),
			)
		);
	}

	public static function render_page(): void {
		$active_id = isset( $_GET['workflow'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['workflow'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		echo '<div class="wrap openclawp-workflows">';

		if ( '' !== $active_id ) {
			self::render_detail( $active_id );
		} else {
			self::render_list();
		}

		echo '</div>';
	}

	private static function render_list(): void {
		?>
		<h1><?php esc_html_e( 'Workflows', 'openclawp' ); ?></h1>
		<p class="description">
			<?php
			echo wp_kses(
				__(
					'Deterministic recipes that compose agents and abilities. Workflows are registered by plugins via <code>wp_register_workflow()</code> or stored as <code>openclawp_workflow</code> posts. Click a workflow to inspect its spec, kick off a run, or browse run history.',
					'openclawp'
				),
				array( 'code' => array() )
			);
			?>
		</p>
		<div id="openclawp-workflow-list" class="openclawp-workflow-list">
			<p><?php esc_html_e( 'Loading workflows…', 'openclawp' ); ?></p>
		</div>
		<?php
	}

	private static function render_detail( string $workflow_id ): void {
		$list_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		?>
		<p class="openclawp-workflow-detail__back">
			<a href="<?php echo esc_url( $list_url ); ?>">
				<?php echo esc_html__( '← All workflows', 'openclawp' ); ?>
			</a>
		</p>
		<h1><?php echo esc_html( $workflow_id ); ?></h1>
		<div id="openclawp-workflow-detail" class="openclawp-workflow-detail" data-workflow-id="<?php echo esc_attr( $workflow_id ); ?>">
			<p><?php esc_html_e( 'Loading…', 'openclawp' ); ?></p>
		</div>
		<?php
	}

	private static function hook_suffix(): string {
		return 'openclawp_page_' . self::PAGE_SLUG;
	}
}
