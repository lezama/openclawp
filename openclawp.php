<?php
/**
 * Plugin Name: openclaWP
 * Plugin URI: https://github.com/lezama/openclawp
 * Description: Run WordPress-native agents in chat blocks, admin screens, REST endpoints, workflows, and messaging channels.
 * Version: 0.1.0
 * Author: Miguel Lezama
 * Author URI: https://github.com/lezama
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: openclawp
 * Requires PHP: 8.1
 * Requires at least: 7.0
 * Tested up to: 7.0
 *
 * @package OpenclaWP
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'OPENCLAWP_LOADED' ) ) {
	return;
}

define( 'OPENCLAWP_LOADED', true );
define( 'OPENCLAWP_VERSION', '0.1.0' );
define( 'OPENCLAWP_PATH', __DIR__ . '/' );
define( 'OPENCLAWP_PLUGIN_FILE', __FILE__ );

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

if ( ! defined( 'AGENTS_API_LOADED' ) ) {
	$openclawp_agents_api_bootstrap = __DIR__ . '/vendor/wordpress/agents-api/agents-api.php';
	if ( file_exists( $openclawp_agents_api_bootstrap ) ) {
		require_once $openclawp_agents_api_bootstrap;
	}
}

// Boot Action Scheduler before agents-api hooks try to schedule anything.
// AS is a vendored composer dep; if a host site already has it (WooCommerce,
// other AS-using plugin), the global function check inside the bridge sees
// it and we no-op cleanly.
if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
	$openclawp_action_scheduler = __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';
	if ( file_exists( $openclawp_action_scheduler ) ) {
		require_once $openclawp_action_scheduler;
	}
}

require_once OPENCLAWP_PATH . 'includes/autoload.php';

// Activation: install the knowledge-base table. Other CPT-backed stores
// rely on WordPress's built-in post tables and don't need this; the KB
// uses a bespoke `wp_openclawp_kb` table with a FULLTEXT index for
// MATCH ... AGAINST search.
register_activation_hook( __FILE__, array( 'OpenclaWP_Knowledge_Base_Schema', 'install' ) );

add_action( 'plugins_loaded', array( 'OpenclaWP_Bootstrap', 'init' ), 20 );
