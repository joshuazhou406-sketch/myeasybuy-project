<?php
/**
 * Maps ProductUpload API data to WooCommerce products.
 *
 * Creates WC_Product_Simple for single-variant products and
 * WC_Product_Variable + WC_Product_Variation for multi-variant products.
 *
 * @package ProductUploadForWoocommerce\Import
 */

namespace ProductUploadForWoocommerce\Import;

use ProductUploadForWoocommerce\Api\ApiClient;

defined('ABSPATH') || exit;

/**
 * ProductImporter class.
 *
 * @since 1.0.0
 */
class ProductImporter
{

	/** Post meta linking a product to the background-import item that created it. */
	const ITEM_META = '_pu_import_item_id';

	/** Post meta storing the supplier URL a product was imported from. */
	const SOURCE_META = '_product_upload_source_url';

	/** Normalized SOURCE_META, matched on re-import to update instead of duplicate. */
	const SOURCE_KEY_META = '_pu_source_key';

	/** Set once every pre-existing product has been given a SOURCE_KEY_META. */
	const BACKFILL_OPTION = 'pu_wc_source_key_backfilled';

	/** Variation meta holding the supplier's own per-variant id (the ASIN on Amazon). */
	const VARIANT_SKU_META = '_pu_source_variant_sku';

	/** @var ApiClient */
	private $api;

	/** @var ImageHandler */
	private $images;

	/**
	 * Constructor.
	 *
	 * @param ApiClient    $api    API client instance.
	 * @param ImageHandler $images Image handler instance.
	 */
	public function __construct(ApiClient $api, ImageHandler $images)
	{
		$this->api = $api;
		$this->images = $images;
	}

	// ------------------------------------------------------------------
	// Re-import matching
	// ------------------------------------------------------------------

	/**
	 * Reduce a supplier URL to a stable comparison key.
	 *
	 * Mirrors normalizeSourceUrl() in main-app/libs/services/site/importedMatch.ts:
	 * drops scheme, www, query and fragment, but keeps ?id= because marketplaces
	 * like Taobao and 1688 identify the product with it.
	 *
	 * @param  string $url Supplier URL.
	 * @return string      Comparison key ('' when the URL is unusable).
	 */
	public static function normalize_source_url($url)
	{
		$lower = strtolower(trim((string)$url));
		if ($lower === '') {
			return '';
		}

		$id = '';
		if (preg_match('/[?&]id=([^&#]+)/', $lower, $matches)) {
			$id = $matches[1];
		}

		$base = preg_replace('#^https?://#', '', $lower);
		$base = preg_replace('#^www\.#', '', $base);
		$base = preg_replace('/[?#].*$/', '', $base);
		$base = preg_replace('#/{2,}#', '/', $base);
		$base = rtrim($base, '/');

		if ($base === '') {
			return '';
		}

		return $id ? $base . '?id=' . $id : $base;
	}

	/**
	 * Find a product previously imported from the same supplier URL.
	 *
	 * Checks the normalized key first, then falls back to scanning products that
	 * only carry the raw URL (imported before this meta existed), backfilling the
	 * key on a hit so the cheap path works next time.
	 *
	 * @param  string $url Supplier URL.
	 * @return int         Product ID, or 0 when this URL hasn't been imported.
	 */
	public function find_product_by_source_url($url)
	{
		$key = self::normalize_source_url($url);
		if ($key === '') {
			return 0;
		}

		$ids = get_posts(array(
			'post_type' => 'product',
			'post_status' => 'any',
			'numberposts' => 1,
			'fields' => 'ids',
			'meta_key' => self::SOURCE_KEY_META,
			'meta_value' => $key,
		));

		if (!empty($ids)) {
			return (int)$ids[0];
		}

		// Legacy rows store the raw URL, so the LIKE needles have to be pieces
		// that survive normalization — the normalized key itself does not match a
		// raw value whenever normalizing rewrote it (a "//" in the path being the
		// common case). Host plus the last path segment are both untouched, and
		// together they stay selective; the loop below is what actually decides.
		$base = preg_replace('/\?id=.*$/', '', $key);
		$needles = array();

		$slash = strpos($base, '/');
		$needles[] = $slash === false ? $base : substr($base, 0, $slash);

		$last_segment = substr(strrchr($base, '/') ?: '', 1);
		if ($last_segment !== '') {
			$needles[] = $last_segment;
		}

		// Marketplaces share one path across every listing, so the id in the query
		// is the only selective part.
		if (preg_match('/\?id=(.+)$/', $key, $matches)) {
			$needles[] = 'id=' . $matches[1];
		}

		$conditions = array('relation' => 'AND');
		foreach ($needles as $needle) {
			$conditions[] = array(
				'key' => self::SOURCE_META,
				'value' => $needle,
				'compare' => 'LIKE',
			);
		}

		$legacy_ids = get_posts(array(
			'post_type' => 'product',
			'post_status' => 'any',
			'numberposts' => 100,
			'fields' => 'ids',
			'meta_query' => $conditions,
		));

		foreach ($legacy_ids as $legacy_id) {
			$stored = get_post_meta($legacy_id, self::SOURCE_META, true);
			if (self::normalize_source_url($stored) === $key) {
				update_post_meta($legacy_id, self::SOURCE_KEY_META, $key);
				return (int)$legacy_id;
			}
		}

		return 0;
	}

	/**
	 * Stamp the normalized key onto products imported before that meta existed,
	 * so re-imports find them on the exact-match path instead of leaning on the
	 * LIKE fallback.
	 *
	 * Runs a batch per admin request until every product is covered, then becomes
	 * a single option read. Products whose stored URL normalizes to nothing still
	 * get an empty key written, otherwise they'd be rescanned forever.
	 *
	 * @param int $batch Products to process in one pass.
	 */
	public static function backfill_source_keys($batch = 200)
	{
		if (get_option(self::BACKFILL_OPTION)) {
			return;
		}

		$ids = get_posts(array(
			'post_type' => 'product',
			'post_status' => 'any',
			'numberposts' => $batch,
			'fields' => 'ids',
			'meta_query' => array(
				'relation' => 'AND',
				array('key' => self::SOURCE_META, 'compare' => 'EXISTS'),
				array('key' => self::SOURCE_KEY_META, 'compare' => 'NOT EXISTS'),
			),
		));

		foreach ($ids as $id) {
			update_post_meta(
				$id,
				self::SOURCE_KEY_META,
				self::normalize_source_url(get_post_meta($id, self::SOURCE_META, true))
			);
		}

		if (count($ids) < $batch) {
			update_option(self::BACKFILL_OPTION, 1, false);
		}
	}

