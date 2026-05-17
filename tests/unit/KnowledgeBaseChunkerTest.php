<?php
/**
 * Pure-PHP unit tests for the knowledge-base text chunker and excerpt builder.
 *
 * The chunker is the one piece of the KB pipeline that has no WordPress
 * dependency — it operates on plain strings. Indexing, schema, and the
 * SQL search path are covered by tests/smoke.php inside a real WP.
 *
 * @package OpenclaWP\Tests
 */

declare( strict_types=1 );

// Stubs of WP helpers that the chunker calls. Defined in the root namespace
// because OpenclaWP_Knowledge_Base_Chunker looks them up unqualified.
namespace {
	if ( ! function_exists( 'wp_strip_all_tags' ) ) {
		function wp_strip_all_tags( string $string, bool $remove_breaks = false ): string {
			$string = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $string );
			$string = strip_tags( (string) $string );
			if ( $remove_breaks ) {
				$string = preg_replace( '/[\r\n\t ]+/', ' ', (string) $string );
			}
			return trim( (string) $string );
		}
	}
	if ( ! function_exists( 'strip_shortcodes' ) ) {
		function strip_shortcodes( string $content ): string {
			return preg_replace( '/\[[^\]]*\]/', '', $content ) ?? $content;
		}
	}
}

namespace OpenclaWP\Tests\Unit {

	use OpenclaWP_Knowledge_Base_Chunker;
	use OpenclaWP_Knowledge_Base_Indexer;
	use OpenclaWP_Knowledge_Base_Search;
	use PHPUnit\Framework\TestCase;

	/**
	 * @covers OpenclaWP_Knowledge_Base_Chunker
	 * @covers OpenclaWP_Knowledge_Base_Search
	 * @covers OpenclaWP_Knowledge_Base_Indexer
	 */
	final class KnowledgeBaseChunkerTest extends TestCase {

		public function test_empty_input_returns_empty_array(): void {
			$this->assertSame( array(), OpenclaWP_Knowledge_Base_Chunker::chunk( '' ) );
			$this->assertSame( array(), OpenclaWP_Knowledge_Base_Chunker::chunk( "   \n  " ) );
		}

		public function test_short_text_returns_single_chunk(): void {
			$text   = 'This is a short paragraph that should fit comfortably in one chunk.';
			$chunks = OpenclaWP_Knowledge_Base_Chunker::chunk( $text, 800, 100 );
			$this->assertCount( 1, $chunks );
			$this->assertSame( $text, $chunks[0] );
		}

		public function test_paragraph_boundaries_are_respected(): void {
			// Three short paragraphs separated by blank lines, max well above
			// total size — should collapse into one chunk that preserves the
			// "\n\n" separators between paragraphs.
			$text   = "Para one.\n\nPara two.\n\nPara three.";
			$chunks = OpenclaWP_Knowledge_Base_Chunker::chunk( $text, 800, 100 );
			$this->assertCount( 1, $chunks );
			$this->assertStringContainsString( "Para one.\n\nPara two.", $chunks[0] );
		}

		public function test_long_input_splits_at_paragraph_boundaries(): void {
			// 10 paragraphs of 50 words each = 500 words. With max=120 we should
			// see multiple chunks, none of which split mid-paragraph (i.e., each
			// chunk starts with the same prefix some paragraph starts with).
			$paragraphs = array();
			for ( $i = 0; $i < 10; $i++ ) {
				$paragraphs[] = sprintf( 'Paragraph %d ', $i + 1 ) . str_repeat( 'word ', 49 );
			}
			$text   = implode( "\n\n", array_map( 'trim', $paragraphs ) );
			$chunks = OpenclaWP_Knowledge_Base_Chunker::chunk( $text, 120, 20 );

			$this->assertGreaterThan( 1, count( $chunks ) );

			// Every chunk should start with "Paragraph N" — proves no paragraph
			// was split mid-content.
			foreach ( $chunks as $chunk ) {
				$this->assertMatchesRegularExpression( '/^Paragraph \d+/', $chunk );
			}
		}

