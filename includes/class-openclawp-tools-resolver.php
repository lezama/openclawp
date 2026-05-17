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
				$description   = method_exists( $ability, 'get_description' ) ? (string) $ability->get_description() : '';
				$input_schema  = method_exists( $ability, 'get_input_schema' ) ? $ability->get_input_schema() : null;
				$parameters    = is_array( $input_schema ) ? $input_schema : array( 'type' => 'object', 'properties' => array() );

				$declarations[ $declared_name ] = array(
					'name'        => $declared_name,
					'source'      => 'openclawp',
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

				$name_to_abil[ $declared_name ] = $ability_name;
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
				$label         = method_exists( $subagent, 'get_label' ) ? (string) $subagent->get_label() : $subagent_slug;
				$bio           = method_exists( $subagent, 'get_description' ) ? (string) $subagent->get_description() : '';
				$description   = sprintf(
					'Delegate to subagent %s. Use when the request is in this subagent\'s scope. Subagent description: %s',
					$label,
					'' === $bio ? '(no description provided)' : $bio
				);

				$declarations[ $declared_name ] = array(
					'name'        => $declared_name,
					'source'      => 'openclawp',
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

				$delegate_targets[ $declared_name ] = $subagent_slug;
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
	 * Convert a `namespace/slug` ability name into a provider-safe function
	 * name. `/` becomes `__`; other unsupported characters get stripped.
	 */
	public static function sanitize_name( string $ability_name ): string {
		$sanitized = str_replace( '/', '__', $ability_name );
		$sanitized = preg_replace( '/[^A-Za-z0-9_-]/', '', $sanitized );
		return is_string( $sanitized ) ? $sanitized : '';
	}
}