	/**
	 * Load the product to write into: the existing one when this is a re-import,
	 * otherwise a new object. Converts type in place if the source changed between
	 * single- and multi-variant.
	 *
	 * @param  int    $existing_id Product ID to update, or 0 to create.
	 * @param  string $type        'simple' or 'variable'.
	 * @return \WC_Product
	 */
	private function load_product_for_write($existing_id, $type)
	{
		$existing = $existing_id ? wc_get_product($existing_id) : null;

		if (!$existing) {
			return $type === 'variable' ? new \WC_Product_Variable() : new \WC_Product_Simple();
		}

		if (!$existing->is_type($type)) {
			// Dropping to simple: clear variations first or they outlive the
			// parent's variable type as orphans.
			if ($type === 'simple') {
				$this->delete_variations($existing_id);
			}
			wp_set_object_terms($existing_id, $type, 'product_type');
			return $type === 'variable'
				? new \WC_Product_Variable($existing_id)
				: new \WC_Product_Simple($existing_id);
		}

		return $existing;
	}

	/**
	 * Remove a variable product's existing variations so a re-import rebuilds them
	 * instead of stacking a second set alongside the old.
	 *
	 * @param int $product_id Parent product ID.
	 */
	private function delete_variations($product_id)
	{
		$children = get_posts(array(
			'post_type' => 'product_variation',
			'post_parent' => $product_id,
			'post_status' => 'any',
			'numberposts' => -1,
			'fields' => 'ids',
		));

		foreach ($children as $child_id) {
			wp_delete_post($child_id, true);
		}
	}

	// ------------------------------------------------------------------
	// Public entry point
	// ------------------------------------------------------------------

	/**
	 * Import a product by supplier URL.
	 *
	 * @param  string $url Supplier product URL.
	 * @return array|\WP_Error  { product_id, product_name, edit_url } on success.
	 */
	public function import($url)
	{
		// 1. Fetch AI-enhanced product data.
		$data = $this->api->query_product($url);
		if (is_wp_error($data)) {
			return $data;
		}

		// 1a. Category-page response — upstream detected this URL was a
		// listing page, returned the extracted product list, and burned no
		// credit. Pass the payload back to the REST controller untouched;
		// the React frontend opens a triage modal and re-queues the
		// products the user picks as individual single-URL imports.
		if (!empty($data['kind']) && $data['kind'] === 'category') {
			return $data;
		}

		return $this->import_from_data($data, $url);
	}

	/**
	 * Create a WooCommerce product from already-normalized API data.
	 *
	 * Shared by the synchronous URL path (import()) and the background-job
	 * drain (JobDrain), which gets the same /query-shaped payload from
	 * /import-jobs/claim. Skips the remote query + category check; the caller
	 * has the product data in hand already.
	 *
	 * @param  array  $data       Normalized ProductLookupData (see ApiClient::normalize_payload).
	 * @param  string $source_url Originating supplier URL (stored as meta).
	 * @param  string $item_id    Background-import item id; stamped as dedup meta in the same save.
	 * @return array|\WP_Error    { product_id, product_name, edit_url } on success.
	 */
	public function import_from_data(array $data, $source_url = '', $item_id = '')
	{
		// 1b. Load locally-stored import defaults (saved by ImportController).
		$defaults = wp_parse_args(
			(array)get_option('pu_wc_additional_settings', array()),
			array(
			'product_status' => 'draft',
			'import_brand' => true,
			'use_global_attributes' => false,
			'track_stock' => false,
			'stock_quantity' => 1,
			'convert_images' => false,
			'rename_image_files' => false,
			// Pricing
			'price_multiplier' => 1,
			'auto_cost_price' => false,
			'auto_compare_at_price' => false,
			'compare_at_multiplier' => 1.2,
			'price_rounding' => 'none',
			'min_price' => 0,
		)
		);

		// 2. Build description HTML (with specs table appended).
		$description = $this->build_description($data);

		// 2a. Re-import of a URL already in this store updates that product
		// rather than creating a second copy of it.
		$resolved_url = $data['product_source_url'] ?? $source_url;
		$existing_id = $this->find_product_by_source_url($resolved_url);

		// 3. Simple vs. variable.
		$variants = $data['product_variants'] ?? array();
		$has_variants = $this->has_meaningful_variants($variants);

		// Our SKUs are unique; WC's REST lock misfires
		$skip_sku_lock = static function () {
			return true;
		};
		add_filter('wc_product_pre_lock_on_sku', $skip_sku_lock);
		try {
			if ($has_variants) {
				$product_id = $this->create_variable_product($data, $description, $defaults, $item_id, $existing_id);
			}
			else {
				$product_id = $this->create_simple_product($data, $description, $defaults, $item_id, $existing_id);
			}
		}
		finally {
			remove_filter('wc_product_pre_lock_on_sku', $skip_sku_lock);
		}

		if (is_wp_error($product_id)) {
			return $product_id;
		}

		// 4. Sideload media. On an update the existing gallery is kept, so a
		// re-import doesn't re-download every image or stack duplicates.
		if (!$existing_id || !get_post_thumbnail_id($product_id)) {
			$this->attach_images(
				$product_id,
				$data,
				!empty($defaults['convert_images']),
				$this->image_name_base($data, $defaults)
			);
		}

		// 5. SEO meta (Yoast / RankMath).
		$this->set_seo_meta($product_id, $data);

		// 6. Store source URL, raw and normalized.
		update_post_meta($product_id, self::SOURCE_META, esc_url_raw($resolved_url));
		update_post_meta($product_id, self::SOURCE_KEY_META, self::normalize_source_url($resolved_url));

		return array(
			'product_id' => $product_id,
			'product_name' => get_the_title($product_id),
			'edit_url' => admin_url('post.php?post=' . $product_id . '&action=edit'),
			'thumbnail_url' => (string)get_the_post_thumbnail_url($product_id, 'thumbnail'),
			'updated' => (bool)$existing_id,
		);
	}

	/**
	 * Finish media + SEO on a product a prior drain pass created but didn't
	 * complete (process died after save() but before attach_images). Safe to
	 * call on a re-claim: no-ops when a featured image is already set.
	 *
	 * @param  int   $product_id Existing product ID.
	 * @param  array $data       Normalized ProductLookupData.
	 */
	public function ensure_media($product_id, array $data)
	{
		if (get_post_thumbnail_id($product_id)) {
			return;
		}
		$settings = get_option('pu_wc_additional_settings', array());
		$settings = is_array($settings) ? $settings : array();
		$convert = !empty($settings['convert_images']);
		$this->attach_images($product_id, $data, $convert, $this->image_name_base($data, $settings));
		$this->set_seo_meta($product_id, $data);
	}

