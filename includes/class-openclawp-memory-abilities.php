<?php
/**
 * Explicit memory abilities.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Memory_Abilities {

	public static function register(): void {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
	}

	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		if ( ! apply_filters( 'openclawp_register_memory_abilities', true ) ) {
			return;
		}

		self::register_remember();
		self::register_search();
	}

	private static function register_remember(): void {
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( 'openclawp/remember' ) ) {
			return;
		}

		wp_register_ability(
			'openclawp/remember',
			array(
				'label'               => __( 'Remember information', 'openclawp' ),
				'description'         => __( 'Store an explicit memory with scope, provenance, confidence, and consent metadata.', 'openclawp' ),
				'category'            => 'openclawp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'text'       => array( 'type' => 'string' ),
						'scope'      => array( 'type' => 'string', 'default' => 'user' ),
						'agent_slug' => array( 'type' => 'string' ),
						'source'     => array( 'type' => 'string', 'default' => 'agent' ),
						'confidence' => array( 'type' => 'number', 'default' => 1 ),
						'consent'    => array( 'type' => 'boolean', 'default' => false ),
						'expires_at' => array( 'type' => 'string' ),
					),
					'required'   => array( 'text', 'consent' ),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
				'execute_callback'    => static fn ( array $args ) => OpenclaWP_Memory_Store::save( $args ),
				'permission_callback' => static fn (): bool => function_exists( 'is_user_logged_in' ) && is_user_logged_in(),
				'meta'                => array( 'effect' => OpenclaWP_Tool_Effects::EFFECT_WRITE ),
			)
		);
	}

	private static function register_search(): void {
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( 'openclawp/search-memory' ) ) {
			return;
		}

		wp_register_ability(
			'openclawp/search-memory',
			array(
				'label'               => __( 'Search memory', 'openclawp' ),
				'description'         => __( 'Search explicit openclaWP memories visible to the current user/agent.', 'openclawp' ),
				'category'            => 'openclawp',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'query'      => array( 'type' => 'string', 'default' => '' ),
						'scope'      => array( 'type' => 'string' ),
						'agent_slug' => array( 'type' => 'string' ),
						'limit'      => array( 'type' => 'integer', 'default' => 5 ),
					),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
				'execute_callback'    => static fn ( array $args ): array => array( 'memories' => OpenclaWP_Memory_Store::search( $args ) ),
				'permission_callback' => static fn (): bool => function_exists( 'is_user_logged_in' ) && is_user_logged_in(),
				'meta'                => array( 'effect' => OpenclaWP_Tool_Effects::EFFECT_READ ),
			)
		);
	}
}
