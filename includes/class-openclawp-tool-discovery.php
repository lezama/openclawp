<?php
/**
 * Tool-discovery helpers backing the `openclawp/list-tools` and
 * `openclawp/execute-tool` meta-abilities.
 *
 * Why these exist: when an agent has many abilities in its allowed tool list
 * (built-ins + plugin extensions + custom tools), declaring all of them in
 * every system prompt burns thousands of tokens per turn — for a tool the
 * model usually does not call. The meta-tools let the model discover tools
 * on demand. The per-agent `catalog_mode` flag in `default_config` swaps the
 * full declaration list for these two entries at resolve time.
 *
 * Discovery returns names + 1-line descriptions only (no input schemas) to
 * keep the listing cheap. The model is expected to call `execute-tool` with
 * the resolved slug; argument validation happens inside the target ability
 * (or via the WP Abilities API's own schema enforcement, when present).
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Tool_Discovery {

	public const LIST_ABILITY    = 'openclawp/list-tools';
	public const EXECUTE_ABILITY = 'openclawp/execute-tool';

	/**
	 * Default page size for `list-tools` pagination. Big enough that small
	 * sites get the whole catalog on the first call; small enough that a
	 * site with 200 abilities still pages.
	 */
	public const DEFAULT_PAGE_SIZE = 50;

	/**
	 * Maximum page size, regardless of what the model asks for. Guards
	 * against a model that requests `limit: 10000` to defeat pagination.
	 */
	public const MAX_PAGE_SIZE = 100;

	/**
	 * Return the ability catalog filtered by `category` and `tools`
	 * (allowlist of slugs), paginated by `cursor`.
	 *
	 * Shape:
	 *   {
	 *     "tools":       [ { "slug": "...", "category": "...", "description": "..." }, ... ],
	 *     "next_cursor": "abilityslug" | null,
	 *     "total":       int (approximate — total visible to this caller before pagination),
	 *   }
	 *
	 * @param array $args      `category` (string), `cursor` (string), `limit` (int),
	 *                         `tools` (string[] — restrict to these slugs).
	 * @return array<string,mixed>
	 */
	public static function list_tools( array $args = array() ): array {
		$category  = isset( $args['category'] ) ? (string) $args['category'] : '';
		$cursor    = isset( $args['cursor'] ) ? (string) $args['cursor'] : '';
		$limit_raw = isset( $args['limit'] ) ? (int) $args['limit'] : self::DEFAULT_PAGE_SIZE;
		$limit     = max( 1, min( self::MAX_PAGE_SIZE, $limit_raw ) );
		$allowlist = isset( $args['tools'] ) && is_array( $args['tools'] )
			? array_values( array_filter( array_map( 'strval', $args['tools'] ) ) )
			: array();

		$rows = self::collect_abilities( $allowlist );

		if ( '' !== $category ) {
			$rows = array_values(
				array_filter(
					$rows,
					static fn( array $r ): bool => self::matches_category( $r, $category )
				)
			);
		}

		// Stable order so the cursor is meaningful across calls.
		usort(
			$rows,
			static fn( array $a, array $b ): int => strcmp( $a['slug'], $b['slug'] )
		);

		$total = count( $rows );

		// Cursor is the last slug returned in the previous page. We page
		// forward past it. Slug-as-cursor stays valid even when new
		// abilities are registered between calls (unlike index-based).
		if ( '' !== $cursor ) {
			$rows = array_values(
				array_filter(
					$rows,
					static fn( array $r ) => strcmp( $r['slug'], $cursor ) > 0
				)
			);
		}

		$page        = array_slice( $rows, 0, $limit );
		$next_cursor = count( $rows ) > $limit ? (string) $page[ count( $page ) - 1 ]['slug'] : null;

		return array(
			'tools'       => $page,
			'next_cursor' => $next_cursor,
			'total'       => $total,
		);
	}

	/**
	 * Execute one registered ability by slug. Used as the `execute-tool`
	 * ability's callback — the model passes `tool` (full ability slug) and
	 * `args` (object); we look up the ability and dispatch via its own
	 * `execute()` (which already validates the input schema).
	 *
	 * Meta-tools (`list-tools` / `execute-tool`) are explicitly *not*
	 * dispatchable through `execute-tool` — that would let a model recurse
	 * itself and skip the visibility the catalog flow is supposed to
	 * provide.
	 *
	 * @param array{tool?:string,args?:array} $args
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function execute_tool( array $args ) {
		$slug = isset( $args['tool'] ) ? (string) $args['tool'] : '';
		$slug = trim( $slug );
		if ( '' === $slug ) {
			return new WP_Error(
				'openclawp_execute_tool_missing_slug',
				__( '`tool` is required (full ability slug).', 'openclawp' ),
				array( 'status' => 400 )
			);
		}

		if ( in_array( $slug, array( self::LIST_ABILITY, self::EXECUTE_ABILITY ), true ) ) {
			return new WP_Error(
				'openclawp_execute_tool_recursion',
				__( 'Meta-tools cannot be invoked through execute-tool. Call them directly.', 'openclawp' ),
				array( 'status' => 400 )
			);
		}

		if ( ! function_exists( 'wp_get_ability' ) ) {
			return new WP_Error(
				'openclawp_execute_tool_no_abilities_api',
				__( 'Abilities API is unavailable.', 'openclawp' ),
				array( 'status' => 500 )
			);
		}

		$ability = wp_get_ability( $slug );
		if ( null === $ability ) {
			return new WP_Error(
				'openclawp_execute_tool_unknown',
				sprintf(
					/* translators: %s: ability slug */
					__( 'Unknown ability slug: %s', 'openclawp' ),
					$slug
				),
				array( 'status' => 404 )
			);
		}

		$parameters = isset( $args['args'] ) && is_array( $args['args'] ) ? $args['args'] : array();
		$result     = $ability->execute( $parameters );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'tool'   => $slug,
			'result' => $result,
		);
	}

	/**
	 * Build the meta-tool declarations to swap into the resolver output when
	 * an agent has `catalog_mode` enabled. Shaped identically to what
	 * {@see OpenclaWP_Tools_Resolver::for_agent()} returns so the loop's
	 * mediation path is unaffected.
	 *
	 * @param array<string> $catalog_tools  Full ability slugs the agent is
	 *                                      allowed to list/execute. Used as
	 *                                      the `tools` filter on every
	 *                                      list/execute call.
	 *
	 * @return array{
	 *     declarations: array<string, array<string,mixed>>,
	 *     declarations_for_provider: array,
	 *     name_to_ability: array<string,string>,
	 * }
	 */
	public static function meta_tool_resolver_payload( array $catalog_tools = array() ): array {
		$list_name    = OpenclaWP_Tools_Resolver::sanitize_name( self::LIST_ABILITY );
		$execute_name = OpenclaWP_Tools_Resolver::sanitize_name( self::EXECUTE_ABILITY );

		$list_params    = self::list_tools_input_schema();
		$execute_params = self::execute_tool_input_schema();

		$declarations = array(
			$list_name    => array(
				'name'        => $list_name,
				'source'      => 'openclawp',
				'description' => self::list_tools_description(),
				'parameters'  => $list_params,
				'executor'    => 'client',
				'scope'       => 'run',
			),
			$execute_name => array(
				'name'        => $execute_name,
				'source'      => 'openclawp',
				'description' => self::execute_tool_description(),
				'parameters'  => $execute_params,
				'executor'    => 'client',
				'scope'       => 'run',
			),
		);

		$provider_decls = array();
		if ( class_exists( '\\WordPress\\AiClient\\Tools\\DTO\\FunctionDeclaration' ) ) {
			$provider_decls[] = new \WordPress\AiClient\Tools\DTO\FunctionDeclaration(
				$list_name,
				self::list_tools_description(),
				$list_params
			);
			$provider_decls[] = new \WordPress\AiClient\Tools\DTO\FunctionDeclaration(
				$execute_name,
				self::execute_tool_description(),
				$execute_params
			);
		}

		return array(
			'declarations'              => $declarations,
			'declarations_for_provider' => $provider_decls,
			'name_to_ability'           => array(
				$list_name    => self::LIST_ABILITY,
				$execute_name => self::EXECUTE_ABILITY,
			),
			'catalog_tools'             => array_values( array_filter( array_map( 'strval', $catalog_tools ) ) ),
		);
	}

	/**
	 * Derive a category for an ability that didn't set one explicitly.
	 * Maps the slug's namespace (`namespace/slug`) to a category. Sites
	 * with consistent slug naming get usable categories for free.
	 */
	public static function infer_category( string $slug, string $explicit_category = '' ): string {
		if ( '' !== $explicit_category ) {
			return $explicit_category;
		}
		$pos = strpos( $slug, '/' );
		if ( false === $pos || 0 === $pos ) {
			return 'uncategorized';
		}
		return substr( $slug, 0, $pos );
	}

	/**
	 * Pull the list of registered abilities. Returns each as a row with
	 * `slug`, `category` (explicit or inferred from namespace), and a 1-line
	 * description (truncated to keep token cost low).
	 *
	 * @param string[] $allowlist Restrict to these slugs (empty = all).
	 * @return array<int, array{slug:string,category:string,description:string,label:string}>
	 */
	private static function collect_abilities( array $allowlist ): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return array();
		}

		$want = array();
		foreach ( $allowlist as $slug ) {
			$want[ $slug ] = true;
		}
		$filter_to_allowlist = ! empty( $want );

		$rows = array();
		foreach ( wp_get_abilities() as $ability ) {
			if ( ! is_object( $ability ) ) {
				continue;
			}

			$slug = method_exists( $ability, 'get_name' ) ? (string) $ability->get_name() : '';
			if ( '' === $slug ) {
				continue;
			}

			// Don't list meta-tools in their own catalog — they're the
			// catalog *entry points*, not catalog entries.
			if ( in_array( $slug, array( self::LIST_ABILITY, self::EXECUTE_ABILITY ), true ) ) {
				continue;
			}

			if ( $filter_to_allowlist && empty( $want[ $slug ] ) ) {
				continue;
			}

			$explicit_category = method_exists( $ability, 'get_category' )
				? (string) $ability->get_category()
				: '';
			$description = method_exists( $ability, 'get_description' )
				? (string) $ability->get_description()
				: '';
			$label = method_exists( $ability, 'get_label' )
				? (string) $ability->get_label()
				: '';

			$rows[] = array(
				'slug'        => $slug,
				'category'    => self::infer_category( $slug, $explicit_category ),
				'description' => self::one_line( $description ),
				'label'       => $label,
			);
		}

		return $rows;
	}

	private static function matches_category( array $row, string $category ): bool {
		return ( $row['category'] ?? '' ) === $category;
	}

	/**
	 * Squeeze a multi-line description into one line and cap at 240 chars.
	 * The model gets a hint about what the tool does; full schema is
	 * fetched only when the model actually calls `execute-tool`.
	 */
	private static function one_line( string $text ): string {
		$text = preg_replace( '/\s+/', ' ', $text ) ?? $text;
		$text = trim( $text );
		if ( strlen( $text ) <= 240 ) {
			return $text;
		}
		return rtrim( substr( $text, 0, 237 ) ) . '...';
	}

	public static function list_tools_input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'category' => array(
					'type'        => 'string',
					'description' => 'Filter by category (e.g. "openclawp", "agents", "posts"). Omit to list every category.',
				),
				'cursor'   => array(
					'type'        => 'string',
					'description' => 'Pagination cursor returned by a previous call as `next_cursor`. Omit on the first page.',
				),
				'limit'    => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => self::MAX_PAGE_SIZE,
					'description' => 'Max tools to return. Default 50.',
				),
			),
		);
	}

	public static function execute_tool_input_schema(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'tool' ),
			'properties' => array(
				'tool' => array(
					'type'        => 'string',
					'description' => 'Full ability slug (e.g. "openclawp/get-time"). Get this from a list-tools call.',
				),
				'args' => array(
					'type'        => 'object',
					'description' => 'Arguments to pass to the ability. Shape depends on the target tool — call list-tools first if unsure.',
				),
			),
		);
	}

	public static function list_tools_description(): string {
		return __( 'List the tools you can invoke. Returns slug + short description for each. Filter by `category` or paginate with `cursor`. Call this when you do not know which tool can answer the user; then call `execute-tool` with the slug you pick.', 'openclawp' );
	}

	public static function execute_tool_description(): string {
		return __( 'Invoke any registered ability by slug. Pass `tool` (the slug from list-tools) and `args` (arguments object). Returns `{ tool, result }` on success or an error object otherwise.', 'openclawp' );
	}
}
