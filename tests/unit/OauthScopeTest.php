<?php
/**
 * Pure-PHP unit tests for OpenclaWP_Oauth_Scope.
 *
 * Covers:
 *   - scope -> effect tier inclusion (read < write < destructive < external)
 *   - the must-have test from issue #45: an `mcp:read` client must not be
 *     able to call a `write` ability.
 *   - parser tolerance: ignore unknown scopes, dedupe, preserve order.
 *   - heuristic effect mapping (used until #40 lands `openclawp_ability_effect`).
 *
 * The token-storage layer (which depends on the post system) is exercised by
 * the full OAuth flow integration test in tests/smoke.php.
 *
 * @package OpenclaWP\Tests
 */

declare( strict_types=1 );

namespace OpenclaWP\Tests\Unit;

use OpenclaWP_Oauth_Scope;
use PHPUnit\Framework\TestCase;

/**
 * @covers OpenclaWP_Oauth_Scope
 */
final class OauthScopeTest extends TestCase {

	public function test_read_scope_only_permits_read_effect(): void {
		$scopes = array( OpenclaWP_Oauth_Scope::SCOPE_READ );
		$this->assertTrue( OpenclaWP_Oauth_Scope::scopes_permit_effect( $scopes, OpenclaWP_Oauth_Scope::EFFECT_READ ) );
		$this->assertFalse( OpenclaWP_Oauth_Scope::scopes_permit_effect( $scopes, OpenclaWP_Oauth_Scope::EFFECT_WRITE ) );
		$this->assertFalse( OpenclaWP_Oauth_Scope::scopes_permit_effect( $scopes, OpenclaWP_Oauth_Scope::EFFECT_DESTRUCTIVE ) );
		$this->assertFalse( OpenclaWP_Oauth_Scope::scopes_permit_effect( $scopes, OpenclaWP_Oauth_Scope::EFFECT_EXTERNAL ) );
	}

	public function test_write_scope_implies_read(): void {
		$scopes = array( OpenclaWP_Oauth_Scope::SCOPE_WRITE );
		$this->assertTrue( OpenclaWP_Oauth_Scope::scopes_permit_effect( $scopes, OpenclaWP_Oauth_Scope::EFFECT_READ ) );
		$this->assertTrue( OpenclaWP_Oauth_Scope::scopes_permit_effect( $scopes, OpenclaWP_Oauth_Scope::EFFECT_WRITE ) );
		$this->assertFalse( OpenclaWP_Oauth_Scope::scopes_permit_effect( $scopes, OpenclaWP_Oauth_Scope::EFFECT_DESTRUCTIVE ) );
		$this->assertFalse( OpenclaWP_Oauth_Scope::scopes_permit_effect( $scopes, OpenclaWP_Oauth_Scope::EFFECT_EXTERNAL ) );
	}

	public function test_destructive_scope_implies_read_and_write(): void {
		$scopes = array( OpenclaWP_Oauth_Scope::SCOPE_DESTRUCTIVE );
		$this->assertTrue( OpenclaWP_Oauth_Scope::scopes_permit_effect( $scopes, OpenclaWP_Oauth_Scope::EFFECT_READ ) );
		$this->assertTrue( OpenclaWP_Oauth_Scope::scopes_permit_effect( $scopes, OpenclaWP_Oauth_Scope::EFFECT_WRITE ) );
		$this->assertTrue( OpenclaWP_Oauth_Scope::scopes_permit_effect( $scopes, OpenclaWP_Oauth_Scope::EFFECT_DESTRUCTIVE ) );
		$this->assertFalse( OpenclaWP_Oauth_Scope::scopes_permit_effect( $scopes, OpenclaWP_Oauth_Scope::EFFECT_EXTERNAL ) );
	}

	public function test_external_scope_permits_everything(): void {
		$scopes = array( OpenclaWP_Oauth_Scope::SCOPE_EXTERNAL );
		$this->assertTrue( OpenclaWP_Oauth_Scope::scopes_permit_effect( $scopes, OpenclaWP_Oauth_Scope::EFFECT_READ ) );
		$this->assertTrue( OpenclaWP_Oauth_Scope::scopes_permit_effect( $scopes, OpenclaWP_Oauth_Scope::EFFECT_WRITE ) );
		$this->assertTrue( OpenclaWP_Oauth_Scope::scopes_permit_effect( $scopes, OpenclaWP_Oauth_Scope::EFFECT_DESTRUCTIVE ) );
		$this->assertTrue( OpenclaWP_Oauth_Scope::scopes_permit_effect( $scopes, OpenclaWP_Oauth_Scope::EFFECT_EXTERNAL ) );
	}

	public function test_empty_scopes_permit_nothing(): void {
		$this->assertFalse( OpenclaWP_Oauth_Scope::scopes_permit_effect( array(), OpenclaWP_Oauth_Scope::EFFECT_READ ) );
		$this->assertFalse( OpenclaWP_Oauth_Scope::scopes_permit_effect( array(), OpenclaWP_Oauth_Scope::EFFECT_WRITE ) );
	}

