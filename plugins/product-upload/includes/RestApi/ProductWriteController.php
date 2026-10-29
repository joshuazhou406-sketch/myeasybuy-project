<?php
/**
 * Server-driven partial product update.
 *
 * Every other route this plugin exposes is gated on an admin cookie, which
 * means Product Upload cannot call them: the server is never a logged-in user.
 * The import queue is the only existing server->site channel and it only
 * speaks "create" — ProductImporter rebuilds the whole product from its
 * payload, so routing a patch through it would clear the fields the patch
 * omits.
 *
 * This route is the narrow exception: a signed, partial update. Only keys
 * present in the body are touched. HMAC-SHA256 over "<timestamp>.<body>", keyed
 * on the Product Upload token the site obtains from its own activation, with a
 * timestamp window so a captured request cannot be replayed later. Signing the
 * timestamp together with the body stops the two being swapped independently.
 *
 * The key is read from a short-lived transient, never fetched inline: an
 * uncached lookup here would mean an unauthenticated request costs an outbound
 * round-trip, which is a denial-of-service lever on the merchant's own site.
 *
 * @package ProductUploadForWoocommerce\RestApi
 */

namespace ProductUploadForWoocommerce\RestApi;

use ProductUploadForWoocommerce\Api\ApiClient;

defined('ABSPATH') || exit;

/**
 * ProductWriteController class.
 *
 * @since 1.2.0
 */
class ProductWriteController
{

	const ROUTE_NAMESPACE = 'product-upload/v1';
	const ROUTE_PATH = '/product/update';

	/** Replay window for a signed request, in seconds. */
	const MAX_SKEW = 300;

	/** Longest title WooCommerce stores cleanly. */
	const TITLE_MAX = 200;

	/** Transient holding the HMAC key, so the unauthenticated path does no I/O. */
	const KEY_TRANSIENT = 'pu_wc_signing_key';

	/** How long to cache it. Well inside the token's 7-day life. */
	const KEY_TTL = 300;

	/** @var ApiClient */
	private $api;

	/**
	 * Constructor.
	 *
	 * @param ApiClient $api API client instance.
	 */
	public function __construct(ApiClient $api)
	{
		$this->api = $api;
	}

	/**
	 * Hook the route up.
	 */
	public function register()
	{
		add_action('rest_api_init', array($this, 'register_routes'));
	}

