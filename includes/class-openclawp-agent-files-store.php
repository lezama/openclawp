<?php
/**
 * CPT-backed store for agent files.
 *
 * Each agent file is one `openclawp_agent_file` post:
 *
 *   - post_title   — the file name slot (e.g. "AGENTS.md", "SOUL.md", "BOOTSTRAP.md")
 *   - post_content — the markdown body the agent will eventually read
 *   - meta `agent_slug` — optional. When set, the file applies only to that
 *                        agent. When empty, the file is "global" (applies to
 *                        every agent).
 *
 * The CPT is hidden from the default Posts admin and the front-end — admins
 * author these documents exclusively through openclaWP's own surface
 * (see {@see OpenclaWP_Agent_Files_Admin}).
 *
 * Runtime consumer: {@see OpenclaWP_Runner} (see `build_turn_runner()` around
 * the `using_system_instruction()` call) appends `for_agent_as_text()` to the
 * agent's static description on every turn.
 *
 * @package OpenclaWP
 * @since   0.11.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * CPT registrar + read API for agent files.
 */
final class OpenclaWP_Agent_Files_Store {

	public const POST_TYPE = 'openclawp_agent_file';

	public const META_AGENT_SLUG = 'agent_slug';

	/**
	 * Wire the CPT registration on `init`.
	 */
	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'register_post_type' ), 5 );
	}

	/**
	 * Register the `openclawp_agent_file` CPT.
	 *
	 * Hidden from the front-end and from the default Posts admin — admins
	 * author these through openclaWP's own surface so the file/agent
	 * relationship is explicit.
	 */
	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Agent files', 'openclawp' ),
					'singular_name' => __( 'Agent file', 'openclawp' ),
				),
				'public'              => false,
				'show_ui'             => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'rewrite'             => false,
				'has_archive'         => false,
				'supports'            => array( 'title', 'editor' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			)
		);
	}

	/**
	 * Return all agent files, newest first.
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
	 * Return the files that target a given agent — both the agent's own
	 * files (matching `agent_slug` meta) and globals (no `agent_slug` set).
	 *
	 * @param string $agent_slug Agent slug, e.g. `openclawp-example`.
	 * @return array<int, \WP_Post>
	 */
	public static function for_agent( string $agent_slug ): array {
		$agent_slug = trim( $agent_slug );

		$out = array();
		foreach ( self::all() as $post ) {
			$slot = (string) get_post_meta( $post->ID, self::META_AGENT_SLUG, true );
			if ( '' === $slot || ( '' !== $agent_slug && $slot === $agent_slug ) ) {
				$out[] = $post;
			}
		}
		return $out;
	}

	/**
	 * Return the files targeted at a given agent concatenated as plain text,
	 * ready to append to a system prompt.
	 *
	 * Each file is emitted as:
	 *
	 *     === {post_title} ===
	 *     {post_content}
	 *
	 * Sections are joined with a blank line. Files with empty bodies are
	 * skipped. Returns an empty string when no files apply — callers can
	 * treat the result as a safe no-op append.
	 *
	 * @param string $agent_slug Agent slug, e.g. `openclawp-example`.
	 */
	public static function for_agent_as_text( string $agent_slug ): string {
		$sections = array();
		foreach ( self::for_agent( $agent_slug ) as $post ) {
			$body = (string) $post->post_content;
			if ( '' === trim( $body ) ) {
				continue;
			}
			$sections[] = '=== ' . $post->post_title . " ===\n" . $body;
		}
		return implode( "\n\n", $sections );
	}

	/**
	 * Fetch a file by its title (file name slot, e.g. "AGENTS.md").
	 *
	 * Title matches are exact and case-sensitive — the title doubles as the
	 * file name, so two files called "AGENTS.md" would be ambiguous. The
	 * first match wins.
	 */
	public static function get_by_title( string $title ): ?\WP_Post {
		$title = trim( $title );
		if ( '' === $title ) {
			return null;
		}
		$matches = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'draft' ),
				'title'          => $title,
				'posts_per_page' => 1,
				'no_found_rows'  => true,
			)
		);
		return $matches[0] ?? null;
	}
}
