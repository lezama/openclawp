<?php
/**
 * Pure-PHP unit tests for OpenclaWP_Message_Adapter.
 *
 * Covers the parts of the adapter that don't depend on the WP AI Client SDK
 * being loaded (last_assistant_text, content extraction, the no-SDK fallback
 * branch of to_ai_client_messages). The full Message DTO round-trip is
 * exercised in tests/smoke.php inside a real WordPress.
 *
 * @package OpenclaWP\Tests
 */

declare( strict_types=1 );

namespace OpenclaWP\Tests\Unit;

use OpenclaWP_Message_Adapter;
use PHPUnit\Framework\TestCase;

/**
 * @covers OpenclaWP_Message_Adapter
 */
final class MessageAdapterTest extends TestCase {

	public function test_to_ai_client_messages_returns_input_unchanged_when_sdk_absent(): void {
		// Without wp-ai-client loaded, the adapter passes the transcript through.
		$input  = array( array( 'role' => 'user', 'content' => 'hi' ) );
		$output = OpenclaWP_Message_Adapter::to_ai_client_messages( $input );
		$this->assertSame( $input, $output );
	}

	public function test_to_ai_client_messages_round_trips_tool_mediation_parts(): void {
		self::install_ai_client_stubs();

		$output = OpenclaWP_Message_Adapter::to_ai_client_messages(
			array(
				array( 'role' => 'user', 'content' => 'Run the echo tool.' ),
				array(
					'type'     => 'tool_call',
					'payload'  => array(
						'tool_name'  => 'client/openclawp__echo',
						'parameters' => array( 'text' => 'hello' ),
					),
					'metadata' => array( 'tool_call_id' => 'call-1' ),
				),
				array(
					'type'     => 'tool_result',
					'payload'  => array(
						'tool_name' => 'client/openclawp__echo',
						'result'    => array( 'ok' => true ),
					),
					'metadata' => array( 'tool_call_id' => 'call-1' ),
				),
			)
		);

		$this->assertCount( 3, $output );

		$call = $output[1]->getParts()[0]->getContent();
		$this->assertInstanceOf( \WordPress\AiClient\Tools\DTO\FunctionCall::class, $call );
		$this->assertSame( 'call-1', $call->getId() );
		$this->assertSame( 'openclawp__echo', $call->getName() );
		$this->assertSame( array( 'text' => 'hello' ), $call->getArgs() );

		$response = $output[2]->getParts()[0]->getContent();
		$this->assertInstanceOf( \WordPress\AiClient\Tools\DTO\FunctionResponse::class, $response );
		$this->assertSame( 'call-1', $response->getId() );
		$this->assertSame( 'openclawp__echo', $response->getName() );
		$this->assertSame( array( 'ok' => true ), $response->getResponse() );
	}

	public function test_to_ai_client_messages_replays_empty_tool_parameters_as_object(): void {
		self::install_ai_client_stubs();

		$output = OpenclaWP_Message_Adapter::to_ai_client_messages(
			array(
				array(
					'type'     => 'tool_call',
					'payload'  => array(
						'tool_name'  => 'client/openclawp__current-context',
						'parameters' => array(),
					),
					'metadata' => array( 'tool_call_id' => 'call-empty' ),
				),
			)
		);

		$call = $output[0]->getParts()[0]->getContent();
		$this->assertInstanceOf( \stdClass::class, $call->getArgs() );
		$this->assertSame( '{}', json_encode( $call->getArgs() ) );
	}

	public function test_last_assistant_text_returns_most_recent_assistant_message(): void {
		$messages = array(
			array( 'role' => 'user',      'content' => 'q1' ),
			array( 'role' => 'assistant', 'content' => 'a1' ),
			array( 'role' => 'user',      'content' => 'q2' ),
			array( 'role' => 'assistant', 'content' => 'a2-most-recent' ),
		);

		$this->assertSame( 'a2-most-recent', OpenclaWP_Message_Adapter::last_assistant_text( $messages ) );
	}

