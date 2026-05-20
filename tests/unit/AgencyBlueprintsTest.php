<?php
/**
 * Unit tests for agency automation blueprints.
 *
 * @package OpenclaWP\Tests
 */

declare( strict_types=1 );

namespace OpenclaWP\Tests\Unit;

use OpenclaWP_Agency_Blueprints;
use OpenclaWP_Agency_Connectors;
use PHPUnit\Framework\TestCase;

/**
 * @covers OpenclaWP_Agency_Blueprints
 * @covers OpenclaWP_Agency_Connectors
 */
final class AgencyBlueprintsTest extends TestCase {

	public function test_core_blueprints_are_registered(): void {
		$blueprints = OpenclaWP_Agency_Blueprints::all();

		foreach ( array( 'lead-concierge', 'support-kb', 'booking-agent', 'quote-agent', 'ecommerce-recovery', 'form-followup', 'agency-maintenance-report' ) as $slug ) {
			$this->assertArrayHasKey( $slug, $blueprints );
			$this->assertNotEmpty( $blueprints[ $slug ]['questions'] );
			$this->assertNotEmpty( $blueprints[ $slug ]['recommended_connectors'] );
		}
	}

	public function test_blueprints_do_not_include_elementor_specific_automation(): void {
		$encoded = strtolower( (string) json_encode( OpenclaWP_Agency_Blueprints::all() ) );

		$this->assertStringNotContainsString( 'elementor', $encoded );
	}

	public function test_connector_plan_marks_available_and_required_packs(): void {
		$plan = OpenclaWP_Agency_Connectors::plan(
			array( 'forms', 'crm', 'email' ),
			array( 'forms' )
		);

		$by_slug = array_column( $plan, null, 'slug' );
		$this->assertSame( 'available', $by_slug['forms']['status'] );
		$this->assertSame( 'required', $by_slug['crm']['status'] );
		$this->assertSame( 'required', $by_slug['email']['status'] );
	}
}
