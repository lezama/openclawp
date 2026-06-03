<?php
/**
 * Pure-PHP unit tests for OpenclaWP_Agent_Card::build_card_data().
 *
 * Verifies the A2A Agent Card shape and that skills are derived from the
 * agent's tools and subagents, with a generic "chat" fallback so every card
 * advertises at least one skill. The pure builder takes a plain descriptor —
 * no WP_Agent, no DB — so it runs without WordPress. The serve() path that
 * resolves a real agent + abilities is covered by tests/smoke.php.
 *
 * @package OpenclaWP\Tests
 */

declare( strict_types=1 );

namespace OpenclaWP\Tests\Unit;

use OpenclaWP_Agent_Card;
use PHPUnit\Framework\TestCase;

/**
 * @covers OpenclaWP_Agent_Card
 */
final class AgentCardTest extends TestCase {

	private const RPC_URL  = 'https://openclawp.test/wp-json/openclawp/v1/agenttic/openclawp-coordinator';
	private const CARD_URL = self::RPC_URL . '/.well-known/agent-card.json';

	public function test_card_carries_core_a2a_fields(): void {
		$card = OpenclaWP_Agent_Card::build_card_data(
			array(
				'slug'        => 'openclawp-coordinator',
				'label'       => 'openclaWP Coordinator',
				'description' => 'Routes work to subagents.',
				'tools'       => array(),
				'subagents'   => array(),
			),
			self::CARD_URL,
			self::RPC_URL
		);

		$this->assertSame( 'openclaWP Coordinator', $card['name'] );
		$this->assertSame( 'Routes work to subagents.', $card['description'] );
		$this->assertSame( self::RPC_URL, $card['url'] );
		$this->assertSame( 'JSONRPC', $card['preferredTransport'] );
		$this->assertArrayHasKey( 'protocolVersion', $card );
		$this->assertContains( 'text/plain', $card['defaultInputModes'] );
		$this->assertContains( 'text/plain', $card['defaultOutputModes'] );
	}

	public function test_streaming_capability_is_advertised(): void {
		$card = OpenclaWP_Agent_Card::build_card_data(
			array( 'slug' => 'a', 'label' => 'A', 'description' => '', 'tools' => array(), 'subagents' => array() ),
			self::CARD_URL,
			self::RPC_URL
		);

		// The bridge implements real SSE for message/stream, but no push or history.
		$this->assertTrue( $card['capabilities']['streaming'] );
		$this->assertFalse( $card['capabilities']['pushNotifications'] );
		$this->assertFalse( $card['capabilities']['stateTransitionHistory'] );
	}

	public function test_tools_become_skills(): void {
		$card = OpenclaWP_Agent_Card::build_card_data(
			array(
				'slug'        => 'site-introspection',
				'label'       => 'Site Introspection',
				'description' => '',
				'tools'       => array(
					array( 'name' => 'openclawp/get-recent-posts', 'label' => 'Recent posts', 'description' => 'List recent posts.' ),
					array( 'name' => 'openclawp/count-comments', 'label' => 'Comment counts', 'description' => '' ),
				),
				'subagents'   => array(),
			),
			self::CARD_URL,
			self::RPC_URL
		);

		$ids = array_column( $card['skills'], 'id' );
		$this->assertContains( 'openclawp/get-recent-posts', $ids );
		$this->assertContains( 'openclawp/count-comments', $ids );

		$first = $card['skills'][0];
		$this->assertSame( 'Recent posts', $first['name'] );
		$this->assertSame( 'List recent posts.', $first['description'] );
		$this->assertContains( 'tool', $first['tags'] );
	}

	public function test_subagents_become_delegation_skills(): void {
		$card = OpenclaWP_Agent_Card::build_card_data(
			array(
				'slug'        => 'coordinator',
				'label'       => 'Coordinator',
				'description' => '',
				'tools'       => array(),
				'subagents'   => array(
					array( 'slug' => 'openclawp-loop-demo', 'label' => 'Loop Demo', 'description' => 'Tells the time.' ),
				),
			),
			self::CARD_URL,
			self::RPC_URL
		);

		$ids = array_column( $card['skills'], 'id' );
		$this->assertContains( 'delegate-to-openclawp-loop-demo', $ids );

		$skill = $card['skills'][0];
		$this->assertContains( 'subagent', $skill['tags'] );
		$this->assertContains( 'delegation', $skill['tags'] );
	}

	public function test_toolless_agent_gets_generic_chat_skill(): void {
		$card = OpenclaWP_Agent_Card::build_card_data(
			array( 'slug' => 'plain', 'label' => 'Plain', 'description' => '', 'tools' => array(), 'subagents' => array() ),
			self::CARD_URL,
			self::RPC_URL
		);

		$this->assertCount( 1, $card['skills'] );
		$this->assertSame( 'chat', $card['skills'][0]['id'] );
	}

	public function test_name_falls_back_to_slug_when_label_empty(): void {
		$card = OpenclaWP_Agent_Card::build_card_data(
			array( 'slug' => 'no-label-agent', 'label' => '', 'description' => '', 'tools' => array(), 'subagents' => array() ),
			self::CARD_URL,
			self::RPC_URL
		);

		$this->assertSame( 'no-label-agent', $card['name'] );
	}
}
