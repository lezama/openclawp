<?php
/**
 * Agency package generator.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Agency_Generator {

	/**
	 * Generate an installable automation package.
	 *
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>|WP_Error
	 */
	public static function generate( array $args ) {
		$blueprint_slug = sanitize_key( (string) ( $args['blueprint'] ?? $args['blueprint_slug'] ?? '' ) );
		$blueprint      = OpenclaWP_Agency_Blueprints::get( $blueprint_slug );
		if ( null === $blueprint ) {
			return new WP_Error(
				'openclawp_unknown_blueprint',
				__( 'Unknown automation blueprint.', 'openclawp' ),
				array( 'status' => 404 )
			);
		}

		$workspace = self::resolve_workspace( $args );
		if ( is_wp_error( $workspace ) ) {
			return $workspace;
		}

		$answers = isset( $args['answers'] ) && is_array( $args['answers'] ) ? self::sanitize_answers( $args['answers'] ) : array();
		$missing = self::missing_required_answers( $blueprint, $answers );
		$agent   = self::build_agent_registration( $workspace, $blueprint, $answers );
		$workflow = self::build_workflow_spec( $workspace, $blueprint, $agent, $answers );

		$available_connectors = isset( $workspace['connectors'] ) && is_array( $workspace['connectors'] )
			? $workspace['connectors']
			: array();
		$connector_plan = OpenclaWP_Agency_Connectors::plan(
			(array) ( $blueprint['recommended_connectors'] ?? array() ),
			$available_connectors
		);

		$package = array(
			'package_id'      => self::package_id( $workspace, $blueprint ),
			'title'           => sprintf(
				'%s - %s',
				(string) ( $workspace['name'] ?? __( 'Client', 'openclawp' ) ),
				(string) ( $blueprint['label'] ?? $blueprint_slug )
			),
			'workspace'       => $workspace,
			'blueprint'       => self::summarize_blueprint( $blueprint ),
			'answers'         => $answers,
			'missing_answers' => $missing,
			'agent_registration' => $agent,
			'workflow_spec'   => $workflow,
			'connector_plan'  => $connector_plan,
			'knowledge_base_plan' => self::build_kb_plan( $workspace, $blueprint ),
			'approval_policy' => self::approval_policy( $blueprint ),
			'demo'            => self::build_demo( $workspace, $blueprint, $answers ),
			'deployment_steps' => self::deployment_steps( $blueprint, $connector_plan, $missing ),
			'generated_at'    => gmdate( 'c' ),
		);

		/**
		 * Filters a generated agency automation package before optional save.
		 *
		 * @param array $package
		 * @param array $args
		 */
		$package = (array) apply_filters( 'openclawp_generated_agency_package', $package, $args );

		if ( ! empty( $args['save'] ) ) {
			return OpenclaWP_Agency_Demo_Store::save( $package );
		}

		return $package;
	}

	/**
	 * @return array<string,mixed>|WP_Error
	 */
	private static function resolve_workspace( array $args ) {
		$workspace_id = isset( $args['workspace_id'] ) ? (int) $args['workspace_id'] : 0;
		if ( $workspace_id > 0 ) {
			return OpenclaWP_Agency_Workspace_Store::hydrate( $workspace_id );
		}

		$workspace = isset( $args['workspace'] ) && is_array( $args['workspace'] ) ? $args['workspace'] : array();
		if ( empty( $workspace['name'] ) ) {
			$workspace['name'] = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : 'Client';
		}
		if ( empty( $workspace['site_url'] ) && function_exists( 'home_url' ) ) {
			$workspace['site_url'] = home_url( '/' );
		}

		return array(
			'workspace_id' => 0,
			'name'         => sanitize_text_field( (string) ( $workspace['name'] ?? 'Client' ) ),
			'summary'      => sanitize_textarea_field( (string) ( $workspace['summary'] ?? '' ) ),
			'site_url'     => esc_url_raw( (string) ( $workspace['site_url'] ?? '' ) ),
			'industry'     => sanitize_text_field( (string) ( $workspace['industry'] ?? '' ) ),
			'goals'        => OpenclaWP_Agency_Workspace_Store::sanitize_text_list( $workspace['goals'] ?? array() ),
			'channels'     => OpenclaWP_Agency_Workspace_Store::sanitize_key_list( $workspace['channels'] ?? array() ),
			'connectors'   => OpenclaWP_Agency_Workspace_Store::sanitize_key_list( $workspace['connectors'] ?? array() ),
			'notes'        => sanitize_textarea_field( (string) ( $workspace['notes'] ?? '' ) ),
		);
	}

	/**
	 * @param array<string,mixed> $answers
	 * @return array<string,string>
	 */
	public static function sanitize_answers( array $answers ): array {
		$out = array();
		foreach ( $answers as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || ! is_scalar( $value ) ) {
				continue;
			}
			$out[ $key ] = sanitize_textarea_field( (string) $value );
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $blueprint
	 * @param array<string,string> $answers
	 * @return array<int,string>
	 */
	public static function missing_required_answers( array $blueprint, array $answers ): array {
		$missing = array();
		foreach ( (array) ( $blueprint['questions'] ?? array() ) as $question ) {
			if ( empty( $question['required'] ) ) {
				continue;
			}
			$id = sanitize_key( (string) ( $question['id'] ?? '' ) );
			if ( '' !== $id && '' === trim( (string) ( $answers[ $id ] ?? '' ) ) ) {
				$missing[] = $id;
			}
		}
		return $missing;
	}

	/**
	 * @param array<string,mixed> $workspace
	 * @param array<string,mixed> $blueprint
	 * @param array<string,string> $answers
	 * @return array<string,mixed>
	 */
	public static function build_agent_registration( array $workspace, array $blueprint, array $answers ): array {
		$client_slug    = self::client_slug( $workspace );
		$blueprint_slug = (string) $blueprint['slug'];
		$agent_slug     = 'agency-' . $client_slug . '-' . $blueprint_slug;
		$tools          = array_values( array_unique( array_merge(
			(array) ( $blueprint['default_tools'] ?? array() ),
			self::tools_for_connectors( (array) ( $blueprint['recommended_connectors'] ?? array() ) )
		) ) );

		return array(
			'slug'          => $agent_slug,
			'label'         => sprintf( '%s %s', (string) $workspace['name'], (string) $blueprint['label'] ),
			'description'   => self::build_system_prompt( $workspace, $blueprint, $answers ),
			'owner_resolver' => 'get_current_user_id',
			'default_config' => array(
				'provider'  => 'auto',
				'model'     => 'claude-haiku-4-5',
				'tools'     => $tools,
				'max_turns' => 8,
			),
			'meta'          => array(
				'source_plugin' => 'openclawp/openclawp.php',
				'source_type'   => 'agency-generated-agent',
				'client_slug'   => $client_slug,
				'blueprint'     => $blueprint_slug,
			),
		);
	}

	/**
	 * @param array<string,mixed> $workspace
	 * @param array<string,mixed> $blueprint
	 * @param array<string,mixed> $agent
	 * @param array<string,string> $answers
	 * @return array<string,mixed>
	 */
	public static function build_workflow_spec( array $workspace, array $blueprint, array $agent, array $answers ): array {
		$template = isset( $blueprint['workflow'] ) && is_array( $blueprint['workflow'] ) ? $blueprint['workflow'] : array();
		$trigger  = isset( $template['trigger'] ) && is_array( $template['trigger'] ) ? $template['trigger'] : array( 'type' => 'on_demand' );
		$steps    = array();

		foreach ( (array) ( $template['steps'] ?? array() ) as $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}
			$type = (string) ( $step['type'] ?? 'agent' );
			$id   = sanitize_key( (string) ( $step['id'] ?? $type ) );
			if ( 'ability' === $type ) {
				$steps[] = array(
					'id'      => $id,
					'type'    => 'ability',
					'ability' => (string) ( $step['ability'] ?? 'connector/configure-me' ),
					'args'    => array(
						'client'  => (string) $workspace['name'],
						'answers' => $answers,
					),
				);
			} else {
				$steps[] = array(
					'id'      => $id,
					'type'    => 'agent',
					'agent'   => (string) $agent['slug'],
					'message' => self::workflow_agent_message( $workspace, $blueprint, $answers ),
				);
			}
		}

		if ( empty( $steps ) ) {
			$steps[] = array(
				'id'      => 'run_agent',
				'type'    => 'agent',
				'agent'   => (string) $agent['slug'],
				'message' => self::workflow_agent_message( $workspace, $blueprint, $answers ),
			);
		}

		return array(
			'id'       => 'agency/' . self::client_slug( $workspace ) . '/' . sanitize_key( (string) ( $template['id_suffix'] ?? $blueprint['slug'] ) ),
			'version'  => '1.0.0',
			'inputs'   => array(
				'message' => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Inbound customer/request text for the automation.',
				),
			),
			'steps'    => $steps,
			'triggers' => array( $trigger ),
			'meta'     => array(
				'source_plugin' => 'openclawp/openclawp.php',
				'source_type'   => 'agency-generated-workflow',
				'client_slug'   => self::client_slug( $workspace ),
				'blueprint'     => (string) $blueprint['slug'],
			),
		);
	}

	/**
	 * @param array<int,string> $connectors
	 * @return array<int,string>
	 */
	private static function tools_for_connectors( array $connectors ): array {
		$packs = OpenclaWP_Agency_Connectors::all();
		$tools = array();
		foreach ( $connectors as $connector ) {
			$connector = sanitize_key( $connector );
			if ( isset( $packs[ $connector ]['tool_hints'] ) ) {
				$tools = array_merge( $tools, (array) $packs[ $connector ]['tool_hints'] );
			}
		}
		return $tools;
	}

	/**
	 * @param array<string,mixed> $workspace
	 * @param array<string,mixed> $blueprint
	 * @param array<string,string> $answers
	 */
	private static function build_system_prompt( array $workspace, array $blueprint, array $answers ): string {
		$lines = array(
			'You are an automation agent for an agency-managed WordPress client.',
			'Client: ' . (string) $workspace['name'],
			'Site: ' . (string) ( $workspace['site_url'] ?? '' ),
			'Industry: ' . (string) ( $workspace['industry'] ?? '' ),
			'Use case: ' . (string) $blueprint['label'] . ' - ' . (string) $blueprint['description'],
			'Primary goals: ' . implode( ', ', (array) ( $workspace['goals'] ?? array() ) ),
			'Rules: ask for missing information, use tools before making factual claims, keep customer-facing replies concise, and hand off when confidence is low or the request crosses policy/approval boundaries.',
			'Do not claim an external action was completed unless a tool result confirms it.',
		);

		if ( ! empty( $answers ) ) {
			$lines[] = 'Client-specific answers:';
			foreach ( $answers as $key => $value ) {
				$lines[] = '- ' . $key . ': ' . $value;
			}
		}

		return implode( "\n", array_filter( $lines, static fn ( $line ) => '' !== trim( $line ) ) );
	}

	/**
	 * @param array<string,mixed> $workspace
	 * @param array<string,mixed> $blueprint
	 * @param array<string,string> $answers
	 */
	private static function workflow_agent_message( array $workspace, array $blueprint, array $answers ): string {
		unset( $answers );
		return sprintf(
			'Handle this %s workflow for %s. Inbound message: ${inputs.message}. Use available tools, gather missing details, and produce a structured result with next_action, customer_reply, internal_summary, and handoff_required.',
			(string) $blueprint['slug'],
			(string) $workspace['name']
		);
	}

	/**
	 * @param array<string,mixed> $workspace
	 * @param array<string,mixed> $blueprint
	 * @return array<string,mixed>
	 */
	private static function build_kb_plan( array $workspace, array $blueprint ): array {
		return array(
			'sources_to_index' => array_values(
				array_filter(
					array(
						$workspace['site_url'] ?? '',
						'service pages',
						'FAQ/support pages',
						'policies',
						'pricing/offer docs',
					),
					static fn ( $value ) => '' !== trim( (string) $value )
				)
			),
			'retrieval_tool'   => in_array( 'knowledge-base/search', (array) ( $blueprint['default_tools'] ?? array() ), true ) ? 'knowledge-base/search' : '',
			'notes'            => 'Index public site content first; add private PDFs/URLs only with client approval.',
		);
	}

	/**
	 * @param array<string,mixed> $blueprint
	 * @return array<string,mixed>
	 */
	private static function approval_policy( array $blueprint ): array {
		return array(
			'default_confirmation_threshold' => OpenclaWP_Tool_Effects::THRESHOLD_WRITE,
			'always_require_human_for'       => array( 'destructive', 'external_send_without_review', 'refunds', 'legal_or_medical_advice' ),
			'blueprint'                      => (string) $blueprint['slug'],
		);
	}

	/**
	 * @param array<string,mixed> $workspace
	 * @param array<string,mixed> $blueprint
	 * @param array<string,string> $answers
	 * @return array<string,mixed>
	 */
	private static function build_demo( array $workspace, array $blueprint, array $answers ): array {
		$prompts = (array) ( $blueprint['demo_prompts'] ?? array() );
		return array(
			'audience'    => 'agency sales call',
			'positioning' => sprintf(
				'Show %s how this automation handles a real inbound request using their site context and routes the outcome to the right human/system.',
				(string) $workspace['name']
			),
			'prompts'     => $prompts,
			'script'      => array(
				'Open with the client pain point.',
				'Run one demo prompt through the generated agent.',
				'Show the internal summary and handoff plan.',
				'Show required connectors and approvals before production.',
			),
			'recording'   => array(
				'workflow'            => OpenclaWP_Demo_Recorder::WORKFLOW_ID,
				'plan_ability'        => OpenclaWP_Demo_Recorder::CREATE_PLAN_ABILITY,
				'record_ability'      => OpenclaWP_Demo_Recorder::RECORD_VIDEO_ABILITY,
				'voice_over_enabled'  => true,
				'local_recorder_mode' => 'http-endpoint',
			),
			'success_criteria' => (array) ( $blueprint['success_metrics'] ?? array() ),
			'answers_used' => array_keys( $answers ),
		);
	}

	/**
	 * @param array<string,mixed> $blueprint
	 * @param array<int,array<string,mixed>> $connector_plan
	 * @param array<int,string> $missing
	 * @return array<int,string>
	 */
	private static function deployment_steps( array $blueprint, array $connector_plan, array $missing ): array {
		$steps = array(
			'Create or confirm the client workspace.',
			'Index the client knowledge base and verify citations.',
			'Register the generated agent and workflow spec.',
			'Set confirmation threshold to write for first production runs.',
			'Run the demo prompts and review transcripts with the client.',
		);
		foreach ( $connector_plan as $connector ) {
			if ( 'available' !== (string) ( $connector['status'] ?? '' ) ) {
				$steps[] = 'Configure connector pack: ' . (string) ( $connector['label'] ?? $connector['slug'] ?? '' );
			}
		}
		if ( ! empty( $missing ) ) {
			$steps[] = 'Fill missing blueprint answers: ' . implode( ', ', $missing );
		}
		if ( 'ecommerce-recovery' === (string) ( $blueprint['slug'] ?? '' ) ) {
			$steps[] = 'Confirm opt-in and messaging compliance before any recovery outreach.';
		}
		return array_values( array_unique( $steps ) );
	}

	/**
	 * @param array<string,mixed> $blueprint
	 * @return array<string,mixed>
	 */
	private static function summarize_blueprint( array $blueprint ): array {
		return array(
			'slug'        => (string) $blueprint['slug'],
			'label'       => (string) $blueprint['label'],
			'category'    => (string) $blueprint['category'],
			'description' => (string) $blueprint['description'],
		);
	}

	/**
	 * @param array<string,mixed> $workspace
	 * @param array<string,mixed> $blueprint
	 */
	private static function package_id( array $workspace, array $blueprint ): string {
		return 'agency/' . self::client_slug( $workspace ) . '/' . (string) $blueprint['slug'];
	}

	/**
	 * @param array<string,mixed> $workspace
	 */
	private static function client_slug( array $workspace ): string {
		$name = (string) ( $workspace['name'] ?? 'client' );
		$slug = sanitize_title( $name );
		return '' === $slug ? 'client' : $slug;
	}
}
