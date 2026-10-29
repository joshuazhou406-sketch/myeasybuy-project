<?php
/**
 * Drains the server-side background import queue into WooCommerce.
 *
 * WooCommerce products must be created inside WordPress (the server holds no
 * WC store credentials), so the background importer parks each extracted item
 * as `awaiting_store_create`. This class claims those parked items via
 * /import-jobs/claim, creates each one in-WP with the native ProductImporter,
 * and reports back via /import-jobs/confirm (which charges on confirm).
 *
 * Drained two ways: a WP-cron event (no admin tab needed) and an on-demand
 * REST route the dashboard can poke for instant feedback while open. The
 * server re-parks any item left `store_creating` past its TTL, so a missed
 * cron tick or a dropped confirm self-heals.
 *
 * @package ProductUploadForWoocommerce\Import
 */

namespace ProductUploadForWoocommerce\Import;

use ProductUploadForWoocommerce\Api\ApiClient;

defined('ABSPATH') || exit;

/**
 * JobDrain class.
 *
 * @since 1.2.0
 */
class JobDrain
{

	const CRON_HOOK = 'pu_wc_drain_import_jobs';
	const CRON_SCHEDULE = 'pu_wc_five_minutes';
	const ITEM_META = ProductImporter::ITEM_META;
	const ROUTE_NAMESPACE = 'product-upload/v1';

	/** @var ApiClient */
	private $api;

	/** @var ProductImporter */
	private $importer;

	/** @var string|null Item being created right now (for fatal reporting). */
	private $current_item_id = null;

	/** @var bool Shutdown report already sent this pass. */
	private $reported = false;

	/**
	 * Constructor.
	 *
	 * @param ApiClient       $api      API client instance.
	 * @param ProductImporter $importer Product importer instance.
	 */
	public function __construct(ApiClient $api, ProductImporter $importer)
	{
		$this->api = $api;
		$this->importer = $importer;
	}

	/**
	 * Wire the cron schedule, cron handler, and on-demand REST route.
	 */
	public function register()
	{
		add_filter('cron_schedules', array(__CLASS__, 'add_cron_schedule'));
		add_action(self::CRON_HOOK, array($this, 'drain'));
		add_action('rest_api_init', array($this, 'register_routes'));

		// Self-heal if the schedule was lost (e.g. a partial activation).
		if (!wp_next_scheduled(self::CRON_HOOK)) {
			wp_schedule_event(time() + 60, self::CRON_SCHEDULE, self::CRON_HOOK);
		}
	}

