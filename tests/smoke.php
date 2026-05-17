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
OpenclaWP_Smoke::check( 'list-tools ability registered', wp_has_ability( OpenclaWP_Tool_Discovery::LIST_ABILITY ) );
OpenclaWP_Smoke::check( 'execute-tool ability registered', wp_has_ability( OpenclaWP_Tool_Discovery::EXECUTE_ABILITY ) );

// list-tools should surface at least the bundled abilities (echo + chat),
// and must not surface the meta-tools themselves.
$catalog_listing = wp_get_ability( OpenclaWP_Tool_Discovery::LIST_ABILITY )->execute( array( 'category' => 'openclawp' ) );
$catalog_slugs   = is_array( $catalog_listing ) && isset( $catalog_listing['tools'] )
	? array_column( $catalog_listing['tools'], 'slug' )
	: array();
OpenclaWP_Smoke::check(
	'list-tools surfaces echo + chat in openclawp category',
	in_array( 'openclawp/echo', $catalog_slugs, true ) && in_array( 'openclawp/chat', $catalog_slugs, true )
);
OpenclaWP_Smoke::check(
	'list-tools hides meta-tools from its own catalog',
	! in_array( OpenclaWP_Tool_Discovery::LIST_ABILITY, $catalog_slugs, true )
		&& ! in_array( OpenclaWP_Tool_Discovery::EXECUTE_ABILITY, $catalog_slugs, true )
);

// execute-tool should dispatch to the target ability and return its result.
$exec_result = wp_get_ability( OpenclaWP_Tool_Discovery::EXECUTE_ABILITY )->execute(
	array(
		'tool' => 'openclawp/echo',
		'args' => array( 'text' => 'hola' ),
	)
);
OpenclaWP_Smoke::check(
	'execute-tool dispatches and returns result',
	is_array( $exec_result )
		&& 'openclawp/echo' === ( $exec_result['tool'] ?? '' )
		&& 'hola' === ( $exec_result['result']['echoed'] ?? '' )
);
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

// Catalog-mode toggle: when default_config.catalog_mode is true, the
// resolver should swap the full tool list for just the two meta-tools.
OpenclaWP_Smoke::register_agent(
	'openclawp-smoke-catalog',
	array(
		'label'          => 'Smoke catalog',
		'description'    => 'Smoke test for catalog mode.',
		'default_config' => array(
			'provider'     => 'auto',
			'model'        => 'auto',
			'catalog_mode' => true,
			'tools'        => array( 'openclawp/echo', 'openclawp/chat' ),
		),
	)
);
$catalog_agent = wp_get_agent( 'openclawp-smoke-catalog' );
OpenclaWP_Smoke::check( 'catalog agent registered', null !== $catalog_agent );
if ( $catalog_agent ) {
	$resolved     = OpenclaWP_Tools_Resolver::for_agent( $catalog_agent );
	$decl_names   = array_keys( $resolved['declarations'] );
	$list_name    = OpenclaWP_Tools_Resolver::sanitize_name( OpenclaWP_Tool_Discovery::LIST_ABILITY );
	$execute_name = OpenclaWP_Tools_Resolver::sanitize_name( OpenclaWP_Tool_Discovery::EXECUTE_ABILITY );
	OpenclaWP_Smoke::check(
		'catalog mode resolves to exactly the two meta-tools',
		2 === count( $decl_names )
			&& in_array( $list_name, $decl_names, true )
			&& in_array( $execute_name, $decl_names, true ),
		json_encode( $decl_names )
	);
}

