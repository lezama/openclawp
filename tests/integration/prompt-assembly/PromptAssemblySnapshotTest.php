<?php
/**
 * Golden-trace snapshot tests for the assembled provider payload.
 *
 * For each canonical (agent, channel) pair we freeze a user message, build
 * the payload openclaWP would send to the provider, and diff it against
 * a JSON snapshot under `__snapshots__/`. Any change to the system prompt,
 * tool descriptions, parameter shape, or default config will surface as a
 * failing test on the next CI run.
 *
 * Update flow (intentional changes):
 *
 *     UPDATE_SNAPSHOTS=1 composer test:assembly
 *
 * The regenerated snapshot is then reviewed in the PR diff.
 *
 * @package OpenclaWP\Tests\Integration\PromptAssembly
 */

declare( strict_types=1 );

namespace OpenclaWP\Tests\Integration\PromptAssembly;

require_once __DIR__ . '/bootstrap.php';

use OpenclaWP_Agent_Registrar;
use PHPUnit\Framework\TestCase;
use WP_Agent;

/**
 * @covers \OpenclaWP_Tools_Resolver
 * @covers \OpenclaWP_Agent_Registrar
 */
final class PromptAssemblySnapshotTest extends TestCase {

	/**
	 * Pre-populated registries reset before each test so the registrar's
	 * idempotency guards (`wp_has_agent`) re-run cleanly. Opt-in filters that
	 * normally gate the demo agents are flipped on here — that way the
	 * snapshot exercises the production registrar's real registration args
	 * (descriptions, default_config, subagents) instead of a duplicated
	 * copy.
	 */
	protected function setUp(): void {
		$GLOBALS['openclawp_test_ability_registry'] = array();
		$GLOBALS['openclawp_test_agent_registry']   = array();

		$true_filter = static fn( $value ) => true;
		$GLOBALS['openclawp_test_filters'] = array(
			'openclawp_register_loop_demo'           => $true_filter,
			'openclawp_register_site_introspection'  => $true_filter,
			'openclawp_register_workflow_drafter'    => $true_filter,
			'openclawp_register_coordinator_demo'    => $true_filter,
			'openclawp_register_example_agent'       => $true_filter,
		);

		$this->register_canonical_abilities();

		// Run the production registrar so the snapshot reflects the *real*
		// agent args. Subagent-coordinator registers last; the registrar's
		// own ordering (priority 20) is preserved here by call order.
		OpenclaWP_Agent_Registrar::maybe_register_loop_demo_agent();
		OpenclaWP_Agent_Registrar::maybe_register_site_introspection_agent();
		OpenclaWP_Agent_Registrar::maybe_register_workflow_drafter_agent();
		OpenclaWP_Agent_Registrar::maybe_register_example_agent();
		OpenclaWP_Agent_Registrar::maybe_register_coordinator_demo_agent();
	}

	/**
	 * @dataProvider canonical_pairs
	 */
	public function test_payload_matches_snapshot( string $snapshot_name, string $agent_slug, string $channel, string $user_message, array $prior_messages ): void {
		$agent = wp_get_agent( $agent_slug );
		$this->assertInstanceOf( WP_Agent::class, $agent, sprintf( 'Fixture agent "%s" must be registered before assembly.', $agent_slug ) );

		$payload = PromptPayloadAssembler::assemble( $agent, $channel, $user_message, $prior_messages );
		$actual  = $this->encode_snapshot( $payload );

		$snapshot_path = __DIR__ . '/__snapshots__/' . $snapshot_name . '.json';

		if ( '1' === getenv( 'UPDATE_SNAPSHOTS' ) ) {
			if ( ! is_dir( dirname( $snapshot_path ) ) ) {
				mkdir( dirname( $snapshot_path ), 0o755, true );
			}
			file_put_contents( $snapshot_path, $actual );
			$this->assertTrue( true, sprintf( 'Updated snapshot %s', $snapshot_name ) );
			return;
		}

		$this->assertFileExists(
			$snapshot_path,
			sprintf(
				"Snapshot file is missing for %s. Generate it with:\n  UPDATE_SNAPSHOTS=1 composer test:assembly\n",
				$snapshot_name
			)
		);

		$expected = (string) file_get_contents( $snapshot_path );
		$this->assertSame(
			$expected,
			$actual,
			sprintf(
				"Payload for %s drifted from snapshot.\nIf the change is intentional, regenerate with:\n  UPDATE_SNAPSHOTS=1 composer test:assembly\n",
				$snapshot_name
			)
		);
	}

