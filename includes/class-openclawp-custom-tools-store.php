<?php
/**
 * CPT-backed custom tool store.
 *
 * Each user-authored custom tool is one `openclawp_tool` post:
 *
 *   - post_title    — human label shown in admin lists
 *   - post_name     — slug used for the registered ability name
 *   - post_content  — agent-visible description (what the LLM sees)
 *   - post_status   — 'publish' = enabled, 'draft' = disabled
 *
 * The structured tool spec (type, input schema, body, auth, output handling,
 * role allowlist) lives in a single `_openclawp_tool_spec` post-meta as a
 * JSON-encoded array so we can hand it back and forth with the REST layer
 * without reshaping it into a flat key-per-field map.
 *
 * Revisions are enabled on the CPT so admins can roll back to a previous
 * spec via the standard WordPress revisions UI.
 *
 * @package OpenclaWP
 * @since   0.7.0
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Custom_Tools_Store {

	public const POST_TYPE = 'openclawp_tool';

	public const META_SPEC = '_openclawp_tool_spec';

	public const TYPE_HTTP = 'http';

	public const EFFECT_READ        = 'read';
	public const EFFECT_WRITE       = 'write';
	public const EFFECT_DESTRUCTIVE = 'destructive';

	public const OUTPUT_RAW      = 'raw';
	public const OUTPUT_JSONPATH = 'jsonpath';
	public const OUTPUT_REGEX    = 'regex';

	public const AUTH_NONE   = 'none';
	public const AUTH_BEARER = 'bearer';

	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'openclaWP Custom Tools', 'openclawp' ),
					'singular_name' => __( 'openclaWP Custom Tool', 'openclawp' ),
				),
				'public'              => false,
				'show_ui'             => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'rewrite'             => false,
				'has_archive'         => false,
				// `revisions` so admins can roll back to a previous spec.
				'supports'            => array( 'title', 'editor', 'author', 'custom-fields', 'revisions' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			)
		);
	}

	/**
	 * Find a tool post by slug. Returns null when nothing matches.
	 */
	public static function find_by_slug( string $slug ): ?\WP_Post {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return null;
		}
		$matches = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'draft' ),
				'name'           => $slug,
				'posts_per_page' => 1,
				'no_found_rows'  => true,
			)
		);
		return $matches[0] ?? null;
	}

	/**
	 * Return all tools, newest first.
	 *
	 * @return array<int, \WP_Post>
	 */
	public static function all(): array {
		return (array) get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => 200,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);
	}

	/**
	 * Return all enabled tools. Used by the registrar at boot.
	 *
	 * @return array<int, \WP_Post>
	 */
	public static function all_enabled(): array {
		return (array) get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'no_found_rows'  => true,
			)
		);
	}

	/**
	 * Create a tool. Returns the post id on success or a WP_Error.
	 *
	 * @param array{label:string,slug:string,description:string,spec:array} $args
	 * @return int|\WP_Error
	 */
	public static function create( array $args ) {
		$label       = trim( (string) ( $args['label'] ?? '' ) );
		$slug        = sanitize_title( (string) ( $args['slug'] ?? '' ) );
		$description = trim( (string) ( $args['description'] ?? '' ) );
		$spec        = is_array( $args['spec'] ?? null ) ? $args['spec'] : array();

		if ( '' === $label || '' === $slug ) {
			return new \WP_Error( 'invalid_input', __( 'label and slug are required', 'openclawp' ) );
		}

		if ( null !== self::find_by_slug( $slug ) ) {
			return new \WP_Error(
				'slug_taken',
				/* translators: %s is a tool slug */
				sprintf( __( 'a tool with slug `%s` already exists', 'openclawp' ), $slug )
			);
		}

		$normalised = self::normalise_spec( $spec );
		if ( is_wp_error( $normalised ) ) {
			return $normalised;
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => $label,
				'post_name'    => $slug,
				'post_content' => $description,
				'post_author'  => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( (int) $post_id, self::META_SPEC, wp_slash( wp_json_encode( $normalised ) ) );

		return (int) $post_id;
	}

	/**
	 * Update an existing tool. Mutating a tool generates a revision automatically.
	 *
	 * @return true|\WP_Error
	 */
	public static function update( int $post_id, array $args ) {
		$post = get_post( $post_id );
		if ( null === $post || self::POST_TYPE !== $post->post_type ) {
			return new \WP_Error( 'not_found', __( 'tool not found', 'openclawp' ) );
		}

		$payload = array( 'ID' => $post_id );

		if ( isset( $args['label'] ) ) {
			$label = trim( (string) $args['label'] );
			if ( '' === $label ) {
				return new \WP_Error( 'invalid_input', __( 'label cannot be empty', 'openclawp' ) );
			}
			$payload['post_title'] = $label;
		}
		if ( isset( $args['description'] ) ) {
			$payload['post_content'] = (string) $args['description'];
		}

		if ( isset( $args['spec'] ) && is_array( $args['spec'] ) ) {
			$normalised = self::normalise_spec( $args['spec'] );
			if ( is_wp_error( $normalised ) ) {
				return $normalised;
			}
			update_post_meta( $post_id, self::META_SPEC, wp_slash( wp_json_encode( $normalised ) ) );
		}

		if ( count( $payload ) > 1 ) {
			$result = wp_update_post( $payload, true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	public static function toggle_enabled( int $post_id, bool $enabled ): bool {
		$result = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => $enabled ? 'publish' : 'draft',
			),
			true
		);
		return ! is_wp_error( $result );
	}

	public static function delete( int $post_id ): bool {
		return false !== wp_delete_post( $post_id, true );
	}

	public static function is_enabled( \WP_Post $post ): bool {
		return 'publish' === $post->post_status;
	}

	/**
	 * Decode the stored spec for a tool post. Returns a normalised array
	 * even when nothing is stored so callers never have to null-check.
	 */
	public static function get_spec( \WP_Post $post ): array {
		$raw = get_post_meta( $post->ID, self::META_SPEC, true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return self::default_spec();
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return self::default_spec();
		}
		return self::with_defaults( $decoded );
	}

	/**
	 * Normalise a user-supplied spec into the canonical shape we persist.
	 * Validates structural constraints (type allowed, body required, etc.)
	 * and returns a WP_Error when anything is off.
	 *
	 * @return array|\WP_Error
	 */
	public static function normalise_spec( array $spec ) {
		$type = strtolower( (string) ( $spec['type'] ?? self::TYPE_HTTP ) );
		if ( self::TYPE_HTTP !== $type ) {
			return new \WP_Error(
				'unsupported_type',
				/* translators: %s is a tool type slug */
				sprintf( __( 'tool type `%s` is not supported yet', 'openclawp' ), $type )
			);
		}

		$input_schema = is_array( $spec['input_schema'] ?? null ) ? $spec['input_schema'] : array(
			'type'       => 'object',
			'properties' => new \stdClass(),
		);

		$http = is_array( $spec['http'] ?? null ) ? $spec['http'] : array();

		$method          = strtoupper( (string) ( $http['method'] ?? 'GET' ) );
		$allowed_methods = array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' );
		if ( ! in_array( $method, $allowed_methods, true ) ) {
			return new \WP_Error(
				'invalid_method',
				/* translators: %s is an HTTP method */
				sprintf( __( 'unsupported HTTP method `%s`', 'openclawp' ), $method )
			);
		}

		$url = (string) ( $http['url'] ?? '' );
		if ( '' === $url ) {
			return new \WP_Error( 'invalid_url', __( 'HTTP url is required', 'openclawp' ) );
		}

		$headers         = is_array( $http['headers'] ?? null ) ? $http['headers'] : array();
		$cleaned_headers = array();
		foreach ( $headers as $name => $value ) {
			$name = trim( (string) $name );
			if ( '' === $name ) {
				continue;
			}
			$cleaned_headers[ $name ] = (string) $value;
		}

		$body_type = strtolower( (string) ( $http['body_type'] ?? 'none' ) );
		if ( ! in_array( $body_type, array( 'none', 'json', 'form', 'raw' ), true ) ) {
			$body_type = 'none';
		}

		$auth = is_array( $spec['auth'] ?? null ) ? $spec['auth'] : array( 'mode' => self::AUTH_NONE );
		$mode = (string) ( $auth['mode'] ?? self::AUTH_NONE );
		if ( ! in_array( $mode, array( self::AUTH_NONE, self::AUTH_BEARER ), true ) ) {
			$mode = self::AUTH_NONE;
		}
		$auth_normalised = array( 'mode' => $mode );
		if ( self::AUTH_BEARER === $mode ) {
			// We never store the literal token — only the option key it lives under.
			$auth_normalised['token_option'] = sanitize_key( (string) ( $auth['token_option'] ?? '' ) );
		}

		$effect = (string) ( $spec['effect'] ?? self::EFFECT_READ );
		if ( ! in_array( $effect, array( self::EFFECT_READ, self::EFFECT_WRITE, self::EFFECT_DESTRUCTIVE ), true ) ) {
			$effect = self::EFFECT_READ;
		}

		$output      = is_array( $spec['output'] ?? null ) ? $spec['output'] : array( 'mode' => self::OUTPUT_RAW );
		$output_mode = (string) ( $output['mode'] ?? self::OUTPUT_RAW );
		if ( ! in_array( $output_mode, array( self::OUTPUT_RAW, self::OUTPUT_JSONPATH, self::OUTPUT_REGEX ), true ) ) {
			$output_mode = self::OUTPUT_RAW;
		}
		$output_normalised = array( 'mode' => $output_mode );
		if ( self::OUTPUT_JSONPATH === $output_mode ) {
			$output_normalised['path'] = (string) ( $output['path'] ?? '' );
		} elseif ( self::OUTPUT_REGEX === $output_mode ) {
			$output_normalised['pattern'] = (string) ( $output['pattern'] ?? '' );
			$output_normalised['group']   = (int) ( $output['group'] ?? 0 );
		}

		$roles = is_array( $spec['allowed_roles'] ?? null ) ? $spec['allowed_roles'] : array( 'administrator' );
		$roles = array_values(
			array_filter(
				array_map( static fn( $r ): string => sanitize_key( (string) $r ), $roles ),
				static fn( string $r ): bool => '' !== $r
			)
		);
		if ( empty( $roles ) ) {
			// Hard rule: default to administrator-only.
			$roles = array( 'administrator' );
		}

		return array(
			'type'          => self::TYPE_HTTP,
			'input_schema'  => $input_schema,
			'http'          => array(
				'method'    => $method,
				'url'       => $url,
				'headers'   => $cleaned_headers,
				'body_type' => $body_type,
				'body'      => isset( $http['body'] ) ? (string) $http['body'] : '',
			),
			'auth'          => $auth_normalised,
			'effect'        => $effect,
			'output'        => $output_normalised,
			'allowed_roles' => $roles,
		);
	}

	/**
	 * Default empty spec. Used when no meta is present yet.
	 */
	public static function default_spec(): array {
		return array(
			'type'          => self::TYPE_HTTP,
			'input_schema'  => array(
				'type'       => 'object',
				'properties' => new \stdClass(),
			),
			'http'          => array(
				'method'    => 'GET',
				'url'       => '',
				'headers'   => array(),
				'body_type' => 'none',
				'body'      => '',
			),
			'auth'          => array( 'mode' => self::AUTH_NONE ),
			'effect'        => self::EFFECT_READ,
			'output'        => array( 'mode' => self::OUTPUT_RAW ),
			'allowed_roles' => array( 'administrator' ),
		);
	}

	/**
	 * Apply defaults to a spec read from storage. Older revisions may be
	 * missing newer fields; this keeps the rest of the system simple.
	 */
	private static function with_defaults( array $spec ): array {
		$defaults = self::default_spec();
		foreach ( $defaults as $key => $value ) {
			if ( ! isset( $spec[ $key ] ) ) {
				$spec[ $key ] = $value;
			} elseif ( is_array( $value ) && is_array( $spec[ $key ] ) ) {
				$spec[ $key ] = array_replace_recursive( $value, $spec[ $key ] );
			}
		}
		return $spec;
	}
}