	/**
	 * Filename stem for this product's images, or '' when the merchant hasn't
	 * opted in (or the title sanitises away to nothing). Prefers the slug: it
	 * is what the product is reachable by, so the files stay traceable to it.
	 *
	 * @param  array $data     ProductLookupData array.
	 * @param  array $settings Import defaults from pu_wc_additional_settings.
	 * @return string
	 */
	private function image_name_base(array $data, array $settings)
	{
		if (empty($settings['rename_image_files'])) {
			return '';
		}
		$raw = $data['product_url_slug'] ?? '';
		if ($raw === '') {
			$raw = $data['product_name'] ?? '';
		}
		return ImageHandler::name_base($raw);
	}

	// ------------------------------------------------------------------
	// Simple product
	// ------------------------------------------------------------------

	/**
	 * Create a WC_Product_Simple from API data.
	 *
	 * @param  array  $data        ProductLookupData array.
	 * @param  string $description Built HTML description.
	 * @param  array  $defaults    Import defaults from pu_wc_additional_settings.
	 * @return int|\WP_Error       Product ID on success.
	 */
	private function create_simple_product(array $data, $description, array $defaults = array(), $item_id = '', $existing_id = 0)
	{
		$product = $this->load_product_for_write($existing_id, 'simple');

		// Dedup key persisted in the same save as the product row.
		if ($item_id) {
			$product->update_meta_data(self::ITEM_META, $item_id);
		}

		// Sections split out of the description render as their own tabs.
		ProductTabs::stash($product, $data['product_info_sections'] ?? array());

		$product->set_name($this->truncate($data['product_name'], 200));
		$product->set_description($description);
		$product->set_short_description(
			$this->truncate(wp_strip_all_tags($data['product_description'] ?? ''), 400)
		);

		// Status and slug are the merchant's after the first import: re-importing
		// must not unpublish a live product or break its permalink.
		if (!$existing_id) {
			$product->set_status($defaults['product_status'] ?? 'draft');
			$this->set_url_slug($product, $data);
		}

		// Price.
		$prices = $this->compute_prices($data['product_price'] ?? 0, $defaults);
		$this->apply_prices($product, $prices);

		// SKU + GTIN.
		$this->set_sku($product, $data);
		$this->set_global_unique_id($product, $data, $defaults);

		// Weight & dimensions.
		$this->set_shipping($product, $data);

		// Tags from SEO keywords.
		$this->set_tags($product, $data);

		// Brand (attribute + taxonomy if available) - only when enabled.
		if (!empty($defaults['import_brand'])) {
			$this->set_brand($product, $data);
		}

		$this->apply_default_categories($product, $defaults);

		// Stock management. Manual track_stock setting wins over the
		// upstream availability hint when both are set.
		if (!empty($defaults['track_stock'])) {
			$product->set_manage_stock(true);
			$product->set_stock_quantity((int)($defaults['stock_quantity'] ?? 1));
			$product->set_stock_status('instock');
			$product->set_backorders($defaults['backorders'] ?? 'no');
		}

		$product->set_tax_status($defaults['tax_status'] ?? 'taxable');

		$product_id = $product->save();

		if (!$product_id) {
			return new \WP_Error('save_failed', __('Failed to save product.', 'product-upload'));
		}

		$this->apply_brand_taxonomy($product_id);

		return $product_id;
	}

	// ------------------------------------------------------------------
	// Variable product
	// ------------------------------------------------------------------

	/**
	 * Create a WC_Product_Variable with variations from API data.
	 *
	 * @param  array  $data        ProductLookupData array.
	 * @param  string $description Built HTML description.
	 * @param  array  $defaults    Import defaults from pu_wc_additional_settings.
	 * @return int|\WP_Error       Product ID on success.
	 */
	private function create_variable_product(array $data, $description, array $defaults = array(), $item_id = '', $existing_id = 0)
	{
		$variants = $data['product_variants'];
		$base_price = floatval($data['product_price'] ?? 0);

		$product = $this->load_product_for_write($existing_id, 'variable');

		// Dedup key persisted in the same save as the product row.
		if ($item_id) {
			$product->update_meta_data(self::ITEM_META, $item_id);
		}

		// Sections split out of the description render as their own tabs.
		ProductTabs::stash($product, $data['product_info_sections'] ?? array());

		$product->set_name($this->truncate($data['product_name'], 200));
		$product->set_description($description);
		$product->set_short_description(
			$this->truncate(wp_strip_all_tags($data['product_description'] ?? ''), 400)
		);

		// Status and slug are the merchant's after the first import: re-importing
		// must not unpublish a live product or break its permalink.
		if (!$existing_id) {
			$product->set_status($defaults['product_status'] ?? 'draft');
			$this->set_url_slug($product, $data);
		}
		$this->set_global_unique_id($product, $data, $defaults);

		// Cost meta on the parent — variations carry their own. Only set when
		// the auto-cost feature is on.
		if (!empty($defaults['auto_cost_price'])) {
			$product->update_meta_data('_cost_price', round($base_price, 2));
		}

		$this->set_shipping($product, $data);
		$this->set_tags($product, $data);

		// Build attributes from variant option axes. Global attributes (pa_*)
		// are opt-in: they enable layered-nav filtering on the shop page but
		// once registered they're permanent in the WC attributes list. Default
		// off so existing imports keep their custom-attribute shape.
		$option_axes = $this->collect_option_axes($variants);
		$use_global = !empty($defaults['use_global_attributes']);
		$attributes = array();
		$position = 0;

		foreach ($option_axes as $label => $values) {
			$attribute = new \WC_Product_Attribute();
			if ($use_global) {
				$taxonomy = $this->ensure_global_attribute_taxonomy($label);
				if ($taxonomy) {
					$attribute->set_id($this->resolve_attribute_id($taxonomy));
					$attribute->set_name($taxonomy);
					$attribute->set_options($this->ensure_attribute_terms($taxonomy, $values));
				}
				else {
					$attribute->set_name($label);
					$attribute->set_options($values);
				}
			}
			else {
				$attribute->set_name($label);
				$attribute->set_options($values);
			}
			$attribute->set_position($position++);
			$attribute->set_visible(true);
			$attribute->set_variation(true);
			$attributes[] = $attribute;
		}

		$product->set_attributes($attributes);

		// Brand (must come after set_attributes so it appends rather than gets overwritten).
		if (!empty($defaults['import_brand'])) {
			$this->set_brand($product, $data);
		}

		$this->apply_default_categories($product, $defaults);

		$product->set_tax_status($defaults['tax_status'] ?? 'taxable');

		$product_id = $product->save();

		if (!$product_id) {
			return new \WP_Error('save_failed', __('Failed to save variable product.', 'product-upload'));
		}

		$this->apply_brand_taxonomy($product_id);

		// Rebuild variations from scratch so removed/renamed source options don't
		// linger alongside the new set.
		if ($existing_id) {
			$this->delete_variations($product_id);
		}

		// Create individual variations.
		$base_sku = $this->generate_sku_base($data);

		$image_base = $this->image_name_base($data, $defaults);
		foreach ($variants as $index => $variant) {
			$this->create_variation($product_id, $variant, $index, $base_sku, $base_price, $data['product_name'], $defaults, $image_base);
		}

		// Sync prices and availability from variations.
		\WC_Product_Variable::sync($product_id);

		return $product_id;
	}

