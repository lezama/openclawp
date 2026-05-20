<?php
/**
 * Agency client workspace store.
 *
 * A workspace represents one client/site the agency wants to automate.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Agency_Workspace_Store {

	public const POST_TYPE = 'openclawp_client';

	public const META_SITE_URL  = '_openclawp_client_site_url';
	public const META_INDUSTRY  = '_openclawp_client_industry';
	public const META_GOALS     = '_openclawp_client_goals';
	public const META_CHANNELS  = '_openclawp_client_channels';
	public const META_CONNECTORS = '_openclawp_client_connectors';
	public const META_NOTES     = '_openclawp_client_notes';

	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'openclaWP Clients', 'openclawp' ),
					'singular_name' => __( 'openclaWP Client', 'openclawp' ),
				),
				'public'              => false,
				'show_ui'             => false,
				'show_in_rest'        => true,
				'rest_base'           => 'openclawp-clients',
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
		$name = sanitize_text_field( (string) ( $args['name'] ?? '' ) );
		if ( '' === $name ) {
			return new WP_Error(
				'openclawp_client_name_required',
				__( 'Client name is required.', 'openclawp' ),
				array( 'status' => 400 )
			);
		}

		$postarr = array(
			'post_type'    => self::POST_TYPE,
			'post_status'  => 'publish',
			'post_title'   => $name,
			'post_content' => sanitize_textarea_field( (string) ( $args['summary'] ?? '' ) ),
			'post_author'  => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
		);

		$workspace_id = isset( $args['workspace_id'] ) ? (int) $args['workspace_id'] : 0;
		if ( $workspace_id > 0 ) {
			$postarr['ID'] = $workspace_id;
			$post_id       = wp_update_post( $postarr, true );
		} else {
			$post_id = wp_insert_post( $postarr, true );
		}

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( (int) $post_id, self::META_SITE_URL, esc_url_raw( (string) ( $args['site_url'] ?? '' ) ) );
		update_post_meta( (int) $post_id, self::META_INDUSTRY, sanitize_text_field( (string) ( $args['industry'] ?? '' ) ) );
		update_post_meta( (int) $post_id, self::META_GOALS, self::sanitize_text_list( $args['goals'] ?? array() ) );
		update_post_meta( (int) $post_id, self::META_CHANNELS, self::sanitize_key_list( $args['channels'] ?? array() ) );
		update_post_meta( (int) $post_id, self::META_CONNECTORS, self::sanitize_key_list( $args['connectors'] ?? array() ) );
		update_post_meta( (int) $post_id, self::META_NOTES, sanitize_textarea_field( (string) ( $args['notes'] ?? '' ) ) );

		return self::hydrate( (int) $post_id );
	}

	/**
	 * @return array<string,mixed>|WP_Error
	 */
	public static function hydrate( int $workspace_id ) {
		$post = get_post( $workspace_id );
		if ( ! $post instanceof \WP_Post || self::POST_TYPE !== (string) $post->post_type ) {
			return new WP_Error(
				'openclawp_client_not_found',
				__( 'Client workspace not found.', 'openclawp' ),
				array( 'status' => 404 )
			);
		}
		return self::hydrate_post( $post );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function all( int $limit = 50 ): array {
		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => max( 1, min( 100, $limit ) ),
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);

		$out = array();
		foreach ( $posts as $post ) {
			$out[] = self::hydrate_post( $post );
		}
		return $out;
	}

	public static function hydrate_post( \WP_Post $post ): array {
		$meta = static fn ( string $key ) => get_post_meta( (int) $post->ID, $key, true );
		return array(
			'workspace_id' => (int) $post->ID,
			'name'         => (string) $post->post_title,
			'summary'      => (string) $post->post_content,
			'site_url'     => (string) $meta( self::META_SITE_URL ),
			'industry'     => (string) $meta( self::META_INDUSTRY ),
			'goals'        => self::sanitize_text_list( $meta( self::META_GOALS ) ),
			'channels'     => self::sanitize_key_list( $meta( self::META_CHANNELS ) ),
			'connectors'   => self::sanitize_key_list( $meta( self::META_CONNECTORS ) ),
			'notes'        => (string) $meta( self::META_NOTES ),
		);
	}

	/**
	 * @param mixed $value
	 * @return array<int,string>
	 */
	public static function sanitize_key_list( $value ): array {
		$items = is_array( $value ) ? $value : explode( ',', (string) $value );
		$out   = array();
		foreach ( $items as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}
			$key = sanitize_key( (string) $item );
			if ( '' !== $key ) {
				$out[] = $key;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * @param mixed $value
	 * @return array<int,string>
	 */
	public static function sanitize_text_list( $value ): array {
		$items = is_array( $value ) ? $value : explode( ',', (string) $value );
		$out   = array();
		foreach ( $items as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}
			$text = sanitize_text_field( (string) $item );
			if ( '' !== $text ) {
				$out[] = $text;
			}
		}
		return array_values( array_unique( $out ) );
	}
}
