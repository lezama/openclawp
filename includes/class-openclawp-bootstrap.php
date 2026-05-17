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

		OpenclaWP_CLI::register();

		if ( ! self::has_required_dependencies() ) {
			add_action( 'admin_notices', array( __CLASS__, 'render_missing_dependencies_notice' ) );
			return;
		}

		// Register hooks. Class files load lazily through includes/autoload.php
		// the first time PHP needs to construct or call into a class.
		add_action( 'init', array( 'OpenclaWP_Conversation_Store', 'register_post_type' ), 5 );
		add_action( 'init', array( 'OpenclaWP_Usage_Recorder', 'register_post_type' ), 5 );
		add_action( 'init', array( 'OpenclaWP_Mcp_Server_Store', 'register_post_type' ), 5 );
		add_action( 'init', array( 'OpenclaWP_Mcp_Client_Store', 'register_post_type' ), 5 );
		add_action( 'init', array( 'OpenclaWP_Decisions_Store', 'register_post_type' ), 5 );
		add_action( 'init', array( 'OpenclaWP_Custom_Tools_Store', 'register_post_type' ), 5 );
		add_action( 'init', array( __CLASS__, 'register_blocks' ), 10 );
		OpenclaWP_Agent_Registrar::register();
		OpenclaWP_Routine_Registrar::register();
		OpenclaWP_Abilities::register();
		OpenclaWP_Mcp_Client_Bridge::register();
		OpenclaWP_Custom_Tools_Registrar::register();
		OpenclaWP_Event_Sink::register();
		OpenclaWP_Tracer::register();
		OpenclaWP_Usage_Recorder::register();
		// Adapter path (WP 7.0 official mcp-adapter) is always wired —
		// it silently no-ops when the adapter isn't loaded so older sites
		// fall back to the legacy JSON-RPC route below.
		OpenclaWP_Mcp_Adapter::register();
		// Legacy hand-rolled JSON-RPC route. Gated behind OPENCLAWP_MCP_LEGACY
		// so new sites get the official adapter only; existing deployments
		// that already shipped external-client configs can flip the constant
		// on for one minor version while they migrate.
		if ( self::legacy_mcp_enabled() ) {
			OpenclaWP_Mcp_Rest::register();
		}
		OpenclaWP_Rest::register();
		OpenclaWP_Decisions_Rest::register();
		OpenclaWP_Agenttic_Bridge::register();
		OpenclaWP_Canonical_Chat_Handler::register();
		OpenclaWP_Workflow_Bootstrap::register();
		OpenclaWP_Routines_Rest::register();
		if ( is_admin() ) {
			OpenclaWP_Admin::register();
			OpenclaWP_Channels_Admin::register();
			OpenclaWP_Routines_Admin::register();
			OpenclaWP_Usage_Admin::register();
			OpenclaWP_Mcp_Admin::register();
			OpenclaWP_Mcp_Clients_Admin::register();
			OpenclaWP_Settings_Admin::register();
			OpenclaWP_Decisions_Admin::register();
			OpenclaWP_Custom_Tools_Admin::register();
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

		// Inject the REST nonce + bridge URL into the React view bundle. The
		// block.json viewScript registers as `openclawp-chat-view-script`.
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
				'bridgeUrl'     => esc_url_raw( rest_url( 'openclawp/v1/agenttic' ) ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/**
	 * Whether the legacy hand-rolled MCP JSON-RPC endpoint should be wired.
	 *
	 * Defaults to off — WP 7.0 sites get the official mcp-adapter. Sites
	 * that already published external-client configs against the legacy
	 * `/openclawp/v1/mcp/{slug}` route can opt in for one minor version
	 * via the `OPENCLAWP_MCP_LEGACY` constant, the matching environment
	 * variable, or the `openclawp_mcp_legacy_enabled` filter.
	 */
	public static function legacy_mcp_enabled(): bool {
		if ( defined( 'OPENCLAWP_MCP_LEGACY' ) ) {
			return (bool) constant( 'OPENCLAWP_MCP_LEGACY' );
		}
		$env = getenv( 'OPENCLAWP_MCP_LEGACY' );
		if ( false !== $env && '' !== $env ) {
			return in_array( strtolower( (string) $env ), array( '1', 'true', 'yes', 'on' ), true );
		}

		/**
		 * Filter whether to expose the deprecated MCP JSON-RPC endpoint.
		 *
		 * @param bool $enabled Default false.
		 */
		return (bool) apply_filters( 'openclawp_mcp_legacy_enabled', false );
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
