<?php
/**
 * Knowledge-base admin page (wp-admin → openclaWP → Knowledge Base).
 *
 * Single-page admin for selecting indexed post types and managing URL
 * sources. Submissions are POSTed to admin-post.php so we keep nonce
 * protection without standing up REST routes for what is fundamentally
 * a settings form.
 *
 * The page also exposes a "Reindex all" action that enqueues Action
 * Scheduler jobs for every published post in the selected post types.
 *
 * @package OpenclaWP
 * @since   0.7.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Knowledge-base admin page.
 */
final class OpenclaWP_Knowledge_Base_Admin {

	public const PAGE_SLUG = 'openclawp-knowledge-base';

	public const ACTION_SAVE    = 'openclawp_kb_save';
	public const ACTION_REINDEX = 'openclawp_kb_reindex';
	public const ACTION_CRAWL   = 'openclawp_kb_crawl';

	/**
	 * Hook admin_menu and admin_post handlers.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_submenu' ), 18 );
		add_action( 'admin_post_' . self::ACTION_SAVE, array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_' . self::ACTION_REINDEX, array( __CLASS__, 'handle_reindex' ) );
		add_action( 'admin_post_' . self::ACTION_CRAWL, array( __CLASS__, 'handle_crawl' ) );
	}

	/**
	 * Add the submenu page under the openclaWP top-level menu.
	 */
	public static function register_submenu(): void {
		add_submenu_page(
			OpenclaWP_Admin::PAGE_SLUG,
			__( 'Knowledge Base', 'openclawp' ),
			__( 'Knowledge Base', 'openclawp' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the Knowledge Base settings page.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage the knowledge base.', 'openclawp' ) );
		}

		$config      = OpenclaWP_Knowledge_Base_Sources::get();
		$post_types  = self::available_post_types();
		$notice      = isset( $_GET['kb_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['kb_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice flag.
		$reindexed   = isset( $_GET['kb_reindexed'] ) ? (int) $_GET['kb_reindexed'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$stats       = self::collect_stats();
		$save_action = esc_url( admin_url( 'admin-post.php' ) );
		$reindex_url = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_REINDEX ), self::ACTION_REINDEX );
		$crawl_url   = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_CRAWL ), self::ACTION_CRAWL );

		?>
		<div class="wrap openclawp-kb">
			<h1><?php esc_html_e( 'openclaWP — Knowledge Base', 'openclawp' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Pick post types and URLs to index. The "knowledge-base/search" ability lets agents look up snippets with citations.', 'openclawp' ); ?>
			</p>

			<?php if ( 'saved' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Knowledge base settings saved.', 'openclawp' ); ?></p></div>
			<?php elseif ( 'reindexed' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php
					printf(
						/* translators: %d: number of posts queued. */
						esc_html__( 'Queued %d posts for indexing.', 'openclawp' ),
						(int) $reindexed
					);
					?>
				</p></div>
			<?php elseif ( 'crawled' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'URL crawl scheduled.', 'openclawp' ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Index summary', 'openclawp' ); ?></h2>
			<table class="widefat striped" style="max-width:480px">
				<tbody>
					<tr>
						<th><?php esc_html_e( 'Indexed posts', 'openclawp' ); ?></th>
						<td><?php echo (int) $stats['post_sources']; ?> (<?php echo (int) $stats['post_chunks']; ?> chunks)</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Indexed URLs', 'openclawp' ); ?></th>
						<td><?php echo (int) $stats['url_sources']; ?> (<?php echo (int) $stats['url_chunks']; ?> chunks)</td>
					</tr>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_attr( $save_action ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE ); ?>" />
				<?php wp_nonce_field( self::ACTION_SAVE ); ?>

				<h2><?php esc_html_e( 'Site content', 'openclawp' ); ?></h2>
				<p><?php esc_html_e( 'Posts in the selected types are auto-reindexed on publish/update.', 'openclawp' ); ?></p>

				<fieldset>
					<?php foreach ( $post_types as $pt ) : ?>
						<?php $checked = in_array( $pt['slug'], $config['post_types'], true ); ?>
						<label style="display:block;margin-bottom:4px">
							<input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $pt['slug'] ); ?>" <?php checked( $checked ); ?> />
							<?php echo esc_html( $pt['label'] ); ?>
							<code><?php echo esc_html( $pt['slug'] ); ?></code>
						</label>
					<?php endforeach; ?>
				</fieldset>

				<h2><?php esc_html_e( 'URLs', 'openclawp' ); ?></h2>
				<p><?php esc_html_e( 'External URLs to crawl. Re-crawl respects robots.txt and a 1-minute per-URL rate limit.', 'openclawp' ); ?></p>

				<table class="widefat striped" id="openclawp-kb-urls">
					<thead>
						<tr>
							<th><?php esc_html_e( 'URL', 'openclawp' ); ?></th>
							<th><?php esc_html_e( 'Re-crawl', 'openclawp' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						$rows = $config['urls'];
						// Pad to at least 3 empty rows so users have something to type into.
						while ( count( $rows ) < 3 ) {
							$rows[] = array(
								'url'      => '',
								'interval' => OpenclaWP_Knowledge_Base_Sources::INTERVAL_WEEKLY,
							);
						}
						foreach ( $rows as $i => $row ) :
							?>
							<tr>
								<td><input type="url" name="urls[<?php echo (int) $i; ?>][url]" value="<?php echo esc_attr( $row['url'] ); ?>" class="regular-text" placeholder="https://example.com/docs/" /></td>
								<td>
									<select name="urls[<?php echo (int) $i; ?>][interval]">
										<option value="<?php echo esc_attr( OpenclaWP_Knowledge_Base_Sources::INTERVAL_DAILY ); ?>" <?php selected( $row['interval'], OpenclaWP_Knowledge_Base_Sources::INTERVAL_DAILY ); ?>><?php esc_html_e( 'Daily', 'openclawp' ); ?></option>
										<option value="<?php echo esc_attr( OpenclaWP_Knowledge_Base_Sources::INTERVAL_WEEKLY ); ?>" <?php selected( $row['interval'], OpenclaWP_Knowledge_Base_Sources::INTERVAL_WEEKLY ); ?>><?php esc_html_e( 'Weekly', 'openclawp' ); ?></option>
									</select>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php submit_button( __( 'Save knowledge base settings', 'openclawp' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Manual actions', 'openclawp' ); ?></h2>
			<p>
				<a class="button button-secondary" href="<?php echo esc_url( $reindex_url ); ?>"><?php esc_html_e( 'Reindex all selected posts', 'openclawp' ); ?></a>
				<a class="button button-secondary" href="<?php echo esc_url( $crawl_url ); ?>"><?php esc_html_e( 'Crawl URLs now', 'openclawp' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Persist submitted source configuration.
	 */
	public static function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage the knowledge base.', 'openclawp' ) );
		}
		check_admin_referer( self::ACTION_SAVE );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above via check_admin_referer.
		$post_types = isset( $_POST['post_types'] ) && is_array( $_POST['post_types'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['post_types'] ) ) : array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$urls_raw = isset( $_POST['urls'] ) && is_array( $_POST['urls'] ) ? wp_unslash( $_POST['urls'] ) : array();

		$urls = array();
		foreach ( $urls_raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$url      = isset( $entry['url'] ) ? esc_url_raw( (string) $entry['url'] ) : '';
			$interval = isset( $entry['interval'] ) ? (string) $entry['interval'] : OpenclaWP_Knowledge_Base_Sources::INTERVAL_WEEKLY;
			if ( '' === $url ) {
				continue;
			}
			$urls[] = array(
				'url'      => $url,
				'interval' => $interval,
			);
		}

		OpenclaWP_Knowledge_Base_Sources::save(
			array(
				'post_types' => $post_types,
				'urls'       => $urls,
			)
		);

		wp_safe_redirect( add_query_arg( 'kb_notice', 'saved', self::page_url() ) );
		exit;
	}

	/**
	 * Queue indexing jobs for every configured post.
	 */
	public static function handle_reindex(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage the knowledge base.', 'openclawp' ) );
		}
		check_admin_referer( self::ACTION_REINDEX );

		$queued = OpenclaWP_Knowledge_Base_Indexer::reindex_all_posts();

		wp_safe_redirect(
			add_query_arg(
				array(
					'kb_notice'    => 'reindexed',
					'kb_reindexed' => (int) $queued,
				),
				self::page_url()
			)
		);
		exit;
	}

	/**
	 * Queue an immediate URL crawl pass.
	 */
	public static function handle_crawl(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage the knowledge base.', 'openclawp' ) );
		}
		check_admin_referer( self::ACTION_CRAWL );

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( OpenclaWP_Knowledge_Base_Indexer::HOOK_CRAWL_URLS, array(), OpenclaWP_Knowledge_Base_Indexer::GROUP );
		} else {
			OpenclaWP_Knowledge_Base_Indexer::crawl_due_urls();
		}

		wp_safe_redirect( add_query_arg( 'kb_notice', 'crawled', self::page_url() ) );
		exit;
	}

	/**
	 * URL for this admin page, used for redirects after admin_post handlers.
	 */
	private static function page_url(): string {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * Public-facing post types eligible for indexing.
	 *
	 * @return array<int,array{slug:string,label:string}>
	 */
	private static function available_post_types(): array {
		$types = get_post_types( array( 'show_ui' => true ), 'objects' );
		$out   = array();
		foreach ( $types as $obj ) {
			// Skip our internal CPTs and core noise.
			if ( in_array( $obj->name, array( 'attachment', 'openclawp_session', 'openclawp_workflow' ), true ) ) {
				continue;
			}
			$out[] = array(
				'slug'  => (string) $obj->name,
				'label' => (string) ( $obj->labels->name ?? $obj->name ),
			);
		}
		usort( $out, static fn ( $a, $b ) => strcmp( $a['label'], $b['label'] ) );
		return $out;
	}

	/**
	 * Aggregate row counts grouped by source type for the summary panel.
	 *
	 * @return array{post_sources:int,post_chunks:int,url_sources:int,url_chunks:int}
	 */
	private static function collect_stats(): array {
		global $wpdb;
		$table = OpenclaWP_Knowledge_Base_Schema::table_name();

		$sql = "SELECT source_type, COUNT(*) AS chunks, COUNT(DISTINCT source_id) AS sources FROM {$table} GROUP BY source_type"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql is a constant template; no untrusted input.
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		$out = array(
			'post_sources' => 0,
			'post_chunks'  => 0,
			'url_sources'  => 0,
			'url_chunks'   => 0,
		);
		if ( ! is_array( $rows ) ) {
			return $out;
		}
		foreach ( $rows as $row ) {
			$type    = (string) $row['source_type'];
			$chunks  = (int) $row['chunks'];
			$sources = (int) $row['sources'];
			if ( OpenclaWP_Knowledge_Base_Schema::SOURCE_POST === $type ) {
				$out['post_sources'] = $sources;
				$out['post_chunks']  = $chunks;
			} elseif ( OpenclaWP_Knowledge_Base_Schema::SOURCE_URL === $type ) {
				$out['url_sources'] = $sources;
				$out['url_chunks']  = $chunks;
			}
		}
		return $out;
	}
}
