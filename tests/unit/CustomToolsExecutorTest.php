<?php
/**
 * Pure-PHP unit tests for OpenclaWP_Custom_Tools_Executor.
 *
 * Focus: `{{parameter}}` substitution and output handling. We exercise the
 * pure helpers (substitute_*, build_json_body, shape_output, apply_jsonpath)
 * directly so they don't need a running WordPress.
 *
 * The integration path (create a tool via the API, exercise it as a
 * registered ability) lives in tests/smoke.php — see the assertions guarded
 * by `class_exists( 'OpenclaWP_Custom_Tools_Store' )`.
 *
 * @package OpenclaWP\Tests
 */

declare( strict_types=1 );

namespace OpenclaWP\Tests\Unit;

use OpenclaWP_Custom_Tools_Executor;
use OpenclaWP_Custom_Tools_Store;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers OpenclaWP_Custom_Tools_Executor
 * @covers OpenclaWP_Custom_Tools_Store
 */
final class CustomToolsExecutorTest extends TestCase {

	private static function invoke_private( string $method, array $args = array() ) {
		$rc = new ReflectionClass( OpenclaWP_Custom_Tools_Executor::class );
		$m  = $rc->getMethod( $method );
		$m->setAccessible( true );
		return $m->invoke( null, ...$args );
	}

	// ---------------------------------------------------------------------
	// URL substitution: every value rawurlencoded
	// ---------------------------------------------------------------------

	public function test_url_substitution_rawurlencodes_each_value(): void {
		$out = self::invoke_private(
			'substitute_url',
			array( 'https://example.com/u/{{name}}/repos', array( 'name' => 'foo bar' ) )
		);
		$this->assertSame( 'https://example.com/u/foo%20bar/repos', $out );
	}

	public function test_url_substitution_blocks_path_traversal_injection(): void {
		// Attempt to escape the path with `/../admin?foo=` — every char gets
		// percent-encoded, so the original path structure is preserved.
		$out = self::invoke_private(
			'substitute_url',
			array( 'https://api.example.com/users/{{user}}', array( 'user' => '../admin?leak=1' ) )
		);
		$this->assertStringContainsString( '/users/..%2Fadmin%3Fleak%3D1', $out );
		$this->assertStringNotContainsString( '/users/../admin', $out );
	}

	public function test_url_substitution_blocks_query_injection(): void {
		// Try to inject an extra query parameter.
		$out = self::invoke_private(
			'substitute_url',
			array( 'https://api.example.com/?q={{q}}', array( 'q' => 'cats&admin=1' ) )
		);
		$this->assertSame( 'https://api.example.com/?q=cats%26admin%3D1', $out );
	}

	// ---------------------------------------------------------------------
	// Header substitution: CRLF stripped (header / response splitting)
	// ---------------------------------------------------------------------

	public function test_header_substitution_strips_crlf_injection(): void {
		// Classic header-splitting attempt: embed CRLF + a forged header.
		$malicious = "user-token\r\nX-Admin: yes";
		$out       = self::invoke_private(
			'substitute_header_value',
			array( 'token={{tok}}', array( 'tok' => $malicious ) )
		);
		$this->assertSame( 'token=user-tokenX-Admin: yes', $out );
		$this->assertStringNotContainsString( "\r", $out );
		$this->assertStringNotContainsString( "\n", $out );
	}

	// ---------------------------------------------------------------------
	// JSON body substitution: typed, escaped values
	// ---------------------------------------------------------------------

	public function test_json_body_substitution_escapes_quotes(): void {
		// An agent passes a string containing a literal quote. Naive
		// string-format substitution would break the JSON. We parse first,
		// so the JSON encoder handles it.
		$template = '{"text": "{{msg}}"}';
		$body     = OpenclaWP_Custom_Tools_Executor::build_json_body(
			$template,
			array( 'msg' => 'they said "hi" and \\bye' )
		);
		$this->assertIsString( $body );
		$decoded = json_decode( $body, true );
		$this->assertIsArray( $decoded );
		// Round-trips cleanly through the JSON encoder/decoder.
		$this->assertSame( 'they said "hi" and \\bye', $decoded['text'] );
	}

