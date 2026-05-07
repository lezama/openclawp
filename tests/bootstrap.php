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

require_once __DIR__ . '/../vendor/autoload.php';
require_once dirname( __DIR__ ) . '/includes/autoload.php';
