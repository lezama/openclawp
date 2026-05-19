<?php
/**
 * Helpers for progressive-disclosure of the openclaWP submenu.
 *
 * A fresh install of openclaWP exposes a long list of submenu pages — most
 * pointing to empty surfaces (no MCP servers configured, no usage recorded,
 * no knowledge-base sources, …). To keep wp-admin focused on the primitives
 * every install needs, the rest of the surfaces appear in the sidebar only
 * once they have at least one entry.
 *
 * Pages themselves are *always* registered — their `?page=…` URL still
 * resolves so deep links, docs, and scripts don't break. Only the menu
 * entry is hidden. This is implemented via the documented WordPress idiom
 * of passing `null` as the parent slug to `add_submenu_page()`, which
 * registers the page handler but skips the sidebar entry.
 *
 * This class also owns the *single* set of population checks for each
 * capability surface: both the menu-gating logic and the "Discover"
 * panel (rendered on the Chat page) consult `is_surface_populated()` /
 * `surface_count()` so there is no risk of the menu and the discovery
 * surface disagreeing about whether a capability is set up.
 *
 * @package OpenclaWP
 * @since   0.9.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Progressive-disclosure helper for the openclaWP submenu.
 */
final class OpenclaWP_Admin_Menu_Visibility {

	/**
	 * Default set of submenu slugs that should always render in the sidebar,
	 * regardless of whether their underlying surface has content.
	 *
	 * These are the conceptual primitives every install needs — the system
	 * never hides them, otherwise a fresh install would have nothing to do.
	 *
	 * @var array<int,string>
	 */
	private const DEFAULT_ALWAYS_VISIBLE = array(
		'openclawp',               // Chat (parent + same-slug first child).
		'openclawp-channels',      // How others talk to it.
		'openclawp-workflows',     // How it runs without you.
		'openclawp-custom-tools',  // How the agent gets new capabilities.
		'openclawp-settings',      // The safety valve.
	);

	/**
	 * The set of submenu slugs that are visible in the sidebar regardless
	 * of content. Hide-when-empty surfaces consult this list before
	 * deciding whether to attach themselves to the parent menu.
	 *
	 * @return array<int,string>
	 */
	public static function always_visible_slugs(): array {
		/**
		 * Filter the set of openclaWP submenu slugs that are always visible
		 * in the sidebar, even when their surface has no content.
		 *
		 * Use this to force-show a surface in a host install — e.g. a billing
		 * context that wants Usage always present even on day one.
		 *
		 * @since 0.9.0
		 *
		 * @param array<int,string> $slugs Default always-visible slugs.
		 */
		$slugs = (array) apply_filters( 'openclawp_admin_menu_always_visible', self::DEFAULT_ALWAYS_VISIBLE );

		// Normalise to a list of unique, non-empty strings — host filters
		// may hand us anything (duplicates, empty strings, non-strings).
		$out = array();
		foreach ( $slugs as $slug ) {
			if ( is_string( $slug ) && '' !== $slug && ! in_array( $slug, $out, true ) ) {
				$out[] = $slug;
			}
		}
		return $out;
	}

	/**
	 * Resolve the parent slug to pass to `add_submenu_page()` for a
	 * hide-when-empty surface.
	 *
	 * - Returns the openclaWP parent slug when the surface is either
	 *   force-listed by the always-visible filter or has at least one
	 *   entry.
	 * - Returns `null` otherwise, which registers the page handler so the
	 *   admin URL keeps working but skips the sidebar entry.
	 *
	 * @param string $slug        The submenu slug being registered.
	 * @param bool   $has_content Whether the underlying store/registry has
	 *                            at least one entry.
	 *
	 * @return string|null
	 */
	public static function parent_for( string $slug, bool $has_content ): ?string {
		if ( $has_content || in_array( $slug, self::always_visible_slugs(), true ) ) {
			return OpenclaWP_Admin::PAGE_SLUG;
		}
		return null;
	}

	/**
	 * Convenience wrapper: resolve the parent slug for a hide-when-empty
	 * surface using the centralised population check.
	 *
	 * Equivalent to `parent_for( $slug, self::is_surface_populated( $slug ) )`.
	 * Prefer this from registration sites so the same store call powers both
	 * the menu gating and the Discover panel.
	 *
	 * @param string $slug The submenu slug being registered.
	 *
	 * @return string|null
	 */
	public static function parent_for_slug( string $slug ): ?string {
		return self::parent_for( $slug, self::is_surface_populated( $slug ) );
	}

