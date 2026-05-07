<?php
/**
 * Resolve an agent's allowed tools into the shapes the loop expects.
 *
 * Reads `$agent->get_default_config()['tools']` (a list of fully namespaced
 * ability names) and produces:
 *
 *   - declarations  : keyed by sanitized name, in the shape canonical's
 *                     WP_Agent_Tool_Declaration::validate() accepts
 *                     (executor=client, scope=run).
 *   - declarations_for_provider : list of WP AI Client `FunctionDeclaration`
 *                     DTOs to pass into using_function_declarations().
 *   - name_to_ability : map from the sanitized declaration name back to the
 *                     full ability name, for the OpenclaWP_Tool_Executor to
 *                     dispatch.
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
	 * }
	 */
	public static function for_agent( WP_Agent $agent ): array {
		$config         = $agent->get_default_config();
		$tools          = isset( $config['tools'] ) && is_array( $config['tools'] ) ? $config['tools'] : array();
		$declarations   = array();
		$provider_decls = array();
		$name_to_abil   = array();

		if ( empty( $tools ) || ! function_exists( 'wp_get_ability' ) ) {
			return array(
				'declarations'              => array(),
				'declarations_for_provider' => array(),
				'name_to_ability'           => array(),
			);
		}

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

		return array(
			'declarations'              => $declarations,
			'declarations_for_provider' => $provider_decls,
			'name_to_ability'           => $name_to_abil,
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
