<?php
/**
 * PHPUnit bootstrap.
 *
 * Loads Composer autoload (PHPUnit + dev deps) and openclaWP's class
 * autoloader. Stubs the small set of WordPress functions that openclaWP's
 * source files reference at load time, so files can be parsed without a
 * running WordPress.
 *
 * Tests in `tests/unit/` are pure-PHP unit tests — they exercise classes
 * that don't touch WP_Query / posts / options. Integration tests that need
 * a running WordPress live in `tests/smoke.php`, runnable via
 * `studio wp eval-file tests/smoke.php`.
 *
 * @package OpenclaWP\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'OPENCLAWP_PATH' ) ) {
	define( 'OPENCLAWP_PATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'OPENCLAWP_VERSION' ) ) {
	define( 'OPENCLAWP_VERSION', '0.1.0-test' );
}

// Stub WP functions referenced at file-load time. Tests that depend on real
// WP behavior (post types, REST routes, hooks firing) live in tests/smoke.php
// and run inside a Studio site.
if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, callable $cb, int $priority = 10, int $accepted_args = 1 ): void {}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $cb, int $priority = 10, int $accepted_args = 1 ): void {}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		// Tests that need to assert filter dispatch can set
		// $GLOBALS['openclawp_test_filters'][$hook] to a callable; the
		// stub will invoke it. Otherwise behaves as a pass-through.
		$cb = $GLOBALS['openclawp_test_filters'][ $hook ] ?? null;
		return null === $cb ? $value : $cb( $value, ...$args );
	}
}
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, int $options = 0, int $depth = 512 ) {
		return json_encode( $value, $options, $depth );
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $name, $default = false ) {
		return $GLOBALS['openclawp_test_options'][ $name ] ?? $default;
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof \WP_Error;
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public string $code;
		public string $message;
		public array $data;
		public function __construct( string $code = '', string $message = '', array $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
		public function get_error_code(): string {
			return $this->code;
		}
		public function get_error_message(): string {
			return $this->message;
		}
		public function get_error_data() {
			return $this->data;
		}
	}
}
if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( string $url, array $args = array() ) {
		$GLOBALS['openclawp_test_http_capture'][] = array(
			'url'  => $url,
			'args' => $args,
		);
		return array(
			'response' => array( 'code' => 202, 'message' => 'Accepted' ),
			'body'     => '',
		);
	}
}
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( string $title ): string {
		$lower = strtolower( $title );
		$clean = preg_replace( '/[^a-z0-9]+/', '-', $lower );
		return is_string( $clean ) ? trim( $clean, '-' ) : '';
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', $key ) );
	}
}
if ( ! function_exists( 'wp_http_validate_url' ) ) {
	function wp_http_validate_url( string $url ) {
		return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : false;
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( string $url ): string {
		return $url;
	}
}

require_once __DIR__ . '/../vendor/autoload.php';

// Stub the agents-api channel base class. The real one lives in
// `automattic/agents-api`, which is a dev-main composer dep that isn't
// always installed during PHPUnit runs (CI environments, fresh checkouts).
// Tests in tests/unit/ exercise pure-PHP helpers on the subclasses; the
// actual loop logic is covered by tests/smoke.php inside a real WP.
if ( ! class_exists( '\\AgentsAPI\\AI\\Channels\\WP_Agent_Channel' ) ) {
	require_once __DIR__ . '/stubs/wp-agent-channel-stub.php';
}

require_once dirname( __DIR__ ) . '/includes/autoload.php';
