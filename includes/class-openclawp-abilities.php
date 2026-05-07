<?php
/**
 * Sample echo ability.
 *
 * Registered via wp-abilities-API. Acts as a smoke test that the agent loop's
 * tool-mediation path works end-to-end. Real abilities are downstream consumer
 * territory — register your own under your plugin's namespace.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Abilities {

	public static function register(): void {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_echo_ability' ) );
	}

	public static function register_echo_ability(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'openclawp/echo',
			array(
				'label'            => __( 'Echo', 'openclawp' ),
				'description'      => __( 'Echoes the input back. Smoke-test ability.', 'openclawp' ),
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'text' => array(
							'type'        => 'string',
							'description' => 'The string to echo.',
						),
					),
					'required'   => array( 'text' ),
				),
				'output_schema'    => array(
					'type'       => 'object',
					'properties' => array(
						'echoed' => array(
							'type'        => 'string',
							'description' => 'The echoed string.',
						),
					),
					'required'   => array( 'echoed' ),
				),
				'execute_callback' => static function ( array $args ): array {
					return array( 'echoed' => (string) ( $args['text'] ?? '' ) );
				},
			)
		);
	}
}