	/**
	 * Create a single product variation.
	 *
	 * @param int    $product_id   Parent variable product ID.
	 * @param array  $variant      Variant data from API.
	 * @param int    $index        Zero-based variant index.
	 * @param string $base_sku     Base SKU to derive variation SKU from.
	 * @param float  $base_price   Fallback price if variant has no price.
	 * @param string $product_name Product name for alt text.
	 * @param array  $defaults     Import defaults from pu_wc_additional_settings.
	 * @param string $image_base   Filename stem for the variant image ('' = keep
	 *                             the supplier's filename).
	 */
	private function create_variation($product_id, array $variant, $index, $base_sku, $base_price, $product_name, array $defaults = array(), $image_base = '')
	{
		$variation = new \WC_Product_Variation();
		$variation->set_parent_id($product_id);
		$variation->set_status('publish');

		// Stock management.
		if (!empty($defaults['track_stock'])) {
			$variation->set_manage_stock(true);
			$variation->set_stock_quantity((int)($defaults['stock_quantity'] ?? 1));
			$variation->set_stock_status('instock');
			$variation->set_backorders($defaults['backorders'] ?? 'no');
		}
		else {
			$variation->set_manage_stock(false);
		}

		// Price.
		$var_price = !empty($variant['price']) ? floatval($variant['price']) : $base_price;
		$var_prices = $this->compute_prices($var_price, $defaults);
		$this->apply_prices($variation, $var_prices);

		// SKU.
		$var_sku = $base_sku . '-' . ($index + 1);
		try {
			$variation->set_sku($var_sku);
		}
		catch (\Exception $e) {
			$variation->set_sku($var_sku . '-' . wp_rand(100, 999));
		}

		// The supplier's own id for this variant, which is the ASIN on Amazon.
		// Stored as meta rather than the SKU so it can't collide with WC's
		// unique-SKU rule or the merchant's own numbering.
		$source_variant_sku = $variant['sourceSku'] ?? ($variant['sku'] ?? ($variant['sourceVariantId'] ?? ''));
		if (!empty($source_variant_sku)) {
			$variation->update_meta_data(
				self::VARIANT_SKU_META,
				sanitize_text_field((string)$source_variant_sku)
			);
		}

		// Attributes. Two shapes:
		//   custom (default):    key = sanitize_title(label),  value = raw text
		//   global (pa_* opt-in): key = "pa_<slug>",            value = term slug
		// WC matches variations to parent attributes by these keys, so they
		// MUST agree with the shape used in create_variable_product above.
		$use_global = !empty($defaults['use_global_attributes']);
		$attrs = array();
		for ($i = 1; $i <= 3; $i++) {
			$label_key = 'option' . $i . 'Label';
			$value_key = 'option' . $i . 'Value';
			if (empty($variant[$label_key]) || empty($variant[$value_key])) {
				continue;
			}
			$label = (string)$variant[$label_key];
			$value = $this->truncate(sanitize_text_field($variant[$value_key]), 49);

			$key = sanitize_title($label);
			if ($use_global) {
				$taxonomy = $this->ensure_global_attribute_taxonomy($label);
				if ($taxonomy) {
					$key = $taxonomy;
					$term = term_exists($value, $taxonomy);
					if (!$term) {
						$term = wp_insert_term($value, $taxonomy);
					}
					if (!is_wp_error($term)) {
						$term_id = (int)(is_array($term) ? $term['term_id'] : $term);
						$term_obj = get_term($term_id, $taxonomy);
						if ($term_obj && !is_wp_error($term_obj)) {
							$value = $term_obj->slug;
						}
					}
				}
			}
			$attrs[$key] = $value;
		}
		$variation->set_attributes($attrs);

		// Weight. Only when this variant carries its own, so variants without
		// one keep inheriting the parent's.
		$var_weight = $variant['weight'] ?? null;
		if (is_numeric($var_weight) && floatval($var_weight) > 0) {
			$wc_unit = strtolower(get_option('woocommerce_weight_unit', 'kg'));
			$var_unit = strtolower($variant['weightUnit'] ?? ($variant['weight_unit'] ?? 'kg'));
			// An unrecognised unit falls back to kg, which is what the importer
			// emits. convert_weight's own fallback would read it as grams, so a
			// junk unit would land 1000x light instead.
			if (!in_array($var_unit, array('g', 'kg', 'lb', 'lbs', 'oz'), true)) {
				$var_unit = 'kg';
			}
			// 4dp, not the default 2: these are gram-scale.
			$variation->set_weight(
				$this->convert_weight(floatval($var_weight), $var_unit, $wc_unit, 4)
			);
		}

		// Variant image.
		$variant_img = $variant['image_url'] ?? ($variant['imageUrl'] ?? '');
		if (!empty($variant_img)) {
			$attach_id = $this->images->sideload(
				$variant_img,
				$product_id,
				$product_name . ' – Variant ' . ($index + 1),
				!empty($defaults['convert_images']),
				$image_base ? $image_base . '-variant-' . ($index + 1) : ''
			);
			if (!is_wp_error($attach_id)) {
				$variation->set_image_id($attach_id);
			}
		}

		$variation->save();
	}

	// ------------------------------------------------------------------
	// Description / specs
	// ------------------------------------------------------------------

	/**
	 * Append a specs HTML table to the product description.
	 *
	 * @param  array  $data ProductLookupData array.
	 * @return string       Full description HTML.
	 */
	private function build_description(array $data)
	{
		$desc = $data['product_description'] ?? '';
		$specs = $data['product_specs'] ?? array();

		if (empty($specs) || !is_array($specs)) {
			return $desc;
		}

		// description already contains the table
		if (!empty($data['specs_rendered_in_description'])) {
			return $desc;
		}

		$settings = get_option('pu_wc_additional_settings', array());
		$format = $settings['description_spec_format'] ?? 'table';

		if ($format === 'list') {
			$specs_html = '';
			foreach ($specs as $spec) {
				$key = esc_html($spec['key'] ?? '');
				$value = esc_html($spec['value'] ?? '');
				if ($key && $value) {
					$specs_html .= '<strong>' . $key . ':</strong> ' . $value . '<br/>';
				}
			}
		} elseif ($format === 'bullet') {
			$specs_html = '<ul>';
			foreach ($specs as $spec) {
				$key = esc_html($spec['key'] ?? '');
				$value = esc_html($spec['value'] ?? '');
				if ($key && $value) {
					$specs_html .= '<li>' . $key . ': ' . $value . '</li>';
				}
			}
			$specs_html .= '</ul>';
		} else {
			$specs_html = '<table><tbody>';
			foreach ($specs as $spec) {
				$key = esc_html($spec['key'] ?? '');
				$value = esc_html($spec['value'] ?? '');
				if ($key && $value) {
					$specs_html .= '<tr><td><strong>' . $key . '</strong></td><td>' . $value . '</td></tr>';
				}
			}
			$specs_html .= '</tbody></table>';
		}

		return $desc . '<br /><br />' . $specs_html;
	}

