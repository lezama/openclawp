<?php
/**
 * Register openclawp/chat as the handler of the canonical agents/chat
 * dispatcher (agents-api#100).
 *
 * agents-api ships `agents/chat` as a runtime-agnostic dispatcher that
 * validates the canonical input/output shape and delegates execution to
 * whichever runtime registers itself via `wp_agent_chat_handler`. This
 * file makes openclaWP that runtime, so any caller targeting `agents/chat`
 * (channels, bridges, future blocks, future REST surfaces) gets routed
 * through openclaWP's existing chat ability.
 *
 * `openclawp/chat` stays registered for backwards compatibility — existing
 * direct callers keep working — and now also serves as the implementation
 * behind the canonical contract.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Canonical_Chat_Handler {

	public static function register(): void {
		add_filter( 'wp_agent_chat_handler', array( __CLASS__, 'register_handler' ), 10, 2 );
	}

	/**
	 * Filter callback. Returns the openclaWP runtime as the chat handler
	 * unless an earlier filter already registered one.
	 *
	 * @param callable|null $existing
	 * @param array         $input
	 * @return callable|null
	 */
	public static function register_handler( $existing, array $input ) {
		unset( $input );
		if ( null !== $existing ) {
			return $existing; // an earlier hook already won
		}
		return array( __CLASS__, 'execute' );
	}

	/**
	 * Translate the canonical input into an `openclawp/chat` ability call.
	 *
	 * Explicit projection keeps the canonical chat contract intact while
	 * letting the runner expose channel context to tool calls.
	 *
	 * @param array $input Canonical agents/chat input (per agents-api#100).
	 * @return array|WP_Error Canonical agents/chat output, or error.
	 */
	public static function execute( array $input ) {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return new WP_Error( 'abilities_api_missing', 'Abilities API is not loaded.' );
		}

		$ability = wp_get_ability( 'openclawp/chat' );
		if ( ! $ability ) {
			return new WP_Error(
				'openclawp_chat_unavailable',
				'openclawp/chat ability is not registered.'
			);
		}

		return $ability->execute(
			array(
				'agent'      => (string) ( $input['agent'] ?? '' ),
				'message'    => (string) ( $input['message'] ?? '' ),
				'session_id' => $input['session_id'] ?? null,
				'attachments'    => isset( $input['attachments'] ) && is_array( $input['attachments'] ) ? $input['attachments'] : array(),
				'client_context' => isset( $input['client_context'] ) && is_array( $input['client_context'] ) ? $input['client_context'] : array(),
			)
		);
	}
}
