<?php
/**
 * Plugin bootstrap.
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

final class OpenclaWP_Bootstrap {

	private static bool $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		if ( ! self::has_required_dependencies() ) {
			add_action( 'admin_notices', array( __CLASS__, 'render_missing_dependencies_notice' ) );
			return;
		}

		// Register hooks. Class files load lazily through includes/autoload.php
		// the first time PHP needs to construct or call into a class.
		add_action( 'init', array( 'OpenclaWP_Conversation_Store', 'register_post_type' ), 5 );
		add_action( 'init', array( __CLASS__, 'register_blocks' ), 10 );
		OpenclaWP_Agent_Registrar::register();
		OpenclaWP_Abilities::register();
		OpenclaWP_Event_Sink::register();
		OpenclaWP_Rest::register();
		OpenclaWP_Canonical_Chat_Handler::register();
		OpenclaWP_Workflow_Bootstrap::register();
		OpenclaWP_Wacli_Transport::register();
		OpenclaWP_Wacli_Rest::register();
		if ( is_admin() ) {
			OpenclaWP_Admin::register();
			OpenclaWP_Channels_Admin::register();
			OpenclaWP_Wacli_Admin::register();
		}

		/**
		 * Whether to register the WhatsApp Cloud API ingress (REST webhook +
		 * outbound sender + settings page).
		 *
		 * Off by default. Opt in with `add_filter( 'openclawp_register_whatsapp', '__return_true' )`
		 * and configure credentials at openclaWP → WhatsApp.
		 *
		 * @param bool $enabled Default false.
		 */
		if ( apply_filters( 'openclawp_register_whatsapp', false ) ) {
			OpenclaWP_Whatsapp::register();
		}
	}

	public static function register_blocks(): void {
		register_block_type( OPENCLAWP_PATH . 'blocks/chat' );

		// The block's view.js depends on wp.apiFetch; declare that and inject
		// the nonce + REST namespace via wp_localize_script when the script is
		// enqueued (block.json's viewScript registers as `openclawp-chat-view-script`).
		add_action(
			'wp_enqueue_scripts',
			array( __CLASS__, 'localize_chat_block_view_script' ),
			15
		);
		add_action(
			'admin_enqueue_scripts',
			array( __CLASS__, 'localize_chat_block_view_script' ),
			15
		);
	}

	public static function localize_chat_block_view_script(): void {
		$handle = 'openclawp-chat-view-script';
		if ( ! wp_script_is( $handle, 'registered' ) ) {
			return;
		}
		wp_localize_script(
			$handle,
			'openclaWPConfig',
			array(
				'restNamespace' => 'openclawp/v1',
				'nonce'         => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	private static function has_required_dependencies(): bool {
		return defined( 'AGENTS_API_LOADED' ) && function_exists( 'wp_register_agent' );
	}

	public static function render_missing_dependencies_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$missing = array();
		if ( ! defined( 'AGENTS_API_LOADED' ) ) {
			$missing[] = '<a href="https://github.com/Automattic/agents-api">automattic/agents-api</a>';
		}
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			$missing[] = 'WordPress 7.0+ (provides <code>wp_ai_client_prompt()</code>)';
		}

		if ( empty( $missing ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s %s</p></div>',
			esc_html__( 'openclaWP cannot start.', 'openclawp' ),
			esc_html__( 'Missing required dependencies:', 'openclawp' ),
			wp_kses(
				implode( ', ', $missing ),
				array(
					'a'    => array( 'href' => array() ),
					'code' => array(),
				)
			)
		);
	}
}
