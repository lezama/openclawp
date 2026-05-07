<?php
/**
 * Event sink.
 *
 * Subscribes to the agents-api conversation loop event stream. For MVP, writes
 * structured `error_log` lines prefixed `[openclawp]`. DB-backed audit logging
 * is a follow-up.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Event_Sink {

	public static function register(): void {
		add_action( 'agents_api_loop_event', array( __CLASS__, 'observe' ), 10, 2 );
	}

	/**
	 * Also passed directly to the loop via the `on_event` option from the runner.
	 *
	 * @param string $event   Event name.
	 * @param array  $payload Event payload snapshot.
	 */
	public static function observe( string $event, array $payload = array() ): void {
		$encoded = wp_json_encode( $payload );
		if ( ! is_string( $encoded ) ) {
			$encoded = '{}';
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional MVP audit sink.
		error_log( sprintf( '[openclawp] event=%s payload=%s', $event, $encoded ) );
	}
}