	/**
	 * The must-have test from issue #45: a `mcp:read` client must not call
	 * a `write` ability — regardless of what tool name the client picks.
	 */
	public function test_read_client_cannot_call_write_ability(): void {
		$granted = array( OpenclaWP_Oauth_Scope::SCOPE_READ );
		// Picks up `update-*` via the heuristic -> EFFECT_WRITE.
		$ability = 'openclawp/update-recent-post';
		$effect  = OpenclaWP_Oauth_Scope::effect_for_ability( $ability );
		$this->assertSame( OpenclaWP_Oauth_Scope::EFFECT_WRITE, $effect );
		$this->assertFalse(
			OpenclaWP_Oauth_Scope::scopes_permit_effect( $granted, $effect ),
			'mcp:read must NOT be allowed to invoke a write-effect ability'
		);
	}

	public function test_read_client_can_call_read_ability(): void {
		$granted = array( OpenclaWP_Oauth_Scope::SCOPE_READ );
		$ability = 'openclawp/get-recent-posts';
		$effect  = OpenclaWP_Oauth_Scope::effect_for_ability( $ability );
		$this->assertSame( OpenclaWP_Oauth_Scope::EFFECT_READ, $effect );
		$this->assertTrue( OpenclaWP_Oauth_Scope::scopes_permit_effect( $granted, $effect ) );
	}

	public function test_destructive_ability_blocked_for_write_scope(): void {
		$granted = array( OpenclaWP_Oauth_Scope::SCOPE_WRITE );
		$ability = 'openclawp/delete-post';
		$effect  = OpenclaWP_Oauth_Scope::effect_for_ability( $ability );
		$this->assertSame( OpenclaWP_Oauth_Scope::EFFECT_DESTRUCTIVE, $effect );
		$this->assertFalse( OpenclaWP_Oauth_Scope::scopes_permit_effect( $granted, $effect ) );
	}

	public function test_external_ability_requires_external_scope(): void {
		// `send-whatsapp` is one of the heuristic external matches.
		$ability = 'openclawp/send-whatsapp';
		$effect  = OpenclaWP_Oauth_Scope::effect_for_ability( $ability );
		$this->assertSame( OpenclaWP_Oauth_Scope::EFFECT_EXTERNAL, $effect );

		$this->assertFalse(
			OpenclaWP_Oauth_Scope::scopes_permit_effect( array( OpenclaWP_Oauth_Scope::SCOPE_DESTRUCTIVE ), $effect ),
			'mcp:destructive must not permit an external-effect tool'
		);
		$this->assertTrue(
			OpenclaWP_Oauth_Scope::scopes_permit_effect( array( OpenclaWP_Oauth_Scope::SCOPE_EXTERNAL ), $effect )
		);
	}

	public function test_parse_scope_string_filters_unknown_and_dedupes(): void {
		$result = OpenclaWP_Oauth_Scope::parse_scope_string( 'mcp:read mcp:write bogus mcp:read' );
		$this->assertSame( array( OpenclaWP_Oauth_Scope::SCOPE_READ, OpenclaWP_Oauth_Scope::SCOPE_WRITE ), $result );
	}

	public function test_parse_scope_string_empty_returns_empty_array(): void {
		$this->assertSame( array(), OpenclaWP_Oauth_Scope::parse_scope_string( '' ) );
		$this->assertSame( array(), OpenclaWP_Oauth_Scope::parse_scope_string( '   ' ) );
		$this->assertSame( array(), OpenclaWP_Oauth_Scope::parse_scope_string( 'completely:unknown other:bad' ) );
	}

	public function test_heuristic_classifies_common_verbs(): void {
		$this->assertSame( OpenclaWP_Oauth_Scope::EFFECT_READ, OpenclaWP_Oauth_Scope::heuristic_effect( 'openclawp/get-time' ) );
		$this->assertSame( OpenclaWP_Oauth_Scope::EFFECT_READ, OpenclaWP_Oauth_Scope::heuristic_effect( 'openclawp/list-comments' ) );
		$this->assertSame( OpenclaWP_Oauth_Scope::EFFECT_WRITE, OpenclaWP_Oauth_Scope::heuristic_effect( 'openclawp/create-post' ) );
		$this->assertSame( OpenclaWP_Oauth_Scope::EFFECT_WRITE, OpenclaWP_Oauth_Scope::heuristic_effect( 'openclawp/publish-page' ) );
		$this->assertSame( OpenclaWP_Oauth_Scope::EFFECT_DESTRUCTIVE, OpenclaWP_Oauth_Scope::heuristic_effect( 'openclawp/delete-post' ) );
		$this->assertSame( OpenclaWP_Oauth_Scope::EFFECT_DESTRUCTIVE, OpenclaWP_Oauth_Scope::heuristic_effect( 'openclawp/uninstall-plugin' ) );
		$this->assertSame( OpenclaWP_Oauth_Scope::EFFECT_EXTERNAL, OpenclaWP_Oauth_Scope::heuristic_effect( 'openclawp/send-whatsapp' ) );
	}
}
