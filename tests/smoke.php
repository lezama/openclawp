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

	public static function register_agent( string $slug, array $args ): void {
		if ( function_exists( 'wp_has_agent' ) && wp_has_agent( $slug ) ) {
			return;
		}
		if ( ! class_exists( 'WP_Agents_Registry' ) ) {
			return;
		}

		if ( method_exists( 'WP_Agents_Registry', 'init' ) ) {
			WP_Agents_Registry::init();
		}

		$registry = WP_Agents_Registry::get_instance();
		if ( null !== $registry ) {
			$registry->register( $slug, $args );
		}
	}

	public static function register_ability( string $name, array $args ): void {
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) {
			return;
		}
		if ( ! class_exists( 'WP_Abilities_Registry' ) ) {
			return;
		}

		$registry = WP_Abilities_Registry::get_instance();
		if ( null !== $registry ) {
			$registry->register( $name, $args );
		}
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
OpenclaWP_Smoke::check( 'echo ability registered', wp_has_ability( 'openclawp/echo' ) );
OpenclaWP_Smoke::check( 'chat ability registered', wp_has_ability( 'openclawp/chat' ) );

// MCP-adapter migration: assert the shim loads, the legacy gate defaults to
// off, and adapter detection answers without throwing. Hosts on WP 7.0 will
// see `adapter_available` flip to true automatically.
OpenclaWP_Smoke::check(
	'mcp-adapter shim class autoloaded',
	class_exists( 'OpenclaWP_Mcp_Adapter' )
);
OpenclaWP_Smoke::check(
	'legacy MCP JSON-RPC is gated (off by default)',
	false === OpenclaWP_Bootstrap::legacy_mcp_enabled()
);
OpenclaWP_Smoke::check(
	'mcp-adapter availability check returns bool',
	is_bool( OpenclaWP_Mcp_Adapter::adapter_available() )
);

$cpt = get_post_type_object( 'openclawp_session' );
OpenclaWP_Smoke::check( 'CPT openclawp_session registered', null !== $cpt );
OpenclaWP_Smoke::check(
	'CPT openclawp_session exposed via REST',
	$cpt && true === $cpt->show_in_rest && 'openclawp-sessions' === $cpt->rest_base
);