	/**
	 * Register the signed update route.
	 */
	public function register_routes()
	{
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE_PATH,
			array(
				'methods' => \WP_REST_Server::CREATABLE,
				'callback' => array($this, 'handle_update'),
				'permission_callback' => array($this, 'verify_signature'),
			)
		);
	}

	// ------------------------------------------------------------------
	// Permission
	// ------------------------------------------------------------------

	/**
	 * Verify the HMAC signature Product Upload sent.
	 *
	 * Signed value is "<timestamp>.<raw body>" so the timestamp cannot be
	 * swapped independently of the payload.
	 *
	 * @param  \WP_REST_Request $request Incoming request.
	 * @return bool|\WP_Error
	 */
	public function verify_signature(\WP_REST_Request $request)
	{
		$signature = (string) $request->get_header('x_pu_signature');
		$timestamp = (string) $request->get_header('x_pu_timestamp');

		if (empty($signature) || empty($timestamp)) {
			return new \WP_Error(
				'missing_signature',
				__('Missing signature.', 'product-upload'),
				array('status' => 401)
			);
		}

		// Bound replay. Also rejects a clock so far off that a captured
		// request would stay valid indefinitely.
		if (abs(time() - (int) $timestamp) > self::MAX_SKEW) {
			return new \WP_Error(
				'stale_signature',
				__('Signature expired.', 'product-upload'),
				array('status' => 401)
			);
		}

		$token = $this->signing_key();
		if (empty($token)) {
			return new \WP_Error(
				'not_connected',
				__('This site is not connected to Product Upload.', 'product-upload'),
				array('status' => 401)
			);
		}

		$expected = base64_encode(
			hash_hmac('sha256', $timestamp . '.' . $request->get_body(), $token, true)
		);
		if (hash_equals($expected, $signature)) {
			return true;
		}

		return new \WP_Error(
			'invalid_signature',
			__('Invalid signature.', 'product-upload'),
			array('status' => 401)
		);
	}

	/**
	 * The HMAC key, from a short-lived transient.
	 *
	 * get_token() is an uncached wp_remote_post to productupload.co. Calling it
	 * from permission_callback would mean an unauthenticated request costs a
	 * 30-second outbound round-trip, so anyone could pin every PHP worker on
	 * the site and turn it into a load generator against our API. Caching keeps
	 * the unauthenticated path free, and a 5-minute window is well inside the
	 * token's 7-day life.
	 *
	 * @return string Empty string when the site is not connected.
	 */
	private function signing_key()
	{
		$cached = get_transient(self::KEY_TRANSIENT);
		// false is a miss; '' is a cached negative and must be returned, or a
		// site whose activation is failing hits the network on every
		// unauthenticated request — what this cache exists to prevent.
		if (false !== $cached && is_string($cached)) {
			return $cached;
		}

		$token = $this->api->get_token();
		if (is_wp_error($token) || empty($token)) {
			set_transient(self::KEY_TRANSIENT, '', MINUTE_IN_SECONDS);
			return '';
		}

		set_transient(self::KEY_TRANSIENT, $token, self::KEY_TTL);
		return $token;
	}

	/**
	 * Run a save without WordPress's HTML filters for logged-out requests.
	 *
	 * A signed request has no user, so WordPress filters every post field on
	 * save. WooCommerce rewrites the whole post when a title, slug or status
	 * changes, so a title-only edit stripped videos, style blocks and scripts
	 * from a description the request never named, and stored "&" in a title
	 * as "&amp;". What this plugin writes itself is sanitised explicitly.
	 *
	 * @param  callable $work The save to run.
	 * @return mixed Whatever $work returns.
	 */
	public static function unfiltered(callable $work)
	{
		$filtered = false !== has_filter('content_save_pre', 'wp_filter_post_kses');
		if ($filtered) {
			kses_remove_filters();
		}
		try {
			return $work();
		} finally {
			if ($filtered) {
				kses_init_filters();
			}
		}
	}

	// ------------------------------------------------------------------
	// Handler
	// ------------------------------------------------------------------

	/**
	 * Apply a partial update. Absent keys are left alone; that is the whole
	 * point of this route existing separately from the import path.
	 *
	 * @param  \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_update(\WP_REST_Request $request)
	{
		$body = $request->get_json_params();
		$product_id = isset($body['product_id']) ? absint($body['product_id']) : 0;

		if (!$product_id) {
			return new \WP_Error(
				'missing_product_id',
				__('product_id is required.', 'product-upload'),
				array('status' => 400)
			);
		}

		$product = wc_get_product($product_id);
		if (!$product) {
			// distinct body so the caller can tell "no such product" from
			// "this plugin is too old to have the route at all"
			return new \WP_Error(
				'product_upload_not_found',
				__('No product with that id.', 'product-upload'),
				array('status' => 404)
			);
		}

		$written = array();

		if (array_key_exists('title', $body) && is_string($body['title'])) {
			// mb_, not substr: a byte cut lands mid
			// UTF-8 sequence and sanitize_text_field
			// returns '' for invalid UTF-8, which
			// would blank a live product's name
			if (mb_strlen($body['title'], 'UTF-8') > self::TITLE_MAX) {
				return new \WP_Error(
					'title_too_long',
					sprintf(
						/* translators: %d: maximum number of characters. */
						__('Title must be %d characters or fewer.', 'product-upload'),
						self::TITLE_MAX
					),
					array('status' => 400)
				);
			}
			$name = sanitize_text_field($body['title']);
			if ('' === $name) {
				return new \WP_Error(
					'invalid_title',
					__('Title is empty after sanitisation.', 'product-upload'),
					array('status' => 400)
				);
			}
			$product->set_name($name);
			$written[] = 'title';
		}

		if (array_key_exists('description_html', $body) && is_string($body['description_html'])) {
			$product->set_description(wp_kses_post($body['description_html']));
			$written[] = 'description_html';
		}

		if (array_key_exists('price', $body) && is_numeric($body['price'])) {
			// A variable product is priced entirely by its variations —
			// handle_updated_props() skips the _price recompute for this type,
			// so writing the parent's _regular_price changes nothing on the
			// storefront. Refuse rather than report a success that never
			// reaches the shelf.
			if ($product->is_type('variable') || $product->is_type('grouped')) {
				return new \WP_Error(
					'price_requires_variation',
					__('This product is priced through its variations, so its price cannot be set on the product itself. Edit the variation prices in WP Admin.', 'product-upload'),
					array('status' => 409)
				);
			}

			$price = wc_format_decimal($body['price']);
			$sale = $product->get_sale_price();
			// is_on_sale() honours the schedule; get_sale_price() alone would
			// also match a finished or not-yet-started sale, and refusing or
			// clearing on one of those would be wrong in both directions.
			$on_sale_now = $product->is_on_sale();

			// A sale below the new regular price keeps winning as the selling
			// price, so writing the regular price alone would answer ok:true
			// while the shelf never moved. Refuse instead of clearing it —
			// deleting a merchant's live promotion to satisfy a price edit is
			// destructive, and the caller cannot put it back from the response.
			if ($on_sale_now && (float) $sale < (float) $price) {
				return new \WP_Error(
					'price_blocked_by_sale',
					sprintf(
						/* translators: %s: the product's current sale price. */
						__('This product is on sale at %s, which customers would keep paying, so its regular price was not changed. End the sale in WP Admin first, or set a price at or below the sale price.', 'product-upload'),
						$sale
					),
					array('status' => 409)
				);
			}

			$product->set_regular_price($price);
			// A running sale at or above the new regular price stops being a
			// discount, so drop the dead value rather than let it resurface on
			// a later price change. A scheduled or finished sale is the
			// merchant's calendar and is left alone.
			if ($on_sale_now && '' !== $sale) {
				$product->set_sale_price('');
			}
			$written[] = 'price';
		}

		if (array_key_exists('sku', $body) && is_string($body['sku'])) {
			$sku = sanitize_text_field($body['sku']);
			// A duplicate SKU throws; report it instead of 500ing.
			$clash = wc_get_product_id_by_sku($sku);
			if ($clash && $clash !== $product_id) {
				return new \WP_Error(
					'sku_in_use',
					__('That SKU is already used by another product.', 'product-upload'),
					array('status' => 409)
				);
			}
			try {
				$product->set_sku($sku);
				$written[] = 'sku';
			} catch (\Exception $e) {
				return new \WP_Error(
					'invalid_sku',
					$e->getMessage(),
					array('status' => 400)
				);
			}
		}

		if (array_key_exists('visible', $body)) {
			$product->set_status($body['visible'] ? 'publish' : 'draft');
			$written[] = 'visible';
		}

		if (empty($written)) {
			return new \WP_Error(
				'nothing_to_update',
				__('No recognised fields in the request.', 'product-upload'),
				array('status' => 400)
			);
		}

		self::unfiltered(
			function () use ($product) {
				$product->save();
			}
		);

		$saved_product = wc_get_product($product_id);

		return rest_ensure_response(
			array(
				'ok' => true,
				'product_id' => (string) $product_id,
				'written' => $written,
				// what actually landed, so the caller can
				// see a value the store altered or ignored
				'effective' => array(
					'title' => $saved_product ? $saved_product->get_name() : null,
					'price' => $saved_product ? $saved_product->get_price() : null,
					'status' => $saved_product ? $saved_product->get_status() : null,
				),
				'permalink' => get_permalink($product_id),
			)
		);
	}
}
