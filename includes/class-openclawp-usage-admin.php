<?php
/**
 * wp-admin → openclaWP → Usage page.
 *
 * Three stacked sections: recent turns, daily totals, per-model breakdown.
 * No JS charts — plain HTML tables. Optional `?days=N`, `?agent=slug`
 * filters on the URL.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Usage_Admin {

	public const PAGE_SLUG = 'openclawp-usage';

	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_submenu' ), 18 );
	}

	public static function register_submenu(): void {
		add_submenu_page(
			OpenclaWP_Admin::PAGE_SLUG,
			__( 'Usage', 'openclawp' ),
			__( 'Usage', 'openclawp' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$days       = isset( $_GET['days'] ) ? max( 1, (int) $_GET['days'] ) : 30;
		$agent_slug = isset( $_GET['agent'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['agent'] ) ) : '';
		// phpcs:enable

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'openclaWP — Usage', 'openclawp' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Per-turn token + cost telemetry from every registered chat invocation. Cost estimates use a filterable pricing table; rates without a match are recorded as $0.00 with an unpriced flag.', 'openclawp' ) . '</p>';

		self::render_filters( $days, $agent_slug );
		self::render_totals( $days, $agent_slug );
		self::render_by_model( $days );
		self::render_by_day( min( $days, 30 ) );
		self::render_recent( $days, $agent_slug );

		echo '</div>';
	}

	private static function render_filters( int $days, string $agent_slug ): void {
		?>
		<form method="get" style="margin: 16px 0;">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
			<label>
				<?php esc_html_e( 'Window:', 'openclawp' ); ?>
				<select name="days">
					<?php foreach ( array( 1, 7, 14, 30, 90, 365 ) as $d ) : ?>
						<option value="<?php echo esc_attr( (string) $d ); ?>" <?php selected( $days, $d ); ?>>
							<?php
							printf(
								/* translators: %d: number of days */
								esc_html( _n( 'last %d day', 'last %d days', $d, 'openclawp' ) ),
								(int) $d
							);
							?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>
			<label style="margin-left: 12px;">
				<?php esc_html_e( 'Agent:', 'openclawp' ); ?>
				<input type="text" name="agent" value="<?php echo esc_attr( $agent_slug ); ?>" placeholder="<?php esc_attr_e( '(any agent)', 'openclawp' ); ?>" />
			</label>
			<button type="submit" class="button"><?php esc_html_e( 'Apply', 'openclawp' ); ?></button>
		</form>
		<?php
	}

	private static function render_totals( int $days, string $agent_slug ): void {
		$filter = array( 'days' => $days );
		if ( '' !== $agent_slug ) {
			$filter['agent_slug'] = $agent_slug;
		}
		$totals = OpenclaWP_Usage_Store::get_totals( $filter );

		echo '<h2>' . esc_html__( 'Totals', 'openclawp' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width: 720px;"><tbody>';
		self::row( __( 'Turns', 'openclawp' ), number_format_i18n( $totals['turns'] ) );
		self::row( __( 'Input tokens', 'openclawp' ), number_format_i18n( $totals['input_tokens'] ) );
		self::row( __( 'Output tokens', 'openclawp' ), number_format_i18n( $totals['output_tokens'] ) );
		self::row( __( 'Total tokens', 'openclawp' ), number_format_i18n( $totals['total_tokens'] ) );
		self::row( __( 'Estimated cost', 'openclawp' ), '$' . number_format( $totals['est_cost_usd'], 4 ) );
		if ( $totals['unpriced_turns'] > 0 ) {
			self::row(
				__( 'Unpriced turns', 'openclawp' ),
				sprintf(
					/* translators: %1$d turn count, %2$s filter name */
					esc_html__( '%1$d (no rate in the pricing table — extend via %2$s filter)', 'openclawp' ),
					(int) $totals['unpriced_turns'],
					'openclawp_model_pricing'
				)
			);
		}
		echo '</tbody></table>';
	}

	private static function render_by_model( int $days ): void {
		$rows = OpenclaWP_Usage_Store::get_by_model( $days );
		if ( empty( $rows ) ) {
			return;
		}
		$total_cost = 0.0;
		foreach ( $rows as $r ) {
			$total_cost += (float) $r['est_cost_usd'];
		}

		echo '<h2>' . esc_html__( 'By provider · model', 'openclawp' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'provider · model', 'openclawp' ) . '</th>';
		echo '<th>' . esc_html__( 'Turns', 'openclawp' ) . '</th>';
		echo '<th>' . esc_html__( 'Total tokens', 'openclawp' ) . '</th>';
		echo '<th>' . esc_html__( 'Est. cost', 'openclawp' ) . '</th>';
		echo '<th>' . esc_html__( 'Share', 'openclawp' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $bucket => $r ) {
			$share = $total_cost > 0 ? ( $r['est_cost_usd'] / $total_cost ) * 100.0 : 0.0;
			echo '<tr>';
			echo '<td><code>' . esc_html( $bucket ) . '</code></td>';
			echo '<td>' . esc_html( number_format_i18n( $r['turns'] ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( $r['total_tokens'] ) ) . '</td>';
			echo '<td>$' . esc_html( number_format( $r['est_cost_usd'], 4 ) ) . '</td>';
			echo '<td>' . esc_html( number_format( $share, 1 ) ) . '%</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_by_day( int $days ): void {
		$rows = OpenclaWP_Usage_Store::get_by_day( $days );
		if ( empty( $rows ) ) {
			return;
		}
		echo '<h2>' . esc_html__( 'By day', 'openclawp' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Day (UTC)', 'openclawp' ) . '</th>';
		echo '<th>' . esc_html__( 'Turns', 'openclawp' ) . '</th>';
		echo '<th>' . esc_html__( 'Total tokens', 'openclawp' ) . '</th>';
		echo '<th>' . esc_html__( 'Est. cost', 'openclawp' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $day => $r ) {
			echo '<tr>';
			echo '<td><code>' . esc_html( $day ) . '</code></td>';
			echo '<td>' . esc_html( number_format_i18n( $r['turns'] ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( $r['total_tokens'] ) ) . '</td>';
			echo '<td>$' . esc_html( number_format( $r['est_cost_usd'], 4 ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_recent( int $days, string $agent_slug ): void {
		$filters = array( 'days' => $days );
		if ( '' !== $agent_slug ) {
			$filters['agent_slug'] = $agent_slug;
		}
		$rows = OpenclaWP_Usage_Store::get_recent( 50, $filters );

		echo '<h2>' . esc_html__( 'Recent turns', 'openclawp' ) . '</h2>';
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No chat turns recorded yet in this window.', 'openclawp' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'When (UTC)', 'openclawp' ) . '</th>';
		echo '<th>' . esc_html__( 'Agent', 'openclawp' ) . '</th>';
		echo '<th>' . esc_html__( 'Provider · model', 'openclawp' ) . '</th>';
		echo '<th>' . esc_html__( 'In', 'openclawp' ) . '</th>';
		echo '<th>' . esc_html__( 'Out', 'openclawp' ) . '</th>';
		echo '<th>' . esc_html__( 'Tools', 'openclawp' ) . '</th>';
		echo '<th>' . esc_html__( 'Latency', 'openclawp' ) . '</th>';
		echo '<th>' . esc_html__( 'Est. cost', 'openclawp' ) . '</th>';
		echo '<th>' . esc_html__( 'Result', 'openclawp' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$bucket = ( $row['provider'] !== '' && $row['model'] !== '' )
				? $row['provider'] . ' · ' . $row['model']
				: ( $row['provider'] ?: $row['model'] ?: '(unknown)' );
			echo '<tr>';
			echo '<td>' . esc_html( $row['date_gmt'] ) . '</td>';
			echo '<td><code>' . esc_html( $row['agent_slug'] ) . '</code></td>';
			echo '<td><code>' . esc_html( $bucket ) . '</code></td>';
			echo '<td>' . esc_html( number_format_i18n( $row['input_tokens'] ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( $row['output_tokens'] ) ) . '</td>';
			echo '<td>' . esc_html( (string) $row['tool_call_count'] ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( $row['wall_duration_ms'] ) ) . ' ms</td>';
			echo '<td>$' . esc_html( number_format( $row['est_cost_usd'], 4 ) );
			if ( ! $row['pricing_resolved'] ) {
				echo ' <span title="' . esc_attr__( 'No rate matched — extend the pricing table via openclawp_model_pricing.', 'openclawp' ) . '" style="color:#aaa;">∗</span>';
			}
			echo '</td>';
			if ( $row['success'] ) {
				echo '<td><span style="color: #2271b1;">' . esc_html__( 'ok', 'openclawp' ) . '</span></td>';
			} else {
				echo '<td><span style="color: #b32d2e;" title="' . esc_attr( $row['error'] ) . '">' . esc_html__( 'error', 'openclawp' ) . '</span></td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private static function row( string $label, string $value ): void {
		echo '<tr><th scope="row" style="width: 240px;">' . esc_html( $label ) . '</th><td>' . wp_kses_post( $value ) . '</td></tr>';
	}
}
