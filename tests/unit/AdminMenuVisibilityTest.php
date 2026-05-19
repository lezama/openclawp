<?php
/**
 * Unit tests for OpenclaWP_Admin_Menu_Visibility.
 *
 * Pure-PHP coverage of the progressive-disclosure helper: default
 * always-visible set, filter override, and the parent-slug-or-null
 * resolution that drives whether `add_submenu_page` shows in the sidebar.
 *
 * @package OpenclaWP\Tests
 */

declare( strict_types=1 );

namespace OpenclaWP\Tests\Unit;

use OpenclaWP_Admin;
use OpenclaWP_Admin_Menu_Visibility;
use PHPUnit\Framework\TestCase;

/**
 * @covers OpenclaWP_Admin_Menu_Visibility
 */
final class AdminMenuVisibilityTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['openclawp_test_filters']['openclawp_admin_menu_always_visible'] );
		parent::tearDown();
	}

	public function test_default_always_visible_includes_the_five_primitives(): void {
		$slugs = OpenclaWP_Admin_Menu_Visibility::always_visible_slugs();
		$this->assertContains( 'openclawp', $slugs );
		$this->assertContains( 'openclawp-channels', $slugs );
		$this->assertContains( 'openclawp-workflows', $slugs );
		$this->assertContains( 'openclawp-custom-tools', $slugs );
		$this->assertContains( 'openclawp-settings', $slugs );
	}

	public function test_hide_when_empty_surfaces_are_not_in_the_default(): void {
		$slugs   = OpenclaWP_Admin_Menu_Visibility::always_visible_slugs();
		$hidable = array(
			'openclawp-routines',
			'openclawp-usage',
			'openclawp-knowledge-base',
			'openclawp-mcp-servers',
			'openclawp-connected-clients',
			'openclawp-decisions',
			'openclawp-mcp-clients',
			'openclawp-whatsapp',
		);
		foreach ( $hidable as $slug ) {
			$this->assertNotContains( $slug, $slugs, "Expected $slug to be hide-when-empty by default" );
		}
	}

	public function test_parent_for_returns_parent_when_surface_has_content(): void {
		$this->assertSame(
			OpenclaWP_Admin::PAGE_SLUG,
			OpenclaWP_Admin_Menu_Visibility::parent_for( 'openclawp-mcp-servers', true )
		);
	}

	public function test_parent_for_returns_null_when_empty_and_not_always_visible(): void {
		$this->assertNull(
			OpenclaWP_Admin_Menu_Visibility::parent_for( 'openclawp-mcp-servers', false )
		);
	}

	public function test_always_visible_slug_is_attached_even_when_empty(): void {
		$this->assertSame(
			OpenclaWP_Admin::PAGE_SLUG,
			OpenclaWP_Admin_Menu_Visibility::parent_for( 'openclawp-settings', false )
		);
	}

	public function test_filter_can_force_show_a_hide_when_empty_surface(): void {
		$GLOBALS['openclawp_test_filters']['openclawp_admin_menu_always_visible'] = static function ( $slugs ) {
			$slugs[] = 'openclawp-usage';
			return $slugs;
		};
		$this->assertSame(
			OpenclaWP_Admin::PAGE_SLUG,
			OpenclaWP_Admin_Menu_Visibility::parent_for( 'openclawp-usage', false )
		);
	}

	public function test_filter_can_hide_a_primitive(): void {
		$GLOBALS['openclawp_test_filters']['openclawp_admin_menu_always_visible'] = static function () {
			return array( 'openclawp', 'openclawp-channels' );
		};
		$this->assertNull(
			OpenclaWP_Admin_Menu_Visibility::parent_for( 'openclawp-settings', false )
		);
	}

	public function test_filter_result_is_normalised(): void {
		$GLOBALS['openclawp_test_filters']['openclawp_admin_menu_always_visible'] = static function () {
			// Throw in non-strings, duplicates, and empty strings — the helper
			// should clean them up before consumers see the list.
			return array( 'openclawp', 'openclawp', '', 'openclawp-channels', 123, null );
		};
		$slugs = OpenclaWP_Admin_Menu_Visibility::always_visible_slugs();
		$this->assertSame(
			array( 'openclawp', 'openclawp-channels' ),
			$slugs
		);
	}

	public function test_surface_count_returns_null_for_unknown_slug(): void {
		$this->assertNull(
			OpenclaWP_Admin_Menu_Visibility::surface_count( 'openclawp-unknown-surface' )
		);
	}

	public function test_is_surface_populated_returns_true_for_unknown_slug(): void {
		// Unknown surfaces (e.g. host-supplied) should not be suppressed by
		// the visibility helper — we have no count to compare against.
		$this->assertTrue(
			OpenclaWP_Admin_Menu_Visibility::is_surface_populated( 'openclawp-unknown-surface' )
		);
	}

	public function test_parent_for_slug_returns_parent_when_helper_reports_unknown(): void {
		// Unknown slugs report `null` from `surface_count()` which the bool
		// helper treats as populated, so the parent is the openclaWP menu.
		$this->assertSame(
			OpenclaWP_Admin::PAGE_SLUG,
			OpenclaWP_Admin_Menu_Visibility::parent_for_slug( 'openclawp-unknown-surface' )
		);
	}

	public function test_parent_for_slug_honours_always_visible(): void {
		// Settings has no count callback, but it's in the always-visible
		// set, so the helper must keep it attached even when treated as
		// "not populated".
		$this->assertSame(
			OpenclaWP_Admin::PAGE_SLUG,
			OpenclaWP_Admin_Menu_Visibility::parent_for_slug( 'openclawp-settings' )
		);
	}
}
