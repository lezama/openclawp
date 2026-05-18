<?php
/**
 * Paragraph-aware text chunker.
 *
 * Splits a long text into roughly `max_words` chunks with `overlap_words`
 * of overlap, never cutting mid-paragraph. Used by the knowledge-base
 * indexer to break posts and crawled URLs into search-sized rows.
 *
 * Phase 1 uses a word count rather than a tokenizer — token counts vary
 * by provider, and we're storing for `MATCH ... AGAINST`, not for an
 * embedding context window. Phase 2 (vector retrieval) will swap in a
 * tokenizer-aware splitter when an embedding provider is selected.
 *
 * @package OpenclaWP
 * @since   0.7.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Paragraph-aware text chunker.
 */
final class OpenclaWP_Knowledge_Base_Chunker {

	public const DEFAULT_MAX_WORDS     = 800;
	public const DEFAULT_OVERLAP_WORDS = 100;

	/**
	 * Split text into paragraph-respecting chunks.
	 *
	 * @param string $text         Plain text (HTML should be stripped by the caller).
	 * @param int    $max_words    Soft maximum words per chunk.
	 * @param int    $overlap_words Words of overlap to carry into the next chunk.
	 * @return array<int,string> Non-empty chunks in source order.
	 */
	public static function chunk( string $text, int $max_words = self::DEFAULT_MAX_WORDS, int $overlap_words = self::DEFAULT_OVERLAP_WORDS ): array {
		$text = trim( $text );
		if ( '' === $text ) {
			return array();
		}

		$max_words     = max( 1, $max_words );
		$overlap_words = max( 0, min( $overlap_words, $max_words - 1 ) );

		// Normalise line endings, then split on blank lines so paragraphs
		// stay together. preg_split with PREG_SPLIT_NO_EMPTY drops trailing
		// blank tail elements that arise from trailing newlines.
		$normalised = preg_replace( "/\r\n?/", "\n", $text );
		$paragraphs = preg_split( "/\n{2,}/", (string) $normalised, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $paragraphs ) || empty( $paragraphs ) ) {
			$paragraphs = array( $text );
		}

		$chunks = array();
		$buffer = array();
		$count  = 0;

		foreach ( $paragraphs as $paragraph ) {
			$paragraph = trim( (string) $paragraph );
			if ( '' === $paragraph ) {
				continue;
			}

			$words      = preg_split( '/\s+/', $paragraph, -1, PREG_SPLIT_NO_EMPTY );
			$word_count = is_array( $words ) ? count( $words ) : 0;
			if ( 0 === $word_count ) {
				continue;
			}

			// Paragraph alone exceeds the budget — flush whatever we have, then
			// hard-split this paragraph into word windows. Long paragraphs are
			// rare (code blocks, run-on transcripts) but we must not silently
			// drop content.
			if ( $word_count > $max_words ) {
				if ( ! empty( $buffer ) ) {
					$chunks[] = implode( "\n\n", $buffer );
					$buffer   = array();
					$count    = 0;
				}
				$position = 0;
				while ( $position < $word_count ) {
					$slice     = array_slice( (array) $words, $position, $max_words );
					$chunks[]  = implode( ' ', $slice );
					$position += max( 1, $max_words - $overlap_words );
				}
				continue;
			}

			if ( $count + $word_count > $max_words && ! empty( $buffer ) ) {
				$chunks[] = implode( "\n\n", $buffer );

				// Build the overlap window from the tail of the chunk we just
				// flushed. We carry whole paragraphs from the end until we
				// have ~$overlap_words words, so the overlap stays readable.
				if ( $overlap_words > 0 ) {
					$tail       = array();
					$tail_words = 0;
					for ( $i = count( $buffer ) - 1; $i >= 0 && $tail_words < $overlap_words; $i-- ) {
						$bw          = self::count_words( $buffer[ $i ] );
						$tail[]      = $buffer[ $i ];
						$tail_words += $bw;
					}
					$buffer = array_reverse( $tail );
					$count  = $tail_words;
				} else {
					$buffer = array();
					$count  = 0;
				}
			}

			$buffer[] = $paragraph;
			$count   += $word_count;
		}

		if ( ! empty( $buffer ) ) {
			$chunks[] = implode( "\n\n", $buffer );
		}

		// Final safety net: dedup adjacent identical chunks that can arise
		// when a single-paragraph document feeds straight through.
		$result = array();
		$last   = null;
		foreach ( $chunks as $chunk ) {
			$chunk = trim( $chunk );
			if ( '' === $chunk || $chunk === $last ) {
				continue;
			}
			$result[] = $chunk;
			$last     = $chunk;
		}

		return $result;
	}

	/**
	 * Count whitespace-separated words in $text.
	 *
	 * @param string $text Input text.
	 */
	private static function count_words( string $text ): int {
		$words = preg_split( '/\s+/', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
		return is_array( $words ) ? count( $words ) : 0;
	}

	/**
	 * Strip HTML and collapse whitespace for indexing. Block / shortcode
	 * markup leaks into post_content; this normalises both before the
	 * chunker sees them.
	 *
	 * @param string $html Source HTML / block markup.
	 */
	public static function normalise( string $html ): string {
		// strip_shortcodes survives without a global $post in scope.
		if ( function_exists( 'strip_shortcodes' ) ) {
			$html = strip_shortcodes( $html );
		}
		// Convert blocks to their rendered output where cheap, then strip tags.
		if ( function_exists( 'wp_strip_all_tags' ) ) {
			$plain = wp_strip_all_tags( $html, true );
		} else {
			$plain = trim( wp_kses( $html, array() ) );
		}
		// Collapse runs of spaces but preserve blank-line paragraph breaks.
		$plain = preg_replace( "/[ \t]+/", ' ', (string) $plain );
		$plain = preg_replace( "/\n{3,}/", "\n\n", (string) $plain );
		return trim( (string) $plain );
	}
}
