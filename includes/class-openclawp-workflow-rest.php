<?php
/**
 * REST endpoints powering the wp-admin → openclaWP → Workflows page.
 *
 * All endpoints sit under `/wp-json/openclawp/v1/workflows*` and require
 * `manage_options`. They cover the read paths the admin JS needs and
 * trigger paths the run-now form posts to. Workflow CRUD (create / edit
 * specs) intentionally stays out of v0 — admins author specs in PHP /
 * register from other plugins until the editor UI lands in a follow-up.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Registry;
use AgentsAPI\AI\Workflows\WP_Agent_Workflow_Spec;

final class OpenclaWP_Workflow_Rest {

	private const NAMESPACE = 'openclawp/v1';

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		// Workflow ids (and run ids) are slash-bearing slugs (`my-plugin/foo`),
		// which WP REST routes cannot accept as path captures — `%2F` doesn't
		// decode before regex matching, so the path-segment forms 404. We use
		// query strings instead: the id rides in `?id=...` / `?run_id=...`.

		register_rest_route(
			self::NAMESPACE,
			'/workflows',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_workflows' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/workflow',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_workflow' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'id' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/workflow/run',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'run_workflow' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'id'     => array(
						'type'     => 'string',
						'required' => true,
					),
					'inputs' => array(
						'type'    => 'object',
						'default' => array(),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/workflow-runs',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_runs' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'workflow_id' => array( 'type' => 'string' ),
					'limit'       => array(
						'type'    => 'integer',
						'default' => 20,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/workflow-run',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_run' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'run_id' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/workflow/draft',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'draft_workflow' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'prompt' => array(
						'type'        => 'string',
						'required'    => true,
						'description' => 'One-paragraph description of what the workflow should do.',
					),
					'agent'  => array(
						'type'        => 'string',
						'description' => 'Optional drafter agent slug. Defaults to the first registered agent.',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/workflow',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'save_workflow' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'spec' => array(
						'type'     => 'object',
						'required' => true,
					),
				),
			)
		);
	}

	public static function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Combined list: in-memory registry first (code-defined workflows are
	 * authoritative), falling back to the persistent store for the rest.
	 */
	public static function list_workflows(): WP_REST_Response {
		$out  = array();
		$seen = array();

		if ( class_exists( 'AgentsAPI\\AI\\Workflows\\WP_Agent_Workflow_Registry' ) ) {
			foreach ( WP_Agent_Workflow_Registry::all() as $spec ) {
				$out[]                    = self::summarize_spec( $spec, 'registered' );
				$seen[ $spec->get_id() ] = true;
			}
		}

		foreach ( OpenclaWP_Workflow_Store::instance()->all() as $spec ) {
			if ( isset( $seen[ $spec->get_id() ] ) ) {
				continue;
			}
			$out[] = self::summarize_spec( $spec, 'stored' );
		}

		return new WP_REST_Response(
			array(
				'workflows' => $out,
			),
			200
		);
	}

	public static function get_workflow( WP_REST_Request $request ): WP_REST_Response {
		$id   = (string) $request->get_param( 'id' );
		if ( '' === $id ) {
			return new WP_REST_Response(
				array(
					'error'   => 'missing_id',
					'message' => 'Pass `?id=<workflow-id>` to fetch a specific workflow.',
				),
				400
			);
		}
		$spec = self::resolve_spec( $id );
		if ( null === $spec ) {
			return new WP_REST_Response(
				array(
					'error'   => 'unknown_workflow',
					'message' => sprintf( 'no workflow with id `%s`', $id ),
				),
				404
			);
		}
		return new WP_REST_Response( self::full_spec( $spec ), 200 );
	}

	public static function run_workflow( WP_REST_Request $request ): WP_REST_Response {
		$id     = (string) $request->get_param( 'id' );
		$inputs = (array) $request->get_param( 'inputs' );

		if ( '' === $id ) {
			return new WP_REST_Response(
				array(
					'error'   => 'missing_id',
					'message' => 'Pass `?id=<workflow-id>` to run a specific workflow.',
				),
				400
			);
		}

		if ( ! function_exists( 'wp_get_ability' ) ) {
			return new WP_REST_Response(
				array(
					'error'   => 'abilities_api_missing',
					'message' => 'Abilities API is not loaded.',
				),
				503
			);
		}

		$ability = wp_get_ability( 'agents/run-workflow' );
		if ( null === $ability ) {
			return new WP_REST_Response(
				array(
					'error'   => 'dispatcher_missing',
					'message' => 'agents/run-workflow ability is not registered. Ensure agents-api 0.103.0+ is installed.',
				),
				503
			);
		}

		$result = $ability->execute(
			array(
				'workflow_id' => $id,
				'inputs'      => $inputs,
			)
		);

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response(
				array(
					'error'   => $result->get_error_code(),
					'message' => $result->get_error_message(),
				),
				500
			);
		}

		return new WP_REST_Response( $result, 200 );
	}

	public static function list_runs( WP_REST_Request $request ): WP_REST_Response {
		$args = array(
			'limit' => (int) $request->get_param( 'limit' ),
		);
		$workflow_id = (string) $request->get_param( 'workflow_id' );
		if ( '' !== $workflow_id ) {
			$args['workflow_id'] = $workflow_id;
		}

		$runs = OpenclaWP_Workflow_Run_Recorder::instance()->recent( $args );
		$out  = array();
		foreach ( $runs as $run ) {
			$arr   = $run->to_array();
			// Drop step bodies for the list view — admins see them in the per-run detail.
			unset( $arr['steps'] );
			$out[] = $arr;
		}

		return new WP_REST_Response( array( 'runs' => $out ), 200 );
	}

	/**
	 * POST /workflow/draft — natural language → spec.
	 */
	public static function draft_workflow( WP_REST_Request $request ): WP_REST_Response {
		$prompt = (string) $request->get_param( 'prompt' );
		$agent  = (string) ( $request->get_param( 'agent' ) ?? '' );

		$result = OpenclaWP_Workflow_Drafter::draft( $prompt, $agent );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response(
				array(
					'error'   => $result->get_error_code(),
					'message' => $result->get_error_message(),
					'data'    => $result->get_error_data(),
				),
				400
			);
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * POST /workflow — persist a spec to the CPT-backed store.
	 */
	public static function save_workflow( WP_REST_Request $request ): WP_REST_Response {
		$raw = (array) $request->get_param( 'spec' );

		$spec = WP_Agent_Workflow_Spec::from_array( $raw );
		if ( is_wp_error( $spec ) ) {
			return new WP_REST_Response(
				array(
					'error'   => $spec->get_error_code(),
					'message' => $spec->get_error_message(),
					'data'    => $spec->get_error_data(),
				),
				400
			);
		}

		$saved = OpenclaWP_Workflow_Store::instance()->save( $spec );
		if ( is_wp_error( $saved ) ) {
			return new WP_REST_Response(
				array(
					'error'   => $saved->get_error_code(),
					'message' => $saved->get_error_message(),
				),
				500
			);
		}

		return new WP_REST_Response(
			array(
				'saved' => true,
				'id'    => $spec->get_id(),
			),
			201
		);
	}

	public static function get_run( WP_REST_Request $request ): WP_REST_Response {
		$run_id = (string) $request->get_param( 'run_id' );
		if ( '' === $run_id ) {
			return new WP_REST_Response(
				array(
					'error'   => 'missing_run_id',
					'message' => 'Pass `?run_id=<run-id>` to fetch a specific run.',
				),
				400
			);
		}
		$run = OpenclaWP_Workflow_Run_Recorder::instance()->find( $run_id );
		if ( null === $run ) {
			return new WP_REST_Response(
				array( 'error' => 'unknown_run' ),
				404
			);
		}
		return new WP_REST_Response( $run->to_array(), 200 );
	}

	/**
	 * Resolve a workflow id against (registry, store) in that order.
	 */
	private static function resolve_spec( string $id ): ?WP_Agent_Workflow_Spec {
		if ( '' === $id ) {
			return null;
		}
		if ( function_exists( 'wp_get_workflow' ) ) {
			$registered = wp_get_workflow( $id );
			if ( null !== $registered ) {
				return $registered;
			}
		}
		return OpenclaWP_Workflow_Store::instance()->find( $id );
	}

	private static function summarize_spec( WP_Agent_Workflow_Spec $spec, string $source ): array {
		return array(
			'id'      => $spec->get_id(),
			'version' => $spec->get_version(),
			'source'  => $source,
			'steps'   => count( $spec->get_steps() ),
			'inputs'  => array_keys( $spec->get_inputs() ),
			'meta'    => $spec->get_meta(),
		);
	}

	private static function full_spec( WP_Agent_Workflow_Spec $spec ): array {
		return array(
			'id'       => $spec->get_id(),
			'version'  => $spec->get_version(),
			'inputs'   => $spec->get_inputs(),
			'steps'    => $spec->get_steps(),
			'triggers' => $spec->get_triggers(),
			'meta'     => $spec->get_meta(),
			'spec'     => $spec->to_array(),
		);
	}
}
