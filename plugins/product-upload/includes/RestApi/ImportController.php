<?php
/**
 * REST API controller for product import and status.
 *
 * @package ProductUploadForWoocommerce\RestApi
 */

namespace ProductUploadForWoocommerce\RestApi;

use ProductUploadForWoocommerce\Api\ApiClient;
use ProductUploadForWoocommerce\Import\ProductImporter;

defined('ABSPATH') || exit;

/**
 * ImportController class.
 *
 * Registers REST routes under the `product-upload/v1` namespace:
 *
 *  POST /product-upload/v1/import        – Import a product by URL
 *  POST /product-upload/v1/disconnect    – Clear the cached token (forces re-activation)
 *  GET  /product-upload/v1/status        – Connection status + token data
 *
 * @since 1.0.0
 */
class ImportController
{

	const ROUTE_NAMESPACE = 'product-upload/v1';

	// WP-Cron hook that sends a queued /track event
	const TRACK_HOOK = 'pu_wc_track_activity';

	/** @var ApiClient */
	private $api;

	/** @var ProductImporter */
	private $importer;

	/**
	 * Constructor – registers REST routes on `rest_api_init`.
	 *
	 * @param ApiClient       $api      API client instance.
	 * @param ProductImporter $importer Product importer instance.
	 */
	public function __construct(ApiClient $api, ProductImporter $importer)
	{
		$this->api = $api;
		$this->importer = $importer;

		add_action('rest_api_init', array($this, 'register_routes'));
		add_action(self::TRACK_HOOK, array($this, 'send_tracked_event'), 10, 3);
	}

	// ------------------------------------------------------------------
	// Route registration
	// ------------------------------------------------------------------

