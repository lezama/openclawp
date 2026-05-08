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

// Stub the agents-api channel base class. The real one lives in
// `automattic/agents-api`, which is a dev-main composer dep that isn't
// always installed during PHPUnit runs (CI environments, fresh checkouts).
// Tests in tests/unit/ exercise pure-PHP helpers on the subclasses; the
// actual loop logic is covered by tests/smoke.php inside a real WP.
if ( ! class_exists( '\\AgentsAPI\\AI\\Channels\\WP_Agent_Channel' ) ) {
	require_once __DIR__ . '/stubs/wp-agent-channel-stub.php';
}

require_once dirname( __DIR__ ) . '/includes/autoload.php';
