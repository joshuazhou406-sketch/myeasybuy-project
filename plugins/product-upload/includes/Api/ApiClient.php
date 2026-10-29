<?php
/**
 * HTTP client for the ProductUpload.co API.
 *
 * Requires an OAuth handshake (see OAuthHandler). Every API call attaches the
 * stored client_token as a bearer; the server validates it against
 * shop.client_token_hash. activate() returns a WP_Error if the site has not
 * yet been connected.
 *
 * @package ProductUploadForWoocommerce\Api
 */

namespace ProductUploadForWoocommerce\Api;

use ProductUploadForWoocommerce\Api\OAuthHandler;

defined('ABSPATH') || exit;

/**
 * ApiClient class.
 *
 * @since 1.0.0
 */
class ApiClient
{

	/** @var array|null Activation result cached for the current PHP request only. */
	private $last_activation = null;

	/**
	 * Activate (or re-activate) this site on ProductUpload.co using the
	 * client_token issued by the OAuth handshake.
	 *
	 * @return array|\WP_Error Token data array on success.
	 */
	public function activate()
	{
		$client_token = OAuthHandler::get_client_token();
		$external_id = OAuthHandler::get_external_id();

		if (empty($client_token) || empty($external_id)) {
			return new \WP_Error(
				'not_connected',
				__('Site is not connected to ProductUpload.co.', 'product-upload')
			);
		}

		$response = wp_remote_post(
			PU_WC_API_BASE . 'wc-activate',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $client_token,
					'X-PU-Site-Id' => $external_id,
					'Content-Type' => 'application/json',
					'Accept' => 'application/json',
				),
				'body' => wp_json_encode(new \stdClass()),
			)
		);

		if (is_wp_error($response)) {
			return new \WP_Error(
				'request_failed',
				sprintf(
				/* translators: %s: error message. */
				__('Could not reach ProductUpload.co: %s', 'product-upload'),
				$response->get_error_message()
			)
				);
		}

		$code = wp_remote_retrieve_response_code($response);
		$body = json_decode(wp_remote_retrieve_body($response), true);

		if (200 !== $code || empty($body['token'])) {
			$message = !empty($body['error'])
				? $body['error']
				: sprintf(
				/* translators: %d: HTTP status code. */
				__('Activation failed (HTTP %d).', 'product-upload'),
				$code
			);
			return new \WP_Error('activate_failed', $message);
		}

		// Cache for the duration of this PHP request only - no wp_options persistence.
		$this->last_activation = array(
			'token' => $body['token'],
			'expires_at' => $body['expires_at'] ?? '',
			'balance' => $body['balance'] ?? 0,
			'plan_name' => $body['plan_name'] ?? 'Free',
			'billing_status' => $body['billing_status'] ?? 'free',
			'renewal_date' => $body['renewal_date'] ?? '',
		);

		return $body;
	}

	// ------------------------------------------------------------------
	// Token management
	// ------------------------------------------------------------------

	/**
	 * Return a valid token, activating via the API every time.
	 *
	 * Within a single PHP request the result is reused from $last_activation
	 * to avoid redundant HTTP calls when multiple methods need the token.
	 *
	 * @return string|\WP_Error Token string on success.
	 */
	public function get_token()
	{
		if (!empty($this->last_activation['token'])) {
			return $this->last_activation['token'];
		}

		$result = $this->activate();
		if (is_wp_error($result)) {
			return $result;
		}
		return $result['token'];
	}

	/**
	 * Whether the API can be reached and returns a valid token.
	 *
	 * @return bool
	 */
	public function is_connected()
	{
		if (!empty($this->last_activation['token'])) {
			return true;
		}
		return !is_wp_error($this->activate());
	}

	/**
	 * Return token metadata from the last activation call in this request.
	 *
	 * @return array
	 */
	public function get_stored_token_data()
	{
		return $this->last_activation ?? array();
	}

	/**
	 * Clear the in-memory activation data. The shop record on
	 * productupload.co is retained.
	 */
	public function disconnect()
	{
		$this->last_activation = null;
	}

	// ------------------------------------------------------------------
	// Authenticated request helpers
	// ------------------------------------------------------------------

	/**
	 * Send an authenticated request to the productupload.co API.
	 *
	 * Token is passed via the Authorization: Bearer header (never the URL).
	 *
	 * @param  string $method GET or POST.
	 * @param  string $path   Path relative to PU_WC_API_BASE.
	 * @param  array  $params Query-string params (for GET) or body (for POST).
	 * @param  int    $timeout Timeout in seconds.
	 * @param  bool   $assoc   False keeps JSON objects as objects.
	 * @return array|object|\WP_Error Decoded body or WP_Error.
	 */
	private function authed_request($method, $path, array $params = array(), $timeout = 30, $assoc = true)
	{
		$token = $this->get_token();
		if (is_wp_error($token)) {
			return $token;
		}

		$args = array(
			'timeout' => $timeout,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept' => 'application/json',
			),
		);

		if (strtoupper($method) === 'POST') {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body'] = wp_json_encode($params);
			$url = PU_WC_API_BASE . $path;
			$response = wp_remote_post($url, $args);
		} else {
			$url = PU_WC_API_BASE . $path;
			if (!empty($params)) {
				$url .= '?' . http_build_query($params);
			}
			$response = wp_remote_get($url, $args);
		}

		if (is_wp_error($response)) {
			return new \WP_Error('request_failed', $response->get_error_message());
		}

		$code = wp_remote_retrieve_response_code($response);
		$body = json_decode(wp_remote_retrieve_body($response), $assoc);

		if ($code >= 400) {
			$error = is_object($body) ? ($body->error ?? null) : ($body['error'] ?? null);
			$message = !empty($error)
				? $error
				: sprintf(
					/* translators: %d: HTTP status code. */
					__('Request failed (HTTP %d).', 'product-upload'),
					$code
				);
			return new \WP_Error('api_error', $message, array('status' => $code));
		}

		return is_array($body) || is_object($body) ? $body : array();
	}

	// ------------------------------------------------------------------
	// Upgrade / billing
	// ------------------------------------------------------------------

	/**
	 * Ask productupload.co to create a WooCommerce.com SaaS billing subscription
	 * for this site and return the WooCommerce.com confirmation URL.
	 *
	 * The returned URL is where the browser must be redirected so the merchant
	 * can complete payment on WooCommerce.com.
	 *
	 * @param  string $plan Optional plan name chosen in the pricing modal. When
	 *                      set, the server returns a direct Stripe Checkout URL
	 *                      for that plan instead of the plan-picker page.
	 * @return string|\WP_Error WooCommerce.com confirmation URL on success.
	 */
	public function get_upgrade_url($plan = '')
	{
		$external_id = OAuthHandler::get_external_id();
		if (empty($external_id)) {
			return new \WP_Error(
				'not_connected',
				__('Connect to ProductUpload.co before upgrading.', 'product-upload')
			);
		}

		$payload = array('site_key' => $external_id);
		if (! empty($plan)) {
			$payload['plan'] = $plan;
		}

		$response = wp_remote_post(
			PU_WC_API_BASE . '../wc-billing/create-subscription',
			array(
			'timeout' => 30,
			'headers' => array(
				'Content-Type' => 'application/json',
				'Accept' => 'application/json',
			),
			'body' => wp_json_encode($payload),
		)
		);

		if (is_wp_error($response)) {
			return new \WP_Error('request_failed', $response->get_error_message());
		}

		$code = wp_remote_retrieve_response_code($response);
		$body = json_decode(wp_remote_retrieve_body($response), true);

		if ($code >= 400 || empty($body['confirmation_url'])) {
			$message = $body['error'] ?? sprintf(
				/* translators: %d: HTTP status code. */
				__('Upgrade request failed (HTTP %d).', 'product-upload'),
				$code
			);
			return new \WP_Error('upgrade_failed', $message);
		}

		return $body['confirmation_url'];
	}

	/**
	 * Ask productupload.co for a Stripe Customer Portal URL where the
	 * merchant can cancel, change plan, update payment method, or view
	 * invoices.
	 *
	 * @param  string $plan Optional plan name. When set, the portal deep-links
	 *                      straight to the confirm screen for switching to it.
	 * @return string|\WP_Error Portal URL on success.
	 */
	public function get_portal_url($plan = '')
	{
		$external_id = OAuthHandler::get_external_id();
		if (empty($external_id)) {
			return new \WP_Error(
				'not_connected',
				__('Connect to ProductUpload.co before opening the billing portal.', 'product-upload')
			);
		}

		$payload = array('site_key' => $external_id);
		if (! empty($plan)) {
			$payload['plan'] = $plan;
		}

		$response = wp_remote_post(
			PU_WC_API_BASE . '../wc-billing/portal',
			array(
			'timeout' => 30,
			'headers' => array(
				'Content-Type' => 'application/json',
				'Accept' => 'application/json',
			),
			'body' => wp_json_encode($payload),
		)
		);

		if (is_wp_error($response)) {
			return new \WP_Error('request_failed', $response->get_error_message());
		}

		$code = wp_remote_retrieve_response_code($response);
		$body = json_decode(wp_remote_retrieve_body($response), true);

		if ($code >= 400 || empty($body['url'])) {
			$message = $body['error'] ?? sprintf(
				/* translators: %d: HTTP status code. */
				__('Could not open billing portal (HTTP %d).', 'product-upload'),
				$code
			);
			return new \WP_Error('portal_failed', $message);
		}

		return $body['url'];
	}

	// ------------------------------------------------------------------
	// Product query
	// ------------------------------------------------------------------

	/**
	 * Fetch AI-enhanced product data from ProductUpload.co.
	 *
	 * @param  string $product_url Supplier product URL.
	 * @return array|\WP_Error     Product data on success.
	 */
	public function query_product($product_url)
	{
		// supports_category=1 opts into the category-parser flow.
		$body = $this->authed_request(
			'GET',
			'query',
			array(
				'url' => $product_url,
				'supports_category' => '1',
			),
			// Must exceed the server's detection budget (120s) + AI time so the
			// plugin waits for a slow import instead of giving up and surfacing
			// a non-JSON timeout. Bounded in practice by the host's PHP
			// max_execution_time - see readme troubleshooting.
			180
		);

		if (is_wp_error($body)) {
			return $body;
		}

		if (!empty($body['kind']) && $body['kind'] === 'category') {
			return array(
				'kind' => 'category',
				'source_url' => $body['source_url'] ?? $product_url,
				'count' => isset($body['count']) ? (int) $body['count'] : 0,
				'products' => is_array($body['products'] ?? null) ? $body['products'] : array(),
				'remaining_balance' => $body['remaining_balance'] ?? null,
			);
		}

		if (empty($body) || empty($body['product_name'])) {
			return new \WP_Error(
				'empty_response',
				__('No product data returned. Please check the URL and try again.', 'product-upload')
				);
		}

		// Normalize the unified /api/external/query response into the shape
		// that ProductImporter.php expects.
		$body = $this->normalize_product_data($body, $product_url);

		return $body;
	}

	// ------------------------------------------------------------------
	// Background import jobs (plugin-drained queue)
	// ------------------------------------------------------------------

	/**
	 * Claim a batch of parked background-import items for this shop.
	 *
	 * The server extracts + enriches each item, then parks it as
	 * `awaiting_store_create`; this transitions a batch to `store_creating`
	 * and returns the /query-shaped product so the plugin can create it in-WP.
	 *
	 * @param  int $limit Max items to claim (server caps at 25).
	 * @return array|\WP_Error { items: [{ id, job_id, url, product }] }.
	 */
	public function claim_import_jobs($limit = 10)
	{
		return $this->authed_request(
			'POST',
			'import-jobs/claim',
			array('limit' => (int) $limit),
			60
		);
	}

	/**
	 * Report the result of creating a claimed item back to the server.
	 *
	 * On success the server charges one credit (charge-on-confirm) and marks
	 * the item succeeded; on failure it marks it failed. Idempotent: a repeat
	 * confirm for an already-terminal item is a no-op and never re-charges.
	 *
	 * @param  string      $item_id            import_job_item id.
	 * @param  string|null $external_product_id Created WC product id (null = failure).
	 * @param  string|null $listing_url        Public product permalink.
	 * @param  string|null $error              Failure message (when no product id).
	 * @param  string|null $error_code         Machine code for the failure (when no product id).
	 * @return array|\WP_Error
	 */
	public function confirm_import_job($item_id, $external_product_id = null, $listing_url = null, $error = null, $error_code = null)
	{
		$body = array('item_id' => (string) $item_id);
		if (!empty($external_product_id)) {
			$body['external_product_id'] = (string) $external_product_id;
			if (!empty($listing_url)) {
				$body['listing_url'] = (string) $listing_url;
			}
		}
		else {
			$body['error'] = (string) ($error ?: 'WooCommerce create failed.');
			if (!empty($error_code)) {
				$body['error_code'] = (string) $error_code;
			}
		}
		return $this->authed_request('POST', 'import-jobs/confirm', $body, 30);
	}

	/**
	 * Normalize a /query-shaped payload into the ProductImporter shape.
	 *
	 * Public wrapper over normalize_product_data so the background-job drain
	 * runs the claimed payload through the exact same transform the
	 * synchronous URL path uses.
	 *
	 * @param  array  $body       Raw /query-shaped product payload.
	 * @param  string $source_url Originating supplier URL.
	 * @return array              Normalized product data.
	 */
	public function normalize_payload(array $body, $source_url = '')
	{
		return $this->normalize_product_data($body, $source_url);
	}

	/**
	 * Create a background import job from one or more supplier URLs.
	 *
	 * The server queues the URLs, the worker extracts + parks each as
	 * awaiting_store_create, and the plugin's drain creates them in-WP. This
	 * is the dashboard's job-backed import path (replaces the synchronous
	 * per-URL import so imports persist + show in the Imports list).
	 *
	 * @param  string[] $urls   Supplier product URLs.
	 * @param  string   $source Job source label.
	 * @return array|\WP_Error  { job_id, total } on success.
	 */
	public function create_import_job(array $urls, $source = 'manual')
	{
		return $this->authed_request(
			'POST',
			'import-jobs',
			array('urls' => array_values($urls), 'source' => $source),
			60
		);
	}

	/**
	 * List import jobs for this shop.
	 *
	 * @param  string|null $state  'active' | 'done' | null (all).
	 * @param  int         $limit  Page size (server caps at 50).
	 * @param  int         $offset Pagination offset.
	 * @return array|\WP_Error     { jobs: [...], hasMore }.
	 */
	public function get_import_jobs($state = null, $limit = 20, $offset = 0)
	{
		$params = array('limit' => (int) $limit, 'offset' => (int) $offset);
		if (!empty($state)) {
			$params['state'] = $state;
		}
		return $this->authed_request('GET', 'import-jobs', $params, 30);
	}

	/**
	 * Fetch a single import job's detail + items.
	 *
	 * @param  string $id import_job id.
	 * @return array|\WP_Error { job, items, balance }.
	 */
	public function get_import_job($id)
	{
		return $this->authed_request('GET', 'import-jobs/' . rawurlencode($id), array(), 30);
	}

	/**
	 * Request cancellation of an import job.
	 *
	 * @param  string $id import_job id.
	 * @return array|\WP_Error
	 */
	public function cancel_import_job($id)
	{
		return $this->authed_request('POST', 'import-jobs/' . rawurlencode($id) . '/cancel', array(), 30);
	}

	/**
	 * Mint a short-lived Chrome-extension pair code for this shop.
	 *
	 * Lets the merchant pair the extension from inside WooCommerce (paste the
	 * code into the extension popup) instead of visiting productupload.co.
	 * /extension/pair-init reads the token from the BODY, so it is passed
	 * explicitly here in addition to the usual Authorization header.
	 *
	 * @return array|\WP_Error { code, expires_at }.
	 */
	public function pair_init()
	{
		$token = $this->get_token();
		if (is_wp_error($token)) {
			return $token;
		}
		return $this->authed_request('POST', 'extension/pair-init', array('token' => $token), 30);
	}

	// ------------------------------------------------------------------
	// Product settings
	// ------------------------------------------------------------------

	/**
	 * Fetch the shop's product settings from ProductUpload.co.
	 *
	 * @return array|\WP_Error Settings data on success.
	 */
	public function get_settings()
	{
		return $this->authed_request('GET', 'settings');
	}

	/**
	 * Save product settings to ProductUpload.co.
	 *
	 * @param  array $settings Settings key-value pairs.
	 * @return array|\WP_Error Response data on success.
	 */
	public function save_settings(array $settings)
	{
		return $this->authed_request('POST', 'settings', $settings);
	}

	// ------------------------------------------------------------------
	// Supplier / site scanning
	// ------------------------------------------------------------------

	/**
	 * Fetch the list of saved suppliers for this shop.
	 *
	 * @return array|\WP_Error Supplier list on success.
	 */
	public function get_suppliers()
	{
		return $this->authed_request('GET', 'suppliers');
	}

	/**
	 * Scan a site for the catalog browser.
	 *
	 * @param  array $params url, optional currency.
	 * @return object|array|\WP_Error Decoded body.
	 */
	public function site_scan(array $params)
	{
		return $this->authed_request('GET', 'site/scan', $params, 95, false);
	}

	/**
	 * One page of a site's product listing.
	 *
	 * @param  array $params url, collection, q, cursor, filters.
	 * @return object|array|\WP_Error Decoded body.
	 */
	public function site_listing(array $params)
	{
		return $this->authed_request('GET', 'site/listing', $params, 95, false);
	}

	/**
	 * Saved marketplace sellers.
	 *
	 * @param  array $params Optional url (the marketplace).
	 * @return object|array|\WP_Error Decoded body.
	 */
	public function get_site_sellers(array $params)
	{
		return $this->authed_request('GET', 'site/sellers', $params, 30, false);
	}

	/**
	 * Add ({url, marketplace}) or remove ({remove}) a seller.
	 *
	 * @param  array $body Request body.
	 * @return object|array|\WP_Error Decoded body.
	 */
	public function post_site_sellers(array $body)
	{
		return $this->authed_request('POST', 'site/sellers', $body, 60, false);
	}

	/**
	 * Price and photo for tier C product URLs.
	 *
	 * @param  array $body url, urls.
	 * @return object|array|\WP_Error Decoded body.
	 */
	public function site_enrich(array $body)
	{
		return $this->authed_request('POST', 'site/enrich', $body, 95, false);
	}

	// ------------------------------------------------------------------
	// Supplier overview (intel / per-supplier history / locale pin)
	// ------------------------------------------------------------------

	/**
	 * Fetch the AI/probe supplier profile for a hostname.
	 *
	 * Stale-while-revalidate, hostname-keyed, cross-shop. A cold cache miss
	 * blocks on a SerpAPI + Firecrawl + Gemini probe (~15-45s), so the
	 * timeout is generous.
	 *
	 * @param  string $hostname Bare supplier hostname.
	 * @param  bool   $refresh  Force a re-probe (skip cache).
	 * @return array|\WP_Error  { intel, cached, stale } on success.
	 */
	public function get_supplier_intel($hostname, $refresh = false)
	{
		$params = array('hostname' => $hostname);
		if ($refresh) {
			$params['refresh'] = '1';
		}
		return $this->authed_request('GET', 'supplier-intel', $params, 70);
	}

	/**
	 * Fetch this shop's imported products for one supplier (history tab).
	 *
	 * Product-centric, 20 per page. The supplier id is a UUID string.
	 *
	 * @param  string $supplier_id Supplier row id (UUID).
	 * @param  int    $page        1-based page.
	 * @return array|\WP_Error     { data: [...] } on success.
	 */
	public function get_supplier_products($supplier_id, $page = 1)
	{
		return $this->authed_request(
			'GET',
			'products',
			array(
				'supplier_id' => (string) $supplier_id,
				'page' => (int) $page,
			),
			30
		);
	}

	/**
	 * Load the per-supplier locale pin (source currency + scrape locale).
	 *
	 * @param  string $hostname Bare supplier hostname.
	 * @return array|\WP_Error  { settings: {...}|null } on success.
	 */
	public function get_supplier_settings($hostname)
	{
		return $this->authed_request('GET', 'supplier-settings', array('hostname' => $hostname), 30);
	}

	/**
	 * Save the per-supplier locale pin.
	 *
	 * @param  array $patch { hostname, currency?, country?, language? }.
	 * @return array|\WP_Error  { settings: {...} } on success.
	 */
	public function save_supplier_settings(array $patch)
	{
		return $this->authed_request('POST', 'supplier-settings', $patch, 30);
	}

	// ------------------------------------------------------------------
	// API key management
	// ------------------------------------------------------------------

	/**
	 * Fetch the current API key for this shop.
	 *
	 * @return array|\WP_Error API key data on success (may contain null data).
	 */
	public function getApiKey()
	{
		return $this->authed_request('GET', 'api-key');
	}

	/**
	 * Generate a new API key for this shop.
	 *
	 * @return array|\WP_Error API key data on success.
	 */
	public function generateApiKey()
	{
		return $this->authed_request('POST', 'api-key', array('action' => 'generate'));
	}

	/**
	 * Rotate an existing API key.
	 *
	 * @param  int $key_id The ID of the key to rotate.
	 * @return array|\WP_Error API key data on success.
	 */
	public function rotateApiKey($key_id)
	{
		return $this->authed_request(
			'POST',
			'api-key',
			array(
				'action' => 'rotate',
				'keyId' => (int)$key_id,
			)
		);
	}

	// ------------------------------------------------------------------
	// Activity log
	// ------------------------------------------------------------------

	/**
	 * Fetch the activity log for this shop.
	 *
	 * @param  int $limit  Number of entries to fetch.
	 * @param  int $offset Offset for pagination.
	 * @return array|\WP_Error Activity log data on success.
	 */
	public function getActivityLog($limit = 50, $offset = 0)
	{
		return $this->authed_request(
			'GET',
			'activity',
			array(
				'limit' => (int)$limit,
				'offset' => (int)$offset,
			)
		);
	}

	/**
	 * Log a client funnel event (e.g. ooc_shown). The server allowlists
	 * event names, so anything else is dropped there.
	 *
	 * @param  string $event    Event name.
	 * @param  array  $metadata Event metadata.
	 * @return array|\WP_Error
	 */
	public function trackActivity($event, array $metadata = array())
	{
		return $this->authed_request(
			'POST',
			'activity',
			array(
				'event' => $event,
				'metadata' => $metadata,
			),
			10
		);
	}

	// ------------------------------------------------------------------
	// User management
	// ------------------------------------------------------------------

	/**
	 * Fetch the list of users for this shop.
	 *
	 * @return array|\WP_Error Users data on success.
	 */
	public function getUsers()
	{
		return $this->authed_request('GET', 'users');
	}

	/**
	 * Invite a user to this shop.
	 *
	 * @param  string $email User email.
	 * @param  string $role  Role to assign (default: admin).
	 * @return array|\WP_Error Response data on success.
	 */
	public function inviteUser($email, $role = 'admin')
	{
		return $this->authed_request(
			'POST',
			'users/invite',
			array(
				'email' => sanitize_email($email),
				'role' => $role,
			)
		);
	}

	/**
	 * Remove a user from this shop.
	 *
	 * @param  string $profile_id Profile ID of the user to remove.
	 * @return array|\WP_Error Response data on success.
	 */
	public function removeUser($profile_id)
	{
		return $this->authed_request(
			'POST',
			'users/remove',
			array('profile_id' => sanitize_text_field($profile_id))
		);
	}

	/**
	 * Update the store owner email.
	 *
	 * @param  string $email New owner email.
	 * @return array|\WP_Error Response data on success.
	 */
	public function updateStoreEmail($email)
	{
		return $this->authed_request(
			'POST',
			'users/update-email',
			array('email' => sanitize_email($email))
		);
	}

	/**
	 * Email a verification code to an address the merchant typed.
	 *
	 * @param  string $email Address to verify.
	 * @return array|\WP_Error Response data on success.
	 */
	public function sendEmailCode($email)
	{
		return $this->authed_request(
			'POST',
			'users/verify-email',
			array('email' => sanitize_email($email))
		);
	}

	/**
	 * Confirm the code; on success the email becomes the store's sign-in.
	 *
	 * @param  string $email Address being verified.
	 * @param  string $code  Code from the email.
	 * @return array|\WP_Error Response data on success.
	 */
	public function confirmEmailCode($email, $code)
	{
		return $this->authed_request(
			'POST',
			'users/verify-email/confirm',
			array(
				'email' => sanitize_email($email),
				'code' => sanitize_text_field($code),
			)
		);
	}

	// ------------------------------------------------------------------
	// Review prompt (cross-platform)
	// ------------------------------------------------------------------

	/**
	 * Fetch live eligibility status for the current shop.
	 *
	 * @return array|\WP_Error { status, reason } from get_review_prompt_eligibility.
	 */
	public function get_review_prompt_status()
	{
		return $this->authed_request('GET', 'review-prompt-status');
	}

	/**
	 * Persist a sticky sentiment transition.
	 *
	 * @param  string      $action             'positive' | 'neutral' | 'negative' | 'dismiss'.
	 * @param  string|null $eligibility_reason Original trigger label (A1_power_user, ...).
	 * @return array|\WP_Error
	 */
	public function submit_review_sentiment($action, $eligibility_reason = null)
	{
		return $this->authed_request(
			'POST',
			'review-sentiment',
			array(
				'action' => $action,
				'eligibility_reason' => $eligibility_reason,
				'platform' => 'woocommerce',
			)
		);
	}

	/**
	 * Log a Phase-2 attempt (WP.org review link opened, etc.).
	 *
	 * @param  bool        $success
	 * @param  string|null $code     e.g. 'success' (treated like Shopify modal-shown), 'link_dismissed'.
	 * @param  string      $channel  'in_app' | 'in_app_banner' | 'email'.
	 * @param  array       $metadata Arbitrary context.
	 * @return array|\WP_Error
	 */
	public function log_review_attempt($success, $code, $channel = 'in_app_banner', array $metadata = array())
	{
		return $this->authed_request(
			'POST',
			'review-attempt',
			array(
				'success' => (bool) $success,
				'code' => $code,
				'channel' => $channel,
				'metadata' => $metadata,
				'platform' => 'woocommerce',
			)
		);
	}

	// ------------------------------------------------------------------
	// Response normalization
	// ------------------------------------------------------------------

	/**
	 * Transform the unified /api/external/query response into the shape
	 * that ProductImporter.php expects.
	 *
	 * The unified endpoint (shared with Shopify/Wix) returns field names
	 * that differ from the WooCommerce-specific format:
	 *   - product_price is an object {average, highest, lowest} → scalar float
	 *   - product_images is a string array → product_media [{src_image_url, alt_text}]
	 *   - product_specifications → product_specs
	 *   - variants → product_variants
	 *   - seo → product_seo
	 *
	 * @param  array  $body        Raw response body from /api/external/query.
	 * @param  string $product_url The originally requested product URL.
	 * @return array               Normalized product data.
	 */
	private function normalize_product_data(array $body, $product_url)
	{
		$data = array();

		$data['product_name'] = $body['product_name'] ?? '';
		$data['product_description'] = $body['product_description'] ?? '';
		$data['product_brand'] = $body['product_brand'] ?? '';
		$data['product_sku'] = $body['product_sku'] ?? null;
		$data['product_source_url'] = $body['product_source_url'] ?? $product_url;
		$data['remaining_balance'] = $body['remaining_balance'] ?? null;

		// Price: object → scalar float.
		$price = $body['product_price'] ?? 0;
		if (is_array($price)) {
			$data['product_price'] = floatval($price['average'] ?? 0);
		}
		else {
			$data['product_price'] = floatval($price);
		}

		// Images: the API returns product_media [{src_image_url, alt_text}]
		// from the product table. Fall back to product_images (string[])
		// if present and convert to the same shape.
		if (!empty($body['product_media']) && is_array($body['product_media'])) {
			$data['product_media'] = $body['product_media'];
		}
		else {
			$images = $body['product_images'] ?? array();
			$seen = array();
			$media = array();
			foreach ($images as $url) {
				if (!is_string($url) || empty($url)) {
					continue;
				}
				$lower = strtolower($url);
				if (isset($seen[$lower])) {
					continue;
				}
				$seen[$lower] = true;
				$media[] = array(
					'src_image_url' => $url,
					'alt_text' => $body['product_name'] ?? '',
				);
			}
			$data['product_media'] = $media;
		}

		// Specs: DB column is product_specs, raw AI uses product_specifications.
		$data['product_specs'] = $body['product_specs']
			?? $body['product_specifications']
			?? array();

		// true = specs already inline in the description
		$data['specs_rendered_in_description'] =
			!empty($body['specs_rendered_in_description']);

		// Blueprint sections split out of the description when expandable
		// sections are on. Lead copy stays in product_description; these
		// render as product tabs (Import\ProductTabs).
		$data['product_info_sections'] = is_array($body['product_info_sections'] ?? null)
			? $body['product_info_sections']
			: array();

		// Variants: DB column is product_variants, raw AI uses variants.
		$data['product_variants'] = $body['product_variants']
			?? $body['variants']
			?? array();

		// SEO: DB column is product_seo, raw AI uses seo.
		$data['product_seo'] = $body['product_seo']
			?? $body['seo']
			?? array();

		// Shipping dimensions (same key in both formats).
		$data['product_shipping_dimensions'] = $body['product_shipping_dimensions'] ?? null;

		// Pass through product_lookup_id if present (used for SKU generation).
		if (!empty($body['product_lookup_id'])) {
			$data['product_lookup_id'] = $body['product_lookup_id'];
		}

		// GTIN/UPC/EAN — returned at the top level of /query responses,
		// nulled by the server when it equals the SKU so we don't duplicate.
		if (!empty($body['barcode'])) {
			$data['barcode'] = $body['barcode'];
		}

		// SEO-friendly URL slug pre-extracted by the AI; falls back to WP's
		// auto-slug from title if absent.
		if (!empty($body['product_url_slug'])) {
			$data['product_url_slug'] = $body['product_url_slug'];
		}

		return $data;
	}
}