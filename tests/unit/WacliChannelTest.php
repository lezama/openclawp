<?php
/**
 * Pure-PHP tests for OpenclaWP_Wacli_Channel's option-resolution logic.
 *
 * The full validate() / handle() path needs WP loaded — see tests/smoke.php.
 *
 * @package OpenclaWP\Tests
 */

declare( strict_types=1 );

namespace OpenclaWP\Tests\Unit;

use OpenclaWP_Wacli_Channel;
use PHPUnit\Framework\TestCase;

/**
 * @covers OpenclaWP_Wacli_Channel::resolve_self_message_mode
 */
final class WacliChannelTest extends TestCase {

	public function test_explicit_block_mode_wins(): void {
		$this->assertSame(
			OpenclaWP_Wacli_Channel::MODE_BLOCK,
			OpenclaWP_Wacli_Channel::resolve_self_message_mode( 'block', false )
		);
	}

	public function test_explicit_allow_mode_wins(): void {
		$this->assertSame(
			OpenclaWP_Wacli_Channel::MODE_ALLOW,
			OpenclaWP_Wacli_Channel::resolve_self_message_mode( 'allow', false )
		);
	}

	public function test_explicit_only_mode_wins(): void {
		$this->assertSame(
			OpenclaWP_Wacli_Channel::MODE_ONLY,
			OpenclaWP_Wacli_Channel::resolve_self_message_mode( 'only', false )
		);
	}

	public function test_unknown_mode_falls_through_to_legacy_default(): void {
		$this->assertSame(
			OpenclaWP_Wacli_Channel::MODE_BLOCK,
			OpenclaWP_Wacli_Channel::resolve_self_message_mode( 'gibberish', false )
		);
	}

	public function test_legacy_allow_boolean_promotes_to_allow_mode_when_enum_is_unset(): void {
		$this->assertSame(
			OpenclaWP_Wacli_Channel::MODE_ALLOW,
			OpenclaWP_Wacli_Channel::resolve_self_message_mode( '', true )
		);
	}

	public function test_no_options_set_defaults_to_block(): void {
		$this->assertSame(
			OpenclaWP_Wacli_Channel::MODE_BLOCK,
			OpenclaWP_Wacli_Channel::resolve_self_message_mode( '', false )
		);
	}

	// ─── extract_user_id / is_self_chat ───────────────────────────────────

	public function test_extract_user_id_strips_device_suffix(): void {
		$this->assertSame(
			'269028278943744',
			OpenclaWP_Wacli_Channel::extract_user_id( '269028278943744:83@lid' )
		);
	}

	public function test_extract_user_id_strips_at_domain(): void {
		$this->assertSame(
			'269028278943744',
			OpenclaWP_Wacli_Channel::extract_user_id( '269028278943744@lid' )
		);
	}

	public function test_extract_user_id_keeps_group_compound_id(): void {
		// Groups use `<creator>-<created_ts>@g.us` and that whole compound
		// is the group's user id; we shouldn't split it.
		$this->assertSame(
			'5491155934137-1631880971',
			OpenclaWP_Wacli_Channel::extract_user_id( '5491155934137-1631880971@g.us' )
		);
	}

	public function test_is_self_chat_true_for_message_yourself(): void {
		$this->assertTrue(
			OpenclaWP_Wacli_Channel::is_self_chat(
				array(
					'chat_jid'   => '269028278943744@lid',
					'sender_jid' => '269028278943744:83@lid',
				)
			)
		);
	}

	public function test_is_self_chat_false_for_dm_with_other_contact(): void {
		$this->assertFalse(
			OpenclaWP_Wacli_Channel::is_self_chat(
				array(
					'chat_jid'   => '5491155555555@s.whatsapp.net',
					'sender_jid' => '269028278943744:83@lid',
				)
			)
		);
	}

	public function test_is_self_chat_false_for_group_chat_even_if_you_sent_it(): void {
		$this->assertFalse(
			OpenclaWP_Wacli_Channel::is_self_chat(
				array(
					'chat_jid'   => '5491155934137-1631880971@g.us',
					'sender_jid' => '269028278943744:83@lid',
				)
			)
		);
	}

