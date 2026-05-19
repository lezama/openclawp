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
}
