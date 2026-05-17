<?php
/**
 * wp-admin → openclaWP → Tool activity.
 *
 * Read-only audit log of every confirmation prompt and its resolution
 * (`pending | allowed | denied | always | expired`). Filterable by ability
 * and user.
 *
 * @package OpenclaWP
 * @since   0.8.0
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Decisions_Admin {

	public const PAGE_SLUG = 'openclawp-decisions';

	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_submenu' ), 22 );
	}

	public static function register_submenu(): void {
		add_submenu_page(
			OpenclaWP_Admin::PAGE_SLUG,
			__( 'Tool activity', 'openclawp' ),
			__( 'Tool activity', 'openclawp' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$ability_filter = isset( $_GET['ability'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['ability'] ) ) : '';
		$user_filter    = isset( $_GET['user_id'] ) ? max( 0, (int) $_GET['user_id'] ) : 0;
		$status_filter  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : '';
		// phpcs:enable

		$rows = OpenclaWP_Decisions_Store::recent(
			array(
				'ability' => $ability_filter,
				'user_id' => $user_filter,
				'status'  => $status_filter,
				'limit'   => 200,
			)
		);

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'openclaWP — Tool activity', 'openclawp' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Every time the agent asked for permission to run a tool. Use this to spot misuse, audit destructive operations, and decide what to pre-authorise for trusted users.', 'openclawp' ) . '</p>';

		self::render_filters( $ability_filter, $user_filter, $status_filter );

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No tool decisions recorded yet.', 'openclawp' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'When (UTC)', 'openclawp' ) . '</th>';
		echo '<th>' . esc_html__( 'User', 'openclawp' ) . '</th>';
		echo '<th>' . esc_html__( 'Agent', 'openclawp' ) . '</th>';
		echo '<th>' . esc_html__( 'Ability', 'openclawp' ) . '</th>';
		echo '<th>' . esc_html__( 'Effect', 'openclawp' ) . '</th>';
		echo '<th>' . esc_html__( 'Threshold', 'openclawp' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'openclawp' ) . '</th>';
		echo '<th>' . esc_html__( 'Resolved', 'openclawp' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$user      = $row['user_id'] > 0 ? get_userdata( (int) $row['user_id'] ) : null;
			$user_name = $user ? $user->user_login : '#' . (int) $row['user_id'];

			echo '<tr>';
			echo '<td>' . esc_html( $row['created_at'] ) . '</td>';
			echo '<td>' . esc_html( $user_name ) . '</td>';
			echo '<td><code>' . esc_html( $row['agent_slug'] ) . '</code></td>';
			echo '<td><code>' . esc_html( $row['ability'] ) . '</code></td>';
			echo '<td><code>' . esc_html( $row['effect'] ) . '</code></td>';
			echo '<td><code>' . esc_html( $row['threshold'] ) . '</code></td>';
			echo '<td>' . esc_html( self::status_label( $row['status'] ) ) . '</td>';
			echo '<td>' . esc_html( $row['resolved_at'] ?: '—' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	private static function render_filters( string $ability, int $user_id, string $status ): void {
		?>
		<form method="get" style="margin: 16px 0;">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
			<label>
				<?php esc_html_e( 'Ability:', 'openclawp' ); ?>
				<input type="text" name="ability" value="<?php echo esc_attr( $ability ); ?>" placeholder="<?php esc_attr_e( '(any ability)', 'openclawp' ); ?>" />
			</label>
			<label style="margin-left: 12px;">
				<?php esc_html_e( 'User ID:', 'openclawp' ); ?>
				<input type="number" name="user_id" value="<?php echo esc_attr( $user_id > 0 ? (string) $user_id : '' ); ?>" placeholder="<?php esc_attr_e( '(any user)', 'openclawp' ); ?>" min="0" style="width: 100px;" />
			</label>
			<label style="margin-left: 12px;">
				<?php esc_html_e( 'Status:', 'openclawp' ); ?>
				<select name="status">
					<option value=""><?php esc_html_e( '(any)', 'openclawp' ); ?></option>
					<?php foreach ( array( 'pending', 'allowed', 'denied', 'always', 'expired' ) as $s ) : ?>
						<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $status, $s ); ?>><?php echo esc_html( self::status_label( $s ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<button type="submit" class="button"><?php esc_html_e( 'Apply', 'openclawp' ); ?></button>
		</form>
		<?php
	}

	private static function status_label( string $status ): string {
		switch ( $status ) {
			case OpenclaWP_Decisions_Store::STATUS_PENDING:
				return __( 'pending', 'openclawp' );
			case OpenclaWP_Decisions_Store::STATUS_ALLOWED:
				return __( 'allowed', 'openclawp' );
			case OpenclaWP_Decisions_Store::STATUS_DENIED:
				return __( 'denied', 'openclawp' );
			case OpenclaWP_Decisions_Store::STATUS_ALWAYS:
				return __( 'always allow', 'openclawp' );
			case OpenclaWP_Decisions_Store::STATUS_EXPIRED:
				return __( 'expired', 'openclawp' );
			default:
				return $status;
		}
	}
}
