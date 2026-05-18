<?php
/**
 * Knowledge-base indexer.
 *
 * Two ingestion paths feed the `wp_openclawp_kb` table:
 *
 *   1. Posts. On `save_post` we enqueue an Action Scheduler job for the
 *      post id. The job re-reads the (current) post and replaces its
 *      chunk rows. We *do not* index on the synchronous save path —
 *      bulk-edit / migration scripts would thrash the DB otherwise.
 *
 *   2. URLs. A recurring AS job walks the configured URL list and
 *      crawls anything past its re-crawl interval. Crawls respect
 *      `robots.txt` and a per-host rate limit (default 1 req/sec).
 *
 * Both paths funnel into `index_post()` / `index_url()`, which compute
 * chunks via {@see OpenclaWP_Knowledge_Base_Chunker} and replace the
 * existing source rows in a single transaction.
 *
 * @package OpenclaWP
 * @since   0.7.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Knowledge-base indexer.
 */
final class OpenclaWP_Knowledge_Base_Indexer {

	public const HOOK_INDEX_POST    = 'openclawp_kb_index_post';
	public const HOOK_INDEX_URL     = 'openclawp_kb_index_url';
	public const HOOK_CRAWL_URLS    = 'openclawp_kb_crawl_urls';
	public const GROUP              = 'openclawp-kb';
	public const URL_CRAWL_LOCK_MIN = 60; // Minimum seconds between fetches of the same URL.

	/**
	 * Wire up save_post / delete_post hooks and Action Scheduler callbacks.
	 */
	public static function register(): void {
		add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 20, 3 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete_post' ), 10, 1 );

		// Action Scheduler callbacks must be void. index_post / index_url
		// return chunk counts for the admin "Reindex" path; wrap them.
		add_action(
			self::HOOK_INDEX_POST,
			static function ( $post_id ): void {
				self::index_post( (int) $post_id );
			},
			10,
			1
		);
		add_action(
			self::HOOK_INDEX_URL,
			static function ( $url ): void {
				self::index_url( (string) $url );
			},
			10,
			1
		);
		add_action( self::HOOK_CRAWL_URLS, array( __CLASS__, 'crawl_due_urls' ), 10, 0 );

		add_action( 'init', array( __CLASS__, 'maybe_schedule_recurring_crawl' ), 20 );
	}

	/* ---------------------------- Hot path -------------------------------- */

	/**
	 * Handle save_post — enqueue an indexing job for indexable post types.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post being saved.
	 * @param bool     $update  Whether this is an update.
	 */
	public static function on_save_post( $post_id, $post, $update ): void {
		unset( $update );
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! ( $post instanceof WP_Post ) ) {
			return;
		}
		if ( 'publish' !== $post->post_status ) {
			// Non-published posts are scrubbed from the index in case the user
			// just unpublished them.
			self::delete_post_chunks( (int) $post_id );
			return;
		}
		if ( ! OpenclaWP_Knowledge_Base_Sources::indexes_post_type( (string) $post->post_type ) ) {
			return;
		}

