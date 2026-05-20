<?php
/**
 * Lightweight agent memory store.
 *
 * This is a provenance-aware CPT store for explicit memories. It avoids a
 * provider lock-in: embeddings/vector ranking can be layered later through
 * filters while the stored contract stays stable.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Memory_Store {

	public const POST_TYPE = 'openclawp_memory';

	public const META_SCOPE       = '_openclawp_memory_scope';
	public const META_USER_ID     = '_openclawp_memory_user_id';
	public const META_AGENT_SLUG  = '_openclawp_memory_agent_slug';
	public const META_SOURCE      = '_openclawp_memory_source';
	public const META_CONFIDENCE  = '_openclawp_memory_confidence';
	public const META_CONSENTED   = '_openclawp_memory_consented';
	public const META_EXPIRES_AT  = '_openclawp_memory_expires_at';

	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'openclaWP Memories', 'openclawp' ),
					'singular_name' => __( 'openclaWP Memory', 'openclawp' ),
				),
				'public'              => false,
				'show_ui'             => false,
				'show_in_rest'        => true,
				'rest_base'           => 'openclawp-memories',
				'rest_namespace'      => 'wp/v2',
				'exclude_from_search' => true,
				'rewrite'             => false,
				'has_archive'         => false,
				'supports'            => array( 'title', 'editor', 'author', 'custom-fields' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			)
		);
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>|WP_Error
	 */
	public static function save( array $args ) {
		$text = trim( (string) ( $args['text'] ?? '' ) );
		if ( '' === $text ) {
			return new WP_Error(
				'openclawp_memory_empty',
				__( 'Memory text is required.', 'openclawp' ),
				array( 'status' => 400 )
			);
		}

		$consented = ! empty( $args['consent'] );
		if ( ! $consented && apply_filters( 'openclawp_memory_requires_consent', true, $args ) ) {
			return new WP_Error(
				'openclawp_memory_requires_consent',
				__( 'Explicit consent is required before storing memory.', 'openclawp' ),
				array( 'status' => 400 )
			);
		}

		$scope      = self::normalize_scope( (string) ( $args['scope'] ?? 'user' ) );
		$user_id    = isset( $args['user_id'] ) ? (int) $args['user_id'] : ( function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0 );
		$agent_slug = sanitize_key( (string) ( $args['agent_slug'] ?? '' ) );
		$source     = sanitize_text_field( (string) ( $args['source'] ?? 'agent' ) );
		$confidence = max( 0.0, min( 1.0, (float) ( $args['confidence'] ?? 1.0 ) ) );
		$expires_at = self::normalize_datetime( (string) ( $args['expires_at'] ?? '' ) );

		$title = self::title_from_text( $text );
		$post_id = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $text,
				'post_author'  => $user_id,
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( (int) $post_id, self::META_SCOPE, $scope );
		update_post_meta( (int) $post_id, self::META_USER_ID, $user_id );
		update_post_meta( (int) $post_id, self::META_AGENT_SLUG, $agent_slug );
		update_post_meta( (int) $post_id, self::META_SOURCE, $source );
		update_post_meta( (int) $post_id, self::META_CONFIDENCE, $confidence );
		update_post_meta( (int) $post_id, self::META_CONSENTED, $consented ? '1' : '0' );
		if ( '' !== $expires_at ) {
			update_post_meta( (int) $post_id, self::META_EXPIRES_AT, $expires_at );
		}

		return self::hydrate( (int) $post_id );
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<int,array<string,mixed>>
	 */
	public static function search( array $args ): array {
		$query      = trim( (string) ( $args['query'] ?? '' ) );
		$scope      = self::normalize_scope( (string) ( $args['scope'] ?? '' ) );
		$user_id    = isset( $args['user_id'] ) ? (int) $args['user_id'] : ( function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0 );
		$agent_slug = sanitize_key( (string) ( $args['agent_slug'] ?? '' ) );
		$limit      = max( 1, min( 25, (int) ( $args['limit'] ?? 5 ) ) );

		$wp_query = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
			'meta_query'     => array(),
		);
		if ( '' !== $query ) {
			$wp_query['s'] = $query;
		}
		if ( '' !== $scope ) {
			$wp_query['meta_query'][] = array(
				'key'   => self::META_SCOPE,
				'value' => $scope,
			);
		}
		if ( $user_id > 0 ) {
			$wp_query['meta_query'][] = array(
				'key'   => self::META_USER_ID,
				'value' => $user_id,
			);
		}
		if ( '' !== $agent_slug ) {
			$wp_query['meta_query'][] = array(
				'key'   => self::META_AGENT_SLUG,
				'value' => $agent_slug,
			);
		}

		$posts = get_posts( $wp_query );
		$out   = array();
		foreach ( $posts as $post ) {
			$item = self::hydrate_post( $post );
			if ( self::is_expired( (string) $item['expires_at'] ) ) {
				continue;
			}
			$out[] = $item;
		}

		return $out;
	}

	/**
	 * @return array<string,mixed>|WP_Error
	 */
	public static function hydrate( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || self::POST_TYPE !== (string) $post->post_type ) {
			return new WP_Error(
				'openclawp_memory_not_found',
				__( 'The memory was not found.', 'openclawp' ),
				array( 'status' => 404 )
			);
		}
		return self::hydrate_post( $post );
	}

	private static function hydrate_post( \WP_Post $post ): array {
		$meta = static fn ( string $key ) => get_post_meta( (int) $post->ID, $key, true );
		return array(
			'memory_id'  => (int) $post->ID,
			'text'       => (string) $post->post_content,
			'scope'      => (string) $meta( self::META_SCOPE ),
			'user_id'    => (int) $meta( self::META_USER_ID ),
			'agent_slug' => (string) $meta( self::META_AGENT_SLUG ),
			'source'     => (string) $meta( self::META_SOURCE ),
			'confidence' => (float) $meta( self::META_CONFIDENCE ),
			'consented'  => '1' === (string) $meta( self::META_CONSENTED ),
			'expires_at' => (string) $meta( self::META_EXPIRES_AT ),
		);
	}

	public static function normalize_scope( string $scope ): string {
		$scope = sanitize_key( $scope );
		return in_array( $scope, array( 'site', 'user', 'agent' ), true ) ? $scope : 'user';
	}

	public static function title_from_text( string $text ): string {
		$text = trim( preg_replace( '/\s+/', ' ', strip_tags( $text ) ) ?? '' );
		if ( '' === $text ) {
			return __( 'Memory', 'openclawp' );
		}
		return strlen( $text ) > 80 ? substr( $text, 0, 77 ) . '...' : $text;
	}

	private static function normalize_datetime( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		$timestamp = strtotime( $value );
		return false === $timestamp ? '' : gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	private static function is_expired( string $expires_at ): bool {
		if ( '' === $expires_at ) {
			return false;
		}
		$timestamp = strtotime( $expires_at . ' UTC' );
		return false !== $timestamp && $timestamp < time();
	}
}
