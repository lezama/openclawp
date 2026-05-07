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

		require_once OPENCLAWP_PATH . 'includes/class-openclawp-conversation-store.php';
		require_once OPENCLAWP_PATH . 'includes/class-openclawp-agent-registrar.php';
		require_once OPENCLAWP_PATH . 'includes/class-openclawp-message-adapter.php';
		require_once OPENCLAWP_PATH . 'includes/class-openclawp-runner.php';
		require_once OPENCLAWP_PATH . 'includes/class-openclawp-rest.php';
		require_once OPENCLAWP_PATH . 'includes/class-openclawp-abilities.php';
		require_once OPENCLAWP_PATH . 'includes/class-openclawp-event-sink.php';
		require_once OPENCLAWP_PATH . 'includes/class-openclawp-admin.php';

		add_action( 'init', array( 'OpenclaWP_Conversation_Store', 'register_post_type' ), 5 );
		OpenclaWP_Agent_Registrar::register();
		OpenclaWP_Abilities::register();
		OpenclaWP_Event_Sink::register();
		OpenclaWP_Rest::register();
		OpenclaWP_Admin::register();
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
