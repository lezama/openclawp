<?php
/**
 * Tracer overhead micro-benchmark.
 *
 * Drives one synthetic turn + one tool-call through OpenclaWP_Tracer 1000 times
 * and reports avg/p50/p95/p99/max overhead. Used to assert the <5ms-per-turn
 * budget from issue #41.
 *
 * Run: php bin/bench-tracer.php
 *
 * @package OpenclaWP
 */

require __DIR__ . '/../tests/bootstrap.php';

$GLOBALS['openclawp_test_filters']['openclawp_otel_endpoint'] = static function () {
	return 'https://collector.example/v1/traces';
};

$samples = array();
for ( $i = 0; $i < 1000; $i++ ) {
	OpenclaWP_Tracer::reset();
	OpenclaWP_Tracer::set_runtime_context(
		array(
			'openclawp.session.id' => 'sess-bench',
			'openclawp.agent.slug' => 'bench-agent',
			'openclawp.user.id'    => '1',
			'openclawp.channel'    => 'chat-block',
		)
	);
	OpenclaWP_Tracer::on_turn_completed(
		array(
			'agent_slug'      => 'bench-agent',
			'session_id'      => 'sess-bench',
			'provider'        => 'anthropic',
			'model'           => 'claude-opus-4-7',
			'token_usage'     => array( 'input' => 1200, 'output' => 350 ),
			'duration_ms'     => 480,
			'success'         => true,
			'error'           => null,
			'tool_call_count' => 1,
		)
	);
	OpenclaWP_Tracer::on_loop_event( 'tool_call', array( 'turn' => 1, 'tool_name' => 'echo' ) );
	OpenclaWP_Tracer::on_loop_event( 'tool_result', array( 'turn' => 1, 'tool_name' => 'echo', 'success' => true ) );
	$samples[] = OpenclaWP_Tracer::overhead_us();
}
sort( $samples );
$n   = count( $samples );
$p50 = $samples[ (int) ( $n * 0.50 ) ];
$p95 = $samples[ (int) ( $n * 0.95 ) ];
$p99 = $samples[ (int) ( $n * 0.99 ) ];
$avg = array_sum( $samples ) / $n;
printf(
	"samples=%d  avg=%.1fus  p50=%dus  p95=%dus  p99=%dus  max=%dus\n",
	$n,
	$avg,
	$p50,
	$p95,
	$p99,
	end( $samples )
);
