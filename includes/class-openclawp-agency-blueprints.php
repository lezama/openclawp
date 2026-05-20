<?php
/**
 * Agency automation blueprints.
 *
 * Blueprints are deterministic templates for common client automation offers.
 * They do not register live agents by themselves; the generator turns a
 * blueprint + client workspace + answers into an installable package.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Agency_Blueprints {

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public static function all(): array {
		$blueprints = array(
			'lead-concierge' => array(
				'slug'                 => 'lead-concierge',
				'label'                => __( 'Lead concierge', 'openclawp' ),
				'category'             => 'sales',
				'description'          => __( 'Captures inbound leads, qualifies intent, asks missing questions, and hands qualified prospects to a human or CRM.', 'openclawp' ),
				'ideal_for'            => array( 'local services', 'B2B services', 'real estate', 'health clinics', 'education' ),
				'recommended_channels' => array( 'site-chat', 'whatsapp', 'email' ),
				'recommended_connectors' => array( 'forms', 'crm', 'email', 'whatsapp' ),
				'default_tools'        => array( 'knowledge-base/search', 'openclawp/search-memory', 'openclawp/remember' ),
				'questions'            => array(
					self::question( 'offer', 'Main offer', 'What service/product should the agent sell first?', true ),
					self::question( 'qualification_fields', 'Qualification fields', 'Which details make a lead qualified?', true, 'budget, timeline, location, service needed' ),
					self::question( 'handoff_destination', 'Handoff destination', 'Where should qualified leads go?', true, 'CRM, email, Slack, WhatsApp group' ),
					self::question( 'tone', 'Tone', 'How should the agent sound?', false, 'professional, brief, warm' ),
				),
				'workflow'             => array(
					'id_suffix' => 'lead-concierge',
					'trigger'   => array( 'type' => 'on_demand' ),
					'steps'     => array(
						array( 'id' => 'understand', 'type' => 'agent' ),
						array( 'id' => 'handoff', 'type' => 'ability', 'ability' => 'connector/crm-or-email' ),
					),
				),
				'demo_prompts'         => array(
					'I need a quote for next week. Can someone help?',
					'What information do you need before I book?',
				),
				'success_metrics'      => array( 'qualified_leads', 'handoff_rate', 'response_time_seconds' ),
			),
			'support-kb' => array(
				'slug'                 => 'support-kb',
				'label'                => __( 'Support agent with KB', 'openclawp' ),
				'category'             => 'support',
				'description'          => __( 'Answers customer questions from the client knowledge base and escalates when confidence is low.', 'openclawp' ),
				'ideal_for'            => array( 'SaaS', 'ecommerce', 'membership sites', 'education', 'public services' ),
				'recommended_channels' => array( 'site-chat', 'whatsapp', 'email' ),
				'recommended_connectors' => array( 'knowledge-base', 'helpdesk', 'email' ),
				'default_tools'        => array( 'knowledge-base/search', 'openclawp/search-memory' ),
				'questions'            => array(
					self::question( 'support_scope', 'Support scope', 'What topics should the agent answer?', true, 'pricing, policies, account help, troubleshooting' ),
					self::question( 'escalation_rule', 'Escalation rule', 'When should it escalate to a human?', true, 'refunds, angry customers, legal/medical advice, low confidence' ),
					self::question( 'support_destination', 'Escalation destination', 'Where should escalations go?', true, 'helpdesk, email, Slack' ),
				),
				'workflow'             => array(
					'id_suffix' => 'support-kb',
					'trigger'   => array( 'type' => 'on_demand' ),
					'steps'     => array(
						array( 'id' => 'retrieve', 'type' => 'ability', 'ability' => 'knowledge-base/search' ),
						array( 'id' => 'answer', 'type' => 'agent' ),
					),
				),
				'demo_prompts'         => array(
					'What is your refund policy?',
					'I cannot find how to reset my password.',
				),
				'success_metrics'      => array( 'deflection_rate', 'escalation_rate', 'answer_confidence' ),
			),
			'booking-agent' => array(
				'slug'                 => 'booking-agent',
				'label'                => __( 'Booking agent', 'openclawp' ),
				'category'             => 'operations',
				'description'          => __( 'Collects appointment requirements, checks scheduling rules, and prepares or creates bookings.', 'openclawp' ),
				'ideal_for'            => array( 'clinics', 'salons', 'consultants', 'repair services', 'fitness studios' ),
				'recommended_channels' => array( 'site-chat', 'whatsapp' ),
				'recommended_connectors' => array( 'calendar', 'email', 'whatsapp' ),
				'default_tools'        => array( 'knowledge-base/search', 'openclawp/remember' ),
				'questions'            => array(
					self::question( 'bookable_services', 'Bookable services', 'Which services can be booked?', true ),
					self::question( 'availability_rules', 'Availability rules', 'What days/hours and constraints apply?', true ),
					self::question( 'calendar_destination', 'Calendar destination', 'Which calendar or booking system should receive the booking?', true ),
				),
				'workflow'             => array(
					'id_suffix' => 'booking',
					'trigger'   => array( 'type' => 'on_demand' ),
					'steps'     => array(
						array( 'id' => 'qualify_booking', 'type' => 'agent' ),
						array( 'id' => 'create_booking', 'type' => 'ability', 'ability' => 'connector/calendar' ),
					),
				),
				'demo_prompts'         => array(
					'I want to book an appointment this Friday afternoon.',
					'Do you have availability for a consultation next week?',
				),
				'success_metrics'      => array( 'booking_requests', 'bookings_created', 'human_followups' ),
			),
			'quote-agent' => array(
				'slug'                 => 'quote-agent',
				'label'                => __( 'Quote intake agent', 'openclawp' ),
				'category'             => 'sales',
				'description'          => __( 'Collects structured requirements for custom quotes and prepares a handoff-ready summary.', 'openclawp' ),
				'ideal_for'            => array( 'agencies', 'contractors', 'manufacturing', 'legal services', 'consulting' ),
				'recommended_channels' => array( 'site-chat', 'forms', 'whatsapp' ),
				'recommended_connectors' => array( 'forms', 'crm', 'email' ),
				'default_tools'        => array( 'knowledge-base/search', 'openclawp/remember' ),
				'questions'            => array(
					self::question( 'quote_inputs', 'Quote inputs', 'What inputs are required before a quote can be estimated?', true ),
					self::question( 'pricing_rules', 'Pricing rules', 'What rules or ranges should the agent mention?', false ),
					self::question( 'sales_destination', 'Sales destination', 'Where should quote summaries go?', true ),
				),
				'workflow'             => array(
					'id_suffix' => 'quote-intake',
					'trigger'   => array( 'type' => 'on_demand' ),
					'steps'     => array(
						array( 'id' => 'collect_requirements', 'type' => 'agent' ),
						array( 'id' => 'send_summary', 'type' => 'ability', 'ability' => 'connector/email-or-crm' ),
					),
				),
				'demo_prompts'         => array(
					'I need a quote for a new project.',
					'Can you estimate what this would cost?',
				),
				'success_metrics'      => array( 'quote_requests', 'complete_intakes', 'sales_handoffs' ),
			),
			'ecommerce-recovery' => array(
				'slug'                 => 'ecommerce-recovery',
				'label'                => __( 'WooCommerce recovery agent', 'openclawp' ),
				'category'             => 'ecommerce',
				'description'          => __( 'Helps recover abandoned carts or stalled purchase intent with product-aware answers and human-safe follow-up.', 'openclawp' ),
				'ideal_for'            => array( 'WooCommerce stores', 'DTC brands', 'catalog stores' ),
				'recommended_channels' => array( 'email', 'whatsapp', 'site-chat' ),
				'recommended_connectors' => array( 'woocommerce', 'email', 'whatsapp' ),
				'default_tools'        => array( 'knowledge-base/search', 'openclawp/search-memory' ),
				'questions'            => array(
					self::question( 'product_categories', 'Product categories', 'Which product categories should the agent prioritize?', true ),
					self::question( 'discount_policy', 'Discount policy', 'Can it mention discounts or only answer questions?', true, 'No discounts unless approved' ),
					self::question( 'handoff_destination', 'Handoff destination', 'Where should sales questions go?', true ),
				),
				'workflow'             => array(
					'id_suffix' => 'ecommerce-recovery',
					'trigger'   => array( 'type' => 'wp_action', 'hook' => 'woocommerce_cart_updated' ),
					'steps'     => array(
						array( 'id' => 'inspect_cart', 'type' => 'ability', 'ability' => 'connector/woocommerce' ),
						array( 'id' => 'draft_followup', 'type' => 'agent' ),
					),
				),
				'demo_prompts'         => array(
					'I am not sure which product is right for me.',
					'Do you ship to my area?',
				),
				'success_metrics'      => array( 'recovered_carts', 'product_questions_answered', 'conversion_assists' ),
			),
			'form-followup' => array(
				'slug'                 => 'form-followup',
				'label'                => __( 'Form follow-up agent', 'openclawp' ),
				'category'             => 'operations',
				'description'          => __( 'Responds to form submissions, enriches missing fields, and routes the request.', 'openclawp' ),
				'ideal_for'            => array( 'any lead-gen site', 'agencies', 'local services' ),
				'recommended_channels' => array( 'forms', 'email', 'whatsapp' ),
				'recommended_connectors' => array( 'forms', 'email', 'crm' ),
				'default_tools'        => array( 'knowledge-base/search', 'openclawp/remember' ),
				'questions'            => array(
					self::question( 'form_names', 'Forms', 'Which forms should trigger follow-up?', true ),
					self::question( 'routing_rules', 'Routing rules', 'How should submissions be routed?', true ),
					self::question( 'sla', 'SLA', 'How quickly should the agent respond?', false, 'immediately during business hours' ),
				),
				'workflow'             => array(
					'id_suffix' => 'form-followup',
					'trigger'   => array( 'type' => 'wp_action', 'hook' => 'openclawp_form_submission' ),
					'steps'     => array(
						array( 'id' => 'classify', 'type' => 'agent' ),
						array( 'id' => 'route', 'type' => 'ability', 'ability' => 'connector/email-or-crm' ),
					),
				),
				'demo_prompts'         => array(
					'New contact form: I need help choosing a plan.',
					'New quote form: Please call me tomorrow.',
				),
				'success_metrics'      => array( 'forms_processed', 'missing_fields_collected', 'routing_accuracy' ),
			),
			'review-responder' => array(
				'slug'                 => 'review-responder',
				'label'                => __( 'Review response assistant', 'openclawp' ),
				'category'             => 'reputation',
				'description'          => __( 'Drafts on-brand responses to reviews and escalates sensitive complaints for approval.', 'openclawp' ),
				'ideal_for'            => array( 'local businesses', 'hospitality', 'clinics', 'ecommerce' ),
				'recommended_channels' => array( 'admin', 'email' ),
				'recommended_connectors' => array( 'reviews', 'email' ),
				'default_tools'        => array( 'knowledge-base/search' ),
				'questions'            => array(
					self::question( 'brand_voice', 'Brand voice', 'What tone should review responses use?', true ),
					self::question( 'escalation_rule', 'Escalation rule', 'Which reviews require human approval?', true, '1-2 star reviews, legal/medical claims, refunds' ),
				),
				'workflow'             => array(
					'id_suffix' => 'review-responder',
					'trigger'   => array( 'type' => 'on_demand' ),
					'steps'     => array(
						array( 'id' => 'draft_response', 'type' => 'agent' ),
						array( 'id' => 'approval', 'type' => 'ability', 'ability' => 'agents/list-pending-actions' ),
					),
				),
				'demo_prompts'         => array(
					'Draft a response to this 5-star review.',
					'Draft a response to a complaint about delayed service.',
				),
				'success_metrics'      => array( 'responses_drafted', 'approval_rate', 'time_saved_minutes' ),
			),
			'ticket-triage' => array(
				'slug'                 => 'ticket-triage',
				'label'                => __( 'Ticket triage agent', 'openclawp' ),
				'category'             => 'support',
				'description'          => __( 'Classifies inbound issues, assigns priority, suggests next action, and routes to the right queue.', 'openclawp' ),
				'ideal_for'            => array( 'SaaS', 'agencies', 'managed services', 'support teams' ),
				'recommended_channels' => array( 'email', 'helpdesk', 'slack' ),
				'recommended_connectors' => array( 'helpdesk', 'email', 'slack' ),
				'default_tools'        => array( 'knowledge-base/search', 'openclawp/search-memory' ),
				'questions'            => array(
					self::question( 'queues', 'Queues', 'Which support queues exist?', true ),
					self::question( 'priority_rules', 'Priority rules', 'How should priority be assigned?', true ),
					self::question( 'handoff_destination', 'Handoff destination', 'Where should routed tickets go?', true ),
				),
				'workflow'             => array(
					'id_suffix' => 'ticket-triage',
					'trigger'   => array( 'type' => 'on_demand' ),
					'steps'     => array(
						array( 'id' => 'triage', 'type' => 'agent' ),
						array( 'id' => 'route_ticket', 'type' => 'ability', 'ability' => 'connector/helpdesk' ),
					),
				),
				'demo_prompts'         => array(
					'Classify this ticket: customer cannot log in.',
					'Route this issue: billing dispute with enterprise customer.',
				),
				'success_metrics'      => array( 'tickets_triaged', 'routing_accuracy', 'first_response_time_seconds' ),
			),
			'agency-maintenance-report' => array(
				'slug'                 => 'agency-maintenance-report',
				'label'                => __( 'Agency maintenance reporter', 'openclawp' ),
				'category'             => 'agency-ops',
				'description'          => __( 'Summarizes site health, content changes, usage, and automation opportunities for recurring client reports.', 'openclawp' ),
				'ideal_for'            => array( 'agencies', 'managed WordPress providers' ),
				'recommended_channels' => array( 'admin', 'email' ),
				'recommended_connectors' => array( 'email', 'analytics', 'site-health' ),
				'default_tools'        => array( 'openclawp/list-tools', 'knowledge-base/search' ),
				'questions'            => array(
					self::question( 'report_frequency', 'Report frequency', 'How often should reports be generated?', true, 'weekly or monthly' ),
					self::question( 'report_sections', 'Report sections', 'Which sections should be included?', true, 'updates, uptime, leads, content, opportunities' ),
					self::question( 'recipient', 'Recipient', 'Who receives the report?', true ),
				),
				'workflow'             => array(
					'id_suffix' => 'maintenance-report',
					'trigger'   => array( 'type' => 'cron', 'interval' => 604800 ),
					'steps'     => array(
						array( 'id' => 'audit', 'type' => 'ability', 'ability' => 'openclawp/audit-automation-opportunities' ),
						array( 'id' => 'draft_report', 'type' => 'agent' ),
					),
				),
				'demo_prompts'         => array(
					'Create this month\'s automation opportunity report.',
					'Summarize what this site could automate next.',
				),
				'success_metrics'      => array( 'reports_sent', 'opportunities_identified', 'upsell_conversations' ),
			),
		);

		/**
		 * Filters agency automation blueprints.
		 *
		 * @param array<string,array<string,mixed>> $blueprints
		 */
		$blueprints = (array) apply_filters( 'openclawp_agent_blueprints', $blueprints );

		return self::normalize_blueprints( $blueprints );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function get( string $slug ): ?array {
		$blueprints = self::all();
		$slug       = sanitize_key( $slug );
		return $blueprints[ $slug ] ?? null;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function list( string $category = '' ): array {
		$category = sanitize_key( $category );
		$out      = array();
		foreach ( self::all() as $blueprint ) {
			if ( '' !== $category && ( $blueprint['category'] ?? '' ) !== $category ) {
				continue;
			}
			$out[] = $blueprint;
		}
		return $out;
	}

	/**
	 * @param array<string,array<string,mixed>> $blueprints
	 * @return array<string,array<string,mixed>>
	 */
	public static function normalize_blueprints( array $blueprints ): array {
		$out = array();
		foreach ( $blueprints as $slug => $blueprint ) {
			if ( ! is_array( $blueprint ) ) {
				continue;
			}
			$slug = sanitize_key( (string) ( $blueprint['slug'] ?? $slug ) );
			if ( '' === $slug || str_contains( $slug, 'elementor' ) ) {
				continue;
			}
			$blueprint['slug']                   = $slug;
			$blueprint['category']               = sanitize_key( (string) ( $blueprint['category'] ?? 'general' ) );
			$blueprint['recommended_channels']   = self::string_list( $blueprint['recommended_channels'] ?? array() );
			$blueprint['recommended_connectors'] = self::string_list( $blueprint['recommended_connectors'] ?? array() );
			$blueprint['default_tools']          = self::string_list( $blueprint['default_tools'] ?? array() );
			$blueprint['ideal_for']              = self::string_list( $blueprint['ideal_for'] ?? array() );
			$blueprint['demo_prompts']           = self::string_list( $blueprint['demo_prompts'] ?? array() );
			$blueprint['success_metrics']        = self::string_list( $blueprint['success_metrics'] ?? array() );
			$blueprint['questions']              = isset( $blueprint['questions'] ) && is_array( $blueprint['questions'] ) ? $blueprint['questions'] : array();
			$out[ $slug ]                        = $blueprint;
		}
		ksort( $out );
		return $out;
	}

	/**
	 * @param mixed $value
	 * @return array<int,string>
	 */
	public static function string_list( $value ): array {
		$items = is_array( $value ) ? $value : array( $value );
		$out   = array();
		foreach ( $items as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}
			$item = trim( (string) $item );
			if ( '' !== $item && false === stripos( $item, 'elementor' ) ) {
				$out[] = $item;
			}
		}
		return array_values( array_unique( $out ) );
	}

	private static function question( string $id, string $label, string $prompt, bool $required, string $placeholder = '' ): array {
		return array(
			'id'          => sanitize_key( $id ),
			'label'       => $label,
			'prompt'      => $prompt,
			'required'    => $required,
			'placeholder' => $placeholder,
			'type'        => 'textarea',
		);
	}
}