		self::enqueue_post_index( (int) $post_id );
	}

	/**
	 * Drop any index rows for a deleted post.
	 *
	 * @param int $post_id Post ID being deleted.
	 */
	public static function on_delete_post( $post_id ): void {
		self::delete_post_chunks( (int) $post_id );
	}

	/* ---------------------------- Scheduling ------------------------------ */

	/**
	 * Schedule an async indexing job for the given post id. No-op if a job
	 * is already pending for the same id (debounce on bulk save_post fires).
	 *
	 * @param int $post_id Post ID.
	 */
	public static function enqueue_post_index( int $post_id ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			// Fallback: index inline. Only happens if Action Scheduler failed
			// to load; the doctor command surfaces that.
			self::index_post( $post_id );
			return;
		}

		// Debounce: don't queue a second job if one is already pending for
		// this post. AS's as_has_scheduled_action() handles the lookup by
		// hook + args.
		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::HOOK_INDEX_POST, array( $post_id ), self::GROUP ) ) {
			return;
		}

		as_enqueue_async_action( self::HOOK_INDEX_POST, array( $post_id ), self::GROUP );
	}

	/**
	 * Ensure a recurring URL-crawl job is scheduled. Safe to call on every
	 * `init` — Action Scheduler dedupes by hook + args.
	 */
	public static function maybe_schedule_recurring_crawl(): void {
		if ( ! function_exists( 'as_schedule_recurring_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}
		if ( as_has_scheduled_action( self::HOOK_CRAWL_URLS, array(), self::GROUP ) ) {
			return;
		}
		// Walk the URL list every hour; per-URL interval is enforced inside
		// crawl_due_urls so daily / weekly entries stay rate-correct.
		as_schedule_recurring_action( time() + HOUR_IN_SECONDS, HOUR_IN_SECONDS, self::HOOK_CRAWL_URLS, array(), self::GROUP );
	}

	/* ---------------------------- Indexing -------------------------------- */

	/**
	 * Index a single post. Replaces all existing chunks for that post id.
	 *
	 * @param int $post_id Post ID to (re)index.
	 * @return int Number of chunks written.
	 */
	public static function index_post( int $post_id ): int {
		$post = get_post( $post_id );
		if ( ! ( $post instanceof WP_Post ) || 'publish' !== $post->post_status ) {
			self::delete_post_chunks( $post_id );
			return 0;
		}

		$normalised = OpenclaWP_Knowledge_Base_Chunker::normalise( (string) $post->post_content );
		if ( '' === $normalised ) {
			self::delete_post_chunks( $post_id );
			return 0;
		}

		$chunks = OpenclaWP_Knowledge_Base_Chunker::chunk( $normalised );
		if ( empty( $chunks ) ) {
			self::delete_post_chunks( $post_id );
			return 0;
		}

		$title     = (string) get_the_title( $post );
		$permalink = (string) get_permalink( $post );

		self::replace_chunks(
			OpenclaWP_Knowledge_Base_Schema::SOURCE_POST,
			(string) $post_id,
			$permalink,
			$title,
			$chunks
		);

		return count( $chunks );
	}

	/**
	 * Crawl + index a single URL. Returns chunk count, or 0 on skip/failure.
	 *
	 * @param string $url Absolute URL to fetch.
	 */
	public static function index_url( string $url ): int {
		$url = esc_url_raw( $url );
		if ( '' === $url ) {
			return 0;
		}

		if ( ! self::robots_allows( $url ) ) {
			return 0;
		}

		if ( ! self::rate_limit_allows( $url ) ) {
			return 0;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 15,
				'redirection' => 3,
				'user-agent'  => 'openclaWP-knowledge-base/' . OPENCLAWP_VERSION . ' (+' . home_url() . ')',
			)
		);

		if ( is_wp_error( $response ) ) {
			return 0;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return 0;
		}

		$body = (string) wp_remote_retrieve_body( $response );
		if ( '' === $body ) {
			return 0;
		}

		$title      = self::extract_title( $body );
		$normalised = OpenclaWP_Knowledge_Base_Chunker::normalise( $body );
		if ( '' === $normalised ) {
			return 0;
		}

		$chunks = OpenclaWP_Knowledge_Base_Chunker::chunk( $normalised );
		if ( empty( $chunks ) ) {
			return 0;
		}

		self::replace_chunks(
			OpenclaWP_Knowledge_Base_Schema::SOURCE_URL,
			self::url_id( $url ),
			$url,
			'' === $title ? $url : $title,
			$chunks
		);

		return count( $chunks );
	}

	/**
	 * Crawl every configured URL that's past its re-crawl interval.
	 */
	public static function crawl_due_urls(): void {
		$config = OpenclaWP_Knowledge_Base_Sources::get();
		if ( empty( $config['urls'] ) ) {
			return;
		}

		foreach ( $config['urls'] as $entry ) {
			$url      = (string) ( $entry['url'] ?? '' );
			$interval = (string) ( $entry['interval'] ?? OpenclaWP_Knowledge_Base_Sources::INTERVAL_WEEKLY );
			if ( '' === $url ) {
				continue;
			}
			if ( ! self::url_is_due( $url, $interval ) ) {
				continue;
			}
			self::index_url( $url );
		}
	}

	/**
	 * Reindex all configured post-type posts. Used by the "Reindex all" admin
	 * button. Schedules per-post jobs so large sites don't time out.
	 *
	 * @return int Number of posts queued.
	 */
	public static function reindex_all_posts(): int {
		$config = OpenclaWP_Knowledge_Base_Sources::get();
		if ( empty( $config['post_types'] ) ) {
			return 0;
		}

		$queued = 0;
		// 1k-post budget keeps the option-driven queue bounded. Sites larger
		// than that should bulk-publish to trigger save_post-driven indexing.
		$query = new WP_Query(
			array(
				'post_type'              => $config['post_types'],
				'post_status'            => 'publish',
				'posts_per_page'         => 1000,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
				'suppress_filters'       => true,
			)
		);

		foreach ( $query->posts as $post_id ) {
			self::enqueue_post_index( (int) $post_id );
			++$queued;
		}

		return $queued;
	}

	/* ---------------------------- DB writes ------------------------------- */

	/**
	 * Replace all KB rows for a given source with a fresh set of chunks.
	 *
	 * @param string            $source_type SOURCE_POST or SOURCE_URL.
	 * @param string            $source_id   Post ID or URL hash.
	 * @param string            $source_ref  Permalink or URL.
	 * @param string            $title       Display title.
	 * @param array<int,string> $chunks      Plain-text chunks.
	 */
	private static function replace_chunks( string $source_type, string $source_id, string $source_ref, string $title, array $chunks ): void {
		global $wpdb;

		$table = OpenclaWP_Knowledge_Base_Schema::table_name();
		$now   = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bespoke table, no caching layer.
		$wpdb->delete(
			$table,
			array(
				'source_type' => $source_type,
				'source_id'   => $source_id,
			),
			array( '%s', '%s' )
		);

		$index = 0;
		foreach ( $chunks as $chunk ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bespoke table.
			$wpdb->insert(
				$table,
				array(
					'source_type' => $source_type,
					'source_id'   => $source_id,
					'source_ref'  => $source_ref,
					'title'       => $title,
					'chunk_index' => $index,
					'content'     => $chunk,
					'indexed_at'  => $now,
				),
				array( '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
			);
			++$index;
		}
	}

	/**
	 * Remove all chunk rows for a post id.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function delete_post_chunks( int $post_id ): void {
		global $wpdb;
		$table = OpenclaWP_Knowledge_Base_Schema::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bespoke table.
		$wpdb->delete(
			$table,
			array(
				'source_type' => OpenclaWP_Knowledge_Base_Schema::SOURCE_POST,
				'source_id'   => (string) $post_id,
			),
			array( '%s', '%s' )
		);
	}

	/**
	 * Remove all chunk rows for a URL.
	 *
	 * @param string $url Source URL.
	 */
	public static function delete_url_chunks( string $url ): void {
		global $wpdb;
		$table = OpenclaWP_Knowledge_Base_Schema::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bespoke table.
		$wpdb->delete(
			$table,
			array(
				'source_type' => OpenclaWP_Knowledge_Base_Schema::SOURCE_URL,
				'source_id'   => self::url_id( $url ),
			),
			array( '%s', '%s' )
		);
	}

	/* --------------------------- URL helpers ------------------------------ */

	/**
	 * Stable short id for a URL — used as `source_id` so we can dedup
	 * rows on re-crawl without storing the full URL in two columns.
	 *
	 * @param string $url Source URL.
	 */
	public static function url_id( string $url ): string {
		return substr( hash( 'sha256', $url ), 0, 32 );
	}

	/**
	 * Whether the URL is past its configured re-crawl window.
	 *
	 * @param string $url      Source URL.
	 * @param string $interval One of OpenclaWP_Knowledge_Base_Sources::INTERVAL_*.
	 */
	private static function url_is_due( string $url, string $interval ): bool {
		global $wpdb;
		$table = OpenclaWP_Knowledge_Base_Schema::table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name is internal; values are prepared on the line below.
		$last_indexed = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(indexed_at) FROM {$table} WHERE source_type = %s AND source_id = %s",
				OpenclaWP_Knowledge_Base_Schema::SOURCE_URL,
				self::url_id( $url )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( null === $last_indexed || '' === $last_indexed ) {
			return true;
		}

		$last_ts = strtotime( (string) $last_indexed . ' UTC' );
		if ( false === $last_ts ) {
			return true;
		}

		$now    = time();
		$budget = OpenclaWP_Knowledge_Base_Sources::INTERVAL_DAILY === $interval ? DAY_IN_SECONDS : WEEK_IN_SECONDS;
		return ( $now - $last_ts ) >= $budget;
	}

	/**
	 * Per-URL rate limit. We refuse to fetch the same URL more than once a
	 * minute regardless of caller — protects against admins mashing the
	 * "Reindex" button or a misconfigured cron loop.
	 *
	 * @param string $url Source URL.
	 */
	private static function rate_limit_allows( string $url ): bool {
		$key = 'openclawp_kb_rate_' . self::url_id( $url );
		if ( get_transient( $key ) ) {
			return false;
		}
		set_transient( $key, time(), self::URL_CRAWL_LOCK_MIN );
		return true;
	}

	/**
	 * Minimal robots.txt check. We only honour `Disallow` lines under a
	 * matching `User-agent: *` block (or the most specific match) — enough
	 * to be a good citizen without shipping a full parser.
	 *
	 * @param string $url Source URL.
	 */
	public static function robots_allows( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return false;
		}

		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		if ( '' === $path ) {
			$path = '/';
		}

		$robots_url = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . '/robots.txt';
		$cache_key  = 'openclawp_kb_robots_' . md5( $robots_url );

		$cached = get_transient( $cache_key );
		if ( false === $cached ) {
			$response = wp_remote_get(
				$robots_url,
				array(
					'timeout'     => 5,
					'redirection' => 2,
					'user-agent'  => 'openclaWP-knowledge-base/' . OPENCLAWP_VERSION,
				)
			);

			if ( is_wp_error( $response ) ) {
				// Missing / unreachable robots.txt — RFC says allow.
				$cached = '';
			} else {
				$code   = (int) wp_remote_retrieve_response_code( $response );
				$cached = ( $code >= 200 && $code < 300 ) ? (string) wp_remote_retrieve_body( $response ) : '';
			}

			set_transient( $cache_key, $cached, HOUR_IN_SECONDS * 6 );
		}

		if ( '' === $cached ) {
			return true;
		}

		return self::robots_path_allowed( (string) $cached, $path );
	}

	/**
	 * Naive robots.txt parser — checks Disallow lines under `User-agent: *`.
	 *
	 * Exposed as protected-style public for unit tests; production callers
	 * should go through robots_allows().
	 *
	 * @param string $robots_txt Raw robots.txt body.
	 * @param string $path       URL path to test.
	 */
	public static function robots_path_allowed( string $robots_txt, string $path ): bool {
		$lines     = preg_split( "/\r\n|\n|\r/", $robots_txt );
		$lines     = is_array( $lines ) ? $lines : array();
		$applies   = false;
		$disallows = array();

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === substr( $line, 0, 1 ) ) {
				continue;
			}
			$parts = explode( ':', $line, 2 );
			if ( count( $parts ) !== 2 ) {
				continue;
			}
			$field = strtolower( trim( $parts[0] ) );
			$value = trim( $parts[1] );

			if ( 'user-agent' === $field ) {
				$applies = ( '*' === $value );
				continue;
			}

			if ( $applies && 'disallow' === $field && '' !== $value ) {
				$disallows[] = $value;
			}
		}

		foreach ( $disallows as $disallowed_prefix ) {
			if ( 0 === strpos( $path, $disallowed_prefix ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Pull the first <title> element from an HTML document.
	 *
	 * @param string $html Raw HTML body.
	 */
	private static function extract_title( string $html ): string {
		if ( preg_match( '#<title[^>]*>(.*?)</title>#is', $html, $m ) ) {
			$title = html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			return trim( $title );
		}
		return '';
	}
}
