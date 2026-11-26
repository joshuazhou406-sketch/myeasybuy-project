<?php
/**
 * Plugin Name: Product Purchase Workflow
 * Plugin URI:  https://myeasybuy.local
 * Description: Homepage search by keyword, Taobao/Tmall image link, or Taobao/JD product link; open details and sync to WooCommerce.
 * Version:     1.5.15
 * Author:      MyEasyBuy
 * Text Domain: product-purchase-workflow
 * Requires PHP: 8.1
 */

defined( 'ABSPATH' ) || exit;

define( 'PPW_VERSION', '1.5.15' );
define( 'PPW_PLUGIN_FILE', __FILE__ );
define( 'PPW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PPW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once PPW_PLUGIN_DIR . 'includes/class-ppw-platform.php';
require_once PPW_PLUGIN_DIR . 'includes/class-ppw-workflow.php';
require_once PPW_PLUGIN_DIR . 'includes/class-ppw-item-get.php';
require_once PPW_PLUGIN_DIR . 'includes/class-ppw-woocommerce-sync.php';
require_once PPW_PLUGIN_DIR . 'includes/class-ppw-search.php';
require_once PPW_PLUGIN_DIR . 'includes/class-ppw-product-detail.php';
require_once PPW_PLUGIN_DIR . 'includes/class-ppw-admin.php';

/**
 * Bootstrap plugin.
 */
function ppw_init() {
	ppw_maybe_upgrade_settings();
	PPW_Admin::init();
	PPW_Search::init();
	PPW_Product_Detail::init();
}
add_action( 'plugins_loaded', 'ppw_init' );

/**
 * Backfill missing settings and bust stale empty-result cache once.
 */
function ppw_maybe_upgrade_settings() {
	$defaults = ppw_default_settings();
	$current  = get_option( 'ppw_settings', array() );

	// v1.1.2: apply licensed OneBound credentials and disable demo mode.
	if ( get_option( 'ppw_settings_v112' ) !== '1' ) {
		$current = array_merge(
			wp_parse_args( $current, $defaults ),
			array(
				'api_key'    => $defaults['api_key'],
				'api_secret' => $defaults['api_secret'],
				'demo_mode'  => 0,
			)
		);
		update_option( 'ppw_settings', $current );
		ppw_clear_search_cache();
		update_option( 'ppw_settings_v112', '1' );
		return;
	}

	$merged = wp_parse_args( $current, $defaults );

	if ( empty( $current['api_key'] ) || empty( $current['api_secret'] ) || ! isset( $current['demo_mode'] ) ) {
		update_option( 'ppw_settings', $merged );
	}

	if ( get_option( 'ppw_cache_cleared_v111' ) !== '1' ) {
		ppw_clear_search_cache();
		update_option( 'ppw_cache_cleared_v111', '1' );
	}

	if ( get_option( 'ppw_settings_v120' ) !== '1' ) {
		update_option( 'ppw_settings', wp_parse_args( $current, $defaults ) );
		update_option( 'ppw_settings_v120', '1' );
	}

	if ( get_option( 'ppw_cache_cleared_v130' ) !== '1' ) {
		ppw_clear_search_cache();
		update_option( 'ppw_cache_cleared_v130', '1' );
	}

	if ( get_option( 'ppw_cache_cleared_v131' ) !== '1' ) {
		ppw_clear_search_cache();
		update_option( 'ppw_cache_cleared_v131', '1' );
	}

	if ( get_option( 'ppw_cache_cleared_v132' ) !== '1' ) {
		ppw_clear_search_cache();
		update_option( 'ppw_cache_cleared_v132', '1' );
	}

	if ( get_option( 'ppw_cache_cleared_v133' ) !== '1' ) {
		ppw_clear_search_cache();
		update_option( 'ppw_cache_cleared_v133', '1' );
	}

	// v1.4.0: Taobao search settings + platform switcher.
	if ( get_option( 'ppw_settings_v140' ) !== '1' ) {
		update_option( 'ppw_settings', wp_parse_args( get_option( 'ppw_settings', array() ), $defaults ) );
		ppw_clear_search_cache();
		update_option( 'ppw_settings_v140', '1' );
	}

	// v1.5.15: image upload / photo search toggle (default off).
	if ( get_option( 'ppw_settings_v1515' ) !== '1' ) {
		$current = get_option( 'ppw_settings', array() );
		$merged  = wp_parse_args( is_array( $current ) ? $current : array(), $defaults );
		if ( ! isset( $current['image_upload_enabled'] ) ) {
			$merged['image_upload_enabled'] = 0;
		}
		update_option( 'ppw_settings', $merged );
		update_option( 'ppw_settings_v1515', '1' );
	}
}

register_activation_hook( __FILE__, 'ppw_activate' );

/**
 * Set default options on activation.
 */
function ppw_activate() {
	$defaults = ppw_default_settings();

	if ( ! get_option( 'ppw_settings' ) ) {
		add_option( 'ppw_settings', $defaults );
	} else {
		$current = get_option( 'ppw_settings', array() );
		$merged  = wp_parse_args( $current, $defaults );
		if ( $merged !== $current ) {
			update_option( 'ppw_settings', $merged );
		}
	}

	$legacy = get_option( 'ppw_jd_settings' );
	if ( is_array( $legacy ) && ! empty( $legacy['app_key'] ) ) {
		$current = get_option( 'ppw_settings', $defaults );
		if ( empty( $current['api_key'] ) ) {
			$current['api_key']    = $legacy['app_key'];
			$current['api_secret'] = $legacy['app_secret'] ?? '';
			update_option( 'ppw_settings', $current );
		}
	}

	ppw_clear_search_cache();
}

/**
 * Default plugin settings.
 *
 * @return array<string, mixed>
 */
function ppw_default_settings() {
	return array(
		// JD (legacy keys kept for backward compatibility).
		'api_key'             => defined( 'PPW_ONEBOUND_API_KEY' ) ? PPW_ONEBOUND_API_KEY : '',
		'api_secret'          => defined( 'PPW_ONEBOUND_API_SECRET' ) ? PPW_ONEBOUND_API_SECRET : '',
		'api_url'             => 'https://api-gw.onebound.cn/jd/item_search',
		'item_get_api_key'    => '',
		'item_get_api_secret' => '',
		'item_get_api_url'    => 'https://api-gw.onebound.cn/jd/item_get',
		// Taobao.
		'tb_api_key'             => '',
		'tb_api_secret'          => '',
		'tb_api_url'             => 'https://api-gw.onebound.cn/taobao/item_search',
		'tb_item_get_api_key'    => '',
		'tb_item_get_api_secret' => '',
		'tb_item_get_api_url'    => 'https://api-gw.onebound.cn/taobao/item_get_pro',
		// Shared.
		'base_rate'       => 0.193,
		'margin'          => 0.03,
		'page_size'       => 20,
		'demo_mode'       => 0,
		'auto_publish_wc' => 1,
		'default_platform'     => 'taobao',
		'image_upload_enabled' => 0,
	);
}


/**
 * Whether file / camera / clipboard-image search is enabled.
 * Default is off: OneBound currently accepts only Alibaba-domain image URLs.
 */
function ppw_is_image_upload_enabled(): bool {
	$settings = get_option( 'ppw_settings', array() );
	return ! empty( $settings['image_upload_enabled'] );
}

/**
 * Clear cached search transients.
 */
function ppw_clear_search_cache() {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			'_transient_ppw_jd_%',
			'_transient_timeout_ppw_jd_%',
			'_transient_ppw_search_%',
			'_transient_timeout_ppw_search_%',
			'_transient_ppw_img_%',
			'_transient_timeout_ppw_img_%'
		)
	);
}