		public function test_long_single_paragraph_is_hard_split(): void {
			// 1000 words in one paragraph — has to be sliced.
			$text   = trim( str_repeat( 'word ', 1000 ) );
			$chunks = OpenclaWP_Knowledge_Base_Chunker::chunk( $text, 200, 50 );

			$this->assertGreaterThan( 1, count( $chunks ) );
			foreach ( $chunks as $chunk ) {
				$word_count = count( preg_split( '/\s+/', trim( $chunk ) ) ?: array() );
				$this->assertLessThanOrEqual( 200, $word_count );
			}
		}

		public function test_overlap_carries_tail_into_next_chunk(): void {
			// Each paragraph is a unique sentinel; with max=2-paragraphs and
			// overlap large enough to retain the most-recent paragraph, the
			// second chunk should start with the same paragraph the first
			// ended on.
			$text = "P1 one one one.\n\nP2 two two two.\n\nP3 three three three.\n\nP4 four four four.";
			// Max ~10 words → two paragraphs per chunk. Overlap 4 words ≈ one paragraph.
			$chunks = OpenclaWP_Knowledge_Base_Chunker::chunk( $text, 10, 4 );

			$this->assertGreaterThanOrEqual( 2, count( $chunks ) );
			$this->assertStringContainsString( 'P2 two two two.', $chunks[0] );
			// The overlap window from chunk 0's tail should reappear at the
			// head of chunk 1.
			$this->assertStringStartsWith( 'P2 two two two.', $chunks[1] );
		}

		public function test_normalise_strips_html_and_shortcodes(): void {
			$html  = '<p>Hello <strong>world</strong>.</p>[my_shortcode]<p>Second.</p>';
			$plain = OpenclaWP_Knowledge_Base_Chunker::normalise( $html );
			$this->assertStringContainsString( 'Hello world', $plain );
			$this->assertStringContainsString( 'Second.', $plain );
			$this->assertStringNotContainsString( '[my_shortcode]', $plain );
			$this->assertStringNotContainsString( '<p>', $plain );
		}

		public function test_excerpt_includes_query_term_when_present(): void {
			$content = str_repeat( 'lorem ipsum dolor sit amet ', 20 ) . 'pineapple ' . str_repeat( 'consectetur adipiscing elit ', 20 );
			$excerpt = OpenclaWP_Knowledge_Base_Search::build_excerpt( $content, 'pineapple', 30 );
			$this->assertStringContainsString( 'pineapple', $excerpt );
		}

		public function test_excerpt_falls_back_to_head_when_no_match(): void {
			$content = 'alpha beta gamma delta epsilon zeta eta theta';
			$excerpt = OpenclaWP_Knowledge_Base_Search::build_excerpt( $content, 'omega', 5 );
			// "alpha beta gamma delta epsilon" + ellipsis since there are more words.
			$this->assertStringStartsWith( 'alpha beta gamma delta epsilon', $excerpt );
		}

		public function test_robots_disallows_blocks_matching_path(): void {
			$robots = "User-agent: *\nDisallow: /private\n";
			$this->assertFalse( OpenclaWP_Knowledge_Base_Indexer::robots_path_allowed( $robots, '/private/docs' ) );
			$this->assertTrue( OpenclaWP_Knowledge_Base_Indexer::robots_path_allowed( $robots, '/public/docs' ) );
		}

		public function test_robots_ignores_non_wildcard_user_agents(): void {
			$robots = "User-agent: SpecialBot\nDisallow: /\n";
			// Our crawler is not "SpecialBot", and there's no "User-agent: *"
			// block, so everything is allowed.
			$this->assertTrue( OpenclaWP_Knowledge_Base_Indexer::robots_path_allowed( $robots, '/anything' ) );
		}

		public function test_url_id_is_stable_and_short(): void {
			$id_one = OpenclaWP_Knowledge_Base_Indexer::url_id( 'https://example.com/docs' );
			$id_two = OpenclaWP_Knowledge_Base_Indexer::url_id( 'https://example.com/docs' );
			$this->assertSame( $id_one, $id_two );
			$this->assertSame( 32, strlen( $id_one ) );

			$id_three = OpenclaWP_Knowledge_Base_Indexer::url_id( 'https://example.com/other' );
			$this->assertNotSame( $id_one, $id_three );
		}
	}

}