	/**
	 * @return array<string, array{0:string,1:string,2:string,3:string,4:array}>
	 */
	public function canonical_pairs(): array {
		return array(
			'loop-demo-chat'             => array(
				'loop-demo--chat',
				'openclawp-loop-demo',
				'chat',
				'What time is it?',
				array(),
			),
			'site-introspection-whatsapp' => array(
				'site-introspection--whatsapp',
				'openclawp-site-introspection',
				'whatsapp',
				'How many comments are awaiting moderation?',
				array(),
			),
			'coordinator-chat'           => array(
				'coordinator--chat',
				'openclawp-coordinator',
				'chat',
				'Summarise the last three posts published on this site.',
				array(),
			),
			'workflow-drafter-chat'      => array(
				'workflow-drafter--chat',
				'openclawp-workflow-drafter',
				'chat',
				'When a new comment is posted, classify it for spam and notify me if it is spam.',
				array(),
			),
			'example-whatsapp'           => array(
				'example--whatsapp',
				'openclawp-example',
				'whatsapp',
				'Hello',
				array(),
			),
		);
	}

	/**
	 * Pretty-printed, deterministic JSON. Sorted keys + UTF-8 unescaped + a
	 * trailing newline so editors don't reflow the file on save.
	 */
	private function encode_snapshot( array $payload ): string {
		$normalized = $this->sort_keys_recursive( $payload );
		$json       = json_encode(
			$normalized,
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
		);
		// json_encode pretty-prints with 4-space indent; collapse to tabs to
		// match the repo's PHP/JS style guide and keep diffs compact.
		$json = preg_replace_callback(
			'/^( {4,})/m',
			static fn( array $m ): string => str_repeat( "\t", (int) ( strlen( $m[1] ) / 4 ) ),
			$json
		);
		return ( $json ?? '' ) . "\n";
	}

	/**
	 * Recursively sort associative keys (leaves list arrays in their natural
	 * order, since order is meaningful for messages + tool catalogs).
	 *
	 * @param mixed $value Value to normalize.
	 * @return mixed
	 */
	private function sort_keys_recursive( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$is_list = array_is_list( $value );
		$out     = array();
		foreach ( $value as $k => $v ) {
			$out[ $k ] = $this->sort_keys_recursive( $v );
		}
		if ( ! $is_list ) {
			ksort( $out );
		}
		return $out;
	}

	/**
	 * Register the abilities referenced by the canonical demo agents. These
	 * mirror the real registrations in `OpenclaWP_Abilities` and
	 * `OpenclaWP_Site_Abilities` — descriptions + schemas only, no callbacks.
	 */
	private function register_canonical_abilities(): void {
		$abilities = array(
			'openclawp/get-time' => array(
				'description'  => 'Returns the current server time in ISO 8601 (UTC). Call this whenever the user asks for the time, the current date, or how long ago something happened.',
				'input_schema' => array( 'type' => 'object' ),
			),
			'openclawp/get-recent-posts' => array(
				'description'  => 'Returns up to 5 most recent published posts (title + permalink + excerpt).',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 10 ),
					),
				),
			),
			'openclawp/count-comments' => array(
				'description'  => 'Returns counts of comments by status (approved, pending, spam, trash).',
				'input_schema' => array( 'type' => 'object' ),
			),
			'openclawp/get-active-plugins' => array(
				'description'  => 'Lists currently active plugins on this site (name + version).',
				'input_schema' => array( 'type' => 'object' ),
			),
			'openclawp/get-current-user' => array(
				'description'  => 'Returns the logged-in WordPress user (id, login, display name, roles).',
				'input_schema' => array( 'type' => 'object' ),
			),
		);
		foreach ( $abilities as $name => $args ) {
			wp_register_ability( $name, $args );
		}
	}

}
