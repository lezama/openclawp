<?php
/**
 * Unit tests for OpenclaWP_Mcp_Client_Transport.
 *
 * @package OpenclaWP\Tests
 */

declare( strict_types=1 );

namespace OpenclaWP\Tests\Unit;

use OpenclaWP_Mcp_Client_Transport;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @covers OpenclaWP_Mcp_Client_Transport
 */
final class McpClientTransportTest extends TestCase {

	private string $previous_max_execution_time = '0';
	private $previous_request_time_float = null;
	private bool $had_request_time_float = false;

	protected function setUp(): void {
		parent::setUp();
		$this->previous_max_execution_time = (string) ini_get( 'max_execution_time' );
		$this->had_request_time_float      = array_key_exists( 'REQUEST_TIME_FLOAT', $_SERVER );
		$this->previous_request_time_float = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
	}

	protected function tearDown(): void {
		ini_set( 'max_execution_time', $this->previous_max_execution_time );
		if ( $this->had_request_time_float ) {
			$_SERVER['REQUEST_TIME_FLOAT'] = $this->previous_request_time_float;
		} else {
			unset( $_SERVER['REQUEST_TIME_FLOAT'] );
		}
		parent::tearDown();
	}

	public function test_request_execution_deadline_is_null_without_php_execution_limit(): void {
		ini_set( 'max_execution_time', '0' );

		$this->assertNull( self::request_execution_deadline() );
	}

	public function test_request_execution_deadline_leaves_margin_before_php_limit(): void {
		$now                            = microtime( true );
		$_SERVER['REQUEST_TIME_FLOAT'] = $now - 10;
		ini_set( 'max_execution_time', '30' );

		$deadline = self::request_execution_deadline();

		$this->assertIsFloat( $deadline );
		$this->assertGreaterThan( $now + 14, $deadline );
		$this->assertLessThan( $now + 16, $deadline );
	}

	private static function request_execution_deadline(): ?float {
		$method = new ReflectionMethod( OpenclaWP_Mcp_Client_Transport::class, 'request_execution_deadline' );
		$method->setAccessible( true );

		return $method->invoke( null );
	}
}
