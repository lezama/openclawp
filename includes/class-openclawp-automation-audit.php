<?php
/**
 * Site automation opportunity audit.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Automation_Audit {

	/**
	 * Audit the current WordPress site.
	 *
	 * @return array<string,mixed>
	 */
	public static function audit_current_site(): array {
		$signals       = self::collect_site_signals();
		$opportunities = self::score_opportunities( $signals );

		return array(
			'site'          => array(
				'name' => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '',
				'url'  => function_exists( 'home_url' ) ? (string) home_url( '/' ) : '',
			),
			'signals'       => $signals,
			'opportunities' => $opportunities,
			'connector_packs' => OpenclaWP_Agency_Connectors::all(),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function collect_site_signals(): array {
		$plugins = self::active_plugin_slugs();
		$pages   = self::page_signals();

		return array(
			'active_plugins' => $plugins,
			'has_forms'      => self::has_plugin_like( $plugins, array( 'contact-form-7', 'gravityforms', 'wpforms', 'fluentform', 'formidable', 'ninja-forms' ) ),
			'has_woocommerce' => self::has_plugin_like( $plugins, array( 'woocommerce' ) ) || class_exists( 'WooCommerce' ),
			'has_booking_terms' => $pages['booking_terms'],
			'has_quote_terms' => $pages['quote_terms'],
			'has_support_terms' => $pages['support_terms'],
			'has_contact_terms' => $pages['contact_terms'],
			'has_service_terms' => $pages['service_terms'],
			'pages'          => $pages['matches'],
			'pending_comments' => self::pending_comment_count(),
			'kb_available'   => class_exists( 'OpenclaWP_Knowledge_Base_Search' ),
			'available_connectors' => self::available_connectors_from_environment( $plugins ),
		);
	}

	/**
	 * Pure scoring helper.
	 *
	 * @param array<string,mixed> $signals
	 * @return array<int,array<string,mixed>>
	 */
	public static function score_opportunities( array $signals ): array {
		$items = array();

		if ( ! empty( $signals['has_forms'] ) ) {
			$items[] = self::opportunity( 'form-followup', 94, array( 'Form plugin detected; automate response, enrichment, and routing.' ), array( 'forms', 'email', 'crm' ) );
			$items[] = self::opportunity( 'lead-concierge', 82, array( 'Existing forms imply active lead capture that can be qualified conversationally.' ), array( 'forms', 'crm', 'whatsapp' ) );
		}

		if ( ! empty( $signals['has_contact_terms'] ) || ! empty( $signals['has_service_terms'] ) ) {
			$items[] = self::opportunity( 'lead-concierge', 86, array( 'Contact/services pages detected; site likely receives service inquiries.' ), array( 'crm', 'email', 'whatsapp' ) );
		}

		if ( ! empty( $signals['has_quote_terms'] ) ) {
			$items[] = self::opportunity( 'quote-agent', 88, array( 'Quote/pricing language detected; structured quote intake can reduce back-and-forth.' ), array( 'forms', 'crm', 'email' ) );
		}

		if ( ! empty( $signals['has_booking_terms'] ) ) {
			$items[] = self::opportunity( 'booking-agent', 86, array( 'Booking/appointment language detected; conversational scheduling is a strong agency offer.' ), array( 'calendar', 'email', 'whatsapp' ) );
		}

		if ( ! empty( $signals['has_woocommerce'] ) ) {
			$items[] = self::opportunity( 'ecommerce-recovery', 90, array( 'WooCommerce detected; product Q&A and recovery workflows can lift conversion.' ), array( 'woocommerce', 'email', 'whatsapp' ) );
		}

		if ( ! empty( $signals['has_support_terms'] ) || ! empty( $signals['kb_available'] ) ) {
			$items[] = self::opportunity( 'support-kb', 80, array( 'Support/FAQ or KB capability detected; deflection agent is feasible.' ), array( 'knowledge-base', 'helpdesk', 'email' ) );
		}

		$pending_comments = (int) ( $signals['pending_comments'] ?? 0 );
		if ( $pending_comments > 0 ) {
			$items[] = self::opportunity( 'review-responder', min( 85, 60 + $pending_comments ), array( 'Pending public feedback/comments detected; response drafting can save time.' ), array( 'reviews', 'email' ) );
		}

		$items[] = self::opportunity( 'agency-maintenance-report', 72, array( 'Every managed client can receive recurring automation and maintenance opportunity reports.' ), array( 'email', 'site-health', 'analytics' ) );

		$deduped = array();
		foreach ( $items as $item ) {
			$slug = (string) $item['blueprint_slug'];
			if ( ! isset( $deduped[ $slug ] ) || (int) $item['score'] > (int) $deduped[ $slug ]['score'] ) {
				$deduped[ $slug ] = $item;
			}
		}
		usort( $deduped, static fn ( $a, $b ) => (int) $b['score'] <=> (int) $a['score'] );

		return array_values( $deduped );
	}

	/**
	 * @param array<int,string> $plugins
	 * @param array<int,string> $needles
	 */
	public static function has_plugin_like( array $plugins, array $needles ): bool {
		foreach ( $plugins as $plugin ) {
			foreach ( $needles as $needle ) {
				if ( false !== stripos( $plugin, $needle ) ) {
					return true;
				}
			}
		}
		return false;
	}

	private static function opportunity( string $blueprint_slug, int $score, array $evidence, array $connectors ): array {
		$blueprint = OpenclaWP_Agency_Blueprints::get( $blueprint_slug );
		return array(
			'blueprint_slug' => $blueprint_slug,
			'label'          => (string) ( $blueprint['label'] ?? $blueprint_slug ),
			'score'          => max( 0, min( 100, $score ) ),
			'evidence'       => OpenclaWP_Agency_Blueprints::string_list( $evidence ),
			'recommended_connectors' => OpenclaWP_Agency_Blueprints::string_list( $connectors ),
		);
	}

	/**
	 * @return array<int,string>
	 */
	private static function active_plugin_slugs(): array {
		$plugins = get_option( 'active_plugins', array() );
		$plugins = is_array( $plugins ) ? $plugins : array();
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			$network = get_site_option( 'active_sitewide_plugins', array() );
			if ( is_array( $network ) ) {
				$plugins = array_merge( $plugins, array_keys( $network ) );
			}
		}
		return OpenclaWP_Agency_Blueprints::string_list( $plugins );
	}

	/**
	 * @return array{matches:array<int,array<string,string>>,booking_terms:bool,quote_terms:bool,support_terms:bool,contact_terms:bool,service_terms:bool}
	 */
	private static function page_signals(): array {
		$matches = array();
		$flags   = array(
			'booking_terms' => false,
			'quote_terms'   => false,
			'support_terms' => false,
			'contact_terms' => false,
			'service_terms' => false,
		);

		if ( ! function_exists( 'get_posts' ) ) {
			return array_merge( array( 'matches' => array() ), $flags );
		}

		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 80,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);

		$terms = array(
			'booking_terms' => array( 'book', 'booking', 'appointment', 'schedule', 'reservation' ),
			'quote_terms'   => array( 'quote', 'pricing', 'estimate', 'proposal' ),
			'support_terms' => array( 'support', 'help', 'faq', 'docs', 'documentation' ),
			'contact_terms' => array( 'contact', 'inquiry', 'enquire' ),
			'service_terms' => array( 'service', 'services', 'solutions' ),
		);

		foreach ( $pages as $page ) {
			$haystack = strtolower( (string) $page->post_title . ' ' . (string) ( $page->post_name ?? '' ) . ' ' . (string) $page->post_content );
			$page_hits = array();
			foreach ( $terms as $flag => $needles ) {
				foreach ( $needles as $needle ) {
					if ( false !== strpos( $haystack, $needle ) ) {
						$flags[ $flag ] = true;
						$page_hits[]    = $needle;
					}
				}
			}
			if ( ! empty( $page_hits ) ) {
				$matches[] = array(
					'title' => (string) $page->post_title,
					'terms' => implode( ', ', array_values( array_unique( $page_hits ) ) ),
				);
			}
		}

		return array_merge( array( 'matches' => $matches ), $flags );
	}

	private static function pending_comment_count(): int {
		if ( ! function_exists( 'wp_count_comments' ) ) {
			return 0;
		}
		$count = wp_count_comments();
		return is_object( $count ) && isset( $count->moderated ) ? (int) $count->moderated : 0;
	}

	/**
	 * @param array<int,string> $plugins
	 * @return array<int,string>
	 */
	private static function available_connectors_from_environment( array $plugins ): array {
		$available = array( 'knowledge-base', 'site-health' );
		if ( self::has_plugin_like( $plugins, array( 'contact-form-7', 'gravityforms', 'wpforms', 'fluentform', 'formidable', 'ninja-forms' ) ) ) {
			$available[] = 'forms';
		}
		if ( self::has_plugin_like( $plugins, array( 'woocommerce' ) ) || class_exists( 'WooCommerce' ) ) {
			$available[] = 'woocommerce';
		}
		return array_values( array_unique( $available ) );
	}
}