	// ------------------------------------------------------------------
	// Images
	// ------------------------------------------------------------------

	/**
	 * Sideload product media and wire up featured image + gallery.
	 *
	 * @param int    $product_id Product post ID.
	 * @param array  $data       ProductLookupData array.
	 * @param bool   $convert    Transcode images to JPG via the ImageKit proxy.
	 * @param string $base_name  Filename stem for the saved files ('' = keep
	 *                           the supplier's filenames).
	 */
	private function attach_images($product_id, array $data, $convert = false, $base_name = '')
	{
		$media = $data['product_media'] ?? array();
		if (empty($media)) {
			return;
		}

		// Cap at 15 images to keep import times reasonable.
		$media = array_slice($media, 0, 15);

		$attachment_ids = $this->images->sideload_many($media, $product_id, $convert, $base_name);
		if (empty($attachment_ids)) {
			return;
		}

		set_post_thumbnail($product_id, $attachment_ids[0]);

		if (count($attachment_ids) > 1) {
			$gallery_ids = array_slice($attachment_ids, 1);
			update_post_meta($product_id, '_product_image_gallery', implode(',', $gallery_ids));
		}
	}

	// ------------------------------------------------------------------
	// SEO meta
	// ------------------------------------------------------------------

	/**
	 * Populate Yoast SEO and RankMath meta fields if those plugins are active.
	 *
	 * @param int   $product_id Product post ID.
	 * @param array $data       ProductLookupData array.
	 */
	private function set_seo_meta($product_id, array $data)
	{
		$seo = $data['product_seo'] ?? array();
		if (empty($seo)) {
			return;
		}

		$title = sanitize_text_field($seo['title'] ?? '');
		$description = sanitize_text_field($seo['description'] ?? '');
		$keywords = array_map('sanitize_text_field', (array)($seo['keywords'] ?? array()));

		// Yoast SEO.
		if (defined('WPSEO_VERSION')) {
			if ($title) {
				update_post_meta($product_id, '_yoast_wpseo_title', $title);
			}
			if ($description) {
				update_post_meta($product_id, '_yoast_wpseo_metadesc', $description);
			}
			if (!empty($keywords)) {
				update_post_meta($product_id, '_yoast_wpseo_focuskw', $keywords[0]);
			}
		}

		// RankMath SEO.
		if (class_exists('RankMath')) {
			if ($title) {
				update_post_meta($product_id, 'rank_math_title', $title);
			}
			if ($description) {
				update_post_meta($product_id, 'rank_math_description', $description);
			}
			if (!empty($keywords)) {
				update_post_meta(
					$product_id,
					'rank_math_focus_keyword',
					implode(', ', array_slice($keywords, 0, 5))
				);
			}
		}

		// AIOSEO 4.x stores per-post SEO in its own wp_aioseo_posts table,
		// not postmeta — the legacy meta keys our older code wrote to are
		// ignored. Full support requires either direct table writes or
		// using AIOSEO's PHP API; neither is shipped yet.
	}

	// ------------------------------------------------------------------
	// Tags
	// ------------------------------------------------------------------

	/**
	 * Map SEO keywords to WooCommerce product tags.
	 *
	 * @param \WC_Product $product WooCommerce product object.
	 * @param array       $data    ProductLookupData array.
	 */
	private function set_tags(\WC_Product $product, array $data)
	{
		$keywords = $data['product_seo']['keywords'] ?? array();
		if (empty($keywords)) {
			return;
		}

		$tag_ids = array();
		foreach ($keywords as $kw) {
			$kw = sanitize_text_field($kw);
			$term = term_exists($kw, 'product_tag');
			if (!$term) {
				$term = wp_insert_term($kw, 'product_tag');
			}
			if (!is_wp_error($term) && !empty($term)) {
				$tag_ids[] = (int)(is_array($term) ? $term['term_id'] : $term);
			}
		}

		if (!empty($tag_ids)) {
			$product->set_tag_ids($tag_ids);
		}
	}

	/**
	 * Assign the merchant's default categories, appending to any the product
	 * already has so a re-import never strips categories they added by hand.
	 *
	 * @param \WC_Product $product  Product to categorize.
	 * @param array       $defaults Import defaults.
	 */
	private function apply_default_categories(\WC_Product $product, array $defaults)
	{
		$raw = $defaults['default_categories'] ?? array();
		if (!is_array($raw) || empty($raw)) {
			return;
		}
		$ids = array();
		foreach ($raw as $value) {
			$id = (int)$value;
			if ($id > 0 && get_term($id, 'product_cat') instanceof \WP_Term) {
				$ids[] = $id;
			}
		}
		if (empty($ids)) {
			return;
		}
		$product->set_category_ids(
			array_values(array_unique(array_merge($product->get_category_ids(), $ids)))
		);
	}

	// ------------------------------------------------------------------
	// Brand
	// ------------------------------------------------------------------

	/**
	 * Set the product brand as a visible attribute and taxonomy term.
	 *
	 * If the `product_brand` taxonomy exists (WooCommerce Brands or similar
	 * plugin), the brand is also assigned as a taxonomy term. Otherwise it
	 * is added as a custom product attribute so it appears on the product
	 * page under "Additional information".
	 *
	 * @param \WC_Product $product WooCommerce product object.
	 * @param array       $data    ProductLookupData array.
	 */
	private function set_brand(\WC_Product $product, array $data)
	{
		$brand = sanitize_text_field($data['product_brand'] ?? '');
		if (empty($brand)) {
			return;
		}

		// If a brands taxonomy exists, assign the term.
		if (taxonomy_exists('product_brand')) {
			$brand = BrandCanonical::pick($brand, $this->store_brands());
			$term = term_exists($brand, 'product_brand');
			if (!$term) {
				$term = wp_insert_term($brand, 'product_brand');
				if (!is_wp_error($term)) {
					$this->store_brands[] = $brand;
				}
			}
			if (!is_wp_error($term)) {
				$term_id = (int)(is_array($term) ? $term['term_id'] : $term);
				// Product must be saved first for wp_set_object_terms,
				// so we store the brand to apply after save.
				$product->update_meta_data('_pu_wc_brand_term_id', $term_id);
			}
		}

		// Add as a visible product attribute so it shows in "Additional information".
		$existing = $product->get_attributes();
		$attribute = new \WC_Product_Attribute();
		$attribute->set_name(__('Brand', 'product-upload'));
		$attribute->set_options(array($brand));
		$attribute->set_position(0);
		$attribute->set_visible(true);
		$attribute->set_variation(false);

		$existing['brand'] = $attribute;
		$product->set_attributes($existing);
	}

