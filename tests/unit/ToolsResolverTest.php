<?php
/**
 * Regression tests for OpenclaWP_Tools_Resolver tool-name handling.
 *
 * Guards the contract that the agents-api conversation loop enforces on client
 * tool declarations: the name must match `^[a-z][a-z0-9_-]*\/[a-z][a-z0-9_-]*$`
 * and the segment before the slash (its derived "source") must equal `client`
 * (see WP_Agent_Tool_Declaration::validate()). When openclaWP handed the loop
 * provider-sanitized names like `openclawp__get-recent-posts` (no slash, source
 * `openclawp`), every declaration was dropped, `mediation_enabled` flipped to
 * false, and tools were never executed — the loop ran one turn and returned an
 * empty reply. `loop_name()` puts the name into `client/<sanitized>` form; the
 * runner maps the model's tool calls back through it.
 *
 * @package OpenclaWP\Tests
 */

declare( strict_types=1 );

namespace OpenclaWP\Tests\Unit;

use OpenclaWP_Tools_Resolver;
use PHPUnit\Framework\TestCase;

/**
 * The exact pattern agents-api validates client tool declaration names against.
 */
const AGENTS_API_CLIENT_TOOL_NAME = '/^[a-z][a-z0-9_-]*\/[a-z][a-z0-9_-]*$/';

/**
 * @covers OpenclaWP_Tools_Resolver
 */
final class ToolsResolverTest extends TestCase {

	public function test_loop_name_prefixes_with_client_source(): void {
		$this->assertSame( 'client/openclawp__get-recent-posts', OpenclaWP_Tools_Resolver::loop_name( 'openclawp__get-recent-posts' ) );
	}

	public function test_loop_name_is_idempotent(): void {
		$once  = OpenclaWP_Tools_Resolver::loop_name( 'openclawp__get-time' );
		$twice = OpenclaWP_Tools_Resolver::loop_name( $once );
		$this->assertSame( $once, $twice );
	}

	public function test_provider_name_strips_the_client_prefix(): void {
		$this->assertSame( 'openclawp__get-recent-posts', OpenclaWP_Tools_Resolver::provider_name( 'client/openclawp__get-recent-posts' ) );
	}

	public function test_loop_name_and_provider_name_round_trip(): void {
		foreach ( array( 'openclawp__get-recent-posts', 'a2a__siteb', 'delegate-to-openclawp-loop-demo' ) as $declared ) {
			$loop = OpenclaWP_Tools_Resolver::loop_name( $declared );
			$this->assertSame( $declared, OpenclaWP_Tools_Resolver::provider_name( $loop ) );
		}
	}

	/**
	 * The core regression guard: every name openclaWP hands the loop must
	 * satisfy the agents-api client-tool pattern AND derive source `client`.
	 */
	public function test_loop_names_satisfy_agents_api_client_tool_contract(): void {
		$abilities = array(
			'openclawp/get-recent-posts',
			'openclawp/count-comments',
			'openclawp/get-active-plugins',
			'a2a/siteb',
			'delegate-to-openclawp-loop-demo', // subagent declared names have no slash pre-sanitize
		);

		foreach ( $abilities as $ability ) {
			$declared = OpenclaWP_Tools_Resolver::sanitize_name( $ability );
			$loop     = OpenclaWP_Tools_Resolver::loop_name( $declared );

			$this->assertMatchesRegularExpression(
				AGENTS_API_CLIENT_TOOL_NAME,
				$loop,
				sprintf( 'loop name "%s" must match the agents-api client-tool pattern', $loop )
			);

			// Source = segment before the slash; the loop requires it to be "client".
			$source = explode( '/', $loop, 2 )[0];
			$this->assertSame( 'client', $source, 'derived source must be "client" so the declaration is not dropped' );
		}
	}

	public function test_sanitize_name_lowercases_and_replaces_slash(): void {
		$this->assertSame( 'myplugin__dothing', OpenclaWP_Tools_Resolver::sanitize_name( 'MyPlugin/DoThing' ) );
		$this->assertSame( 'openclawp__get-recent-posts', OpenclaWP_Tools_Resolver::sanitize_name( 'openclawp/get-recent-posts' ) );
	}
}
