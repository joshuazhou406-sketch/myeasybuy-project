<?php
/**
 * Plugin Name:       MyEasyBuy Core
 * Plugin URI:        https://myeasybuy.local
 * Description:       Reverse-proxy shopping core for myeasybuy — order statuses, hooks, and REST API for WMS/frontend integration.
 * Version:           1.19.1
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            MyEasyBuy
 * Text Domain:       myeasybuy-core
 * Domain Path:       /languages
 *
 * @package MyEasyBuy_Core
 */

defined( 'ABSPATH' ) || exit;

define( 'MYEASYBUY_CORE_VERSION', '1.19.1' );
define( 'MYEASYBUY_CORE_FILE', __FILE__ );
define( 'MYEASYBUY_CORE_DIR', plugin_dir_path( __FILE__ ) );
define( 'MYEASYBUY_CORE_URL', plugin_dir_url( __FILE__ ) );

require_once MYEASYBUY_CORE_DIR . 'includes/class-myeasybuy-order-statuses.php';
require_once MYEASYBUY_CORE_DIR . 'includes/class-myeasybuy-hooks.php';
require_once MYEASYBUY_CORE_DIR . 'includes/class-myeasybuy-rest-api.php';
require_once MYEASYBUY_CORE_DIR . 'includes/class-myeasybuy-registration.php';
require_once MYEASYBUY_CORE_DIR . 'includes/class-myeasybuy-login-required.php';
require_once MYEASYBUY_CORE_DIR . 'includes/class-myeasybuy-consolidation.php';
require_once MYEASYBUY_CORE_DIR . 'includes/class-myeasybuy-customer-consolidation.php';
require_once MYEASYBUY_CORE_DIR . 'includes/class-myeasybuy-customer-inbound.php';
require_once MYEASYBUY_CORE_DIR . 'includes/class-myeasybuy-customer-tracking.php';
require_once MYEASYBUY_CORE_DIR . 'includes/class-myeasybuy-customer-dashboard.php';
require_once MYEASYBUY_CORE_DIR . 'includes/class-myeasybuy-customer-cart.php';
require_once MYEASYBUY_CORE_DIR . 'includes/class-myeasybuy-customer-orders.php';
require_once MYEASYBUY_CORE_DIR . 'includes/class-myeasybuy-customer-warehouse.php';
require_once MYEASYBUY_CORE_DIR . 'includes/class-myeasybuy-customer-parcels.php';
require_once MYEASYBUY_CORE_DIR . 'includes/class-myeasybuy-customer-rehearsal.php';
require_once MYEASYBUY_CORE_DIR . 'includes/class-myeasybuy-customer-messages.php';
require_once MYEASYBUY_CORE_DIR . 'includes/class-myeasybuy-customer-profile.php';
require_once MYEASYBUY_CORE_DIR . 'includes/class-myeasybuy-customer-wallet.php';
require_once MYEASYBUY_CORE_DIR . 'includes/class-myeasybuy-4px-service.php';
require_once MYEASYBUY_CORE_DIR . 'includes/class-myeasybuy-procurement.php';
require_once MYEASYBUY_CORE_DIR . 'includes/class-myeasybuy-roles.php';
require_once MYEASYBUY_CORE_DIR . 'admin/class-myeasybuy-admin-workflow.php';
require_once MYEASYBUY_CORE_DIR . 'admin/class-myeasybuy-admin-monitor.php';

/**
 * Main plugin bootstrap.
 */
final class MyEasyBuy_Core {

	/**
	 * Singleton instance.
	 *
	 * @var MyEasyBuy_Core|null
	 */
	private static ?MyEasyBuy_Core $instance = null;

