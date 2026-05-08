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

		// Meta expects the challenge echoed back as plain text, not JSON.
		$response = new WP_REST_Response( $challenge, 200 );
		$response->header( 'Content-Type', 'text/plain' );
		return $response;
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
	 * a flat list of `[ phone, text, message_id ]` triples for text messages.
	 *
	 * @return array<int, array{phone:string,text:string,id:string}>
	 */
	public static function extract_messages( array $payload ): array {
		$out = array();
		if ( ( $payload['object'] ?? '' ) !== 'whatsapp_business_account' ) {
			return $out;
		}
		foreach ( ( $payload['entry'] ?? array() ) as $entry ) {
			foreach ( ( $entry['changes'] ?? array() ) as $change ) {
				$value = $change['value'] ?? array();
				foreach ( ( $value['messages'] ?? array() ) as $message ) {
					$type = (string) ( $message['type'] ?? '' );
					if ( 'text' !== $type ) {
						// Non-text messages: skip for v1 (images, audio, etc).
						continue;
					}
					$phone = (string) ( $message['from'] ?? '' );
					$text  = (string) ( $message['text']['body'] ?? '' );
					$id    = (string) ( $message['id'] ?? '' );
					if ( '' === $phone || '' === $text ) {
						continue;
					}
					$out[] = array( 'phone' => $phone, 'text' => $text, 'id' => $id );
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
		$phone = $message['phone'];
		$text  = $message['text'];

		// Idempotency: skip if we've already processed this message id.
		if ( '' !== $message['id'] && self::is_already_processed( $message['id'] ) ) {
			return false;
		}

		$session_id = self::resolve_session_for_phone( $phone, $user_id );

		$result = OpenclaWP_Runner::run_turn( $agent_slug, $text, $session_id, $user_id );

		if ( '' !== ( $message['id'] ?? '' ) ) {
			self::mark_processed( $message['id'] );
		}

		// Tag the session post with the phone so subsequent inbounds find it.
		if ( ! empty( $result['session_id'] ) ) {
			self::tag_session_with_phone( (string) $result['session_id'], $phone );
		}

		$reply = isset( $result['reply'] ) ? (string) $result['reply'] : '';
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
			error_log( '[openclawp] whatsapp_send_failed err=' . $response->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			error_log( '[openclawp] whatsapp_send_failed status=' . $code . ' body=' . wp_remote_retrieve_body( $response ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}

		return true;
	}

	/* ------------------------------ Settings ------------------------------ */

	public static function register_settings_menu(): void {
		add_submenu_page(
			'openclawp',
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
								<input type="text" id="openclawp-wa-verify-token" class="regular-text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[webhook_verify_token]" value="<?php echo esc_attr( $settings['webhook_verify_token'] ); ?>" autocomplete="off">
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