	/**
	 * Register a 5-minute cron interval.
	 *
	 * @param  array $schedules Existing schedules.
	 * @return array
	 */
	public static function add_cron_schedule($schedules)
	{
		if (!isset($schedules[self::CRON_SCHEDULE])) {
			$schedules[self::CRON_SCHEDULE] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display' => __('Every 5 minutes (Product Upload)', 'product-upload'),
			);
		}
		return $schedules;
	}

	/**
	 * Schedule the cron event. Called from the plugin activation hook.
	 */
	public static function activate()
	{
		add_filter('cron_schedules', array(__CLASS__, 'add_cron_schedule'));
		if (!wp_next_scheduled(self::CRON_HOOK)) {
			wp_schedule_event(time() + 60, self::CRON_SCHEDULE, self::CRON_HOOK);
		}
	}

	/**
	 * Clear the cron event. Called from the plugin deactivation hook.
	 */
	public static function deactivate()
	{
		$timestamp = wp_next_scheduled(self::CRON_HOOK);
		if ($timestamp) {
			wp_unschedule_event($timestamp, self::CRON_HOOK);
		}
		wp_clear_scheduled_hook(self::CRON_HOOK);
	}

	/**
	 * Register the on-demand drain route.
	 */
	public function register_routes()
	{
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/drain',
			array(
				'methods' => \WP_REST_Server::CREATABLE,
				'callback' => array($this, 'handle_drain'),
				'permission_callback' => array($this, 'check_admin_permission'),
			)
		);
	}

	/**
	 * POST /drain - run a drain pass immediately (dashboard-triggered).
	 *
	 * @return \WP_REST_Response
	 */
	public function handle_drain()
	{
		return rest_ensure_response($this->drain());
	}

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

	/**
	 * Claim a batch of parked items and create them in WooCommerce.
	 *
	 * @param  int $max Max items to claim this pass. Kept small so a pass
	 *                  finishes creating its batch within the PHP time limit;
	 *                  leftovers are re-parked server-side and retried.
	 * @return array { claimed, created, failed, skipped } counts.
	 */
	public function drain($max = 5)
	{
		// headroom for slow hosts
		if (function_exists('set_time_limit')) {
			set_time_limit(120);
		}

		// keep alive for fatal report
		if (function_exists('ignore_user_abort')) {
			ignore_user_abort(true);
		}

		// headroom so an OOM fatal can still POST
		$reserve = str_repeat('x', 1024 * 1024);
		$this->current_item_id = null;
		$this->reported = false;
		register_shutdown_function(
			function () use (&$reserve) {
				$reserve = null;
				unset($reserve);
				$this->handle_fatal_shutdown();
			}
		);

		$counts = array('claimed' => 0, 'created' => 0, 'failed' => 0, 'skipped' => 0);

		// Skip silently when the site is not connected - nothing to drain.
		if (!$this->api->is_connected()) {
			return $counts;
		}

		$response = $this->api->claim_import_jobs($max);
		if (is_wp_error($response) || empty($response['items']) || !is_array($response['items'])) {
			return $counts;
		}

		foreach ($response['items'] as $item) {
			$item_id = isset($item['id']) ? (string) $item['id'] : '';
			if (empty($item_id)) {
				continue;
			}
			$counts['claimed']++;
			$this->current_item_id = $item_id; // in-flight for fatal report

			$url = isset($item['url']) ? (string) $item['url'] : '';
			$payload = (isset($item['product']) && is_array($item['product'])) ? $item['product'] : null;

			// Idempotency: if a product already exists for this item (a prior
			// pass created it but the confirm was lost and the server re-parked
			// it), re-confirm with the existing id instead of duplicating.
			$existing = $this->find_product_by_item_id($item_id);
			if ($existing) {
				// finish a half-built product
				if ($payload) {
					$this->importer->ensure_media($existing, $this->api->normalize_payload($payload, $url));
				}
				$this->api->confirm_import_job($item_id, $existing, get_permalink($existing));
				$this->current_item_id = null;
				$counts['created']++;
				continue;
			}

			if (empty($payload)) {
				$this->api->confirm_import_job($item_id, null, null, __('Missing product data.', 'product-upload'), 'INVALID_PRODUCT_DATA');
				$this->current_item_id = null;
				$counts['failed']++;
				continue;
			}

			try {
				$data = $this->api->normalize_payload($payload, $url);
				// item_id is stamped as dedup meta inside the create, before save()
				$result = $this->importer->import_from_data($data, $url, $item_id);

				if (is_wp_error($result)) {
					$this->api->confirm_import_job($item_id, null, null, $result->get_error_message(), 'STORE_CREATE_FAILED');
					$this->current_item_id = null;
					$counts['failed']++;
					continue;
				}

				$product_id = isset($result['product_id']) ? (int) $result['product_id'] : 0;
				$this->api->confirm_import_job($item_id, (string) $product_id, get_permalink($product_id));
				$this->current_item_id = null;
				$counts['created']++;
			} catch (\Throwable $e) {
				// thrown Error, keep batch
				$msg = $e->getMessage();
				error_log('[product-upload] ' . get_class($e) . ': ' . $msg);
				$this->api->confirm_import_job($item_id, null, null, substr($msg, 0, 900), 'STORE_CREATE_EXCEPTION');
				$this->current_item_id = null;
				$counts['failed']++;
			}
		}

		$this->current_item_id = null; // batch done, disarm
		return $counts;
	}

	/**
	 * Report an in-flight item's PHP fatal before the process dies.
	 *
	 * Registered per drain() pass. No-ops on a clean end-of-request (nothing in
	 * flight, or no fatal). Maintenance: keep the drain loop nulling
	 * current_item_id on every exit path or a healthy item could be mis-reported.
	 */
	public function handle_fatal_shutdown()
	{
		$item_id = $this->current_item_id;
		if (empty($item_id) || $this->reported) {
			return;
		}

		$err = error_get_last();
		$fatal = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
		if (!$err || !in_array($err['type'], $fatal, true)) {
			return; // in-flight but clean exit
		}

		$this->reported = true;

		// budget for the POST
		if (function_exists('set_time_limit')) {
			@set_time_limit(15);
		}
		// raise mem only if needed
		$cur = wp_convert_hr_to_bytes((string) ini_get('memory_limit'));
		if ($cur > 0 && $cur < 256 * 1024 * 1024) {
			@ini_set('memory_limit', '256M');
		}

		// late-success re-check
		$existing = $this->find_product_by_item_id($item_id);
		if ($existing) {
			$this->api->confirm_import_job($item_id, (string) $existing, get_permalink($existing));
			$this->current_item_id = null;
			return;
		}

		$code = (strpos($err['message'], 'Maximum execution time') !== false)
			? 'STORE_CREATE_TIMEOUT_FATAL'
			: ((strpos($err['message'], 'Allowed memory size') !== false)
				? 'STORE_CREATE_OOM'
				: 'STORE_CREATE_FATAL');
		$msg = substr(
			'PHP fatal during WooCommerce create: ' . $err['message']
				. ' @ ' . basename($err['file']) . ':' . $err['line'],
			0,
			900
		);

		error_log('[product-upload] ' . $msg); // survives a lost POST
		$this->api->confirm_import_job($item_id, null, null, $msg, $code);
		$this->current_item_id = null;
	}

	/**
	 * Find a product previously created for a given import item id.
	 *
	 * @param  string $item_id import_job_item id.
	 * @return int Product ID, or 0 if none.
	 */
	private function find_product_by_item_id($item_id)
	{
		$ids = get_posts(
			array(
				'post_type' => 'product',
				'post_status' => 'any',
				'numberposts' => 1,
				'fields' => 'ids',
				'meta_key' => self::ITEM_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $item_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return !empty($ids) ? (int) $ids[0] : 0;
	}

}
