<?php
/**
 * Pure-PHP unit tests for OpenclaWP_Tool_Discovery.
 *
 * Exercises `list_tools()` and `execute_tool()` against a stub abilities
 * registry. The integration path (real Abilities API + REST exposure) is
 * covered by tests/smoke.php inside a running WordPress.
 *
 * @package OpenclaWP\Tests
 */

declare( strict_types=1 );

namespace {
	/**
	 * Static, mutable in-memory registry shared between the stub functions
	 * below and the test methods. The test class swaps it out in setUp().
	 *
	 * @var array<string, object>
	 */
	$GLOBALS['openclawp_test_abilities_registry'] = array();

	if ( ! function_exists( 'wp_get_abilities' ) ) {
		function wp_get_abilities(): array {
			return array_values( $GLOBALS['openclawp_test_abilities_registry'] ?? array() );
		}
	}
	if ( ! function_exists( 'wp_get_ability' ) ) {
		function wp_get_ability( string $name ) {
			return $GLOBALS['openclawp_test_abilities_registry'][ $name ] ?? null;
		}
	}
}

namespace OpenclaWP\Tests\Unit {

	use OpenclaWP_Tool_Discovery;
	use PHPUnit\Framework\TestCase;

	/**
	 * Tiny stub of WP_Ability — only the methods the discovery helpers call.
	 */
	final class StubAbility {
		/** @var callable|null */
		private $executor;

		public function __construct(
			private string $name,
			private string $description = '',
			private string $category = '',
			private string $label = '',
			?callable $executor = null
		) {
			$this->executor = $executor;
		}
		public function get_name(): string {
			return $this->name;
		}
		public function get_description(): string {
			return $this->description;
		}
		public function get_category(): string {
			return $this->category;
		}
		public function get_label(): string {
			return $this->label;
		}
		public function execute( array $args ) {
			if ( null === $this->executor ) {
				return array( 'echoed' => $args );
			}
			return call_user_func( $this->executor, $args );
		}
	}

	/**
	 * @covers OpenclaWP_Tool_Discovery
	 */
	final class ToolDiscoveryTest extends TestCase {

		protected function setUp(): void {
			$GLOBALS['openclawp_test_abilities_registry'] = array(
				'openclawp/get-time'   => new StubAbility(
					'openclawp/get-time',
					'Return the current server time.',
					'openclawp',
					'Get time'
				),
				'openclawp/echo'       => new StubAbility(
					'openclawp/echo',
					'Echoes the input back.',
					'openclawp',
					'Echo',
					static fn ( array $args ) => array( 'echoed' => $args['text'] ?? '' )
				),
				'posts/recent'         => new StubAbility(
					'posts/recent',
					"Returns recent published posts.\nUses WP_Query.",
					'',
					'Recent posts'
				),
				'posts/count'          => new StubAbility(
					'posts/count',
					'Counts posts by status.',
					'',
					'Count posts'
				),
				// Meta-tools must be filtered out of their own catalog.
				OpenclaWP_Tool_Discovery::LIST_ABILITY    => new StubAbility(
					OpenclaWP_Tool_Discovery::LIST_ABILITY,
					'meta — list tools',
					'openclawp'
				),
				OpenclaWP_Tool_Discovery::EXECUTE_ABILITY => new StubAbility(
					OpenclaWP_Tool_Discovery::EXECUTE_ABILITY,
					'meta — execute tool',
					'openclawp'
				),
			);
		}

		public function test_list_tools_returns_registered_abilities_sans_meta_tools(): void {
			$result = OpenclaWP_Tool_Discovery::list_tools();

			$this->assertIsArray( $result['tools'] );
			$this->assertSame( 4, $result['total'], 'total counts non-meta abilities' );

			$slugs = array_column( $result['tools'], 'slug' );
			$this->assertContains( 'openclawp/get-time', $slugs );
			$this->assertContains( 'openclawp/echo', $slugs );
			$this->assertContains( 'posts/recent', $slugs );
			$this->assertContains( 'posts/count', $slugs );
			$this->assertNotContains( OpenclaWP_Tool_Discovery::LIST_ABILITY, $slugs );
			$this->assertNotContains( OpenclaWP_Tool_Discovery::EXECUTE_ABILITY, $slugs );
		}

		public function test_list_tools_returns_one_line_descriptions(): void {
			$result = OpenclaWP_Tool_Discovery::list_tools();
			$rows   = array_combine( array_column( $result['tools'], 'slug' ), $result['tools'] );

			// Multi-line description gets collapsed.
			$this->assertSame( 'Returns recent published posts. Uses WP_Query.', $rows['posts/recent']['description'] );
		}

