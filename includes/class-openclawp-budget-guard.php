<?php
/**
 * Budget guard for chat turns and tool calls.
 *
 * Enforces simple site/agent daily and monthly caps based on recorded usage,
 * and a per-turn tool-call ceiling before the executor dispatches a tool.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Budget_Guard {

	public static function register(): void {
		add_filter( 'openclawp_pre_chat_turn', array( __CLASS__, 'pre_chat_turn' ), 5, 2 );
		add_filter( 'openclawp_pre_tool_execute', array( __CLASS__, 'pre_tool_execute' ), 5, 2 );
	}

	/**
	 * @param mixed $existing Existing preflight response.
	 * @param array $context  Turn context.
	 * @return mixed
	 */
	public static function pre_chat_turn( $existing, array $context ) {
		if ( null !== $existing ) {
			return $existing;
		}

		$result = self::check_turn_budget( $context );
		return null === $result ? null : $result;
	}

	/**
	 * @param mixed $existing Existing tool preflight response.
	 * @param array $payload  Tool execution payload.
	 * @return mixed
	 */
	public static function pre_tool_execute( $existing, array $payload ) {
		if ( null !== $existing ) {
			return $existing;
		}

		$limits = self::limits( (array) ( $payload['runtime_context'] ?? array() ) );
		$max    = (int) ( $limits['max_tool_calls_per_turn'] ?? 0 );
		$index  = (int) ( $payload['tool_call_index'] ?? 0 );
		if ( $max > 0 && $index > $max ) {
			return new WP_Error(
				'openclawp_tool_budget_exceeded',
				sprintf(
					/* translators: 1: tool-call limit. */
					__( 'Tool-call budget exceeded for this turn. Limit: %d.', 'openclawp' ),
					$max
				),
				array(
					'status' => 429,
					'limit'  => $max,
					'actual' => $index,
				)
			);
		}

		return null;
	}

	/**
	 * @param array $context Chat turn context.
	 * @return WP_Error|null
	 */
	public static function check_turn_budget( array $context ): ?WP_Error {
		$limits = self::limits( (array) ( $context['runtime_context'] ?? array() ) );
		if ( ! self::has_turn_limits( $limits ) ) {
			return null;
		}

		$agent_slug = (string) ( $context['agent_slug'] ?? '' );

		$checks = array(
			array( 'scope' => 'site', 'period' => 'day', 'totals' => OpenclaWP_Usage_Store::get_totals( array( 'period' => 'day' ) ) ),
			array( 'scope' => 'site', 'period' => 'month', 'totals' => OpenclaWP_Usage_Store::get_totals( array( 'period' => 'month' ) ) ),
		);
		if ( '' !== $agent_slug ) {
			$checks[] = array( 'scope' => 'agent', 'period' => 'day', 'totals' => OpenclaWP_Usage_Store::get_totals( array( 'period' => 'day', 'agent_slug' => $agent_slug ) ) );
			$checks[] = array( 'scope' => 'agent', 'period' => 'month', 'totals' => OpenclaWP_Usage_Store::get_totals( array( 'period' => 'month', 'agent_slug' => $agent_slug ) ) );
		}

		foreach ( $checks as $check ) {
			$exceeded = self::exceeded_limits( $check['totals'], $limits, $check['period'], $check['scope'] );
			if ( null !== $exceeded ) {
				return new WP_Error(
					'openclawp_budget_exceeded',
					sprintf(
						/* translators: 1: budget metric, 2: period. */
						__( 'openclaWP budget exceeded for %1$s in the current %2$s.', 'openclawp' ),
						$exceeded['metric'],
						$check['period']
					),
					array_merge(
						array(
							'status' => 429,
							'scope'  => $check['scope'],
							'period' => $check['period'],
						),
						$exceeded
					)
				);
			}
		}

		return null;
	}

	/**
	 * Normalize budget limits from options and filters.
	 *
	 * @param array $runtime_context Runtime context.
	 * @return array<string,float|int>
	 */
	public static function limits( array $runtime_context = array() ): array {
		$options = get_option( 'openclawp_options', array() );
		$options = is_array( $options ) ? $options : array();

		$limits = array(
			'daily_usd'                => $options['budget_daily_usd'] ?? 0,
			'monthly_usd'              => $options['budget_monthly_usd'] ?? 0,
			'daily_turns'              => $options['budget_daily_turns'] ?? 0,
			'monthly_turns'            => $options['budget_monthly_turns'] ?? 0,
			'agent_daily_usd'          => $options['budget_agent_daily_usd'] ?? 0,
			'agent_monthly_usd'        => $options['budget_agent_monthly_usd'] ?? 0,
			'agent_daily_turns'        => $options['budget_agent_daily_turns'] ?? 0,
			'agent_monthly_turns'      => $options['budget_agent_monthly_turns'] ?? 0,
			'max_tool_calls_per_turn'  => $options['budget_max_tool_calls_per_turn'] ?? 0,
		);

		/**
		 * Filters active budget limits.
		 *
		 * Return 0/empty for a key to disable it.
		 *
		 * @param array $limits
		 * @param array $runtime_context
		 */
		$limits = (array) apply_filters( 'openclawp_budget_limits', $limits, $runtime_context );

		foreach ( $limits as $key => $value ) {
			if ( 'max_tool_calls_per_turn' === $key || str_ends_with( (string) $key, '_turns' ) ) {
				$limits[ $key ] = max( 0, (int) $value );
			} else {
				$limits[ $key ] = max( 0.0, (float) $value );
			}
		}

		return $limits;
	}

	/**
	 * @param array<string,mixed> $totals
	 * @param array<string,mixed> $limits
	 * @return array{metric:string,limit:float|int,actual:float|int}|null
	 */
	public static function exceeded_limits( array $totals, array $limits, string $period, string $scope = 'site' ): ?array {
		$prefix = 'agent' === $scope ? 'agent_' : '';

		$usd_key = $prefix . ( 'month' === $period ? 'monthly_usd' : 'daily_usd' );
		$usd     = (float) ( $limits[ $usd_key ] ?? 0 );
		if ( $usd > 0 && (float) ( $totals['est_cost_usd'] ?? 0 ) >= $usd ) {
			return array(
				'metric' => 'estimated_cost_usd',
				'limit'  => $usd,
				'actual' => (float) ( $totals['est_cost_usd'] ?? 0 ),
			);
		}

		$turns_key = $prefix . ( 'month' === $period ? 'monthly_turns' : 'daily_turns' );
		$turns     = (int) ( $limits[ $turns_key ] ?? 0 );
		if ( $turns > 0 && (int) ( $totals['turns'] ?? 0 ) >= $turns ) {
			return array(
				'metric' => 'turns',
				'limit'  => $turns,
				'actual' => (int) ( $totals['turns'] ?? 0 ),
			);
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $limits
	 */
	private static function has_turn_limits( array $limits ): bool {
		foreach ( array( 'daily_usd', 'monthly_usd', 'daily_turns', 'monthly_turns', 'agent_daily_usd', 'agent_monthly_usd', 'agent_daily_turns', 'agent_monthly_turns' ) as $key ) {
			if ( ! empty( $limits[ $key ] ) ) {
				return true;
			}
		}
		return false;
	}
}