	/** @var string[]|null Brand names in the store, read once per request. */
	private $store_brands = null;

	/**
	 * Every product_brand term name, including empty ones.
	 *
	 * @return string[]
	 */
	private function store_brands()
	{
		if ($this->store_brands === null) {
			$names = get_terms(array(
				'taxonomy'   => 'product_brand',
				'hide_empty' => false,
				'fields'     => 'names',
			));
			$this->store_brands = is_array($names) ? $names : array();
		}
		return $this->store_brands;
	}

	/**
	 * Apply brand taxonomy term after the product has been saved.
	 *
	 * @param int $product_id Product post ID.
	 */
	private function apply_brand_taxonomy($product_id)
	{
		$term_id = get_post_meta($product_id, '_pu_wc_brand_term_id', true);
		if ($term_id) {
			wp_set_object_terms($product_id, (int)$term_id, 'product_brand');
			delete_post_meta($product_id, '_pu_wc_brand_term_id');
		}
	}

	/**
	 * Set the product's Global Unique ID (GTIN/UPC/EAN/ISBN) from the
	 * upstream-extracted barcode. WC 8.7+ surfaces this as a first-class
	 * field used by Google Shopping feeds and other integrations.
	 *
	 * @param \WC_Product $product
	 * @param array       $data ProductLookupData; barcode lives at $data['barcode'].
	 */
	private function set_global_unique_id(\WC_Product $product, array $data, array $defaults = array())
	{
		$barcode = sanitize_text_field($data['barcode'] ?? '');
		// Fall back to the SKU as the GTIN when the merchant opts in and the
		// source has no barcode of its own. WC's GTIN field strips everything
		// but digits and hyphens, so a lettered SKU ("SUP-ABC-123") would land
		// as garbage ("--123") in a field that feeds Google Shopping. Only
		// reuse the SKU when it is a plausible barcode (8-14 digits).
		if (empty($barcode) && !empty($defaults['barcode_from_sku'])) {
			$sku = $product->get_sku();
			$digits = preg_replace('/[^0-9]/', '', $sku);
			if ($sku !== '' && preg_match('/^[0-9\- ]+$/', $sku)
				&& strlen($digits) >= 8 && strlen($digits) <= 14) {
				$barcode = $sku;
			}
		}
		if (empty($barcode)) {
			return;
		}
		// WC validates GTIN format + uniqueness and throws WC_Data_Exception on
		// a bad/duplicate barcode. A barcode isn't worth failing the whole
		// import, so set it through the validating setter and skip on reject.
		if (method_exists($product, 'set_global_unique_id')) {
			try {
				$product->set_global_unique_id($barcode);
			} catch (\WC_Data_Exception $e) {
				// invalid/dup barcode: import without it
			}
		} else {
			$product->update_meta_data('_global_unique_id', $barcode);
		}
	}

	/**
	 * Apply a clean SEO slug from the upstream extractor. WP would
	 * auto-generate from the title otherwise — fine, but the AI slug is
	 * usually shorter and keyword-focused.
	 *
	 * @param \WC_Product $product
	 * @param array       $data
	 */
	private function set_url_slug(\WC_Product $product, array $data)
	{
		$slug = sanitize_title($data['product_url_slug'] ?? '');
		if (empty($slug)) {
			return;
		}
		$product->set_slug($slug);
	}

	/**
	 * Find or create a global attribute taxonomy for a human label like
	 * "Color". Returns the registered taxonomy slug (e.g. "pa_color") or an
	 * empty string if creation fails (caller falls back to a custom
	 * attribute).
	 *
	 * Cached per-request via $global_attribute_cache to avoid re-querying
	 * for every variation axis.
	 *
	 * @param  string $label
	 * @return string Taxonomy slug, or '' on failure.
	 */
	private function ensure_global_attribute_taxonomy($label)
	{
		if (!function_exists('wc_create_attribute') || !function_exists('wc_get_attribute_taxonomies')) {
			return '';
		}

		$slug = sanitize_title($label);
		if (empty($slug)) {
			return '';
		}
		// WC truncates attribute slugs to 28 chars before prepending "pa_".
		$slug = substr($slug, 0, 28);
		$taxonomy = wc_attribute_taxonomy_name($slug);

		if (isset($this->global_attribute_cache[$taxonomy])) {
			return $this->global_attribute_cache[$taxonomy];
		}

		if (taxonomy_exists($taxonomy)) {
			$this->global_attribute_cache[$taxonomy] = $taxonomy;
			return $taxonomy;
		}

		$result = wc_create_attribute(array(
			'name' => $label,
			'slug' => $slug,
			'type' => 'select',
			'order_by' => 'menu_order',
			'has_archives' => false,
		));
		if (is_wp_error($result)) {
			$this->global_attribute_cache[$taxonomy] = '';
			return '';
		}

		// Register the taxonomy on-the-fly so wp_set_object_terms accepts
		// it within the same request — WC only registers all attribute
		// taxonomies on init, which has already fired.
		register_taxonomy(
			$taxonomy,
			'product',
			array(
				'label' => $label,
				'rewrite' => false,
				'public' => true,
				'hierarchical' => false,
				'show_ui' => false,
				'query_var' => true,
				'show_in_nav_menus' => false,
				'show_in_rest' => false,
			)
		);

		$this->global_attribute_cache[$taxonomy] = $taxonomy;
		return $taxonomy;
	}

	/** @var array<string,string> request-scoped cache of label → taxonomy */
	private $global_attribute_cache = array();

	/**
	 * Resolve the numeric attribute id for a taxonomy slug.
	 *
	 * @param  string $taxonomy e.g. "pa_color"
	 * @return int
	 */
	private function resolve_attribute_id($taxonomy)
	{
		if (!function_exists('wc_attribute_taxonomy_id_by_name')) {
			return 0;
		}
		$slug = preg_replace('/^pa_/', '', $taxonomy);
		return (int)wc_attribute_taxonomy_id_by_name($slug);
	}