	public function test_last_assistant_text_is_empty_when_no_assistant_messages(): void {
		$messages = array(
			array( 'role' => 'user', 'content' => 'still waiting' ),
		);

		$this->assertSame( '', OpenclaWP_Message_Adapter::last_assistant_text( $messages ) );
	}

	public function test_last_assistant_text_skips_empty_assistant_then_returns_earlier_one(): void {
		$messages = array(
			array( 'role' => 'assistant', 'content' => 'kept' ),
			array( 'role' => 'user',      'content' => 'q' ),
			array( 'role' => 'assistant', 'content' => '' ),
		);

		$this->assertSame( 'kept', OpenclaWP_Message_Adapter::last_assistant_text( $messages ) );
	}

	public function test_last_assistant_text_concatenates_array_content(): void {
		$messages = array(
			array(
				'role'    => 'assistant',
				'content' => array(
					array( 'text' => 'one ' ),
					'two',
				),
			),
		);

		$this->assertSame( 'one two', OpenclaWP_Message_Adapter::last_assistant_text( $messages ) );
	}

	public function test_last_assistant_text_handles_string_content(): void {
		$messages = array(
			array( 'role' => 'assistant', 'content' => 'plain string reply' ),
		);

		$this->assertSame( 'plain string reply', OpenclaWP_Message_Adapter::last_assistant_text( $messages ) );
	}

	private static function install_ai_client_stubs(): void {
		$aliases = array(
			AiClientStubs\Message::class          => 'WordPress\\AiClient\\Messages\\DTO\\Message',
			AiClientStubs\MessagePart::class      => 'WordPress\\AiClient\\Messages\\DTO\\MessagePart',
			AiClientStubs\MessageRoleEnum::class  => 'WordPress\\AiClient\\Messages\\Enums\\MessageRoleEnum',
			AiClientStubs\FunctionCall::class     => 'WordPress\\AiClient\\Tools\\DTO\\FunctionCall',
			AiClientStubs\FunctionResponse::class => 'WordPress\\AiClient\\Tools\\DTO\\FunctionResponse',
		);

		foreach ( $aliases as $source => $alias ) {
			if ( ! class_exists( '\\' . $alias, false ) ) {
				class_alias( $source, $alias );
			}
		}
	}
}

namespace OpenclaWP\Tests\Unit\AiClientStubs;

final class MessageRoleEnum {
	private string $value;

	private function __construct( string $value ) {
		$this->value = $value;
	}

	public static function user(): self {
		return new self( 'user' );
	}

	public static function model(): self {
		return new self( 'model' );
	}

	public function value(): string {
		return $this->value;
	}
}

final class Message {
	private MessageRoleEnum $role;
	/** @var array<int, MessagePart> */
	private array $parts;

	/**
	 * @param array<int, MessagePart> $parts
	 */
	public function __construct( MessageRoleEnum $role, array $parts ) {
		$this->role  = $role;
		$this->parts = $parts;
	}

	public function getRole(): MessageRoleEnum {
		return $this->role;
	}

	/**
	 * @return array<int, MessagePart>
	 */
	public function getParts(): array {
		return $this->parts;
	}
}

final class MessagePart {
	/** @var mixed */
	private $content;

	public function __construct( $content ) {
		$this->content = $content;
	}

	public function getContent() {
		return $this->content;
	}
}

final class FunctionCall {
	private ?string $id;
	private string $name;
	/** @var mixed */
	private $args;

	public function __construct( ?string $id, string $name, $args ) {
		$this->id   = $id;
		$this->name = $name;
		$this->args = $args;
	}

	public function getId(): ?string {
		return $this->id;
	}

	public function getName(): string {
		return $this->name;
	}

	public function getArgs() {
		return $this->args;
	}
}

final class FunctionResponse {
	private ?string $id;
	private ?string $name;
	/** @var mixed */
	private $response;

	public function __construct( ?string $id, ?string $name, $response ) {
		$this->id       = $id;
		$this->name     = $name;
		$this->response = $response;
	}

	public function getId(): ?string {
		return $this->id;
	}

	public function getName(): ?string {
		return $this->name;
	}

	public function getResponse() {
		return $this->response;
	}
}
