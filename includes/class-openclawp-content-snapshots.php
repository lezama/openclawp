<?php
/**
 * Content snapshots for agent-side writes.
 *
 * Stores a before-image for post/page mutations so write tools can show a
 * diff and restore a prior state without depending on a provider-specific
 * memory layer.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Content_Snapshots {

	public const POST_TYPE = 'openclawp_snapshot';

	public const META_SOURCE_POST_ID = '_openclawp_source_post_id';
	public const META_OPERATION      = '_openclawp_snapshot_operation';
	public const META_BEFORE_HASH    = '_openclawp_before_hash';
	public const META_AFTER_HASH     = '_openclawp_after_hash';

	private const MAX_DIFF_CHARS = 12000;

	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'openclaWP Content Snapshots', 'openclawp' ),
					'singular_name' => __( 'openclaWP Content Snapshot', 'openclawp' ),
				),
				'public'              => false,
				'show_ui'             => false,
				'show_in_rest'        => true,
				'rest_base'           => 'openclawp-content-snapshots',
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
	 * Create a before-image for a post mutation.
	 *
	 * @param int    $post_id   Source post ID.
	 * @param string $operation Human-readable operation token.
	 * @param array  $context   Optional: reason, after, created_by.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function create_for_post( int $post_id, string $operation, array $context = array() ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return new WP_Error(
				'openclawp_snapshot_post_not_found',
				__( 'The post to snapshot was not found.', 'openclawp' ),
				array( 'status' => 404 )
			);
		}

		$before = self::payload_from_post( $post );
		$after  = isset( $context['after'] ) && is_array( $context['after'] )
			? array_merge( $before, $context['after'] )
			: null;

		$payload = array(
			'version'    => 1,
			'operation'  => sanitize_key( $operation ),
			'reason'     => isset( $context['reason'] ) ? sanitize_text_field( (string) $context['reason'] ) : '',
			'created_by' => isset( $context['created_by'] ) ? (int) $context['created_by'] : ( function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0 ),
			'source'     => array(
				'post_id'   => (int) $post->ID,
				'post_type' => (string) $post->post_type,
			),
			'before'     => $before,
			'after'      => $after,
			'diff'       => null === $after ? '' : self::diff_payload( $before, $after )['unified'],
		);

		$json = (string) wp_json_encode( $payload );
		if ( function_exists( 'wp_slash' ) ) {
			$json = wp_slash( $json );
		}

		$title = sprintf(
			'%s #%d before %s',
			(string) $post->post_type,
			(int) $post->ID,
			sanitize_key( $operation )
		);

		$snapshot_id = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $json,
				'post_author'  => (int) $payload['created_by'],
			),
			true
		);
		if ( is_wp_error( $snapshot_id ) ) {
			return $snapshot_id;
		}

		update_post_meta( (int) $snapshot_id, self::META_SOURCE_POST_ID, (int) $post->ID );
		update_post_meta( (int) $snapshot_id, self::META_OPERATION, sanitize_key( $operation ) );
		update_post_meta( (int) $snapshot_id, self::META_BEFORE_HASH, self::payload_hash( $before ) );
		if ( null !== $after ) {
			update_post_meta( (int) $snapshot_id, self::META_AFTER_HASH, self::payload_hash( $after ) );
		}

		return self::hydrate_snapshot( (int) $snapshot_id );
	}

	/**
	 * Preview a post update without mutating WordPress.
	 *
	 * @param int   $post_id Source post ID.
	 * @param array $changes Canonical post field changes.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function preview_update( int $post_id, array $changes ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return new WP_Error(
				'openclawp_content_not_found',
				__( 'The post was not found.', 'openclawp' ),
				array( 'status' => 404 )
			);
		}

		$normalized = self::normalize_changes( $changes );
		if ( empty( $normalized ) ) {
			return new WP_Error(
				'openclawp_content_no_changes',
				__( 'No supported fields were provided.', 'openclawp' ),
				array( 'status' => 400 )
			);
		}

		$before = self::payload_from_post( $post );
		$after  = array_merge( $before, $normalized );
		$diff   = self::diff_payload( $before, $after );

		return array(
			'post_id'        => (int) $post->ID,
			'before_hash'    => self::payload_hash( $before ),
			'after_hash'     => self::payload_hash( $after ),
			'changed_fields' => $diff['changed_fields'],
			'unified_diff'   => $diff['unified'],
			'before'         => $before,
			'after'          => $after,
		);
	}

	/**
	 * Restore the source post from a stored snapshot.
	 *
	 * @param int    $snapshot_id Snapshot post ID.
	 * @param string $reason      Optional audit note.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function restore( int $snapshot_id, string $reason = '' ) {
		$snapshot = self::read_payload( $snapshot_id );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}

		$before  = isset( $snapshot['before'] ) && is_array( $snapshot['before'] ) ? $snapshot['before'] : array();
		$post_id = (int) ( $before['ID'] ?? $snapshot['source']['post_id'] ?? 0 );
		if ( $post_id <= 0 ) {
			return new WP_Error(
				'openclawp_snapshot_missing_source',
				__( 'The snapshot does not include a source post ID.', 'openclawp' ),
				array( 'status' => 400 )
			);
		}

		$current = get_post( $post_id );
		if ( ! $current instanceof \WP_Post ) {
			return new WP_Error(
				'openclawp_snapshot_source_missing',
				__( 'The source post no longer exists.', 'openclawp' ),
				array( 'status' => 404 )
			);
		}
		if ( function_exists( 'current_user_can' ) && ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'openclawp_snapshot_forbidden',
				__( 'You are not allowed to restore this post.', 'openclawp' ),
				array( 'status' => 403 )
			);
		}

		$restore_before = self::create_for_post(
			$post_id,
			'restore-before',
			array(
				'reason' => $reason,
				'after'  => $before,
			)
		);
		if ( is_wp_error( $restore_before ) ) {
			return $restore_before;
		}

		$postarr = self::payload_to_postarr( $before );
		$postarr['ID'] = $post_id;

		$result = wp_update_post( $postarr, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'post_id'                  => $post_id,
			'restored_from_snapshot'   => $snapshot_id,
			'pre_restore_snapshot_id'  => (int) ( $restore_before['snapshot_id'] ?? 0 ),
			'restored_before_hash'     => self::payload_hash( $before ),
		);
	}

	/**
	 * Hydrate a snapshot post into a compact response.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public static function hydrate_snapshot( int $snapshot_id ) {
		$payload = self::read_payload( $snapshot_id );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$before = isset( $payload['before'] ) && is_array( $payload['before'] ) ? $payload['before'] : array();
		$after  = isset( $payload['after'] ) && is_array( $payload['after'] ) ? $payload['after'] : null;
		$diff   = null === $after ? array( 'changed_fields' => array(), 'unified' => '' ) : self::diff_payload( $before, $after );

		return array(
			'snapshot_id'    => $snapshot_id,
			'source_post_id' => (int) ( $before['ID'] ?? $payload['source']['post_id'] ?? 0 ),
			'post_type'      => (string) ( $before['post_type'] ?? $payload['source']['post_type'] ?? '' ),
			'operation'      => (string) ( $payload['operation'] ?? '' ),
			'reason'         => (string) ( $payload['reason'] ?? '' ),
			'before_hash'    => self::payload_hash( $before ),
			'after_hash'     => null === $after ? '' : self::payload_hash( $after ),
			'changed_fields' => $diff['changed_fields'],
			'unified_diff'   => $diff['unified'],
		);
	}

	/**
	 * Decode the JSON payload stored in a snapshot post.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public static function read_payload( int $snapshot_id ) {
		$post = get_post( $snapshot_id );
		if ( ! $post instanceof \WP_Post || self::POST_TYPE !== (string) $post->post_type ) {
			return new WP_Error(
				'openclawp_snapshot_not_found',
				__( 'The snapshot was not found.', 'openclawp' ),
				array( 'status' => 404 )
			);
		}

		$payload = json_decode( (string) $post->post_content, true );
		if ( ! is_array( $payload ) ) {
			return new WP_Error(
				'openclawp_snapshot_invalid',
				__( 'The snapshot payload is invalid.', 'openclawp' ),
				array( 'status' => 500 )
			);
		}

		return $payload;
	}

	/**
	 * Convert aliases from ability input into canonical post fields.
	 *
	 * @param array $changes Raw changes.
	 * @return array<string,string>
	 */
	public static function normalize_changes( array $changes ): array {
		$aliases = array(
			'title'   => 'post_title',
			'content' => 'post_content',
			'excerpt' => 'post_excerpt',
			'status'  => 'post_status',
		);

		foreach ( $aliases as $alias => $field ) {
			if ( array_key_exists( $alias, $changes ) && ! array_key_exists( $field, $changes ) ) {
				$changes[ $field ] = $changes[ $alias ];
			}
		}

		$out = array();
		foreach ( array( 'post_title', 'post_content', 'post_excerpt', 'post_status' ) as $field ) {
			if ( ! array_key_exists( $field, $changes ) ) {
				continue;
			}
			$value = is_scalar( $changes[ $field ] ) ? (string) $changes[ $field ] : '';
			if ( 'post_status' === $field ) {
				$value = self::normalize_status( $value );
				if ( '' === $value ) {
					continue;
				}
			}
			$out[ $field ] = $value;
		}

		return $out;
	}

	public static function normalize_status( string $status ): string {
		$status = sanitize_key( $status );
		return in_array( $status, array( 'draft', 'pending', 'publish', 'private', 'future' ), true ) ? $status : '';
	}

	/**
	 * Build a canonical payload from a WP_Post-like object.
	 *
	 * @param object $post WP_Post or compatible test double.
	 * @return array<string,mixed>
	 */
	public static function payload_from_post( object $post ): array {
		return array(
			'ID'             => (int) ( $post->ID ?? 0 ),
			'post_type'      => (string) ( $post->post_type ?? 'post' ),
			'post_status'    => (string) ( $post->post_status ?? 'draft' ),
			'post_title'     => (string) ( $post->post_title ?? '' ),
			'post_name'      => (string) ( $post->post_name ?? '' ),
			'post_content'   => (string) ( $post->post_content ?? '' ),
			'post_excerpt'   => (string) ( $post->post_excerpt ?? '' ),
			'comment_status' => (string) ( $post->comment_status ?? 'open' ),
			'ping_status'    => (string) ( $post->ping_status ?? 'open' ),
			'post_parent'    => (int) ( $post->post_parent ?? 0 ),
			'menu_order'     => (int) ( $post->menu_order ?? 0 ),
		);
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public static function payload_to_postarr( array $payload ): array {
		$out = array();
		foreach ( array( 'post_type', 'post_status', 'post_title', 'post_name', 'post_content', 'post_excerpt', 'comment_status', 'ping_status', 'post_parent', 'menu_order' ) as $field ) {
			if ( array_key_exists( $field, $payload ) ) {
				$out[ $field ] = $payload[ $field ];
			}
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	public static function payload_hash( array $payload ): string {
		$stable = self::payload_to_postarr( $payload );
		ksort( $stable );
		return hash( 'sha256', (string) wp_json_encode( $stable ) );
	}

	/**
	 * @param array<string,mixed> $before
	 * @param array<string,mixed> $after
	 * @return array{changed_fields:array<int,string>,unified:string}
	 */
	public static function diff_payload( array $before, array $after ): array {
		$fields  = array( 'post_title', 'post_status', 'post_excerpt', 'post_content' );
		$changed = array();
		$chunks  = array();

		foreach ( $fields as $field ) {
			$old = (string) ( $before[ $field ] ?? '' );
			$new = (string) ( $after[ $field ] ?? '' );
			if ( $old === $new ) {
				continue;
			}
			$changed[] = $field;
			$chunks[]  = '@@ ' . $field . ' @@';
			$chunks[]  = self::diff_text( $old, $new );
		}

		$unified = trim( implode( "\n", array_filter( $chunks, static fn ( $line ) => '' !== $line ) ) );
		if ( strlen( $unified ) > self::MAX_DIFF_CHARS ) {
			$unified = substr( $unified, 0, self::MAX_DIFF_CHARS ) . "\n...diff truncated...";
		}

		return array(
			'changed_fields' => $changed,
			'unified'        => $unified,
		);
	}

	public static function diff_text( string $before, string $after ): string {
		if ( $before === $after ) {
			return '';
		}

		$before_lines = preg_split( '/\R/', $before );
		$after_lines  = preg_split( '/\R/', $after );
		$before_lines = is_array( $before_lines ) ? $before_lines : array( $before );
		$after_lines  = is_array( $after_lines ) ? $after_lines : array( $after );

		if ( 1 === count( $before_lines ) && 1 === count( $after_lines ) ) {
			return '- ' . $before_lines[0] . "\n+ " . $after_lines[0];
		}

		$rows  = array( '--- before', '+++ after' );
		$count = max( count( $before_lines ), count( $after_lines ) );
		for ( $i = 0; $i < $count; $i++ ) {
			$old = $before_lines[ $i ] ?? null;
			$new = $after_lines[ $i ] ?? null;
			if ( $old === $new ) {
				$rows[] = '  ' . (string) $old;
				continue;
			}
			if ( null !== $old ) {
				$rows[] = '- ' . $old;
			}
			if ( null !== $new ) {
				$rows[] = '+ ' . $new;
			}
		}

		return implode( "\n", $rows );
	}
}
