<?php
/**
 * Parity test: tools exposed by the legacy JSON-RPC `tools/list` path must
 * also be exposed (with the same name + inputSchema) by the adapter shim.
 *
 * The two paths consume the same `OpenclaWP_Tools_Resolver::for_agent()`
 * output but project it through different helpers — the legacy translator
 * produces MCP `tools/list` entries directly, while the adapter shim
 * projects to (ability_name, MCP tool) pairs that the official mcp-adapter
 * then exposes. This test asserts the projections agree on the public
 * contract: tool name + description + inputSchema.
 *
 * Both helpers are pure: they accept the resolved declarations array and
 * an optional allowlist. No real WordPress, no registered agent, no live
 * resolver call.
 *
 * @package OpenclaWP\Tests
 */

declare( strict_types=1 );

namespace OpenclaWP\Tests\Unit;

use OpenclaWP_Mcp_Adapter;
use OpenclaWP_Mcp_Tool_Translator;
use PHPUnit\Framework\TestCase;

/**
 * @covers OpenclaWP_Mcp_Adapter
 * @covers OpenclaWP_Mcp_Tool_Translator
 */
final class McpAdapterParityTest extends TestCase {

	/**
	 * Resolver-shaped fixture exercising both ability tools and a
	 * `delegate-to-<subagent>` synthetic tool — the two code paths the
	 * adapter shim has to keep in sync with the legacy translator.
	 *
	 * @return array{
	 *     declarations: array<string, array{name:string, description:string, parameters:array}>,
	 *     declarations_for_provider: array,
	 *     name_to_ability: array<string, string>,
	 *     delegate_targets: array<string, string>,
	 * }
	 */
	private function resolved_fixture(): array {
		return array(
			'declarations'              => array(
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
				'openclawp__count-comments' => array(
					'name'        => 'openclawp__count-comments',
					'source'      => 'openclawp',
					'description' => 'Counts comments site-wide.',
					'parameters'  => array( 'type' => 'object', 'properties' => array() ),
					'executor'    => 'client',
					'scope'       => 'run',
				),
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
					'executor'    => 'client',
					'scope'       => 'run',
				),
			),
			'declarations_for_provider' => array(),
			'name_to_ability'           => array(
				'openclawp__get-recent-posts' => 'openclawp/get-recent-posts',
				'openclawp__count-comments'   => 'openclawp/count-comments',
			),
			'delegate_targets'          => array(
				'delegate-to-openclawp-site-introspection' => 'openclawp-site-introspection',
			),
		);
	}

	public function test_adapter_exposes_same_tool_names_as_legacy(): void {
		$resolved = $this->resolved_fixture();

		$legacy   = OpenclaWP_Mcp_Tool_Translator::translate_declarations( $resolved['declarations'] );
		$adapter  = OpenclaWP_Mcp_Adapter::project_resolved_for_adapter( $resolved );

		$legacy_names  = array_column( $legacy, 'name' );
		$adapter_names = array_column( $adapter['tools'], 'name' );

		sort( $legacy_names );
		sort( $adapter_names );

		$this->assertSame(
			$legacy_names,
			$adapter_names,
			'Adapter path must expose the same tool names as the legacy translator.'
		);
	}

	public function test_adapter_input_schemas_match_legacy(): void {
		$resolved = $this->resolved_fixture();

		$legacy  = OpenclaWP_Mcp_Tool_Translator::translate_declarations( $resolved['declarations'] );
		$adapter = OpenclaWP_Mcp_Adapter::project_resolved_for_adapter( $resolved );

		$legacy_by_name = array();
		foreach ( $legacy as $tool ) {
			$legacy_by_name[ $tool['name'] ] = $tool;
		}

		foreach ( $adapter['tools'] as $tool ) {
			$this->assertArrayHasKey( $tool['name'], $legacy_by_name, sprintf( 'Adapter surfaced %s but legacy did not.', $tool['name'] ) );
			$this->assertSame(
				$legacy_by_name[ $tool['name'] ]['inputSchema'],
				$tool['inputSchema'],
				sprintf( 'inputSchema mismatch on tool `%s`.', $tool['name'] )
			);
			$this->assertSame(
				$legacy_by_name[ $tool['name'] ]['description'],
				$tool['description'],
				sprintf( 'description mismatch on tool `%s`.', $tool['name'] )
			);
		}
	}

	public function test_adapter_resolves_ability_names_for_each_tool(): void {
		$resolved = $this->resolved_fixture();
		$adapter  = OpenclaWP_Mcp_Adapter::project_resolved_for_adapter( $resolved );

		$abilities_by_tool = array();
		foreach ( $adapter['tools'] as $tool ) {
			$abilities_by_tool[ $tool['name'] ] = $tool['ability'];
		}

		$this->assertSame( 'openclawp/get-recent-posts', $abilities_by_tool['openclawp__get-recent-posts'] );
		$this->assertSame( 'openclawp/count-comments', $abilities_by_tool['openclawp__count-comments'] );
		// Delegate ability names are server-scoped — assert the namespace + subagent segment.
		$this->assertStringStartsWith(
			OpenclaWP_Mcp_Adapter::DELEGATE_ABILITY_PREFIX,
			$abilities_by_tool['delegate-to-openclawp-site-introspection']
		);
		$this->assertStringContainsString(
			'openclawp-site-introspection',
			$abilities_by_tool['delegate-to-openclawp-site-introspection']
		);
	}

	public function test_allowlist_filters_both_paths_identically(): void {
		$resolved  = $this->resolved_fixture();
		$allowlist = array( 'openclawp__count-comments', 'delegate-to-openclawp-site-introspection' );

		$legacy_names  = array_column(
			OpenclaWP_Mcp_Tool_Translator::translate_declarations( $resolved['declarations'], $allowlist ),
			'name'
		);
		$adapter_names = array_column(
			OpenclaWP_Mcp_Adapter::project_resolved_for_adapter( $resolved, $allowlist )['tools'],
			'name'
		);

		sort( $legacy_names );
		sort( $adapter_names );

		$this->assertSame(
			array( 'delegate-to-openclawp-site-introspection', 'openclawp__count-comments' ),
			$legacy_names
		);
		$this->assertSame( $legacy_names, $adapter_names );
	}

	public function test_adapter_returns_unique_ability_names(): void {
		// Two declarations resolving to the same ability (shouldn't happen
		// in practice but is permitted by the resolver shape) should be
		// deduped in the adapter projection.
		$resolved = array(
			'declarations'     => array(
				'a' => array( 'name' => 'a', 'description' => '', 'parameters' => array( 'type' => 'object' ) ),
				'b' => array( 'name' => 'b', 'description' => '', 'parameters' => array( 'type' => 'object' ) ),
			),
			'name_to_ability'  => array(
				'a' => 'openclawp/same',
				'b' => 'openclawp/same',
			),
			'delegate_targets' => array(),
		);

		$adapter = OpenclaWP_Mcp_Adapter::project_resolved_for_adapter( $resolved );

		$this->assertSame( array( 'openclawp/same' ), $adapter['abilities'] );
		$this->assertCount( 2, $adapter['tools'], 'tools list keeps both declared names; only abilities[] dedupes.' );
	}
}