	/**
	 * Register all REST routes.
	 */
	public function register_routes()
	{
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/import',
			array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array($this, 'handle_import'),
			'permission_callback' => array($this, 'check_admin_permission'),
			'args' => array(
				'url' => array(
					'required' => true,
					'type' => 'string',
					'sanitize_callback' => 'esc_url_raw',
					'validate_callback' => array($this, 'validate_url'),
					'description' => __('Supplier product URL to import.', 'product-upload'),
				),
			),
		)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/import-jobs',
			array(
				array(
					'methods' => \WP_REST_Server::READABLE,
					'callback' => array($this, 'handle_list_import_jobs'),
					'permission_callback' => array($this, 'check_admin_permission'),
				),
				array(
					'methods' => \WP_REST_Server::CREATABLE,
					'callback' => array($this, 'handle_create_import_job'),
					'permission_callback' => array($this, 'check_admin_permission'),
				),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/import-jobs/(?P<id>[A-Za-z0-9\-]+)',
			array(
				'methods' => \WP_REST_Server::READABLE,
				'callback' => array($this, 'handle_get_import_job'),
				'permission_callback' => array($this, 'check_admin_permission'),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/import-jobs/(?P<id>[A-Za-z0-9\-]+)/cancel',
			array(
				'methods' => \WP_REST_Server::CREATABLE,
				'callback' => array($this, 'handle_cancel_import_job'),
				'permission_callback' => array($this, 'check_admin_permission'),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/pair-code',
			array(
				'methods' => \WP_REST_Server::CREATABLE,
				'callback' => array($this, 'handle_pair_code'),
				'permission_callback' => array($this, 'check_admin_permission'),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/disconnect',
			array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array($this, 'handle_disconnect'),
			'permission_callback' => array($this, 'check_admin_permission'),
		)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/status',
			array(
			'methods' => \WP_REST_Server::READABLE,
			'callback' => array($this, 'handle_status'),
			'permission_callback' => array($this, 'check_admin_permission'),
		)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/upgrade-url',
			array(
			'methods' => \WP_REST_Server::READABLE,
			'callback' => array($this, 'handle_upgrade_url'),
			'permission_callback' => array($this, 'check_admin_permission'),
		)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/portal-url',
			array(
			'methods' => \WP_REST_Server::READABLE,
			'callback' => array($this, 'handle_portal_url'),
			'permission_callback' => array($this, 'check_admin_permission'),
		)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/settings',
			array(
				array(
				'methods' => \WP_REST_Server::READABLE,
				'callback' => array($this, 'handle_get_settings'),
				'permission_callback' => array($this, 'check_admin_permission'),
			),
				array(
				'methods' => \WP_REST_Server::CREATABLE,
				'callback' => array($this, 'handle_save_settings'),
				'permission_callback' => array($this, 'check_admin_permission'),
			),
		)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/categories',
			array(
			'methods' => \WP_REST_Server::READABLE,
			'callback' => array($this, 'handle_get_categories'),
			'permission_callback' => array($this, 'check_admin_permission'),
		)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/suppliers',
			array(
			'methods' => \WP_REST_Server::READABLE,
			'callback' => array($this, 'handle_get_suppliers'),
			'permission_callback' => array($this, 'check_admin_permission'),
		)
		);

		// Supplier overview: AI/probe profile for a hostname. Not a site
		// scrape (keyed by hostname, cross-shop), so it ships in both builds.
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/supplier-intel',
			array(
			'methods' => \WP_REST_Server::READABLE,
			'callback' => array($this, 'handle_get_supplier_intel'),
			'permission_callback' => array($this, 'check_admin_permission'),
			'args' => array(
				'hostname' => array(
					'required' => true,
					'type' => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'refresh' => array(
					'type' => 'string',
					'default' => '',
				),
			),
		)
		);

		// Per-supplier import history (this shop's own products).
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/supplier-products',
			array(
			'methods' => \WP_REST_Server::READABLE,
			'callback' => array($this, 'handle_get_supplier_products'),
			'permission_callback' => array($this, 'check_admin_permission'),
			'args' => array(
				'supplier_id' => array(
					'required' => true,
					'type' => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'page' => array(
					'type' => 'integer',
					'default' => 1,
				),
			),
		)
		);

		// Per-supplier locale pin (source currency + scrape locale).
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/supplier-settings',
			array(
				array(
				'methods' => \WP_REST_Server::READABLE,
				'callback' => array($this, 'handle_get_supplier_settings'),
				'permission_callback' => array($this, 'check_admin_permission'),
				'args' => array(
					'hostname' => array(
						'required' => true,
						'type' => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			),
				array(
				'methods' => \WP_REST_Server::CREATABLE,
				'callback' => array($this, 'handle_save_supplier_settings'),
				'permission_callback' => array($this, 'check_admin_permission'),
			),
		)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/api-key',
			array(
				array(
				'methods' => \WP_REST_Server::READABLE,
				'callback' => array($this, 'handle_get_api_key'),
				'permission_callback' => array($this, 'check_admin_permission'),
			),
				array(
				'methods' => \WP_REST_Server::CREATABLE,
				'callback' => array($this, 'handle_post_api_key'),
				'permission_callback' => array($this, 'check_admin_permission'),
				'args' => array(
					'action' => array(
						'required' => true,
						'type' => 'string',
						'enum' => array('generate', 'rotate'),
					),
					'keyId' => array(
						'type' => 'integer',
					),
				),
			),
		)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/activity',
			array(
			'methods' => \WP_REST_Server::READABLE,
			'callback' => array($this, 'handle_get_activity'),
			'permission_callback' => array($this, 'check_admin_permission'),
			'args' => array(
				'limit' => array(
					'type' => 'integer',
					'default' => 50,
				),
				'offset' => array(
					'type' => 'integer',
					'default' => 0,
				),
			),
		)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/track',
			array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array($this, 'handle_track'),
			'permission_callback' => array($this, 'check_admin_permission'),
			'args' => array(
				'event' => array(
					'type' => 'string',
					'required' => true,
					'sanitize_callback' => 'sanitize_key',
				),
				'metadata' => array(
					'type' => 'object',
					'default' => array(),
				),
			),
		)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/users',
			array(
			'methods' => \WP_REST_Server::READABLE,
			'callback' => array($this, 'handle_get_users'),
			'permission_callback' => array($this, 'check_admin_permission'),
		)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/users/invite',
			array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array($this, 'handle_invite_user'),
			'permission_callback' => array($this, 'check_admin_permission'),
			'args' => array(
				'email' => array(
					'required' => true,
					'type' => 'string',
					'sanitize_callback' => 'sanitize_email',
				),
				'role' => array(
					'type' => 'string',
					'default' => 'admin',
				),
			),
		)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/users/remove',
			array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array($this, 'handle_remove_user'),
			'permission_callback' => array($this, 'check_admin_permission'),
			'args' => array(
				'profile_id' => array(
					'required' => true,
					'type' => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/users/update-email',
			array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array($this, 'handle_update_store_email'),
			'permission_callback' => array($this, 'check_admin_permission'),
			'args' => array(
				'email' => array(
					'required' => true,
					'type' => 'string',
					'sanitize_callback' => 'sanitize_email',
				),
			),
		)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/users/verify-email',
			array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array($this, 'handle_send_email_code'),
			'permission_callback' => array($this, 'check_admin_permission'),
			'args' => array(
				'email' => array(
					'required' => true,
					'type' => 'string',
					'sanitize_callback' => 'sanitize_email',
				),
			),
		)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/users/verify-email/confirm',
			array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array($this, 'handle_confirm_email_code'),
			'permission_callback' => array($this, 'check_admin_permission'),
			'args' => array(
				'email' => array(
					'required' => true,
					'type' => 'string',
					'sanitize_callback' => 'sanitize_email',
				),
				'code' => array(
					'required' => true,
					'type' => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
		);

		// Catalog browser: tiered scan engine proxies.
		$site_url_arg = array(
			'required' => true,
			'type' => 'string',
			'sanitize_callback' => 'esc_url_raw',
			'validate_callback' => array($this, 'validate_url'),
		);
		$text_arg = array(
			'type' => 'string',
			'default' => '',
			'sanitize_callback' => array($this, 'sanitize_passthrough'),
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/site/scan',
			array(
				'methods' => \WP_REST_Server::READABLE,
				'callback' => array($this, 'handle_site_scan'),
				'permission_callback' => array($this, 'check_admin_permission'),
				'args' => array(
					'url' => $site_url_arg,
					'currency' => $text_arg,
				),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/site/listing',
			array(
				'methods' => \WP_REST_Server::READABLE,
				'callback' => array($this, 'handle_site_listing'),
				'permission_callback' => array($this, 'check_admin_permission'),
				'args' => array(
					'url' => $site_url_arg,
					'collection' => $text_arg,
					'q' => $text_arg,
					'cursor' => $text_arg,
					'filters' => $text_arg,
				),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/site/sellers',
			array(
				array(
					'methods' => \WP_REST_Server::READABLE,
					'callback' => array($this, 'handle_get_site_sellers'),
					'permission_callback' => array($this, 'check_admin_permission'),
					'args' => array('url' => $text_arg),
				),
				array(
					'methods' => \WP_REST_Server::CREATABLE,
					'callback' => array($this, 'handle_post_site_sellers'),
					'permission_callback' => array($this, 'check_admin_permission'),
				),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/site/enrich',
			array(
				'methods' => \WP_REST_Server::CREATABLE,
				'callback' => array($this, 'handle_site_enrich'),
				'permission_callback' => array($this, 'check_admin_permission'),
			)
		);

		// Review prompt: cross-platform state machine. See
		// 20260518_review_prompt_tables.sql for the eligibility logic.
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/review-prompt-status',
			array(
				'methods' => \WP_REST_Server::READABLE,
				'callback' => array($this, 'handle_review_prompt_status'),
				'permission_callback' => array($this, 'check_admin_permission'),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/review-sentiment',
			array(
				'methods' => \WP_REST_Server::CREATABLE,
				'callback' => array($this, 'handle_review_sentiment'),
				'permission_callback' => array($this, 'check_admin_permission'),
				'args' => array(
					'action' => array(
						'required' => true,
						'type' => 'string',
						'enum' => array('positive', 'neutral', 'negative', 'dismiss'),
					),
					'eligibility_reason' => array(
						'required' => false,
						'type' => 'string',
					),
				),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/review-attempt',
			array(
				'methods' => \WP_REST_Server::CREATABLE,
				'callback' => array($this, 'handle_review_attempt'),
				'permission_callback' => array($this, 'check_admin_permission'),
				'args' => array(
					'success' => array(
						'required' => true,
						'type' => 'boolean',
					),
					'code' => array(
						'required' => false,
						'type' => 'string',
					),
					'channel' => array(
						'required' => false,
						'type' => 'string',
						'enum' => array('in_app', 'in_app_banner', 'email'),
					),
					'metadata' => array(
						'required' => false,
						'type' => 'object',
					),
				),
			)
		);
	}

	// ------------------------------------------------------------------
	// Handlers
	// ------------------------------------------------------------------

	/**
	 * POST /import - import a supplier product by URL.
	 *
	 * @param  \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_import(\WP_REST_Request $request)
	{
		$url = $request->get_param('url');

		$result = $this->importer->import($url);

		if (is_wp_error($result)) {
			return $this->error_response($result);
		}

		return rest_ensure_response($result);
	}

	/**
	 * POST /import-jobs - queue one or more supplier URLs as a background job.
	 *
	 * The job-backed dashboard import path: the server queues + extracts, this
	 * plugin's drain creates the products in-WP, and the Imports list shows the
	 * progress. Replaces per-URL synchronous /import so imports persist.
	 *
	 * @param  \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_create_import_job(\WP_REST_Request $request)
	{
		$raw = $request->get_param('urls');
		$urls = array();
		foreach ((array) $raw as $u) {
			$u = esc_url_raw(trim((string) $u));
			if ($u === '' || !filter_var($u, FILTER_VALIDATE_URL)) {
				continue;
			}
			$urls[] = $u;
		}
		if (empty($urls)) {
			return $this->error_response(
				new \WP_Error('no_urls', __('No valid product URLs.', 'product-upload'), array('status' => 400))
			);
		}

		$source = sanitize_text_field((string) ($request->get_param('source') ?: 'manual'));
		$result = $this->api->create_import_job($urls, $source);
		if (is_wp_error($result)) {
			return $this->error_response($result);
		}
		return rest_ensure_response($result);
	}

	/**
	 * GET /import-jobs - list import jobs (state=active|done|all).
	 *
	 * @param  \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_list_import_jobs(\WP_REST_Request $request)
	{
		$state = $request->get_param('state');
		$state = in_array($state, array('active', 'done'), true) ? $state : null;
		$limit = (int) ($request->get_param('limit') ?: 20);
		$offset = (int) ($request->get_param('offset') ?: 0);

		$result = $this->api->get_import_jobs($state, $limit, $offset);
		if (is_wp_error($result)) {
			return $this->error_response($result);
		}
		return rest_ensure_response($result);
	}

	/**
	 * GET /import-jobs/{id} - a single job's detail + items.
	 *
	 * @param  \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_get_import_job(\WP_REST_Request $request)
	{
		$result = $this->api->get_import_job($request->get_param('id'));
		if (is_wp_error($result)) {
			return $this->error_response($result);
		}
		return rest_ensure_response($result);
	}

	/**
	 * POST /import-jobs/{id}/cancel - request cancellation.
	 *
	 * @param  \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_cancel_import_job(\WP_REST_Request $request)
	{
		$result = $this->api->cancel_import_job($request->get_param('id'));
		if (is_wp_error($result)) {
			return $this->error_response($result);
		}
		return rest_ensure_response($result);
	}

	/**
	 * POST /pair-code - mint a Chrome-extension pair code for this shop so the
	 * merchant can pair the extension from inside WooCommerce.
	 *
	 * @param  \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_pair_code(\WP_REST_Request $request)
	{ // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$result = $this->api->pair_init();
		if (is_wp_error($result)) {
			return $this->error_response($result);
		}
		return rest_ensure_response($result);
	}

	/**
	 * POST /disconnect - clear the cached token.
	 *
	 * The site_key is preserved so the same account is re-linked on next use.
	 *
	 * @param  \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response
	 */
	public function handle_disconnect(\WP_REST_Request $request)
	{ // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$this->api->disconnect();
		return rest_ensure_response(array('disconnected' => true));
	}

	/**
	 * GET /status - return connection status and stored token metadata.
	 *
	 * Uses get_token() rather than activate() so that the locally-cached
	 * balance (updated after every product query via `remaining_balance`)
	 * is preserved. activate() is only called when the token is genuinely
	 * missing or within one hour of expiry - not on every status poll.
	 *
	 * @param  \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response
	 */
	public function handle_status(\WP_REST_Request $request)
	{ // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		// get_token() auto-provisions / refreshes only when necessary,
		// leaving the locally-stored balance intact between refreshes.
		$token = $this->api->get_token();

		if (is_wp_error($token)) {
			return rest_ensure_response(
				array(
				'connected' => false,
				'error' => $token->get_error_message(),
			)
			);
		}

		$data = $this->api->get_stored_token_data();

		return rest_ensure_response(
			array(
			'connected' => true,
			'balance' => $data['balance'] ?? 0,
			'plan_name' => $data['plan_name'] ?? 'Free',
			'billing_status' => $data['billing_status'] ?? 'free',
			'renewal_date' => $data['renewal_date'] ?? '',
			'expires_at' => $data['expires_at'] ?? '',
		)
		);
	}

	/**
	 * GET /upgrade-url - get a WooCommerce.com confirmation URL to upgrade plan.
	 *
	 * The React app redirects the browser to the returned URL so the merchant
	 * can complete payment on WooCommerce.com without leaving the ecosystem.
	 *
	 * @param  \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_upgrade_url(\WP_REST_Request $request)
	{
		$plan = sanitize_text_field((string) $request->get_param('plan'));
		$url = $this->api->get_upgrade_url($plan);

		if (is_wp_error($url)) {
			return $this->error_response($url);
		}

		return rest_ensure_response(array('confirmation_url' => $url));
	}

	/**
	 * GET /portal-url - get a Stripe Customer Portal URL where the merchant
	 * can cancel, change plan, update payment method, or view invoices.
	 *
	 * @param  \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_portal_url(\WP_REST_Request $request)
	{
		$plan = sanitize_text_field((string) $request->get_param('plan'));
		$url = $this->api->get_portal_url($plan);

		if (is_wp_error($url)) {
			return $this->error_response($url);
		}

		return rest_ensure_response(array('url' => $url));
	}

	/**
	 * GET /categories - this store's product categories for the picker.
	 *
	 * @return \WP_REST_Response
	 */
	public function handle_get_categories()
	{
		$terms = get_terms(array(
			'taxonomy' => 'product_cat',
			'hide_empty' => false,
			'orderby' => 'name',
		));
		if (is_wp_error($terms)) {
			return rest_ensure_response(array());
		}
		// index for ancestor paths
		$by_id = array();
		foreach ($terms as $t) {
			$by_id[(int)$t->term_id] = $t;
		}
		// decode entities so React renders "&" not "&amp;"
		$name_of = function ($term) {
			return html_entity_decode((string)$term->name, ENT_QUOTES);
		};
		$out = array();
		foreach ($terms as $term) {
			// "Parent › Child" disambiguates duplicate leaf names
			$labels = array($name_of($term));
			$parent = (int)$term->parent;
			$guard = 0;
			while ($parent && isset($by_id[$parent]) && $guard < 10) {
				array_unshift($labels, $name_of($by_id[$parent]));
				$parent = (int)$by_id[$parent]->parent;
				$guard++;
			}
			$out[] = array(
				'id' => (int)$term->term_id,
				'name' => $name_of($term),
				'path' => implode(' › ', $labels),
			);
		}
		return rest_ensure_response($out);
	}

	/**
	 * GET /settings - load product settings.
	 *
	 * The remote API doesn't store `additional_settings`, so we overlay
	 * the locally-persisted values on top of whatever the remote returns.
	 *
	 * @param  \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_get_settings(\WP_REST_Request $request)
	{ // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$result = $this->api->get_settings();

		if (is_wp_error($result)) {
			return $this->error_response($result);
		}

		// merge: server keys + local
		$remote = (isset($result['additional_settings']) && is_array($result['additional_settings']))
			? $result['additional_settings']
			: array();
		$local = get_option('pu_wc_additional_settings', array());
		$result['additional_settings'] = array_merge($remote, is_array($local) ? $local : array());

		return rest_ensure_response($result);
	}

	/**
	 * POST /settings - save product settings.
	 *
	 * `additional_settings` (product_status, import_brand, track_stock,
	 * stock_quantity, plus pricing keys: price_multiplier, auto_cost_price,
	 * auto_compare_at_price, compare_at_multiplier, price_rounding, min_price)
	 * are stored locally in wp_options so they can be read by ProductImporter
	 * without a remote API round-trip.
	 *
	 * @param  \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_save_settings(\WP_REST_Request $request)
	{
		$settings = $request->get_json_params();

		// Report the store currency so the server can convert source prices to it.
		if (function_exists('get_woocommerce_currency')) {
			$settings['locale_currency'] = get_woocommerce_currency();
		}

		// Persist import defaults locally so ProductImporter can read them.
		if (isset($settings['additional_settings']) && is_array($settings['additional_settings'])) {
			update_option('pu_wc_additional_settings', $settings['additional_settings']);
		}

		// Persist spec format locally so ProductImporter can format without a remote round-trip.
		if (!empty($settings['description_spec_format'])) {
			$local = get_option('pu_wc_additional_settings', array());
			$local['description_spec_format'] = sanitize_text_field($settings['description_spec_format']);
			update_option('pu_wc_additional_settings', $local);
		}

		$result = $this->api->save_settings($settings);

		if (is_wp_error($result)) {
			return $this->error_response($result);
		}

		return rest_ensure_response($result);
	}

	/**
	 * GET /suppliers - fetch saved supplier list.
	 *
	 * @param  \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_get_suppliers(\WP_REST_Request $request)
	{ // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$result = $this->api->get_suppliers();
		if (is_wp_error($result)) {
			return $this->error_response($result);
		}
		return rest_ensure_response($result);
	}

	/**
	 * GET /supplier-intel - AI/probe supplier profile for the overview.
	 *
	 * @param  \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_get_supplier_intel(\WP_REST_Request $request)
	{
		$hostname = $request->get_param('hostname');
		$refresh = (string) $request->get_param('refresh') === '1';
		$result = $this->api->get_supplier_intel($hostname, $refresh);
		if (is_wp_error($result)) {
			return $this->error_response($result);
		}
		return rest_ensure_response($result);
	}

	/**
	 * GET /supplier-products - this shop's imports for one supplier.
	 *
	 * @param  \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_get_supplier_products(\WP_REST_Request $request)
	{
		$supplier_id = (string) $request->get_param('supplier_id');
		$page = (int) $request->get_param('page');
		$result = $this->api->get_supplier_products($supplier_id, $page ?: 1);
		if (is_wp_error($result)) {
			return $this->error_response($result);
		}
		return rest_ensure_response($result);
	}

	/**
	 * GET /supplier-settings - load the per-supplier locale pin.
	 *
	 * @param  \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_get_supplier_settings(\WP_REST_Request $request)
	{
		$result = $this->api->get_supplier_settings($request->get_param('hostname'));
		if (is_wp_error($result)) {
			return $this->error_response($result);
		}
		return rest_ensure_response($result);
	}

	/**
	 * POST /supplier-settings - save the per-supplier locale pin.
	 *
	 * @param  \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_save_supplier_settings(\WP_REST_Request $request)
	{
		$body = $request->get_json_params();
		if (!is_array($body) || empty($body['hostname'])) {
			return $this->error_response(
				new \WP_Error('missing_hostname', __('hostname required.', 'product-upload'), array('status' => 400))
			);
		}
		$patch = array('hostname' => sanitize_text_field((string) $body['hostname']));
		foreach (array('currency', 'country', 'language') as $key) {
			if (array_key_exists($key, $body)) {
				$patch[$key] = $body[$key] === null ? null : sanitize_text_field((string) $body[$key]);
			}
		}
		$result = $this->api->save_supplier_settings($patch);
		if (is_wp_error($result)) {
			return $this->error_response($result);
		}
		return rest_ensure_response($result);
	}

	/**
	 * GET /site/scan - classify a site for the catalog browser.
	 *
	 * @param  \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response
	 */
	public function handle_site_scan(\WP_REST_Request $request)
	{
		return $this->site_response(
			$this->api->site_scan($this->present_params($request, array('url', 'currency')))
		);
	}

	/**
	 * GET /site/listing - one page of a site's products.
	 *
	 * @param  \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response
	 */
	public function handle_site_listing(\WP_REST_Request $request)
	{
		$keys = array('url', 'collection', 'q', 'cursor', 'filters');
		return $this->site_response($this->api->site_listing($this->present_params($request, $keys)));
	}

	/**
	 * GET /site/sellers - saved sellers for a marketplace.
	 *
	 * @param  \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response
	 */
	public function handle_get_site_sellers(\WP_REST_Request $request)
	{
		return $this->site_response($this->api->get_site_sellers($this->present_params($request, array('url'))));
	}

	/**
	 * POST /site/sellers - add {url, marketplace} or {remove}.
	 *
	 * @param  \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response
	 */
	public function handle_post_site_sellers(\WP_REST_Request $request)
	{
		$body = (array) $request->get_json_params();
		$data = array();
		foreach (array('url', 'marketplace', 'remove') as $key) {
			if (isset($body[$key]) && is_string($body[$key])) {
				$data[$key] = $this->sanitize_passthrough($body[$key]);
			}
		}
		return $this->site_response($this->api->post_site_sellers($data));
	}

	/**
	 * POST /site/enrich - price and photo for listed URLs.
	 *
	 * @param  \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response
	 */
	public function handle_site_enrich(\WP_REST_Request $request)
	{
		$body = (array) $request->get_json_params();
		$urls = isset($body['urls']) && is_array($body['urls']) ? $body['urls'] : array();
		$urls = array_values(array_filter(array_map('esc_url_raw', array_filter($urls, 'is_string'))));
		$data = array('urls' => $urls);
		if (isset($body['url']) && is_string($body['url'])) {
			$data['url'] = esc_url_raw($body['url']);
		}
		return $this->site_response($this->api->site_enrich($data));
	}

	/**
	 * GET /api-key - fetch the current API key.
	 *
	 * @param  \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_get_api_key(\WP_REST_Request $request)
	{ // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$result = $this->api->getApiKey();
		if (is_wp_error($result)) {
			return $this->error_response($result);
		}
		return rest_ensure_response($result);
	}

	/**
	 * POST /api-key - generate or rotate an API key.
	 *
	 * @param  \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_post_api_key(\WP_REST_Request $request)
	{
		$action = $request->get_param('action');

		if ('rotate' === $action) {
			$key_id = (int)$request->get_param('keyId');
			if (empty($key_id)) {
				return new \WP_REST_Response(
					array(
					'code' => 'missing_key_id',
					'message' => __('Key ID is required for rotation.', 'product-upload'),
				),
					400
				);
			}
			$result = $this->api->rotateApiKey($key_id);
		} else {
			$result = $this->api->generateApiKey();
		}

		if (is_wp_error($result)) {
			return $this->error_response($result);
		}
		return rest_ensure_response($result);
	}

	/**
	 * GET /activity - fetch the activity log.
	 *
	 * @param  \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_get_activity(\WP_REST_Request $request)
	{
		$limit = (int)$request->get_param('limit');
		$offset = (int)$request->get_param('offset');

		$result = $this->api->getActivityLog($limit ?: 50, $offset);
		if (is_wp_error($result)) {
			return $this->error_response($result);
		}
		return rest_ensure_response($result);
	}

	/**
	 * POST /track - log a funnel event to activity_log. Always 200: a
	 * dropped analytics event must never surface as an error.
	 *
	 * @param  \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response
	 */
	public function handle_track(\WP_REST_Request $request)
	{
		$metadata = $request->get_param('metadata');
		// async: inline blocks on activate() + POST
		wp_schedule_single_event(
			time(),
			self::TRACK_HOOK,
			array(
				$request->get_param('event'),
				is_array($metadata) ? $metadata : array(),
				wp_generate_uuid4(), // else WP drops repeats <10m
			)
		);
		return rest_ensure_response(array('ok' => true));
	}

	/**
	 * WP-Cron callback for handle_track.
	 *
	 * @param string $event    Event name.
	 * @param array  $metadata Event metadata.
	 * @param string $id       Uniqueness key, unused.
	 */
	public function send_tracked_event($event, $metadata, $id = '')
	{ // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$this->api->trackActivity($event, is_array($metadata) ? $metadata : array());
	}

	/**
	 * GET /users - fetch the user list.
	 *
	 * @param  \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_get_users(\WP_REST_Request $request)
	{ // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$result = $this->api->getUsers();
		if (is_wp_error($result)) {
			return $this->error_response($result);
		}
		return rest_ensure_response($result);
	}

	/**
	 * POST /users/invite - invite a user.
	 *
	 * @param  \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_invite_user(\WP_REST_Request $request)
	{
		$email = $request->get_param('email');
		$role = $request->get_param('role') ?: 'admin';

		$result = $this->api->inviteUser($email, $role);
		if (is_wp_error($result)) {
			return $this->error_response($result);
		}
		return rest_ensure_response($result);
	}

	/**
	 * POST /users/remove - remove a user.
	 *
	 * @param  \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_remove_user(\WP_REST_Request $request)
	{
		$profile_id = $request->get_param('profile_id');

		$result = $this->api->removeUser($profile_id);
		if (is_wp_error($result)) {
			return $this->error_response($result);
		}
		return rest_ensure_response($result);
	}

	/**
	 * POST /users/update-email - update store owner email.
	 *
	 * @param  \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_update_store_email(\WP_REST_Request $request)
	{
		$email = $request->get_param('email');

		$result = $this->api->updateStoreEmail($email);
		if (is_wp_error($result)) {
			return $this->error_response($result);
		}
		return rest_ensure_response($result);
	}

	/**
	 * POST /users/verify-email - email a verification code.
	 *
	 * @param  \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_send_email_code(\WP_REST_Request $request)
	{
		$result = $this->api->sendEmailCode($request->get_param('email'));
		if (is_wp_error($result)) {
			return $this->error_response($result);
		}
		return rest_ensure_response($result);
	}

	/**
	 * POST /users/verify-email/confirm - confirm the code.
	 *
	 * @param  \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_confirm_email_code(\WP_REST_Request $request)
	{
		$result = $this->api->confirmEmailCode(
			$request->get_param('email'),
			$request->get_param('code')
		);
		if (is_wp_error($result)) {
			return $this->error_response($result);
		}
		return rest_ensure_response($result);
	}

	// ------------------------------------------------------------------
	// Permissions
	// ------------------------------------------------------------------

	/**
	 * Only allow logged-in users with `manage_woocommerce` capability.
	 *
	 * @return bool|\WP_Error
	 */
	public function check_admin_permission()
	{
		if (current_user_can('manage_woocommerce')) {
			return true;
		}

		return new \WP_Error(
			'rest_forbidden',
			__('You do not have permission to perform this action.', 'product-upload'),
			array('status' => rest_authorization_required_code())
			);
	}

	// ------------------------------------------------------------------
	// Review prompt handlers
	// ------------------------------------------------------------------

	/**
	 * GET /review-prompt-status — live eligibility check.
	 * Soft-fails to { status: 'ineligible' } so the banner never crashes.
	 *
	 * @param  \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response
	 */
	public function handle_review_prompt_status(\WP_REST_Request $request)
	{ // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$data = $this->api->get_review_prompt_status();
		if (is_wp_error($data)) {
			return rest_ensure_response(array('status' => 'ineligible', 'reason' => 'upstream_error'));
		}
		return rest_ensure_response($data);
	}

	/**
	 * POST /review-sentiment — persist a sticky sentiment transition.
	 *
	 * @param  \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_review_sentiment(\WP_REST_Request $request)
	{
		$result = $this->api->submit_review_sentiment(
			$request->get_param('action'),
			$request->get_param('eligibility_reason')
		);
		if (is_wp_error($result)) {
			return $this->error_response($result);
		}
		return rest_ensure_response($result);
	}

	/**
	 * POST /review-attempt — log a Phase-2 dispatch result.
	 *
	 * @param  \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_review_attempt(\WP_REST_Request $request)
	{
		$metadata = $request->get_param('metadata');
		$result = $this->api->log_review_attempt(
			(bool) $request->get_param('success'),
			$request->get_param('code'),
			$request->get_param('channel') ?: 'in_app_banner',
			is_array($metadata) ? $metadata : array()
		);
		if (is_wp_error($result)) {
			return $this->error_response($result);
		}
		return rest_ensure_response($result);
	}

	// ------------------------------------------------------------------
	// Validation
	// ------------------------------------------------------------------

	/**
	 * Proxied text: trimmed, valid UTF-8, never stored.
	 *
	 * @param  mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_passthrough($value)
	{
		return is_string($value) ? trim(wp_check_invalid_utf8($value)) : '';
	}

	/**
	 * Validate that a parameter is a non-empty URL.
	 *
	 * @param  mixed $value The value to validate.
	 * @return bool|\WP_Error
	 */
	public function validate_url($value)
	{
		if (empty($value) || !filter_var($value, FILTER_VALIDATE_URL)) {
			return new \WP_Error(
				'invalid_url',
				__('Please provide a valid product URL.', 'product-upload'),
				array('status' => 400)
				);
		}
		return true;
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Request params that were sent and non-empty.
	 *
	 * @param  \WP_REST_Request $request Incoming request.
	 * @param  string[]         $keys    Allowed keys.
	 * @return array
	 */
	private function present_params(\WP_REST_Request $request, array $keys)
	{
		$out = array();
		foreach ($keys as $key) {
			$value = $request->get_param($key);
			if (is_string($value) && $value !== '') {
				$out[$key] = $value;
			}
		}
		return $out;
	}

	/**
	 * Catalog browser result as a REST response.
	 *
	 * @param  mixed $result Decoded body or WP_Error.
	 * @return \WP_REST_Response
	 */
	private function site_response($result)
	{
		return is_wp_error($result) ? $this->error_response($result) : rest_ensure_response($result);
	}

	/**
	 * Wrap a WP_Error in a REST response with an appropriate HTTP status.
	 *
	 * @param  \WP_Error $error WP_Error object.
	 * @return \WP_REST_Response
	 */
	private function error_response(\WP_Error $error)
	{
		$data = $error->get_error_data();
		$status = isset($data['status']) ? (int)$data['status'] : 400;

		return new \WP_REST_Response(
			array(
			'code' => $error->get_error_code(),
			'message' => $error->get_error_message(),
		),
			$status
			);
	}
}