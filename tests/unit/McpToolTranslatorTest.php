<?php
/**
 * Pure-PHP unit tests for OpenclaWP_Mcp_Tool_Translator.
 *
 * Exercises `translate_declarations()` — the pure-PHP helper that
 * reshapes an OpenclaWP tool-declaration array into MCP tool
 * definitions. The full path (which calls Tools_Resolver + wp_get_ability)
 * needs a registered agent and is covered by tests/smoke.php inside a
 * real WordPress.
 *
 * @package OpenclaWP\Tests
 */

declare( strict_types=1 );

namespace OpenclaWP\Tests\Unit;

use OpenclaWP_Mcp_Tool_Translator;
use PHPUnit\Framework\TestCase;

/**
 * @covers OpenclaWP_Mcp_Tool_Translator
 */
final class McpToolTranslatorTest extends TestCase {

	public function test_reshapes_declarations_into_mcp_tool_shape(): void {
		$declarations = array(
			'openclawp__get-recent-posts' => array(
				'name'        => 'openclawp__get-recent-posts',
				'source'      => 'openclawp',
				'description' => 'Returns recent posts.',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 10 ),
					),
				),
				'executor'    => 'client',
				'scope'       => 'run',
			),
		);

		$tools = OpenclaWP_Mcp_Tool_Translator::translate_declarations( $declarations );

		$this->assertCount( 1, $tools );
		$this->assertSame( 'openclawp__get-recent-posts', $tools[0]['name'] );
		$this->assertSame( 'Returns recent posts.', $tools[0]['description'] );
		$this->assertSame( 'object', $tools[0]['inputSchema']['type'] );
		$this->assertArrayHasKey( 'limit', $tools[0]['inputSchema']['properties'] );
	}

	public function test_returns_empty_when_no_declarations(): void {
		$this->assertSame( array(), OpenclaWP_Mcp_Tool_Translator::translate_declarations( array() ) );
	}

	public function test_allowlist_filters_declarations(): void {
		$declarations = array(
			'a' => array( 'name' => 'a', 'description' => 'A', 'parameters' => array( 'type' => 'object' ) ),
			'b' => array( 'name' => 'b', 'description' => 'B', 'parameters' => array( 'type' => 'object' ) ),
			'c' => array( 'name' => 'c', 'description' => 'C', 'parameters' => array( 'type' => 'object' ) ),
		);

		$tools = OpenclaWP_Mcp_Tool_Translator::translate_declarations( $declarations, array( 'a', 'c' ) );

		$this->assertCount( 2, $tools );
		$names = array_column( $tools, 'name' );
		$this->assertContains( 'a', $names );
		$this->assertContains( 'c', $names );
		$this->assertNotContains( 'b', $names );
	}

	public function test_empty_allowlist_includes_all(): void {
		$declarations = array(
			'a' => array( 'name' => 'a', 'description' => '', 'parameters' => array( 'type' => 'object' ) ),
			'b' => array( 'name' => 'b', 'description' => '', 'parameters' => array( 'type' => 'object' ) ),
		);
		$tools = OpenclaWP_Mcp_Tool_Translator::translate_declarations( $declarations, array() );
		$this->assertCount( 2, $tools );
	}

	public function test_unnamed_declarations_are_skipped(): void {
		$declarations = array(
			'good' => array( 'name' => 'good', 'description' => '', 'parameters' => array( 'type' => 'object' ) ),
			'bad'  => array( 'description' => 'no name', 'parameters' => array( 'type' => 'object' ) ),
		);
		$tools = OpenclaWP_Mcp_Tool_Translator::translate_declarations( $declarations );
		$this->assertCount( 1, $tools );
		$this->assertSame( 'good', $tools[0]['name'] );
	}

	public function test_missing_parameters_falls_back_to_empty_object_schema(): void {
		$declarations = array(
			'a' => array( 'name' => 'a', 'description' => 'A' ),
		);
		$tools = OpenclaWP_Mcp_Tool_Translator::translate_declarations( $declarations );
		$this->assertCount( 1, $tools );
		$this->assertSame( array( 'type' => 'object', 'properties' => array() ), $tools[0]['inputSchema'] );
	}

	public function test_subagent_delegate_declaration_round_trips(): void {
		// Coordinator agents expose subagents as `delegate-to-<slug>` tools.
		// The translator must surface them just like ability tools.
		$declarations = array(
			'delegate-to-openclawp-site-introspection' => array(
				'name'        => 'delegate-to-openclawp-site-introspection',
				'source'      => 'openclawp',
				'description' => 'Delegate to subagent openclaWP Site Introspection.',
				'parameters'  => array(
					'type'       => 'object',
					'required'   => array( 'prompt' ),
					'properties' => array(
						'prompt' => array( 'type' => 'string' ),
					),
				),
			),
		);
		$tools = OpenclaWP_Mcp_Tool_Translator::translate_declarations( $declarations );
		$this->assertCount( 1, $tools );
		$this->assertSame( 'delegate-to-openclawp-site-introspection', $tools[0]['name'] );
		$this->assertSame( array( 'prompt' ), $tools[0]['inputSchema']['required'] );
	}
}
