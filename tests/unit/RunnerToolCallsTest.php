<?php
/**
 * Unit tests for provider tool-call extraction.
 *
 * @package OpenclaWP\Tests
 */

declare( strict_types=1 );

namespace OpenclaWP\Tests\Unit;

use OpenclaWP_Runner;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @covers OpenclaWP_Runner
 */
final class RunnerToolCallsTest extends TestCase {

	public function test_extract_tool_calls_preserves_provider_id(): void {
		$method = new ReflectionMethod( OpenclaWP_Runner::class, 'extract_tool_calls' );
		$method->setAccessible( true );

		$result = new RunnerToolCallsStubs\Result(
			array(
				new RunnerToolCallsStubs\Part(
					new RunnerToolCallsStubs\FunctionCall(
						'toolu_01ABC',
						'carpeta__delete-potreros',
						array(
							'ids'     => array( 987654321 ),
							'confirm' => true,
						)
					)
				),
			)
		);

		$this->assertSame(
			array(
				array(
					'name'       => 'client/carpeta__delete-potreros',
					'parameters' => array(
						'ids'     => array( 987654321 ),
						'confirm' => true,
					),
					'id'         => 'toolu_01ABC',
				),
			),
			$method->invoke( null, $result )
		);
	}
}

namespace OpenclaWP\Tests\Unit\RunnerToolCallsStubs;

final class Result {
	private Message $message;

	/**
	 * @param array<int, Part> $parts
	 */
	public function __construct( array $parts ) {
		$this->message = new Message( $parts );
	}

	public function toMessage(): Message {
		return $this->message;
	}
}

final class Message {
	/** @var array<int, Part> */
	private array $parts;

	/**
	 * @param array<int, Part> $parts
	 */
	public function __construct( array $parts ) {
		$this->parts = $parts;
	}

	/**
	 * @return array<int, Part>
	 */
	public function getParts(): array {
		return $this->parts;
	}
}

final class Part {
	private FunctionCall $function_call;

	public function __construct( FunctionCall $function_call ) {
		$this->function_call = $function_call;
	}

	public function getType(): Type {
		return new Type( 'function_call' );
	}

	public function getFunctionCall(): FunctionCall {
		return $this->function_call;
	}
}

final class Type {
	private string $value;

	public function __construct( string $value ) {
		$this->value = $value;
	}

	public function value(): string {
		return $this->value;
	}
}

final class FunctionCall {
	private string $id;
	private string $name;
	/** @var array<string,mixed> */
	private array $args;

	/**
	 * @param array<string,mixed> $args
	 */
	public function __construct( string $id, string $name, array $args ) {
		$this->id   = $id;
		$this->name = $name;
		$this->args = $args;
	}

	public function getId(): string {
		return $this->id;
	}

	public function getName(): string {
		return $this->name;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function getArgs(): array {
		return $this->args;
	}
}
