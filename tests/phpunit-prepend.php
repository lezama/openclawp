<?php
/**
 * PHPUnit process bootstrap before Composer autoload.
 *
 * The `wordpress/agents-api` Composer package autoloads its plugin bootstrap via
 * `autoload.files`. PHPUnit's binary loads `vendor/autoload.php` before it loads
 * this project's `tests/bootstrap.php`, so the agents-api bootstrap would either
 * exit outside WordPress or try to boot WordPress hooks too early. Define the two
 * runtime guards it checks before Composer autoloads files.
 *
 * @package OpenclaWP\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! defined( 'AGENTS_API_LOADED' ) ) {
	define( 'AGENTS_API_LOADED', true );
}
