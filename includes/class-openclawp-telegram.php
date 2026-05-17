<?php
/**
 * Telegram Bot API ingress for openclaWP.
 *
 * Adds a second chat surface alongside WhatsApp: an inbound webhook that
 * dispatches Telegram messages to a configured agent and posts the reply
 * back via the Telegram Bot API (`sendMessage`).
 *
 * Off by default. Opt in with:
 *
 *     add_filter( 'openclawp_register_telegram', '__return_true' );
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Telegram {

	public const OPTION_NAME = 'openclawp_telegram_settings';

	private const REST_NAMESPACE = 'openclawp/v1';
	private const REST_ROUTE     = '/telegram/webhook';
	private const META_CHAT_KEY  = '_openclawp_telegram_chat_id';
	private const TELEGRAM_API   = 'https://api.telegram.org';
	private const DROPPED_OPTION = 'openclawp_telegram_dropped_count';

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
				'bot_token'     => '',
				'secret_token'  => '',
				'allowlist'     => '',
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
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_inbound' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Inbound update webhook. Verify the secret_token header, parse the
	 * Update payload, dispatch to the configured agent, and reply via the
	 * Bot API.
	 *
	 * Telegram authenticates webhook deliveries via a shared secret sent in
	 * `X-Telegram-Bot-Api-Secret-Token`. We treat empty/missing settings as
	 * a fail-closed state — the webhook only accepts requests once a token
	 * is configured.
	 */
	public static function handle_inbound( WP_REST_Request $request ) {
		$settings = self::settings();
		$header   = (string) ( $request->get_header( 'x_telegram_bot_api_secret_token' ) ?? '' );

		if ( ! self::verify_secret( $header, $settings['secret_token'] ) ) {
			return new WP_Error( 'openclawp_telegram_bad_secret', __( 'Invalid secret token.', 'openclawp' ), array( 'status' => 401 ) );
		}

		$payload = json_decode( $request->get_body(), true );
		if ( ! is_array( $payload ) ) {
			return new WP_REST_Response( array( 'received' => true ), 200 );
		}

		$message = self::extract_message( $payload );
		if ( null === $message ) {
			// Non-text update (edited messages, channel posts, callbacks, etc.).
			// v1 acks 200 and drops.
			return new WP_REST_Response(
				array(
					'received'    => true,
					'processed'   => 0,
					'unsupported' => true,
				),
				200
			);
		}

		// Allowlist gate. Telegram bots can be discovered by anyone — without
		// an allowlist, any user who finds the bot can send agent traffic.
		if ( ! self::is_allowed( $message['chat_id'], $settings['allowlist'] ) ) {
			self::increment_dropped();
			return new WP_REST_Response(
				array(
					'received'  => true,
					'processed' => 0,
					'dropped'   => true,
				),
				200
			);
		}

		$agent_slug = $settings['default_agent'];
		$user_id    = (int) ( $settings['user_id'] ?: get_current_user_id() );
		if ( '' === $agent_slug || ! function_exists( 'wp_get_agent' ) || null === wp_get_agent( $agent_slug ) ) {
			return new WP_Error( 'openclawp_telegram_no_agent', __( 'Telegram adapter has no configured agent.', 'openclawp' ), array( 'status' => 503 ) );
		}

		$ok = self::dispatch( $message, $agent_slug, $user_id, $settings );

		return new WP_REST_Response(
			array(
				'received'  => true,
				'processed' => $ok ? 1 : 0,
			),
			200
		);
	}

	/* ----------------------------- Signature ------------------------------ */

	/**
	 * Constant-time compare between the configured secret and what Telegram
	 * sent in `X-Telegram-Bot-Api-Secret-Token`. Fails closed when either
	 * side is empty so an unconfigured plugin never accepts unauthenticated
	 * inbound traffic.
	 */
	public static function verify_secret( string $header, string $expected ): bool {
		if ( '' === $expected || '' === $header ) {
			return false;
		}
		return hash_equals( $expected, $header );
	}

	/* ----------------------------- Allowlist ------------------------------ */

	/**
	 * Allowlist is a comma-separated list of integer chat IDs. Empty list
	 * = nothing allowed (fail-closed). A literal `*` disables the gate so
	 * operators can opt out explicitly during development.
	 */
	public static function is_allowed( int $chat_id, string $allowlist ): bool {
		$allowlist = trim( $allowlist );
		if ( '' === $allowlist ) {
			return false;
		}
		if ( '*' === $allowlist ) {
			return true;
		}
		foreach ( explode( ',', $allowlist ) as $entry ) {
			$entry = trim( $entry );
			if ( '' === $entry ) {
				continue;
			}
			if ( (string) $chat_id === $entry ) {
				return true;
			}
		}
		return false;
	}

	private static function increment_dropped(): void {
		$count = (int) get_option( self::DROPPED_OPTION, 0 );
		update_option( self::DROPPED_OPTION, $count + 1, false );
	}

	/* ----------------------------- Dispatch ------------------------------- */

	/**
	 * Extract a normalized text message from Telegram's `Update` envelope.
	 *
	 * v1 handles `message.text` only. Edits, channel posts, captions on
	 * media, inline queries, and callbacks are intentionally skipped.
	 *
	 * @return array{chat_id:int,user_id:int,text:string,message_id:int}|null
	 */
	public static function extract_message( array $payload ): ?array {
		$message = $payload['message'] ?? null;
		if ( ! is_array( $message ) ) {
			return null;
		}

		$chat_id    = isset( $message['chat']['id'] ) ? (int) $message['chat']['id'] : 0;
		$user_id    = isset( $message['from']['id'] ) ? (int) $message['from']['id'] : 0;
		$message_id = isset( $message['message_id'] ) ? (int) $message['message_id'] : 0;
		if ( 0 === $chat_id ) {
			return null;
		}

		$text = isset( $message['text'] ) ? (string) $message['text'] : '';
		if ( '' === $text ) {
			// Unsupported media types (photo/voice/sticker/document/etc.) —
			// the issue requires ack 200 with "unsupported" log, never crash.
			error_log( '[openclawp] telegram_unsupported_type chat=' . $chat_id . ' message=' . $message_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return null;
		}

		return array(
			'chat_id'    => $chat_id,
			'user_id'    => $user_id,
			'text'       => $text,
			'message_id' => $message_id,
		);
	}

	/**
	 * Dispatch one inbound text to the agent, persist the session under the
	 * chat id, send the reply back to that chat (threading the reply when a
	 * message_id is present).
	 */
	private static function dispatch( array $message, string $agent_slug, int $user_id, array $settings ): bool {
		$chat_id    = (int) $message['chat_id'];
		$text       = (string) $message['text'];
		$message_id = (int) ( $message['message_id'] ?? 0 );

		// Inbound webhook arrives anonymous (no logged-in user). Promote to
		// the configured Telegram service user so ability `permission_callback`s
		// and `current_user_can()` checks downstream see a real principal.
		if ( $user_id > 0 && get_current_user_id() !== $user_id ) {
			wp_set_current_user( $user_id );
		}

		$session_id = self::resolve_session_for_chat( $chat_id, $user_id );

		$result = OpenclaWP_Runner::run_turn(
			$agent_slug,
			$text,
			$session_id,
			$user_id,
			array(
				'attachments'    => array(),
				'client_context' => array(
					'source'                   => 'channel',
					'connector_id'             => 'telegram',
					'client_name'              => 'telegram',
					'external_provider'        => 'telegram',
					'external_conversation_id' => (string) $chat_id,
					'external_message_id'      => (string) $message_id,
					'sender_id'                => (string) $chat_id,
					'room_kind'                => 'dm',
				),
			)
		);

		if ( ! empty( $result['session_id'] ) ) {
			self::tag_session_with_chat( (string) $result['session_id'], $chat_id );
		}

		$reply = isset( $result['reply'] ) ? (string) $result['reply'] : '';
		if ( '' === $reply ) {
			return false;
		}

		return self::send_text_message( $chat_id, $reply, $settings, $message_id );
	}

	/**
	 * Find an openclawp_session attached to this chat id, or null to start
	 * a fresh conversation.
	 *
	 * @return string|null Session UUID, or null when no prior session exists.
	 */
	private static function resolve_session_for_chat( int $chat_id, int $user_id ): ?string {
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
				'meta_key'               => self::META_CHAT_KEY,
				'meta_value'             => (string) $chat_id,
			)
		);
		if ( empty( $query->posts ) ) {
			return null;
		}
		$session_id = (string) get_post_meta( $query->posts[0]->ID, '_openclawp_session_id', true );
		return '' !== $session_id ? $session_id : null;
	}

	private static function tag_session_with_chat( string $session_id, int $chat_id ): void {
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
		update_post_meta( $query->posts[0]->ID, self::META_CHAT_KEY, (string) $chat_id );
	}

	/* ----------------------------- Outbound ------------------------------- */

	/**
	 * Send a text reply via `sendMessage`. Threads the reply to the inbound
	 * message when a non-zero `reply_to_message_id` is provided.
	 */
	public static function send_text_message( int $chat_id, string $body, array $settings = array(), int $reply_to_message_id = 0 ): bool {
		if ( empty( $settings ) ) {
			$settings = self::settings();
		}
		$bot_token = (string) $settings['bot_token'];
		if ( '' === $bot_token || 0 === $chat_id || '' === $body ) {
			return false;
		}

		$payload = array(
			'chat_id' => $chat_id,
			'text'    => $body,
		);
		if ( $reply_to_message_id > 0 ) {
			$payload['reply_to_message_id']         = $reply_to_message_id;
			$payload['allow_sending_without_reply'] = true;
		}

		$response = wp_remote_post(
			self::TELEGRAM_API . '/bot' . $bot_token . '/sendMessage',
			array(
				'timeout' => 20,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( '[openclawp] telegram_send_failed err=' . self::redact_token( $response->get_error_message(), $bot_token ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			error_log( '[openclawp] telegram_send_failed status=' . $code . ' body=' . self::redact_token( wp_remote_retrieve_body( $response ), $bot_token ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}

		return true;
	}

	/**
	 * Strip the bot token from text destined for logs. Telegram URLs embed
	 * the bot token in the path (`/bot<token>/sendMessage`), so any error
	 * message that includes the URL leaks the credential.
	 */
	public static function redact_token( string $text, string $bot_token ): string {
		if ( '' === $bot_token ) {
			return $text;
		}
		return str_replace( $bot_token, '[redacted]', $text );
	}

	/* --------------------------- setWebhook helper ------------------------ */

	/**
	 * Register our REST URL with Telegram so updates start flowing here.
	 * Called from the admin "Register webhook" button.
	 */
	public static function set_webhook( string $bot_token, string $url, string $secret_token ): array {
		if ( '' === $bot_token || '' === $url || '' === $secret_token ) {
			return array(
				'ok'    => false,
				'error' => 'missing-arg',
			);
		}

		$response = wp_remote_post(
			self::TELEGRAM_API . '/bot' . $bot_token . '/setWebhook',
			array(
				'timeout' => 20,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'url'             => $url,
						'secret_token'    => $secret_token,
						'allowed_updates' => array( 'message' ),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'    => false,
				'error' => self::redact_token( $response->get_error_message(), $bot_token ),
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['ok'] ) ) {
			return array(
				'ok'    => false,
				'error' => is_array( $body ) ? (string) ( $body['description'] ?? 'unknown' ) : 'invalid-response',
			);
		}
		return array( 'ok' => true );
	}

	/* ------------------------------ Settings ------------------------------ */

	public static function register_channel_card( array $channels ): array {
		$settings   = self::settings();
		$configured = '' !== trim( (string) $settings['bot_token'] );
		$channels[] = array(
			'id'          => 'telegram',
			'name'        => __( 'Telegram', 'openclawp' ),
			'subtitle'    => __( 'Bot API webhook', 'openclawp' ),
			'description' => __( 'Free, official bot API. No carrier registration or business verification.', 'openclawp' ),
			'status'      => $configured
				? OpenclaWP_Channels_Admin::STATUS_CONNECTED
				: OpenclaWP_Channels_Admin::STATUS_NOT_CONFIGURED,
			'detail_url'  => admin_url( 'admin.php?page=openclawp-telegram' ),
		);
		return $channels;
	}

	public static function register_settings_menu(): void {
		add_submenu_page(
			'openclawp',
			__( 'Telegram', 'openclawp' ),
			__( 'Telegram', 'openclawp' ),
			'manage_options',
			'openclawp-telegram',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	public static function register_settings(): void {
		register_setting(
			'openclawp_telegram',
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
			'bot_token'     => isset( $value['bot_token'] ) ? trim( (string) $value['bot_token'] ) : '',
			'secret_token'  => isset( $value['secret_token'] ) ? trim( (string) $value['secret_token'] ) : '',
			'allowlist'     => isset( $value['allowlist'] ) ? trim( (string) $value['allowlist'] ) : '',
			'default_agent' => isset( $value['default_agent'] ) ? sanitize_title( (string) $value['default_agent'] ) : '',
			'user_id'       => isset( $value['user_id'] ) ? (int) $value['user_id'] : 0,
		);
	}

	public static function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		self::maybe_handle_set_webhook();

		$settings    = self::settings();
		$webhook_url = esc_url( rest_url( self::REST_NAMESPACE . self::REST_ROUTE ) );
		$agents      = function_exists( 'wp_get_agents' ) ? wp_get_agents() : array();
		$dropped     = (int) get_option( self::DROPPED_OPTION, 0 );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'openclaWP — Telegram', 'openclawp' ); ?></h1>
			<p class="description">
				<?php
				printf(
					/* translators: %s: webhook URL. */
					esc_html__( 'Inbound updates land at %s. Register this URL with Telegram via the button below or via BotFather + curl.', 'openclawp' ),
					'<code>' . esc_html( $webhook_url ) . '</code>'
				);
				?>
			</p>

			<?php if ( $dropped > 0 ) : ?>
				<div class="notice notice-info inline">
					<p>
						<?php
						printf(
							/* translators: %d: number of dropped messages. */
							esc_html__( '%d inbound messages dropped because the sender was not in the allowlist.', 'openclawp' ),
							(int) $dropped
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<form action="options.php" method="post">
				<?php settings_fields( 'openclawp_telegram' ); ?>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="openclawp-tg-bot-token"><?php esc_html_e( 'Bot token', 'openclawp' ); ?></label></th>
							<td>
								<input type="password" id="openclawp-tg-bot-token" class="regular-text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[bot_token]" value="<?php echo esc_attr( $settings['bot_token'] ); ?>" autocomplete="new-password">
								<p class="description"><?php esc_html_e( 'BotFather issues this. Looks like `123456:ABC-DEF…`. Stored in wp_options.', 'openclawp' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="openclawp-tg-secret-token"><?php esc_html_e( 'Webhook secret token', 'openclawp' ); ?></label></th>
							<td>
								<input type="password" id="openclawp-tg-secret-token" class="regular-text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[secret_token]" value="<?php echo esc_attr( $settings['secret_token'] ); ?>" autocomplete="new-password">
								<p class="description"><?php esc_html_e( 'Telegram sends this back in `X-Telegram-Bot-Api-Secret-Token` on every webhook. Letters/numbers/`-_` only, 1–256 chars.', 'openclawp' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="openclawp-tg-allowlist"><?php esc_html_e( 'Chat allowlist', 'openclawp' ); ?></label></th>
							<td>
								<input type="text" id="openclawp-tg-allowlist" class="regular-text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[allowlist]" value="<?php echo esc_attr( $settings['allowlist'] ); ?>">
								<p class="description"><?php esc_html_e( 'Comma-separated chat IDs allowed to reach the agent. Use `*` to allow everyone (development only). Empty = nothing allowed.', 'openclawp' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="openclawp-tg-agent"><?php esc_html_e( 'Default agent', 'openclawp' ); ?></label></th>
							<td>
								<select id="openclawp-tg-agent" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[default_agent]">
									<option value=""><?php esc_html_e( '— Select an agent —', 'openclawp' ); ?></option>
									<?php foreach ( $agents as $slug => $agent_obj ) : ?>
										<option value="<?php echo esc_attr( (string) $slug ); ?>" <?php selected( $settings['default_agent'], (string) $slug ); ?>>
											<?php echo esc_html( $agent_obj instanceof WP_Agent ? $agent_obj->get_label() : (string) $slug ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Inbound Telegram messages are dispatched to this agent.', 'openclawp' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="openclawp-tg-user-id"><?php esc_html_e( 'Owner user ID', 'openclawp' ); ?></label></th>
							<td>
								<input type="number" id="openclawp-tg-user-id" class="small-text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[user_id]" value="<?php echo esc_attr( (string) $settings['user_id'] ); ?>" min="0">
								<p class="description"><?php esc_html_e( 'WP user ID that owns inbound conversations. 0 falls back to the current admin.', 'openclawp' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Register webhook with Telegram', 'openclawp' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Calls `setWebhook` with the URL above and the secret token. Save settings first.', 'openclawp' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( 'openclawp_telegram_set_webhook', 'openclawp_telegram_nonce' ); ?>
				<input type="hidden" name="openclawp_telegram_action" value="set_webhook">
				<?php submit_button( __( 'Register webhook', 'openclawp' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle the "Register webhook" button POST. Reads the saved settings,
	 * calls Telegram's setWebhook, surfaces the outcome as an admin notice.
	 */
	private static function maybe_handle_set_webhook(): void {
		if ( ! isset( $_POST['openclawp_telegram_action'] ) || 'set_webhook' !== $_POST['openclawp_telegram_action'] ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! isset( $_POST['openclawp_telegram_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['openclawp_telegram_nonce'] ) ), 'openclawp_telegram_set_webhook' ) ) {
			return;
		}

		$settings = self::settings();
		$result   = self::set_webhook(
			(string) $settings['bot_token'],
			rest_url( self::REST_NAMESPACE . self::REST_ROUTE ),
			(string) $settings['secret_token']
		);

		add_action(
			'admin_notices',
			static function () use ( $result ) {
				if ( ! empty( $result['ok'] ) ) {
					printf(
						'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
						esc_html__( 'Telegram webhook registered.', 'openclawp' )
					);
					return;
				}
				printf(
					'<div class="notice notice-error is-dismissible"><p>%s %s</p></div>',
					esc_html__( 'Telegram webhook registration failed:', 'openclawp' ),
					esc_html( (string) ( $result['error'] ?? 'unknown' ) )
				);
			}
		);
	}
}
