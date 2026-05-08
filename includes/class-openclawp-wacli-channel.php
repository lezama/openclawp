<?php
/**
 * WhatsApp channel for openclaWP, backed by openclaw/wacli.
 *
 * Concrete implementation of `\AgentsAPI\AI\Channels\WP_Agent_Channel`. Each
 * inbound webhook payload from `wacli sync --follow --webhook ...` constructs
 * one of these per chat thread; the channel base class drives the agent loop
 * and we only fill in the wacli-specific I/O (extract message, send reply,
 * loop-prevention, allowlist).
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

use AgentsAPI\AI\Channels\WP_Agent_Channel;

final class OpenclaWP_Wacli_Channel extends WP_Agent_Channel {

	/**
	 * Production-safe (default): self-messages from the linked account are
	 * silent-skipped to prevent reply loops; everyone else's messages reach
	 * the agent.
	 */
	public const MODE_BLOCK = 'block';

	/**
	 * Pass everything through, including from_me. Useful for solo testing
	 * combined with the outbound msg_id dedupe.
	 */
	public const MODE_ALLOW = 'allow';

	/**
	 * Test-only: respond ONLY to messages the linked account itself sends
	 * — every other inbound is silent-skipped. Use when pairing a personal
	 * WhatsApp account so other contacts never trigger the agent.
	 */
	public const MODE_ONLY = 'only';

	public const MODE_OPTION        = 'openclawp_wacli_self_message_mode';
	public const LEGACY_ALLOW_OPTION = 'openclawp_wacli_allow_self_messages';

	private string $chat_jid;
	private string $reply_to_message_id;
	private string $reply_to_sender_jid;

	public function __construct( string $agent_slug, string $chat_jid, string $reply_to_message_id = '', string $reply_to_sender_jid = '' ) {
		parent::__construct( $agent_slug );
		$this->chat_jid            = $chat_jid;
		$this->reply_to_message_id = $reply_to_message_id;
		$this->reply_to_sender_jid = $reply_to_sender_jid;
	}

	// ─── Channel identity ──────────────────────────────────────────────

	public function get_external_id_provider(): string {
		return 'wacli';
	}

	public function get_external_id(): ?string {
		return '' === $this->chat_jid ? null : $this->chat_jid;
	}

	public function get_client_name(): string {
		return 'wacli';
	}

	protected function get_job_action(): string {
		// Webhook → handle() runs synchronously inside the REST request; no
		// async dispatch. wacli does not retry on failure, so we want a clean
		// 200 to come back from the REST callback after the reply was sent.
		return '';
	}

	// ─── Conversation metadata for the canonical agents/chat dispatcher ─

	protected function get_room_kind( array $data ): ?string {
		unset( $data );
		// JID suffix is the most reliable kind discriminator wacli emits.
		// `@lid` is WhatsApp's opaque Linked-Id format and is used for both
		// DM senders and (rarely) groups; we treat dash-bearing JIDs as
		// groups regardless of suffix.
		if ( str_contains( $this->chat_jid, '-' ) && str_ends_with( $this->chat_jid, '@g.us' ) ) {
			return 'group';
		}
		if ( str_ends_with( $this->chat_jid, '@g.us' ) ) {
			return 'group';
		}
		if ( str_ends_with( $this->chat_jid, '@newsletter' ) ) {
			return 'channel';
		}
		return 'dm';
	}

	// ─── Lifecycle: silent loop-prevention and allowlist ──────────────

	protected function validate( array $data ): ?\WP_Error {
		// Skip echoes of our own outbound replies (wacli reflects them back).
		// Cheap, msg_id-based, never confused with genuine inbound traffic.
		$msg_id = (string) ( $data['msg_id'] ?? $data['ID'] ?? '' );
		if ( '' !== $msg_id && OpenclaWP_Wacli_Transport::is_recent_outbound( $msg_id ) ) {
			return new \WP_Error( self::SILENT_SKIP_CODE, 'self_reply_echo' );
		}

		$is_self = ! empty( $data['from_me'] ) || ! empty( $data['fromMe'] ) || ! empty( $data['FromMe'] );
		$mode    = self::resolve_self_message_mode(
			(string) get_option( self::MODE_OPTION, '' ),
			(bool) get_option( self::LEGACY_ALLOW_OPTION, false )
		);

		// `only` mode: ignore everyone except the linked account itself.
		// Lets you pair your personal number for solo demos without the
		// bot answering messages from family / coworkers / contacts.
		if ( self::MODE_ONLY === $mode && ! $is_self ) {
			return new \WP_Error( self::SILENT_SKIP_CODE, 'test_mode_self_only' );
		}

		// `block` mode (default): production-safe loop prevention.
		$default_skip = ( self::MODE_BLOCK === $mode );
		/**
		 * Filter the loop-prevention skip on self-originated messages.
		 *
		 * Defaults to true in `block` mode and false in `allow` / `only`.
		 * Return true to silent-skip every from_me message; false to let
		 * the agent reply to messages the linked account sent.
		 *
		 * @param bool  $skip True to silent-skip self-messages.
		 * @param array $data Raw normalized webhook payload.
		 */
		if ( $is_self && (bool) apply_filters( 'openclawp_wacli_skip_self_messages', $default_skip, $data ) ) {
			return new \WP_Error( self::SILENT_SKIP_CODE, 'self_message' );
		}
		if ( '' === $this->chat_jid ) {
			return new \WP_Error( self::SILENT_SKIP_CODE, 'no_chat' );
		}
		if ( ! $this->is_allowed( $this->chat_jid ) ) {
			return new \WP_Error( self::SILENT_SKIP_CODE, 'chat_not_allowed' );
		}
		return null;
	}

	/**
	 * Resolve the active self-message mode from the new enum option, with
	 * fall-through to the legacy boolean shipped in #9. Pure function — the
	 * runtime caller passes both option values so unit tests can drive it
	 * directly without WP loaded.
	 *
	 * @param string $mode_option   Value of openclawp_wacli_self_message_mode.
	 * @param bool   $legacy_allow  Value of openclawp_wacli_allow_self_messages (legacy).
	 * @return self::MODE_*
	 */
	public static function resolve_self_message_mode( string $mode_option, bool $legacy_allow ): string {
		if ( in_array( $mode_option, array( self::MODE_BLOCK, self::MODE_ALLOW, self::MODE_ONLY ), true ) ) {
			return $mode_option;
		}
		return $legacy_allow ? self::MODE_ALLOW : self::MODE_BLOCK;
	}

	// ─── I/O ───────────────────────────────────────────────────────────

	protected function extract_message( array $data ): string {
		// wacli emits text under different keys depending on the message kind.
		// Defensive pluck across the documented variations.
		return (string) ( $data['display_text'] ?? $data['text'] ?? $data['body'] ?? '' );
	}

	protected function send_response( string $text ): void {
		// Delegate the actual proc_open / shell-out to the transport static
		// helper. Keeps the channel free of CLI-shape concerns.
		$result = OpenclaWP_Wacli_Transport::send_via_wacli(
			$this->chat_jid,
			$text,
			$this->reply_to_message_id,
			$this->reply_to_sender_jid
		);

		if ( is_wp_error( $result ) ) {
			$this->response_status = 'failed';
			error_log( '[openclawp-wacli] send failed: ' . $result->get_error_message() );
		}
	}

	protected function send_error( string $text ): void {
		// Don't post error replies to the user's WhatsApp by default — we
		// don't want the agent's failures bleeding into a personal thread.
		// Log instead. Subclasses can override if they want the noise.
		error_log( '[openclawp-wacli] agent error in chat ' . $this->chat_jid . ': ' . $text );
	}

	// ─── Allowlist ─────────────────────────────────────────────────────

	private function is_allowed( string $chat_jid ): bool {
		$raw = (string) get_option( OpenclaWP_Wacli_Transport::ALLOWED_OPTION, '' );
		if ( '' === $raw ) {
			return true; // empty allowlist = allow all
		}
		$allowed = array_filter( array_map( 'trim', preg_split( '/[\s,]+/', $raw ) ?: array() ) );
		return in_array( $chat_jid, $allowed, true );
	}

	// ─── Custom session storage key (kept compatible with v0 transport) ─

	protected function session_storage_key(): string {
		// Match the legacy key shape so existing chats keep their session
		// continuity across the refactor. Once everyone has migrated this
		// can fold back into the parent's md5(provider:external_id).
		return 'openclawp_wacli_session_' . md5( $this->chat_jid );
	}
}
