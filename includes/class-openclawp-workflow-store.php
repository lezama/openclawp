<?php
/**
 * CPT-backed workflow spec store.
 *
 * Implements `WP_Agent_Workflow_Store` against `wp_posts` + `wp_postmeta` —
 * each registered (or REST-uploaded) workflow gets one `openclawp_workflow`
 * post, with the spec serialised to `post_content` and the workflow id
 * indexed via post-meta so look-ups by id are direct.
 *
 * The CPT is `show_ui = false` because openclaWP renders its own Workflows
 * admin (in a follow-up); WP-Query / REST access still works through
 * `/wp/v2/openclawp-workflows` for tooling that wants raw access.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Spec;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Store;

final class OpenclaWP_Workflow_Store implements WP_Agent_Workflow_Store {

	public const POST_TYPE = 'openclawp_workflow';

	private const META_WORKFLOW_ID = '_openclawp_workflow_id';

	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'openclaWP Workflows', 'openclawp' ),
					'singular_name' => __( 'openclaWP Workflow', 'openclawp' ),
				),
				'public'              => false,
				'show_ui'             => false,
				'show_in_rest'        => true,
				'rest_base'           => 'openclawp-workflows',
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
	 * Resolve the active store. Filterable so adopters can swap in a
	 * different `WP_Agent_Workflow_Store` impl without forking openclaWP.
	 */
	public static function instance(): WP_Agent_Workflow_Store {
		static $instance = null;
		if ( null === $instance ) {
			$instance = new self();
			/**
			 * Filters the active workflow store.
			 *
			 * @since 0.2.0
			 *
			 * @param WP_Agent_Workflow_Store $store Default CPT-backed store.
			 */
			$instance = apply_filters( 'openclawp_workflow_store', $instance );
		}
		return $instance;
	}

	public function find( string $workflow_id ): ?WP_Agent_Workflow_Spec {
		$post = self::find_post( $workflow_id );
		if ( null === $post ) {
			return null;
		}
		$decoded = json_decode( $post->post_content, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}
		$spec = WP_Agent_Workflow_Spec::from_array( $decoded );
		return $spec instanceof WP_Agent_Workflow_Spec ? $spec : null;
	}

	public function save( WP_Agent_Workflow_Spec $spec ) {
		$existing = self::find_post( $spec->get_id() );
		$payload  = array(
			'post_type'    => self::POST_TYPE,
			'post_status'  => 'publish',
			'post_title'   => $spec->get_id(),
			'post_content' => wp_slash( (string) wp_json_encode( $spec->to_array() ) ),
		);

		if ( null === $existing ) {
			$post_id = wp_insert_post( $payload, true );
		} else {
			$payload['ID'] = $existing->ID;
			$post_id       = wp_update_post( $payload, true );
		}

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( (int) $post_id, self::META_WORKFLOW_ID, $spec->get_id() );

		return true;
	}

	public function delete( string $workflow_id ) {
		$post = self::find_post( $workflow_id );
		if ( null === $post ) {
			return new WP_Error(
				'not_found',
				sprintf( 'no workflow stored with id `%s`', $workflow_id )
			);
		}
		$result = wp_delete_post( $post->ID, true );
		if ( false === $result || null === $result ) {
			return new WP_Error( 'delete_failed', 'failed to delete workflow post' );
		}
		return true;
	}

	public function all( array $args = array() ): array {
		$query_args = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => isset( $args['limit'] ) ? (int) $args['limit'] : -1,
			'offset'         => isset( $args['offset'] ) ? (int) $args['offset'] : 0,
			'no_found_rows'  => true,
			'fields'         => 'all',
		);

		$posts = get_posts( $query_args );
		$specs = array();
		foreach ( $posts as $post ) {
			$decoded = json_decode( $post->post_content, true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}
			$spec = WP_Agent_Workflow_Spec::from_array( $decoded );
			if ( $spec instanceof WP_Agent_Workflow_Spec ) {
				$specs[] = $spec;
			}
		}
		return $specs;
	}

	private static function find_post( string $workflow_id ): ?\WP_Post {
		if ( '' === $workflow_id ) {
			return null;
		}
		$matches = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'meta_key'       => self::META_WORKFLOW_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $workflow_id,            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return $matches[0] ?? null;
	}
}