	/**
	 * Ensure each value exists as a term in the global attribute taxonomy
	 * and return the slugs. WC variation matching uses term slugs, not
	 * raw values.
	 *
	 * @param  string $taxonomy
	 * @param  array  $values
	 * @return array  Term slugs in the same order as $values.
	 */
	private function ensure_attribute_terms($taxonomy, array $values)
	{
		$slugs = array();
		foreach ($values as $value) {
			$value = trim((string)$value);
			if ($value === '') {
				continue;
			}
			$term = term_exists($value, $taxonomy);
			if (!$term) {
				$term = wp_insert_term($value, $taxonomy);
			}
			if (is_wp_error($term)) {
				continue;
			}
			$term_id = (int)(is_array($term) ? $term['term_id'] : $term);
			$term_obj = get_term($term_id, $taxonomy);
			if ($term_obj && !is_wp_error($term_obj)) {
				$slugs[] = $term_obj->slug;
			}
		}
		return $slugs;
	}

	// ------------------------------------------------------------------
	// SKU
	// ------------------------------------------------------------------

	/**
	 * Set a unique SKU on a product object.
	 *
	 * @param \WC_Product $product WooCommerce product object.
	 * @param array       $data    ProductLookupData array.
	 */
	private function set_sku(\WC_Product $product, array $data)
	{
		$base = $this->generate_sku_base($data);
		try {
			$product->set_sku($base);
		}
		catch (\Exception $e) {
			// SKU exists - append a random suffix and retry once.
			try {
				$product->set_sku($base . '-' . wp_rand(100, 999));
			}
			catch (\Exception $e2) {
			// Give up; WooCommerce will leave SKU blank.
			}
		}
	}

	/**
	 * Build a base SKU string from product data.
	 *
	 * @param  array  $data ProductLookupData array.
	 * @return string       SKU base string.
	 */
	private function generate_sku_base(array $data)
	{
		$sku = $data['product_sku'] ?? '';
		$lookup_id = $data['product_lookup_id'] ?? '';

		if (!empty($sku)) {
			return sanitize_text_field($sku) . ($lookup_id ? '-' . substr($lookup_id, 0, 4) : '');
		}

		return $lookup_id ? substr($lookup_id, 0, 8) : substr(md5(uniqid()), 0, 8);
	}

	// ------------------------------------------------------------------
	// Shipping / dimensions
	// ------------------------------------------------------------------

	/**
	 * Set weight and dimensions, converting units to match store settings.
	 *
	 * @param \WC_Product $product  WooCommerce product object.
	 * @param array       $data     ProductLookupData array.
	 */
	private function set_shipping(\WC_Product $product, array $data)
	{
		$shipping = $data['product_shipping_dimensions'] ?? array();
		if (empty($shipping)) {
			return;
		}

		// Weight.
		$weight_data = $shipping['weight'] ?? array();
		if (!empty($weight_data['value'])) {
			$wc_unit = strtolower(get_option('woocommerce_weight_unit', 'kg'));
			$product->set_weight(
				$this->convert_weight(
				floatval($weight_data['value']),
				strtolower($weight_data['unit'] ?? 'kg'),
				$wc_unit
			)
			);
		}

		// Dimensions.
		$dims = $shipping['dimensions'] ?? array();
		if (!empty($dims)) {
			$wc_dim = strtolower(get_option('woocommerce_dimension_unit', 'cm'));
			$dim_unit = strtolower($dims['unit'] ?? 'cm');

			if (!empty($dims['length'])) {
				$product->set_length($this->convert_dimension(floatval($dims['length']), $dim_unit, $wc_dim));
			}
			if (!empty($dims['width'])) {
				$product->set_width($this->convert_dimension(floatval($dims['width']), $dim_unit, $wc_dim));
			}
			if (!empty($dims['height'])) {
				$product->set_height($this->convert_dimension(floatval($dims['height']), $dim_unit, $wc_dim));
			}
		}
	}

	// ------------------------------------------------------------------
	// Unit conversion helpers
	// ------------------------------------------------------------------

	/**
	 * Convert a weight value between units.
	 *
	 * @param  float  $value     Value in $from units.
	 * @param  string $from      Source unit (g, kg, lb, oz).
	 * @param  string $to        Target unit.
	 * @param  int    $precision Decimal places. Defaults to 2, which suits
	 *                           product-level weights. Gram-scale variant
	 *                           weights need more: in a kg store 2dp rounds a
	 *                           23 g part to 0.02 and anything under 5 g to 0.
	 * @return float             Converted value.
	 */
	private function convert_weight($value, $from, $to, $precision = 2)
	{
		if ($from === $to) {
			return round($value, $precision);
		}

		// Convert to grams, then to target.
		$to_grams = array(
			'g' => 1,
			'kg' => 1000,
			'lb' => 453.592,
			'lbs' => 453.592,
			'oz' => 28.3495,
		);

		$in_grams = $value * ($to_grams[$from] ?? 1);
		return round($in_grams / ($to_grams[$to] ?? 1), $precision);
	}

	/**
	 * Convert a dimension value between units.
	 *
	 * @param  float  $value Value in $from units.
	 * @param  string $from  Source unit (mm, cm, m, in, yd).
	 * @param  string $to    Target unit.
	 * @return float         Converted value rounded to 2 decimal places.
	 */
	private function convert_dimension($value, $from, $to)
	{
		if ($from === $to) {
			return round($value, 2);
		}

		// Convert to cm, then to target.
		// Backend emits 'inch'; WC stores 'in'.
		$to_cm = array(
			'cm' => 1,
			'mm' => 0.1,
			'm' => 100,
			'in' => 2.54,
			'inch' => 2.54,
			'inches' => 2.54,
			'yd' => 91.44,
		);

		$in_cm = $value * ($to_cm[$from] ?? 1);
		return round($in_cm / ($to_cm[$to] ?? 1), 2);
	}

	// ------------------------------------------------------------------
	// Variant helpers
	// ------------------------------------------------------------------

	/**
	 * Determine whether the product's variants are meaningfully variable.
	 *
	 * Returns false when there is only 1 variant, or all variants are
	 * "Title" placeholders (Shopify default for simple products).
	 *
	 * @param  array $variants Variants array from API.
	 * @return bool
	 */
	private function has_meaningful_variants(array $variants)
	{
		if (count($variants) <= 1) {
			return false;
		}

		foreach ($variants as $v) {
			$label1 = $v['option1Label'] ?? '';
			$label2 = $v['option2Label'] ?? '';
			$label3 = $v['option3Label'] ?? '';

			// Skip "Title"-only variants.
			if (strtolower($label1) === 'title' && empty($label2) && empty($label3)) {
				continue;
			}

			return true;
		}

		return false;
	}