// Toggle off: same agent without catalog_mode = full tool list (echo + chat).
OpenclaWP_Smoke::register_agent(
	'openclawp-smoke-no-catalog',
	array(
		'label'          => 'Smoke no catalog',
		'description'    => 'Catalog-mode-off baseline.',
		'default_config' => array(
			'provider' => 'auto',
			'model'    => 'auto',
			'tools'    => array( 'openclawp/echo', 'openclawp/chat' ),
		),
	)
);
$no_catalog_agent = wp_get_agent( 'openclawp-smoke-no-catalog' );
if ( $no_catalog_agent ) {
	$resolved   = OpenclaWP_Tools_Resolver::for_agent( $no_catalog_agent );
	$decl_names = array_keys( $resolved['declarations'] );
	$echo_name  = OpenclaWP_Tools_Resolver::sanitize_name( 'openclawp/echo' );
	$chat_name  = OpenclaWP_Tools_Resolver::sanitize_name( 'openclawp/chat' );
	OpenclaWP_Smoke::check(
		'catalog mode off keeps the legacy full tool list',
		2 === count( $decl_names )
			&& in_array( $echo_name, $decl_names, true )
			&& in_array( $chat_name, $decl_names, true ),
		json_encode( $decl_names )
	);
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

// Tool-call confirmation gate (#40) — CPT + REST surface present.
OpenclaWP_Smoke::check(
	'CPT openclawp_decision registered',
	null !== get_post_type_object( 'openclawp_decision' )
);

OpenclaWP_Smoke::check(
	'effect helper classifies read-prefixed abilities as read',
	'read' === OpenclaWP_Tool_Effects::for_ability( 'openclawp/get-recent-posts' )
);
OpenclaWP_Smoke::check(
	'effect helper classifies delete-prefixed abilities as destructive',
	'destructive' === OpenclaWP_Tool_Effects::for_ability( 'openclawp/delete-post' )
);
OpenclaWP_Smoke::check(
	'requires_confirmation default threshold gates destructive',
	OpenclaWP_Tool_Effects::requires_confirmation( 'destructive', OpenclaWP_Tool_Effects::DEFAULT_THRESHOLD )
);
OpenclaWP_Smoke::check(
	'requires_confirmation default threshold does not gate read',
	false === OpenclaWP_Tool_Effects::requires_confirmation( 'read', OpenclaWP_Tool_Effects::DEFAULT_THRESHOLD )
);

// Round-trip a pending decision through the store.
$pending = OpenclaWP_Decisions_Store::create_pending(
	array(
		'session_id' => 'smoke-session',
		'user_id'    => 1,
		'agent_slug' => 'smoke-agent',
		'ability'    => 'openclawp/delete-post',
		'effect'     => 'destructive',
		'threshold'  => 'destructive',
		'parameters' => array( 'id' => 42 ),
	)
);
OpenclaWP_Smoke::check( 'create_pending returns a decision id', is_array( $pending ) && '' !== ( $pending['decision_id'] ?? '' ) );

if ( is_array( $pending ) ) {
	$resolved = OpenclaWP_Decisions_Store::resolve( $pending['decision_id'], OpenclaWP_Decisions_Store::STATUS_ALLOWED, 1 );
	OpenclaWP_Smoke::check( 'resolve marks a pending decision as allowed', true === $resolved );

	$re_resolve = OpenclaWP_Decisions_Store::resolve( $pending['decision_id'], OpenclaWP_Decisions_Store::STATUS_DENIED, 1 );
	OpenclaWP_Smoke::check( 'resolving an already-resolved decision returns false', false === $re_resolve );

	// Always-allow round-trip.
	OpenclaWP_Tool_Effects::add_always_allow( 1, 'openclawp/delete-post' );
	OpenclaWP_Smoke::check(
		'add_always_allow persists in user_meta',
		OpenclaWP_Tool_Effects::user_allows_always( 1, 'openclawp/delete-post' )
	);
	OpenclaWP_Tool_Effects::remove_always_allow( 1, 'openclawp/delete-post' );
	OpenclaWP_Smoke::check(
		'remove_always_allow clears the entry',
		false === OpenclaWP_Tool_Effects::user_allows_always( 1, 'openclawp/delete-post' )
	);
}

// Custom tools: round-trip a tool through the store + registrar and verify
// the ability shows up and {{parameter}} substitution survives an injection
// attempt. Acceptance criteria for issue #43.
if ( class_exists( 'OpenclaWP_Custom_Tools_Store' ) ) {
	OpenclaWP_Smoke::check(
		'CPT openclawp_tool registered',
		null !== get_post_type_object( OpenclaWP_Custom_Tools_Store::POST_TYPE )
	);

	// Clean up any leftover smoke-test tool from a previous run.
	$existing = OpenclaWP_Custom_Tools_Store::find_by_slug( 'smoke-http-tool' );
	if ( null !== $existing ) {
		OpenclaWP_Custom_Tools_Store::delete( $existing->ID );
	}

	$created = OpenclaWP_Custom_Tools_Store::create(
		array(
			'label'       => 'Smoke HTTP tool',
			'slug'        => 'smoke-http-tool',
			'description' => 'Smoke test for HTTP tool authoring.',
			'spec'        => array(
				'type'         => OpenclaWP_Custom_Tools_Store::TYPE_HTTP,
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'msg' => array( 'type' => 'string' ),
					),
				),
				'http'         => array(
					'method'    => 'POST',
					'url'       => 'https://example.invalid/api',
					'headers'   => array(),
					'body_type' => 'json',
					'body'      => '{"text": "{{msg}}"}',
				),
				'auth'         => array( 'mode' => OpenclaWP_Custom_Tools_Store::AUTH_NONE ),
				'effect'       => OpenclaWP_Custom_Tools_Store::EFFECT_WRITE,
				'output'       => array( 'mode' => OpenclaWP_Custom_Tools_Store::OUTPUT_RAW ),
				'allowed_roles' => array( 'administrator' ),
			),
		)
	);
	OpenclaWP_Smoke::check( 'custom tool create returns int id', is_int( $created ) && $created > 0 );

	$post = get_post( (int) $created );
	OpenclaWP_Smoke::check( 'custom tool post exists', $post instanceof WP_Post );
	OpenclaWP_Smoke::check( 'custom tool revisions supported', post_type_supports( OpenclaWP_Custom_Tools_Store::POST_TYPE, 'revisions' ) );

	// Force registration on this request (the registrar normally fires on
	// `wp_abilities_api_init`, which has already fired by the time eval-file
	// executes this script).
	OpenclaWP_Custom_Tools_Registrar::register_one( $post );

	$ability_name = OpenclaWP_Custom_Tools_Registrar::ability_name_for_slug( $post->post_name );
	OpenclaWP_Smoke::check(
		'custom tool registered as an ability',
		wp_has_ability( $ability_name ),
		'expected ability ' . $ability_name
	);

	// Substitution: an injection-attempt input must not be able to forge a
	// second top-level JSON key on the outgoing body.
	$injected_body = OpenclaWP_Custom_Tools_Executor::build_json_body(
		'{"text": "{{msg}}"}',
		array( 'msg' => 'hi", "is_admin": true, "_": "' )
	);
	$decoded = is_string( $injected_body ) ? json_decode( $injected_body, true ) : null;
	OpenclaWP_Smoke::check(
		'JSON injection attempt does not forge sibling keys',
		is_array( $decoded ) && ! array_key_exists( 'is_admin', $decoded ) && 'hi", "is_admin": true, "_": "' === ( $decoded['text'] ?? null )
	);

	OpenclaWP_Custom_Tools_Store::delete( (int) $created );
}

