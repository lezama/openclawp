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
				// phpcs:disable WordPress.Security.NonceVerification.Recommended
				'activeId'      => isset( $_GET['workflow'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['workflow'] ) ) : '',
				'action'        => isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : '',
				// phpcs:enable
				'listUrl'       => admin_url( 'admin.php?page=' . self::PAGE_SLUG ),
				'createUrl'     => admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=new' ),
			)
		);
	}

	public static function render_page(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$active_id = isset( $_GET['workflow'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['workflow'] ) ) : '';
		$action    = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : '';
		// phpcs:enable

		echo '<div class="wrap openclawp-workflows">';

		if ( 'new' === $action ) {
			self::render_create();
		} elseif ( '' !== $active_id ) {
			self::render_detail( $active_id );
		} else {
			self::render_list();
		}

		echo '</div>';
	}

	private static function render_list(): void {
		$create_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=new' );
		?>
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Workflows', 'openclawp' ); ?></h1>
		<a href="<?php echo esc_url( $create_url ); ?>" class="page-title-action">
			<?php esc_html_e( 'Create with AI', 'openclawp' ); ?>
		</a>
		<hr class="wp-header-end" />

		<div class="openclawp-workflows-intro">
			<p>
				<?php
				echo wp_kses(
					__(
						'A <strong>workflow</strong> is a reusable recipe — for example, "every time a comment lands, check if it is spam and email me if it is." A workflow runs on a trigger (a WordPress event, a schedule, or on demand) and chains together steps. Each step either runs a deterministic action (read posts, send mail, hit an API) or asks an AI agent to reason about something.',
						'openclawp'
					),
					array(
						'strong' => array(),
					)
				);
				?>
			</p>
			<p>
				<?php
				echo wp_kses(
					__(
						'Click <strong>Create with AI</strong> to describe a workflow in plain English — an AI agent will translate it into a runnable spec for you.',
						'openclawp'
					),
					array(
						'strong' => array(),
					)
				);
				?>
			</p>
		</div>

		<div id="openclawp-workflow-list" class="openclawp-workflow-list">
			<p><?php esc_html_e( 'Loading workflows…', 'openclawp' ); ?></p>
		</div>
		<?php
	}

	private static function render_create(): void {
		$list_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		?>
		<p class="openclawp-workflow-detail__back">
			<a href="<?php echo esc_url( $list_url ); ?>">
				<?php echo esc_html__( '← All workflows', 'openclawp' ); ?>
			</a>
		</p>
		<h1><?php esc_html_e( 'Create workflow with AI', 'openclawp' ); ?></h1>
		<div id="openclawp-workflow-create" class="openclawp-workflow-create">
			<p><?php esc_html_e( 'Loading…', 'openclawp' ); ?></p>
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
