<?php
/**
 * Generated agency demo package store.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Agency_Demo_Store {

	public const POST_TYPE = 'openclawp_demo';

	public const META_WORKSPACE_ID = '_openclawp_demo_workspace_id';
	public const META_BLUEPRINT    = '_openclawp_demo_blueprint';
	public const META_PACKAGE_ID   = '_openclawp_demo_package_id';

	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'openclaWP Automation Demos', 'openclawp' ),
					'singular_name' => __( 'openclaWP Automation Demo', 'openclawp' ),
				),
				'public'              => false,
				'show_ui'             => false,
				'show_in_rest'        => true,
				'rest_base'           => 'openclawp-demos',
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
	 * @param array<string,mixed> $package
	 * @return array<string,mixed>|WP_Error
	 */
	public static function save( array $package ) {
		$package_id = (string) ( $package['package_id'] ?? '' );
		if ( '' === $package_id ) {
			return new WP_Error(
				'openclawp_demo_package_id_required',
				__( 'Generated package is missing an id.', 'openclawp' ),
				array( 'status' => 400 )
			);
		}

		$json = (string) wp_json_encode( $package );
		if ( function_exists( 'wp_slash' ) ) {
			$json = wp_slash( $json );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => sanitize_text_field( (string) ( $package['title'] ?? $package_id ) ),
				'post_content' => $json,
				'post_author'  => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( (int) $post_id, self::META_WORKSPACE_ID, (int) ( $package['workspace']['workspace_id'] ?? 0 ) );
		update_post_meta( (int) $post_id, self::META_BLUEPRINT, sanitize_key( (string) ( $package['blueprint']['slug'] ?? '' ) ) );
		update_post_meta( (int) $post_id, self::META_PACKAGE_ID, $package_id );

		$package['demo_id'] = (int) $post_id;
		return $package;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function recent( int $limit = 20 ): array {
		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => max( 1, min( 50, $limit ) ),
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);

		$out = array();
		foreach ( $posts as $post ) {
			$decoded = json_decode( (string) $post->post_content, true );
			if ( is_array( $decoded ) ) {
				$decoded['demo_id'] = (int) $post->ID;
				$out[]             = $decoded;
			}
		}
		return $out;
	}
}
