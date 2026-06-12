<?php
/**
 * Voice session credentials endpoint.
 *
 * Mints a short-lived (ephemeral) Gemini Live token from the provider key
 * that WordPress already owns via the AI Client credential store, so realtime
 * voice clients (the voice-gateway sidecar, or eventually a browser) never
 * see the long-lived API key.
 *
 * This is deliberately shaped as a userland polyfill of what php-ai-client /
 * wp-ai-client could support natively: a provider capability for ephemeral
 * client credentials for realtime sessions. See
 * docs/voice-gateway-core-proposal.md for the core pitch; if that lands, the
 * body of `mint_google_token()` collapses into a one-line registry call.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Voice_Session {

	private const NAMESPACE = 'openclawp/v1';

	/**
	 * Default Live model. Mirrors the voice-gateway default; override with
	 * the `openclawp_voice_session_model` filter or the request param.
	 */
	private const DEFAULT_MODEL = 'gemini-3.1-flash-live-preview';

	private const GOOGLE_API_BASE = 'https://generativelanguage.googleapis.com';

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/voice/session',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
				'args'                => array(
					'agent' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_title',
					),
					'model' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	public static function check_permission( WP_REST_Request $request ) {
		$default = current_user_can( 'manage_options' );

		/**
		 * Filters whether the current user may mint a voice session
		 * credential. Mirrors `openclawp_agenttic_bridge_permission`: a voice
		 * client that may chat with the agent should normally also be allowed
		 * here.
		 *
		 * @since 0.6.0
		 *
		 * @param bool            $allowed Default: manage_options.
		 * @param WP_REST_Request $request Current request.
		 */
		$allowed = (bool) apply_filters( 'openclawp_voice_session_permission', $default, $request );

		if ( ! $allowed ) {
			return new WP_Error(
				'openclawp_forbidden',
				__( 'You do not have permission to start a voice session.', 'openclawp' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	public static function handle( WP_REST_Request $request ) {
		$model = (string) $request->get_param( 'model' );
		if ( '' === $model ) {
			$model = self::DEFAULT_MODEL;
		}

		/**
		 * Filters the Gemini Live model a voice session is constrained to.
		 *
		 * @since 0.6.0
		 *
		 * @param string          $model   Model slug (no `models/` prefix).
		 * @param WP_REST_Request $request Current request.
		 */
		$model = (string) apply_filters( 'openclawp_voice_session_model', $model, $request );

		$api_key = self::get_provider_api_key( 'google' );
		if ( '' === $api_key ) {
			return new WP_Error(
				'openclawp_voice_no_credentials',
				__( 'No Google AI credentials are configured in the AI Client.', 'openclawp' ),
				array( 'status' => 501 )
			);
		}

		$token = self::mint_google_token( $api_key, $model );
		if ( ! is_wp_error( $token ) ) {
			return rest_ensure_response(
				array_merge(
					array(
						'provider'   => 'google',
						'model'      => $model,
						'credential' => $token,
						'ws_url'     => 'wss://' . wp_parse_url( self::GOOGLE_API_BASE, PHP_URL_HOST )
							. '/ws/google.ai.generativelanguage.v1alpha.GenerativeService.BidiGenerateContent',
					),
					self::agent_info( (string) $request->get_param( 'agent' ) )
				)
			);
		}

		/**
		 * Filters whether the endpoint may fall back to returning the
		 * long-lived API key when ephemeral-token minting fails. Off by
		 * default on purpose: the core-shaped contract is that the stored
		 * key never leaves the server.
		 *
		 * @since 0.6.0
		 *
		 * @param bool            $allowed Default false.
		 * @param WP_REST_Request $request Current request.
		 */
		if ( apply_filters( 'openclawp_voice_session_allow_api_key', false, $request ) ) {
			return rest_ensure_response(
				array_merge(
					array(
						'provider'   => 'google',
						'model'      => $model,
						'credential' => array(
							'type'  => 'api_key',
							'value' => $api_key,
						),
						'ws_url'     => 'wss://' . wp_parse_url( self::GOOGLE_API_BASE, PHP_URL_HOST )
							. '/ws/google.ai.generativelanguage.v1beta.GenerativeService.BidiGenerateContent',
					),
					self::agent_info( (string) $request->get_param( 'agent' ) )
				)
			);
		}

		return $token;
	}

	/**
	 * Reads a provider API key from the AI Client credential store — the
	 * core-owned path: WP stores it, `AiClient::defaultRegistry()` exposes it
	 * to PHP, and it is never serialized into a REST response.
	 */
	private static function get_provider_api_key( string $provider_id ): string {
		if ( ! class_exists( '\\WordPress\\AiClient\\AiClient' ) ) {
			return '';
		}

		try {
			$registry = \WordPress\AiClient\AiClient::defaultRegistry();
			$auth     = $registry->getProviderRequestAuthentication( $provider_id );
			if ( is_object( $auth ) && method_exists( $auth, 'getApiKey' ) ) {
				return (string) $auth->getApiKey();
			}
		} catch ( Throwable $e ) {
			return '';
		}

		return '';
	}

	/**
	 * Mints a single-use ephemeral token for the Live API
	 * (`v1alpha/auth_tokens`). The token can open exactly one Live session,
	 * constrained to $model, and expires in 30 minutes.
	 *
	 * @return array{type: string, value: string, expires_at: string}|WP_Error
	 */
	private static function mint_google_token( string $api_key, string $model ) {
		$expire_at      = gmdate( 'Y-m-d\TH:i:s\Z', time() + 30 * MINUTE_IN_SECONDS );
		$new_session_by = gmdate( 'Y-m-d\TH:i:s\Z', time() + 2 * MINUTE_IN_SECONDS );

		$body = array(
			'uses'                  => 1,
			'expireTime'            => $expire_at,
			'newSessionExpireTime'  => $new_session_by,
			'liveConnectConstraints' => array(
				'model' => 'models/' . $model,
			),
		);

		/**
		 * Filters the auth_tokens:create request body, e.g. to pin the full
		 * Live config into the token constraints.
		 *
		 * @since 0.6.0
		 *
		 * @param array  $body  Request body.
		 * @param string $model Model slug.
		 */
		$body = (array) apply_filters( 'openclawp_voice_token_request', $body, $model );

		$response = wp_remote_post(
			self::GOOGLE_API_BASE . '/v1alpha/auth_tokens',
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type'   => 'application/json',
					'x-goog-api-key' => $api_key,
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'openclawp_voice_mint_failed',
				$response->get_error_message(),
				array( 'status' => 502 )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || empty( $data['name'] ) ) {
			return new WP_Error(
				'openclawp_voice_mint_failed',
				sprintf(
					/* translators: %d: HTTP status code from the Google API. */
					__( 'Ephemeral token minting failed (HTTP %d).', 'openclawp' ),
					$code
				),
				array(
					'status'   => 502,
					'upstream' => is_array( $data ) ? ( $data['error']['message'] ?? null ) : null,
				)
			);
		}

		return array(
			'type'       => 'ephemeral_token',
			'value'      => (string) $data['name'],
			'expires_at' => $expire_at,
		);
	}

	/**
	 * Optional agent display info so the voice shell can introduce itself
	 * without extra round-trips. Never includes the agent's instructions.
	 *
	 * @return array{agent?: array{slug: string, label: string}}
	 */
	private static function agent_info( string $agent_slug ): array {
		if ( '' === $agent_slug || ! function_exists( 'wp_get_agent' ) ) {
			return array();
		}

		$agent = wp_get_agent( $agent_slug );
		if ( ! $agent ) {
			return array();
		}

		$label = is_object( $agent ) && method_exists( $agent, 'get_label' )
			? (string) $agent->get_label()
			: $agent_slug;

		return array(
			'agent' => array(
				'slug'  => $agent_slug,
				'label' => $label,
			),
		);
	}
}
