<?php
/**
 * Pure-PHP integration test of the indexer → search round-trip.
 *
 * The real path uses MySQL's FULLTEXT engine, which we can't stand up in
 * a pure-PHP unit test. We stub a minimal $wpdb that records inserts and
 * implements a naive MATCH ... AGAINST in PHP — enough to assert that:
 *
 *   - Indexing a fixture post writes the expected chunks.
 *   - knowledge-base/search returns the post with a citable permalink
 *     and an excerpt containing the query term.
 *
 * The real SQL path is exercised by tests/smoke.php inside a Studio site.
 *
 * @package OpenclaWP\Tests
 */

declare( strict_types=1 );

// Define WP_Post in the root namespace before declaring our test namespace.
// Tests further down `use` it via the fully-qualified `\WP_Post`.
namespace {
	if ( ! class_exists( '\\WP_Post' ) ) {
		class WP_Post {
			public $ID;
			public $post_title   = '';
			public $post_content = '';
			public $post_status  = 'publish';
			public $post_type    = 'post';
		}
	}

	if ( ! function_exists( 'wp_is_post_revision' ) ) {
		function wp_is_post_revision( $id ) {
			return false; }
	}
	if ( ! function_exists( 'wp_is_post_autosave' ) ) {
		function wp_is_post_autosave( $id ) {
			return false; }
	}
	if ( ! function_exists( 'get_post' ) ) {
		function get_post( $id ) {
			return $GLOBALS['openclawp_test_posts'][ (int) $id ] ?? null; }
	}
	if ( ! function_exists( 'get_the_title' ) ) {
		function get_the_title( $post ) {
			return is_object( $post ) ? (string) $post->post_title : ''; }
	}
	if ( ! function_exists( 'get_permalink' ) ) {
		function get_permalink( $post ) {
			$id = is_object( $post ) ? $post->ID : (int) $post;
			return 'https://example.test/?p=' . (int) $id;
		}
	}
	if ( ! function_exists( 'get_option' ) ) {
		function get_option( $name, $default = false ) {
			return $GLOBALS['openclawp_test_options'][ $name ] ?? $default; }
	}
	if ( ! function_exists( 'update_option' ) ) {
		function update_option( $name, $value, $autoload = null ) {
			$GLOBALS['openclawp_test_options'][ $name ] = $value;
			return true;
		}
	}
	if ( ! function_exists( 'sanitize_key' ) ) {
		function sanitize_key( $key ) {
			return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) ?? '' ); }
	}
	if ( ! function_exists( 'esc_url_raw' ) ) {
		function esc_url_raw( $url ) {
			return (string) $url; }
	}
	if ( ! function_exists( 'home_url' ) ) {
		function home_url( $path = '' ) {
			return 'https://example.test' . $path; }
	}

	if ( ! defined( 'OPENCLAWP_VERSION' ) ) {
		define( 'OPENCLAWP_VERSION', '0.1.0-test' );
	}
	if ( ! defined( 'ARRAY_A' ) ) {
		define( 'ARRAY_A', 'ARRAY_A' );
	}
	if ( ! defined( 'OBJECT' ) ) {
		define( 'OBJECT', 'OBJECT' );
	}
}

namespace OpenclaWP\Tests\Unit {

	use OpenclaWP_Knowledge_Base_Indexer;
	use OpenclaWP_Knowledge_Base_Schema;
	use OpenclaWP_Knowledge_Base_Search;
	use OpenclaWP_Knowledge_Base_Sources;
	use PHPUnit\Framework\TestCase;

	/**
	 * In-memory $wpdb stub that implements just enough of insert/delete/get_results
	 * and prepare to round-trip the indexer + search code.
	 */
	final class FakeWpdb {
		public string $prefix = 'wp_';
		/** @var array<int,array<string,mixed>> */
		public array $rows   = array();
		private int $next_id = 1;

		public function get_charset_collate(): string {
			return ''; }

