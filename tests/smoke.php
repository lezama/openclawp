<?php
/**
 * Integration smoke test.
 *
 * Run from a real WordPress with openclaWP active and at least one
 * configured WP AI Client connector:
 *
 *     studio wp --path /path/to/site eval-file tests/smoke.php
 *
 * Exits non-zero on the first assertion failure. Prints a summary table.
 *
 * @package OpenclaWP\Tests
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tiny harness that lives in a static so eval-file's wrapper doesn't lose it.
 */
final class OpenclaWP_Smoke {

	/** @var array<int, array{name:string,ok:bool,detail:string}> */
	public static array $results = array();

	public static function check( string $name, bool $ok, string $detail = '' ): void {
		self::$results[] = array( 'name' => $name, 'ok' => $ok, 'detail' => $detail );
	}

	public static function summarize(): int {
		$pass = 0;
		$fail = 0;
		foreach ( self::$results as $r ) {
			$mark = $r['ok'] ? '+' : 'X';
			echo $mark . ' ' . $r['name'];
			if ( ! $r['ok'] && '' !== $r['detail'] ) {
				echo '  (' . $r['detail'] . ')';
			}
			echo PHP_EOL;
			$r['ok'] ? $pass++ : $fail++;
		}
		echo PHP_EOL;
		echo "passed: {$pass}    failed: {$fail}" . PHP_EOL;
		return $fail;
	}
}

wp_set_current_user( 1 );

OpenclaWP_Smoke::check( 'OPENCLAWP_LOADED defined', defined( 'OPENCLAWP_LOADED' ) );
OpenclaWP_Smoke::check( 'AGENTS_API_LOADED defined', defined( 'AGENTS_API_LOADED' ) );

$has_example = wp_has_agent( 'openclawp-example' );
OpenclaWP_Smoke::check(
	'example agent registered',
	$has_example,
	$has_example ? '' : 'enable openclawp_register_example_agent filter for this assertion'
);

do_action( 'wp_abilities_api_categories_init' );
do_action( 'wp_abilities_api_init' );
OpenclaWP_Smoke::check( 'echo ability registered', wp_has_ability( 'openclawp/echo' ) );
OpenclaWP_Smoke::check( 'chat ability registered', wp_has_ability( 'openclawp/chat' ) );

$cpt = get_post_type_object( 'openclawp_session' );
OpenclaWP_Smoke::check( 'CPT openclawp_session registered', null !== $cpt );
OpenclaWP_Smoke::check(
	'CPT openclawp_session exposed via REST',
	$cpt && true === $cpt->show_in_rest && 'openclawp-sessions' === $cpt->rest_base
);

$store      = OpenclaWP_Conversation_Store::instance();
$workspace  = new \AgentsAPI\Core\Workspace\WP_Agent_Workspace_Scope( 'site', '1' );
$session_id = $store->create_session( $workspace, 1, 0, array(), 'chat' );
OpenclaWP_Smoke::check( 'create_session returns UUID', '' !== $session_id );

$t1 = $store->acquire_session_lock( $session_id, 60 );
OpenclaWP_Smoke::check( 'lock acquire fresh', null !== $t1 );

$t2 = $store->acquire_session_lock( $session_id, 60 );
OpenclaWP_Smoke::check( 'lock contention while held', null === $t2 );

$bad_release = $store->release_session_lock( $session_id, 'wrong-token' );
OpenclaWP_Smoke::check( 'release with wrong token returns false', false === $bad_release );

$ok_release = $store->release_session_lock( $session_id, $t1 );
OpenclaWP_Smoke::check( 'release with right token returns true', true === $ok_release );

$store->delete_session( $session_id );

// Resolver maps default_config to the shape using_model_preference accepts.
add_action(
	'wp_agents_api_init',
	static function () {
		wp_register_agent(
			'openclawp-smoke-pin',
			array(
				'label'          => 'Smoke pin',
				'description'    => 'Smoke test for model pinning.',
				'default_config' => array(
					'provider' => 'ollama',
					'model'    => 'llama3.1:8b',
				),
			)
		);
		wp_register_agent(
			'openclawp-smoke-auto',
			array(
				'label'          => 'Smoke auto',
				'description'    => 'Smoke test for auto fallback.',
				'default_config' => array( 'provider' => 'auto', 'model' => 'auto' ),
			)
		);
	},
	60
);
do_action( 'wp_agents_api_init' );

$rc       = new ReflectionClass( 'OpenclaWP_Runner' );
$resolver = $rc->getMethod( 'resolve_model_preference' );
$resolver->setAccessible( true );

$pin_agent  = wp_get_agent( 'openclawp-smoke-pin' );
$auto_agent = wp_get_agent( 'openclawp-smoke-auto' );

OpenclaWP_Smoke::check( 'pinned agent registered', null !== $pin_agent );
OpenclaWP_Smoke::check( 'auto agent registered', null !== $auto_agent );

if ( $pin_agent ) {
	$pref = $resolver->invoke( null, $pin_agent );
	OpenclaWP_Smoke::check(
		'pinned config resolves to provider+model tuple',
		array( 'ollama', 'llama3.1:8b' ) === $pref,
		is_array( $pref ) ? json_encode( $pref ) : (string) $pref
	);
}

if ( $auto_agent ) {
	$pref = $resolver->invoke( null, $auto_agent );
	OpenclaWP_Smoke::check( 'auto config resolves to null preference', null === $pref );
}

$failed = OpenclaWP_Smoke::summarize();
if ( $failed > 0 ) {
	exit( 1 );
}