	public function test_json_body_substitution_blocks_structural_injection(): void {
		// Try to inject a sibling key by closing the string + adding a comma.
		// With value-layer substitution this CAN'T escape the string slot.
		$template = '{"text": "{{msg}}"}';
		$body     = OpenclaWP_Custom_Tools_Executor::build_json_body(
			$template,
			array( 'msg' => 'hello", "is_admin": true, "ignored": "' )
		);
		$decoded = json_decode( $body, true );
		$this->assertIsArray( $decoded );
		// The user's payload survives intact in `text`; the forged keys
		// never appear at the top level.
		$this->assertArrayHasKey( 'text', $decoded );
		$this->assertArrayNotHasKey( 'is_admin', $decoded );
		$this->assertSame( 'hello", "is_admin": true, "ignored": "', $decoded['text'] );
	}

	public function test_json_body_whole_string_token_preserves_type(): void {
		// `{{count}}` as the whole string slot should yield the int, not "5".
		$template = '{"count": "{{count}}"}';
		$body     = OpenclaWP_Custom_Tools_Executor::build_json_body(
			$template,
			array( 'count' => 5 )
		);
		$decoded = json_decode( $body, true );
		$this->assertSame( 5, $decoded['count'] );
	}

	public function test_json_body_partial_token_stringifies(): void {
		// Mixed string: partial template should stringify.
		$template = '{"text": "you have {{count}} items"}';
		$body     = OpenclaWP_Custom_Tools_Executor::build_json_body(
			$template,
			array( 'count' => 5 )
		);
		$decoded = json_decode( $body, true );
		$this->assertSame( 'you have 5 items', $decoded['text'] );
	}

