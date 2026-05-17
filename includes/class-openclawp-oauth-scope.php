<?php
/**
 * Scope <-> effect mapping for the openclaWP OAuth 2.1 MCP server.
 *
 * MCP 2025-06-18 specifies OAuth 2.1; openclaWP layers scopes on top of the
 * effect taxonomy from issue #40 (`read`, `write`, `destructive`, `external`).
 *
 * Each scope grants the union of effects at or below its tier:
 *
 *   mcp:read         -> read
 *   mcp:write        -> read, write
 *   mcp:destructive  -> read, write, destructive
 *   mcp:external     -> read, write, destructive, external
 *
 * Assumed API contract from #40: an ability declares its effect via either
 *   - `ability->get_meta('effect')`, or
 *   - the `openclawp_ability_effect` filter, falling back to a heuristic on
 *     the ability name (`delete-*`/`destroy-*` -> destructive,
 *     `update-*`/`create-*`/`send-*` -> write, `*-external`/`*-remote`
 *     -> external, everything else -> read).
 *
 * Pure functions only — easy to unit-test, no WP/DB calls in the hot path.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Oauth_Scope {

	public const EFFECT_READ        = 'read';
	public const EFFECT_WRITE       = 'write';
	public const EFFECT_DESTRUCTIVE = 'destructive';
	public const EFFECT_EXTERNAL    = 'external';

	public const SCOPE_READ        = 'mcp:read';
	public const SCOPE_WRITE       = 'mcp:write';
	public const SCOPE_DESTRUCTIVE = 'mcp:destructive';
	public const SCOPE_EXTERNAL    = 'mcp:external';

	/**
	 * @return array<string, array<int, string>> scope -> list of permitted effects
	 */
	public static function scope_effect_map(): array {
		return array(
			self::SCOPE_READ        => array( self::EFFECT_READ ),
			self::SCOPE_WRITE       => array( self::EFFECT_READ, self::EFFECT_WRITE ),
			self::SCOPE_DESTRUCTIVE => array( self::EFFECT_READ, self::EFFECT_WRITE, self::EFFECT_DESTRUCTIVE ),
			self::SCOPE_EXTERNAL    => array( self::EFFECT_READ, self::EFFECT_WRITE, self::EFFECT_DESTRUCTIVE, self::EFFECT_EXTERNAL ),
		);
	}

	/**
	 * @return array<int, string>
	 */
	public static function all_scopes(): array {
		return array_keys( self::scope_effect_map() );
	}

	/**
	 * Decide whether a token holding `$granted_scopes` may invoke a tool whose
	 * effect is `$effect`. A token is allowed iff at least one of its scopes
	 * grants the effect.
	 *
	 * @param array<int, string> $granted_scopes List of scope strings.
	 */
	public static function scopes_permit_effect( array $granted_scopes, string $effect ): bool {
		if ( '' === $effect ) {
			// Conservative default — refuse rather than over-grant.
			return false;
		}
		$map = self::scope_effect_map();
		foreach ( $granted_scopes as $scope ) {
			$allowed = $map[ $scope ] ?? array();
			if ( in_array( $effect, $allowed, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Parse a space-delimited scope string into a deduplicated, validated list.
	 *
	 * Unknown scopes are dropped. Empty string returns an empty array. The
	 * order is preserved by first appearance — useful when the admin page
	 * renders the list.
	 *
	 * @return array<int, string>
	 */
	public static function parse_scope_string( string $scope_string ): array {
		$tokens = preg_split( '/\s+/', trim( $scope_string ) );
		if ( ! is_array( $tokens ) ) {
			return array();
		}
		$valid = self::all_scopes();
		$out   = array();
		foreach ( $tokens as $token ) {
			if ( '' === $token ) {
				continue;
			}
			if ( ! in_array( $token, $valid, true ) ) {
				continue;
			}
			if ( in_array( $token, $out, true ) ) {
				continue;
			}
			$out[] = $token;
		}
		return $out;
	}

	/**
	 * Resolve the effect of an ability by name. Wraps the `openclawp_ability_effect`
	 * filter (set by #40 once it lands) with a name-based heuristic fallback so
	 * the scope layer is safe to run before #40 merges.
	 *
	 * @return string One of self::EFFECT_*.
	 */
	public static function effect_for_ability( string $ability_name ): string {
		/**
		 * Filters the resolved effect for an ability.
		 *
		 * Issue #40 will populate this filter directly from `wp_register_ability()`
		 * metadata. Until then, this filter is invoked with the heuristic value so
		 * callers can override per ability.
		 *
		 * @param string $effect       Heuristic effect.
		 * @param string $ability_name Fully namespaced ability name (e.g. openclawp/get-time).
		 */
		$heuristic = self::heuristic_effect( $ability_name );
		$filtered  = apply_filters( 'openclawp_ability_effect', $heuristic, $ability_name );
		if ( ! is_string( $filtered ) || '' === $filtered ) {
			return $heuristic;
		}
		$valid = array(
			self::EFFECT_READ,
			self::EFFECT_WRITE,
			self::EFFECT_DESTRUCTIVE,
			self::EFFECT_EXTERNAL,
		);
		return in_array( $filtered, $valid, true ) ? $filtered : $heuristic;
	}

	/**
	 * Name-based heuristic for the ability effect tier. Conservative: anything
	 * that smells like a write or external call ends up gated by the matching
	 * scope.
	 */
	public static function heuristic_effect( string $ability_name ): string {
		$lower = strtolower( $ability_name );

		// Destructive verbs — most explicit, check first.
		foreach ( array( 'delete', 'destroy', 'remove', 'drop', 'purge', 'truncate', 'uninstall', 'deactivate' ) as $verb ) {
			if ( false !== strpos( $lower, $verb ) ) {
				return self::EFFECT_DESTRUCTIVE;
			}
		}

		// External-side-effect verbs (network calls to non-WP systems).
		foreach ( array( 'send-whatsapp', 'send-sms', 'send-email', 'send-telegram', 'charge-', 'webhook-', 'fetch-remote', 'external' ) as $verb ) {
			if ( false !== strpos( $lower, $verb ) ) {
				return self::EFFECT_EXTERNAL;
			}
		}

		// Write verbs.
		foreach ( array( 'create', 'update', 'edit', 'save', 'insert', 'post-', 'publish', 'install' ) as $verb ) {
			if ( false !== strpos( $lower, $verb ) ) {
				return self::EFFECT_WRITE;
			}
		}

		// Default: read.
		return self::EFFECT_READ;
	}
}
