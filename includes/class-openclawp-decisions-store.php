<?php
/**
 * Audit log for tool-call confirmation decisions.
 *
 * Persists every confirmation prompt and its eventual resolution as a
 * `openclawp_decision` CPT row. Reused for both the inline chat-block UI and
 * (future) async channels (WhatsApp / Telegram numbered-reply flow).
 *
 * Storage choice: CPT + postmeta rather than a custom `wpdb` table.
 * Rationale — keeps the plugin schema-free, lets adopters read the audit log
 * via the standard WP REST API (`/wp/v2/openclawp-decisions`), and reuses the
 * same query / index strategy as `openclawp_usage`. A custom table would buy
 * faster aggregation, but decisions are low-volume (one per confirmation
 * prompt) and we already pay the CPT cost for usage telemetry.
 *
 * @package OpenclaWP
 * @since   0.8.0
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Decisions_Store {

	/**
	 * CPT slug. Capped at 20 chars per `register_post_type` limit.
	 */
	public const POST_TYPE = 'openclawp_decision';

	public const STATUS_PENDING = 'pending';
	public const STATUS_ALLOWED = 'allowed';
	public const STATUS_DENIED  = 'denied';
	public const STATUS_ALWAYS  = 'always';
	public const STATUS_EXPIRED = 'expired';

	public const META_DECISION_ID = '_openclawp_decision_id';
	public const META_SESSION_ID  = '_openclawp_session_id';
	public const META_USER_ID     = '_openclawp_decision_user_id';
	public const META_AGENT_SLUG  = '_openclawp_agent_slug';
	public const META_ABILITY     = '_openclawp_ability';
	public const META_EFFECT      = '_openclawp_effect';
	public const META_THRESHOLD   = '_openclawp_threshold';
	public const META_PARAMETERS  = '_openclawp_parameters';
	public const META_STATUS      = '_openclawp_decision_status';
	public const META_RESOLVED_AT = '_openclawp_decision_resolved_at';
	public const META_RESOLVED_BY = '_openclawp_decision_resolved_by';

	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'openclaWP Decisions', 'openclawp' ),
					'singular_name' => __( 'openclaWP Decision', 'openclawp' ),
				),
				'public'              => false,
				'show_ui'             => false,
				'show_in_rest'        => true,
				'rest_base'           => 'openclawp-decisions',
				'rest_namespace'      => 'wp/v2',
				'exclude_from_search' => true,
				'rewrite'             => false,
				'has_archive'         => false,
				'supports'            => array( 'title', 'author', 'custom-fields' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			)
		);
	}

	/**
	 * Persist a pending decision and return its row.
	 *
	 * @return array{decision_id:string,post_id:int}|null
	 */
	public static function create_pending( array $args ): ?array {
		$decision_id = isset( $args['decision_id'] ) && '' !== $args['decision_id']
			? (string) $args['decision_id']
			: self::generate_id();

		$post_id = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => sprintf(
					'%s · %s',
					(string) ( $args['ability'] ?? '(unknown ability)' ),
					(string) ( $args['agent_slug'] ?? '(unknown agent)' )
				),
				'post_author'  => (int) ( $args['user_id'] ?? 0 ),
				'post_content' => '',
			),
			true
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return null;
		}

		$post_id = (int) $post_id;
		update_post_meta( $post_id, self::META_DECISION_ID, $decision_id );
		update_post_meta( $post_id, self::META_SESSION_ID, (string) ( $args['session_id'] ?? '' ) );
		update_post_meta( $post_id, self::META_USER_ID,    (int) ( $args['user_id'] ?? 0 ) );
		update_post_meta( $post_id, self::META_AGENT_SLUG, (string) ( $args['agent_slug'] ?? '' ) );
		update_post_meta( $post_id, self::META_ABILITY,    (string) ( $args['ability'] ?? '' ) );
		update_post_meta( $post_id, self::META_EFFECT,     (string) ( $args['effect'] ?? '' ) );
		update_post_meta( $post_id, self::META_THRESHOLD,  (string) ( $args['threshold'] ?? '' ) );
		update_post_meta( $post_id, self::META_PARAMETERS, wp_json_encode( $args['parameters'] ?? array() ) );
		update_post_meta( $post_id, self::META_STATUS,     self::STATUS_PENDING );

		return array(
			'decision_id' => $decision_id,
			'post_id'     => $post_id,
		);
	}

	/**
	 * Resolve a pending decision. Idempotent — re-resolving a non-pending
	 * row is a no-op that returns false.
	 */
	public static function resolve( string $decision_id, string $status, int $resolved_by_user_id ): bool {
		if ( ! in_array( $status, array( self::STATUS_ALLOWED, self::STATUS_DENIED, self::STATUS_ALWAYS, self::STATUS_EXPIRED ), true ) ) {
			return false;
		}
		$post = self::find_post( $decision_id );
		if ( null === $post ) {
			return false;
		}
		$current_status = (string) get_post_meta( $post->ID, self::META_STATUS, true );
		if ( self::STATUS_PENDING !== $current_status ) {
			return false;
		}

		update_post_meta( $post->ID, self::META_STATUS,      $status );
		update_post_meta( $post->ID, self::META_RESOLVED_AT, gmdate( 'Y-m-d H:i:s' ) );
		update_post_meta( $post->ID, self::META_RESOLVED_BY, $resolved_by_user_id );

		/**
		 * Fires when a tool-call decision is resolved by the user.
		 *
		 * @since 0.8.0
		 *
		 * @param string $decision_id The decision UUID.
		 * @param string $status      One of allowed | denied | always.
		 * @param array  $record      Full decision record.
		 */
		do_action( 'openclawp_tool_decision_resolved', $decision_id, $status, self::get( $decision_id ) );

		return true;
	}

	/**
	 * Fetch a decision by id.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function get( string $decision_id ): ?array {
		$post = self::find_post( $decision_id );
		if ( null === $post ) {
			return null;
		}
		return self::to_array( $post );
	}

	/**
	 * List recent decisions, optionally filtered by ability + user id.
	 *
	 * @param array{ability?:string,user_id?:int,limit?:int,status?:string} $filters
	 * @return array<int,array<string,mixed>>
	 */
	public static function recent( array $filters = array() ): array {
		$limit = isset( $filters['limit'] ) ? max( 1, (int) $filters['limit'] ) : 100;

		$meta_query = array( 'relation' => 'AND' );
		if ( isset( $filters['ability'] ) && '' !== $filters['ability'] ) {
			$meta_query[] = array( 'key' => self::META_ABILITY, 'value' => (string) $filters['ability'] );
		}
		if ( isset( $filters['status'] ) && '' !== $filters['status'] ) {
			$meta_query[] = array( 'key' => self::META_STATUS, 'value' => (string) $filters['status'] );
		}
		if ( isset( $filters['user_id'] ) && (int) $filters['user_id'] > 0 ) {
			$meta_query[] = array(
				'key'   => self::META_USER_ID,
				'value' => (int) $filters['user_id'],
				'type'  => 'NUMERIC',
			);
		}

		$query = new WP_Query(
			array(
				'post_type'              => self::POST_TYPE,
				'post_status'            => 'any',
				'posts_per_page'         => $limit,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'meta_query'             => count( $meta_query ) > 1 ? $meta_query : array(),
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'suppress_filters'       => true,
			)
		);

		$out = array();
		foreach ( $query->posts as $post ) {
			$out[] = self::to_array( $post );
		}
		return $out;
	}

	private static function find_post( string $decision_id ): ?WP_Post {
		if ( '' === trim( $decision_id ) ) {
			return null;
		}
		$query = new WP_Query(
			array(
				'post_type'              => self::POST_TYPE,
				'post_status'            => 'any',
				'posts_per_page'         => 1,
				'meta_key'               => self::META_DECISION_ID,
				'meta_value'             => $decision_id,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'suppress_filters'       => true,
			)
		);
		return ! empty( $query->posts ) ? $query->posts[0] : null;
	}

	private static function to_array( WP_Post $post ): array {
		$params_raw = get_post_meta( $post->ID, self::META_PARAMETERS, true );
		$params     = is_string( $params_raw ) && '' !== $params_raw ? json_decode( $params_raw, true ) : array();

		return array(
			'decision_id' => (string) get_post_meta( $post->ID, self::META_DECISION_ID, true ),
			'session_id'  => (string) get_post_meta( $post->ID, self::META_SESSION_ID, true ),
			'user_id'     => (int) get_post_meta( $post->ID, self::META_USER_ID, true ),
			'agent_slug'  => (string) get_post_meta( $post->ID, self::META_AGENT_SLUG, true ),
			'ability'     => (string) get_post_meta( $post->ID, self::META_ABILITY, true ),
			'effect'      => (string) get_post_meta( $post->ID, self::META_EFFECT, true ),
			'threshold'   => (string) get_post_meta( $post->ID, self::META_THRESHOLD, true ),
			'parameters'  => is_array( $params ) ? $params : array(),
			'status'      => (string) ( get_post_meta( $post->ID, self::META_STATUS, true ) ?: self::STATUS_PENDING ),
			'created_at'  => (string) $post->post_date_gmt,
			'resolved_at' => (string) get_post_meta( $post->ID, self::META_RESOLVED_AT, true ),
			'resolved_by' => (int) get_post_meta( $post->ID, self::META_RESOLVED_BY, true ),
		);
	}

	private static function generate_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}
		$data    = random_bytes( 16 );
		$data[6] = chr( ( ord( $data[6] ) & 0x0f ) | 0x40 );
		$data[8] = chr( ( ord( $data[8] ) & 0x3f ) | 0x80 );
		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
	}
}
