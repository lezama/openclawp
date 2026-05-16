<?php
/**
 * Tests for WP-CLI diagnostics helpers.
 *
 * @package OpenclaWP\Tests
 */

use PHPUnit\Framework\TestCase;

final class CliTest extends TestCase {

	/**
	 * @dataProvider supported_wordpress_versions
	 */
	public function test_supported_wordpress_version_accepts_7x_prereleases( string $version, bool $expected ): void {
		$method = new ReflectionMethod( OpenclaWP_CLI::class, 'is_supported_wordpress_version' );
		$method->setAccessible( true );

		$this->assertSame( $expected, $method->invoke( null, $version ) );
	}

	public function supported_wordpress_versions(): array {
		return array(
			'7.0 final' => array( '7.0', true ),
			'7.0 RC'   => array( '7.0-RC3', true ),
			'7.1 dev'  => array( '7.1-alpha-62359', true ),
			'8.0'      => array( '8.0', true ),
			'6.9'      => array( '6.9', false ),
			'unknown'  => array( 'unknown', false ),
		);
	}
}