// Knowledge base — schema, ability, indexer round-trip.
OpenclaWP_Knowledge_Base_Schema::maybe_install();

global $wpdb;
$kb_table = OpenclaWP_Knowledge_Base_Schema::table_name();
$kb_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $kb_table ) );
OpenclaWP_Smoke::check( 'KB table installed', $kb_exists, $kb_table );
OpenclaWP_Smoke::check(
	'knowledge-base/search ability registered',
	wp_has_ability( 'knowledge-base/search' )
);

if ( $kb_exists ) {
	// Pin the smoke post type so on_save_post() takes the indexing branch.
	OpenclaWP_Knowledge_Base_Sources::save(
		array(
			'post_types' => array( 'post' ),
			'urls'       => array(),
		)
	);

	$fixture_id = wp_insert_post(
		array(
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_title'   => 'Smoke pineapple policy',
			'post_content' => "We sell pineapple-flavoured ice cream year-round.\n\nReturns are accepted within 30 days for any reason.",
		),
		true
	);
	OpenclaWP_Smoke::check( 'KB fixture post inserted', ! is_wp_error( $fixture_id ) && (int) $fixture_id > 0 );

	if ( ! is_wp_error( $fixture_id ) && (int) $fixture_id > 0 ) {
		$chunks = OpenclaWP_Knowledge_Base_Indexer::index_post( (int) $fixture_id );
		OpenclaWP_Smoke::check( 'KB indexer wrote at least one chunk', $chunks >= 1, 'chunks=' . $chunks );

		$results = OpenclaWP_Knowledge_Base_Search::search( 'pineapple', 5 );
		OpenclaWP_Smoke::check( 'KB search returns the fixture post', ! empty( $results ) );
		if ( ! empty( $results ) ) {
			$top = $results[0];
			OpenclaWP_Smoke::check(
				'KB top result cites the fixture by permalink',
				is_string( $top['permalink'] ) && '' !== $top['permalink']
			);
			OpenclaWP_Smoke::check(
				'KB top result excerpt mentions the query term',
				false !== stripos( (string) $top['excerpt'], 'pineapple' )
			);
		}

		wp_delete_post( (int) $fixture_id, true );
		OpenclaWP_Knowledge_Base_Indexer::delete_post_chunks( (int) $fixture_id );
	}
}

