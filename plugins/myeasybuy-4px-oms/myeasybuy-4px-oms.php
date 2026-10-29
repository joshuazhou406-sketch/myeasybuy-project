<?php
/**
 * Plugin Name:       MyEasyBuy 4PX 集运 OMS
 * Plugin URI:        https://myeasybuy.local
 * Description:       按集运步骤对接递四方/菜鸟 OMS：一段预报、二段合包出库、仓内节点回传，并接入代购与转运流程。
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Author:            MyEasyBuy
 * Text Domain:       meb-oms
 * Domain Path:       /languages
 *
 * @package MyEasyBuy_OMS
 */

defined( 'ABSPATH' ) || exit;

define( 'MEB_OMS_VERSION', '1.0.0' );
define( 'MEB_OMS_FILE', __FILE__ );
define( 'MEB_OMS_DIR', plugin_dir_path( __FILE__ ) );
define( 'MEB_OMS_URL', plugin_dir_url( __FILE__ ) );
define( 'MEB_OMS_BASENAME', plugin_basename( __FILE__ ) );

require_once MEB_OMS_DIR . 'includes/class-activator.php';
require_once MEB_OMS_DIR . 'includes/class-repository.php';
require_once MEB_OMS_DIR . 'includes/class-client.php';
require_once MEB_OMS_DIR . 'includes/class-service.php';
require_once MEB_OMS_DIR . 'includes/class-hooks.php';
require_once MEB_OMS_DIR . 'includes/class-callback.php';
require_once MEB_OMS_DIR . 'includes/class-admin.php';
require_once MEB_OMS_DIR . 'includes/class-frontend.php';

/**
 * Bootstrap the OMS consolidation plugin.
 */
final class MyEasyBuy_OMS {

	/**
	 * Singleton.
	 *
	 * @var MyEasyBuy_OMS|null
	 */
	private static ?MyEasyBuy_OMS $instance = null;

	/**
	 * Instance.
	 */
	public static function instance(): MyEasyBuy_OMS {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		register_activation_hook( MEB_OMS_FILE, array( MyEasyBuy_OMS_Activator::class, 'activate' ) );
		register_deactivation_hook( MEB_OMS_FILE, array( MyEasyBuy_OMS_Activator::class, 'deactivate' ) );
		add_action( 'plugins_loaded', array( $this, 'init' ), 30 );
	}

	/**
	 * Load components after WooCommerce.
	 */
	public function init(): void {
		load_plugin_textdomain( 'meb-oms', false, dirname( MEB_OMS_BASENAME ) . '/languages' );

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
			return;
		}

		MyEasyBuy_OMS_Activator::maybe_upgrade();
		MyEasyBuy_OMS_Hooks::init();
		MyEasyBuy_OMS_Callback::init();
		MyEasyBuy_OMS_Admin::init();
		MyEasyBuy_OMS_Frontend::init();

		/**
		 * Fires when the 4PX OMS plugin is ready.
		 *
		 * @since 1.0.0
		 */
		do_action( 'myeasybuy_oms_loaded' );
	}

	/**
	 * WooCommerce missing notice.
	 */
	public function woocommerce_missing_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'MyEasyBuy 4PX 集运 OMS 需要先启用 WooCommerce。', 'meb-oms' )
		);
	}
}

MyEasyBuy_OMS::instance();