$doctor_checks          = OpenclaWP_CLI::collect_checks();
$doctor_critical_failed = array_values(
	array_filter(
		$doctor_checks,
		static fn ( array $check ): bool => 'fail' === $check['status'] && $check['critical']
	)
);
OpenclaWP_Smoke::check(
	'doctor critical checks pass',
	empty( $doctor_critical_failed ),
	empty( $doctor_critical_failed ) ? '' : json_encode( $doctor_critical_failed )
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
OpenclaWP_Smoke::register_agent(
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
OpenclaWP_Smoke::register_agent(
	'openclawp-smoke-auto',
	array(
		'label'          => 'Smoke auto',
		'description'    => 'Smoke test for auto fallback.',
		'default_config' => array( 'provider' => 'auto', 'model' => 'auto' ),
	)
);

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

OpenclaWP_Smoke::register_agent(
	'openclawp-smoke-preflight',
	array(
		'label'          => 'Smoke preflight',
		'description'    => 'Smoke test for deterministic pre-turn handling.',
		'default_config' => array( 'provider' => 'auto', 'model' => 'auto' ),
	)
);

$preflight_filter = static function ( $preflight, array $turn ) {
	if ( 'openclawp-smoke-preflight' !== ( $turn['agent_slug'] ?? '' ) ) {
		return $preflight;
	}

	return array(
		'reply'     => 'preflight-ok:' . ( $turn['runtime_context']['client_context']['sender_id'] ?? '' ),
		'completed' => true,
	);
};
add_filter( 'openclawp_pre_chat_turn', $preflight_filter, 10, 2 );
$preflight_run = OpenclaWP_Runner::run_turn(
	'openclawp-smoke-preflight',
	'JOINME',
	null,
	1,
	array( 'client_context' => array( 'sender_id' => '15555550100' ) )
);
remove_filter( 'openclawp_pre_chat_turn', $preflight_filter, 10 );

OpenclaWP_Smoke::check(
	'pre-chat turn filter can short-circuit model calls',
	'preflight-ok:15555550100' === ( $preflight_run['reply'] ?? '' ),
	is_array( $preflight_run ) ? json_encode( $preflight_run ) : 'not-an-array'
);
OpenclaWP_Smoke::check(
	'pre-chat turn filter records transcript messages',
	isset( $preflight_run['messages'] ) && 2 === count( $preflight_run['messages'] )
);

// Workflow primitives — substrate dispatcher + openclaWP runtime adapter.
if ( class_exists( 'AgentsAPI\\AI\\Workflows\\WP_Agent_Workflow_Runner' ) ) {
	OpenclaWP_Smoke::check(
		'agents/run-workflow ability registered',
		wp_has_ability( 'agents/run-workflow' )
	);
	OpenclaWP_Smoke::check(
		'agents/validate-workflow ability registered',
		wp_has_ability( 'agents/validate-workflow' )
	);
	OpenclaWP_Smoke::check(
		'CPT openclawp_workflow registered',
		null !== get_post_type_object( 'openclawp_workflow' )
	);
	OpenclaWP_Smoke::check(
		'CPT openclawp_wf_run registered',
		null !== get_post_type_object( 'openclawp_wf_run' )
	);

	add_filter( 'openclawp_register_example_workflow', '__return_true' );
	add_filter( 'openclawp_register_example_agent', '__return_true' );
	OpenclaWP_Workflow_Bootstrap::maybe_register_example_workflow();

	OpenclaWP_Smoke::check(
		'example workflow registers when opted in',
		null !== wp_get_workflow( 'openclawp/site-summary' )
	);

	// validate-workflow is pure substrate — exercise it without a runner.
	$validate = wp_get_ability( 'agents/validate-workflow' )->execute(
		array(
			'spec' => array(
				'id'    => 'tests/no-id-on-step',
				'steps' => array( array( 'type' => 'ability' ) ),
			),
		)
	);
	OpenclaWP_Smoke::check(
		'validate-workflow returns errors for malformed spec',
		isset( $validate['valid'] ) && false === $validate['valid'] && ! empty( $validate['errors'] )
	);

	// run-workflow with an inline spec that uses ONLY the substrate's default
	// ability handler — no agent / Anthropic / Ollama needed. Registers a
	// throwaway ability that echoes its input. wp_register_ability bails
	// unless `doing_action('wp_abilities_api_init')` is true; use the registry
	// directly so this smoke test does not re-fire every plugin's registration hook.
	if ( ! wp_has_ability( 'tests/echo-input' ) ) {
		OpenclaWP_Smoke::register_ability(
			'tests/echo-input',
			array(
				'label'               => 'Smoke echo',
				'description'         => 'Returns its input under `echo` for the workflow smoke.',
				'category'            => 'agents-api',
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
				'execute_callback'    => static function ( $input ) {
					return array( 'echo' => $input );
				},
				'permission_callback' => '__return_true',
			)
		);
	}

	$run = wp_get_ability( 'agents/run-workflow' )->execute(
		array(
			'spec'   => array(
				'id'    => 'tests/run-now',
				'steps' => array(
					array(
						'id'      => 'echo',
						'type'    => 'ability',
						'ability' => 'tests/echo-input',
						'args'    => array( 'value' => '${inputs.text}' ),
					),
				),
			),
			'inputs' => array( 'text' => 'hola' ),
		)
	);

	OpenclaWP_Smoke::check(
		'run-workflow inline spec succeeds',
		is_array( $run ) && ( $run['status'] ?? '' ) === 'succeeded',
		is_array( $run ) ? json_encode( $run['error'] ?? null ) : 'not-an-array'
	);
	OpenclaWP_Smoke::check(
		'echo step output recorded',
		isset( $run['output']['steps']['echo']['echo']['value'] ) && 'hola' === $run['output']['steps']['echo']['echo']['value']
	);
	OpenclaWP_Smoke::check(
		'run row persisted in openclawp_wf_run CPT',
		isset( $run['run_id'] ) && null !== get_posts(
			array(
				'post_type'      => 'openclawp_wf_run',
				'meta_key'       => '_openclawp_run_id',
				'meta_value'     => $run['run_id'],
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		)[0] ?? null
	);
}

// WhatsApp adapter unit-style checks (don't depend on a configured Meta app).
if ( class_exists( 'OpenclaWP_Whatsapp' ) ) {
	OpenclaWP_Smoke::check(
		'verify_signature accepts a correctly signed body',
		OpenclaWP_Whatsapp::verify_signature(
			'{"object":"whatsapp_business_account"}',
			'sha256=' . hash_hmac( 'sha256', '{"object":"whatsapp_business_account"}', 'test-secret' ),
			'test-secret'
		)
	);

	OpenclaWP_Smoke::check(
		'verify_signature rejects a tampered body',
		false === OpenclaWP_Whatsapp::verify_signature(
			'{"object":"whatsapp_business_account","tampered":true}',
			'sha256=' . hash_hmac( 'sha256', '{"object":"whatsapp_business_account"}', 'test-secret' ),
			'test-secret'
		)
	);

	OpenclaWP_Smoke::check(
		'verify_signature rejects when secret is empty',
		false === OpenclaWP_Whatsapp::verify_signature( '{}', 'sha256=anything', '' )
	);

	$payload = array(
		'object' => 'whatsapp_business_account',
		'entry'  => array(
			array(
				'id'      => '123',
				'changes' => array(
					array(
						'field' => 'messages',
						'value' => array(
							'messages' => array(
								array(
									'from' => '15555550100',
									'id'   => 'wamid.HBg=',
									'type' => 'text',
									'text' => array( 'body' => 'hola' ),
								),
								// status events / non-text messages should be skipped:
								array( 'from' => '15555550100', 'type' => 'image', 'id' => 'wamid.IMG=' ),
							),
						),
					),
				),
			),
		),
	);
	$messages = OpenclaWP_Whatsapp::extract_messages( $payload );
	OpenclaWP_Smoke::check( 'extract_messages pulls one text message', 1 === count( $messages ) );
	OpenclaWP_Smoke::check( 'extracted phone is correct', '15555550100' === ( $messages[0]['phone'] ?? '' ) );
	OpenclaWP_Smoke::check( 'extracted text is correct', 'hola' === ( $messages[0]['text'] ?? '' ) );
}

$failed = OpenclaWP_Smoke::summarize();
if ( $failed > 0 ) {
	exit( 1 );
}
