<?php
/**
 * CPT-backed conversation store + lock primitive.
 *
 * Implements `WP_Agent_Conversation_Store` and `WP_Agent_Conversation_Lock`
 * against `wp_posts` + `wp_postmeta`. No new tables.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

use AgentsAPI\Core\Database\Chat\WP_Agent_Conversation_Lock;
use AgentsAPI\Core\Database\Chat\WP_Agent_Conversation_Store;
use AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope;

final class OpenclaWP_Conversation_Store implements WP_Agent_Conversation_Store, WP_Agent_Conversation_Lock {

	public const POST_TYPE = 'openclawp_session';

	private const META_SESSION_ID           = '_openclawp_session_id';
	private const META_WORKSPACE_TYPE       = '_openclawp_workspace_type';
	private const META_WORKSPACE_ID         = '_openclawp_workspace_id';
	private const META_AGENT_ID             = '_openclawp_agent_id';
	private const META_METADATA             = '_openclawp_metadata';
	private const META_PROVIDER             = '_openclawp_provider';
	private const META_MODEL                = '_openclawp_model';
	private const META_PROVIDER_RESPONSE_ID = '_openclawp_provider_response_id';
	private const META_CONTEXT              = '_openclawp_context';
	private const META_LAST_READ_AT         = '_openclawp_last_read_at';
	private const META_EXPIRES_AT           = '_openclawp_expires_at';
	private const META_LOCK                 = '_openclawp_lock';
	private const META_TOKEN_ID             = '_openclawp_token_id';

	public static function register_post_type(): void {
		// Sessions are first-class WordPress entities: queryable via WP_Query and
		// the standard REST API, themeable, exportable, and ownable by the user
		// who created them. They are not surfaced in wp-admin lists by default
		// (show_ui = false) — openclaWP renders its own session views — and they
		// have no front-end permalinks (public = false) because they are
		// per-user data, not site content. The intent matches how `wp_block`
		// (reusable blocks) is registered: useful as a real CPT, invisible in
		// the global menu, fully exposed to programmatic queries.
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'openclaWP Sessions', 'openclawp' ),
					'singular_name' => __( 'openclaWP Session', 'openclawp' ),
				),
				'public'              => false,
				'show_ui'             => false,
				'show_in_rest'        => true,
				'rest_base'           => 'openclawp-sessions',
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
	 * Resolve the active store. Filterable so adopters can swap implementations.
	 */
	public static function instance(): WP_Agent_Conversation_Store {
		static $instance = null;
		if ( null === $instance ) {
			$instance = new self();
			/**
			 * Filters the active conversation store.
			 *
			 * @param WP_Agent_Conversation_Store $store Default store.
			 */
			$instance = apply_filters( 'openclawp_conversation_store', $instance );
		}
		return $instance;
	}

	/* --------------------------- Store contract --------------------------- */

	public function create_session(
		WP_Agent_Workspace_Scope $workspace,
		int $user_id,
		int $agent_id = 0,
		array $metadata = array(),
		string $context = 'chat'
	): string {
		$session_id = self::uuid4();

		$post_id = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'publish',
				'post_author'  => $user_id,
				'post_title'   => '',
				'post_content' => wp_json_encode( array() ),
			),
			true
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return '';
		}

		update_post_meta( $post_id, self::META_SESSION_ID, $session_id );
		update_post_meta( $post_id, self::META_WORKSPACE_TYPE, $workspace->workspace_type );
		update_post_meta( $post_id, self::META_WORKSPACE_ID, $workspace->workspace_id );
		update_post_meta( $post_id, self::META_AGENT_ID, $agent_id );
		update_post_meta( $post_id, self::META_METADATA, wp_json_encode( $metadata ) );
		update_post_meta( $post_id, self::META_CONTEXT, $context );

		if ( isset( $metadata['token_id'] ) ) {
			update_post_meta( $post_id, self::META_TOKEN_ID, (int) $metadata['token_id'] );
		}

		return $session_id;
	}

	public function get_session( string $session_id ): ?array {
		$post = $this->find_post_by_session_id( $session_id );
		if ( null === $post ) {
			return null;
		}
		return $this->session_array( $post );
	}

	public function update_session(
		string $session_id,
		array $messages,
		array $metadata = array(),
		string $provider = '',
		string $model = '',
		?string $provider_response_id = null
	): bool {
		$post = $this->find_post_by_session_id( $session_id );
		if ( null === $post ) {
			return false;
		}

		$updated = wp_update_post(
			array(
				'ID'           => $post->ID,
				'post_content' => wp_json_encode( array_values( $messages ) ),
			),
			true
		);

		if ( is_wp_error( $updated ) || ! $updated ) {
			return false;
		}

		update_post_meta( $post->ID, self::META_METADATA, wp_json_encode( $metadata ) );
		if ( '' !== $provider ) {
			update_post_meta( $post->ID, self::META_PROVIDER, $provider );
		}
		if ( '' !== $model ) {
			update_post_meta( $post->ID, self::META_MODEL, $model );
		}
		if ( null !== $provider_response_id ) {
			update_post_meta( $post->ID, self::META_PROVIDER_RESPONSE_ID, $provider_response_id );
		}

		return true;
	}

	public function delete_session( string $session_id ): bool {
		$post = $this->find_post_by_session_id( $session_id );
		if ( null === $post ) {
			return true;
		}
		$result = wp_delete_post( $post->ID, true );
		return false !== $result && null !== $result;
	}

	public function get_recent_pending_session(
		WP_Agent_Workspace_Scope $workspace,
		int $user_id,
		int $seconds = 600,
		string $context = 'chat',
		?int $token_id = null
	): ?array {
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 1, $seconds ) );

		$meta_query = array(
			'relation' => 'AND',
			array(
				'key'   => self::META_WORKSPACE_TYPE,
				'value' => $workspace->workspace_type,
			),
			array(
				'key'   => self::META_WORKSPACE_ID,
				'value' => $workspace->workspace_id,
			),
			array(
				'key'   => self::META_CONTEXT,
				'value' => $context,
			),
		);

		if ( null !== $token_id ) {
			$meta_query[] = array(
				'key'   => self::META_TOKEN_ID,
				'value' => $token_id,
				'type'  => 'NUMERIC',
			);
		}

		$query = new WP_Query(
			array(
				'post_type'              => self::POST_TYPE,
				'post_status'            => 'any',
				'author'                 => $user_id,
				'posts_per_page'         => 1,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'date_query'             => array(
					array(
						'after'     => $cutoff,
						'inclusive' => true,
						'column'    => 'post_date_gmt',
					),
				),
				'meta_query'             => $meta_query,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'suppress_filters'       => true,
				'fields'                 => 'all',
			)
		);

		if ( empty( $query->posts ) ) {
			return null;
		}

		$post     = $query->posts[0];
		$messages = $this->decode_messages( $post->post_content );

		// "Pending" = empty transcript or actively-locked session.
		if ( empty( $messages ) || $this->lock_active( $post->ID ) ) {
			return $this->session_array( $post );
		}

		return null;
	}

	public function update_title( string $session_id, string $title ): bool {
		$post = $this->find_post_by_session_id( $session_id );
		if ( null === $post ) {
			return false;
		}

		$updated = wp_update_post(
			array(
				'ID'         => $post->ID,
				'post_title' => $title,
			),
			true
		);

		return ! is_wp_error( $updated ) && (bool) $updated;
	}

	/* ---------------------------- Lock contract --------------------------- */

	public function acquire_session_lock( string $session_id, int $ttl_seconds = 300 ): ?string {
		global $wpdb;

		$post = $this->find_post_by_session_id( $session_id );
		if ( null === $post ) {
			return null;
		}

		$token   = self::uuid4();
		$expires = time() + max( 1, $ttl_seconds );
		$value   = wp_json_encode(
			array(
				'token'   => $token,
				'expires' => $expires,
			)
		);

		// Fast path: no lock present. add_post_meta with $unique=true is atomic.
		$added = add_post_meta( $post->ID, self::META_LOCK, $value, true );
		if ( $added ) {
			return $token;
		}

		// Slow path: a lock row exists. Read it; if not yet expired, lose.
		$existing_raw = get_post_meta( $post->ID, self::META_LOCK, true );
		if ( ! is_string( $existing_raw ) || '' === $existing_raw ) {
			// Race: meta disappeared between calls. Retry once.
			$retry = add_post_meta( $post->ID, self::META_LOCK, $value, true );
			return $retry ? $token : null;
		}

		$existing = json_decode( $existing_raw, true );
		if ( ! is_array( $existing ) || (int) ( $existing['expires'] ?? 0 ) > time() ) {
			return null;
		}

		// Atomic compare-and-swap on the expired lock. The WHERE meta_value =
		// $existing_raw clause is the test; the SET is the swap. Concurrent
		// callers that read the same expired lock will race here, and only one
		// will see rows_affected=1.
		$rows = $wpdb->update(
			$wpdb->postmeta,
			array( 'meta_value' => $value ),
			array(
				'post_id'    => $post->ID,
				'meta_key'   => self::META_LOCK,
				'meta_value' => $existing_raw,
			),
			array( '%s' ),
			array( '%d', '%s', '%s' )
		);

		if ( false === $rows ) {
			return null;
		}

		// Bust the post-meta cache so subsequent reads see the new lock value.
		wp_cache_delete( $post->ID, 'post_meta' );

		return 1 === (int) $rows ? $token : null;
	}

	public function release_session_lock( string $session_id, string $lock_token ): bool {
		$post = $this->find_post_by_session_id( $session_id );
		if ( null === $post ) {
			return false;
		}

		$existing = $this->read_lock( $post->ID );
		if ( null === $existing ) {
			return false;
		}

		if ( ( $existing['token'] ?? '' ) !== $lock_token ) {
			return false;
		}

		delete_post_meta( $post->ID, self::META_LOCK );
		return true;
	}

	/* ----------------------------- Internals ------------------------------ */

	private function find_post_by_session_id( string $session_id ): ?WP_Post {
		if ( '' === trim( $session_id ) ) {
			return null;
		}

		$query = new WP_Query(
			array(
				'post_type'              => self::POST_TYPE,
				'post_status'            => 'any',
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'suppress_filters'       => true,
				'meta_key'               => self::META_SESSION_ID,
				'meta_value'             => $session_id,
			)
		);

		return ! empty( $query->posts ) ? $query->posts[0] : null;
	}

	private function session_array( WP_Post $post ): array {
		$metadata_raw = get_post_meta( $post->ID, self::META_METADATA, true );
		$metadata     = is_string( $metadata_raw ) && '' !== $metadata_raw ? json_decode( $metadata_raw, true ) : array();
		if ( ! is_array( $metadata ) ) {
			$metadata = array();
		}

		return array(
			'session_id'           => (string) get_post_meta( $post->ID, self::META_SESSION_ID, true ),
			'workspace_type'       => (string) get_post_meta( $post->ID, self::META_WORKSPACE_TYPE, true ),
			'workspace_id'         => (string) get_post_meta( $post->ID, self::META_WORKSPACE_ID, true ),
			'user_id'              => (int) $post->post_author,
			'agent_id'             => (int) get_post_meta( $post->ID, self::META_AGENT_ID, true ),
			'title'                => (string) $post->post_title,
			'messages'             => $this->decode_messages( $post->post_content ),
			'metadata'             => $metadata,
			'provider'             => (string) get_post_meta( $post->ID, self::META_PROVIDER, true ),
			'model'                => (string) get_post_meta( $post->ID, self::META_MODEL, true ),
			'provider_response_id' => $this->nullable_meta_string( $post->ID, self::META_PROVIDER_RESPONSE_ID ),
			'context'              => (string) ( get_post_meta( $post->ID, self::META_CONTEXT, true ) ?: 'chat' ),
			'mode'                 => (string) ( get_post_meta( $post->ID, self::META_CONTEXT, true ) ?: 'chat' ),
			'created_at'           => (string) $post->post_date_gmt,
			'updated_at'           => (string) $post->post_modified_gmt,
			'last_read_at'         => $this->nullable_meta_string( $post->ID, self::META_LAST_READ_AT ),
			'expires_at'           => $this->nullable_meta_string( $post->ID, self::META_EXPIRES_AT ),
		);
	}

	private function decode_messages( string $raw ): array {
		if ( '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? array_values( $decoded ) : array();
	}

	private function nullable_meta_string( int $post_id, string $key ): ?string {
		$value = get_post_meta( $post_id, $key, true );
		return ( '' === $value || null === $value ) ? null : (string) $value;
	}

	private function read_lock( int $post_id ): ?array {
		$raw = get_post_meta( $post_id, self::META_LOCK, true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	private function lock_active( int $post_id ): bool {
		$lock = $this->read_lock( $post_id );
		if ( null === $lock ) {
			return false;
		}
		return (int) ( $lock['expires'] ?? 0 ) > time();
	}

	private static function uuid4(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}
		// Fallback for environments without WP loaded.
		$data    = random_bytes( 16 );
		$data[6] = chr( ( ord( $data[6] ) & 0x0f ) | 0x40 );
		$data[8] = chr( ( ord( $data[8] ) & 0x3f ) | 0x80 );
		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
	}
}
