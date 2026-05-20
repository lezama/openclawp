<?php
/**
 * wp-admin surface for agency automation.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Agency_Admin {

	public const PAGE_SLUG = 'openclawp-agency';

	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_submenu' ), 18 );
	}

	public static function register_submenu(): void {
		add_submenu_page(
			OpenclaWP_Admin::PAGE_SLUG,
			__( 'Agency', 'openclawp' ),
			__( 'Agency', 'openclawp' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page(): void {
		$audit      = OpenclaWP_Automation_Audit::audit_current_site();
		$blueprints = OpenclaWP_Agency_Blueprints::list();
		$workspaces = OpenclaWP_Agency_Workspace_Store::all( 10 );
		$demos      = OpenclaWP_Agency_Demo_Store::recent( 10 );
		?>
		<div class="wrap openclawp-agency">
			<h1><?php esc_html_e( 'openclaWP - Agency automation', 'openclawp' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Generate client-specific automation agents, workflows, connector plans, and sales demos from reusable blueprints.', 'openclawp' ); ?>
			</p>

			<h2><?php esc_html_e( 'Top opportunities on this site', 'openclawp' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Blueprint', 'openclawp' ); ?></th>
						<th><?php esc_html_e( 'Score', 'openclawp' ); ?></th>
						<th><?php esc_html_e( 'Evidence', 'openclawp' ); ?></th>
						<th><?php esc_html_e( 'Connectors', 'openclawp' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( array_slice( (array) $audit['opportunities'], 0, 6 ) as $opportunity ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $opportunity['label'] ); ?></td>
							<td><?php echo esc_html( (string) $opportunity['score'] ); ?></td>
							<td><?php echo esc_html( implode( ' ', (array) $opportunity['evidence'] ) ); ?></td>
							<td><?php echo esc_html( implode( ', ', (array) $opportunity['recommended_connectors'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Blueprints', 'openclawp' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Use case', 'openclawp' ); ?></th>
						<th><?php esc_html_e( 'Category', 'openclawp' ); ?></th>
						<th><?php esc_html_e( 'Connectors', 'openclawp' ); ?></th>
						<th><?php esc_html_e( 'Demo prompts', 'openclawp' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $blueprints as $blueprint ) : ?>
						<tr>
							<td>
								<strong><?php echo esc_html( (string) $blueprint['label'] ); ?></strong><br />
								<span class="description"><?php echo esc_html( (string) $blueprint['description'] ); ?></span>
							</td>
							<td><?php echo esc_html( (string) $blueprint['category'] ); ?></td>
							<td><?php echo esc_html( implode( ', ', (array) $blueprint['recommended_connectors'] ) ); ?></td>
							<td><?php echo esc_html( implode( ' | ', array_slice( (array) $blueprint['demo_prompts'], 0, 2 ) ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Recent client workspaces', 'openclawp' ); ?></h2>
			<?php self::render_simple_list( $workspaces, 'name', 'site_url', __( 'No client workspaces yet.', 'openclawp' ) ); ?>

			<h2><?php esc_html_e( 'Recent generated demos', 'openclawp' ); ?></h2>
			<?php self::render_simple_list( $demos, 'title', 'package_id', __( 'No demo packages yet.', 'openclawp' ) ); ?>

			<h2><?php esc_html_e( 'API quick paths', 'openclawp' ); ?></h2>
			<ul>
				<li><code>GET /wp-json/openclawp/v1/agency/blueprints</code></li>
				<li><code>GET /wp-json/openclawp/v1/agency/audit</code></li>
				<li><code>POST /wp-json/openclawp/v1/agency/workspaces</code></li>
				<li><code>POST /wp-json/openclawp/v1/agency/generate</code></li>
			</ul>
		</div>
		<?php
	}

	/**
	 * @param array<int,array<string,mixed>> $items
	 */
	private static function render_simple_list( array $items, string $title_key, string $meta_key, string $empty ): void {
		if ( empty( $items ) ) {
			echo '<p class="description">' . esc_html( $empty ) . '</p>';
			return;
		}
		echo '<ul>';
		foreach ( $items as $item ) {
			echo '<li><strong>' . esc_html( (string) ( $item[ $title_key ] ?? '' ) ) . '</strong> ';
			echo '<span class="description">' . esc_html( (string) ( $item[ $meta_key ] ?? '' ) ) . '</span></li>';
		}
		echo '</ul>';
	}
}
