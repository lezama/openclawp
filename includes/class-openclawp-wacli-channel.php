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

	private string $chat_jid;
	private string $reply_to_message_id;

	public function __construct( string $agent_slug, string $chat_jid, string $reply_to_message_id = '' ) {
		parent::__construct( $agent_slug );
		$this->chat_jid            = $chat_jid;
		$this->reply_to_message_id = $reply_to_message_id;
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

	// ─── Lifecycle: silent loop-prevention and allowlist ──────────────

	protected function validate( array $data ): ?\WP_Error {
		if ( ! empty( $data['from_me'] ) || ! empty( $data['fromMe'] ) ) {
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
			$this->reply_to_message_id
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
		$raw = (string) get_option( 'openclawp_wacli_allowed_jids', '' );
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
