<?php
/**
 * Class autoloader for openclaWP.
 *
 * Maps `OpenclaWP_*` class names to `includes/class-openclawp-*.php` files
 * on first use, replacing the previous block of `require_once` calls in the
 * bootstrap. Only the file containing the class actually being constructed
 * is parsed by PHP; admin-only paths don't pay for REST classes, REST-only
 * paths don't pay for the admin page renderer, etc.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( $class_name ): void {
		if ( 0 !== strpos( $class_name, 'OpenclaWP_' ) ) {
			return;
		}

		// OpenclaWP_Foo_Bar → class-openclawp-foo-bar.php
		$file_slug = strtolower( str_replace( '_', '-', substr( $class_name, strlen( 'OpenclaWP_' ) ) ) );
		$path      = OPENCLAWP_PATH . 'includes/class-openclawp-' . $file_slug . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);
