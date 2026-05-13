<?php
/**
 * Plugin Name: openclaWP
 * Plugin URI: https://github.com/lezama/openclawp
 * Description: Generic chat-with-an-agent WordPress plugin built on Automattic/agents-api. Registers an example agent and exposes REST + admin chat surfaces; downstream plugins register additional agents.
 * Version: 0.1.0
 * Author: Miguel Lezama
 * Author URI: https://github.com/lezama
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: openclawp
 * Requires PHP: 8.1
 * Requires at least: 6.7
 * Requires Plugins: agents-api
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
	$openclawp_agents_api_bootstrap = __DIR__ . '/vendor/automattic/agents-api/agents-api.php';
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

add_action( 'plugins_loaded', array( 'OpenclaWP_Bootstrap', 'init' ), 20 );
