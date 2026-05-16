<?php
/**
 * CPT-backed per-turn usage recorder.
 *
 * Subscribes to `openclawp_chat_turn_completed` and persists one
 * `openclawp_usage` post per turn — provider/model, token counts, an
 * estimated USD cost computed from a filterable pricing table, wall
 * duration, tool-call count, and success/error. The admin Usage page
 * queries these rows for recent-turn lists and aggregate dashboards.
 *
 * Sibling of `class-openclawp-workflow-run-recorder.php`. No agents-api
 * contract change for v1: a future PR can introduce a substrate
 * `WP_Agent_Usage_Recorder` interface and this class would implement it.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Usage_Recorder {

	/**
	 * CPT slug. Capped at 16 characters — `register_post_type` rejects
	 * names longer than 20.
	 */
	public const POST_TYPE = 'openclawp_usage';

	public const META_AGENT_SLUG          = '_openclawp_agent_slug';
	public const META_SESSION_ID          = '_openclawp_session_id';
	public const META_PROVIDER            = '_openclawp_provider';
	public const META_MODEL               = '_openclawp_model';
	public const META_INPUT_TOKENS        = '_openclawp_input_tokens';
	public const META_OUTPUT_TOKENS       = '_openclawp_output_tokens';
	public const META_TOTAL_TOKENS        = '_openclawp_total_tokens';
	public const META_EST_COST_USD        = '_openclawp_est_cost_usd';
	public const META_PRICING_RESOLVED    = '_openclawp_pricing_resolved';
	public const META_WALL_DURATION_MS    = '_openclawp_wall_duration_ms';
	public const META_TOOL_CALL_COUNT     = '_openclawp_tool_call_count';
	public const META_SUCCESS             = '_openclawp_success';
	public const META_ERROR               = '_openclawp_error';

	public static function register(): void {
		// Priority 20 so the event-sink (priority 10) runs first — keeps
		// the error_log line emitted even when the recorder errors out.
		add_action( 'openclawp_chat_turn_completed', array( __CLASS__, 'record_turn' ), 20, 1 );
	}

	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'openclaWP Usage', 'openclawp' ),
					'singular_name' => __( 'openclaWP Usage Record', 'openclawp' ),
				),
				'public'              => false,
				'show_ui'             => false,
				'show_in_rest'        => true,
				'rest_base'           => 'openclawp-usage',
				'rest_namespace'      => 'wp/v2',
				'exclude_from_search' => true,
				'rewrite'             => false,
				'has_archive'         => false,
				'supports'            => array( 'title', 'author', 'custom-fields' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			)
		);
	}

	/**
	 * Subscriber for `openclawp_chat_turn_completed`. Writes one CPT row.
	 *
	 * Errors are swallowed (logged) — usage recording must not crash a
	 * chat turn that succeeded on the wire.
	 *
	 * @param array $telemetry Read-only snapshot from the runner.
	 */
	public static function record_turn( array $telemetry ): void {
		try {
			$agent_slug = (string) ( $telemetry['agent_slug'] ?? '' );
			$provider   = (string) ( $telemetry['provider'] ?? '' );
			$model      = (string) ( $telemetry['model'] ?? '' );

			$token_usage  = is_array( $telemetry['token_usage'] ?? null ) ? $telemetry['token_usage'] : array();
			$input        = (int) ( $token_usage['input'] ?? 0 );
			$output       = (int) ( $token_usage['output'] ?? 0 );
			$total        = (int) ( $token_usage['total'] ?? ( $input + $output ) );
			$duration_ms  = (int) ( $telemetry['duration_ms'] ?? 0 );
			$success      = ! empty( $telemetry['success'] );
			$error        = (string) ( $telemetry['error'] ?? '' );
			$tool_count   = (int) ( $telemetry['tool_call_count'] ?? 0 );
			$session_id   = (string) ( $telemetry['session_id'] ?? '' );

			$cost_info = self::estimate_cost( $provider, $model, $input, $output );

			$title = trim( sprintf(
				'%s · %s',
				'' !== $provider && '' !== $model ? $provider . '/' . $model : ( $provider ?: $model ?: '(unknown)' ),
				$agent_slug ?: '(unknown agent)'
			) );

			$post_id = wp_insert_post(
				array(
					'post_type'    => self::POST_TYPE,
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_author'  => get_current_user_id(),
					'post_content' => '',
				),
				true
			);

			if ( is_wp_error( $post_id ) || ! $post_id ) {
				return;
			}

			$post_id = (int) $post_id;
			update_post_meta( $post_id, self::META_AGENT_SLUG,       $agent_slug );
			update_post_meta( $post_id, self::META_SESSION_ID,       $session_id );
			update_post_meta( $post_id, self::META_PROVIDER,         $provider );
			update_post_meta( $post_id, self::META_MODEL,            $model );
			update_post_meta( $post_id, self::META_INPUT_TOKENS,     $input );
			update_post_meta( $post_id, self::META_OUTPUT_TOKENS,    $output );
			update_post_meta( $post_id, self::META_TOTAL_TOKENS,     $total );
			update_post_meta( $post_id, self::META_EST_COST_USD,     $cost_info['cost'] );
			update_post_meta( $post_id, self::META_PRICING_RESOLVED, $cost_info['resolved'] ? '1' : '0' );
			update_post_meta( $post_id, self::META_WALL_DURATION_MS, $duration_ms );
			update_post_meta( $post_id, self::META_TOOL_CALL_COUNT,  $tool_count );
			update_post_meta( $post_id, self::META_SUCCESS,          $success ? '1' : '0' );
			update_post_meta( $post_id, self::META_ERROR,            $error );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- recorder failure should not break chat.
			error_log( '[openclawp] usage_recorder error: ' . $e->getMessage() );
		}
	}

	/**
	 * Per-1k input/output rates by `provider|model`. Wildcard
	 * `provider|*` is honored as a fallback. Filterable via
	 * `openclawp_model_pricing`.
	 *
	 * @return array<string, array{input_per_1k:float,output_per_1k:float}>
	 */
	public static function pricing_table(): array {
		$baseline = array(
			'anthropic|claude-haiku-4-5'   => array( 'input_per_1k' => 0.001,   'output_per_1k' => 0.005 ),
			'anthropic|claude-sonnet-4-6'  => array( 'input_per_1k' => 0.003,   'output_per_1k' => 0.015 ),
			'anthropic|claude-opus-4-7'    => array( 'input_per_1k' => 0.015,   'output_per_1k' => 0.075 ),
			'openai|gpt-4o-mini'           => array( 'input_per_1k' => 0.00015, 'output_per_1k' => 0.0006 ),
			'openai|gpt-4o'                => array( 'input_per_1k' => 0.0025,  'output_per_1k' => 0.01 ),
			'google|gemini-2.5-flash'      => array( 'input_per_1k' => 0.000075,'output_per_1k' => 0.0003 ),
			'google|gemini-2.5-pro'        => array( 'input_per_1k' => 0.00125, 'output_per_1k' => 0.005 ),
			'ollama|*'                     => array( 'input_per_1k' => 0.0,     'output_per_1k' => 0.0 ),
		);
		/**
		 * Filters the per-1k input/output token pricing table.
		 *
		 * Keys are `provider|model` (or `provider|*` as a wildcard
		 * fallback). Values are arrays with `input_per_1k` and
		 * `output_per_1k` keys, both floats in USD.
		 *
		 * @since 0.7.0
		 *
		 * @param array<string, array{input_per_1k:float,output_per_1k:float}> $rates Baseline rates.
		 */
		return (array) apply_filters( 'openclawp_model_pricing', $baseline );
	}

	/**
	 * Estimate cost in USD for an input/output token pair.
	 *
	 * @return array{cost:float, resolved:bool}
	 */
	public static function estimate_cost( string $provider, string $model, int $input_tokens, int $output_tokens ): array {
		if ( '' === $provider && '' === $model ) {
			return array( 'cost' => 0.0, 'resolved' => false );
		}

		$table = self::pricing_table();
		$key   = $provider . '|' . $model;
		$entry = $table[ $key ] ?? ( $table[ $provider . '|*' ] ?? null );

		if ( null === $entry || ! is_array( $entry ) ) {
			return array( 'cost' => 0.0, 'resolved' => false );
		}

		$input_rate  = (float) ( $entry['input_per_1k'] ?? 0.0 );
		$output_rate = (float) ( $entry['output_per_1k'] ?? 0.0 );

		$cost = ( $input_tokens / 1000.0 ) * $input_rate
			+ ( $output_tokens / 1000.0 ) * $output_rate;

		return array(
			'cost'     => round( $cost, 6 ),
			'resolved' => true,
		);
	}
}