	public function test_json_body_invalid_template_returns_error(): void {
		$result = OpenclaWP_Custom_Tools_Executor::build_json_body(
			'{"broken": "',
			array()
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_json_body_empty_template_defaults_to_input(): void {
		$body = OpenclaWP_Custom_Tools_Executor::build_json_body(
			'',
			array( 'a' => 1, 'b' => 'two' )
		);
		$this->assertSame( array( 'a' => 1, 'b' => 'two' ), json_decode( $body, true ) );
	}

	public function test_missing_input_substitutes_to_empty_string(): void {
		$out = self::invoke_private(
			'substitute_url',
			array( 'https://x.test/{{missing}}', array() )
		);
		$this->assertSame( 'https://x.test/', $out );
	}

	public function test_dot_path_resolves_nested_input(): void {
		$body = OpenclaWP_Custom_Tools_Executor::build_json_body(
			'{"owner": "{{repo.owner}}", "name": "{{repo.name}}"}',
			array( 'repo' => array( 'owner' => 'lezama', 'name' => 'openclawp' ) )
		);
		$this->assertSame(
			array( 'owner' => 'lezama', 'name' => 'openclawp' ),
			json_decode( $body, true )
		);
	}

	// ---------------------------------------------------------------------
	// Output handling
	// ---------------------------------------------------------------------

	public function test_jsonpath_extracts_nested_value(): void {
		$out = OpenclaWP_Custom_Tools_Executor::shape_output(
			array( 'mode' => OpenclaWP_Custom_Tools_Store::OUTPUT_JSONPATH, 'path' => '$.current_weather.temperature' ),
			(string) json_encode( array( 'current_weather' => array( 'temperature' => 18.4 ) ) )
		);
		$this->assertSame( 18.4, $out['extracted'] );
	}

	public function test_jsonpath_extracts_array_index(): void {
		$out = OpenclaWP_Custom_Tools_Executor::shape_output(
			array( 'mode' => OpenclaWP_Custom_Tools_Store::OUTPUT_JSONPATH, 'path' => '$.items[1].name' ),
			(string) json_encode(
				array(
					'items' => array(
						array( 'name' => 'a' ),
						array( 'name' => 'b' ),
					),
				)
			)
		);
		$this->assertSame( 'b', $out['extracted'] );
	}

	public function test_jsonpath_missing_path_returns_null(): void {
		$out = OpenclaWP_Custom_Tools_Executor::shape_output(
			array( 'mode' => OpenclaWP_Custom_Tools_Store::OUTPUT_JSONPATH, 'path' => '$.nope' ),
			'{"present": 1}'
		);
		$this->assertNull( $out['extracted'] );
	}

	public function test_jsonpath_non_json_body_returns_error_payload(): void {
		$out = OpenclaWP_Custom_Tools_Executor::shape_output(
			array( 'mode' => OpenclaWP_Custom_Tools_Store::OUTPUT_JSONPATH, 'path' => '$' ),
			'plain text response'
		);
		$this->assertArrayHasKey( 'error', $out );
	}

	public function test_regex_capture_returns_named_group(): void {
		$out = OpenclaWP_Custom_Tools_Executor::shape_output(
			array(
				'mode'    => OpenclaWP_Custom_Tools_Store::OUTPUT_REGEX,
				'pattern' => 'token=([A-Z0-9]+)',
				'group'   => 1,
			),
			'response: token=ABC123 trailing'
		);
		$this->assertTrue( $out['matched'] );
		$this->assertSame( 'ABC123', $out['captured'] );
	}

	public function test_regex_no_match_returns_false_flag(): void {
		$out = OpenclaWP_Custom_Tools_Executor::shape_output(
			array(
				'mode'    => OpenclaWP_Custom_Tools_Store::OUTPUT_REGEX,
				'pattern' => 'never-matches',
				'group'   => 0,
			),
			'whatever'
		);
		$this->assertFalse( $out['matched'] );
	}

	public function test_regex_invalid_pattern_returns_error(): void {
		$out = OpenclaWP_Custom_Tools_Executor::shape_output(
			array(
				'mode'    => OpenclaWP_Custom_Tools_Store::OUTPUT_REGEX,
				// Unbalanced group — preg_match returns false; we surface as error.
				'pattern' => '(unbalanced',
				'group'   => 0,
			),
			'whatever'
		);
		$this->assertArrayHasKey( 'error', $out );
	}

	public function test_raw_output_returns_body_verbatim(): void {
		$out = OpenclaWP_Custom_Tools_Executor::shape_output(
			array( 'mode' => OpenclaWP_Custom_Tools_Store::OUTPUT_RAW ),
			'hello world'
		);
		$this->assertSame( 'hello world', $out['body'] );
	}

	// ---------------------------------------------------------------------
	// Spec normalisation defaults to safe values
	// ---------------------------------------------------------------------

	public function test_normalise_spec_defaults_to_administrator_only_when_empty(): void {
		$spec = OpenclaWP_Custom_Tools_Store::normalise_spec(
			array(
				'type'          => OpenclaWP_Custom_Tools_Store::TYPE_HTTP,
				'http'          => array( 'method' => 'GET', 'url' => 'https://x.test/' ),
				'allowed_roles' => array(),
			)
		);
		$this->assertIsArray( $spec );
		$this->assertSame( array( 'administrator' ), $spec['allowed_roles'] );
	}

	public function test_normalise_spec_rejects_unsupported_method(): void {
		$result = OpenclaWP_Custom_Tools_Store::normalise_spec(
			array(
				'type' => OpenclaWP_Custom_Tools_Store::TYPE_HTTP,
				'http' => array( 'method' => 'CONNECT', 'url' => 'https://x.test/' ),
			)
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_method', $result->get_error_code() );
	}

	public function test_normalise_spec_rejects_non_http_type_for_v1(): void {
		$result = OpenclaWP_Custom_Tools_Store::normalise_spec(
			array(
				'type' => 'wp-hook',
				'http' => array( 'method' => 'GET', 'url' => 'https://x.test/' ),
			)
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'unsupported_type', $result->get_error_code() );
	}

	public function test_normalise_spec_requires_url(): void {
		$result = OpenclaWP_Custom_Tools_Store::normalise_spec(
			array(
				'type' => OpenclaWP_Custom_Tools_Store::TYPE_HTTP,
				'http' => array( 'method' => 'GET', 'url' => '' ),
			)
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_url', $result->get_error_code() );
	}
}