		public function prepare( string $sql, ...$args ): string {
			// Flatten variadic — wpdb does this for callers that pass an array.
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0];
			}
			$i      = 0;
			$result = preg_replace_callback(
				'/%[sdif]/',
				static function ( $m ) use ( &$i, $args ) {
					$value = $args[ $i++ ] ?? '';
					if ( '%d' === $m[0] || '%i' === $m[0] ) {
						return (string) (int) $value;
					}
					if ( '%f' === $m[0] ) {
						return (string) (float) $value;
					}
					// %s
					return "'" . addslashes( (string) $value ) . "'";
				},
				$sql
			);
			return (string) $result;
		}

		public function insert( string $table, array $data, array $formats ): int {
			$row          = $data;
			$row['id']    = $this->next_id++;
			$this->rows[] = $row;
			return 1;
		}

		public function delete( string $table, array $where, array $formats ): int {
			$kept    = array();
			$deleted = 0;
			foreach ( $this->rows as $row ) {
				$match = true;
				foreach ( $where as $col => $val ) {
					if ( ! array_key_exists( $col, $row ) || (string) $row[ $col ] !== (string) $val ) {
						$match = false;
						break;
					}
				}
				if ( $match ) {
					++$deleted;
					continue;
				}
				$kept[] = $row;
			}
			$this->rows = $kept;
			return $deleted;
		}

		public function get_var( string $sql ) {
			return null;
		}

		/**
		 * Naive MATCH ... AGAINST in NATURAL LANGUAGE MODE: count case-insensitive
		 * substring hits in title + content for each query term, then order DESC.
		 * Real MySQL's ranking is fancier — we only need the correct row to win
		 * for the fixture queries used in tests.
		 */
		public function get_results( string $sql, $output = OBJECT ) {
			// Extract the AGAINST argument and the LIMIT.
			if ( ! preg_match( "/AGAINST\\('([^']*)' IN NATURAL LANGUAGE MODE\\)/i", $sql, $m ) ) {
				return array();
			}
			$query = strtolower( (string) $m[1] );
			$terms = array_filter( preg_split( '/\s+/', $query ) ?: array() );

			$limit = 5;
			if ( preg_match( '/LIMIT (\d+)/i', $sql, $m2 ) ) {
				$limit = (int) $m2[1];
			}

			$scored = array();
			foreach ( $this->rows as $row ) {
				$haystack = strtolower( ( (string) ( $row['title'] ?? '' ) ) . ' ' . (string) ( $row['content'] ?? '' ) );
				$score    = 0.0;
				foreach ( $terms as $term ) {
					$score += substr_count( $haystack, $term );
				}
				if ( $score > 0 ) {
					$row['score'] = $score;
					$scored[]     = $row;
				}
			}
			usort( $scored, static fn ( $a, $b ) => $b['score'] <=> $a['score'] );
			return array_slice( $scored, 0, $limit );
		}

		public function query( string $sql ): bool {
			return true; }
	}

	/**
	 * @covers OpenclaWP_Knowledge_Base_Indexer
	 * @covers OpenclaWP_Knowledge_Base_Search
	 */
	final class KnowledgeBaseSearchTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();
			$GLOBALS['wpdb']                   = new FakeWpdb();
			$GLOBALS['openclawp_test_posts']   = array();
			$GLOBALS['openclawp_test_options'] = array();
		}

		protected function tearDown(): void {
			unset( $GLOBALS['wpdb'], $GLOBALS['openclawp_test_posts'], $GLOBALS['openclawp_test_options'] );
			parent::tearDown();
		}

		public function test_index_post_writes_chunks_and_search_returns_citation(): void {
			// Configure sources so the post type is indexable.
			OpenclaWP_Knowledge_Base_Sources::save(
				array(
					'post_types' => array( 'post' ),
					'urls'       => array(),
				)
			);

			// Stub a fixture post matching get_post() expectations.
			$post                                = new \WP_Post();
			$post->ID                            = 42;
			$post->post_title                    = 'Our return policy';
			$post->post_content                  = "<p>We offer pineapple-flavoured ice cream year-round.</p>\n\n<p>Returns are accepted within 30 days for any reason.</p>";
			$post->post_status                   = 'publish';
			$post->post_type                     = 'post';
			$GLOBALS['openclawp_test_posts'][42] = $post;

			$count = OpenclaWP_Knowledge_Base_Indexer::index_post( 42 );
			$this->assertGreaterThanOrEqual( 1, $count );

			$results = OpenclaWP_Knowledge_Base_Search::search( 'pineapple', 5 );
			$this->assertNotEmpty( $results, 'Search should return at least one result for an indexed term.' );

			$top = $results[0];
			$this->assertSame( OpenclaWP_Knowledge_Base_Schema::SOURCE_POST, $top['source'] );
			$this->assertSame( '42', $top['source_id'] );
			$this->assertSame( 'Our return policy', $top['title'] );
			$this->assertSame( 'https://example.test/?p=42', $top['permalink'] );
			$this->assertStringContainsString( 'pineapple', strtolower( $top['excerpt'] ) );
			$this->assertGreaterThan( 0.0, $top['score'] );
		}

		public function test_reindex_replaces_old_chunks(): void {
			OpenclaWP_Knowledge_Base_Sources::save(
				array(
					'post_types' => array( 'post' ),
					'urls'       => array(),
				)
			);

			$post                               = new \WP_Post();
			$post->ID                           = 7;
			$post->post_title                   = 'Original';
			$post->post_content                 = 'pineapple pineapple pineapple';
			$post->post_status                  = 'publish';
			$post->post_type                    = 'post';
			$GLOBALS['openclawp_test_posts'][7] = $post;

			OpenclaWP_Knowledge_Base_Indexer::index_post( 7 );
			$this->assertNotEmpty( OpenclaWP_Knowledge_Base_Search::search( 'pineapple' ) );

			// Mutate the post and reindex. Old term should no longer match.
			$post->post_content = 'mango mango mango';
			OpenclaWP_Knowledge_Base_Indexer::index_post( 7 );

			$this->assertSame( array(), OpenclaWP_Knowledge_Base_Search::search( 'pineapple' ) );
			$this->assertNotEmpty( OpenclaWP_Knowledge_Base_Search::search( 'mango' ) );
		}

		public function test_unpublished_post_is_removed_from_index(): void {
			OpenclaWP_Knowledge_Base_Sources::save(
				array(
					'post_types' => array( 'post' ),
					'urls'       => array(),
				)
			);

			$post                                = new \WP_Post();
			$post->ID                            = 11;
			$post->post_title                    = 'Draft me';
			$post->post_content                  = 'pineapple';
			$post->post_status                   = 'publish';
			$post->post_type                     = 'post';
			$GLOBALS['openclawp_test_posts'][11] = $post;

			OpenclaWP_Knowledge_Base_Indexer::index_post( 11 );
			$this->assertNotEmpty( OpenclaWP_Knowledge_Base_Search::search( 'pineapple' ) );

			// Unpublish.
			$post->post_status = 'draft';
			$count             = OpenclaWP_Knowledge_Base_Indexer::index_post( 11 );
			$this->assertSame( 0, $count );
			$this->assertSame( array(), OpenclaWP_Knowledge_Base_Search::search( 'pineapple' ) );
		}
	}

}
