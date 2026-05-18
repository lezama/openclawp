<?php
/**
 * Bootstrap for the prompt-assembly snapshot suite.
 *
 * Reuses the unit-suite bootstrap (Composer autoload, WP_Agent_Channel stub,
 * openclaWP class autoloader) and layers on in-memory stubs for:
 *
 *   - wp_get_ability / wp_register_ability / wp_has_ability
 *   - wp_get_agent / wp_register_agent / wp_has_agent
 *
 * The real implementations live in the WP-7.0 Abilities + Agents APIs which
 * we don't pull into PHPUnit. These stubs are deliberately minimal — they
 * exist so `OpenclaWP_Tools_Resolver::for_agent()` can build a full payload
 * with realistic tool catalogs, without standing up wp-env.
 *
 * @package OpenclaWP\Tests\Integration\PromptAssembly
 */

require_once dirname( __DIR__, 2 ) . '/bootstrap.php';

// Pull the real `WP_Agent` definition out of the agents-api dep so the
// snapshot exercises the same property shape production sees. We load it
// directly (rather than via the substrate bootstrap) because the substrate
// also tries to register WordPress actions, REST routes, and hooks that
// aren't available in this minimal PHPUnit environment.
require_once dirname( __DIR__, 3 ) . '/vendor/automattic/agents-api/src/Registry/class-wp-agent.php';

// Stub the WP AI Client FunctionDeclaration DTO. The real class ships with
// `wordpress/php-ai-client` (or WP 7.0 core) and is only used at runtime to
// pass declarations into the provider builder. The snapshot suite reads
// from the resolver's plain-PHP `declarations` map, not the provider DTOs,
// so an empty stub satisfies the `new …\FunctionDeclaration()` calls.
if ( ! class_exists( '\\WordPress\\AiClient\\Tools\\DTO\\FunctionDeclaration' ) ) {
	require_once __DIR__ . '/stubs/function-declaration-stub.php';
}

require_once __DIR__ . '/PromptPayloadAssembler.php';

if ( ! isset( $GLOBALS['openclawp_test_ability_registry'] ) ) {
	$GLOBALS['openclawp_test_ability_registry'] = array();
}
if ( ! isset( $GLOBALS['openclawp_test_agent_registry'] ) ) {
	$GLOBALS['openclawp_test_agent_registry'] = array();
}

/**
 * Minimal stand-in for the WP-7 Ability object surface that
 * OpenclaWP_Tools_Resolver reads (description + input_schema).
 */
if ( ! class_exists( 'Openclawp_Test_Stub_Ability' ) ) {
	final class Openclawp_Test_Stub_Ability {

		private string $name;
		private string $description;
		/** @var array<string, mixed> */
		private array $input_schema;

		public function __construct( string $name, string $description, array $input_schema ) {
			$this->name         = $name;
			$this->description  = $description;
			$this->input_schema = $input_schema;
		}

		public function get_name(): string {
			return $this->name;
		}

		public function get_description(): string {
			return $this->description;
		}

		/**
		 * @return array<string, mixed>
		 */
		public function get_input_schema(): array {
			return $this->input_schema;
		}
	}
}

if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( string $name, array $args ) {
		$ability = new Openclawp_Test_Stub_Ability(
			$name,
			(string) ( $args['description'] ?? '' ),
			isset( $args['input_schema'] ) && is_array( $args['input_schema'] ) ? $args['input_schema'] : array( 'type' => 'object' )
		);
		$GLOBALS['openclawp_test_ability_registry'][ $name ] = $ability;
		return $ability;
	}
}
if ( ! function_exists( 'wp_get_ability' ) ) {
	function wp_get_ability( string $name ) {
		return $GLOBALS['openclawp_test_ability_registry'][ $name ] ?? null;
	}
}
if ( ! function_exists( 'wp_has_ability' ) ) {
	function wp_has_ability( string $name ): bool {
		return isset( $GLOBALS['openclawp_test_ability_registry'][ $name ] );
	}
}

if ( ! function_exists( 'wp_register_agent' ) ) {
	function wp_register_agent( string $slug, array $args ) {
		$agent = new WP_Agent( $slug, $args );
		$GLOBALS['openclawp_test_agent_registry'][ $agent->get_slug() ] = $agent;
		return $agent;
	}
}
if ( ! function_exists( 'wp_get_agent' ) ) {
	function wp_get_agent( string $slug ) {
		return $GLOBALS['openclawp_test_agent_registry'][ $slug ] ?? null;
	}
}
if ( ! function_exists( 'wp_has_agent' ) ) {
	function wp_has_agent( string $slug ): bool {
		return isset( $GLOBALS['openclawp_test_agent_registry'][ $slug ] );
	}
}

// Helpers normally provided by core that the registrar / abilities touch.
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		unset( $domain );
		return $text;
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( string $title ): string {
		$title = strtolower( trim( $title ) );
		$title = preg_replace( '/[^a-z0-9-]+/', '-', $title );
		$title = trim( (string) $title, '-' );
		return (string) $title;
	}
}
if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( string $name ): string {
		return preg_replace( '/[^A-Za-z0-9._-]+/', '', $name ) ?? '';
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return 0;
	}
}
