<?php
/**
 * Translate an agent's tool surface into the MCP `tools/list` shape.
 *
 * Reuses `OpenclaWP_Tools_Resolver::for_agent()` to get the agent's
 * declared abilities + delegate-to-subagent declarations, then reshapes
 * each into `{ name, description, inputSchema }`. The sanitized tool
 * names (provider-safe — `/` → `__`) round-trip cleanly through
 * `tools/call`, where `OpenclaWP_Tool_Executor` resolves them back to
 * the underlying ability via the same `name_to_ability` map.
 *
 * Pure function — no DB access, no side effects. Easy to unit-test.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Mcp_Tool_Translator {

	/**
	 * Translate an agent's tool declarations into MCP tool definitions.
	 *
	 * @param WP_Agent       $agent     The agent whose surface to expose.
	 * @param array<int,string> $allowlist Optional: restrict to this subset of
	 *                                  sanitized tool names. Empty = all.
	 *
	 * @return array<int, array{name:string, description:string, inputSchema:array}>
	 */
	public static function translate( WP_Agent $agent, array $allowlist = array() ): array {
		$resolved     = OpenclaWP_Tools_Resolver::for_agent( $agent );
		$declarations = (array) ( $resolved['declarations'] ?? array() );
		return self::translate_declarations( $declarations, $allowlist );
	}

	/**
	 * Pure helper: reshape an array of OpenclaWP tool declarations into
	 * MCP tool definitions. Extracted from `translate()` so it's directly
	 * unit-testable without a registered agent.
	 *
	 * @param array<string|int, array<string, mixed>> $declarations Output of
	 *        `OpenclaWP_Tools_Resolver::for_agent()`'s `declarations` key.
	 * @param array<int, string>                      $allowlist Optional name
	 *        filter (empty = include all).
	 *
	 * @return array<int, array{name:string, description:string, inputSchema:array}>
	 */
	public static function translate_declarations( array $declarations, array $allowlist = array() ): array {
		$tools = array();
		foreach ( $declarations as $declaration ) {
			if ( ! is_array( $declaration ) ) {
				continue;
			}
			$name = (string) ( $declaration['name'] ?? '' );
			if ( '' === $name ) {
				continue;
			}
			if ( ! empty( $allowlist ) && ! in_array( $name, $allowlist, true ) ) {
				continue;
			}

			$tools[] = array(
				'name'        => $name,
				'description' => (string) ( $declaration['description'] ?? '' ),
				'inputSchema' => is_array( $declaration['parameters'] ?? null )
					? $declaration['parameters']
					: array( 'type' => 'object', 'properties' => array() ),
			);
		}
		return $tools;
	}
}
