<?php
/**
 * Resolve an agent's allowed tools into the shapes the loop expects.
 *
 * Reads `$agent->get_default_config()['tools']` (a list of fully namespaced
 * ability names) **and** `$agent->get_subagents()` (list of subagent slugs
 * for coordinator agents) and produces:
 *
 *   - declarations  : keyed by sanitized name, in the shape canonical's
 *                     WP_Agent_Tool_Declaration::validate() accepts
 *                     (executor=client, scope=run).
 *   - declarations_for_provider : list of WP AI Client `FunctionDeclaration`
 *                     DTOs to pass into using_function_declarations().
 *   - name_to_ability : map from the sanitized declaration name back to the
 *                     full ability name, for the OpenclaWP_Tool_Executor to
 *                     dispatch.
 *   - delegate_targets : map from a `delegate-to-<slug>` declaration name to
 *                     the subagent slug it dispatches to. Used by the
 *                     executor to route tool calls through canonical
 *                     `agents/chat` instead of the abilities API.
 *
 * Sanitization: provider APIs (OpenAI, Anthropic, Gemini) reject `/` in
 * function names, so `openclawp/get_time` becomes `openclawp__get_time`
 * for the LLM.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Tools_Resolver {

	/**
	 * Source segment for client tool declarations. The agents-api conversation
	 * loop validates client tool names against `^client/[a-z][a-z0-9_-]*$` and
	 * derives the declaration's source from the segment before the slash, which
	 * MUST equal `client` (see WP_Agent_Tool_Declaration::validate()). Provider
	 * APIs reject `/` in function names, so the name the model sees stays the
	 * sanitized `__` form; the loop-facing declaration + executor key it as
	 * `client/<sanitized>`. {@see self::loop_name()}.
	 */
	public const TOOL_SOURCE = 'client';

	/**
	 * Map a provider-safe declaration name (what the model sees, e.g.
	 * `openclawp__get-recent-posts`) to the loop-facing name the agents-api
	 * conversation loop validates and matches tool calls against
	 * (`client/openclawp__get-recent-posts`). Idempotent.
	 */
	public static function loop_name( string $declared_name ): string {
		$prefix = self::TOOL_SOURCE . '/';
		if ( 0 === strpos( $declared_name, $prefix ) ) {
			return $declared_name;
		}
		return $prefix . $declared_name;
	}

	/**
	 * Inverse of {@see self::loop_name()}: strip the `client/` prefix off a
	 * loop-facing tool name to recover the provider-safe name the model used
	 * (and expects back in FunctionCall / FunctionResponse parts). Idempotent.
	 */
	public static function provider_name( string $loop_name ): string {
		$prefix = self::TOOL_SOURCE . '/';
		if ( 0 === strpos( $loop_name, $prefix ) ) {
			return substr( $loop_name, strlen( $prefix ) );
		}
		return $loop_name;
	}

	/**
	 * @return array{
	 *     declarations: array<string, array{name:string,source:string,description:string,parameters:array,executor:string,scope:string}>,
	 *     declarations_for_provider: array,
	 *     name_to_ability: array<string, string>,
	 *     delegate_targets: array<string, string>,
	 * }
	 */
	public static function for_agent( WP_Agent $agent ): array {
		$config           = $agent->get_default_config();
		$tools            = isset( $config['tools'] ) && is_array( $config['tools'] ) ? $config['tools'] : array();
		$subagents        = method_exists( $agent, 'get_subagents' ) ? $agent->get_subagents() : array();
		$catalog_mode     = ! empty( $config['catalog_mode'] );
		$declarations     = array();
		$provider_decls   = array();
		$name_to_abil     = array();
		$delegate_targets = array();

		// Catalog mode: replace the full tools list with the two meta-tools
		// (`openclawp/list-tools` + `openclawp/execute-tool`). The agent
		// discovers and dispatches abilities on demand instead of paying
		// for every input schema in the system prompt. Subagent delegate
		// tools stay in the declaration list — coordinator routing is
		// structural, not catalogable.
		if ( $catalog_mode && class_exists( 'OpenclaWP_Tool_Discovery' ) ) {
			$meta           = OpenclaWP_Tool_Discovery::meta_tool_resolver_payload( $tools );
			$declarations   = $meta['declarations'];
			$provider_decls = $meta['declarations_for_provider'];
			$name_to_abil   = $meta['name_to_ability'];
			// Skip the per-ability declaration loop below; fall through to
			// the subagent branch so delegate-* tools are still added.
			$tools = array();
		}

		if ( empty( $tools ) && empty( $subagents ) && ! $catalog_mode ) {
			return array(
				'declarations'              => array(),
				'declarations_for_provider' => array(),
				'name_to_ability'           => array(),
				'delegate_targets'          => array(),
			);
		}

		if ( ! empty( $tools ) && function_exists( 'wp_get_ability' ) ) {
			foreach ( $tools as $ability_name ) {
				$ability_name = (string) $ability_name;
				$ability      = wp_get_ability( $ability_name );
				if ( null === $ability ) {
					continue;
				}

				$declared_name = self::sanitize_name( $ability_name );
				$loop_name     = self::loop_name( $declared_name );
				$description   = method_exists( $ability, 'get_description' ) ? (string) $ability->get_description() : '';
				$input_schema  = method_exists( $ability, 'get_input_schema' ) ? $ability->get_input_schema() : null;
				$parameters    = is_array( $input_schema ) ? $input_schema : array( 'type' => 'object', 'properties' => array() );

				$declarations[ $loop_name ] = array(
					'name'        => $loop_name,
					'source'      => self::TOOL_SOURCE,
					'description' => $description,
					'parameters'  => $parameters,
					'executor'    => 'client',
					'scope'       => 'run',
				);

				$provider_decls[] = new \WordPress\AiClient\Tools\DTO\FunctionDeclaration(
					$declared_name,
					$description,
					$parameters
				);

				$name_to_abil[ $loop_name ] = $ability_name;
			}
		}

		// Subagents-as-tools. Each subagent gets a `delegate-to-<slug>`
		// entry so the parent agent can dispatch to it the same way it
		// invokes any other tool. Tool execution routes through the
		// canonical `agents/chat` ability rather than abilities, so the
		// executor needs the slug map (`delegate_targets`) to pick which
		// path to take.
		if ( ! empty( $subagents ) && function_exists( 'wp_get_agent' ) ) {
			$delegate_parameters = array(
				'type'       => 'object',
				'required'   => array( 'prompt' ),
				'properties' => array(
					'prompt' => array(
						'type'        => 'string',
						'description' => 'The instruction to send to the subagent. The subagent receives only this text — include all the context it needs.',
					),
				),
			);

			foreach ( $subagents as $subagent_slug ) {
				$subagent = wp_get_agent( $subagent_slug );
				if ( null === $subagent ) {
					continue;
				}

				$declared_name = self::sanitize_name( 'delegate-to-' . $subagent_slug );
				$loop_name     = self::loop_name( $declared_name );
				$label         = method_exists( $subagent, 'get_label' ) ? (string) $subagent->get_label() : $subagent_slug;
				$bio           = method_exists( $subagent, 'get_description' ) ? (string) $subagent->get_description() : '';
				$description   = sprintf(
					'Delegate to subagent %s. Use when the request is in this subagent\'s scope. Subagent description: %s',
					$label,
					'' === $bio ? '(no description provided)' : $bio
				);

				$declarations[ $loop_name ] = array(
					'name'        => $loop_name,
					'source'      => self::TOOL_SOURCE,
					'description' => $description,
					'parameters'  => $delegate_parameters,
					'executor'    => 'client',
					'scope'       => 'run',
				);

				$provider_decls[] = new \WordPress\AiClient\Tools\DTO\FunctionDeclaration(
					$declared_name,
					$description,
					$delegate_parameters
				);

				$delegate_targets[ $loop_name ] = $subagent_slug;
			}
		}

		return array(
			'declarations'              => $declarations,
			'declarations_for_provider' => $provider_decls,
			'name_to_ability'           => $name_to_abil,
			'delegate_targets'          => $delegate_targets,
		);
	}

	/**
	 * Build the loop-facing variant of {@see self::for_agent()} for the
	 * agents-api conversation loop.
	 *
	 * The loop validates client tool declarations as `client/<name>` (deriving
	 * source `client` from the segment before the slash) and matches the model's
	 * tool calls against those same names. So this re-keys the declarations and
	 * the executor maps under {@see self::loop_name()} and stamps the
	 * source/executor/scope the loop requires. The provider-facing declarations
	 * and the raw {@see self::for_agent()} output are intentionally left
	 * unprefixed — the MCP tool surface and the names the model sees must NOT
	 * carry the `client/` prefix. The runner maps the model's returned tool-call
	 * names back through {@see self::loop_name()}.
	 *
	 * @return array{declarations:array<string,array<string,mixed>>,declarations_for_provider:array,name_to_ability:array<string,string>,delegate_targets:array<string,string>}
	 */
	public static function loop_tools( WP_Agent $agent ): array {
		$resolved = self::for_agent( $agent );

		$declarations = array();
		foreach ( $resolved['declarations'] as $name => $decl ) {
			$loop             = self::loop_name( (string) $name );
			$decl['name']     = $loop;
			$decl['source']   = self::TOOL_SOURCE;
			$decl['executor'] = 'client';
			$decl['scope']    = 'run';

			$declarations[ $loop ] = $decl;
		}

		$name_to_ability = array();
		foreach ( $resolved['name_to_ability'] as $name => $ability ) {
			$name_to_ability[ self::loop_name( (string) $name ) ] = $ability;
		}

		$delegate_targets = array();
		foreach ( ( $resolved['delegate_targets'] ?? array() ) as $name => $slug ) {
			$delegate_targets[ self::loop_name( (string) $name ) ] = $slug;
		}

		return array(
			'declarations'              => $declarations,
			'declarations_for_provider' => $resolved['declarations_for_provider'],
			'name_to_ability'           => $name_to_ability,
			'delegate_targets'          => $delegate_targets,
		);
	}

	/**
	 * Convert a `namespace/slug` ability name into a provider-safe function
	 * name. `/` becomes `__`; other unsupported characters get stripped; the
	 * result is lowercased so the `client/<name>` loop form satisfies the
	 * agents-api client-tool name pattern (`^[a-z][a-z0-9_-]*$` per segment).
	 */
	public static function sanitize_name( string $ability_name ): string {
		$sanitized = str_replace( '/', '__', strtolower( $ability_name ) );
		$sanitized = preg_replace( '/[^a-z0-9_-]/', '', $sanitized );
		return is_string( $sanitized ) ? $sanitized : '';
	}
}
