<?php
/**
 * Plugin Name:       MyEasyBuy Online Service
 * Plugin URI:        https://myeasybuy.ca/
 * Description:       Keyword-based customer help widget (free retrieval, no paid AI). Quotes policy clauses verbatim.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            MyEasyBuy
 * Text Domain:       myeasybuy-online-service
 *
 * @package MyEasyBuy_Online_Service
 */

defined( 'ABSPATH' ) || exit;

define( 'MEB_OS_VERSION', '1.0.0' );
define( 'MEB_OS_FILE', __FILE__ );
define( 'MEB_OS_DIR', plugin_dir_path( __FILE__ ) );
define( 'MEB_OS_URL', plugin_dir_url( __FILE__ ) );

/**
 * Default mascot: B, the fluffy big-eyes beaver with a cart and headset.
 * Swap later by changing this constant or the `myeasybuy_os_mascot` option.
 */
if ( ! defined( 'MEB_OS_MASCOT' ) ) {
	define( 'MEB_OS_MASCOT', 'beaver' );
}

if ( ! defined( 'MEB_OS_CONTACT_EMAIL' ) ) {
	define( 'MEB_OS_CONTACT_EMAIL', 'joshuazhou406@gmail.com' );
}

require_once MEB_OS_DIR . 'includes/class-meb-os-kb.php';
require_once MEB_OS_DIR . 'includes/class-meb-os-plugin.php';

add_action( 'plugins_loaded', array( 'MEB_OS_Plugin', 'init' ) );
