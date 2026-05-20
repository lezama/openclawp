<?php
/**
 * REST API for the agency automation factory.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Agency_Rest {

	private const NAMESPACE = 'openclawp/v1';

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/agency/blueprints',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => static fn ( WP_REST_Request $request ) => rest_ensure_response(
					array( 'blueprints' => OpenclaWP_Agency_Blueprints::list( (string) $request->get_param( 'category' ) ) )
				),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/agency/connectors',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => static fn () => rest_ensure_response( array( 'connectors' => array_values( OpenclaWP_Agency_Connectors::all() ) ) ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/agency/audit',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => static fn () => rest_ensure_response( OpenclaWP_Automation_Audit::audit_current_site() ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/agency/workspaces',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => static fn ( WP_REST_Request $request ) => rest_ensure_response(
						array( 'workspaces' => OpenclaWP_Agency_Workspace_Store::all( (int) ( $request->get_param( 'limit' ) ?? 50 ) ) )
					),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'save_workspace' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/agency/generate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'generate' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/agency/demos',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => static fn ( WP_REST_Request $request ) => rest_ensure_response(
					array( 'demos' => OpenclaWP_Agency_Demo_Store::recent( (int) ( $request->get_param( 'limit' ) ?? 20 ) ) )
				),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);
	}

	public static function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	public static function save_workspace( WP_REST_Request $request ) {
		$result = OpenclaWP_Agency_Workspace_Store::save( (array) $request->get_json_params() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	public static function generate( WP_REST_Request $request ) {
		$result = OpenclaWP_Agency_Generator::generate( (array) $request->get_json_params() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}
}