		public function test_category_is_inferred_from_slug_namespace_when_missing(): void {
			$result = OpenclaWP_Tool_Discovery::list_tools();
			$rows   = array_combine( array_column( $result['tools'], 'slug' ), $result['tools'] );

			$this->assertSame( 'openclawp', $rows['openclawp/get-time']['category'], 'explicit category preserved' );
			$this->assertSame( 'posts', $rows['posts/recent']['category'], 'category inferred from namespace' );
		}

		public function test_list_tools_filters_by_category(): void {
			$result = OpenclaWP_Tool_Discovery::list_tools( array( 'category' => 'posts' ) );
			$slugs  = array_column( $result['tools'], 'slug' );

			$this->assertCount( 2, $result['tools'] );
			$this->assertContains( 'posts/recent', $slugs );
			$this->assertContains( 'posts/count', $slugs );
		}

		public function test_list_tools_pagination_cursor_survives_across_pages(): void {
			$page1 = OpenclaWP_Tool_Discovery::list_tools( array( 'limit' => 2 ) );
			$this->assertCount( 2, $page1['tools'] );
			$this->assertNotNull( $page1['next_cursor'], 'first page should have a next_cursor' );

			$page2 = OpenclaWP_Tool_Discovery::list_tools( array( 'limit' => 2, 'cursor' => $page1['next_cursor'] ) );
			$this->assertCount( 2, $page2['tools'] );

			// Pages must be disjoint.
			$page1_slugs = array_column( $page1['tools'], 'slug' );
			$page2_slugs = array_column( $page2['tools'], 'slug' );
			$this->assertEmpty( array_intersect( $page1_slugs, $page2_slugs ), 'pages must not overlap' );

			// Last page has no further cursor.
			$this->assertNull( $page2['next_cursor'], 'final page has no next_cursor' );
		}

		public function test_list_tools_respects_tools_allowlist(): void {
			$result = OpenclaWP_Tool_Discovery::list_tools( array( 'tools' => array( 'openclawp/get-time' ) ) );
			$slugs  = array_column( $result['tools'], 'slug' );

			$this->assertSame( array( 'openclawp/get-time' ), $slugs );
		}

		public function test_list_tools_caps_limit_at_max_page_size(): void {
			$result = OpenclaWP_Tool_Discovery::list_tools( array( 'limit' => 99999 ) );

			// Cap is internal — assert we got everything in one page anyway.
			$this->assertCount( 4, $result['tools'] );
			$this->assertNull( $result['next_cursor'] );
		}

		public function test_execute_tool_dispatches_to_registered_ability(): void {
			$result = OpenclaWP_Tool_Discovery::execute_tool(
				array(
					'tool' => 'openclawp/echo',
					'args' => array( 'text' => 'hola' ),
				)
			);

			$this->assertIsArray( $result );
			$this->assertSame( 'openclawp/echo', $result['tool'] );
			$this->assertSame( array( 'echoed' => 'hola' ), $result['result'] );
		}

		public function test_execute_tool_returns_wp_error_for_unknown_slug(): void {
			$result = OpenclaWP_Tool_Discovery::execute_tool( array( 'tool' => 'unknown/slug' ) );

			$this->assertTrue( \is_wp_error( $result ) );
			$this->assertSame( 'openclawp_execute_tool_unknown', $result->get_error_code() );
		}

		public function test_execute_tool_rejects_missing_slug(): void {
			$result = OpenclaWP_Tool_Discovery::execute_tool( array() );

			$this->assertTrue( \is_wp_error( $result ) );
			$this->assertSame( 'openclawp_execute_tool_missing_slug', $result->get_error_code() );
		}

		public function test_execute_tool_refuses_to_recurse_into_meta_tools(): void {
			foreach ( array( OpenclaWP_Tool_Discovery::LIST_ABILITY, OpenclaWP_Tool_Discovery::EXECUTE_ABILITY ) as $meta ) {
				$result = OpenclaWP_Tool_Discovery::execute_tool( array( 'tool' => $meta ) );
				$this->assertTrue( \is_wp_error( $result ), 'meta-tool ' . $meta . ' must not be dispatchable' );
				$this->assertSame( 'openclawp_execute_tool_recursion', $result->get_error_code() );
			}
		}

		public function test_meta_tool_resolver_payload_declares_both_meta_tools(): void {
			$payload = OpenclaWP_Tool_Discovery::meta_tool_resolver_payload();

			$this->assertCount( 2, $payload['declarations'] );
			$this->assertCount( 2, $payload['name_to_ability'] );

			$abilities = array_values( $payload['name_to_ability'] );
			$this->assertContains( OpenclaWP_Tool_Discovery::LIST_ABILITY, $abilities );
			$this->assertContains( OpenclaWP_Tool_Discovery::EXECUTE_ABILITY, $abilities );
		}
	}
}
