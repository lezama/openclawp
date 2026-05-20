<?php
/**
 * Unit tests for agency package generation.
 *
 * @package OpenclaWP\Tests
 */

declare( strict_types=1 );

namespace OpenclaWP\Tests\Unit;

use OpenclaWP_Agency_Generator;
use PHPUnit\Framework\TestCase;

/**
 * @covers OpenclaWP_Agency_Generator
 */
final class AgencyGeneratorTest extends TestCase {

	public function test_generate_lead_concierge_package_contains_agent_workflow_and_demo(): void {
		$package = OpenclaWP_Agency_Generator::generate(
			array(
				'blueprint' => 'lead-concierge',
				'workspace' => array(
					'name'       => 'Acme Legal',
					'site_url'   => 'https://acme.example',
					'industry'   => 'legal services',
					'goals'      => array( 'qualify leads', 'reduce response time' ),
					'connectors' => array( 'forms' ),
				),
				'answers'   => array(
					'offer'                => 'Initial legal consultation',
					'qualification_fields' => 'case type, urgency, jurisdiction',
					'handoff_destination'  => 'CRM pipeline',
				),
			)
		);

		$this->assertIsArray( $package );
		$this->assertSame( 'agency/acme-legal/lead-concierge', $package['package_id'] );
		$this->assertSame( array(), $package['missing_answers'] );
		$this->assertSame( 'agency-acme-legal-lead-concierge', $package['agent_registration']['slug'] );
		$this->assertStringContainsString( 'Acme Legal', $package['agent_registration']['description'] );
		$this->assertSame( 'agency/acme-legal/lead-concierge', $package['workflow_spec']['id'] );
		$this->assertNotEmpty( $package['demo']['prompts'] );

		$connector_status = array_column( $package['connector_plan'], 'status', 'slug' );
		$this->assertSame( 'available', $connector_status['forms'] );
		$this->assertSame( 'required', $connector_status['crm'] );
	}

	public function test_missing_required_answers_are_reported_without_blocking_draft(): void {
		$package = OpenclaWP_Agency_Generator::generate(
			array(
				'blueprint' => 'booking-agent',
				'workspace' => array( 'name' => 'Studio Norte' ),
				'answers'   => array(
					'bookable_services' => 'Consultations',
				),
			)
		);

		$this->assertIsArray( $package );
		$this->assertContains( 'availability_rules', $package['missing_answers'] );
		$this->assertContains( 'calendar_destination', $package['missing_answers'] );
		$this->assertStringContainsString( 'Fill missing blueprint answers', implode( "\n", $package['deployment_steps'] ) );
	}

	public function test_unknown_blueprint_returns_wp_error(): void {
		$result = OpenclaWP_Agency_Generator::generate(
			array(
				'blueprint' => 'nope',
				'workspace' => array( 'name' => 'Client' ),
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'openclawp_unknown_blueprint', $result->get_error_code() );
	}
}
