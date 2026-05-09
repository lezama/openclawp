<?php
/**
 * WP-CLI helpers for the wacli transport.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

final class OpenclaWP_Wacli_CLI {

	/**
	 * Print the wacli sync command to copy-paste, generating an HMAC secret on first run.
	 *
	 * Usage:
	 *   wp openclawp wacli setup [--rotate]
	 *
	 * --rotate
	 * : Regenerate the HMAC secret instead of reusing the existing one. Existing
	 *   wacli processes will need to restart with the new secret.
	 */
	public function setup( $args, $assoc_args ): void {
		$secret = ! empty( $assoc_args['rotate'] )
			? OpenclaWP_Wacli_Transport::rotate_secret()
			: OpenclaWP_Wacli_Transport::ensure_secret();

		$agent = (string) get_option( 'openclawp_wacli_agent', '' );
		if ( '' === $agent ) {
			\WP_CLI::warning( 'No agent configured. Run: wp option update openclawp_wacli_agent <agent-slug>' );
		}

		$url = OpenclaWP_Wacli_Transport::webhook_url();

		\WP_CLI::log( '' );
		\WP_CLI::log( 'Run this in a terminal that has wacli authenticated:' );
		\WP_CLI::log( '' );
		\WP_CLI::log(
			sprintf(
				'  WACLI_WEBHOOK_SECRET=%s \\' . "\n" .
				'    wacli sync --follow \\' . "\n" .
				'    --webhook %s',
				escapeshellarg( $secret ),
				escapeshellarg( $url )
			)
		);
		\WP_CLI::log( '' );
		\WP_CLI::log( sprintf( 'Forwarding to agent: %s', $agent ?: '(none configured)' ) );
		\WP_CLI::log( sprintf( 'Webhook URL:        %s', $url ) );
	}

	/**
	 * Send a test WhatsApp message via wacli to verify the binary is reachable.
	 *
	 * ## OPTIONS
	 *
	 * <jid>
	 * : Recipient JID, phone number, or synced contact name.
	 *
	 * <message>
	 * : The text to send.
	 *
	 * Usage:
	 *   wp openclawp wacli ping 1234567890 "hello from openclawp"
	 */
	public function ping( $args, $assoc_args ): void {
		[ $jid, $message ] = array_pad( $args, 2, '' );
		if ( '' === $jid || '' === $message ) {
			\WP_CLI::error( 'Usage: wp openclawp wacli ping <jid> <message>' );
		}
		$result = OpenclaWP_Wacli_Transport::send_via_wacli( $jid, $message );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		\WP_CLI::success( 'Sent.' );
	}
}
