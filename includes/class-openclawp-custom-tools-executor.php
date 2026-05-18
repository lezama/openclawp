<?php
/**
 * Execute a custom tool against agent-supplied arguments.
 *
 * Security model — `{{parameter}}` substitution is context-aware. We never
 * do a single string-format pass against the assembled request, because
 * that would let an agent stash newlines / quote breaks in one parameter
 * and have them land inside a header value or a JSON key. Instead:
 *
 *   - URL path / query   → each substituted value is `rawurlencode()`d.
 *   - Header values      → each substituted value has CR/LF stripped and
 *                          is appended to the header *value* only — header
 *                          names are not templatable.
 *   - JSON body          → the template is parsed as JSON, then each
 *                          `{{param}}` token is replaced as a typed value
 *                          (the JSON encoder handles escaping).
 *   - Form / raw body    → substituted values are `rawurlencode()`d (form)
 *                          or left as-is with control-char stripping (raw).
 *
 * Output handling is JSONPath ("dot-path", subset — `$.foo.bar[0]`) or a
 * regex with a configurable capture group. We never `eval()` or
 * `unserialize()` anything in the response.
 *
 * @package OpenclaWP
 * @since   0.7.0
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Custom_Tools_Executor {

	/**
	 * Execute an HTTP custom tool.
	 *
	 * @param array $spec  Normalised spec from OpenclaWP_Custom_Tools_Store.
	 * @param array $input Agent-supplied arguments.
	 * @return array|\WP_Error Result with `raw_response` and `output` keys.
	 */
	public static function execute( array $spec, array $input ) {
		if ( ( $spec['type'] ?? '' ) !== OpenclaWP_Custom_Tools_Store::TYPE_HTTP ) {
			return new \WP_Error(
				'unsupported_type',
				/* translators: %s is a tool type slug */
				sprintf( __( 'tool type `%s` is not executable', 'openclawp' ), (string) ( $spec['type'] ?? '' ) )
			);
		}

		$http = is_array( $spec['http'] ?? null ) ? $spec['http'] : array();

		$method = strtoupper( (string) ( $http['method'] ?? 'GET' ) );
		$url    = self::substitute_url( (string) ( $http['url'] ?? '' ), $input );

		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return new \WP_Error( 'invalid_url', __( 'tool URL is empty or invalid after substitution', 'openclawp' ) );
		}

		$headers = array();
		foreach ( (array) ( $http['headers'] ?? array() ) as $name => $value ) {
			$name = (string) $name;
			if ( ! self::is_safe_header_name( $name ) ) {
				continue;
			}
			$substituted      = self::substitute_header_value( (string) $value, $input );
			$headers[ $name ] = $substituted;
		}

		$auth = is_array( $spec['auth'] ?? null ) ? $spec['auth'] : array();
		if ( ( $auth['mode'] ?? '' ) === OpenclaWP_Custom_Tools_Store::AUTH_BEARER ) {
			$option_key = sanitize_key( (string) ( $auth['token_option'] ?? '' ) );
			if ( '' !== $option_key ) {
				$token = (string) get_option( $option_key, '' );
				if ( '' !== $token ) {
					$headers['Authorization'] = 'Bearer ' . self::strip_newlines( $token );
				}
			}
		}

		$body_type = (string) ( $http['body_type'] ?? 'none' );
		$body_raw  = (string) ( $http['body'] ?? '' );

		$body = null;
		switch ( $body_type ) {
			case 'json':
				$built = self::build_json_body( $body_raw, $input );
				if ( is_wp_error( $built ) ) {
					return $built;
				}
				$body = $built;
				if ( ! isset( $headers['Content-Type'] ) && ! isset( $headers['content-type'] ) ) {
					$headers['Content-Type'] = 'application/json';
				}
				break;

			case 'form':
				$body = self::substitute_form_body( $body_raw, $input );
				if ( ! isset( $headers['Content-Type'] ) && ! isset( $headers['content-type'] ) ) {
					$headers['Content-Type'] = 'application/x-www-form-urlencoded';
				}
				break;

			case 'raw':
				$body = self::substitute_raw_body( $body_raw, $input );
				break;

			case 'none':
			default:
				$body = null;
				break;
		}

		$args = array(
			'method'      => $method,
			'headers'     => $headers,
			'timeout'     => 15,
			'redirection' => 3,
			'user-agent'  => 'openclaWP/' . OPENCLAWP_VERSION . ' (custom-tool)',
		);
		if ( null !== $body ) {
			$args['body'] = $body;
		}

		/**
		 * Filter the `wp_remote_request` args for a custom tool before it fires.
		 *
		 * Lets adopters add timeouts, retries, proxies, or extra headers.
		 *
		 * @since 0.7.0
		 *
		 * @param array  $args  Request args.
		 * @param array  $spec  Tool spec.
		 * @param array  $input Agent-supplied arguments.
		 * @param string $url   Substituted URL.
		 */
		$args = (array) apply_filters( 'openclawp_custom_tool_request_args', $args, $spec, $input, $url );

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status       = (int) wp_remote_retrieve_response_code( $response );
		$resp_body    = (string) wp_remote_retrieve_body( $response );
		$resp_headers = wp_remote_retrieve_headers( $response );

		// Apply output handling. The "agent-visible output" is what we feed
		// back into the LLM; the raw response stays in the result for the
		// test panel + debugging.
		$output = self::shape_output( $spec['output'] ?? array(), $resp_body );

		return array(
			'raw_response' => array(
				'status'  => $status,
				'headers' => self::headers_to_array( $resp_headers ),
				'body'    => $resp_body,
			),
			'output'       => $output,
		);
	}

	/**
	 * Substitute {{tokens}} in a URL — every value is rawurlencoded.
	 */
	private static function substitute_url( string $url, array $input ): string {
		return self::substitute(
			$url,
			$input,
			static function ( $value ): string {
				return rawurlencode( self::scalar_to_string( $value ) );
			}
		);
	}

	/**
	 * Substitute {{tokens}} in a header value. Strips CR/LF from the
	 * substituted values to prevent header / response splitting.
	 */
	private static function substitute_header_value( string $value, array $input ): string {
		return self::substitute(
			$value,
			$input,
			static function ( $value ): string {
				return self::strip_newlines( self::scalar_to_string( $value ) );
			}
		);
	}

	/**
	 * Build a JSON body by parsing the template as JSON, then substituting
	 * placeholders at the *value* layer. Empty template → all-input body.
	 *
	 * @return string|\WP_Error
	 */
	public static function build_json_body( string $template, array $input ) {
		$template = trim( $template );
		if ( '' === $template ) {
			return (string) wp_json_encode( $input );
		}

		$parsed = json_decode( $template, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new \WP_Error(
				'invalid_json_template',
				/* translators: %s is a JSON parser error */
				sprintf( __( 'tool JSON body template is not valid JSON: %s', 'openclawp' ), json_last_error_msg() )
			);
		}

		$substituted = self::substitute_json_value( $parsed, $input );
		return (string) wp_json_encode( $substituted );
	}

	/**
	 * Walk a decoded JSON tree, replacing {{tokens}} in string leaves with
	 * their typed values. A whole-string token like `"{{count}}"` becomes
	 * the *typed* value of $input['count']; partial tokens like
	 * `"Hello {{name}}!"` stringify the value.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	private static function substitute_json_value( $value, array $input ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				$out[ $k ] = self::substitute_json_value( $v, $input );
			}
			return $out;
		}

		if ( ! is_string( $value ) ) {
			return $value;
		}

		// Whole-string token: preserve type.
		if ( preg_match( '/^\{\{\s*([a-zA-Z0-9_\-\.]+)\s*\}\}$/', $value, $m ) ) {
			$resolved = self::resolve_input( $m[1], $input );
			return null === $resolved ? '' : $resolved;
		}

		// Partial substitution: stringify each token.
		return self::substitute(
			$value,
			$input,
			static function ( $value ): string {
				return self::scalar_to_string( $value );
			}
		);
	}

	/**
	 * Substitute {{tokens}} in a form-encoded body template — each value is
	 * rawurlencoded so e.g. `foo={{user}}` survives an injection attempt.
	 */
	private static function substitute_form_body( string $template, array $input ): string {
		return self::substitute(
			$template,
			$input,
			static function ( $value ): string {
				return rawurlencode( self::scalar_to_string( $value ) );
			}
		);
	}

	/**
	 * Substitute {{tokens}} in a raw body template. Control chars are
	 * stripped from substituted values so that a parameter cannot inject
	 * a fake HTTP chunk or null-byte truncate.
	 */
	private static function substitute_raw_body( string $template, array $input ): string {
		return self::substitute(
			$template,
			$input,
			static function ( $value ): string {
				$str = self::scalar_to_string( $value );
				// Strip NUL, backspace, vertical tab, form feed, etc.
				return preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $str ) ?? $str;
			}
		);
	}

	/**
	 * Generic {{token}} substitution. The encoder closure decides how each
	 * substituted scalar is rendered in the target context.
	 *
	 * @param callable $encode fn(mixed $value): string
	 */
	private static function substitute( string $template, array $input, callable $encode ): string {
		return (string) preg_replace_callback(
			'/\{\{\s*([a-zA-Z0-9_\-\.]+)\s*\}\}/',
			static function ( array $matches ) use ( $input, $encode ): string {
				$resolved = self::resolve_input( $matches[1], $input );
				if ( null === $resolved ) {
					return '';
				}
				return $encode( $resolved );
			},
			$template
		);
	}

	/**
	 * Resolve a dot path against the agent-supplied input map. Supports
	 * `name`, `nested.field`, and array indices like `items.0.id`.
	 *
	 * @return mixed|null
	 */
	private static function resolve_input( string $path, array $input ) {
		$segments = explode( '.', $path );
		$cursor   = $input;
		foreach ( $segments as $segment ) {
			if ( is_array( $cursor ) && array_key_exists( $segment, $cursor ) ) {
				$cursor = $cursor[ $segment ];
				continue;
			}
			return null;
		}
		return $cursor;
	}

	/**
	 * Coerce a scalar (or array) input value into a string for substitution.
	 */
	private static function scalar_to_string( $value ): string {
		if ( is_string( $value ) ) {
			return $value;
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( null === $value ) {
			return '';
		}
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}
		// Arrays / objects: JSON-encode. Agents that pass a structured value
		// where a scalar is expected get a deterministic JSON projection
		// instead of a PHP "Array" stringification.
		return (string) wp_json_encode( $value );
	}

	/**
	 * Apply the spec's `output` rule to an HTTP response body.
	 *
	 * @return string|array Agent-visible output payload.
	 */
	public static function shape_output( array $output, string $body ) {
		$mode = (string) ( $output['mode'] ?? OpenclaWP_Custom_Tools_Store::OUTPUT_RAW );

		if ( OpenclaWP_Custom_Tools_Store::OUTPUT_JSONPATH === $mode ) {
			$decoded = json_decode( $body, true );
			if ( ! is_array( $decoded ) ) {
				return array( 'error' => 'response body is not JSON; cannot apply JSONPath' );
			}
			$path      = (string) ( $output['path'] ?? '' );
			$extracted = self::apply_jsonpath( $decoded, $path );
			return array( 'extracted' => $extracted );
		}

		if ( OpenclaWP_Custom_Tools_Store::OUTPUT_REGEX === $mode ) {
			$pattern  = (string) ( $output['pattern'] ?? '' );
			$group    = (int) ( $output['group'] ?? 0 );
			$delim    = '~';
			$compiled = $delim . str_replace( $delim, '\\' . $delim, $pattern ) . $delim . 'is';
			$matches  = array();
			// Suppress warnings so a malformed pattern returns a typed error
			// to the caller instead of fataling.
			$result = @preg_match( $compiled, $body, $matches );
			if ( false === $result ) {
				return array( 'error' => 'regex pattern is invalid' );
			}
			if ( 0 === $result ) {
				return array( 'matched' => false );
			}
			return array(
				'matched'  => true,
				'captured' => $matches[ $group ] ?? '',
			);
		}

		// Raw passthrough — return the body verbatim so the LLM can read it.
		return array( 'body' => $body );
	}

	/**
	 * Tiny JSONPath evaluator — supports dot keys and `[n]` indices only.
	 * Deliberately limited: no filters, no recursive descent, no wildcards.
	 */
	public static function apply_jsonpath( array $data, string $path ) {
		$path = trim( $path );
		if ( '' === $path || '$' === $path ) {
			return $data;
		}
		// Normalise `$.a.b[0]` → tokens `['a','b','0']`.
		$path     = preg_replace( '/^\$\.?/', '', $path );
		$path     = preg_replace_callback(
			'/\[(\d+)\]/',
			static fn( array $m ): string => '.' . $m[1],
			$path
		);
		$segments = array_values( array_filter( explode( '.', (string) $path ), static fn( $s ) => '' !== $s ) );

		$cursor = $data;
		foreach ( $segments as $seg ) {
			if ( is_array( $cursor ) && array_key_exists( $seg, $cursor ) ) {
				$cursor = $cursor[ $seg ];
				continue;
			}
			return null;
		}
		return $cursor;
	}

	private static function strip_newlines( string $value ): string {
		return (string) preg_replace( '/[\r\n]+/', '', $value );
	}

	private static function is_safe_header_name( string $name ): bool {
		// RFC 7230 token: alpha, digit, !#$%&'*+-.^_`|~
		return (bool) preg_match( "/^[A-Za-z0-9!#\$%&'*+\-.^_`|~]+$/", $name );
	}

	/**
	 * @param mixed $headers WP_HTTP_Requests_Response_Headers or array
	 */
	private static function headers_to_array( $headers ): array {
		if ( is_array( $headers ) ) {
			return $headers;
		}
		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			return (array) $headers->getAll();
		}
		return array();
	}
}
