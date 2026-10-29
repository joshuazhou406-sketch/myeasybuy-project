<?php
/**
 * Admin Setup - registers WC Admin pages and enqueues compiled assets.
 *
 * @package ProductUploadForWoocommerce\Admin
 */

namespace ProductUploadForWoocommerce\Admin;

use ProductUploadForWoocommerce\Api\ApiClient;
use ProductUploadForWoocommerce\Api\OAuthHandler;

defined('ABSPATH') || exit;

/**
 * Setup class.
 *
 * @since 1.0.0
 */
class Setup
{

	/** @var ApiClient */
	private $api;

	/**
	 * @param ApiClient $api API client instance.
	 */
	public function __construct(ApiClient $api)
	{
		$this->api = $api;
		add_action('admin_enqueue_scripts', array($this, 'register_scripts'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_products_list_button'));
		add_action('admin_menu', array($this, 'register_pages'));
		add_action('admin_notices', array($this, 'maybe_render_product_editor_banner'));
		add_action('admin_post_pu_dismiss_banner', array($this, 'handle_dismiss_banner'));
	}

	/**
	 * Enqueue compiled JS + CSS on WC Admin pages only.
	 */
	public function register_scripts()
	{
		if (
		!method_exists('Automattic\\WooCommerce\\Admin\\PageController', 'is_admin_or_embed_page')
		|| !\Automattic\WooCommerce\Admin\PageController::is_admin_or_embed_page()
		) {
			return;
		}
		$this->enqueue_entry('import');
	}

	/**
	 * Enqueue a single webpack entry point.
	 *
	 * @param string $entry Entry name matching a key in webpack.config.js.
	 */
	private function enqueue_entry($entry)
	{
		$handle = 'product-upload-' . $entry;
		$build_dir = PU_WC_DIR . 'build/';
		$build_url = PU_WC_URL . 'build/';
		$asset_file = $build_dir . $entry . '.asset.php';

		$default_asset = array(
			'dependencies' => array(),
			'version' => PU_WC_VERSION,
		);

		// Validate the resolved path stays inside the plugin build directory
		// to satisfy static analysis (semgrep file-inclusion-arg).
		$real_asset = realpath($asset_file);
		$real_build = realpath($build_dir);
		if ($real_asset && $real_build && 0 === strpos($real_asset, $real_build . DIRECTORY_SEPARATOR)) {
			$asset = require $real_asset;
		}
		else {
			$asset = $default_asset;
		}

		wp_register_script(
			$handle,
			$build_url . $entry . '.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_localize_script($handle, 'puWCAdmin', $this->get_script_data());

		if (file_exists($build_dir . $entry . '.css')) {
			wp_register_style(
				$handle,
				$build_url . $entry . '.css',
				array('wc-components'),
				$asset['version']
			);
			wp_enqueue_style($handle);
		}

		wp_enqueue_script($handle);
	}

	/**
	 * Build the window.puWCAdmin object passed to the React page.
	 *
	 * Auto-activates only for sites that have completed the OAuth handshake.
	 * Fresh installs land in `needsConnect: true` so the React app can show
	 * a Connect CTA.
	 *
	 * @return array<string, mixed>
	 */
	private function get_script_data()
	{
		$is_oauth_connected = OAuthHandler::is_connected();

		$connected = false;
		$token_data = array();

		if ($is_oauth_connected) {
			$result = $this->api->activate();
			$connected = !is_wp_error($result);
			$token_data = $connected ? $this->api->get_stored_token_data() : array();
		}

		// Surface any error from the OAuth callback in the URL (`pu_connect_error=...`)
		// so the React app can show a meaningful message.
		$connect_error = isset($_GET['pu_connect_error']) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? sanitize_text_field(wp_unslash($_GET['pu_connect_error'])) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: '';
		$just_connected = isset($_GET['pu_connected']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$just_disconnected = isset($_GET['pu_disconnected']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return array(
			'apiUrl' => rest_url('product-upload/v1/'),
			'nonce' => wp_create_nonce('wp_rest'),
			'connected' => $connected,
			'connectionData' => $token_data,
			'needsConnect' => !$is_oauth_connected,
			'oauthConnected' => $is_oauth_connected,
			'connectUrl' => OAuthHandler::start_url(),
			'disconnectUrl' => OAuthHandler::disconnect_url(),
			'connectError' => $connect_error,
			'justConnected' => $just_connected,
			'justDisconnected' => $just_disconnected,
			'adminPostUrl' => admin_url('post.php'),
			'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '',
			'version' => PU_WC_VERSION,
		);
	}

	/**
	 * Register admin pages via wc_admin_register_page().
	 */
	public function register_pages()
	{
		if (!function_exists('wc_admin_register_page')) {
			return;
		}

		$title = __('Product Upload', 'product-upload');
		$import_url = $this->get_import_url();

		// Register the React route in the WC Admin page controller. Some WooCommerce
		// versions construct the WordPress menu slug as "wc-admin/product-upload"
		// which produces a broken URL - we fix that below.
		wc_admin_register_page(
			array(
			'id' => 'product-upload',
			'title' => $title,
			'parent' => 'woocommerce',
			'path' => '/product-upload',
		)
		);

		// Fix the broken URL that wc_admin_register_page may have written into the
		// WooCommerce submenu, replacing it with the correct WC Admin path URL.
		global $submenu;
		if (isset($submenu['woocommerce'])) {
			foreach ($submenu['woocommerce'] as &$item) {
				if (isset($item[0]) && $item[0] === $title) {
					$item[2] = $import_url;
					break;
				}
			}
			unset($item);
		}

		// Also surface the page under Products → Import Products.
		$submenu['woocommerce-product'][] = array(
			__('Import Products', 'product-upload'),
			'manage_woocommerce',
			$import_url,
		);
	}

	/**
	 * Add an "Import Products" button next to "Add New" on the Products list
	 * screen. Enqueued as an inline script on a registered (sourceless) handle
	 * rather than echoed directly, so no raw <script> is printed.
	 */
	public function enqueue_products_list_button()
	{
		$screen = get_current_screen();
		if (!$screen || 'edit-product' !== $screen->id) {
			return;
		}

		$handle = 'product-upload-products-list-button';
		wp_register_script($handle, false, array(), PU_WC_VERSION, true);
		wp_enqueue_script($handle);

		$data = wp_json_encode(array(
			'label' => __('Import products', 'product-upload'),
			'href' => $this->get_import_url(),
		));

		wp_add_inline_script(
			$handle,
			'( function() {' .
			'var d = ' . $data . ';' .
			'if ( ! d.href ) { return; }' .
			'var btn = document.createElement( "a" );' .
			'btn.href = d.href;' .
			'btn.className = "page-title-action";' .
			'btn.textContent = d.label;' .
			'var anchor = document.querySelector( ".page-title-action" );' .
			'if ( anchor && anchor.parentNode ) { anchor.parentNode.insertBefore( btn, anchor.nextSibling ); }' .
			'} )();'
		);
	}

	/**
	 * Render a dismissible banner on the Add New Product screen encouraging
	 * the user to import from a supplier URL instead of filling in manually.
	 */
	public function maybe_render_product_editor_banner()
	{
		global $pagenow;

		$screen = get_current_screen();
		if (!$screen || 'product' !== $screen->id || 'post-new.php' !== $pagenow) {
			return;
		}

		if (get_user_meta(get_current_user_id(), 'pu_wc_banner_dismissed', true)) {
			return;
		}

		$import_url = $this->get_import_url();
		$dismiss_url = wp_nonce_url(
			admin_url('admin-post.php?action=pu_dismiss_banner'),
			'pu_dismiss_banner'
		);
?>
		<div class="notice notice-info" style="display:flex;align-items:center;gap:16px;padding:10px 16px;">
			<span style="flex:1;margin:0;">
				<?php esc_html_e('Save time - ', 'product-upload'); ?>
				<a href="<?php echo esc_url($import_url); ?>">
					<?php esc_html_e('import products with Product Upload', 'product-upload'); ?>
				</a>
				<?php esc_html_e(' and let AI fill in the title, description, images and variants for you.', 'product-upload'); ?>
			</span>
			<a href="<?php echo esc_url($dismiss_url); ?>" style="white-space:nowrap;text-decoration:none;color:#646970;font-size:13px;">
				<?php esc_html_e('Dismiss', 'product-upload'); ?>
			</a>
		</div>
		<?php
	}

	/**
	 * Persist the banner dismissal against the current user and redirect back.
	 */
	public function handle_dismiss_banner()
	{
		check_admin_referer('pu_dismiss_banner');
		if (!current_user_can('edit_products')) {
			wp_die(esc_html__('You do not have permission to do that.', 'product-upload'), 403);
		}
		update_user_meta(get_current_user_id(), 'pu_wc_banner_dismissed', true);
		wp_safe_redirect(wp_get_referer() ?: admin_url('post-new.php?post_type=product'));
		exit;
	}

	/**
	 * Return the URL for the Product Upload WC Admin page.
	 *
	 * @return string
	 */
	private function get_import_url()
	{
		return admin_url('admin.php?page=wc-admin&path=/product-upload');
	}
}