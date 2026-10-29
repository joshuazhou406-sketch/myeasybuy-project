<?php
/**
 * OAuth-style install handshake with productupload.co.
 *
 * Three steps, all browser-driven:
 *
 *   1. WP admin clicks "Connect" → admin-post pu_wc_oauth_start
 *      generates a state nonce, stashes it in wp_options, and 302s the
 *      admin's browser to https://productupload.co/connect/woocommerce.
 *
 *   2. User clicks Authorize on productupload.co. The server creates a
 *      one-shot exchange_code and 302s back to admin-post
 *      pu_wc_oauth_finish with `state` and `exchange_code` in the URL.
 *
 *   3. pu_wc_oauth_finish verifies the state matches what was stashed,
 *      then makes a server-to-server POST to /wc-connect/exchange to
 *      swap the exchange_code for a durable {external_id, client_token}.
 *      Both are persisted to wp_options and the admin is redirected back
 *      to the WC Admin page.
 *
 * The client_token never appears in the browser URL; only the short-lived
 * exchange_code does.
 *
 * @package ProductUploadForWoocommerce\Api
 */

namespace ProductUploadForWoocommerce\Api;

defined('ABSPATH') || exit;

class OAuthHandler
{
	const OPT_EXTERNAL_ID = 'pu_wc_external_id';
	const OPT_CLIENT_TOKEN = 'pu_wc_client_token';
	const OPT_PENDING_STATE = 'pu_wc_oauth_pending_state';
	const OPT_PENDING_EXPIRES = 'pu_wc_oauth_pending_expires';

	const STATE_TTL = 600; // 10 minutes

	public function __construct()
	{
		add_action('admin_post_pu_wc_oauth_start', array($this, 'handle_start'));
		add_action('admin_post_pu_wc_oauth_finish', array($this, 'handle_finish'));
		add_action('admin_post_pu_wc_oauth_disconnect', array($this, 'handle_disconnect'));
	}

	/**
	 * True if a client_token has been stored — i.e. the OAuth handshake
	 * has completed at least once on this site.
	 */
	public static function is_connected()
	{
		$token = get_option(self::OPT_CLIENT_TOKEN, '');
		$external_id = get_option(self::OPT_EXTERNAL_ID, '');
		return !empty($token) && !empty($external_id);
	}

	public static function get_external_id()
	{
		return get_option(self::OPT_EXTERNAL_ID, '');
	}

	public static function get_client_token()
	{
		return get_option(self::OPT_CLIENT_TOKEN, '');
	}

	/**
	 * URL the admin clicks to start the handshake.
	 */
	public static function start_url()
	{
		return wp_nonce_url(
			admin_url('admin-post.php?action=pu_wc_oauth_start'),
			'pu_wc_oauth_start'
		);
	}

	public static function disconnect_url()
	{
		return wp_nonce_url(
			admin_url('admin-post.php?action=pu_wc_oauth_disconnect'),
			'pu_wc_oauth_disconnect'
		);
	}

	/**
	 * Step 1: stash a fresh state and 302 to productupload.co.
	 */
	public function handle_start()
	{
		check_admin_referer('pu_wc_oauth_start');
		if (!current_user_can('manage_woocommerce')) {
			wp_die(esc_html__('You do not have permission to do that.', 'product-upload'), 403);
		}

		$state = self::random_token(32);
		update_option(self::OPT_PENDING_STATE, $state, false);
		update_option(self::OPT_PENDING_EXPIRES, time() + self::STATE_TTL, false);

		$return_url = admin_url('admin-post.php?action=pu_wc_oauth_finish');

		$connect_url = add_query_arg(
			array(
				'state' => $state,
				'site_url' => get_site_url(),
				'return_url' => $return_url,
			),
			self::connect_base_url()
		);

		// External redirect host.
		$connect_host = wp_parse_url($connect_url, PHP_URL_HOST);
		if ($connect_host) {
			add_filter(
				'allowed_redirect_hosts',
				function ($hosts) use ($connect_host) {
					$hosts[] = $connect_host;
					return $hosts;
				}
			);
		}
		wp_safe_redirect($connect_url);
		exit;
	}

