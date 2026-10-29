<?php
/**
 * Plugin Name:       WooCommerce Reverse Forwarding
 * Plugin URI:        https://myeasybuy.local
 * Description:       China warehouse consolidation and parcel forwarding to Canada, as a standalone WooCommerce extension.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Author:            MyEasyBuy
 * Text Domain:       wc-rf
 * Domain Path:       /languages
 * WC requires at least: 8.0
 *
 * @package ReverseForwarding
 */

defined( 'ABSPATH' ) || exit;

define( 'WC_RF_VERSION', '1.0.0' );
define( 'WC_RF_PLUGIN_FILE', __FILE__ );
define( 'WC_RF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WC_RF_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * PSR-4 autoloader: ReverseForwarding\ → includes/, WordPress class-*.php filenames.
 *
 * ReverseForwarding\Activator → includes/class-activator.php
 * ReverseForwarding\Admin\Wms → includes/Admin/class-wms.php
 *
 * @param string $class Fully-qualified class name.
 */
function wc_rf_autoload( string $class ): void {
	$prefix = 'ReverseForwarding\\';

	if ( ! str_starts_with( $class, $prefix ) ) {
		return;
	}

	$relative   = substr( $class, strlen( $prefix ) );
	$segments   = explode( '\\', $relative );
	$class_name = array_pop( $segments );

	$normalized = str_replace( '_', '', $class_name );
	$kebab      = strtolower( (string) preg_replace( '/([a-z0-9])([A-Z])/', '$1-$2', $normalized ) );
	$file       = 'class-' . $kebab . '.php';

	$directory = WC_RF_PLUGIN_DIR . 'includes/';
	if ( array() !== $segments ) {
		$directory .= implode( '/', $segments ) . '/';
	}

	$path = $directory . $file;

	if ( is_readable( $path ) ) {
		require_once $path;
	}
}

spl_autoload_register( 'wc_rf_autoload' );

register_activation_hook( WC_RF_PLUGIN_FILE, array( \ReverseForwarding\Activator::class, 'activate' ) );
register_deactivation_hook( WC_RF_PLUGIN_FILE, array( \ReverseForwarding\Activator::class, 'deactivate' ) );
add_action( 'plugins_loaded', 'wc_rf_bootstrap' );

/**
 * Boot the plugin after WordPress (and optionally WooCommerce) has loaded.
 */
function wc_rf_bootstrap(): void {
	load_plugin_textdomain(
		'wc-rf',
		false,
		dirname( WC_RF_PLUGIN_BASENAME ) . '/languages'
	);

	if ( ! wc_rf_is_woocommerce_active() ) {
		add_action( 'admin_notices', 'wc_rf_woocommerce_missing_notice' );
		return;
	}

	\ReverseForwarding\Activator::maybe_upgrade();
	\ReverseForwarding\Frontend\Forwarding_Center::init();
	\ReverseForwarding\Frontend\Package_Actions::init();
	\ReverseForwarding\Checkout\Cart_Integration::init();
	\ReverseForwarding\Admin\Warehouse_Wms::init();
	\ReverseForwarding\Shipping\Four_Px::init();
}

/**
 * Whether WooCommerce is available.
 */
function wc_rf_is_woocommerce_active(): bool {
	return class_exists( 'WooCommerce' );
}

/**
 * Admin warning when WooCommerce is not active. Stops further plugin load.
 */
function wc_rf_woocommerce_missing_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'WooCommerce Reverse Forwarding requires WooCommerce to be installed and active.', 'wc-rf' )
	);
}