// -----------------------------------------------------------------------
// OAuth 2.1 + DCR + scopes — issue #45.
//
// Full flow integration test using wp_remote_request against the live REST
// API: discovery -> DCR -> /authorize (skipped — interactive only; we mint
// a code directly via the store) -> /token (authorization_code + PKCE) ->
// /introspect -> MCP tools/call with the issued token, asserting allowed /
// denied per scope.
// -----------------------------------------------------------------------
if ( class_exists( 'OpenclaWP_Oauth_Store' ) && class_exists( 'OpenclaWP_Mcp_Server_Store' ) ) {
	$base = rest_url( 'openclawp/v1' );

	// Make sure the OAuth post types are registered (init fires before
	// eval-file in studio, but defensive).
	OpenclaWP_Oauth_Store::register_post_types();
	OpenclaWP_Mcp_Server_Store::register_post_type();

	// 1) Discovery doc.
	$discovery_resp = wp_remote_get( $base . '/.well-known/oauth-authorization-server' );
	OpenclaWP_Smoke::check(
		'discovery doc 200',
		! is_wp_error( $discovery_resp ) && 200 === (int) wp_remote_retrieve_response_code( $discovery_resp )
	);
	$discovery = is_wp_error( $discovery_resp ) ? null : json_decode( (string) wp_remote_retrieve_body( $discovery_resp ), true );
	OpenclaWP_Smoke::check(
		'discovery advertises authorization_code + PKCE',
		is_array( $discovery )
			&& in_array( 'authorization_code', (array) ( $discovery['grant_types_supported'] ?? array() ), true )
			&& in_array( 'S256', (array) ( $discovery['code_challenge_methods_supported'] ?? array() ), true )
	);

	// 2) Make sure an MCP server exists. Reuse or create one bound to the smoke agent.
	$mcp_slug   = 'smoke-oauth';
	$mcp_server = OpenclaWP_Mcp_Server_Store::find_by_slug( $mcp_slug );
	if ( null === $mcp_server ) {
		// Need an agent whose tools span effects. Register an inline agent with read+write+destructive abilities.
		OpenclaWP_Smoke::register_ability(
			'tests/read-thing',
			array(
				'label'               => 'Smoke read',
				'description'         => 'returns a thing',
				'category'            => 'agents-api',
				'input_schema'        => array( 'type' => 'object' ),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static fn (): array => array( 'ok' => true ),
				'permission_callback' => '__return_true',
			)
		);
		OpenclaWP_Smoke::register_ability(
			'tests/update-thing',
			array(
				'label'               => 'Smoke update',
				'description'         => 'updates a thing',
				'category'            => 'agents-api',
				'input_schema'        => array( 'type' => 'object' ),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static fn (): array => array( 'updated' => true ),
				'permission_callback' => '__return_true',
			)
		);
		OpenclaWP_Smoke::register_agent(
			'openclawp-smoke-oauth',
			array(
				'label'          => 'Smoke OAuth',
				'description'    => 'Agent with mixed-effect abilities for the OAuth scope test.',
				'default_config' => array(
					'provider' => 'auto',
					'model'    => 'auto',
					'tools'    => array( 'tests/read-thing', 'tests/update-thing' ),
				),
			)
		);
		$created    = OpenclaWP_Mcp_Server_Store::create( 'Smoke OAuth server', $mcp_slug, 'openclawp-smoke-oauth' );
		$mcp_server = is_wp_error( $created ) ? null : OpenclaWP_Mcp_Server_Store::find_by_slug( $mcp_slug );
	}
	OpenclaWP_Smoke::check( 'mcp server exists for OAuth flow', null !== $mcp_server );

	// 3) DCR — self-register a public client (PKCE, no secret).
	$dcr_resp = wp_remote_post(
		$base . '/oauth/register',
		array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode(
				array(
					'client_name'                => 'smoke DCR client',
					'redirect_uris'              => array( 'https://example.test/cb' ),
					'scope'                      => 'mcp:read mcp:write',
					'token_endpoint_auth_method' => 'none',
					'mcp_server_slug'            => $mcp_slug,
				)
			),
		)
	);
	$dcr_body = is_wp_error( $dcr_resp ) ? array() : (array) json_decode( (string) wp_remote_retrieve_body( $dcr_resp ), true );
	$client_id = (string) ( $dcr_body['client_id'] ?? '' );
	OpenclaWP_Smoke::check(
		'DCR returns 201 + client_id',
		! is_wp_error( $dcr_resp )
			&& 201 === (int) wp_remote_retrieve_response_code( $dcr_resp )
			&& '' !== $client_id
	);
	OpenclaWP_Smoke::check(
		'DCR public client has no secret',
		! isset( $dcr_body['client_secret'] )
	);

	// Helper to run an end-to-end token-issuing flow for a given scope.
	$mint_token = static function ( string $client_id, string $scope_string, string $mcp_slug ) {
		// PKCE pair.
		$verifier  = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );

		// Mint the authorization code directly via the store (skips the
		// interactive consent screen; the screen + redirect is covered manually).
		$scopes = OpenclaWP_Oauth_Scope::parse_scope_string( $scope_string );
		$code   = OpenclaWP_Oauth_Store::issue_authorization_code(
			$client_id,
			get_current_user_id(),
			'https://example.test/cb',
			$scopes,
			$mcp_slug,
			$challenge,
			'S256'
		);

		$resp = wp_remote_post(
			rest_url( 'openclawp/v1/oauth/token' ),
			array(
				'body' => array(
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'redirect_uri'  => 'https://example.test/cb',
					'client_id'     => $client_id,
					'code_verifier' => $verifier,
				),
			)
		);
		$body = is_wp_error( $resp ) ? array() : (array) json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		return array(
			'status' => is_wp_error( $resp ) ? 0 : (int) wp_remote_retrieve_response_code( $resp ),
			'body'   => $body,
		);
	};

	// 4) Exchange for an `mcp:read`-only access token.
	$read_exchange = $mint_token( $client_id, 'mcp:read', $mcp_slug );
	OpenclaWP_Smoke::check(
		'token exchange returns 200 + access_token (read scope)',
		200 === $read_exchange['status'] && ! empty( $read_exchange['body']['access_token'] )
	);
	$read_token = (string) ( $read_exchange['body']['access_token'] ?? '' );
	OpenclaWP_Smoke::check(
		'token exchange returns scope=mcp:read',
		'mcp:read' === (string) ( $read_exchange['body']['scope'] ?? '' )
	);

	// 5) Introspection — requires no client auth here because we registered as `none`.
	$introspect = wp_remote_post(
		$base . '/oauth/introspect',
		array(
			'body' => array(
				'token'     => $read_token,
				'client_id' => $client_id,
			),
		)
	);
	$introspect_body = is_wp_error( $introspect ) ? array() : (array) json_decode( (string) wp_remote_retrieve_body( $introspect ), true );
	OpenclaWP_Smoke::check(
		'introspect returns active=true + scope',
		! is_wp_error( $introspect )
			&& true === ( $introspect_body['active'] ?? false )
			&& 'mcp:read' === (string) ( $introspect_body['scope'] ?? '' )
	);

	// 6) Call MCP tools/list — `tests/read-thing` should appear, `tests/update-thing` should NOT.
	$jsonrpc = static function ( string $token, array $payload ) use ( $mcp_slug ) {
		return wp_remote_post(
			rest_url( 'openclawp/v1/mcp/' . $mcp_slug ),
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);
	};

	$tools_resp = $jsonrpc( $read_token, array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => array() ) );
	$tools_body = is_wp_error( $tools_resp ) ? array() : (array) json_decode( (string) wp_remote_retrieve_body( $tools_resp ), true );
	$tool_names = array_map(
		static fn ( $t ): string => (string) ( $t['name'] ?? '' ),
		(array) ( $tools_body['result']['tools'] ?? array() )
	);
	OpenclaWP_Smoke::check(
		'tools/list scoped to read shows the read ability',
		in_array( 'tests__read-thing', $tool_names, true )
	);
	OpenclaWP_Smoke::check(
		'tools/list scoped to read hides the write ability',
		! in_array( 'tests__update-thing', $tool_names, true )
	);

	// 7) Try to call the write tool with read scope — must be denied with insufficient_scope text.
	$call_write_resp = $jsonrpc(
		$read_token,
		array(
			'jsonrpc' => '2.0',
			'id'      => 2,
			'method'  => 'tools/call',
			'params'  => array(
				'name'      => 'tests__update-thing',
				'arguments' => array(),
			),
		)
	);
	$call_write_body = is_wp_error( $call_write_resp ) ? array() : (array) json_decode( (string) wp_remote_retrieve_body( $call_write_resp ), true );
	$write_result    = (array) ( $call_write_body['result'] ?? array() );
	$write_text      = (string) ( $write_result['content'][0]['text'] ?? '' );
	OpenclaWP_Smoke::check(
		'tools/call write denied for mcp:read token',
		true === ( $write_result['isError'] ?? false ) && false !== strpos( $write_text, 'insufficient_scope' )
	);

	// 8) Call the read tool — must be allowed.
	$call_read_resp = $jsonrpc(
		$read_token,
		array(
			'jsonrpc' => '2.0',
			'id'      => 3,
			'method'  => 'tools/call',
			'params'  => array(
				'name'      => 'tests__read-thing',
				'arguments' => array(),
			),
		)
	);
	$call_read_body = is_wp_error( $call_read_resp ) ? array() : (array) json_decode( (string) wp_remote_retrieve_body( $call_read_resp ), true );
	$read_result    = (array) ( $call_read_body['result'] ?? array() );
	OpenclaWP_Smoke::check(
		'tools/call read allowed for mcp:read token',
		isset( $read_result['isError'] ) && false === $read_result['isError']
	);

	// 9) Mint a fresh token with mcp:write and confirm it can call the write tool.
	$write_exchange = $mint_token( $client_id, 'mcp:write', $mcp_slug );
	OpenclaWP_Smoke::check(
		'token exchange for mcp:write succeeds',
		200 === $write_exchange['status'] && ! empty( $write_exchange['body']['access_token'] )
	);
	$write_token = (string) ( $write_exchange['body']['access_token'] ?? '' );

	$call_write2 = $jsonrpc(
		$write_token,
		array(
			'jsonrpc' => '2.0',
			'id'      => 4,
			'method'  => 'tools/call',
			'params'  => array(
				'name'      => 'tests__update-thing',
				'arguments' => array(),
			),
		)
	);
	$call_write2_body = is_wp_error( $call_write2 ) ? array() : (array) json_decode( (string) wp_remote_retrieve_body( $call_write2 ), true );
	$write2_result    = (array) ( $call_write2_body['result'] ?? array() );
	OpenclaWP_Smoke::check(
		'tools/call write allowed for mcp:write token',
		isset( $write2_result['isError'] ) && false === $write2_result['isError']
	);

	// 10) Revoke the write token and confirm the next call is rejected (no perm).
	$revoke = wp_remote_post(
		$base . '/oauth/revoke',
		array( 'body' => array( 'token' => $write_token ) )
	);
	OpenclaWP_Smoke::check(
		'revoke endpoint returns 200',
		! is_wp_error( $revoke ) && 200 === (int) wp_remote_retrieve_response_code( $revoke )
	);
	$after_revoke = $jsonrpc(
		$write_token,
		array( 'jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/list', 'params' => array() )
	);
	OpenclaWP_Smoke::check(
		'tools/list rejects revoked token',
		! is_wp_error( $after_revoke ) && 401 === (int) wp_remote_retrieve_response_code( $after_revoke )
	);
}

$failed = OpenclaWP_Smoke::summarize();
if ( $failed > 0 ) {
	exit( 1 );
}