	/**
	 * Whether the capability surface backing `$slug` currently has at least
	 * one entry. Returns `true` for surfaces this class doesn't know about
	 * — unknown slugs are assumed to be set up by some host-supplied module,
	 * so they aren't suppressed.
	 *
	 * @param string $slug Submenu slug, e.g. `openclawp-mcp-servers`.
	 */
	public static function is_surface_populated( string $slug ): bool {
		$count = self::surface_count( $slug );
		return null === $count ? true : $count > 0;
	}

	/**
	 * Count the entries for a capability surface. Returns `null` for
	 * surfaces this class doesn't know how to introspect.
	 *
	 * Cheap on purpose — every check is either a `posts_per_page=1` query, an
	 * already-cached option read, or a function-exists guard. The Discover
	 * panel calls each of these on every Chat page load.
	 *
	 * @param string $slug Submenu slug, e.g. `openclawp-channels`.
	 */
	public static function surface_count( string $slug ): ?int {
		switch ( $slug ) {
			case 'openclawp-channels':
				if ( ! class_exists( 'OpenclaWP_Channels_Admin' ) ) {
					return null;
				}
				return count( OpenclaWP_Channels_Admin::get_channels() );

			case 'openclawp-workflows':
				if ( ! class_exists( 'OpenclaWP_Workflow_Store' ) ) {
					return null;
				}
				$store = OpenclaWP_Workflow_Store::instance();
				return count( $store->all() );

			case 'openclawp-custom-tools':
				if ( ! class_exists( 'OpenclaWP_Custom_Tools_Store' ) ) {
					return null;
				}
				return count( OpenclaWP_Custom_Tools_Store::all() );

			case 'openclawp-routines':
				if ( ! function_exists( 'wp_get_routines' ) ) {
					return 0;
				}
				return count( (array) wp_get_routines() );

			case 'openclawp-usage':
				if ( ! class_exists( 'OpenclaWP_Usage_Store' ) ) {
					return null;
				}
				// `get_recent( 1 )` keeps the existence check cheap; treat it
				// as a boolean signal, since the user-facing count would be
				// expensive to compute.
				return count( OpenclaWP_Usage_Store::get_recent( 1 ) );

			case 'openclawp-knowledge-base':
				if ( ! class_exists( 'OpenclaWP_Knowledge_Base_Sources' ) ) {
					return null;
				}
				$config = OpenclaWP_Knowledge_Base_Sources::get();
				return count( (array) ( $config['post_types'] ?? array() ) )
					+ count( (array) ( $config['urls'] ?? array() ) );

			case 'openclawp-mcp-servers':
				if ( ! class_exists( 'OpenclaWP_Mcp_Server_Store' ) ) {
					return null;
				}
				return count( OpenclaWP_Mcp_Server_Store::all() );

			case 'openclawp-whatsapp':
				if ( ! class_exists( 'OpenclaWP_Whatsapp' ) ) {
					return null;
				}
				$settings = OpenclaWP_Whatsapp::settings();
				$has_token = '' !== trim( (string) ( $settings['access_token'] ?? '' ) );
				$has_phone = '' !== trim( (string) ( $settings['phone_number_id'] ?? '' ) );
				return ( $has_token || $has_phone ) ? 1 : 0;

			case 'openclawp-connected-clients':
				if ( ! class_exists( 'OpenclaWP_Oauth_Store' ) ) {
					return null;
				}
				return count( OpenclaWP_Oauth_Store::all_clients() );

			case 'openclawp-decisions':
				if ( ! class_exists( 'OpenclaWP_Decisions_Store' ) ) {
					return null;
				}
				return count( OpenclaWP_Decisions_Store::recent( array( 'limit' => 1 ) ) );

			case 'openclawp-mcp-clients':
				if ( ! class_exists( 'OpenclaWP_Mcp_Client_Store' ) ) {
					return null;
				}
				return count( OpenclaWP_Mcp_Client_Store::all() );

			case 'openclawp-agent-files':
				if ( ! class_exists( 'OpenclaWP_Agent_Files_Store' ) ) {
					return null;
				}
				return count( OpenclaWP_Agent_Files_Store::all() );
		}

		return null;
	}
}