	/**
	 * Collect unique option axes and their ordered unique values from variants.
	 *
	 * @param  array $variants Variants array from API.
	 * @return array           [ 'Color' => ['Red', 'Blue'], 'Size' => ['S', 'M', 'L'] ]
	 */
	private function collect_option_axes(array $variants)
	{
		$axes = array();

		foreach ($variants as $variant) {
			for ($i = 1; $i <= 3; $i++) {
				$label = $variant['option' . $i . 'Label'] ?? '';
				$value = $variant['option' . $i . 'Value'] ?? '';

				if (empty($label) || empty($value) || strtolower($label) === 'title') {
					continue;
				}

				$label = sanitize_text_field($label);
				$value = $this->truncate(sanitize_text_field($value), 49);

				if (!isset($axes[$label])) {
					$axes[$label] = array();
				}

				if (!in_array($value, $axes[$label], true)) {
					$axes[$label][] = $value;
				}
			}
		}

		return $axes;
	}

	// ------------------------------------------------------------------
	// String utilities
	// ------------------------------------------------------------------

	/**
	 * Truncate a multibyte string to a maximum length.
	 *
	 * @param  string $str String to truncate.
	 * @param  int    $max Maximum length in characters.
	 * @return string
	 */
	private function truncate($str, $max)
	{
		return mb_strlen($str) <= $max ? $str : mb_substr($str, 0, $max);
	}

	/**
	 * Apply pricing defaults (multiplier, min floor, rounding, on-sale,
	 * cost mapping) to a scraped price.
	 *
	 * Returns:
	 *   regular_price  -> WC's "Regular price" (crossed out when on sale)
	 *   sale_price     -> WC's "Sale price" (null when not on sale)
	 *   cost_price     -> mapped to _cost_price meta; null when feature off
	 *
	 * On-sale mapping (Shopify "compare at" pattern translated to WC):
	 *   regular_price = sellingPrice × compareAtMultiplier
	 *   sale_price    = sellingPrice
	 * WC then auto-shows the "% off" badge from the gap.
	 */
	private function compute_prices($scraped_price, array $defaults)
	{
		$scraped = floatval($scraped_price);
		$multiplier = floatval($defaults['price_multiplier'] ?? 1) ?: 1;
		$min = floatval($defaults['min_price'] ?? 0);

		$selling = $scraped * $multiplier;
		if ($min > 0 && $selling < $min) {
			$selling = $min;
		}
		$selling = $this->round_price($selling, $defaults['price_rounding'] ?? 'none');
		// never price under cost
		if ($multiplier >= 1 && $selling < $scraped) {
			$selling = $this->price_at_least($scraped, $defaults['price_rounding'] ?? 'none');
		}

		$regular = $selling;
		$sale = null;

		if (!empty($defaults['auto_compare_at_price'])) {
			$compare_mult = floatval($defaults['compare_at_multiplier'] ?? 1.2);
			if ($compare_mult > 1 && $selling > 0) {
				$regular = $this->round_price(
					$selling * $compare_mult,
					$defaults['price_rounding'] ?? 'none'
				);
				$sale = $selling;
			}
		}

		$cost = null;
		if (!empty($defaults['auto_cost_price'])) {
			// Cost = raw scraped price, NOT multiplied. The merchant's margin
			// is sellingPrice − costPrice, so cost should reflect what they
			// actually pay the supplier.
			$cost = round($scraped, 2);
		}

		return array(
			'regular' => round($regular, 2),
			'sale' => $sale === null ? null : round($sale, 2),
			'cost' => $cost,
		);
	}

	/**
	 * Round a price to a retail-friendly endpoint.
	 *
	 * 'none'  -> 2 decimal precision, no further rounding
	 * 'whole' -> ceil to next whole number (so prices read cleanly)
	 * '99'    -> next .99 ($9.99, $10.99)
	 * '95'    -> next .95
	 * '90'    -> next .90
	 * '49'    -> next .49 OR .99, whichever is closer above
	 */
	private function round_price($value, $mode)
	{
		$value = floatval($value);
		if ($value <= 0) {
			return 0;
		}
		switch ($mode) {
			case 'whole':
				return ceil($value);
			case '99':
				return floor($value) + 0.99;
			case '95':
				return floor($value) + 0.95;
			case '90':
				return floor($value) + 0.90;
			case '49':
				// Pick whichever .49 / .99 boundary is the next one up.
				$floor = floor($value);
				$candidates = array($floor + 0.49, $floor + 0.99);
				foreach ($candidates as $c) {
					if ($c >= $value) {
						return $c;
					}
				}
				return $floor + 1.49;
			case 'none':
			default:
				return round($value, 2);
		}
	}

	/**
	 * Smallest price fitting $mode that is still at least $minimum.
	 *
	 * The charm modes floor to their cents, so on a source price whose own
	 * cents sit above the target the rounded result lands BELOW what the
	 * merchant paid ($4.99 at x1 with .95 rounding sells for $4.95). This is
	 * the step-up that prevents it; round_price itself stays an exact mirror
	 * of the JS copies.
	 *
	 * Keep in sync with roundPriceAtLeast in
	 * main-app/libs/services/sync/priceRecipe.ts,
	 * shopify-app/api/utils/priceRounding.js and
	 * shopify-app/web/utils/priceRounding.js.
	 */
	private function price_at_least($minimum, $mode)
	{
		$min = floatval($minimum);
		if ($min <= 0) {
			return 0;
		}
		$floor = floor($min);
		// float slop, not a real gap
		$eps = 0.000000001;
		switch ($mode) {
			case 'whole':
				return ceil($min);
			case '99':
				return ($floor + 0.99 >= $min - $eps) ? $floor + 0.99 : $floor + 1.99;
			case '95':
				return ($floor + 0.95 >= $min - $eps) ? $floor + 0.95 : $floor + 1.95;
			case '90':
				return ($floor + 0.90 >= $min - $eps) ? $floor + 0.90 : $floor + 1.90;
			case '49':
				$candidates = array($floor + 0.49, $floor + 0.99, $floor + 1.49);
				foreach ($candidates as $c) {
					if ($c >= $min - $eps) {
						return $c;
					}
				}
				return $floor + 1.49;
			case 'none':
			default:
				return ceil($min * 100) / 100;
		}
	}

	/**
	 * Apply computed prices to a WC_Product or WC_Product_Variation.
	 */
	private function apply_prices($product, array $prices)
	{
		$regular = floatval($prices['regular'] ?? 0);
		$sale = $prices['sale'];

		$product->set_regular_price($regular);
		if ($sale !== null && $sale < $regular) {
			$product->set_sale_price($sale);
			$product->set_price($sale);
		}
		else {
			$product->set_sale_price('');
			$product->set_price($regular);
		}

		if ($prices['cost'] !== null) {
			$product->update_meta_data('_cost_price', floatval($prices['cost']));
		}
	}
}