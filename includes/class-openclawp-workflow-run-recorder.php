<?php
/**
 * CPT-backed workflow run recorder.
 *
 * Implements `WP_Agent_Workflow_Run_Recorder` against an `openclawp_workflow_run`
 * CPT — one post per run, status / step records / error / metadata stored in
 * post-meta. Runs are durable across the request that started them so the
 * upcoming admin UI can render a recent-runs list, retry a failed run, or
 * deep-link into the per-step trace.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Run_Recorder;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Run_Result;

final class OpenclaWP_Workflow_Run_Recorder implements WP_Agent_Workflow_Run_Recorder {

	public const POST_TYPE = 'openclawp_workflow_run';

	private const META_RUN_ID      = '_openclawp_run_id';
	private const META_WORKFLOW_ID = '_openclawp_workflow_id';
	private const META_STATUS      = '_openclawp_status';
	private const META_INPUTS      = '_openclawp_inputs';
	private const META_OUTPUT      = '_openclawp_output';
	private const META_STEPS       = '_openclawp_steps';
	private const META_ERROR       = '_openclawp_error';
	private const META_STARTED_AT  = '_openclawp_started_at';
	private const META_ENDED_AT    = '_openclawp_ended_at';
	private const META_METADATA    = '_openclawp_metadata';

	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'openclaWP Workflow Runs', 'openclawp' ),
					'singular_name' => __( 'openclaWP Workflow Run', 'openclawp' ),
				),
				'public'              => false,
				'show_ui'             => false,
				'show_in_rest'        => true,
				'rest_base'           => 'openclawp-workflow-runs',
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
	 * Resolve the active recorder. Filterable so adopters can swap in
	 * a different `WP_Agent_Workflow_Run_Recorder` impl.
	 */
	public static function instance(): WP_Agent_Workflow_Run_Recorder {
		static $instance = null;
		if ( null === $instance ) {
			$instance = new self();
			/**
			 * Filters the active workflow run recorder.
			 *
			 * @since 0.2.0
			 *
			 * @param WP_Agent_Workflow_Run_Recorder $recorder Default CPT-backed recorder.
			 */
			$instance = apply_filters( 'openclawp_workflow_run_recorder', $instance );
		}
		return $instance;
	}

	public function start( WP_Agent_Workflow_Run_Result $result ) {
		$post_id = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => sprintf( '%s — %s', $result->get_workflow_id(), $result->get_run_id() ),
				'post_author'  => get_current_user_id(),
				'post_content' => '',
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$this->persist_meta( (int) $post_id, $result );
		return $result->get_run_id();
	}

	public function update( WP_Agent_Workflow_Run_Result $result ) {
		$post = self::find_post( $result->get_run_id() );
		if ( null === $post ) {
			// `update` may be called before `start` lands in a weird timing
			// window — re-create rather than fail silently.
			return $this->start( $result ) instanceof WP_Error
				? new WP_Error( 'recreate_failed', 'could not recreate run record on update' )
				: true;
		}
		$this->persist_meta( $post->ID, $result );
		return true;
	}

	public function find( string $run_id ): ?WP_Agent_Workflow_Run_Result {
		$post = self::find_post( $run_id );
		if ( null === $post ) {
			return null;
		}
		return self::hydrate( $post );
	}

	public function recent( array $args = array() ): array {
		$query = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => isset( $args['limit'] ) ? (int) $args['limit'] : 20,
			'offset'         => isset( $args['offset'] ) ? (int) $args['offset'] : 0,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		);

		if ( ! empty( $args['workflow_id'] ) ) {
			// phpcs:disable WordPress.DB.SlowDBQuery
			$query['meta_key']   = self::META_WORKFLOW_ID;
			$query['meta_value'] = (string) $args['workflow_id'];
			// phpcs:enable
		}

		$posts   = get_posts( $query );
		$results = array();
		foreach ( $posts as $post ) {
			$result = self::hydrate( $post );
			if ( null !== $result ) {
				$results[] = $result;
			}
		}
		return $results;
	}

	private function persist_meta( int $post_id, WP_Agent_Workflow_Run_Result $result ): void {
		update_post_meta( $post_id, self::META_RUN_ID, $result->get_run_id() );
		update_post_meta( $post_id, self::META_WORKFLOW_ID, $result->get_workflow_id() );
		update_post_meta( $post_id, self::META_STATUS, $result->get_status() );
		update_post_meta( $post_id, self::META_INPUTS, wp_json_encode( $result->get_inputs() ) );
		update_post_meta( $post_id, self::META_OUTPUT, wp_json_encode( $result->get_output() ) );
		update_post_meta( $post_id, self::META_STEPS, wp_json_encode( $result->get_steps() ) );
		update_post_meta( $post_id, self::META_ERROR, wp_json_encode( $result->get_error() ) );
		update_post_meta( $post_id, self::META_STARTED_AT, $result->get_started_at() );
		update_post_meta( $post_id, self::META_ENDED_AT, $result->get_ended_at() );
		update_post_meta( $post_id, self::META_METADATA, wp_json_encode( $result->get_metadata() ) );
	}

	private static function hydrate( \WP_Post $post ): ?WP_Agent_Workflow_Run_Result {
		$run_id = (string) get_post_meta( $post->ID, self::META_RUN_ID, true );
		if ( '' === $run_id ) {
			return null;
		}
		return new WP_Agent_Workflow_Run_Result(
			$run_id,
			(string) get_post_meta( $post->ID, self::META_WORKFLOW_ID, true ),
			(string) ( get_post_meta( $post->ID, self::META_STATUS, true ) ?: WP_Agent_Workflow_Run_Result::STATUS_PENDING ),
			self::decode_meta( $post->ID, self::META_INPUTS ),
			self::decode_meta( $post->ID, self::META_OUTPUT ),
			self::decode_meta( $post->ID, self::META_STEPS ),
			self::decode_meta( $post->ID, self::META_ERROR ),
			(int) get_post_meta( $post->ID, self::META_STARTED_AT, true ),
			(int) get_post_meta( $post->ID, self::META_ENDED_AT, true ),
			self::decode_meta( $post->ID, self::META_METADATA )
		);
	}

	private static function decode_meta( int $post_id, string $key ): array {
		$raw = (string) get_post_meta( $post_id, $key, true );
		if ( '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	private static function find_post( string $run_id ): ?\WP_Post {
		if ( '' === $run_id ) {
			return null;
		}
		$matches = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'meta_key'       => self::META_RUN_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $run_id,            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return $matches[0] ?? null;
	}
}
