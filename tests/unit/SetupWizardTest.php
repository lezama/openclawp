<?php
/**
 * Unit tests for OpenclaWP_Setup_Wizard.
 *
 * @package OpenclaWP\Tests
 */

declare( strict_types=1 );

namespace OpenclaWP\Tests\Unit;

use OpenclaWP_Setup_Wizard;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @covers OpenclaWP_Setup_Wizard
 */
final class SetupWizardTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['openclawp_test_filters']['openclawp_show_setup_notice'] );
		parent::tearDown();
	}

	public function test_welcome_notice_is_hidden_on_setup_page(): void {
		$this->assertFalse( self::should_render_welcome_notice( 'openclawp-setup', null ) );
	}

	public function test_welcome_notice_can_be_hidden_by_filter_for_host_screen(): void {
		$screen = (object) array( 'id' => 'toplevel_page_wp-inbox' );

		$GLOBALS['openclawp_test_filters']['openclawp_show_setup_notice'] = static function ( bool $show, string $current_page, $passed_screen ) use ( $screen ): bool {
			TestCase::assertTrue( $show );
			TestCase::assertSame( 'wp-inbox', $current_page );
			TestCase::assertSame( $screen, $passed_screen );
			return false;
		};

		$this->assertFalse( self::should_render_welcome_notice( 'wp-inbox', $screen ) );
	}

	private static function should_render_welcome_notice( string $current_page, $screen ): bool {
		$method = new ReflectionMethod( OpenclaWP_Setup_Wizard::class, 'should_render_welcome_notice' );
		$method->setAccessible( true );

		return (bool) $method->invoke( null, $current_page, $screen );
	}
}
