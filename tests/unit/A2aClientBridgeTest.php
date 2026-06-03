<?php
/**
 * Pure-PHP unit tests for OpenclaWP_A2a_Client_Bridge::plan_peer_list().
 *
 * Verifies that configured A2A peers surface in the ability registry under the
 * `a2a/<peer-slug>` prefix, that peers without a slug or usable endpoint are
 * dropped, and that headers/label/local_agent are carried through. The
 * plan_peer_list() helper is pure-PHP — no DB, no WP, no HTTP — mirroring the
 * MCP client bridge's plan_ability_list(). The full path that calls
 * wp_register_ability() is covered by tests/smoke.php.
 *
 * @package OpenclaWP\Tests
 */

declare( strict_types=1 );

namespace OpenclaWP\Tests\Unit;

use OpenclaWP_A2a_Client_Bridge;
use PHPUnit\Framework\TestCase;

/**
 * @covers OpenclaWP_A2a_Client_Bridge
 */
final class A2aClientBridgeTest extends TestCase {

	public function test_registers_under_a2a_prefix_with_peer_slug(): void {
		$plan = OpenclaWP_A2a_Client_Bridge::plan_peer_list(
			array(
				'site-b' => array(
					'label'    => 'Client Site B',
					'endpoint' => 'https://site-b.test/wp-json/openclawp/v1/agenttic/openclawp-site-introspection',
				),
			)
		);

		$this->assertCount( 1, $plan );
		$this->assertSame( 'a2a/site-b', $plan[0]['ability_name'] );
		$this->assertStringStartsWith( OpenclaWP_A2a_Client_Bridge::ABILITY_PREFIX, $plan[0]['ability_name'] );
		$this->assertSame( 'site-b', $plan[0]['slug'] );
		$this->assertSame( 'Client Site B', $plan[0]['label'] );
		$this->assertSame( 'https://site-b.test/wp-json/openclawp/v1/agenttic/openclawp-site-introspection', $plan[0]['endpoint'] );
	}

	public function test_peer_without_endpoint_is_dropped(): void {
		$plan = OpenclaWP_A2a_Client_Bridge::plan_peer_list(
			array(
				'good' => array( 'endpoint' => 'https://good.test/wp-json/openclawp/v1/agenttic/x' ),
				'bad'  => array( 'label' => 'No endpoint here' ),
			)
		);

		$slugs = array_column( $plan, 'slug' );
		$this->assertCount( 1, $plan );
		$this->assertContains( 'good', $slugs );
		$this->assertNotContains( 'bad', $slugs );
	}

	public function test_label_falls_back_to_slug(): void {
		$plan = OpenclaWP_A2a_Client_Bridge::plan_peer_list(
			array( 'peer-x' => array( 'endpoint' => 'https://x.test/wp-json/openclawp/v1/agenttic/x' ) )
		);

		$this->assertSame( 'peer-x', $plan[0]['label'] );
	}

	public function test_slug_is_sanitized(): void {
		$plan = OpenclaWP_A2a_Client_Bridge::plan_peer_list(
			array( 'Client Site!' => array( 'endpoint' => 'https://x.test/wp-json/openclawp/v1/agenttic/x' ) )
		);

		$this->assertCount( 1, $plan );
		$this->assertSame( 'client-site', $plan[0]['slug'] );
		$this->assertSame( 'a2a/client-site', $plan[0]['ability_name'] );
	}

	public function test_headers_and_local_agent_carry_through(): void {
		$plan = OpenclaWP_A2a_Client_Bridge::plan_peer_list(
			array(
				'site-b' => array(
					'endpoint'    => 'https://site-b.test/wp-json/openclawp/v1/agenttic/x',
					'headers'     => array( 'Authorization' => 'Bearer abc123' ),
					'local_agent' => 'openclawp-coordinator',
				),
			)
		);

		$this->assertSame( array( 'Authorization' => 'Bearer abc123' ), $plan[0]['headers'] );
		$this->assertSame( 'openclawp-coordinator', $plan[0]['local_agent'] );
	}

	public function test_non_array_peer_config_is_skipped(): void {
		$plan = OpenclaWP_A2a_Client_Bridge::plan_peer_list(
			array(
				'ok'      => array( 'endpoint' => 'https://ok.test/wp-json/openclawp/v1/agenttic/x' ),
				'garbage' => 'not-an-array',
			)
		);

		$this->assertCount( 1, $plan );
		$this->assertSame( 'ok', $plan[0]['slug'] );
	}
}
