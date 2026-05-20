<?php
/**
 * Agency connector pack registry.
 *
 * Connector packs describe capabilities an automation may need. They are not
 * hard dependencies; a pack can be fulfilled by Abilities, MCP tools, a custom
 * tool, or a channel plugin.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Agency_Connectors {

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public static function all(): array {
		$packs = array(
			'forms' => array(
				'slug'        => 'forms',
				'label'       => __( 'Forms', 'openclawp' ),
				'description' => __( 'Read submissions and trigger follow-up workflows from common WordPress form plugins.', 'openclawp' ),
				'tool_hints'  => array( 'connector/forms', 'mcp/forms/list-submissions' ),
			),
			'crm' => array(
				'slug'        => 'crm',
				'label'       => __( 'CRM', 'openclawp' ),
				'description' => __( 'Create or update contacts, deals, and lead notes.', 'openclawp' ),
				'tool_hints'  => array( 'connector/crm', 'mcp/crm/create-contact' ),
			),
			'email' => array(
				'slug'        => 'email',
				'label'       => __( 'Email', 'openclawp' ),
				'description' => __( 'Send internal notifications, customer replies, and recurring reports.', 'openclawp' ),
				'tool_hints'  => array( 'connector/email', 'wp/send-email' ),
			),
			'whatsapp' => array(
				'slug'        => 'whatsapp',
				'label'       => __( 'WhatsApp', 'openclawp' ),
				'description' => __( 'Use WhatsApp as an inbound/outbound customer channel.', 'openclawp' ),
				'tool_hints'  => array( 'channel/whatsapp/send-message' ),
			),
			'calendar' => array(
				'slug'        => 'calendar',
				'label'       => __( 'Calendar', 'openclawp' ),
				'description' => __( 'Check availability and create appointment events.', 'openclawp' ),
				'tool_hints'  => array( 'connector/calendar', 'mcp/calendar/create-event' ),
			),
			'woocommerce' => array(
				'slug'        => 'woocommerce',
				'label'       => __( 'WooCommerce', 'openclawp' ),
				'description' => __( 'Read products, orders, carts, and customer purchase context.', 'openclawp' ),
				'tool_hints'  => array( 'connector/woocommerce', 'wc/orders/search' ),
			),
			'helpdesk' => array(
				'slug'        => 'helpdesk',
				'label'       => __( 'Helpdesk', 'openclawp' ),
				'description' => __( 'Create, classify, route, or update support tickets.', 'openclawp' ),
				'tool_hints'  => array( 'connector/helpdesk', 'mcp/helpdesk/create-ticket' ),
			),
			'slack' => array(
				'slug'        => 'slack',
				'label'       => __( 'Slack', 'openclawp' ),
				'description' => __( 'Notify internal teams and route escalations to channels.', 'openclawp' ),
				'tool_hints'  => array( 'connector/slack', 'mcp/slack/post-message' ),
			),
			'reviews' => array(
				'slug'        => 'reviews',
				'label'       => __( 'Reviews', 'openclawp' ),
				'description' => __( 'Import reviews and draft response workflows.', 'openclawp' ),
				'tool_hints'  => array( 'connector/reviews' ),
			),
			'analytics' => array(
				'slug'        => 'analytics',
				'label'       => __( 'Analytics', 'openclawp' ),
				'description' => __( 'Read traffic, conversion, and campaign metrics for reporting.', 'openclawp' ),
				'tool_hints'  => array( 'connector/analytics' ),
			),
			'site-health' => array(
				'slug'        => 'site-health',
				'label'       => __( 'Site health', 'openclawp' ),
				'description' => __( 'Summarize WordPress health, updates, and operational risks.', 'openclawp' ),
				'tool_hints'  => array( 'wp/site-health' ),
			),
			'knowledge-base' => array(
				'slug'        => 'knowledge-base',
				'label'       => __( 'Knowledge base', 'openclawp' ),
				'description' => __( 'Use indexed site content, URLs, policies, and docs as retrieval context.', 'openclawp' ),
				'tool_hints'  => array( 'knowledge-base/search' ),
			),
		);

		/**
		 * Filters agency connector pack definitions.
		 *
		 * @param array<string,array<string,mixed>> $packs
		 */
		$packs = (array) apply_filters( 'openclawp_agency_connector_packs', $packs );

		$out = array();
		foreach ( $packs as $slug => $pack ) {
			if ( ! is_array( $pack ) ) {
				continue;
			}
			$slug = sanitize_key( (string) ( $pack['slug'] ?? $slug ) );
			if ( '' === $slug ) {
				continue;
			}
			$pack['slug']       = $slug;
			$pack['tool_hints'] = OpenclaWP_Agency_Blueprints::string_list( $pack['tool_hints'] ?? array() );
			$out[ $slug ]       = $pack;
		}
		ksort( $out );
		return $out;
	}

	/**
	 * @param array<int,string> $slugs
	 * @param array<int,string> $available
	 * @return array<int,array<string,mixed>>
	 */
	public static function plan( array $slugs, array $available = array() ): array {
		$packs     = self::all();
		$available = array_map( 'sanitize_key', $available );
		$out       = array();
		foreach ( $slugs as $slug ) {
			$slug = sanitize_key( $slug );
			if ( ! isset( $packs[ $slug ] ) ) {
				$out[] = array(
					'slug'   => $slug,
					'label'  => $slug,
					'status' => 'missing-pack-definition',
				);
				continue;
			}
			$pack           = $packs[ $slug ];
			$pack['status'] = in_array( $slug, $available, true ) ? 'available' : 'required';
			$out[]          = $pack;
		}
		return $out;
	}
}
