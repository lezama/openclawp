<?php
/**
 * Knowledge-base search ability — full-text MATCH ... AGAINST lookup.
 *
 * Registered as `knowledge-base/search` via the WP Abilities API. Returns
 * a list of result objects suitable for an agent to cite:
 *
 *   [
 *     'source'    => 'post' | 'url',
 *     'title'     => string,
 *     'excerpt'   => string,           // ~50 words around the strongest match
 *     'score'     => float,            // MATCH ... AGAINST relevance
 *     'permalink' => string,           // permalink (posts) or source URL
 *     'source_id' => string,
 *   ]
 *
 * Phase 2 (vector retrieval) will swap the SQL for an embeddings lookup
 * transparently — the response shape is intentionally provider-agnostic.
 *
 * @package OpenclaWP
 * @since   0.7.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Knowledge-base search ability — full-text MATCH ... AGAINST lookup.
 */
final class OpenclaWP_Knowledge_Base_Search {

	public const ABILITY = 'knowledge-base/search';

	public const DEFAULT_LIMIT = 5;
	public const MAX_LIMIT     = 25;

	/**
	 * Hook the ability registration into the Abilities API init action.
	 */
	public static function register(): void {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ) );
	}

	/**
	 * Register the `knowledge-base/search` ability on the Abilities API.
	 */
	public static function register_ability(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY ) ) {
			return;
		}

		wp_register_ability(
			self::ABILITY,
			array(
				'label'               => __( 'Knowledge base: search', 'openclawp' ),
				'description'         => __( 'Full-text search across the site\'s configured knowledge base (posts, URLs). Returns ranked chunks with citations the agent can quote.', 'openclawp' ),
				'category'            => 'openclawp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'query' => array(
							'type'        => 'string',
							'description' => 'Natural-language query. Boolean operators are passed straight through to MySQL.',
						),
						'limit' => array(
							'type'        => 'integer',
							'description' => 'Maximum results to return (1-25).',
							'default'     => self::DEFAULT_LIMIT,
						),
					),
					'required'   => array( 'query' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'results' => array(
							'type'        => 'array',
							'description' => 'Ranked results; each item carries a citable permalink.',
						),
					),
					'required'   => array( 'results' ),
				),
				'execute_callback'    => static function ( array $args ) {
					$query = isset( $args['query'] ) ? (string) $args['query'] : '';
					$limit = isset( $args['limit'] ) ? (int) $args['limit'] : self::DEFAULT_LIMIT;
					if ( '' === trim( $query ) ) {
						return new WP_Error(
							'openclawp_kb_invalid_query',
							__( 'A non-empty query is required.', 'openclawp' ),
							array( 'status' => 400 )
						);
					}
					return array( 'results' => self::search( $query, $limit ) );
				},
				'permission_callback' => static function (): bool {
					/**
					 * Filters whether the current caller may invoke knowledge-base/search.
					 *
					 * Default: any authenticated user. Hosts that expose the KB to
					 * anonymous chat surfaces should leave this open; sites that
					 * KB private content can tighten it to `manage_options`.
					 *
					 * @param bool $allowed
					 */
					return (bool) apply_filters( 'openclawp_kb_search_permission', is_user_logged_in() );
				},
			)
		);
	}

	/**
	 * Run a full-text search against the KB table.
	 *
	 * @param string $query Natural-language query.
	 * @param int    $limit Maximum results to return.
	 * @return array<int,array<string,mixed>>
	 */
	public static function search( string $query, int $limit = self::DEFAULT_LIMIT ): array {
		$query = trim( $query );
		if ( '' === $query ) {
			return array();
		}

		$limit = max( 1, min( self::MAX_LIMIT, $limit ) );

		/**
		 * Allows an embeddings/vector provider to satisfy KB search.
		 *
		 * Return an array of normalized result rows to bypass SQL FULLTEXT.
		 * Return null to use the built-in full-text path.
		 *
		 * @param array|null $results
		 * @param string     $query
		 * @param int        $limit
		 */
		$vector_results = apply_filters( 'openclawp_kb_vector_search_results', null, $query, $limit );
		if ( is_array( $vector_results ) ) {
			return self::normalize_external_results( $vector_results, $limit );
		}

		global $wpdb;
		$table = OpenclaWP_Knowledge_Base_Schema::table_name();

		// Use NATURAL LANGUAGE MODE so user prose works without boolean operators.
		// MySQL's default minimum word length is 4 chars (ft_min_word_len /
		// innodb_ft_min_token_size); installs that want shorter terms tune
		// MySQL — we don't try to work around that here.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$sql = "SELECT id, source_type, source_id, source_ref, title, chunk_index, content,
			MATCH(title, content) AGAINST(%s IN NATURAL LANGUAGE MODE) AS score
			FROM {$table}
			WHERE MATCH(title, content) AGAINST(%s IN NATURAL LANGUAGE MODE)
			ORDER BY score DESC
			LIMIT %d";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $sql is a constant template; values are prepared on the next call.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $query, $query, $limit ), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$results = array();
		foreach ( $rows as $row ) {
			$results[] = array(
				'source'    => (string) $row['source_type'],
				'source_id' => (string) $row['source_id'],
				'title'     => (string) $row['title'],
				'excerpt'   => self::build_excerpt( (string) $row['content'], $query ),
				'score'     => (float) $row['score'],
				'permalink' => (string) $row['source_ref'],
			);
		}

		return $results;
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<int,array<string,mixed>>
	 */
	public static function normalize_external_results( array $rows, int $limit = self::DEFAULT_LIMIT ): array {
		$out = array();
		foreach ( array_slice( $rows, 0, max( 1, min( self::MAX_LIMIT, $limit ) ) ) as $row ) {
			$out[] = array(
				'source'    => (string) ( $row['source'] ?? $row['source_type'] ?? '' ),
				'source_id' => (string) ( $row['source_id'] ?? '' ),
				'title'     => (string) ( $row['title'] ?? '' ),
				'excerpt'   => (string) ( $row['excerpt'] ?? $row['content'] ?? '' ),
				'score'     => (float) ( $row['score'] ?? 0 ),
				'permalink' => (string) ( $row['permalink'] ?? $row['source_ref'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * Build a short excerpt around the first occurrence of any query term.
	 * Falls back to the chunk head when no term matches.
	 *
	 * @param string $content     Chunk content.
	 * @param string $query       Original query string.
	 * @param int    $word_window Approximate word count for the excerpt.
	 */
	public static function build_excerpt( string $content, string $query, int $word_window = 50 ): string {
		$content = trim( preg_replace( '/\s+/', ' ', $content ) ?? '' );
		if ( '' === $content ) {
			return '';
		}

		$terms_raw = preg_split( '/\s+/', $query );
		$terms     = array_filter( is_array( $terms_raw ) ? $terms_raw : array(), static fn ( $t ) => '' !== $t );
		$lower     = strtolower( $content );
		$pos       = false;
		foreach ( $terms as $term ) {
			$needle = strtolower( $term );
			$found  = strpos( $lower, $needle );
			if ( false !== $found && ( false === $pos || $found < $pos ) ) {
				$pos = $found;
			}
		}

		$words = preg_split( '/\s+/', $content );
		if ( ! is_array( $words ) ) {
			return $content;
		}

		if ( false === $pos ) {
			return implode( ' ', array_slice( $words, 0, $word_window ) ) . ( count( $words ) > $word_window ? '…' : '' );
		}

		// Find word index for character position.
		$running = 0;
		$word_at = 0;
		foreach ( $words as $i => $word ) {
			$len = strlen( $word ) + 1; // + space.
			if ( $running + $len > $pos ) {
				$word_at = $i;
				break;
			}
			$running += $len;
		}

		$start  = max( 0, $word_at - (int) floor( $word_window / 2 ) );
		$slice  = array_slice( $words, $start, $word_window );
		$prefix = $start > 0 ? '…' : '';
		$suffix = ( $start + $word_window ) < count( $words ) ? '…' : '';
		return $prefix . implode( ' ', $slice ) . $suffix;
	}
}
