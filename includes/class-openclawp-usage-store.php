<?php
/**
 * Query helpers for the `openclawp_usage` CPT.
 *
 * Aggregations go through prepared SQL against `wp_postmeta` joined to
 * `wp_posts` so we don't hydrate thousands of WP_Post objects to sum a
 * column. The admin Usage page is the primary consumer.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Usage_Store {

	/**
	 * Return recent turns as hydrated arrays (newest first).
	 *
	 * @param int   $limit   How many rows.
	 * @param array $filters Optional: ['agent_slug' => …, 'days' => N].
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_recent( int $limit = 50, array $filters = array() ): array {
		$query = array(
			'post_type'      => OpenclaWP_Usage_Recorder::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => max( 1, $limit ),
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		);

		if ( ! empty( $filters['agent_slug'] ) ) {
			// phpcs:disable WordPress.DB.SlowDBQuery
			$query['meta_key']   = OpenclaWP_Usage_Recorder::META_AGENT_SLUG;
			$query['meta_value'] = (string) $filters['agent_slug'];
			// phpcs:enable
		}

		if ( ! empty( $filters['days'] ) ) {
			$query['date_query'] = array(
				array(
					'after'     => gmdate( 'Y-m-d 00:00:00', strtotime( '-' . (int) $filters['days'] . ' days' ) ),
					'inclusive' => true,
				),
			);
		}

		$posts = get_posts( $query );
		$out   = array();
		foreach ( $posts as $post ) {
			$out[] = self::hydrate( $post );
		}
		return $out;
	}

	/**
	 * Aggregate totals over a window.
	 *
	 * @param array $filter ['days' => N (default 30), 'agent_slug' => …,
	 *                       'period' => 'month'|'day'|'all'].
	 *
	 * @return array{turns:int, input_tokens:int, output_tokens:int,
	 *               total_tokens:int, est_cost_usd:float,
	 *               unpriced_turns:int}
	 */
	public static function get_totals( array $filter = array() ): array {
		global $wpdb;

		$where    = "p.post_type = %s AND p.post_status = 'publish'";
		$params   = array( OpenclaWP_Usage_Recorder::POST_TYPE );
		$date_sql = self::date_constraint( $filter );
		if ( '' !== $date_sql ) {
			$where .= ' AND ' . $date_sql;
		}

		if ( ! empty( $filter['agent_slug'] ) ) {
			$where   .= " AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = %s)";
			$params[] = OpenclaWP_Usage_Recorder::META_AGENT_SLUG;
			$params[] = (string) $filter['agent_slug'];
		}

		$sql = "
			SELECT
				COUNT(DISTINCT p.ID) AS turns,
				COALESCE(SUM(CASE WHEN pm.meta_key = %s THEN CAST(pm.meta_value AS UNSIGNED) ELSE 0 END), 0) AS input_tokens,
				COALESCE(SUM(CASE WHEN pm.meta_key = %s THEN CAST(pm.meta_value AS UNSIGNED) ELSE 0 END), 0) AS output_tokens,
				COALESCE(SUM(CASE WHEN pm.meta_key = %s THEN CAST(pm.meta_value AS UNSIGNED) ELSE 0 END), 0) AS total_tokens,
				COALESCE(SUM(CASE WHEN pm.meta_key = %s THEN CAST(pm.meta_value AS DECIMAL(14,6)) ELSE 0 END), 0) AS est_cost_usd,
				COALESCE(SUM(CASE WHEN pm.meta_key = %s AND pm.meta_value = '0' THEN 1 ELSE 0 END), 0) AS unpriced_turns
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
			WHERE {$where}
		";

		$meta_params = array(
			OpenclaWP_Usage_Recorder::META_INPUT_TOKENS,
			OpenclaWP_Usage_Recorder::META_OUTPUT_TOKENS,
			OpenclaWP_Usage_Recorder::META_TOTAL_TOKENS,
			OpenclaWP_Usage_Recorder::META_EST_COST_USD,
			OpenclaWP_Usage_Recorder::META_PRICING_RESOLVED,
		);

		$all_params = array_merge( $meta_params, $params );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- aggregate query, no caching needed for admin page
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $all_params ), ARRAY_A );

		return array(
			'turns'          => (int) ( $row['turns'] ?? 0 ),
			'input_tokens'   => (int) ( $row['input_tokens'] ?? 0 ),
			'output_tokens'  => (int) ( $row['output_tokens'] ?? 0 ),
			'total_tokens'   => (int) ( $row['total_tokens'] ?? 0 ),
			'est_cost_usd'   => (float) ( $row['est_cost_usd'] ?? 0.0 ),
			'unpriced_turns' => (int) ( $row['unpriced_turns'] ?? 0 ),
		);
	}

	/**
	 * Bucket totals per day.
	 *
	 * @return array<string, array{turns:int, total_tokens:int, est_cost_usd:float}>
	 */
	public static function get_by_day( int $days = 14 ): array {
		global $wpdb;

		$after = gmdate( 'Y-m-d 00:00:00', strtotime( '-' . max( 1, $days ) . ' days' ) );

		$sql = "
			SELECT
				DATE(p.post_date_gmt) AS day,
				COUNT(DISTINCT p.ID) AS turns,
				COALESCE(SUM(CASE WHEN pm.meta_key = %s THEN CAST(pm.meta_value AS UNSIGNED) ELSE 0 END), 0) AS total_tokens,
				COALESCE(SUM(CASE WHEN pm.meta_key = %s THEN CAST(pm.meta_value AS DECIMAL(14,6)) ELSE 0 END), 0) AS est_cost_usd
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
			WHERE p.post_type = %s AND p.post_status = 'publish' AND p.post_date_gmt >= %s
			GROUP BY DATE(p.post_date_gmt)
			ORDER BY day DESC
		";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				$sql,
				OpenclaWP_Usage_Recorder::META_TOTAL_TOKENS,
				OpenclaWP_Usage_Recorder::META_EST_COST_USD,
				OpenclaWP_Usage_Recorder::POST_TYPE,
				$after
			),
			ARRAY_A
		);

		$out = array();
		foreach ( $rows as $row ) {
			$out[ (string) $row['day'] ] = array(
				'turns'        => (int) $row['turns'],
				'total_tokens' => (int) $row['total_tokens'],
				'est_cost_usd' => (float) $row['est_cost_usd'],
			);
		}
		return $out;
	}

	/**
	 * Bucket totals per `provider|model`.
	 *
	 * @return array<string, array{turns:int, total_tokens:int, est_cost_usd:float}>
	 */
	public static function get_by_model( int $days = 30 ): array {
		global $wpdb;

		$after = gmdate( 'Y-m-d 00:00:00', strtotime( '-' . max( 1, $days ) . ' days' ) );

		$sql = "
			SELECT
				CONCAT(IFNULL(prov.meta_value, ''), '|', IFNULL(mdl.meta_value, '')) AS bucket,
				COUNT(DISTINCT p.ID) AS turns,
				COALESCE(SUM(CASE WHEN pm.meta_key = %s THEN CAST(pm.meta_value AS UNSIGNED) ELSE 0 END), 0) AS total_tokens,
				COALESCE(SUM(CASE WHEN pm.meta_key = %s THEN CAST(pm.meta_value AS DECIMAL(14,6)) ELSE 0 END), 0) AS est_cost_usd
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} pm   ON pm.post_id   = p.ID
			LEFT JOIN {$wpdb->postmeta} prov ON prov.post_id = p.ID AND prov.meta_key = %s
			LEFT JOIN {$wpdb->postmeta} mdl  ON mdl.post_id  = p.ID AND mdl.meta_key = %s
			WHERE p.post_type = %s AND p.post_status = 'publish' AND p.post_date_gmt >= %s
			GROUP BY bucket
			ORDER BY est_cost_usd DESC
		";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				$sql,
				OpenclaWP_Usage_Recorder::META_TOTAL_TOKENS,
				OpenclaWP_Usage_Recorder::META_EST_COST_USD,
				OpenclaWP_Usage_Recorder::META_PROVIDER,
				OpenclaWP_Usage_Recorder::META_MODEL,
				OpenclaWP_Usage_Recorder::POST_TYPE,
				$after
			),
			ARRAY_A
		);

		$out = array();
		foreach ( $rows as $row ) {
			$bucket = (string) $row['bucket'];
			if ( '|' === $bucket ) {
				$bucket = '(unknown)';
			}
			$out[ $bucket ] = array(
				'turns'        => (int) $row['turns'],
				'total_tokens' => (int) $row['total_tokens'],
				'est_cost_usd' => (float) $row['est_cost_usd'],
			);
		}
		return $out;
	}

	private static function date_constraint( array $filter ): string {
		$days = isset( $filter['days'] ) ? (int) $filter['days'] : 0;
		if ( $days <= 0 && ! in_array( (string) ( $filter['period'] ?? '' ), array( 'day', 'month' ), true ) ) {
			return '';
		}
		if ( ( $filter['period'] ?? '' ) === 'month' ) {
			$after = gmdate( 'Y-m-01 00:00:00' );
		} elseif ( ( $filter['period'] ?? '' ) === 'day' ) {
			$after = gmdate( 'Y-m-d 00:00:00' );
		} else {
			$after = gmdate( 'Y-m-d 00:00:00', strtotime( '-' . $days . ' days' ) );
		}
		global $wpdb;
		return $wpdb->prepare( 'p.post_date_gmt >= %s', $after );
	}

	/**
	 * Hydrate a WP_Post into a flat usage-record array.
	 *
	 * @return array<string, mixed>
	 */
	public static function hydrate( \WP_Post $post ): array {
		$meta = function ( string $key ) use ( $post ) {
			return get_post_meta( $post->ID, $key, true );
		};

		return array(
			'id'              => (int) $post->ID,
			'date_gmt'        => (string) $post->post_date_gmt,
			'title'           => (string) $post->post_title,
			'agent_slug'      => (string) $meta( OpenclaWP_Usage_Recorder::META_AGENT_SLUG ),
			'session_id'      => (string) $meta( OpenclaWP_Usage_Recorder::META_SESSION_ID ),
			'provider'        => (string) $meta( OpenclaWP_Usage_Recorder::META_PROVIDER ),
			'model'           => (string) $meta( OpenclaWP_Usage_Recorder::META_MODEL ),
			'input_tokens'    => (int)    $meta( OpenclaWP_Usage_Recorder::META_INPUT_TOKENS ),
			'output_tokens'   => (int)    $meta( OpenclaWP_Usage_Recorder::META_OUTPUT_TOKENS ),
			'total_tokens'    => (int)    $meta( OpenclaWP_Usage_Recorder::META_TOTAL_TOKENS ),
			'est_cost_usd'    => (float)  $meta( OpenclaWP_Usage_Recorder::META_EST_COST_USD ),
			'pricing_resolved' => '1' === (string) $meta( OpenclaWP_Usage_Recorder::META_PRICING_RESOLVED ),
			'wall_duration_ms' => (int)   $meta( OpenclaWP_Usage_Recorder::META_WALL_DURATION_MS ),
			'tool_call_count' => (int)    $meta( OpenclaWP_Usage_Recorder::META_TOOL_CALL_COUNT ),
			'success'         => '1' === (string) $meta( OpenclaWP_Usage_Recorder::META_SUCCESS ),
			'error'           => (string) $meta( OpenclaWP_Usage_Recorder::META_ERROR ),
		);
	}
}