	/**
	 * Get singleton instance.
	 */
	public static function instance(): MyEasyBuy_Core {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'init' ), 20 );
		register_activation_hook( MYEASYBUY_CORE_FILE, array( __CLASS__, 'activate' ) );
		register_deactivation_hook( MYEASYBUY_CORE_FILE, array( __CLASS__, 'deactivate' ) );
	}

	/**
	 * Initialize plugin components after WooCommerce is available.
	 */
	public function init(): void {
		load_plugin_textdomain(
			'myeasybuy-core',
			false,
			dirname( plugin_basename( MYEASYBUY_CORE_FILE ) ) . '/languages'
		);

		if ( ! $this->is_woocommerce_active() ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
			return;
		}

		MyEasyBuy_Order_Statuses::init();
		MyEasyBuy_Hooks::init();
		MyEasyBuy_REST_API::init();
		MyEasyBuy_Registration::init();
		MyEasyBuy_Login_Required::init();
		MyEasyBuy_Consolidation::init();
		MyEasyBuy_Customer_Consolidation::init();
		MyEasyBuy_Customer_Inbound::init();
		MyEasyBuy_Customer_Tracking::init();
		MyEasyBuy_Customer_Dashboard::init();
		MyEasyBuy_Customer_Cart::init();
		MyEasyBuy_Customer_Orders::init();
		MyEasyBuy_Customer_Warehouse::init();
		MyEasyBuy_Customer_Parcels::init();
		MyEasyBuy_Customer_Rehearsal::init();
		MyEasyBuy_Customer_Messages::init();
		MyEasyBuy_Customer_Profile::init();
		MyEasyBuy_Customer_Wallet::init();
		MyEasyBuy_4PX_Service::init();
		MyEasyBuy_Procurement::init();
		MyEasyBuy_Roles::init();
		MyEasyBuy_Admin_Workflow::init();
		MyEasyBuy_Admin_Monitor::init();
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_storefront_palette' ), 100 );

		/**
		 * Fires when myeasybuy-core has finished loading all components.
		 *
		 * @since 1.0.0
		 */
		do_action( 'myeasybuy_core_loaded' );
	}

	/**
	 * Front-end brand palette (deep sea / oat / ink).
	 */
	public static function enqueue_storefront_palette(): void {
		if ( is_admin() ) {
			return;
		}

		wp_enqueue_style(
			'myeasybuy-storefront-palette',
			MYEASYBUY_CORE_URL . 'assets/css/storefront-palette.css',
			array(),
			MYEASYBUY_CORE_VERSION
		);
	}

	/**
	 * Plugin activation routine.
	 */
	public static function activate(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			deactivate_plugins( plugin_basename( MYEASYBUY_CORE_FILE ) );
			wp_die(
				esc_html__( 'MyEasyBuy Core requires WooCommerce to be installed and active.', 'myeasybuy-core' ),
				esc_html__( 'Plugin Activation Error', 'myeasybuy-core' ),
				array( 'back_link' => true )
			);
		}

		MyEasyBuy_Order_Statuses::register_statuses();
		MyEasyBuy_Registration::activate();
		MyEasyBuy_Customer_Consolidation::add_endpoint();
		MyEasyBuy_Customer_Cart::add_endpoint();
		MyEasyBuy_Customer_Warehouse::add_endpoint();
		MyEasyBuy_Customer_Parcels::add_endpoint();
		MyEasyBuy_Customer_Rehearsal::add_endpoint();
		MyEasyBuy_Customer_Messages::add_endpoint();
		MyEasyBuy_Customer_Profile::add_endpoint();
		MyEasyBuy_Customer_Wallet::add_endpoint();
		MyEasyBuy_4PX_Service::activate();
		MyEasyBuy_Procurement::activate();
		MyEasyBuy_Roles::activate();
		flush_rewrite_rules();
		update_option( 'myeasybuy_consolidation_endpoint_flushed', '1.7.0', false );
	}

	/**
	 * Plugin deactivation routine.
	 */
	public static function deactivate(): void {
		MyEasyBuy_4PX_Service::deactivate();
	}

	/**
	 * Whether WooCommerce is active.
	 */
	private function is_woocommerce_active(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Admin notice when WooCommerce is missing.
	 */
	public function woocommerce_missing_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'MyEasyBuy Core requires WooCommerce. Please install and activate WooCommerce.', 'myeasybuy-core' )
		);
	}
}

MyEasyBuy_Core::instance();
