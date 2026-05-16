<?php
/**
 * WP-CLI diagnostics for openclaWP.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_CLI {

	public static function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		WP_CLI::add_command(
			'openclawp doctor',
			array( __CLASS__, 'doctor' ),
			array(
				'shortdesc' => 'Check whether openclaWP can run chat, workflows, and packaged dependencies.',
				'synopsis'  => array(
					array(
						'type'        => 'flag',
						'name'        => 'strict',
						'description' => 'Exit non-zero on warnings as well as failures.',
						'optional'    => true,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'format',
						'description' => 'Render output in a specific format.',
						'optional'    => true,
						'default'     => 'table',
						'options'     => array( 'table', 'json', 'csv', 'yaml', 'count' ),
					),
				),
			)
		);
	}

	/**
	 * Run install diagnostics.
	 *
	 * ## OPTIONS
	 *
	 * [--strict]
	 * : Exit non-zero on warnings as well as failures.
	 *
	 * [--format=<format>]
	 * : Output format. One of: table, json, csv, yaml, count.
	 *
	 * ## EXAMPLES
	 *
	 *     wp openclawp doctor
	 *     wp openclawp doctor --format=json
	 *
	 * @param array<int, string> $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public static function doctor( array $args, array $assoc_args ): void {
		unset( $args );

		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		$strict = ! empty( $assoc_args['strict'] );
		$checks = self::collect_checks();
		$rows   = array_map(
			static function ( array $check ): array {
				return array(
					'check'    => $check['check'],
					'status'   => $check['status'],
					'critical' => $check['critical'] ? 'yes' : 'no',
					'detail'   => $check['detail'],
				);
			},
			$checks
		);

		$failures          = array_filter( $checks, static fn ( array $check ): bool => 'fail' === $check['status'] );
		$critical_failures = array_filter( $failures, static fn ( array $check ): bool => $check['critical'] );
		$warnings          = array_filter( $checks, static fn ( array $check ): bool => 'warn' === $check['status'] );
		$should_fail       = ! empty( $critical_failures ) || ( $strict && ( ! empty( $failures ) || ! empty( $warnings ) ) );

		\WP_CLI\Utils\format_items( $format, $rows, array( 'check', 'status', 'critical', 'detail' ) );

		if ( 'table' !== $format ) {
			if ( $should_fail ) {
				WP_CLI::halt( 1 );
			}
			return;
		}

		if ( ! empty( $critical_failures ) ) {
			WP_CLI::error( sprintf( '%d critical openclaWP check(s) failed.', count( $critical_failures ) ) );
		}

		if ( $strict && ( ! empty( $failures ) || ! empty( $warnings ) ) ) {
			WP_CLI::error( sprintf( '%d warning/failure check(s) failed under --strict.', count( $failures ) + count( $warnings ) ) );
		}

		if ( ! empty( $warnings ) ) {
			WP_CLI::warning( sprintf( '%d non-critical openclaWP check(s) need attention.', count( $warnings ) ) );
			return;
		}

		WP_CLI::success( 'openclaWP install checks passed.' );
	}

	/**
	 * @return array<int, array{check:string,status:string,critical:bool,detail:string}>
	 */
	public static function collect_checks(): array {
		$checks = array();

		self::add_check(
			$checks,
			'PHP version',
			PHP_VERSION_ID >= 80100,
			true,
			PHP_VERSION
		);

		$wp_version = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : 'unknown';
		self::add_check(
			$checks,
			'WordPress version',
			self::is_supported_wordpress_version( $wp_version ),
			true,
			$wp_version
		);

		self::add_check(
			$checks,
			'openclaWP loaded',
			defined( 'OPENCLAWP_LOADED' ),
			true,
			defined( 'OPENCLAWP_VERSION' ) ? OPENCLAWP_VERSION : 'missing OPENCLAWP_VERSION'
		);

		self::add_check(
			$checks,
			'agents-api loaded',
			defined( 'AGENTS_API_LOADED' ) && function_exists( 'wp_register_agent' ),
			true,
			defined( 'AGENTS_API_PLUGIN_FILE' ) ? AGENTS_API_PLUGIN_FILE : 'AGENTS_API_PLUGIN_FILE not defined'
		);

		self::add_check(
			$checks,
			'WP AI client available',
			function_exists( 'wp_ai_client_prompt' ),
			true,
			function_exists( 'wp_ai_client_prompt' ) ? 'wp_ai_client_prompt() exists' : 'WordPress AI client is missing'
		);

		self::add_check(
			$checks,
			'Action Scheduler available',
			function_exists( 'as_schedule_recurring_action' ),
			true,
			function_exists( 'as_schedule_recurring_action' ) ? 'as_schedule_recurring_action() exists' : 'Action Scheduler is missing'
		);

		$asset_files = array(
			'blocks/chat/build/view.js',
			'blocks/chat/build/view.asset.php',
			'blocks/chat/build/view.css',
			'blocks/routines/build/view.js',
			'blocks/routines/build/view.asset.php',
			'blocks/routines/build/view.css',
		);
		$missing_assets = array_values(
			array_filter(
				$asset_files,
				static fn ( string $path ): bool => ! file_exists( OPENCLAWP_PATH . $path )
			)
		);
		self::add_check(
			$checks,
			'built block assets',
			empty( $missing_assets ),
			true,
			empty( $missing_assets ) ? 'chat and routines builds present' : 'missing: ' . implode( ', ', $missing_assets )
		);

		self::add_check(
			$checks,
			'openclawp/chat ability',
			function_exists( 'wp_has_ability' ) && wp_has_ability( 'openclawp/chat' ),
			true,
			function_exists( 'wp_has_ability' ) ? 'registered through Abilities API' : 'wp_has_ability() is missing'
		);

		self::add_check(
			$checks,
			'agents/chat ability',
			function_exists( 'wp_has_ability' ) && wp_has_ability( 'agents/chat' ),
			true,
			function_exists( 'wp_has_ability' ) ? 'canonical dispatcher registered' : 'wp_has_ability() is missing'
		);

		self::add_check(
			$checks,
			'session storage CPT',
			function_exists( 'get_post_type_object' ) && null !== get_post_type_object( 'openclawp_session' ),
			true,
			'openclawp_session'
		);

		self::add_check(
			$checks,
			'REST chat route',
			self::rest_route_exists( '/openclawp/v1/chat' ),
			true,
			'/wp-json/openclawp/v1/chat'
		);

		self::add_check(
			$checks,
			'workflow runtime',
			class_exists( 'AgentsAPI\\AI\\Workflows\\WP_Agent_Workflow_Runner' )
				&& function_exists( 'wp_has_ability' )
				&& wp_has_ability( 'agents/run-workflow' )
				&& wp_has_ability( 'agents/validate-workflow' ),
			true,
			'agents/run-workflow and agents/validate-workflow'
		);

		$provider_ids = self::get_registered_provider_ids();
		self::add_check(
			$checks,
			'AI providers registered',
			! empty( $provider_ids ),
			false,
			empty( $provider_ids ) ? 'install and configure a WordPress AI provider before real chat' : implode( ', ', $provider_ids )
		);

		$agents = function_exists( 'wp_get_agents' ) ? wp_get_agents() : array();
		self::add_check(
			$checks,
			'agent inventory',
			! empty( $agents ),
			false,
			empty( $agents ) ? 'no registered agents; register one or enable the example agent filter for testing' : count( $agents ) . ' registered'
		);

		return $checks;
	}

	/**
	 * @param array<int, array{check:string,status:string,critical:bool,detail:string}> $checks Checks.
	 */
	private static function add_check( array &$checks, string $label, bool $ok, bool $critical, string $detail ): void {
		$checks[] = array(
			'check'    => $label,
			'status'   => $ok ? 'pass' : ( $critical ? 'fail' : 'warn' ),
			'critical' => $critical,
			'detail'   => $detail,
		);
	}

	private static function rest_route_exists( string $route ): bool {
		if ( ! function_exists( 'rest_get_server' ) ) {
			return false;
		}

		$server = rest_get_server();
		return isset( $server->get_routes()[ $route ] );
	}

	private static function is_supported_wordpress_version( string $version ): bool {
		if ( preg_match( '/^(\d+)\.(\d+)/', $version, $matches ) ) {
			$major = (int) $matches[1];
			$minor = (int) $matches[2];

			return $major > 7 || ( 7 === $major && $minor >= 0 );
		}

		return false;
	}

	/**
	 * @return string[]
	 */
	private static function get_registered_provider_ids(): array {
		if ( ! class_exists( 'WordPress\\AiClient\\AiClient' ) ) {
			return array();
		}

		try {
			$registry = \WordPress\AiClient\AiClient::defaultRegistry();
			if ( is_object( $registry ) && method_exists( $registry, 'getRegisteredProviderIds' ) ) {
				return array_values( array_map( 'strval', (array) $registry->getRegisteredProviderIds() ) );
			}
		} catch ( Throwable $e ) {
			return array();
		}

		return array();
	}
}
