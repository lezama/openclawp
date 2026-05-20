<?php
/**
 * WhatsApp Cloud API ingress for openclaWP.
 *
 * Adds a fourth chat surface alongside the block, the openclawp/chat ability,
 * and the REST endpoint: an inbound webhook that dispatches WhatsApp messages
 * to a configured agent and posts the reply back via the Graph API.
 *
 * Off by default. Opt in with:
 *
 *     add_filter( 'openclawp_register_whatsapp', '__return_true' );
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Whatsapp {

	public const OPTION_NAME = 'openclawp_whatsapp_settings';

	private const REST_NAMESPACE = 'openclawp/v1';
	private const REST_ROUTE     = '/whatsapp/webhook';
	private const META_PHONE_KEY = '_openclawp_whatsapp_phone';
	private const DEFAULT_API_VERSION = 'v20.0';

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_settings_menu' ), 20 );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_render_missing_app_secret_notice' ) );
	}

	/**
	 * Warn admins when the WhatsApp channel has live credentials but no
	 * app_secret. In that state inbound webhooks fail closed because
	 * {@see verify_signature()} cannot authenticate Meta's request.
	 */
	public static function maybe_render_missing_app_secret_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = self::settings();
		$configured = '' !== trim( (string) $settings['access_token'] )
			|| '' !== trim( (string) $settings['phone_number_id'] );
		if ( ! $configured ) {
			return;
		}
		if ( '' !== trim( (string) $settings['app_secret'] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
			esc_html__( 'openclaWP WhatsApp:', 'openclawp' ),
			esc_html__( 'Your WhatsApp Cloud API credentials are configured but the App Secret is empty. Inbound webhook requests will be rejected until the Meta app secret is set.', 'openclawp' ),
			esc_url( admin_url( 'admin.php?page=openclawp-whatsapp' ) ),
			esc_html__( 'Open WhatsApp settings', 'openclawp' )
		);
	}

	public static function settings(): array {
		$stored = get_option( self::OPTION_NAME, array() );
		return wp_parse_args(
			is_array( $stored ) ? $stored : array(),
			array(
				'phone_number_id'      => '',
				'app_secret'           => '',
				'access_token'         => '',
				'webhook_verify_token' => '',
				'default_agent'        => '',
				'api_version'          => self::DEFAULT_API_VERSION,
				'user_id'              => 0,
			)
		);
	}

	/* -------------------------------- REST -------------------------------- */

	public static function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'handle_verification' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'handle_inbound' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * Meta verifies the webhook by GETting the URL with hub.* query params.
	 * Echo hub.challenge back when hub.verify_token matches our stored token.
	 */
	public static function handle_verification( WP_REST_Request $request ) {
		$mode      = (string) $request->get_param( 'hub_mode' );
		$challenge = (string) $request->get_param( 'hub_challenge' );
		$token     = (string) $request->get_param( 'hub_verify_token' );

		// Meta sends params with dots (hub.mode); WP_REST_Request normalizes
		// to underscores by default but preserves dotted access too.
		if ( '' === $mode ) {
			$mode      = (string) $request->get_param( 'hub.mode' );
			$challenge = (string) $request->get_param( 'hub.challenge' );
			$token     = (string) $request->get_param( 'hub.verify_token' );
		}

		$expected = self::settings()['webhook_verify_token'];

		if ( 'subscribe' !== $mode || '' === $expected || ! hash_equals( $expected, $token ) ) {
			return new WP_Error(
				'openclawp_whatsapp_verify_failed',
				__( 'WhatsApp webhook verification failed.', 'openclawp' ),
				array( 'status' => 403 )
			);
		}

		// Meta expects the challenge echoed back as plain text without JSON
		// quotes. Returning a string through the REST pipeline JSON-encodes it
		// to `"42abc"`, which Meta rejects. Send the raw body and exit.
		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo $challenge; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- value is the challenge token Meta sent us; bytes are returned verbatim.
		exit;
	}

	/**
	 * Inbound message webhook. Verify HMAC, parse messages, dispatch each
	 * one to the configured agent, send the reply back via the Graph API.
	 */
	public static function handle_inbound( WP_REST_Request $request ) {
		$settings = self::settings();
		$raw_body = $request->get_body();

		if ( ! self::verify_signature( $raw_body, $request->get_header( 'x_hub_signature_256' ) ?? '', $settings['app_secret'] ) ) {
			return new WP_Error( 'openclawp_whatsapp_bad_signature', __( 'Invalid signature.', 'openclawp' ), array( 'status' => 401 ) );
		}

		$payload = json_decode( $raw_body, true );
		if ( ! is_array( $payload ) ) {
			return new WP_REST_Response( array( 'received' => true ), 200 );
		}

		$messages = self::extract_messages( $payload );
		if ( empty( $messages ) ) {
			// Status events / read receipts / non-text messages: ack and move on.
			return new WP_REST_Response( array( 'received' => true, 'processed' => 0 ), 200 );
		}

		$agent_slug = $settings['default_agent'];
		$user_id    = (int) ( $settings['user_id'] ?: get_current_user_id() );
		if ( '' === $agent_slug || ! function_exists( 'wp_get_agent' ) || null === wp_get_agent( $agent_slug ) ) {
			return new WP_Error( 'openclawp_whatsapp_no_agent', __( 'WhatsApp adapter has no configured agent.', 'openclawp' ), array( 'status' => 503 ) );
		}

		$processed = 0;
		foreach ( $messages as $message ) {
			$type = (string) ( $message['type'] ?? 'text' );
			if ( 'image' === $type ) {
				$processed += self::dispatch_image( $message, $user_id, $settings ) ? 1 : 0;
				continue;
			}
			$processed += self::dispatch( $message, $agent_slug, $user_id, $settings ) ? 1 : 0;
		}

		return new WP_REST_Response( array( 'received' => true, 'processed' => $processed ), 200 );
	}

	/* ----------------------------- Signature ------------------------------ */

	public static function verify_signature( string $raw_body, string $signature_header, string $app_secret ): bool {
		if ( '' === $app_secret ) {
			return false;
		}
		if ( 0 !== strpos( $signature_header, 'sha256=' ) ) {
			return false;
		}
		$expected = substr( $signature_header, strlen( 'sha256=' ) );
		$computed = hash_hmac( 'sha256', $raw_body, $app_secret );
		return hash_equals( $expected, $computed );
	}

	/* ----------------------------- Dispatch ------------------------------- */

	/**
	 * Pull `messages[]` entries out of Meta's nested webhook envelope. Returns
	 * tagged entries so the caller can dispatch each by type:
	 *
	 * - text — typed messages + button/list reply payloads (id surfaces in `text`)
	 * - image — image attachments, with media_id + mime_type + optional caption
	 *
	 * Other message types (audio, video, document, location, sticker) currently
	 * pass through as ack-only — consumers can extend by adding their own branch
	 * here or filtering openclawp_extract_messages.
	 *
	 * @return array<int, array{type:string,phone:string,id:string,text?:string,media_id?:string,mime_type?:string,caption?:string}>
	 */
	public static function extract_messages( array $payload ): array {
		$out = array();
		if ( ( $payload['object'] ?? '' ) !== 'whatsapp_business_account' ) {
			return $out;
		}
		foreach ( ( $payload['entry'] ?? array() ) as $entry ) {
			foreach ( ( $entry['changes'] ?? array() ) as $change ) {
				$value = $change['value'] ?? array();
				// Meta sidecars sender names in value.contacts[], keyed by
				// wa_id. Index once so per-message lookups stay O(1). Without
				// this, every downstream consumer reaches for profile.name
				// themselves and many forget — agents end up with users titled
				// by raw E.164 phone instead of "Pedro" / "Florencia" / etc.
				$names_by_wa_id = array();
				foreach ( ( $value['contacts'] ?? array() ) as $contact ) {
					$wa_id = (string) ( $contact['wa_id'] ?? '' );
					$name  = (string) ( $contact['profile']['name'] ?? '' );
					if ( '' !== $wa_id && '' !== $name ) {
						$names_by_wa_id[ $wa_id ] = $name;
					}
				}

				foreach ( ( $value['messages'] ?? array() ) as $message ) {
					$type  = (string) ( $message['type'] ?? '' );
					$phone = (string) ( $message['from'] ?? '' );
					$id    = (string) ( $message['id'] ?? '' );
					$name  = $names_by_wa_id[ $phone ] ?? '';
					if ( '' === $phone ) {
						continue;
					}

					if ( 'text' === $type ) {
						$body = (string) ( $message['text']['body'] ?? '' );
						if ( '' === $body ) {
							continue;
						}
						$out[] = array( 'type' => 'text', 'phone' => $phone, 'text' => $body, 'id' => $id, 'sender_name' => $name );
					} elseif ( 'interactive' === $type ) {
						$interactive = isset( $message['interactive'] ) && is_array( $message['interactive'] ) ? $message['interactive'] : array();
						$itype       = (string) ( $interactive['type'] ?? '' );
						$reply_id    = 'button_reply' === $itype
							? (string) ( $interactive['button_reply']['id'] ?? '' )
							: ( 'list_reply' === $itype ? (string) ( $interactive['list_reply']['id'] ?? '' ) : '' );
						if ( '' === $reply_id ) {
							continue;
						}
						$out[] = array( 'type' => 'text', 'phone' => $phone, 'text' => $reply_id, 'id' => $id, 'sender_name' => $name );
					} elseif ( 'image' === $type ) {
						$img = isset( $message['image'] ) && is_array( $message['image'] ) ? $message['image'] : array();
						if ( empty( $img['id'] ) ) {
							continue;
						}
						$out[] = array(
							'type'        => 'image',
							'phone'       => $phone,
							'id'          => $id,
							'sender_name' => $name,
							'media_id'    => (string) $img['id'],
							'mime_type'   => (string) ( $img['mime_type'] ?? '' ),
							'caption'     => (string) ( $img['caption'] ?? '' ),
						);
					}
				}
			}
		}
		return $out;
	}

	/**
	 * Dispatch one inbound text to the agent, persist the session under the
	 * sender's phone number, send the reply back to that phone.
	 */
	private static function dispatch( array $message, string $agent_slug, int $user_id, array $settings ): bool {
		$phone       = $message['phone'];
		$text        = $message['text'];
		$sender_name = (string) ( $message['sender_name'] ?? '' );

		// Idempotency: skip if we've already processed this message id.
		if ( '' !== $message['id'] && self::is_already_processed( $message['id'] ) ) {
			return false;
		}

		// Inbound webhook arrives anonymous (no logged-in user). Promote to
		// the configured WhatsApp user so ability `permission_callback`s and
		// `current_user_can()` checks downstream see a real principal. This
		// is the bot's "service identity" — the operator picks the WP user
		// in openclaWP → WhatsApp settings.
		if ( $user_id > 0 && get_current_user_id() !== $user_id ) {
			wp_set_current_user( $user_id );
		}

		$session_id = self::resolve_session_for_phone( $phone, $user_id );

		$result = OpenclaWP_Runner::run_turn(
			$agent_slug,
			$text,
			$session_id,
			$user_id,
			array(
				'attachments'    => array(),
				'client_context' => array(
					'source'                   => 'channel',
					'connector_id'             => 'whatsapp',
					'client_name'              => 'whatsapp',
					'external_provider'        => 'whatsapp',
					'external_conversation_id' => $phone,
					'external_message_id'      => (string) ( $message['id'] ?? '' ),
					'sender_id'                => $phone,
					'sender_name'              => $sender_name,
					'room_kind'                => 'dm',
				),
			)
		);

		if ( '' !== ( $message['id'] ?? '' ) ) {
			self::mark_processed( $message['id'] );
		}

		// Tag the session post with the phone so subsequent inbounds find it.
		if ( ! empty( $result['session_id'] ) ) {
			self::tag_session_with_phone( (string) $result['session_id'], $phone );
		}

		$reply       = isset( $result['reply'] ) ? (string) $result['reply'] : '';
		$interactive = isset( $result['interactive'] ) && is_array( $result['interactive'] ) ? $result['interactive'] : null;

		if ( null !== $interactive ) {
			return self::send_interactive_message( $phone, $reply, $interactive, $settings );
		}
		if ( '' === $reply ) {
			return false;
		}
		return self::send_text_message( $phone, $reply, $settings );
	}

	/**
	 * Find the openclawp_session for this phone, or null to start fresh.
	 *
	 * @return string|null Session UUID, or null when no prior session exists.
	 */
	private static function resolve_session_for_phone( string $phone, int $user_id ): ?string {
		$query = new WP_Query(
			array(
				'post_type'              => OpenclaWP_Conversation_Store::POST_TYPE,
				'post_status'            => 'any',
				'author'                 => $user_id,
				'posts_per_page'         => 1,
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'suppress_filters'       => true,
				'meta_key'               => self::META_PHONE_KEY,
				'meta_value'             => $phone,
			)
		);
		if ( empty( $query->posts ) ) {
			return null;
		}
		$session_id = (string) get_post_meta( $query->posts[0]->ID, '_openclawp_session_id', true );
		return '' !== $session_id ? $session_id : null;
	}

	private static function tag_session_with_phone( string $session_id, string $phone ): void {
		$query = new WP_Query(
			array(
				'post_type'              => OpenclaWP_Conversation_Store::POST_TYPE,
				'post_status'            => 'any',
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'suppress_filters'       => true,
				'meta_key'               => '_openclawp_session_id',
				'meta_value'             => $session_id,
			)
		);
		if ( empty( $query->posts ) ) {
			return;
		}
		update_post_meta( $query->posts[0]->ID, self::META_PHONE_KEY, $phone );
	}

	/**
	 * Dispatch an inbound image. We don't route images through the agent
	 * turn loop — they're side-channel events that consumers (e.g. a
	 * plugin storing the photo as a user avatar) hook into via the
	 * `openclawp_image_message_received` action. The action payload
	 * carries enough context to fetch the bytes from Meta's media URL
	 * without re-implementing the auth dance.
	 */
	private static function dispatch_image( array $message, int $user_id, array $settings ): bool {
		$phone    = (string) ( $message['phone'] ?? '' );
		$media_id = (string) ( $message['media_id'] ?? '' );
		if ( '' === $phone || '' === $media_id ) {
			return false;
		}
		if ( '' !== ( $message['id'] ?? '' ) && self::is_already_processed( (string) $message['id'] ) ) {
			return false;
		}

		// Promote to the configured service user so action handlers run
		// under a real principal (e.g. media_handle_sideload needs caps).
		if ( $user_id > 0 && get_current_user_id() !== $user_id ) {
			wp_set_current_user( $user_id );
		}

		/**
		 * Fires when an image lands at the webhook.
		 *
		 * @param array $payload {
		 *   @type string $phone        Sender E.164 (digits only)
		 *   @type string $media_id     Meta media ID — fetch URL from /v25.0/{id}
		 *   @type string $mime_type    image/jpeg, image/png, etc.
		 *   @type string $caption      Optional user caption
		 *   @type string $message_id   Webhook message id (already idempotency-checked)
		 *   @type string $access_token Bearer for both the metadata + binary fetch
		 *   @type string $api_version  Graph API version configured in settings
		 * }
		 */
		do_action(
			'openclawp_image_message_received',
			array(
				'phone'        => $phone,
				'media_id'     => $media_id,
				'mime_type'    => (string) ( $message['mime_type'] ?? '' ),
				'caption'      => (string) ( $message['caption'] ?? '' ),
				'message_id'   => (string) ( $message['id'] ?? '' ),
				'access_token' => (string) ( $settings['access_token'] ?? '' ),
				'api_version'  => (string) ( $settings['api_version'] ?? self::DEFAULT_API_VERSION ),
			)
		);

		if ( '' !== ( $message['id'] ?? '' ) ) {
			self::mark_processed( (string) $message['id'] );
		}
		return true;
	}

	/* ----------------------------- Idempotency ---------------------------- */

	private static function is_already_processed( string $message_id ): bool {
		$key = 'openclawp_wa_msg_' . md5( $message_id );
		return false !== get_transient( $key );
	}

	private static function mark_processed( string $message_id ): void {
		$key = 'openclawp_wa_msg_' . md5( $message_id );
		set_transient( $key, 1, 7 * DAY_IN_SECONDS );
	}

	/* ----------------------------- Outbound ------------------------------- */

	public static function send_text_message( string $to_phone, string $body, array $settings = array() ): bool {
		if ( empty( $settings ) ) {
			$settings = self::settings();
		}
		$phone_number_id = $settings['phone_number_id'];
		$access_token    = $settings['access_token'];
		$api_version     = $settings['api_version'] ?: self::DEFAULT_API_VERSION;

		if ( '' === $phone_number_id || '' === $access_token ) {
			return false;
		}

		$url = sprintf( 'https://graph.facebook.com/%s/%s/messages', rawurlencode( $api_version ), rawurlencode( $phone_number_id ) );

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'messaging_product' => 'whatsapp',
						'recipient_type'    => 'individual',
						'to'                => $to_phone,
						'type'              => 'text',
						'text'              => array( 'body' => $body ),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( '[openclawp] whatsapp_send_failed err=' . self::redact_secrets( $response->get_error_message() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			error_log( '[openclawp] whatsapp_send_failed status=' . $code . ' body=' . self::redact_secrets( wp_remote_retrieve_body( $response ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}

		return true;
	}

	/**
	 * Replace common secret-shaped substrings with `[redacted]` for safe
	 * logging. Aimed at the obvious shapes we know flow through this plugin:
	 * Bearer headers, Meta Graph access tokens (EAA…), Anthropic keys
	 * (sk-ant-…), and any `access_token`/`api_key` JSON values.
	 */
	public static function redact_secrets( string $text ): string {
		$patterns = array(
			'/(Bearer\s+)[A-Za-z0-9_\-\.]{8,}/i',
			'/\bEAA[A-Za-z0-9_\-]{20,}\b/',
			'/\bsk-ant-[A-Za-z0-9_\-]{16,}\b/',
			'/("access_token"\s*:\s*")[^"]+(")/',
			'/("api_key"\s*:\s*")[^"]+(")/',
			'/("token"\s*:\s*")[^"]+(")/',
		);
		$replacements = array(
			'$1[redacted]',
			'[redacted]',
			'[redacted]',
			'$1[redacted]$2',
			'$1[redacted]$2',
			'$1[redacted]$2',
		);
		return (string) preg_replace( $patterns, $replacements, $text );
	}

	/**
	 * Send a WhatsApp Cloud API interactive message.
	 *
	 * Accepts a normalized $interactive payload shaped by the caller and
	 * builds the Meta-compliant body. Two shapes supported today:
	 *
	 *   type: 'button' — up to 3 quick reply buttons
	 *     {
	 *       'type'    => 'button',
	 *       'header'  => optional string,
	 *       'footer'  => optional string,
	 *       'buttons' => [ ['id' => '...', 'title' => '...'], ... ]
	 *     }
	 *
	 *   type: 'list' — up to 10 selectable rows across sections
	 *     {
	 *       'type'         => 'list',
	 *       'header'       => optional string,
	 *       'footer'       => optional string,
	 *       'button_label' => 'Elegir' (default),
	 *       'sections'     => [ [ 'title' => '...', 'rows' => [ ['id'=>'','title'=>'','description'=>''], ... ] ], ... ]
	 *     }
	 *
	 * $body_text is the message body shown above the buttons / list.
	 * Inbound replies (button/list selection) surface as their `id` in
	 * extract_messages(), so callers can route them through their existing
	 * command dispatcher.
	 */
	public static function send_interactive_message( string $to_phone, string $body_text, array $interactive, array $settings = array() ): bool {
		if ( empty( $settings ) ) {
			$settings = self::settings();
		}
		$phone_number_id = $settings['phone_number_id'];
		$access_token    = $settings['access_token'];
		$api_version     = $settings['api_version'] ?: self::DEFAULT_API_VERSION;

		if ( '' === $phone_number_id || '' === $access_token ) {
			return false;
		}

		$built = self::build_interactive_payload( $body_text, $interactive );
		if ( null === $built ) {
			// Fall back to a plain text send if the payload couldn't be shaped.
			return '' !== $body_text && self::send_text_message( $to_phone, $body_text, $settings );
		}

		$url = sprintf( 'https://graph.facebook.com/%s/%s/messages', rawurlencode( $api_version ), rawurlencode( $phone_number_id ) );

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'messaging_product' => 'whatsapp',
						'recipient_type'    => 'individual',
						'to'                => $to_phone,
						'type'              => 'interactive',
						'interactive'       => $built,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( '[openclawp] whatsapp_interactive_send_failed err=' . self::redact_secrets( $response->get_error_message() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			error_log( '[openclawp] whatsapp_interactive_send_failed status=' . $code . ' body=' . self::redact_secrets( wp_remote_retrieve_body( $response ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			// Soft fallback to text so the user at least sees the message body.
			return '' !== $body_text && self::send_text_message( $to_phone, $body_text, $settings );
		}

		return true;
	}

	private static function build_interactive_payload( string $body_text, array $interactive ): ?array {
		$type = (string) ( $interactive['type'] ?? '' );
		$body = '' !== $body_text ? array( 'text' => self::truncate( $body_text, 1024 ) ) : array( 'text' => ' ' );

		if ( 'button' === $type ) {
			$buttons_in = isset( $interactive['buttons'] ) && is_array( $interactive['buttons'] ) ? $interactive['buttons'] : array();
			$buttons    = array();
			foreach ( array_slice( $buttons_in, 0, 3 ) as $btn ) {
				$id    = (string) ( $btn['id'] ?? '' );
				$title = self::truncate( (string) ( $btn['title'] ?? '' ), 20 );
				if ( '' === $id || '' === $title ) {
					continue;
				}
				$buttons[] = array(
					'type'  => 'reply',
					'reply' => array( 'id' => $id, 'title' => $title ),
				);
			}
			if ( empty( $buttons ) ) {
				return null;
			}
			$payload = array(
				'type'   => 'button',
				'body'   => $body,
				'action' => array( 'buttons' => $buttons ),
			);
			if ( ! empty( $interactive['header'] ) ) {
				$payload['header'] = array( 'type' => 'text', 'text' => self::truncate( (string) $interactive['header'], 60 ) );
			}
			if ( ! empty( $interactive['footer'] ) ) {
				$payload['footer'] = array( 'text' => self::truncate( (string) $interactive['footer'], 60 ) );
			}
			return $payload;
		}

		if ( 'list' === $type ) {
			$sections_in = isset( $interactive['sections'] ) && is_array( $interactive['sections'] ) ? $interactive['sections'] : array();
			$sections    = array();
			foreach ( $sections_in as $section ) {
				$rows_in = isset( $section['rows'] ) && is_array( $section['rows'] ) ? $section['rows'] : array();
				$rows    = array();
				foreach ( $rows_in as $row ) {
					$id    = (string) ( $row['id'] ?? '' );
					$title = self::truncate( (string) ( $row['title'] ?? '' ), 24 );
					if ( '' === $id || '' === $title ) {
						continue;
					}
					$entry = array( 'id' => $id, 'title' => $title );
					if ( ! empty( $row['description'] ) ) {
						$entry['description'] = self::truncate( (string) $row['description'], 72 );
					}
					$rows[] = $entry;
				}
				if ( empty( $rows ) ) {
					continue;
				}
				$sections[] = array(
					'title' => self::truncate( (string) ( $section['title'] ?? 'Opciones' ), 24 ),
					'rows'  => $rows,
				);
			}
			if ( empty( $sections ) ) {
				return null;
			}
			$payload = array(
				'type'   => 'list',
				'body'   => $body,
				'action' => array(
					'button'   => self::truncate( (string) ( $interactive['button_label'] ?? 'Elegir' ), 20 ),
					'sections' => $sections,
				),
			);
			if ( ! empty( $interactive['header'] ) ) {
				$payload['header'] = array( 'type' => 'text', 'text' => self::truncate( (string) $interactive['header'], 60 ) );
			}
			if ( ! empty( $interactive['footer'] ) ) {
				$payload['footer'] = array( 'text' => self::truncate( (string) $interactive['footer'], 60 ) );
			}
			return $payload;
		}

		return null;
	}

	private static function truncate( string $text, int $max ): string {
		$text = trim( $text );
		if ( function_exists( 'mb_strimwidth' ) ) {
			return mb_strimwidth( $text, 0, $max, '', 'UTF-8' );
		}
		return strlen( $text ) > $max ? substr( $text, 0, $max ) : $text;
	}

	/* ------------------------------ Settings ------------------------------ */

	public static function register_settings_menu(): void {
		// Hide-when-empty: until Meta Cloud API credentials are saved the
		// WhatsApp tab points at an empty form — first-time setup lives
		// inside the Channels card. The settings page itself stays reachable
		// at admin.php?page=openclawp-whatsapp. The "configured?" check is
		// centralised on the menu-visibility helper so the Discover panel
		// shows the same state.
		$parent = OpenclaWP_Admin_Menu_Visibility::parent_for_slug( 'openclawp-whatsapp' );
		add_submenu_page(
			$parent,
			__( 'WhatsApp', 'openclawp' ),
			__( 'WhatsApp', 'openclawp' ),
			'manage_options',
			'openclawp-whatsapp',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	public static function register_settings(): void {
		register_setting(
			'openclawp_whatsapp',
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => array(),
			)
		);
	}

	public static function sanitize_settings( $value ): array {
		if ( ! is_array( $value ) ) {
			$value = array();
		}
		return array(
			'phone_number_id'      => isset( $value['phone_number_id'] ) ? preg_replace( '/\D/', '', (string) $value['phone_number_id'] ) : '',
			'app_secret'           => isset( $value['app_secret'] ) ? trim( (string) $value['app_secret'] ) : '',
			'access_token'         => isset( $value['access_token'] ) ? trim( (string) $value['access_token'] ) : '',
			'webhook_verify_token' => isset( $value['webhook_verify_token'] ) ? trim( (string) $value['webhook_verify_token'] ) : '',
			'default_agent'        => isset( $value['default_agent'] ) ? sanitize_title( (string) $value['default_agent'] ) : '',
			'api_version'          => isset( $value['api_version'] ) && '' !== trim( (string) $value['api_version'] ) ? trim( (string) $value['api_version'] ) : self::DEFAULT_API_VERSION,
			'user_id'              => isset( $value['user_id'] ) ? (int) $value['user_id'] : 0,
		);
	}

	public static function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings    = self::settings();
		$webhook_url = esc_url( rest_url( self::REST_NAMESPACE . self::REST_ROUTE ) );
		$agents      = function_exists( 'wp_get_agents' ) ? wp_get_agents() : array();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'openclaWP — WhatsApp', 'openclawp' ); ?></h1>
			<p class="description">
				<?php
				printf(
					/* translators: %s: webhook URL. */
					esc_html__( 'Configure Meta\'s WhatsApp Cloud API to point at your webhook URL: %s', 'openclawp' ),
					'<code>' . esc_html( $webhook_url ) . '</code>'
				);
				?>
			</p>

			<form action="options.php" method="post">
				<?php settings_fields( 'openclawp_whatsapp' ); ?>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="openclawp-wa-phone-number-id"><?php esc_html_e( 'Phone Number ID', 'openclawp' ); ?></label></th>
							<td><input type="text" id="openclawp-wa-phone-number-id" class="regular-text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[phone_number_id]" value="<?php echo esc_attr( $settings['phone_number_id'] ); ?>" autocomplete="off"></td>
						</tr>
						<tr>
							<th scope="row"><label for="openclawp-wa-app-secret"><?php esc_html_e( 'App Secret', 'openclawp' ); ?></label></th>
							<td>
								<input type="password" id="openclawp-wa-app-secret" class="regular-text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[app_secret]" value="<?php echo esc_attr( $settings['app_secret'] ); ?>" autocomplete="new-password">
								<p class="description"><?php esc_html_e( 'Used to verify the X-Hub-Signature-256 header on inbound webhooks.', 'openclawp' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="openclawp-wa-access-token"><?php esc_html_e( 'Permanent Access Token', 'openclawp' ); ?></label></th>
							<td>
								<input type="password" id="openclawp-wa-access-token" class="regular-text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[access_token]" value="<?php echo esc_attr( $settings['access_token'] ); ?>" autocomplete="new-password">
								<p class="description"><?php esc_html_e( 'Bearer token used for outbound Graph API calls.', 'openclawp' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="openclawp-wa-verify-token"><?php esc_html_e( 'Webhook Verify Token', 'openclawp' ); ?></label></th>
							<td>
								<input type="password" id="openclawp-wa-verify-token" class="regular-text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[webhook_verify_token]" value="<?php echo esc_attr( $settings['webhook_verify_token'] ); ?>" autocomplete="new-password">
								<p class="description"><?php esc_html_e( 'Free-form string you also paste into Meta\'s webhook setup form.', 'openclawp' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="openclawp-wa-agent"><?php esc_html_e( 'Default agent', 'openclawp' ); ?></label></th>
							<td>
								<select id="openclawp-wa-agent" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[default_agent]">
									<option value=""><?php esc_html_e( '— Select an agent —', 'openclawp' ); ?></option>
									<?php foreach ( $agents as $slug => $agent_obj ) : ?>
										<option value="<?php echo esc_attr( (string) $slug ); ?>" <?php selected( $settings['default_agent'], (string) $slug ); ?>>
											<?php echo esc_html( $agent_obj instanceof WP_Agent ? $agent_obj->get_label() : (string) $slug ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Inbound WhatsApp messages are dispatched to this agent.', 'openclawp' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="openclawp-wa-user-id"><?php esc_html_e( 'Owner user ID', 'openclawp' ); ?></label></th>
							<td>
								<input type="number" id="openclawp-wa-user-id" class="small-text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[user_id]" value="<?php echo esc_attr( (string) $settings['user_id'] ); ?>" min="0">
								<p class="description"><?php esc_html_e( 'WP user ID that owns inbound conversations. 0 falls back to the current admin.', 'openclawp' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="openclawp-wa-api-version"><?php esc_html_e( 'API version', 'openclawp' ); ?></label></th>
							<td>
								<input type="text" id="openclawp-wa-api-version" class="regular-text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[api_version]" value="<?php echo esc_attr( $settings['api_version'] ); ?>">
							</td>
						</tr>
					</tbody>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
