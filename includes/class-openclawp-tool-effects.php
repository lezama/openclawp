<?php
/**
 * Effect tagging for tool calls + confirmation-threshold gating.
 *
 * "Effect" is the side-effect classification of an ability. The agent runtime
 * uses it to decide whether a tool call needs human confirmation. The values
 * are taken straight from the issue (#40):
 *
 *   - read        — pure-read ability. Never gated.
 *   - write       — mutates site state. Gated under threshold `write`.
 *   - destructive — irreversible mutation (delete, drop, uninstall). Gated
 *                   under threshold `destructive` (the default).
 *   - external    — hits a non-WordPress system (HTTP API, WhatsApp send,
 *                   payment gateway). Gated under threshold `external`.
 *
 * This file is the canonical API surface for effect tagging. Other consumers
 * — the MCP bridge (#38), the OAuth scope mapper (#45) — should read effects
 * via `OpenclaWP_Tool_Effects::for_ability( $ability_name )` rather than
 * inventing their own. The `openclawp_ability_effect` filter is the single
 * extension point.
 *
 * Resolution order, highest priority first:
 *   1. The `openclawp_ability_effect` filter (callers may override).
 *   2. An explicit `effect` key on the ability's `meta` array (set at
 *      registration time via `wp_register_ability( $name, [ 'meta' =>
 *      [ 'effect' => 'destructive' ] ] )`).
 *   3. Heuristic from the ability name: anything beginning with `delete-`,
 *      `remove-`, `drop-`, `uninstall-` is `destructive`; anything beginning
 *      with `create-`, `update-`, `set-`, `send-`, `install-`, `enable-`,
 *      `disable-` is `write`; anything beginning with `get-`, `list-`,
 *      `count-`, `read-` is `read`. Falls through to `write` (conservative).
 *
 * The heuristic is intentionally conservative — unknown abilities default
 * to `write`, not `read`, so the safety net is closed by default.
 *
 * @package OpenclaWP
 * @since   0.8.0
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Tool_Effects {

	public const EFFECT_READ        = 'read';
	public const EFFECT_WRITE       = 'write';
	public const EFFECT_DESTRUCTIVE = 'destructive';
	public const EFFECT_EXTERNAL    = 'external';

	public const THRESHOLD_NONE        = 'none';
	public const THRESHOLD_DESTRUCTIVE = 'destructive';
	public const THRESHOLD_WRITE       = 'write';
	public const THRESHOLD_EXTERNAL    = 'external';

	public const DEFAULT_THRESHOLD = self::THRESHOLD_DESTRUCTIVE;

	/**
	 * All valid effect tokens. Anything outside this set is coerced to
	 * `write` by {@see self::normalize()}.
	 *
	 * @return array<int,string>
	 */
	public static function valid_effects(): array {
		return array(
			self::EFFECT_READ,
			self::EFFECT_WRITE,
			self::EFFECT_DESTRUCTIVE,
			self::EFFECT_EXTERNAL,
		);
	}

	/**
	 * All valid confirmation-threshold tokens.
	 *
	 * @return array<int,string>
	 */
	public static function valid_thresholds(): array {
		return array(
			self::THRESHOLD_NONE,
			self::THRESHOLD_DESTRUCTIVE,
			self::THRESHOLD_WRITE,
			self::THRESHOLD_EXTERNAL,
		);
	}

	/**
	 * Coerce arbitrary input into a known effect token.
	 *
	 * Unknown/empty values become `write` — the conservative default. This
	 * matches the "safety net is closed by default" stance.
	 */
	public static function normalize( $value ): string {
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
		if ( in_array( $value, self::valid_effects(), true ) ) {
			return $value;
		}
		return self::EFFECT_WRITE;
	}

	/**
	 * Coerce arbitrary input into a known threshold token.
	 *
	 * Unknown/empty values fall back to {@see self::DEFAULT_THRESHOLD}.
	 */
	public static function normalize_threshold( $value ): string {
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
		if ( in_array( $value, self::valid_thresholds(), true ) ) {
			return $value;
		}
		return self::DEFAULT_THRESHOLD;
	}

	/**
	 * Resolve the effect for a registered ability.
	 *
	 * The filter takes precedence so adopters can override on a per-ability
	 * basis without modifying the registration site (useful for abilities
	 * registered by other plugins).
	 *
	 * @param string $ability_name Fully namespaced ability name (e.g. `openclawp/delete-post`).
	 *
	 * @return string One of {@see self::valid_effects()}.
	 */
	public static function for_ability( string $ability_name ): string {
		$default = self::guess_from_meta( $ability_name );

		/**
		 * Filters the effect classification of an ability.
		 *
		 * Consumers of openclaWP can return one of:
		 *   - 'read'        — no confirmation, ever.
		 *   - 'write'       — gated when the threshold is 'write' or stricter.
		 *   - 'destructive' — gated when the threshold is 'destructive' or stricter (default).
		 *   - 'external'    — gated when the threshold is 'external'.
		 *
		 * @since 0.8.0
		 *
		 * @param string $effect       Default effect (from ability meta or name heuristic).
		 * @param string $ability_name Fully namespaced ability name.
		 */
		$effect = apply_filters( 'openclawp_ability_effect', $default, $ability_name );

		return self::normalize( $effect );
	}

	/**
	 * Heuristic effect resolver — explicit ability meta first, then a
	 * name-prefix guess.
	 */
	private static function guess_from_meta( string $ability_name ): string {
		if ( function_exists( 'wp_get_ability' ) ) {
			$ability = wp_get_ability( $ability_name );
			if ( null !== $ability && method_exists( $ability, 'get_meta' ) ) {
				$meta = $ability->get_meta();
				if ( is_array( $meta ) && isset( $meta['effect'] ) ) {
					return self::normalize( $meta['effect'] );
				}
			}
		}

		return self::guess_from_name( $ability_name );
	}

	/**
	 * Pure helper — guess the effect from the ability name suffix.
	 *
	 * Exposed for unit tests; production callers should go through
	 * {@see self::for_ability()} so the filter pipeline runs.
	 */
	public static function guess_from_name( string $ability_name ): string {
		$short = $ability_name;
		$slash = strrpos( $ability_name, '/' );
		if ( false !== $slash ) {
			$short = substr( $ability_name, $slash + 1 );
		}
		$short = strtolower( $short );

		$destructive_prefixes = array( 'delete-', 'remove-', 'drop-', 'uninstall-', 'destroy-', 'purge-' );
		$external_prefixes    = array( 'send-', 'post-to-', 'call-', 'fetch-external-', 'webhook-' );
		$write_prefixes       = array(
			'create-',
			'update-',
			'set-',
			'install-',
			'enable-',
			'disable-',
			'activate-',
			'deactivate-',
			'publish-',
			'unpublish-',
		);
		$read_prefixes        = array( 'get-', 'list-', 'count-', 'read-', 'find-', 'search-', 'show-' );

		foreach ( $destructive_prefixes as $prefix ) {
			if ( 0 === strpos( $short, $prefix ) ) {
				return self::EFFECT_DESTRUCTIVE;
			}
		}
		foreach ( $external_prefixes as $prefix ) {
			if ( 0 === strpos( $short, $prefix ) ) {
				return self::EFFECT_EXTERNAL;
			}
		}
		foreach ( $read_prefixes as $prefix ) {
			if ( 0 === strpos( $short, $prefix ) ) {
				return self::EFFECT_READ;
			}
		}
		foreach ( $write_prefixes as $prefix ) {
			if ( 0 === strpos( $short, $prefix ) ) {
				return self::EFFECT_WRITE;
			}
		}

		// Closed by default: unknown abilities are treated as `write`.
		return self::EFFECT_WRITE;
	}

	/**
	 * Decide whether a tool call with the given effect requires confirmation
	 * under the given threshold. Pure function, no I/O — directly testable.
	 *
	 *   threshold=none        → never confirm
	 *   threshold=destructive → confirm destructive AND external
	 *   threshold=write       → confirm write, destructive, AND external
	 *   threshold=external    → confirm everything except read
	 *
	 * `read` is never gated.
	 */
	public static function requires_confirmation( string $effect, string $threshold ): bool {
		$effect    = self::normalize( $effect );
		$threshold = self::normalize_threshold( $threshold );

		if ( self::EFFECT_READ === $effect ) {
			return false;
		}
		if ( self::THRESHOLD_NONE === $threshold ) {
			return false;
		}

		switch ( $threshold ) {
			case self::THRESHOLD_EXTERNAL:
				// Confirm anything that isn't `read` — write, destructive, external.
				return true;
			case self::THRESHOLD_WRITE:
				return in_array( $effect, array( self::EFFECT_WRITE, self::EFFECT_DESTRUCTIVE, self::EFFECT_EXTERNAL ), true );
			case self::THRESHOLD_DESTRUCTIVE:
			default:
				return in_array( $effect, array( self::EFFECT_DESTRUCTIVE, self::EFFECT_EXTERNAL ), true );
		}
	}

	/**
	 * Resolve the active confirmation threshold for a chat turn.
	 *
	 * Order:
	 *   1. Per-conversation override carried in the runtime context
	 *      (`runtime_context['confirmation_threshold']`).
	 *   2. Site-wide setting at `openclawp_options['confirmation_threshold']`.
	 *   3. {@see self::DEFAULT_THRESHOLD}.
	 *
	 * Filterable via `openclawp_confirmation_threshold`.
	 */
	public static function active_threshold( array $runtime_context = array() ): string {
		$threshold = '';
		if ( isset( $runtime_context['confirmation_threshold'] ) && is_string( $runtime_context['confirmation_threshold'] ) ) {
			$threshold = $runtime_context['confirmation_threshold'];
		}
		if ( '' === $threshold ) {
			$options   = (array) get_option( 'openclawp_options', array() );
			$threshold = isset( $options['confirmation_threshold'] ) ? (string) $options['confirmation_threshold'] : '';
		}
		$threshold = '' === $threshold ? self::DEFAULT_THRESHOLD : $threshold;

		/**
		 * Filters the active confirmation threshold for a tool call.
		 *
		 * @since 0.8.0
		 *
		 * @param string $threshold       One of {@see self::valid_thresholds()}.
		 * @param array  $runtime_context Runtime context for the chat turn.
		 */
		$threshold = apply_filters( 'openclawp_confirmation_threshold', $threshold, $runtime_context );

		return self::normalize_threshold( $threshold );
	}

	/**
	 * Whether (user, ability) has been pre-authorised via "Always allow".
	 *
	 * Stored as a JSON list of ability names on the user_meta key
	 * `_openclawp_always_allow`. Read-mostly — written once per "Always
	 * allow" click, read on every gated tool call.
	 */
	public static function user_allows_always( int $user_id, string $ability_name ): bool {
		if ( $user_id <= 0 || '' === $ability_name ) {
			return false;
		}
		$list = self::get_always_allow_list( $user_id );
		return in_array( $ability_name, $list, true );
	}

	/**
	 * @return array<int,string>
	 */
	public static function get_always_allow_list( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}
		$raw = get_user_meta( $user_id, '_openclawp_always_allow', true );
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			return is_array( $decoded ) ? array_values( array_filter( array_map( 'strval', $decoded ) ) ) : array();
		}
		return is_array( $raw ) ? array_values( array_filter( array_map( 'strval', $raw ) ) ) : array();
	}

	public static function add_always_allow( int $user_id, string $ability_name ): void {
		if ( $user_id <= 0 || '' === $ability_name ) {
			return;
		}
		$list = self::get_always_allow_list( $user_id );
		if ( in_array( $ability_name, $list, true ) ) {
			return;
		}
		$list[] = $ability_name;
		update_user_meta( $user_id, '_openclawp_always_allow', wp_json_encode( array_values( $list ) ) );
	}

	public static function remove_always_allow( int $user_id, string $ability_name ): void {
		if ( $user_id <= 0 || '' === $ability_name ) {
			return;
		}
		$list = self::get_always_allow_list( $user_id );
		$list = array_values( array_filter( $list, static fn ( string $a ): bool => $a !== $ability_name ) );
		update_user_meta( $user_id, '_openclawp_always_allow', wp_json_encode( $list ) );
	}
}