	public function test_is_self_chat_false_when_jids_missing(): void {
		$this->assertFalse( OpenclaWP_Wacli_Channel::is_self_chat( array() ) );
		$this->assertFalse( OpenclaWP_Wacli_Channel::is_self_chat( array( 'chat_jid' => 'a@lid' ) ) );
		$this->assertFalse( OpenclaWP_Wacli_Channel::is_self_chat( array( 'sender_jid' => 'a@lid' ) ) );
	}

	public function test_is_self_chat_reads_pascalcase_keys_too(): void {
		$this->assertTrue(
			OpenclaWP_Wacli_Channel::is_self_chat(
				array(
					'Chat'      => '269028278943744@lid',
					'SenderJID' => '269028278943744:83@lid',
				)
			)
		);
	}

	// ─── resolve_self_message_mode (continued) ────────────────────────────

	public function test_explicit_enum_overrides_legacy_boolean(): void {
		// Even if a stale `allow_self_messages=1` is on the books, the new
		// enum value is authoritative. Avoids two opposing knobs disagreeing.
		$this->assertSame(
			OpenclaWP_Wacli_Channel::MODE_ONLY,
			OpenclaWP_Wacli_Channel::resolve_self_message_mode( 'only', true )
		);
		$this->assertSame(
			OpenclaWP_Wacli_Channel::MODE_BLOCK,
			OpenclaWP_Wacli_Channel::resolve_self_message_mode( 'block', true )
		);
	}

	// ─── extract_reply_from_run ───────────────────────────────────────────

	public function test_workflow_reply_prefers_explicit_reply_field(): void {
		$run = array(
			'output' => array(
				'last' => array(
					'reply'   => 'hello from the workflow',
					'message' => 'should not win — reply takes precedence',
				),
			),
		);
		$this->assertSame( 'hello from the workflow', OpenclaWP_Wacli_Channel::extract_reply_from_run( $run ) );
	}

	public function test_workflow_reply_falls_back_to_message_then_text_then_value(): void {
		$with_message = array( 'output' => array( 'last' => array( 'message' => 'msg-shape' ) ) );
		$this->assertSame( 'msg-shape', OpenclaWP_Wacli_Channel::extract_reply_from_run( $with_message ) );

		$with_text = array( 'output' => array( 'last' => array( 'text' => 'text-shape' ) ) );
		$this->assertSame( 'text-shape', OpenclaWP_Wacli_Channel::extract_reply_from_run( $with_text ) );

		$with_value = array( 'output' => array( 'last' => array( 'value' => 'value-shape' ) ) );
		$this->assertSame( 'value-shape', OpenclaWP_Wacli_Channel::extract_reply_from_run( $with_value ) );
	}

	public function test_workflow_reply_empty_when_no_recognised_field(): void {
		$run = array(
			'output' => array(
				'last' => array( 'thing' => 'unknown shape' ),
			),
		);
		$this->assertSame( '', OpenclaWP_Wacli_Channel::extract_reply_from_run( $run ) );
	}

	public function test_workflow_reply_empty_when_no_last_step_succeeded(): void {
		// Runner omits `last` when the final step failed (continue_on_error mode).
		$run = array(
			'output' => array(
				'steps' => array( 'first' => array( 'value' => 'x' ) ),
				// no `last` key
			),
		);
		$this->assertSame( '', OpenclaWP_Wacli_Channel::extract_reply_from_run( $run ) );
	}

	public function test_workflow_reply_empty_when_run_is_skeletal(): void {
		$this->assertSame( '', OpenclaWP_Wacli_Channel::extract_reply_from_run( array() ) );
		$this->assertSame( '', OpenclaWP_Wacli_Channel::extract_reply_from_run( array( 'output' => array() ) ) );
	}

	public function test_workflow_reply_coerces_scalars_to_string(): void {
		$run = array( 'output' => array( 'last' => array( 'value' => 42 ) ) );
		$this->assertSame( '42', OpenclaWP_Wacli_Channel::extract_reply_from_run( $run ) );

		$run = array( 'output' => array( 'last' => array( 'value' => true ) ) );
		$this->assertSame( '1', OpenclaWP_Wacli_Channel::extract_reply_from_run( $run ) );
	}
}
