<?php
/**
 * Plugin Name: Product Upload: AI Dropshipping & Product Importer for WooCommerce
 * Plugin URI:  https://productupload.co
 * Description: Import products into WooCommerce with AI: titles, descriptions, variants, images, SEO. Search supported marketplaces by keyword, no CSV required. Not affiliated with any marketplace.
 * Version:     1.4.5
 * Author:      Harrison Bay
 * Author URI:  https://www.bay.work
 * Text Domain: product-upload
 * Domain Path: /languages
 *
 * WC requires at least: 7.0
 * WC tested up to: 10.0
 * Requires at least: 6.2
 * Tested up to: 7.1
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package ProductUploadForWoocommerce
 */

defined('ABSPATH') || exit;

define('PU_WC_MAIN_PLUGIN_FILE', __FILE__);
define('PU_WC_VERSION', '1.4.5');
define('PU_WC_DIR', plugin_dir_path(__FILE__));
define('PU_WC_URL', plugin_dir_url(__FILE__));
define('PU_WC_API_BASE', 'https://productupload.co/api/external/');

$pu_wc_autoloader = PU_WC_DIR . 'vendor/autoload_packages.php';
if (file_exists($pu_wc_autoloader)) {
	require_once $pu_wc_autoloader;
} else {
	spl_autoload_register(
		function ($class) {
			$prefix = 'ProductUploadForWoocommerce\\';
			if (0 !== strpos($class, $prefix)) {
				return;
			}
			$relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
			$file = PU_WC_DIR . 'includes/' . $relative . '.php';
			if (file_exists($file)) {
				require_once $file;
			}
		}
	);
}

// HPOS + Cart/Checkout Blocks compatibility.
add_action(
	'before_woocommerce_init',
	function () {
		if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
		}
	}
);

// Self-hosted update checker for the direct-download (full) build. The
// WordPress.org build excludes vendor/ and strips this whole block via
// scripts/build-plugin.mjs (between the markers), so wp.org serves updates
// itself and the static scanner sees no updater code.

function pu_wc_missing_wc_notice()
{
	echo '<div class="error"><p><strong>'
		. sprintf(
			/* translators: %s: WooCommerce download link. */
			esc_html__('Product Upload requires WooCommerce to be installed and active. You can download %s here.', 'product-upload'),
			'<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>'
		)
		. '</strong></p></div>';
}

if (!class_exists('ProductUploadForWoocommerce')):

	class ProductUploadForWoocommerce
	{

		/** @var ProductUploadForWoocommerce|null */
		private static $instance = null;

		/** @var \ProductUploadForWoocommerce\Api\ApiClient */
		public $api;

		/** @var \ProductUploadForWoocommerce\Import\ImageHandler */
		public $images;

		/** @var \ProductUploadForWoocommerce\Import\ProductImporter */
		public $importer;

		public static function instance()
		{
			if (null === self::$instance) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		private function __construct()
		{
			$this->api = new \ProductUploadForWoocommerce\Api\ApiClient();
			$this->images = new \ProductUploadForWoocommerce\Import\ImageHandler();
			$this->importer = new \ProductUploadForWoocommerce\Import\ProductImporter($this->api, $this->images);

			if (is_admin()) {
				new \ProductUploadForWoocommerce\Admin\Setup($this->api);
				add_action(
					'admin_init',
					array('\ProductUploadForWoocommerce\Import\ProductImporter', 'backfill_source_keys')
				);
			}

			new \ProductUploadForWoocommerce\RestApi\ImportController($this->api, $this->importer);
			new \ProductUploadForWoocommerce\Api\OAuthHandler();

			$drain = new \ProductUploadForWoocommerce\Import\JobDrain($this->api, $this->importer);
			$drain->register();

			// Signed server->site partial update. Not admin-gated; see the
			// class docblock for why it has to be separate from the queue.
			$writes = new \ProductUploadForWoocommerce\RestApi\ProductWriteController($this->api);
			$writes->register();

			// Signed read + operation-list edit for the AI-assistant connector.
			$edits = new \ProductUploadForWoocommerce\RestApi\ProductEditController($writes, $this->images);
			$edits->register();

			// Front end: blueprint sections stored at import time as tabs.
			$tabs = new \ProductUploadForWoocommerce\Import\ProductTabs();
			$tabs->register();
		}
	}

endif;

// Background-drain cron lifecycle.
register_activation_hook(__FILE__, array('\ProductUploadForWoocommerce\Import\JobDrain', 'activate'));
register_deactivation_hook(__FILE__, array('\ProductUploadForWoocommerce\Import\JobDrain', 'deactivate'));

add_action('plugins_loaded', 'pu_wc_init', 10);

function pu_wc_init()
{
	if (!class_exists('WooCommerce')) {
		add_action('admin_notices', 'pu_wc_missing_wc_notice');
		return;
	}

	ProductUploadForWoocommerce::instance();
}