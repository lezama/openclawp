<?php
/**
 * Pure-PHP unit tests for OpenclaWP_Mcp_Client_Bridge::plan_ability_list().
 *
 * Verifies that bridged MCP tools surface in the ability registry under the
 * `mcp/<server>/<tool>` prefix, that the per-tool allowlist + disabled-list
 * are honoured, and that server-native tool names are sanitised into
 * URL-safe path segments without losing the original (the original is what
 * we send back to the server in tools/call).
 *
 * The plan_ability_list() helper is pure-PHP — no DB, no WP, no transport —
 * so we can exercise it without standing up WordPress. The full path that
 * actually calls wp_register_ability() needs a registered abilities API and
 * is covered by tests/smoke.php.
 *
 * @package OpenclaWP\Tests
 */

declare( strict_types=1 );

namespace OpenclaWP\Tests\Unit;

use OpenclaWP_Mcp_Client_Bridge;
use OpenclaWP_Mcp_Client_Store;
use PHPUnit\Framework\TestCase;

/**
 * @covers OpenclaWP_Mcp_Client_Bridge
 */
final class McpClientBridgeTest extends TestCase {

	public function test_registers_under_mcp_prefix_with_server_slug(): void {
		$cached = array(
			array(
				'name'        => 'fetch',
				'description' => 'Fetch a URL.',
				'inputSchema' => array( 'type' => 'object', 'properties' => array( 'url' => array( 'type' => 'string' ) ) ),
			),
		);
		$config = OpenclaWP_Mcp_Client_Store::sanitize_config( array() );

		$plan = OpenclaWP_Mcp_Client_Bridge::plan_ability_list( $cached, $config, 'fetch' );

		$this->assertCount( 1, $plan );
		$this->assertSame( 'mcp/fetch/fetch', $plan[0]['ability_name'] );
		$this->assertStringStartsWith( OpenclaWP_Mcp_Client_Bridge::ABILITY_PREFIX, $plan[0]['ability_name'] );
		// Server-native tool name (unchanged) is preserved so the executor
		// can pass it back to tools/call verbatim.
		$this->assertSame( 'fetch', $plan[0]['tool_name'] );
		$this->assertSame( 'Fetch a URL.', $plan[0]['description'] );
		$this->assertSame( 'object', $plan[0]['input_schema']['type'] );
	}

	public function test_multiple_tools_all_share_mcp_prefix(): void {
		$cached = array(
			array( 'name' => 'list_repos', 'description' => '', 'inputSchema' => array( 'type' => 'object' ) ),
			array( 'name' => 'create_issue', 'description' => '', 'inputSchema' => array( 'type' => 'object' ) ),
			array( 'name' => 'comment_on_issue', 'description' => '', 'inputSchema' => array( 'type' => 'object' ) ),
		);
		$config = OpenclaWP_Mcp_Client_Store::sanitize_config( array() );

		$plan = OpenclaWP_Mcp_Client_Bridge::plan_ability_list( $cached, $config, 'github' );

		$names = array_column( $plan, 'ability_name' );
		$this->assertCount( 3, $names );
		foreach ( $names as $name ) {
			$this->assertStringStartsWith( 'mcp/github/', $name, 'all bridged tools must live under the mcp/<server>/ prefix' );
		}
		$this->assertContains( 'mcp/github/list_repos', $names );
		$this->assertContains( 'mcp/github/create_issue', $names );
		$this->assertContains( 'mcp/github/comment_on_issue', $names );
	}

	public function test_disabled_tools_are_skipped(): void {
		$cached = array(
			array( 'name' => 'fetch', 'description' => '', 'inputSchema' => array( 'type' => 'object' ) ),
			array( 'name' => 'fetch_async', 'description' => '', 'inputSchema' => array( 'type' => 'object' ) ),
		);
		$config             = OpenclaWP_Mcp_Client_Store::sanitize_config( array() );
		$config['disabled'] = array( 'fetch_async' );

		$plan = OpenclaWP_Mcp_Client_Bridge::plan_ability_list( $cached, $config, 'fetch' );

		$this->assertCount( 1, $plan );
		$this->assertSame( 'fetch', $plan[0]['tool_name'] );
	}

	public function test_allowlist_restricts_registered_tools(): void {
		$cached = array(
			array( 'name' => 'a', 'description' => '', 'inputSchema' => array( 'type' => 'object' ) ),
			array( 'name' => 'b', 'description' => '', 'inputSchema' => array( 'type' => 'object' ) ),
			array( 'name' => 'c', 'description' => '', 'inputSchema' => array( 'type' => 'object' ) ),
		);
		$config              = OpenclaWP_Mcp_Client_Store::sanitize_config( array() );
		$config['allowlist'] = array( 'a', 'c' );

		$plan  = OpenclaWP_Mcp_Client_Bridge::plan_ability_list( $cached, $config, 'srv' );
		$names = array_column( $plan, 'tool_name' );

		$this->assertCount( 2, $plan );
		$this->assertContains( 'a', $names );
		$this->assertContains( 'c', $names );
		$this->assertNotContains( 'b', $names );
	}

