<?php
/**
 * Generic external WhatsApp gateway adapter for openclaWP.
 *
 * Sibling to the Cloud API ingress in {@see OpenclaWP_Whatsapp}, but
 * provider-agnostic: any long-lived daemon (evolution-api, wacli, baileys,
 * whatsmeow, …) that delivers signed JSON over HTTP and accepts an outbound
 * POST can plug in here without modifications.
 *
 * Inbound canonical shape:
 *
 *     { "from": "+15551234567", "text": "hola", "id": "msg-uuid", "type": "text" }
 *
 * Inbound authentication: HMAC-SHA256 over the raw body using a shared secret
 * configured in admin, sent by the gateway as `X-OpenclaWP-Signature: sha256=<hex>`.
 *
 * Outbound: POST to the configured gateway URL with the canonical shape
 *
 *     { "to": "+15551234567", "text": "reply", "id": "msg-uuid" }
 *
 * plus the same HMAC header computed over the raw outbound body, so the
 * gateway can authenticate the call back.
 *
 * Off by default. Opt in with:
 *
 *     add_filter( 'openclawp_register_external_whatsapp_gateway', '__return_true' );
 *
 * Two filters let users adapt a non-conforming gateway without forking:
 *
 *   - openclawp_external_wa_inbound_map  ( $raw_payload, $headers ) -> $normalized
 *   - openclawp_external_wa_outbound_map ( $reply, $session )       -> $gateway_payload
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_External_Whatsapp {

	public const OPTION_NAME = 'openclawp_external_whatsapp_settings';

	private const REST_NAMESPACE = 'openclawp/v1';
	private const REST_ROUTE     = '/whatsapp-gateway/webhook';
	private const META_PHONE_KEY = '_openclawp_external_wa_phone';
	private const SIGNATURE_HEADER = 'X-OpenclaWP-Signature';

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_settings_menu' ), 20 );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_filter( 'openclawp_channels', array( __CLASS__, 'register_channel_card' ) );
	}

	public static function settings(): array {
		$stored = get_option( self::OPTION_NAME, array() );
		return wp_parse_args(
			is_array( $stored ) ? $stored : array(),
			array(
				'shared_secret' => '',
				'outbound_url'  => '',
				'default_agent' => '',
				'user_id'       => 0,
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
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'handle_inbound' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * Inbound webhook. Verify HMAC, normalize payload, dispatch to the
	 * configured agent, POST the reply back to the configured gateway URL.
	 */
	public static function handle_inbound( WP_REST_Request $request ) {
		$settings = self::settings();
		$raw_body = $request->get_body();

		// `WP_REST_Request::get_header` normalizes "X-OpenclaWP-Signature"
		// to `x_openclawp_signature`. Accept either casing the gateway might
		// have generated to play nice with reverse proxies.
		$signature = (string) ( $request->get_header( 'x_openclawp_signature' ) ?? '' );
		if ( '' === $signature ) {
			$signature = (string) ( $request->get_header( 'X-OpenclaWP-Signature' ) ?? '' );
		}

		if ( ! self::verify_signature( $raw_body, $signature, (string) $settings['shared_secret'] ) ) {
			return new WP_Error(
				'openclawp_external_wa_bad_signature',
				__( 'Invalid signature.', 'openclawp' ),
				array( 'status' => 401 )
			);
		}

		$payload = json_decode( $raw_body, true );
		if ( ! is_array( $payload ) ) {
			return new WP_REST_Response( array( 'received' => true, 'processed' => 0 ), 200 );
		}

		/**
		 * Map the raw gateway payload into the canonical shape openclaWP
		 * dispatches on:
		 *
		 *     { "from": "+15551234567", "text": "hi", "id": "msg-uuid", "type": "text" }
		 *
		 * Return the unchanged $payload to accept the canonical shape, or
		 * reshape an evolution-api / wacli / baileys event here. Returning
		 * an empty array signals "nothing to dispatch" and yields a 200 ack.
		 *
		 * @since 0.2.0
		 *
		 * @param array $payload Decoded JSON body from the gateway.
		 * @param array $headers Request headers (lowercased keys).
		 */
		$normalized = apply_filters(
			'openclawp_external_wa_inbound_map',
			$payload,
			self::collect_headers( $request )
		);

		if ( ! is_array( $normalized ) || empty( $normalized ) ) {
			return new WP_REST_Response( array( 'received' => true, 'processed' => 0 ), 200 );
		}

		$message = self::normalize_message( $normalized );
		if ( null === $message ) {
			// Unknown / unsupported type (image, audio, sticker, …) — ack and log.
			$type = is_array( $normalized ) ? (string) ( $normalized['type'] ?? 'unknown' ) : 'unknown';
			error_log( '[openclawp] external_wa unsupported_message type=' . $type ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return new WP_REST_Response( array( 'received' => true, 'processed' => 0, 'reason' => 'unsupported' ), 200 );
		}

		$agent_slug = (string) $settings['default_agent'];
		$user_id    = (int) ( $settings['user_id'] ?: get_current_user_id() );
		if ( '' === $agent_slug || ! function_exists( 'wp_get_agent' ) || null === wp_get_agent( $agent_slug ) ) {
			return new WP_Error(
				'openclawp_external_wa_no_agent',
				__( 'External WhatsApp gateway has no configured agent.', 'openclawp' ),
				array( 'status' => 503 )
			);
		}

		$processed = self::dispatch( $message, $agent_slug, $user_id, $settings ) ? 1 : 0;

		return new WP_REST_Response( array( 'received' => true, 'processed' => $processed ), 200 );
	}

	/* ----------------------------- Signature ------------------------------ */

	/**
	 * HMAC-SHA256 over the raw body. Fail-closed on an empty secret so a
	 * mis-configured install doesn't accept arbitrary unsigned traffic — same
	 * posture as the Cloud API verifier.
	 */
	public static function verify_signature( string $raw_body, string $signature_header, string $shared_secret ): bool {
		if ( '' === $shared_secret ) {
			return false;
		}
		if ( 0 !== strpos( $signature_header, 'sha256=' ) ) {
			return false;
		}
		$expected = substr( $signature_header, strlen( 'sha256=' ) );
		$computed = hash_hmac( 'sha256', $raw_body, $shared_secret );
		return hash_equals( $computed, $expected );
	}

	/* ----------------------------- Normalize ------------------------------ */

	/**
	 * Pull the canonical fields out of a (post-filter) payload. Returns null
	 * for anything that isn't a v1-supported text message — those get ack'd
	 * 200 + logged at the call site.
	 *
	 * @return array{from:string,text:string,id:string}|null
	 */
	public static function normalize_message( array $payload ): ?array {
		$type = (string) ( $payload['type'] ?? 'text' );
		if ( 'text' !== $type ) {
			return null;
		}

		$from = trim( (string) ( $payload['from'] ?? '' ) );
		$text = (string) ( $payload['text'] ?? '' );
		$id   = (string) ( $payload['id'] ?? '' );

		if ( '' === $from || '' === $text ) {
			return null;
		}

		return array( 'from' => $from, 'text' => $text, 'id' => $id );
	}

	/**
	 * Collect request headers as a `lowercased_key => value` map. Filters
	 * get this so they can read provider-specific signatures (e.g.
	 * evolution-api's `apikey`) when deciding how to remap a payload.
	 *
	 * @return array<string,string>
	 */
	private static function collect_headers( WP_REST_Request $request ): array {
		$out = array();
		foreach ( (array) $request->get_headers() as $name => $values ) {
			$out[ strtolower( (string) $name ) ] = is_array( $values ) ? implode( ',', $values ) : (string) $values;
		}
		return $out;
	}

	/* ----------------------------- Dispatch ------------------------------- */

	/**
	 * Dispatch one inbound canonical message to the agent, persist the
	 * session under the sender's identifier, send the reply back via the
	 * configured outbound URL.
	 */
	private static function dispatch( array $message, string $agent_slug, int $user_id, array $settings ): bool {
		$from = $message['from'];
		$text = $message['text'];

		// Idempotency: skip if we've already processed this message id.
		if ( '' !== $message['id'] && self::is_already_processed( $message['id'] ) ) {
			return false;
		}

		// Inbound webhook arrives anonymous (no logged-in user). Promote to
		// the configured user so ability `permission_callback`s downstream
		// see a real principal.
		if ( $user_id > 0 && get_current_user_id() !== $user_id ) {
			wp_set_current_user( $user_id );
		}

		$session_id = self::resolve_session_for_from( $from, $user_id );

		$result = OpenclaWP_Runner::run_turn(
			$agent_slug,
			$text,
			$session_id,
			$user_id,
			array(
				'attachments'    => array(),
				'client_context' => array(
					'source'                   => 'channel',
					'connector_id'             => 'external-whatsapp',
					'client_name'              => 'external-whatsapp',
					'external_provider'        => 'external-whatsapp',
					'external_conversation_id' => $from,
					'external_message_id'      => $message['id'],
					'sender_id'                => $from,
					'room_kind'                => 'dm',
				),
			)
		);

		if ( '' !== $message['id'] ) {
			self::mark_processed( $message['id'] );
		}

		if ( ! empty( $result['session_id'] ) ) {
			self::tag_session_with_from( (string) $result['session_id'], $from );
		}

		$reply = isset( $result['reply'] ) ? (string) $result['reply'] : '';
		if ( '' === $reply ) {
			return false;
		}

		return self::send_text_message( $from, $reply, $message['id'], $result, $settings );
	}

	/* ----------------------------- Sessions ------------------------------- */

	private static function resolve_session_for_from( string $from, int $user_id ): ?string {
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
				'meta_value'             => $from,
			)
		);
		if ( empty( $query->posts ) ) {
			return null;
		}
		$session_id = (string) get_post_meta( $query->posts[0]->ID, '_openclawp_session_id', true );
		return '' !== $session_id ? $session_id : null;
	}

	private static function tag_session_with_from( string $session_id, string $from ): void {
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
		update_post_meta( $query->posts[0]->ID, self::META_PHONE_KEY, $from );
	}

	/* ----------------------------- Idempotency ---------------------------- */

	private static function is_already_processed( string $message_id ): bool {
		$key = 'openclawp_ext_wa_msg_' . md5( $message_id );
		return false !== get_transient( $key );
	}

	private static function mark_processed( string $message_id ): void {
		$key = 'openclawp_ext_wa_msg_' . md5( $message_id );
		set_transient( $key, 1, 7 * DAY_IN_SECONDS );
	}

	/* ----------------------------- Outbound ------------------------------- */

	/**
	 * POST the canonical outbound payload to the gateway URL.
	 *
	 * Passes the agent's reply + session through the outbound mapping filter
	 * before serialization so users can reshape into evolution-api / wacli /
	 * baileys-specific shapes without forking.
	 */
	public static function send_text_message( string $to, string $body, string $inbound_id, array $session, array $settings = array() ): bool {
		if ( empty( $settings ) ) {
			$settings = self::settings();
		}
		$url    = (string) $settings['outbound_url'];
		$secret = (string) $settings['shared_secret'];

		if ( '' === $url ) {
			return false;
		}

		$canonical = array(
			'to'   => $to,
			'text' => $body,
			'id'   => $inbound_id,
		);

		/**
		 * Reshape the canonical outbound payload before serialization. Useful
		 * for adapting to a gateway that doesn't speak canonical openclaWP
		 * shape — return the body the gateway actually expects.
		 *
		 * @since 0.2.0
		 *
		 * @param array $canonical { to:string, text:string, id:string }
		 * @param array $session   Runner result (session_id, reply, …).
		 */
		$payload = apply_filters( 'openclawp_external_wa_outbound_map', $canonical, $session );
		if ( ! is_array( $payload ) ) {
			$payload = $canonical;
		}

		$body_json = wp_json_encode( $payload );
		if ( false === $body_json ) {
			return false;
		}

		$headers = array( 'Content-Type' => 'application/json' );
		if ( '' !== $secret ) {
			$headers[ self::SIGNATURE_HEADER ] = 'sha256=' . hash_hmac( 'sha256', $body_json, $secret );
		}

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 20,
				'headers' => $headers,
				'body'    => $body_json,
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( '[openclawp] external_wa_send_failed err=' . $response->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			error_log( '[openclawp] external_wa_send_failed status=' . $code . ' body=' . wp_remote_retrieve_body( $response ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}

		return true;
	}

	/* ------------------------------ Channels ------------------------------ */

	/**
	 * Register the channel card in wp-admin → openclaWP → Channels.
	 *
	 * @param array<int,array<string,mixed>> $channels
	 * @return array<int,array<string,mixed>>
	 */
	public static function register_channel_card( array $channels ): array {
		$settings   = self::settings();
		$configured = '' !== trim( (string) $settings['shared_secret'] ) && '' !== trim( (string) $settings['outbound_url'] );
		$channels[] = array(
			'id'              => 'external-whatsapp',
			'name'            => __( 'External WhatsApp Gateway', 'openclawp' ),
			'subtitle'        => __( 'Generic adapter for evolution-api, wacli, baileys, whatsmeow, …', 'openclawp' ),
			'description'     => __( 'Webhook-in + POST-out over HMAC-signed JSON. Pair with any long-lived gateway daemon.', 'openclawp' ),
			'status'          => $configured
				? OpenclaWP_Channels_Admin::STATUS_CONNECTED
				: OpenclaWP_Channels_Admin::STATUS_NOT_CONFIGURED,
			'detail_url'      => admin_url( 'admin.php?page=openclawp-external-whatsapp' ),
		);
		return $channels;
	}

	/* ------------------------------ Settings ------------------------------ */

	public static function register_settings_menu(): void {
		add_submenu_page(
			'openclawp',
			__( 'External WhatsApp', 'openclawp' ),
			__( 'External WhatsApp', 'openclawp' ),
			'manage_options',
			'openclawp-external-whatsapp',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	public static function register_settings(): void {
		register_setting(
			'openclawp_external_whatsapp',
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
			'shared_secret' => isset( $value['shared_secret'] ) ? trim( (string) $value['shared_secret'] ) : '',
			'outbound_url'  => isset( $value['outbound_url'] ) ? esc_url_raw( trim( (string) $value['outbound_url'] ) ) : '',
			'default_agent' => isset( $value['default_agent'] ) ? sanitize_title( (string) $value['default_agent'] ) : '',
			'user_id'       => isset( $value['user_id'] ) ? (int) $value['user_id'] : 0,
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
			<h1><?php esc_html_e( 'openclaWP — External WhatsApp Gateway', 'openclawp' ); ?></h1>
			<p class="description">
				<?php
				printf(
					/* translators: %s: webhook URL. */
					esc_html__( 'Point your gateway (evolution-api, wacli, baileys, …) at: %s', 'openclawp' ),
					'<code>' . esc_html( $webhook_url ) . '</code>'
				);
				?>
			</p>
			<p class="description">
				<?php esc_html_e( 'See docs/external-whatsapp-gateway.md for worked recipes.', 'openclawp' ); ?>
			</p>

			<form action="options.php" method="post">
				<?php settings_fields( 'openclawp_external_whatsapp' ); ?>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="openclawp-ext-wa-secret"><?php esc_html_e( 'Shared secret', 'openclawp' ); ?></label></th>
							<td>
								<input type="password" id="openclawp-ext-wa-secret" class="regular-text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[shared_secret]" value="<?php echo esc_attr( $settings['shared_secret'] ); ?>" autocomplete="new-password">
								<p class="description"><?php esc_html_e( 'Used to verify the X-OpenclaWP-Signature header on inbound webhooks AND to sign outbound calls. Treat like a webhook signing key.', 'openclawp' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="openclawp-ext-wa-outbound"><?php esc_html_e( 'Outbound URL', 'openclawp' ); ?></label></th>
							<td>
								<input type="url" id="openclawp-ext-wa-outbound" class="regular-text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[outbound_url]" value="<?php echo esc_attr( $settings['outbound_url'] ); ?>" placeholder="https://your-gateway.example.com/send" autocomplete="off">
								<p class="description"><?php esc_html_e( 'openclaWP POSTs the reply to this URL.', 'openclawp' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="openclawp-ext-wa-agent"><?php esc_html_e( 'Default agent', 'openclawp' ); ?></label></th>
							<td>
								<select id="openclawp-ext-wa-agent" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[default_agent]">
									<option value=""><?php esc_html_e( '— Select an agent —', 'openclawp' ); ?></option>
									<?php foreach ( $agents as $slug => $agent_obj ) : ?>
										<option value="<?php echo esc_attr( (string) $slug ); ?>" <?php selected( $settings['default_agent'], (string) $slug ); ?>>
											<?php echo esc_html( $agent_obj instanceof WP_Agent ? $agent_obj->get_label() : (string) $slug ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Inbound gateway messages are dispatched to this agent.', 'openclawp' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="openclawp-ext-wa-user-id"><?php esc_html_e( 'Owner user ID', 'openclawp' ); ?></label></th>
							<td>
								<input type="number" id="openclawp-ext-wa-user-id" class="small-text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[user_id]" value="<?php echo esc_attr( (string) $settings['user_id'] ); ?>" min="0">
								<p class="description"><?php esc_html_e( 'WP user ID that owns inbound conversations. 0 falls back to the current admin.', 'openclawp' ); ?></p>
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
