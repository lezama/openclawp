<?php
/**
 * Agency automation abilities.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Agency_Abilities {

	public static function register(): void {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
	}

	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		if ( ! apply_filters( 'openclawp_register_agency_abilities', true ) ) {
			return;
		}

		self::register_ability(
			'openclawp/list-agent-blueprints',
			array(
				'label'               => __( 'List agency agent blueprints', 'openclawp' ),
				'description'         => __( 'List automation blueprints agencies can use to generate client-specific agents, workflows, connector plans, and demos.', 'openclawp' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'category' => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => static fn ( array $args ): array => array(
					'blueprints' => OpenclaWP_Agency_Blueprints::list( (string) ( $args['category'] ?? '' ) ),
				),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'meta'                => array( 'effect' => OpenclaWP_Tool_Effects::EFFECT_READ ),
			)
		);

		self::register_ability(
			'openclawp/list-connector-packs',
			array(
				'label'               => __( 'List connector packs', 'openclawp' ),
				'description'         => __( 'List connector packs that can fulfill generated agency automations.', 'openclawp' ),
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => static fn (): array => array( 'connectors' => array_values( OpenclaWP_Agency_Connectors::all() ) ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'meta'                => array( 'effect' => OpenclaWP_Tool_Effects::EFFECT_READ ),
			)
		);

		self::register_ability(
			'openclawp/audit-automation-opportunities',
			array(
				'label'               => __( 'Audit automation opportunities', 'openclawp' ),
				'description'         => __( 'Scan the current site for automation opportunities and map them to agency blueprints.', 'openclawp' ),
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => static fn (): array => OpenclaWP_Automation_Audit::audit_current_site(),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'meta'                => array( 'effect' => OpenclaWP_Tool_Effects::EFFECT_READ ),
			)
		);

		self::register_ability(
			'openclawp/create-client-workspace',
			array(
				'label'               => __( 'Create client workspace', 'openclawp' ),
				'description'         => __( 'Create or update an agency client workspace used by generated automations.', 'openclawp' ),
				'input_schema'        => self::workspace_input_schema(),
				'execute_callback'    => static fn ( array $args ) => OpenclaWP_Agency_Workspace_Store::save( $args ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'meta'                => array( 'effect' => OpenclaWP_Tool_Effects::EFFECT_WRITE ),
			)
		);

		self::register_ability(
			'openclawp/list-client-workspaces',
			array(
				'label'               => __( 'List client workspaces', 'openclawp' ),
				'description'         => __( 'List agency client workspaces.', 'openclawp' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'limit' => array( 'type' => 'integer', 'default' => 50 ),
					),
				),
				'execute_callback'    => static fn ( array $args ): array => array( 'workspaces' => OpenclaWP_Agency_Workspace_Store::all( (int) ( $args['limit'] ?? 50 ) ) ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'meta'                => array( 'effect' => OpenclaWP_Tool_Effects::EFFECT_READ ),
			)
		);

		self::register_ability(
			'openclawp/generate-client-agent-package',
			array(
				'label'               => __( 'Generate client agent package', 'openclawp' ),
				'description'         => __( 'Generate an agency automation package: agent registration args, workflow spec, connector plan, KB plan, approvals, and demo script.', 'openclawp' ),
				'input_schema'        => self::generate_input_schema(),
				'execute_callback'    => static fn ( array $args ) => OpenclaWP_Agency_Generator::generate( $args ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'meta'                => array( 'effect' => OpenclaWP_Tool_Effects::EFFECT_WRITE ),
			)
		);

		self::register_ability(
			'openclawp/list-demo-packages',
			array(
				'label'               => __( 'List generated demo packages', 'openclawp' ),
				'description'         => __( 'List recently saved agency automation demo packages.', 'openclawp' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'limit' => array( 'type' => 'integer', 'default' => 20 ),
					),
				),
				'execute_callback'    => static fn ( array $args ): array => array( 'demos' => OpenclaWP_Agency_Demo_Store::recent( (int) ( $args['limit'] ?? 20 ) ) ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'meta'                => array( 'effect' => OpenclaWP_Tool_Effects::EFFECT_READ ),
			)
		);
	}

	public static function can_manage(): bool {
		return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
	}

	private static function register_ability( string $name, array $args ): void {
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) {
			return;
		}
		$args['category']      = $args['category'] ?? 'openclawp';
		$args['output_schema'] = $args['output_schema'] ?? array(
			'type'                 => 'object',
			'additionalProperties' => true,
		);
		wp_register_ability( $name, $args );
	}

	private static function workspace_input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'workspace_id' => array( 'type' => 'integer' ),
				'name'         => array( 'type' => 'string' ),
				'site_url'     => array( 'type' => 'string' ),
				'industry'     => array( 'type' => 'string' ),
				'summary'      => array( 'type' => 'string' ),
				'goals'        => array( 'type' => 'array' ),
				'channels'     => array( 'type' => 'array' ),
				'connectors'   => array( 'type' => 'array' ),
				'notes'        => array( 'type' => 'string' ),
			),
			'required'   => array( 'name' ),
		);
	}

	private static function generate_input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'blueprint'    => array( 'type' => 'string' ),
				'workspace_id' => array( 'type' => 'integer' ),
				'workspace'    => array( 'type' => 'object' ),
				'answers'      => array( 'type' => 'object' ),
				'save'         => array( 'type' => 'boolean', 'default' => false ),
			),
			'required'   => array( 'blueprint' ),
		);
	}
}
