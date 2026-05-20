<?php
/**
 * Unit tests for automation opportunity scoring.
 *
 * @package OpenclaWP\Tests
 */

declare( strict_types=1 );

namespace OpenclaWP\Tests\Unit;

use OpenclaWP_Automation_Audit;
use PHPUnit\Framework\TestCase;

/**
 * @covers OpenclaWP_Automation_Audit
 */
final class AutomationAuditTest extends TestCase {

	public function test_forms_and_quote_signals_recommend_followup_and_quote_agents(): void {
		$opportunities = OpenclaWP_Automation_Audit::score_opportunities(
			array(
				'has_forms'        => true,
				'has_quote_terms'  => true,
				'has_contact_terms' => true,
				'has_service_terms' => false,
				'has_booking_terms' => false,
				'has_woocommerce'  => false,
				'has_support_terms' => false,
				'kb_available'     => false,
				'pending_comments' => 0,
			)
		);

		$slugs = array_column( $opportunities, 'blueprint_slug');
		$this->assertContains( 'form-followup', $slugs );
		$this->assertContains( 'quote-agent', $slugs );
		$this->assertContains( 'lead-concierge', $slugs );
	}

	public function test_woocommerce_signal_recommends_ecommerce_recovery(): void {
		$opportunities = OpenclaWP_Automation_Audit::score_opportunities(
			array(
				'has_forms'        => false,
				'has_quote_terms'  => false,
				'has_contact_terms' => false,
				'has_service_terms' => false,
				'has_booking_terms' => false,
				'has_woocommerce'  => true,
				'has_support_terms' => false,
				'kb_available'     => false,
				'pending_comments' => 0,
			)
		);

		$this->assertSame( 'ecommerce-recovery', $opportunities[0]['blueprint_slug'] );
		$this->assertGreaterThanOrEqual( 90, $opportunities[0]['score'] );
	}

	public function test_has_plugin_like_detects_common_form_plugins(): void {
		$this->assertTrue(
			OpenclaWP_Automation_Audit::has_plugin_like(
				array( 'wpforms-lite/wpforms.php' ),
				array( 'wpforms' )
			)
		);
		$this->assertFalse(
			OpenclaWP_Automation_Audit::has_plugin_like(
				array( 'akismet/akismet.php' ),
				array( 'wpforms' )
			)
		);
	}
}