	/**
	 * Step 3: validate state, swap exchange_code → client_token, persist.
	 *
	 * No WP nonce check here — the request comes back via the user's browser
	 * after a 302 from productupload.co, so a WP nonce wouldn't be available.
	 * Instead we verify the high-entropy `state` matches what we stashed at
	 * step 1, and that the user is logged in with manage_woocommerce.
	 */
	public function handle_finish()
	{
		if (!is_user_logged_in() || !current_user_can('manage_woocommerce')) {
			wp_die(esc_html__('You do not have permission to do that.', 'product-upload'), 403);
		}

		// State is validated below in place of a nonce (external 302 callback).
		$incoming_state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$exchange_code = isset($_GET['exchange_code']) ? sanitize_text_field(wp_unslash($_GET['exchange_code'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$stored_state = get_option(self::OPT_PENDING_STATE, '');
		$expires_at = (int) get_option(self::OPT_PENDING_EXPIRES, 0);

		// Clear pending state regardless of outcome — single-use.
		delete_option(self::OPT_PENDING_STATE);
		delete_option(self::OPT_PENDING_EXPIRES);

		if (empty($incoming_state) || empty($exchange_code) || empty($stored_state)) {
			$this->fail_redirect('missing_params');
		}
		if (!hash_equals($stored_state, $incoming_state)) {
			$this->fail_redirect('state_mismatch');
		}
		if ($expires_at < time()) {
			$this->fail_redirect('state_expired');
		}

		$response = wp_remote_post(
			PU_WC_API_BASE . 'wc-connect/exchange',
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept' => 'application/json',
				),
				'body' => wp_json_encode(array(
					'state' => $incoming_state,
					'exchange_code' => $exchange_code,
				)),
			)
		);

		if (is_wp_error($response)) {
			$this->fail_redirect('exchange_unreachable');
		}

		$code = wp_remote_retrieve_response_code($response);
		$body = json_decode(wp_remote_retrieve_body($response), true);

		if ($code !== 200 || empty($body['client_token']) || empty($body['external_id'])) {
			$this->fail_redirect('exchange_rejected');
		}

		update_option(self::OPT_EXTERNAL_ID, sanitize_text_field($body['external_id']), false);
		update_option(self::OPT_CLIENT_TOKEN, sanitize_text_field($body['client_token']), false);

		wp_safe_redirect(
			add_query_arg(
				array('pu_connected' => '1'),
				admin_url('admin.php?page=wc-admin&path=/product-upload')
			)
		);
		exit;
	}

	/**
	 * Forget the local credentials. The shop row on productupload.co is left
	 * alone — reconnecting later will create a fresh one (with a fresh
	 * external_id and client_token).
	 */
	public function handle_disconnect()
	{
		check_admin_referer('pu_wc_oauth_disconnect');
		if (!current_user_can('manage_woocommerce')) {
			wp_die(esc_html__('You do not have permission to do that.', 'product-upload'), 403);
		}

		delete_option(self::OPT_EXTERNAL_ID);
		delete_option(self::OPT_CLIENT_TOKEN);

		wp_safe_redirect(
			add_query_arg(
				array('pu_disconnected' => '1'),
				admin_url('admin.php?page=wc-admin&path=/product-upload')
			)
		);
		exit;
	}

	private function fail_redirect($code)
	{
		wp_safe_redirect(
			add_query_arg(
				array('pu_connect_error' => $code),
				admin_url('admin.php?page=wc-admin&path=/product-upload')
			)
		);
		exit;
	}

	private static function connect_base_url()
	{
		// PU_WC_API_BASE is `https://productupload.co/api/external/`; we want
		// `https://productupload.co/connect/woocommerce`.
		$parts = wp_parse_url(PU_WC_API_BASE);
		$scheme = $parts['scheme'] ?? 'https';
		$host = $parts['host'] ?? 'productupload.co';
		$port = isset($parts['port']) ? ':' . $parts['port'] : '';
		return $scheme . '://' . $host . $port . '/connect/woocommerce';
	}

	private static function random_token($bytes)
	{
		if (function_exists('random_bytes')) {
			return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
		}
		return wp_generate_password($bytes * 2, false, false);
	}
}
