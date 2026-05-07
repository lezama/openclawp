<?php
/**
 * Site-introspection abilities for the bundled site-introspection demo agent.
 *
 * These abilities expose read-only, low-stakes site information so a chat
 * agent can answer questions like "what was my last post?" or "how many
 * comments are pending moderation?" against a real WordPress install.
 *
 * Gated behind the `openclawp_register_site_introspection` filter (off by
 * default). Production installs that want a site-aware agent should write
 * their own; this is fixture code for the bundled demo.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Site_Abilities {

	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		self::register_get_recent_posts();
		self::register_count_comments();
		self::register_get_active_plugins();
		self::register_get_current_user();
	}

	private static function register_get_recent_posts(): void {
		wp_register_ability(
			'openclawp/get-recent-posts',
			array(
				'label'               => __( 'Get recent posts', 'openclawp' ),
				'description'         => __( 'Returns the N most recent published posts on this site, with title, excerpt, author display name, and ISO 8601 publish date. Use this to answer questions like "what is my last post?" or "what did I publish last week?".', 'openclawp' ),
				'category'            => 'openclawp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'limit' => array(
							'type'        => 'integer',
							'description' => 'How many posts to return (1–10).',
							'minimum'     => 1,
							'maximum'     => 10,
						),
					),
				),
				'output_schema'       => array(
					'type'  => 'object',
					'properties' => array(
						'posts' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
					),
					'required' => array( 'posts' ),
				),
				'execute_callback'    => static function ( array $args ): array {
					$limit = isset( $args['limit'] ) ? max( 1, min( 10, (int) $args['limit'] ) ) : 5;
					$posts = get_posts(
						array(
							'post_type'              => 'post',
							'post_status'            => 'publish',
							'posts_per_page'         => $limit,
							'orderby'                => 'date',
							'order'                  => 'DESC',
							'no_found_rows'          => true,
							'update_post_term_cache' => false,
							'suppress_filters'       => true,
						)
					);

					return array(
						'posts' => array_map(
							static function ( WP_Post $p ): array {
								$author = get_userdata( (int) $p->post_author );
								return array(
									'id'           => (int) $p->ID,
									'title'        => (string) $p->post_title,
									'excerpt'      => wp_trim_words(
										wp_strip_all_tags( (string) ( $p->post_excerpt !== '' ? $p->post_excerpt : $p->post_content ) ),
										40
									),
									'author'       => $author ? (string) $author->display_name : '',
									'published_at' => mysql2date( 'c', $p->post_date_gmt, false ),
								);
							},
							$posts
						),
					);
				},
				'permission_callback' => '__return_true',
			)
		);
	}

	private static function register_count_comments(): void {
		wp_register_ability(
			'openclawp/count-comments',
			array(
				'label'               => __( 'Count comments by status', 'openclawp' ),
				'description'         => __( 'Returns how many comments are in each moderation status on this site (approved, pending, spam, trash). Use this to answer questions about comment moderation workload.', 'openclawp' ),
				'category'            => 'openclawp',
				'input_schema'        => array(
					'type' => 'object',
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'approved' => array( 'type' => 'integer' ),
						'pending'  => array( 'type' => 'integer' ),
						'spam'     => array( 'type' => 'integer' ),
						'trash'    => array( 'type' => 'integer' ),
						'total'    => array( 'type' => 'integer' ),
					),
					'required'   => array( 'approved', 'pending', 'spam', 'trash', 'total' ),
				),
				'execute_callback'    => static function (): array {
					$counts = wp_count_comments();
					return array(
						'approved' => (int) $counts->approved,
						'pending'  => (int) $counts->moderated,
						'spam'     => (int) $counts->spam,
						'trash'    => (int) $counts->trash,
						'total'    => (int) $counts->total_comments,
					);
				},
				'permission_callback' => '__return_true',
			)
		);
	}

	private static function register_get_active_plugins(): void {
		wp_register_ability(
			'openclawp/get-active-plugins',
			array(
				'label'               => __( 'Get active plugins', 'openclawp' ),
				'description'         => __( 'Returns the slugs and names of all currently active plugins on this site. Use this to answer questions about what is installed and running.', 'openclawp' ),
				'category'            => 'openclawp',
				'input_schema'        => array(
					'type' => 'object',
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'plugins' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
					),
					'required'   => array( 'plugins' ),
				),
				'execute_callback'    => static function (): array {
					if ( ! function_exists( 'get_plugins' ) ) {
						require_once ABSPATH . 'wp-admin/includes/plugin.php';
					}
					$active = (array) get_option( 'active_plugins', array() );
					$all    = get_plugins();

					$plugins = array();
					foreach ( $active as $plugin_file ) {
						$entry = $all[ $plugin_file ] ?? null;
						$plugins[] = array(
							'slug'    => (string) $plugin_file,
							'name'    => $entry ? (string) ( $entry['Name'] ?? $plugin_file ) : (string) $plugin_file,
							'version' => $entry ? (string) ( $entry['Version'] ?? '' ) : '',
						);
					}

					return array( 'plugins' => $plugins );
				},
				'permission_callback' => '__return_true',
			)
		);
	}

	private static function register_get_current_user(): void {
		wp_register_ability(
			'openclawp/get-current-user',
			array(
				'label'               => __( 'Get current user', 'openclawp' ),
				'description'         => __( 'Returns the display name, login, email, and roles of the currently authenticated WordPress user — i.e. the human running this conversation. Use this when the user asks "who am I?", "what is my email?", or similar.', 'openclawp' ),
				'category'            => 'openclawp',
				'input_schema'        => array(
					'type' => 'object',
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'           => array( 'type' => 'integer' ),
						'login'        => array( 'type' => 'string' ),
						'display_name' => array( 'type' => 'string' ),
						'email'        => array( 'type' => 'string' ),
						'roles'        => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					),
					'required'   => array( 'id', 'login', 'display_name', 'email', 'roles' ),
				),
				'execute_callback'    => static function (): array {
					$user = wp_get_current_user();
					if ( ! $user || 0 === (int) $user->ID ) {
						return array(
							'id'           => 0,
							'login'        => '',
							'display_name' => '',
							'email'        => '',
							'roles'        => array(),
						);
					}
					return array(
						'id'           => (int) $user->ID,
						'login'        => (string) $user->user_login,
						'display_name' => (string) $user->display_name,
						'email'        => (string) $user->user_email,
						'roles'        => array_values( array_map( 'strval', (array) $user->roles ) ),
					);
				},
				'permission_callback' => '__return_true',
			)
		);
	}
}
