<?php
/**
 * Persistence for knowledge-base source configuration.
 *
 * Two source families are persisted in a single option:
 *
 *   - `post_types`: array of post-type slugs whose published items are
 *                   eligible for indexing. Auto-reindex on `save_post`
 *                   only fires for these types.
 *   - `urls`:       array of url entries: `[ url, interval ]` where
 *                   `interval` is `daily` | `weekly`.
 *
 * Kept as plain option storage (no CPT) because the source list is
 * small, edited only by admins, and we want a single read on the
 * `save_post` hot path.
 *
 * @package OpenclaWP
 * @since   0.7.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Knowledge-base source configuration store.
 */
final class OpenclaWP_Knowledge_Base_Sources {

	public const OPTION = 'openclawp_kb_sources';

	public const INTERVAL_DAILY  = 'daily';
	public const INTERVAL_WEEKLY = 'weekly';

	/**
	 * Read the saved source configuration with defaults applied.
	 *
	 * @return array{post_types: array<int,string>, urls: array<int,array{url:string,interval:string}>}
	 */
	public static function get(): array {
		$defaults = array(
			'post_types' => array(),
			'urls'       => array(),
		);

		$raw = get_option( self::OPTION, $defaults );
		if ( ! is_array( $raw ) ) {
			return $defaults;
		}

		$post_types = array();
		if ( isset( $raw['post_types'] ) && is_array( $raw['post_types'] ) ) {
			foreach ( $raw['post_types'] as $pt ) {
				if ( is_string( $pt ) && '' !== $pt ) {
					$post_types[] = sanitize_key( $pt );
				}
			}
		}

		$urls = array();
		if ( isset( $raw['urls'] ) && is_array( $raw['urls'] ) ) {
			foreach ( $raw['urls'] as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$url      = isset( $entry['url'] ) ? esc_url_raw( (string) $entry['url'] ) : '';
				$interval = isset( $entry['interval'] ) ? (string) $entry['interval'] : self::INTERVAL_WEEKLY;
				if ( '' === $url ) {
					continue;
				}
				if ( ! in_array( $interval, array( self::INTERVAL_DAILY, self::INTERVAL_WEEKLY ), true ) ) {
					$interval = self::INTERVAL_WEEKLY;
				}
				$urls[] = array(
					'url'      => $url,
					'interval' => $interval,
				);
			}
		}

		return array(
			'post_types' => array_values( array_unique( $post_types ) ),
			'urls'       => $urls,
		);
	}

	/**
	 * Persist a normalised copy of $sources.
	 *
	 * @param array{post_types?: array<int,string>, urls?: array<int,array{url:string,interval:string}>} $sources Source list.
	 */
	public static function save( array $sources ): void {
		$normalised = array(
			'post_types' => array(),
			'urls'       => array(),
		);

		if ( isset( $sources['post_types'] ) && is_array( $sources['post_types'] ) ) {
			foreach ( $sources['post_types'] as $pt ) {
				if ( ! is_string( $pt ) || '' === $pt ) {
					continue;
				}
				$slug = sanitize_key( $pt );
				if ( '' === $slug ) {
					continue;
				}
				$normalised['post_types'][] = $slug;
			}
			$normalised['post_types'] = array_values( array_unique( $normalised['post_types'] ) );
		}

		if ( isset( $sources['urls'] ) && is_array( $sources['urls'] ) ) {
			foreach ( $sources['urls'] as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$url      = isset( $entry['url'] ) ? esc_url_raw( (string) $entry['url'] ) : '';
				$interval = isset( $entry['interval'] ) ? (string) $entry['interval'] : self::INTERVAL_WEEKLY;
				if ( '' === $url ) {
					continue;
				}
				if ( ! in_array( $interval, array( self::INTERVAL_DAILY, self::INTERVAL_WEEKLY ), true ) ) {
					$interval = self::INTERVAL_WEEKLY;
				}
				$normalised['urls'][] = array(
					'url'      => $url,
					'interval' => $interval,
				);
			}
		}

		update_option( self::OPTION, $normalised, false );
	}

	/**
	 * Whether the given post type is configured for indexing.
	 *
	 * @param string $post_type Post-type slug.
	 */
	public static function indexes_post_type( string $post_type ): bool {
		$config = self::get();
		return in_array( $post_type, $config['post_types'], true );
	}
}
