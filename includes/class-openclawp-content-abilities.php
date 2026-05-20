<?php
/**
 * Core WordPress content abilities for agents.
 *
 * These are deliberately small, capability-checked wrappers around posts and
 * pages. Mutating abilities carry `meta.effect` so the existing confirmation
 * gate can pause writes before they run.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Content_Abilities {

	public static function register(): void {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
	}

	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		/**
		 * Whether to register the bundled post/page content abilities.
		 *
		 * @param bool $enabled Default true.
		 */
		if ( ! apply_filters( 'openclawp_register_content_abilities', true ) ) {
			return;
		}

		self::register_list_content();
		self::register_get_content();
		self::register_preview_update();
		self::register_create_content();
		self::register_update_content();
		self::register_delete_content();
		self::register_list_snapshots();
		self::register_restore_snapshot();
	}

	private static function register_list_content(): void {
		self::register_ability(
			'openclawp/list-content',
			array(
				'label'               => __( 'List WordPress content', 'openclawp' ),
				'description'         => __( 'List posts or pages visible to the current user. Use before editing when you need to find a target post ID.', 'openclawp' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_type' => array( 'type' => 'string', 'default' => 'post' ),
						'status'    => array( 'type' => 'string', 'default' => 'publish' ),
						'search'    => array( 'type' => 'string' ),
						'limit'     => array( 'type' => 'integer', 'default' => 10 ),
					),
				),
				'execute_callback'    => static fn ( array $args ): array => self::list_content( $args ),
				'permission_callback' => static fn (): bool => self::current_user_can_read_collection(),
				'meta'                => array( 'effect' => OpenclaWP_Tool_Effects::EFFECT_READ ),
			)
		);
	}

	private static function register_get_content(): void {
		self::register_ability(
			'openclawp/get-content',
			array(
				'label'               => __( 'Get WordPress content', 'openclawp' ),
				'description'         => __( 'Fetch a post or page by ID, including title, content, excerpt, status, permalink, and revision count.', 'openclawp' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'post_id' ),
				),
				'execute_callback'    => static fn ( array $args ) => self::get_content( (int) ( $args['post_id'] ?? 0 ) ),
				'permission_callback' => static fn (): bool => self::current_user_can_read_collection(),
				'meta'                => array( 'effect' => OpenclaWP_Tool_Effects::EFFECT_READ ),
			)
		);
	}

	private static function register_preview_update(): void {
		self::register_ability(
			'openclawp/preview-content-update',
			array(
				'label'               => __( 'Preview content update', 'openclawp' ),
				'description'         => __( 'Preview the diff for a post/page update without writing anything.', 'openclawp' ),
				'input_schema'        => self::update_input_schema(),
				'execute_callback'    => static fn ( array $args ) => self::preview_update( $args ),
				'permission_callback' => static fn (): bool => self::current_user_can_read_collection(),
				'meta'                => array( 'effect' => OpenclaWP_Tool_Effects::EFFECT_READ ),
			)
		);
	}

	private static function register_create_content(): void {
		self::register_ability(
			'openclawp/create-content',
			array(
				'label'               => __( 'Create WordPress content', 'openclawp' ),
				'description'         => __( 'Create a post or page. Defaults to draft; publishing requires the matching publish capability and human confirmation under write gating.', 'openclawp' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_type' => array( 'type' => 'string', 'default' => 'post' ),
						'title'     => array( 'type' => 'string' ),
						'content'   => array( 'type' => 'string', 'default' => '' ),
						'excerpt'   => array( 'type' => 'string', 'default' => '' ),
						'status'    => array( 'type' => 'string', 'default' => 'draft' ),
					),
					'required'   => array( 'title' ),
				),
				'execute_callback'    => static fn ( array $args ) => self::create_content( $args ),
				'permission_callback' => static fn (): bool => function_exists( 'current_user_can' ) && current_user_can( 'edit_posts' ),
				'meta'                => array( 'effect' => OpenclaWP_Tool_Effects::EFFECT_WRITE ),
			)
		);
	}

	private static function register_update_content(): void {
		self::register_ability(
			'openclawp/update-content',
			array(
				'label'               => __( 'Update WordPress content', 'openclawp' ),
				'description'         => __( 'Update title, content, excerpt, or status for an existing post/page. Automatically stores a snapshot and returns a diff.', 'openclawp' ),
				'input_schema'        => self::update_input_schema(),
				'execute_callback'    => static fn ( array $args ) => self::update_content( $args ),
				'permission_callback' => static fn (): bool => function_exists( 'current_user_can' ) && current_user_can( 'edit_posts' ),
				'meta'                => array( 'effect' => OpenclaWP_Tool_Effects::EFFECT_WRITE ),
			)
		);
	}

	private static function register_delete_content(): void {
		self::register_ability(
			'openclawp/delete-content',
			array(
				'label'               => __( 'Delete WordPress content', 'openclawp' ),
				'description'         => __( 'Trash or permanently delete a post/page. Stores a before-image snapshot first.', 'openclawp' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer' ),
						'force'   => array( 'type' => 'boolean', 'default' => false ),
						'reason'  => array( 'type' => 'string', 'default' => '' ),
					),
					'required'   => array( 'post_id' ),
				),
				'execute_callback'    => static fn ( array $args ) => self::delete_content( $args ),
				'permission_callback' => static fn (): bool => function_exists( 'current_user_can' ) && current_user_can( 'delete_posts' ),
				'meta'                => array( 'effect' => OpenclaWP_Tool_Effects::EFFECT_DESTRUCTIVE ),
			)
		);
	}

	private static function register_list_snapshots(): void {
		self::register_ability(
			'openclawp/list-content-snapshots',
			array(
				'label'               => __( 'List content snapshots', 'openclawp' ),
				'description'         => __( 'List before-image snapshots created by openclaWP content mutations.', 'openclawp' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer' ),
						'limit'   => array( 'type' => 'integer', 'default' => 10 ),
					),
				),
				'execute_callback'    => static fn ( array $args ): array => self::list_snapshots( $args ),
				'permission_callback' => static fn (): bool => function_exists( 'current_user_can' ) && current_user_can( 'edit_posts' ),
				'meta'                => array( 'effect' => OpenclaWP_Tool_Effects::EFFECT_READ ),
			)
		);
	}

	private static function register_restore_snapshot(): void {
		self::register_ability(
			'openclawp/restore-content-snapshot',
			array(
				'label'               => __( 'Restore content snapshot', 'openclawp' ),
				'description'         => __( 'Restore a post/page from a stored openclaWP before-image snapshot. Creates another snapshot before restoring.', 'openclawp' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'snapshot_id' => array( 'type' => 'integer' ),
						'reason'      => array( 'type' => 'string', 'default' => '' ),
					),
					'required'   => array( 'snapshot_id' ),
				),
				'execute_callback'    => static fn ( array $args ) => OpenclaWP_Content_Snapshots::restore( (int) ( $args['snapshot_id'] ?? 0 ), (string) ( $args['reason'] ?? '' ) ),
				'permission_callback' => static fn (): bool => function_exists( 'current_user_can' ) && current_user_can( 'edit_posts' ),
				'meta'                => array( 'effect' => OpenclaWP_Tool_Effects::EFFECT_DESTRUCTIVE ),
			)
		);
	}

	private static function register_ability( string $name, array $args ): void {
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) {
			return;
		}
		$args['category']      = $args['category'] ?? 'openclawp';
		$args['output_schema'] = $args['output_schema'] ?? array(
			'type'                 => 'object',
			'additionalProperties' => true,
		);
		wp_register_ability( $name, $args );
	}

	private static function update_input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id' => array( 'type' => 'integer' ),
				'title'   => array( 'type' => 'string' ),
				'content' => array( 'type' => 'string' ),
				'excerpt' => array( 'type' => 'string' ),
				'status'  => array( 'type' => 'string' ),
				'reason'  => array( 'type' => 'string', 'default' => '' ),
			),
			'required'   => array( 'post_id' ),
		);
	}

	public static function list_content( array $args ): array {
		$post_type = self::normalize_post_type( (string) ( $args['post_type'] ?? 'post' ) );
		$status    = sanitize_key( (string) ( $args['status'] ?? 'publish' ) );
		$limit     = max( 1, min( 50, (int) ( $args['limit'] ?? 10 ) ) );
		$search    = isset( $args['search'] ) ? sanitize_text_field( (string) $args['search'] ) : '';

		if ( '' === $post_type ) {
			return array( 'items' => array() );
		}

		if ( ! in_array( $status, array( 'publish', 'draft', 'pending', 'private', 'future', 'any' ), true ) ) {
			$status = 'publish';
		}

		$query = array(
			'post_type'      => $post_type,
			'post_status'    => $status,
			'posts_per_page' => $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		);
		if ( '' !== $search ) {
			$query['s'] = $search;
		}

		$posts = get_posts( $query );
		$items = array();
		foreach ( $posts as $post ) {
			if ( ! self::can_read_post( $post ) ) {
				continue;
			}
			$items[] = self::summarize_post( $post );
		}

		return array( 'items' => $items );
	}

	public static function get_content( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! self::is_allowed_post_type( (string) $post->post_type ) ) {
			return new WP_Error(
				'openclawp_content_not_found',
				__( 'The post was not found.', 'openclawp' ),
				array( 'status' => 404 )
			);
		}
		if ( ! self::can_read_post( $post ) ) {
			return new WP_Error(
				'openclawp_content_forbidden',
				__( 'You are not allowed to read this post.', 'openclawp' ),
				array( 'status' => 403 )
			);
		}

		$revision_count = 0;
		if ( function_exists( 'wp_get_post_revisions' ) ) {
			$revision_count = count( wp_get_post_revisions( $post->ID ) );
		}

		return array_merge(
			self::summarize_post( $post ),
			array(
				'content'        => (string) $post->post_content,
				'revision_count' => $revision_count,
			)
		);
	}

	public static function preview_update( array $args ) {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		if ( ! self::can_edit_post_id( $post_id ) ) {
			return new WP_Error(
				'openclawp_content_forbidden',
				__( 'You are not allowed to edit this post.', 'openclawp' ),
				array( 'status' => 403 )
			);
		}
		return OpenclaWP_Content_Snapshots::preview_update( $post_id, $args );
	}

	public static function create_content( array $args ) {
		$post_type = self::normalize_post_type( (string) ( $args['post_type'] ?? 'post' ) );
		if ( '' === $post_type ) {
			return new WP_Error(
				'openclawp_content_invalid_post_type',
				__( 'Unsupported post type.', 'openclawp' ),
				array( 'status' => 400 )
			);
		}

		$status = OpenclaWP_Content_Snapshots::normalize_status( (string) ( $args['status'] ?? 'draft' ) );
		if ( '' === $status ) {
			$status = 'draft';
		}
		if ( ! self::can_create_post_type( $post_type, $status ) ) {
			return new WP_Error(
				'openclawp_content_forbidden',
				__( 'You are not allowed to create this content.', 'openclawp' ),
				array( 'status' => 403 )
			);
		}

		$title = trim( (string) ( $args['title'] ?? '' ) );
		if ( '' === $title ) {
			return new WP_Error(
				'openclawp_content_title_required',
				__( 'A title is required.', 'openclawp' ),
				array( 'status' => 400 )
			);
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => $post_type,
				'post_status'  => $status,
				'post_title'   => $title,
				'post_content' => (string) ( $args['content'] ?? '' ),
				'post_excerpt' => (string) ( $args['excerpt'] ?? '' ),
				'post_author'  => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		return array( 'post' => self::get_content( (int) $post_id ) );
	}

	public static function update_content( array $args ) {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		if ( ! self::can_edit_post_id( $post_id ) ) {
			return new WP_Error(
				'openclawp_content_forbidden',
				__( 'You are not allowed to edit this post.', 'openclawp' ),
				array( 'status' => 403 )
			);
		}

		$preview = OpenclaWP_Content_Snapshots::preview_update( $post_id, $args );
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}

		$changes = OpenclaWP_Content_Snapshots::normalize_changes( $args );
		if ( isset( $changes['post_status'] ) && ! self::can_publish_status_for_post( $post_id, $changes['post_status'] ) ) {
			return new WP_Error(
				'openclawp_content_publish_forbidden',
				__( 'You are not allowed to set that post status.', 'openclawp' ),
				array( 'status' => 403 )
			);
		}

		$snapshot = OpenclaWP_Content_Snapshots::create_for_post(
			$post_id,
			'update',
			array(
				'reason' => (string) ( $args['reason'] ?? '' ),
				'after'  => $changes,
			)
		);
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}

		$result = wp_update_post( array_merge( array( 'ID' => $post_id ), $changes ), true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'post'     => self::get_content( $post_id ),
			'snapshot' => $snapshot,
			'diff'     => array(
				'changed_fields' => $preview['changed_fields'],
				'unified_diff'   => $preview['unified_diff'],
			),
		);
	}

	public static function delete_content( array $args ) {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		if ( ! self::can_delete_post_id( $post_id ) ) {
			return new WP_Error(
				'openclawp_content_forbidden',
				__( 'You are not allowed to delete this post.', 'openclawp' ),
				array( 'status' => 403 )
			);
		}

		$force    = ! empty( $args['force'] );
		$snapshot = OpenclaWP_Content_Snapshots::create_for_post(
			$post_id,
			$force ? 'delete' : 'trash',
			array( 'reason' => (string) ( $args['reason'] ?? '' ) )
		);
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}

		$result = $force
			? wp_delete_post( $post_id, true )
			: ( function_exists( 'wp_trash_post' ) ? wp_trash_post( $post_id ) : wp_delete_post( $post_id, false ) );

		if ( false === $result || null === $result ) {
			return new WP_Error(
				'openclawp_content_delete_failed',
				__( 'WordPress could not delete the post.', 'openclawp' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'post_id'  => $post_id,
			'force'    => $force,
			'snapshot' => $snapshot,
		);
	}

	public static function list_snapshots( array $args ): array {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		if ( $post_id > 0 && ! self::can_edit_post_id( $post_id ) ) {
			return array( 'snapshots' => array() );
		}

		$query = array(
			'post_type'      => OpenclaWP_Content_Snapshots::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => max( 1, min( 50, (int) ( $args['limit'] ?? 10 ) ) ),
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		);
		if ( $post_id > 0 ) {
			$query['meta_key']   = OpenclaWP_Content_Snapshots::META_SOURCE_POST_ID;
			$query['meta_value'] = $post_id;
		}

		$snapshots = array();
		foreach ( get_posts( $query ) as $post ) {
			$hydrated = OpenclaWP_Content_Snapshots::hydrate_snapshot( (int) $post->ID );
			if ( ! is_wp_error( $hydrated ) ) {
				$snapshots[] = $hydrated;
			}
		}

		return array( 'snapshots' => $snapshots );
	}

	/**
	 * @return array<int,string>
	 */
	public static function allowed_post_types(): array {
		$default = array( 'post', 'page' );
		$types   = (array) apply_filters( 'openclawp_content_post_types', $default );
		$out     = array();
		foreach ( $types as $type ) {
			$key = sanitize_key( (string) $type );
			if ( '' !== $key ) {
				$out[] = $key;
			}
		}
		return array_values( array_unique( $out ) );
	}

	private static function normalize_post_type( string $post_type ): string {
		$post_type = sanitize_key( $post_type );
		return self::is_allowed_post_type( $post_type ) ? $post_type : '';
	}

	private static function is_allowed_post_type( string $post_type ): bool {
		return in_array( sanitize_key( $post_type ), self::allowed_post_types(), true );
	}

	private static function current_user_can_read_collection(): bool {
		if ( ! function_exists( 'current_user_can' ) ) {
			return false;
		}
		return (bool) apply_filters( 'openclawp_content_read_permission', current_user_can( 'edit_posts' ) );
	}

	private static function can_read_post( \WP_Post $post ): bool {
		if ( ! self::is_allowed_post_type( (string) $post->post_type ) ) {
			return false;
		}
		if ( 'publish' === (string) $post->post_status ) {
			return function_exists( 'current_user_can' ) && current_user_can( 'read' );
		}
		return function_exists( 'current_user_can' ) && current_user_can( 'edit_post', (int) $post->ID );
	}

	private static function can_edit_post_id( int $post_id ): bool {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! self::is_allowed_post_type( (string) $post->post_type ) ) {
			return false;
		}
		return function_exists( 'current_user_can' ) && current_user_can( 'edit_post', $post_id );
	}

	private static function can_delete_post_id( int $post_id ): bool {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! self::is_allowed_post_type( (string) $post->post_type ) ) {
			return false;
		}
		return function_exists( 'current_user_can' ) && current_user_can( 'delete_post', $post_id );
	}

	private static function can_create_post_type( string $post_type, string $status ): bool {
		$cap = 'page' === $post_type ? 'edit_pages' : 'edit_posts';
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( $cap ) ) {
			return false;
		}
		if ( in_array( $status, array( 'publish', 'private', 'future' ), true ) ) {
			return current_user_can( 'page' === $post_type ? 'publish_pages' : 'publish_posts' );
		}
		return true;
	}

	private static function can_publish_status_for_post( int $post_id, string $status ): bool {
		if ( ! in_array( $status, array( 'publish', 'private', 'future' ), true ) ) {
			return true;
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}
		return function_exists( 'current_user_can' ) && current_user_can( 'page' === (string) $post->post_type ? 'publish_pages' : 'publish_posts' );
	}

	private static function summarize_post( \WP_Post $post ): array {
		$excerpt = (string) $post->post_excerpt;
		if ( '' === $excerpt ) {
			$excerpt = self::plain_text_excerpt( (string) $post->post_content );
		}

		return array(
			'post_id'      => (int) $post->ID,
			'post_type'    => (string) $post->post_type,
			'status'       => (string) $post->post_status,
			'title'        => (string) $post->post_title,
			'excerpt'      => $excerpt,
			'permalink'    => function_exists( 'get_permalink' ) ? (string) get_permalink( $post ) : '',
			'modified_gmt' => (string) ( $post->post_modified_gmt ?? '' ),
		);
	}

	private static function plain_text_excerpt( string $content ): string {
		$text = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $content ) : strip_tags( $content );
		$text = trim( preg_replace( '/\s+/', ' ', $text ) ?? '' );
		return strlen( $text ) > 240 ? substr( $text, 0, 237 ) . '...' : $text;
	}
}