	public function test_tool_names_with_unsafe_chars_are_sanitized_in_ability_path(): void {
		$cached = array(
			array( 'name' => 'Fetch URL', 'description' => '', 'inputSchema' => array( 'type' => 'object' ) ),
			array( 'name' => 'list/issues', 'description' => '', 'inputSchema' => array( 'type' => 'object' ) ),
		);
		$config = OpenclaWP_Mcp_Client_Store::sanitize_config( array() );

		$plan = OpenclaWP_Mcp_Client_Bridge::plan_ability_list( $cached, $config, 'github' );

		$this->assertCount( 2, $plan );
		$abilities = array_column( $plan, 'ability_name' );
		$this->assertContains( 'mcp/github/fetch-url', $abilities );
		$this->assertContains( 'mcp/github/list-issues', $abilities );

		// The original (un-sanitised) tool_name round-trips so it can be sent
		// back to the MCP server in tools/call.
		$tool_names = array_column( $plan, 'tool_name' );
		$this->assertContains( 'Fetch URL', $tool_names );
		$this->assertContains( 'list/issues', $tool_names );
	}

	public function test_unnamed_tools_are_skipped(): void {
		$cached = array(
			array( 'name' => 'ok', 'description' => '', 'inputSchema' => array( 'type' => 'object' ) ),
			array( 'description' => 'missing-name', 'inputSchema' => array( 'type' => 'object' ) ),
			array( 'name' => '', 'description' => 'empty-name', 'inputSchema' => array( 'type' => 'object' ) ),
		);
		$config = OpenclaWP_Mcp_Client_Store::sanitize_config( array() );

		$plan = OpenclaWP_Mcp_Client_Bridge::plan_ability_list( $cached, $config, 'srv' );
		$this->assertCount( 1, $plan );
		$this->assertSame( 'ok', $plan[0]['tool_name'] );
	}

	public function test_empty_server_slug_returns_empty_plan(): void {
		$cached = array(
			array( 'name' => 'fetch', 'description' => '', 'inputSchema' => array( 'type' => 'object' ) ),
		);
		$config = OpenclaWP_Mcp_Client_Store::sanitize_config( array() );

		$plan = OpenclaWP_Mcp_Client_Bridge::plan_ability_list( $cached, $config, '' );
		$this->assertSame( array(), $plan );
	}

	public function test_sanitize_tool_segment_lowercases_and_strips_unsafe_chars(): void {
		$this->assertSame( 'foo-bar', OpenclaWP_Mcp_Client_Bridge::sanitize_tool_segment( 'Foo Bar' ) );
		$this->assertSame( 'foo-bar', OpenclaWP_Mcp_Client_Bridge::sanitize_tool_segment( 'foo/bar' ) );
		$this->assertSame( 'foo_bar', OpenclaWP_Mcp_Client_Bridge::sanitize_tool_segment( 'foo_bar' ) );
		$this->assertSame( 'a1b2', OpenclaWP_Mcp_Client_Bridge::sanitize_tool_segment( 'a1b2' ) );
	}

	public function test_normalize_tools_list_handles_real_server_response_shape(): void {
		// Shape mirrors a real MCP `tools/list` response from `@modelcontextprotocol/server-fetch`.
		$response = array(
			'tools' => array(
				array(
					'name'        => 'fetch',
					'description' => 'Fetches a URL from the internet and extracts its contents as markdown.',
					'inputSchema' => array(
						'type'       => 'object',
						'required'   => array( 'url' ),
						'properties' => array(
							'url'    => array( 'type' => 'string', 'description' => 'URL to fetch' ),
							'max_length' => array( 'type' => 'integer', 'default' => 5000 ),
						),
					),
				),
			),
		);

		$normalized = \OpenclaWP_Mcp_Client_Transport::normalize_tools_list( $response );
		$this->assertCount( 1, $normalized );
		$this->assertSame( 'fetch', $normalized[0]['name'] );

		// Round-trip through the bridge: a normalized tool should produce an
		// `mcp/<slug>/<tool>` ability.
		$plan = OpenclaWP_Mcp_Client_Bridge::plan_ability_list(
			$normalized,
			OpenclaWP_Mcp_Client_Store::sanitize_config( array() ),
			'fetch'
		);
		$this->assertSame( 'mcp/fetch/fetch', $plan[0]['ability_name'] );
		$this->assertSame( 'object', $plan[0]['input_schema']['type'] );
	}
}
