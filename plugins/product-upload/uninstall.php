<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Removes all wp_options rows created by Product Upload for WooCommerce
 * so no data is left behind after removal.
 *
 * @package ProductUploadForWoocommerce
 */

// Only run when called by WordPress uninstall mechanism.
if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

// OAuth credentials issued by productupload.co.
delete_option('pu_wc_external_id');
delete_option('pu_wc_client_token');

// In-flight handshake state (cleared on success, but may exist if the
// merchant uninstalls mid-flow).
delete_option('pu_wc_oauth_pending_state');
delete_option('pu_wc_oauth_pending_expires');

// Cached token payload (balance, plan, expiry, etc.).
delete_option('pu_wc_token_data');

// WooCommerce.com billing state. The handler that wrote this was removed in
// 1.2.0 and never ran in the first place (its signing constant was never
// defined), so no site should have the row — deleted anyway, for free.
delete_option('pu_wc_billing');

// Pre-OAuth deterministic site key — only present on shops that installed
// before the OAuth handshake landed. Harmless to delete unconditionally.
delete_option('pu_wc_site_key');

// Background-drain cron event.
wp_clear_scheduled_hook('pu_wc_drain_import_jobs');

// Ids the AI-assistant editor keeps for option values.
delete_post_meta_by_key('_pu_option_value_ids');
delete_post_meta_by_key('_pu_stock_memory');
