<?php
/**
 * WhatsApp transport for openclaWP via openclaw/wacli.
 *
 * `wacli sync --follow --webhook <REST URL> --webhook-secret <secret>` posts each
 * incoming WhatsApp message as JSON to the REST endpoint registered here. We
 * verify the HMAC signature, forward the text to the configured agent through
 * the openclawp/chat ability, then reply by shelling out to `wacli send text`
 * (which delegates to the running sync process so we don't fight over the
 * WhatsApp session lock).
 *
 * Setup:
 *   1. brew install steipete/tap/wacli; wacli auth
 *   2. wp option update openclawp_wacli_agent <slug-of-registered-agent>
 *   3. wp openclawp wacli setup    # prints the wacli sync command to copy
 *   4. Run that wacli sync command in a terminal / launchd / systemd unit.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Wacli_Transport {

	private const REST_NAMESPACE = 'openclawp/v1';
	private const SECRET_OPTION  = 'openclawp_wacli_secret';
	private const AGENT_OPTION   = 'openclawp_wacli_agent';
	private const BINARY_OPTION  = 'openclawp_wacli_binary';

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'openclawp wacli', 'OpenclaWP_Wacli_CLI' );
		}
	}

	public static function register_rest_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/wacli/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_webhook' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function handle_webhook( WP_REST_Request $request ) {
		$secret = (string) get_option( self::SECRET_OPTION, '' );
		if ( '' === $secret ) {
			return new WP_REST_Response( array( 'error' => 'transport_disabled' ), 503 );
		}

		$signature = (string) $request->get_header( 'x_wacli_signature' );
		$body      = (string) $request->get_body();

		if ( ! self::verify_signature( $body, $signature, $secret ) ) {
			return new WP_REST_Response( array( 'error' => 'bad_signature' ), 401 );
		}

		$payload = json_decode( $body, true );
		if ( ! is_array( $payload ) ) {
			return new WP_REST_Response( array( 'error' => 'bad_payload' ), 400 );
		}

		$message = isset( $payload['message'] ) && is_array( $payload['message'] ) ? $payload['message'] : $payload;

		$agent_slug = (string) get_option( self::AGENT_OPTION, '' );
		if ( '' === $agent_slug ) {
			return new WP_REST_Response( array( 'error' => 'agent_not_configured' ), 503 );
		}

		if ( ! class_exists( 'AgentsAPI\\AI\\Channels\\WP_Agent_Channel' ) ) {
			return new WP_REST_Response( array( 'error' => 'agents_api_missing' ), 503 );
		}

		$chat_jid = (string) ( $message['chat_jid'] ?? $message['chat_id'] ?? $message['chat'] ?? '' );
		$reply_to = (string) ( $message['msg_id'] ?? $message['id'] ?? '' );

		// HMAC is the auth gate for this surface — wacli has already proven it
		// holds the shared secret, so allow the chat ability to run without
		// requiring a logged-in admin. The filter scope is bounded to this
		// request via the immediate add_filter / remove_filter dance.
		$grant = static fn() => true;
		add_filter( 'openclawp_chat_ability_permission', $grant );

		try {
			$channel = new OpenclaWP_Wacli_Channel( $agent_slug, $chat_jid, $reply_to );
			$result  = $channel->handle( $message );
		} finally {
			remove_filter( 'openclawp_chat_ability_permission', $grant );
		}

		if ( is_wp_error( $result ) ) {
			$code = $result->get_error_code();
			if ( \AgentsAPI\AI\Channels\WP_Agent_Channel::SILENT_SKIP_CODE === $code ) {
				return self::ack( $result->get_error_message() );
			}
			if ( 'empty_message' === $code ) {
				return self::ack( 'empty_message' );
			}
			return new WP_REST_Response(
				array(
					'error'   => 'agent_error',
					'code'    => $code,
					'message' => $result->get_error_message(),
				),
				500
			);
		}

		return self::ack( 'replied' );
	}

	private static function ack( string $note ): WP_REST_Response {
		return new WP_REST_Response( array( 'ok' => true, 'note' => $note ), 200 );
	}

	public static function verify_signature( string $body, string $header, string $secret ): bool {
		if ( '' === $header ) {
			return false;
		}
		// Header looks like `sha256=<hex>`.
		$parts = explode( '=', $header, 2 );
		if ( 2 !== count( $parts ) || 'sha256' !== strtolower( $parts[0] ) ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $body, $secret );
		return hash_equals( $expected, $parts[1] );
	}

	// is_allowed / session lookup moved to OpenclaWP_Wacli_Channel.

	/**
	 * Shell out to `wacli send text`. Returns true on success or WP_Error on failure.
	 */
	public static function send_via_wacli( string $jid, string $text, string $reply_to_id = '' ) {
		$binary = (string) get_option( self::BINARY_OPTION, 'wacli' );

		$cmd = array( $binary, 'send', 'text', '--json', '--to', $jid, '--message', $text );
		if ( '' !== $reply_to_id ) {
			$cmd[] = '--reply-to';
			$cmd[] = $reply_to_id;
		}

		$escaped = implode( ' ', array_map( 'escapeshellarg', $cmd ) );

		$descriptor_spec = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		$process = proc_open( $escaped, $descriptor_spec, $pipes );
		if ( ! is_resource( $process ) ) {
			return new WP_Error( 'wacli_proc_open_failed', 'Could not start wacli; check the openclawp_wacli_binary option.' );
		}

		fclose( $pipes[0] );
		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$exit = proc_close( $process );

		if ( 0 !== $exit ) {
			return new WP_Error(
				'wacli_send_failed',
				sprintf( 'wacli exited %d: %s', $exit, trim( (string) $stderr ) ),
				array( 'stdout' => $stdout, 'stderr' => $stderr )
			);
		}

		return true;
	}

	/**
	 * Generate-or-return the HMAC secret. Used by the WP-CLI setup helper.
	 */
	public static function ensure_secret(): string {
		$secret = (string) get_option( self::SECRET_OPTION, '' );
		if ( '' !== $secret ) {
			return $secret;
		}
		$secret = wp_generate_password( 48, false );
		update_option( self::SECRET_OPTION, $secret, false );
		return $secret;
	}

	public static function rotate_secret(): string {
		$secret = wp_generate_password( 48, false );
		update_option( self::SECRET_OPTION, $secret, false );
		return $secret;
	}

	public static function webhook_url(): string {
		return rest_url( self::REST_NAMESPACE . '/wacli/webhook' );
	}
}
