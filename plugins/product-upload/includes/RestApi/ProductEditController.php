<?php

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages leave as JSON in a REST response, never as HTML.

/**
 * Signed read and edit of one product, as a list of small operations.
 *
 * ProductWriteController patches three fields. This is its wider sibling for
 * the AI-assistant connector: Product Upload reads the product (drafts and
 * variation ids included, which the public Store API cannot give it), sends a
 * list of operations, and each one changes only what it names. Nothing here
 * rebuilds a product, so nothing here can drop variations.
 *
 * Requests are HMAC-signed with the same key and check as the update route.
 *
 * @package ProductUploadForWoocommerce
 */

namespace ProductUploadForWoocommerce\RestApi;

use ProductUploadForWoocommerce\Import\ImageHandler;

defined('ABSPATH') || exit;

/**
 * Class ProductEditController
 */
class ProductEditController
{

	const ROUTE_NAMESPACE = 'product-upload/v1';
	const READ_PATH = '/product/read';
	const EDIT_PATH = '/product/edit';

	/** Beyond this a snapshot would be incomplete, so edits are refused. */
	const MAX_VARIATIONS = 500;
	const MAX_OPERATIONS = 100;
	const TITLE_MAX = 200;

	/** Product meta holding the ids of product-level option values. */
	const VALUE_IDS_META = '_pu_option_value_ids';

	/** Variant meta: stock state from before tracking was switched. */
	const STOCK_MEMORY_META = '_pu_stock_memory';

	/** WordPress actions that mean something was written. */
	const WRITE_HOOKS = array(
		'woocommerce_update_product',
		'woocommerce_update_product_variation',
		'woocommerce_new_product_variation',
		'added_post_meta',
		'updated_post_meta',
		'deleted_post_meta',
		'set_object_terms',
		'deleted_term_relationships',
		'delete_post',
		'add_attachment',
		'created_term',
	);

	/**
	 * Database writes seen during the current apply().
	 *
	 * @var int
	 */
	private $touched = 0;

	/**
	 * True while writing our own id bookkeeping, which is not an edit.
	 *
	 * @var bool
	 */
	private $bookkeeping = false;

	/**
	 * Signature check lives on the update route's controller.
	 *
	 * @var ProductWriteController
	 */
	private $writes;

	/**
	 * Image sideloader.
	 *
	 * @var ImageHandler
	 */
	private $images;

	/**
	 * Constructor.
	 *
	 * @param ProductWriteController $writes Holds the signature check.
	 * @param ImageHandler           $images Image sideloader.
	 */
	public function __construct(ProductWriteController $writes, ImageHandler $images)
	{
		$this->writes = $writes;
		$this->images = $images;
	}

	/**
	 * Hook the routes up.
	 */
	public function register()
	{
		add_action('rest_api_init', array($this, 'register_routes'));
	}

	/**
	 * Register the signed read and edit routes.
	 */
	public function register_routes()
	{
		foreach (array(self::READ_PATH => 'handle_read', self::EDIT_PATH => 'handle_edit') as $path => $method) {
			register_rest_route(
				self::ROUTE_NAMESPACE,
				$path,
				array(
					'methods' => \WP_REST_Server::CREATABLE,
					'callback' => array($this, $method),
					'permission_callback' => array($this->writes, 'verify_signature'),
				)
			);
		}
	}

	// ------------------------------------------------------------------
	// Handlers
	// ------------------------------------------------------------------

	/**
	 * Return the product's current state.
	 *
	 * @param  \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_read(\WP_REST_Request $request)
	{
		$product = $this->product_from($request->get_json_params());
		if (is_wp_error($product)) {
			return $product;
		}
		return rest_ensure_response(array('ok' => true, 'product' => $this->snapshot($product)));
	}

	/**
	 * Apply a list of operations in order, stopping at the first failure.
	 *
	 * @param  \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_edit(\WP_REST_Request $request)
	{
		$body = $request->get_json_params();
		$product = $this->product_from($body);
		if (is_wp_error($product)) {
			return $product;
		}
		$operations = isset($body['operations']) && is_array($body['operations']) ? $body['operations'] : array();
		if (empty($operations) || count($operations) > self::MAX_OPERATIONS) {
			return new \WP_Error(
				'bad_operations',
				__('Between 1 and 50 operations are required.', 'product-upload'),
				array('status' => 400)
			);
		}
		return rest_ensure_response($this->apply($product->get_id(), $operations));
	}

	/**
	 * Load the parent product named in the body.
	 *
	 * @param  array|null $body Request body.
	 * @return \WC_Product|\WP_Error
	 */
	private function product_from($body)
	{
		$product_id = isset($body['product_id']) ? absint($body['product_id']) : 0;
		$product = $product_id ? wc_get_product($product_id) : false;
		if (!$product || $product->is_type('variation')) {
			// same distinct code as the update route: not "plugin too old"
			return new \WP_Error(
				'product_upload_not_found',
				__('No product with that id.', 'product-upload'),
				array('status' => 404)
			);
		}
		return $product;
	}

	// ------------------------------------------------------------------
	// Read
	// ------------------------------------------------------------------

	/**
	 * The product in the shape Product Upload edits and undoes against.
	 *
	 * @param  \WC_Product $product Parent product.
	 * @return array
	 */
	public function snapshot($product)
	{
		$id = $product->get_id();
		$variant_ids = $product->is_type('variable') ? $product->get_children() : array($id);
		$truncated = count($variant_ids) > self::MAX_VARIATIONS;

		$variants = array();
		foreach (array_slice($variant_ids, 0, self::MAX_VARIATIONS) as $variant_id) {
			$variant = $variant_id === $id ? $product : wc_get_product($variant_id);
			if ($variant) {
				$variants[] = $this->variant_snapshot($variant, $product);
			}
		}

		$options = array();
		foreach ($product->get_attributes() as $key => $attribute) {
			if (!is_object($attribute) || !$attribute->get_variation()) {
				continue;
			}
			$values = array();
			foreach ($this->attribute_values($attribute, $id) as $value) {
				$values[] = array('id' => $value['id'], 'name' => $value['name']);
			}
			$options[] = array(
				'id' => (string) $key,
				'name' => wc_attribute_label($attribute->get_name(), $product),
				// shared with other products: its values cannot be renamed here
				'global' => $attribute->is_taxonomy(),
				'values' => $values,
			);
		}

		$media = array();
		foreach ($this->image_ids($product) as $attachment_id) {
			$alt = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
			$url = wp_get_attachment_url($attachment_id);
			$media[] = array(
				'id' => (string) $attachment_id,
				'alt' => '' === (string) $alt ? null : (string) $alt,
				'type' => 'IMAGE',
				'url' => $url ? $url : null,
			);
		}

		$collections = array();
		foreach ($this->terms($id, 'product_cat') as $term) {
			$collections[] = array('id' => (string) $term->term_id, 'title' => html_entity_decode($term->name, ENT_QUOTES, 'UTF-8'));
		}
		$brands = taxonomy_exists('product_brand') ? $this->terms($id, 'product_brand') : array();
		$status = $product->get_status();
		$seo = $this->read_seo($id);

		return array(
			'id' => (string) $id,
			'truncated' => $truncated,
			'title' => $product->get_name(),
			'handle' => $product->get_slug(),
			'descriptionHtml' => $product->get_description(),
			'vendor' => $brands ? html_entity_decode($brands[0]->name, ENT_QUOTES, 'UTF-8') : '',
			'productType' => '',
			// WordPress stores term names entity-encoded
			'tags' => array_map(
				function ($term) {
					return html_entity_decode($term->name, ENT_QUOTES, 'UTF-8');
				},
				$this->terms($id, 'product_tag')
			),
			'status' => 'publish' === $status ? 'ACTIVE' : strtoupper($status),
			'seo' => $seo,
			'options' => $options,
			'variants' => $variants,
			'media' => $media,
			'collections' => $collections,
			'metafields' => array(),
			// what this site can take, so the caller refuses up front
			'caps' => array(
				'variable' => $product->is_type('variable'),
				'seo' => null !== $this->seo_keys(),
				'brands' => taxonomy_exists('product_brand'),
				'barcode' => method_exists($product, 'set_global_unique_id'),
				'titleMax' => self::TITLE_MAX,
				// a rewrite would strip it: see wp_kses_post
				'descriptionEmbeds' => $this->loses_elements($product->get_description('edit')),
			),
		);
	}

	/**
	 * One variation (or the simple product itself) as a variant.
	 *
	 * @param  \WC_Product $variant Variation or simple product.
	 * @param  \WC_Product $parent  Parent product.
	 * @return array
	 */
	private function variant_snapshot($variant, $parent)
	{
		$regular = (string) $variant->get_regular_price('edit');
		$sale = (string) $variant->get_sale_price('edit');
		// a sale scheduled for later is not today's price
		$on_sale = 'running' === $this->sale_state($variant);
		$weight = (string) $variant->get_weight('edit');
		$tracked = true === $variant->get_manage_stock();
		$barcode = method_exists($variant, 'get_global_unique_id') ? (string) $variant->get_global_unique_id() : '';
		$image = $variant->is_type('variation') ? (int) $variant->get_image_id('edit') : 0;
		$units = array('kg' => 'KILOGRAMS', 'g' => 'GRAMS', 'lbs' => 'POUNDS', 'oz' => 'OUNCES');
		$unit = (string) get_option('woocommerce_weight_unit', 'kg');

		$option_values = array();
		if ($variant->is_type('variation')) {
			$attributes = $parent->get_attributes();
			foreach ($variant->get_attributes() as $key => $value) {
				// the parent's attribute knows the real taxonomy; the
				// variation's key is URL-encoded for non-Latin names
				$attribute = isset($attributes[$key]) && is_object($attributes[$key]) ? $attributes[$key] : null;
				$name = (string) $value;
				if ($attribute && $attribute->is_taxonomy()) {
					$term = get_term_by('slug', $value, $attribute->get_name());
					$name = $term ? html_entity_decode($term->name, ENT_QUOTES, 'UTF-8') : $name;
				}
				$option_values[] = array(
					'optionName' => wc_attribute_label($attribute ? $attribute->get_name() : $key, $parent),
					'name' => $name,
				);
			}
		}

		return array(
			'id' => (string) $variant->get_id(),
			'sku' => '' === (string) $variant->get_sku('edit') ? null : $variant->get_sku('edit'),
			'barcode' => '' === $barcode ? null : $barcode,
			'price' => $on_sale ? $sale : ('' === $regular ? null : $regular),
			'compareAt' => $on_sale ? $regular : null,
			'cost' => null,
			// in the site's own unit, so a restore writes back the same number
			'weight' => '' === $weight || !isset($units[$unit])
				? null
				: array('value' => (float) $weight, 'unit' => $units[$unit]),
			'tracked' => $tracked,
			'inventoryPolicy' => 'no' === $variant->get_backorders() ? 'DENY' : 'CONTINUE',
			'inventoryQuantity' => $tracked ? (int) $variant->get_stock_quantity() : null,
			'optionValues' => $option_values,
			'mediaId' => $image ? (string) $image : null,
		);
	}

	/**
	 * What a variant's sale price is doing: 'running' (customers pay it
	 * now), 'scheduled' (it starts later), 'stale' (set, but it can never
	 * apply: lapsed, or not below the regular price), or 'none'.
	 *
	 * @param  \WC_Product $variant Variation or simple product.
	 * @return string
	 */
	private function sale_state($variant)
	{
		$regular = (string) $variant->get_regular_price('edit');
		$sale = (string) $variant->get_sale_price('edit');
		if ('' === $sale) {
			return 'none';
		}
		$now = time();
		$from = $variant->get_date_on_sale_from('edit');
		$to = $variant->get_date_on_sale_to('edit');
		// WooCommerce only honours a sale below the regular price
		if ('' === $regular || (float) $sale >= (float) $regular || ($to && $to->getTimestamp() < $now)) {
			return 'stale';
		}
		return $from && $from->getTimestamp() > $now ? 'scheduled' : 'running';
	}

	/**
	 * An attribute's values. `id` stays the same through renames, deletes
	 * and reordering: the term id for a global attribute, and for a
	 * product-level one an id kept in product meta. A position would shift
	 * when an earlier value is deleted and point at the wrong value.
	 * `stored` is what a variation holds for that value.
	 *
	 * @param  \WC_Product_Attribute $attribute  Product attribute.
	 * @param  int                   $product_id Product id; 0 when ids are not needed.
	 * @return array[] Each: id, name, stored.
	 */
	private function attribute_values($attribute, $product_id = 0)
	{
		$out = array();
		if ($attribute->is_taxonomy()) {
			foreach ((array) $attribute->get_terms() as $term) {
				$out[] = array('id' => 't' . $term->term_id, 'name' => html_entity_decode($term->name, ENT_QUOTES, 'UTF-8'), 'stored' => $term->slug);
			}
			return $out;
		}
		$names = array_map('strval', array_values($attribute->get_options()));
		$ids = $product_id ? $this->value_ids($product_id, sanitize_title($attribute->get_name()), $names) : array();
		foreach ($names as $name) {
			$out[] = array('id' => isset($ids[$name]) ? $ids[$name] : '', 'name' => $name, 'stored' => $name);
		}
		return $out;
	}

	/**
	 * Ids for a product-level option's values, by name. New names get an id,
	 * names that are gone lose theirs, and a rename carries its id across.
	 *
	 * @param  int      $product_id Product id.
	 * @param  string   $key        Option key.
	 * @param  string[] $names      The option's current value names.
	 * @param  array    $renames    Old name => new name.
	 * @return array Name => id.
	 */
	private function value_ids($product_id, $key, array $names, array $renames = array())
	{
		$all = get_post_meta($product_id, self::VALUE_IDS_META, true);
		$all = is_array($all) ? $all : array();
		$known = isset($all[$key]) && is_array($all[$key]) ? $all[$key] : array();
		$stored = $known;
		foreach ($renames as $old => $new) {
			if (isset($known[$old])) {
				$known[$new] = $known[$old];
				unset($known[$old]);
			}
		}
		$ids = array();
		foreach ($names as $name) {
			$ids[$name] = isset($known[$name]) ? $known[$name] : 'v' . strtolower(wp_generate_password(8, false));
		}
		if ($ids !== $stored) {
			$all[$key] = $ids;
			$this->bookkeeping = true;
			update_post_meta($product_id, self::VALUE_IDS_META, $all);
			$this->bookkeeping = false;
		}
		return $ids;
	}

	/**
	 * Featured image first, then the gallery.
	 *
	 * @param  \WC_Product $product Product.
	 * @return int[]
	 */
	private function image_ids($product)
	{
		$ids = array_map('absint', $product->get_gallery_image_ids('edit'));
		$featured = absint($product->get_image_id('edit'));
		if ($featured) {
			array_unshift($ids, $featured);
		}
		return array_values(array_unique(array_filter($ids)));
	}

	/**
	 * Terms on a product, never an error.
	 *
	 * @param  int    $product_id Product id.
	 * @param  string $taxonomy   Taxonomy.
	 * @return \WP_Term[]
	 */
	private function terms($product_id, $taxonomy)
	{
		$terms = get_the_terms($product_id, $taxonomy);
		return is_array($terms) ? array_values($terms) : array();
	}

	/**
	 * Which SEO plugin's fields to use, if any.
	 *
	 * @return array|null Meta keys for title and description.
	 */
	private function seo_keys()
	{
		if (defined('WPSEO_VERSION')) {
			return array('title' => '_yoast_wpseo_title', 'description' => '_yoast_wpseo_metadesc');
		}
		if (class_exists('RankMath')) {
			return array('title' => 'rank_math_title', 'description' => 'rank_math_description');
		}
		return null;
	}

	/**
	 * SEO title and description, null where unset.
	 *
	 * @param  int $product_id Product id.
	 * @return array
	 */
	private function read_seo($product_id)
	{
		$keys = $this->seo_keys();
		$read = function ($key) use ($product_id) {
			$value = (string) get_post_meta($product_id, $key, true);
			return '' === $value ? null : $value;
		};
		return array(
			'title' => $keys ? $read($keys['title']) : null,
			'description' => $keys ? $read($keys['description']) : null,
		);
	}

	// ------------------------------------------------------------------
	// Edit
	// ------------------------------------------------------------------

	/**
	 * Run the operations in order. Stops at the first one that fails;
	 * earlier ones stay applied and the caller is told how far it got.
	 *
	 * @param  int   $product_id Parent product id.
	 * @param  array $operations Operations from Product Upload.
	 * @return array
	 */
	public function apply($product_id, array $operations)
	{
		$this->touched = 0;
		$count = function () {
			$this->touched += $this->bookkeeping ? 0 : 1;
		};
		foreach (self::WRITE_HOOKS as $hook) {
			add_action($hook, $count, 1, 0);
		}
		try {
			return ProductWriteController::unfiltered(
				function () use ($product_id, $operations) {
					return $this->run($product_id, $operations);
				}
			);
		} finally {
			foreach (self::WRITE_HOOKS as $hook) {
				remove_action($hook, $count, 1);
			}
			wc_delete_product_transients($product_id);
		}
	}

	/**
	 * The loop behind apply().
	 *
	 * `wrote` counts real database writes, seen through WordPress's own
	 * hooks, so an operation that saved one variation and then failed on the
	 * next is never reported as having changed nothing.
	 *
	 * @param  int   $product_id Parent product id.
	 * @param  array $operations Operations from Product Upload.
	 * @return array
	 */
	private function run($product_id, array $operations)
	{
		$created = array('variantIds' => array(), 'mediaIds' => array());
		foreach (array_values($operations) as $index => $op) {
			$before = $this->touched;
			try {
				$type = is_array($op) && isset($op['type']) ? (string) $op['type'] : '';
				$method = 'op_' . $type;
				if ('' === $type || !method_exists($this, $method)) {
					throw new \Exception(sprintf('%s is not available on WooCommerce yet', '' === $type ? 'this operation' : $type));
				}
				$this->$method($product_id, $op, $created);
			} catch (\Throwable $e) {
				return array(
					'ok' => false,
					'applied' => $index,
					'wrote' => $this->touched,
					// a PHP error, unlike a refusal, can stop anywhere
					'uncertain' => !($e instanceof \Exception),
					'created' => $created,
					'failed' => array(
						'index' => $index,
						'message' => $e instanceof \Exception ? $e->getMessage() : 'the site hit an error applying this operation',
						'landed' => $this->touched - $before,
					),
				);
			}
		}
		return array('ok' => true, 'applied' => count($operations), 'wrote' => $this->touched, 'uncertain' => false, 'created' => $created);
	}

	/**
	 * Product fields. Only keys present are written.
	 *
	 * @param  int   $product_id Product id.
	 * @param  array $op         Operation.
	 * @return int Writes made.
	 * @throws \Exception When a field cannot be written.
	 */
	private function op_set_fields($product_id, array $op)
	{
		$product = wc_get_product($product_id);
		$touched = false;

		if (isset($op['product_type'])) {
			throw new \Exception('WooCommerce products have no free-text product type');
		}
		if (isset($op['title'])) {
			$name = sanitize_text_field((string) $op['title']);
			if ('' === $name || mb_strlen($name, 'UTF-8') > self::TITLE_MAX) {
				throw new \Exception(sprintf('title must be 1 to %d characters', self::TITLE_MAX));
			}
			$product->set_name($name);
			$touched = true;
		}
		if (array_key_exists('description_html', $op)) {
			$product->set_description(wp_kses_post((string) $op['description_html']));
			$touched = true;
		}
		if (isset($op['handle'])) {
			$slug = sanitize_title((string) $op['handle']);
			if ('' === $slug) {
				throw new \Exception('handle is empty after sanitisation');
			}
			$product->set_slug($slug);
			$touched = true;
		}
		if (isset($op['status'])) {
			$map = array('active' => 'publish', 'draft' => 'draft');
			if (!isset($map[$op['status']])) {
				throw new \Exception('WooCommerce has no archived status; use draft to hide the product');
			}
			$product->set_status($map[$op['status']]);
			$touched = true;
		}
		$seo_title = array_key_exists('seo_title', $op);
		$seo_description = array_key_exists('seo_description', $op);
		$keys = $this->seo_keys();
		if (($seo_title || $seo_description) && !$keys) {
			throw new \Exception('SEO title and description need Yoast SEO or Rank Math, and neither is active on this site');
		}
		if (array_key_exists('vendor', $op) && !taxonomy_exists('product_brand')) {
			throw new \Exception('this site has no product brands (WooCommerce Brands is not active)');
		}

		$writes = 0;
		if ($touched) {
			$product->save();
			$writes++;
		}
		if ($seo_title) {
			update_post_meta($product_id, $keys['title'], sanitize_text_field((string) $op['seo_title']));
			$writes++;
		}
		if ($seo_description) {
			update_post_meta($product_id, $keys['description'], sanitize_text_field((string) $op['seo_description']));
			$writes++;
		}
		if (array_key_exists('vendor', $op)) {
			$brand = sanitize_text_field((string) $op['vendor']);
			$this->set_terms($product_id, '' === $brand ? array() : array($brand), 'product_brand', false);
			$writes++;
		}
		if (isset($op['tags']) && is_array($op['tags'])) {
			$this->set_terms($product_id, $op['tags'], 'product_tag', false);
			$writes++;
		}
		if (!empty($op['remove_tags']) && is_array($op['remove_tags'])) {
			$ids = array();
			foreach ($op['remove_tags'] as $name) {
				$term = get_term_by('name', sanitize_text_field((string) $name), 'product_tag');
				if ($term) {
					$ids[] = (int) $term->term_id;
				}
			}
			if ($ids) {
				wp_remove_object_terms($product_id, $ids, 'product_tag');
				$writes++;
			}
		}
		if (!empty($op['add_tags']) && is_array($op['add_tags'])) {
			$this->set_terms($product_id, $op['add_tags'], 'product_tag', true);
			$writes++;
		}
		return $writes;
	}

	/**
	 * Set terms by name, creating any that do not exist.
	 *
	 * @param  int      $product_id Product id.
	 * @param  string[] $names      Term names.
	 * @param  string   $taxonomy   Taxonomy.
	 * @param  bool     $append     Keep the existing terms.
	 * @throws \Exception When a term cannot be created.
	 */
	private function set_terms($product_id, array $names, $taxonomy, $append)
	{
		$ids = array();
		foreach ($names as $name) {
			$name = sanitize_text_field((string) $name);
			if ('' === $name) {
				continue;
			}
			// by name: a slug match would attach "C" for "C++"
			$found = get_term_by('name', $name, $taxonomy);
			$term = $found ? array('term_id' => $found->term_id) : wp_insert_term($name, $taxonomy);
			if (is_wp_error($term)) {
				throw new \Exception($term->get_error_message());
			}
			$ids[] = (int) $term['term_id'];
		}
		$result = wp_set_object_terms($product_id, $ids, $taxonomy, $append);
		if (is_wp_error($result)) {
			throw new \Exception($result->get_error_message());
		}
	}

	/**
	 * A variant of this product: the product itself, or one of its variations.
	 *
	 * @param  int   $product_id Parent product id.
	 * @param  mixed $variant_id Variant id from the request.
	 * @return \WC_Product
	 * @throws \Exception When the variant belongs elsewhere.
	 */
	private function variant($product_id, $variant_id)
	{
		$variant_id = absint($variant_id);
		$variant = $variant_id ? wc_get_product($variant_id) : false;
		$mine = $variant && ($variant_id === $product_id || ($variant->is_type('variation') && $variant->get_parent_id() === $product_id));
		if (!$mine || ($variant_id === $product_id && $variant->is_type('variable'))) {
			throw new \Exception(sprintf('variant %s is not on this product', $variant_id));
		}
		return $variant;
	}

	/**
	 * Values on existing variants. Only keys present are written.
	 *
	 * @param  int   $product_id Product id.
	 * @param  array $op         Operation.
	 * @return int Writes made.
	 * @throws \Exception When a value cannot be written.
	 */
	private function op_update_variants($product_id, array $op)
	{
		// every variant is checked before any is saved
		$ready = array();
		$skus = array();
		foreach ((array) ($op['variants'] ?? array()) as $fields) {
			$variant = $this->variant($product_id, $fields['variant_id'] ?? 0);
			if (array_key_exists('option_values', $fields)) {
				throw new \Exception('option_values cannot be changed on an existing WooCommerce variation; add a new one and delete the old');
			}
			$this->write_variant_fields($variant, $fields, $product_id);
			// only SKUs this operation sets: old duplicates are not ours
			$sku = array_key_exists('sku', $fields) ? (string) $variant->get_sku('edit') : '';
			if ('' !== $sku && isset($skus[$sku]) && $skus[$sku] !== $variant->get_id()) {
				throw new \Exception(sprintf('SKU %s is given to two variants in this operation', $sku));
			}
			if ('' !== $sku) {
				$skus[$sku] = $variant->get_id();
			}
			$ready[] = $variant;
		}
		if (!$ready) {
			throw new \Exception('update_variants needs variants');
		}
		foreach ($ready as $variant) {
			$variant->save();
		}
	}

	/**
	 * Set the fields present on a variant. Does not save.
	 *
	 * @param  \WC_Product $variant Variation or simple product.
	 * @param  array       $fields  Fields from the request.
	 * @throws \Exception When a value cannot be written.
	 */
	private function write_variant_fields($variant, array $fields, $product_id = 0)
	{
		$units = array('KILOGRAMS' => 'kg', 'GRAMS' => 'g', 'POUNDS' => 'lbs', 'OUNCES' => 'oz');
		if (array_key_exists('cost', $fields)) {
			throw new \Exception('cost cannot be edited on WooCommerce variants here yet');
		}
		$has_price = array_key_exists('price', $fields);
		$has_compare = array_key_exists('compare_at_price', $fields);
		if ($has_price || $has_compare) {
			$state = $this->sale_state($variant);
			if ('stale' === $state) {
				// a sale that can never apply is only clutter that a
				// new price could switch on by accident
				$variant->set_sale_price('');
				$variant->set_date_on_sale_from('');
				$variant->set_date_on_sale_to('');
				$state = 'none';
			}
			$regular = (string) $variant->get_regular_price('edit');
			$sale = (string) $variant->get_sale_price('edit');
			if ('scheduled' === $state) {
				// the dates are the merchant's: only the regular price moves
				if ($has_compare && null !== $fields['compare_at_price']) {
					throw new \Exception('this variant has a sale scheduled in WooCommerce; change or remove that sale there before setting a compare-at price');
				}
				if ($has_price) {
					if ((float) $fields['price'] <= (float) $sale) {
						throw new \Exception(sprintf('this variant has a sale scheduled at %s; a price at or below that needs the sale changed in WooCommerce first', $sale));
					}
					$variant->set_regular_price(wc_format_decimal($fields['price']));
				}
			} else {
				$on_sale = 'running' === $state;
				$current = $on_sale ? $sale : $regular;
				if (!$has_price && '' === $current) {
					throw new \Exception('this variant has no price; set price along with compare_at_price');
				}
				$price = $has_price ? (float) $fields['price'] : (float) $current;
				$compare = $has_compare ? $fields['compare_at_price'] : ($on_sale ? (float) $regular : null);
				if ($has_compare && null !== $compare && (float) $compare <= $price) {
					throw new \Exception('compare_at_price must be higher than price; pass null to remove it');
				}
				if (!$has_compare && null !== $compare && (float) $compare <= $price) {
					// would silently end the sale
					throw new \Exception(sprintf('this variant is on sale (was %s, now %s); pass compare_at_price too, or null to end the sale', $regular, $sale));
				}
				// a compare-at above the price is a sale in WooCommerce terms
				if (null !== $compare) {
					$variant->set_regular_price(wc_format_decimal($compare));
					$variant->set_sale_price(wc_format_decimal($price));
					$now = time();
					$from = $variant->get_date_on_sale_from('edit');
					$to = $variant->get_date_on_sale_to('edit');
					// only dates that would stop this sale running: an end
					// date still ahead is the merchant's and is kept
					if ($from && $from->getTimestamp() > $now) {
						$variant->set_date_on_sale_from('');
					}
					if ($to && $to->getTimestamp() < $now) {
						$variant->set_date_on_sale_to('');
					}
				} else {
					$variant->set_regular_price(wc_format_decimal($price));
					$variant->set_sale_price('');
				}
			}
		}
		if (array_key_exists('sku', $fields)) {
			$sku = sanitize_text_field((string) $fields['sku']);
			$clash = '' === $sku ? 0 : wc_get_product_id_by_sku($sku);
			if ($clash && $clash !== $variant->get_id()) {
				throw new \Exception(sprintf('SKU %s is already used by another product', $sku));
			}
			$variant->set_sku($sku);
		}
		if (array_key_exists('barcode', $fields)) {
			if (!method_exists($variant, 'set_global_unique_id')) {
				throw new \Exception('barcodes need WooCommerce 9.2 or later');
			}
			$variant->set_global_unique_id(sanitize_text_field((string) $fields['barcode']));
		}
		$weight = null;
		if (isset($fields['weight']['value'], $fields['weight']['unit'], $units[$fields['weight']['unit']])) {
			$weight = array((float) $fields['weight']['value'], $units[$fields['weight']['unit']]);
		} elseif (isset($fields['weight_kg'])) {
			$weight = array((float) $fields['weight_kg'], 'kg');
		}
		if ($weight) {
			// stored in the site's own weight unit
			$variant->set_weight(wc_format_decimal(wc_get_weight($weight[0], get_option('woocommerce_weight_unit', 'kg'), $weight[1]), 6, true));
		}
		if (array_key_exists('track_inventory', $fields)) {
			$this->switch_tracking($variant, (bool) $fields['track_inventory']);
		}
		if (array_key_exists('continue_selling', $fields)) {
			// "notify" already allows it: leave the merchant's choice
			if (!$fields['continue_selling'] || 'no' === $variant->get_backorders('edit')) {
				$variant->set_backorders($fields['continue_selling'] ? 'yes' : 'no');
			}
		}
		if (!empty($fields['image_id'])) {
			if (!$variant->is_type('variation')) {
				// here it would replace the product's main image
				throw new \Exception('a product without variations has no variant image; use reorder_images to choose its main image');
			}
			$image = absint($fields['image_id']);
			$owner = $product_id ? wc_get_product($product_id) : null;
			$mine = $owner ? array_merge($this->image_ids($owner), array(absint($variant->get_image_id('edit')))) : array();
			if ('attachment' !== get_post_type($image) || !in_array($image, $mine, true)) {
				throw new \Exception(sprintf('image %s is not one of this product\'s images', $image));
			}
			$variant->set_image_id($image);
		}
	}

	/**
	 * Turn stock tracking on or off without losing what the other state held.
	 *
	 * WooCommerce blanks the quantity and backorder setting when tracking goes
	 * off, and leaves the stock status as it was when tracking goes back off
	 * after being on. A switch and its undo would otherwise leave a variant
	 * out of stock, so what is about to be lost is kept in meta and put back
	 * by the opposite switch.
	 *
	 * @param \WC_Product $variant Variation or simple product. Not saved here.
	 * @param bool        $on      Whether stock should be tracked.
	 */
	private function switch_tracking($variant, $on)
	{
		$tracked = true === $variant->get_manage_stock('edit');
		if ($on === $tracked) {
			return;
		}
		$memory = $variant->get_meta(self::STOCK_MEMORY_META, true);
		$memory = is_array($memory) ? $memory : array();
		// Only the state this switch itself left behind is put back: anything
		// the merchant changed since makes the memory stale.
		$left = isset($memory['left']) ? $memory['left'] : null;
		if ($on) {
			$status = $variant->get_stock_status('edit');
			$variant->set_manage_stock(true);
			if (array_key_exists('quantity', $memory) && $left === array('status' => $status)) {
				$variant->set_stock_quantity($memory['quantity']);
				$variant->set_backorders(isset($memory['backorders']) ? $memory['backorders'] : 'no');
			}
			$variant->update_meta_data(
				self::STOCK_MEMORY_META,
				array(
					'status' => $status,
					'left' => array('quantity' => $this->quantity_key($variant), 'backorders' => $variant->get_backorders('edit')),
				)
			);
			return;
		}
		$now = array('quantity' => $this->quantity_key($variant), 'backorders' => $variant->get_backorders('edit'));
		$variant->set_manage_stock(false);
		if (isset($memory['status']) && $left === $now) {
			$variant->set_stock_status($memory['status']);
		}
		$variant->update_meta_data(
			self::STOCK_MEMORY_META,
			array(
				'quantity' => $variant->get_stock_quantity('edit'),
				'backorders' => $now['backorders'],
				'left' => array('status' => $variant->get_stock_status('edit')),
			)
		);
	}

	/**
	 * A quantity in one comparable form.
	 *
	 * @param  \WC_Product $variant Variation or simple product.
	 * @return string
	 */
	private function quantity_key($variant)
	{
		$quantity = $variant->get_stock_quantity('edit');
		return null === $quantity ? '' : (string) (float) $quantity;
	}

	/**
	 * Whether saving this HTML through WordPress's filter would drop an
	 * element. Attribute and entity tidying is not a loss.
	 *
	 * @param  string $html Description.
	 * @return bool
	 */
	private function loses_elements($html)
	{
		$tags = function ($markup) {
			preg_match_all('/<([a-z][a-z0-9-]*)/i', (string) $markup, $found);
			$names = array_map('strtolower', $found[1]);
			sort($names);
			return $names;
		};
		return $tags($html) !== $tags(wp_kses_post($html));
	}

	/**
	 * Stock quantities on variants that manage stock.
	 *
	 * @param  int   $product_id Product id.
	 * @param  array $op         Operation.
	 * @return int Writes made.
	 * @throws \Exception When a variant does not manage stock.
	 */
	private function op_set_stock($product_id, array $op)
	{
		$targets = array();
		foreach ((array) ($op['items'] ?? array()) as $item) {
			$variant = $this->variant($product_id, $item['variant_id'] ?? 0);
			if (true !== $variant->get_manage_stock()) {
				throw new \Exception(sprintf('variant %d does not manage stock; turn that on first with track_inventory', $variant->get_id()));
			}
			if (!isset($item['quantity']) || !is_numeric($item['quantity']) || $item['quantity'] < 0) {
				throw new \Exception('quantity must be zero or more');
			}
			$targets[] = array($variant, (int) $item['quantity']);
		}
		if (!$targets) {
			throw new \Exception('set_stock needs items');
		}
		foreach ($targets as $target) {
			$target[0]->set_stock_quantity($target[1]);
			$target[0]->save();
		}
		return count($targets);
	}

	/**
	 * Write the product's images back as featured + gallery.
	 *
	 * @param int   $product_id Product id.
	 * @param int[] $ids        Attachment ids in display order.
	 */
	private function save_images($product_id, array $ids)
	{
		$product = wc_get_product($product_id);
		$ids = array_values(array_unique(array_filter(array_map('absint', $ids))));
		$product->set_image_id($ids ? $ids[0] : '');
		$product->set_gallery_image_ids(array_slice($ids, 1));
		$product->save();
	}

	/**
	 * Sideload images and append them to the gallery.
	 *
	 * @param  int   $product_id Product id.
	 * @param  array $op         Operation.
	 * @param  array $created    Ids this call created, by reference.
	 * @return int Writes made.
	 * @throws \Exception When an image cannot be fetched.
	 */
	private function op_add_images($product_id, array $op, array &$created)
	{
		$ids = $this->image_ids(wc_get_product($product_id));
		$added = 0;
		foreach ((array) ($op['images'] ?? array()) as $image) {
			$url = esc_url_raw((string) ($image['url'] ?? ''));
			if ('' === $url) {
				throw new \Exception('each image needs a url');
			}
			$attachment = $this->images->sideload($url, $product_id, sanitize_text_field((string) ($image['alt'] ?? '')));
			if (is_wp_error($attachment) || !$attachment) {
				if ($added) {
					$this->save_images($product_id, $ids);
				}
				throw new \Exception(sprintf('could not fetch %s', $url));
			}
			$ids[] = (int) $attachment;
			$created['mediaIds'][] = (string) $attachment;
			$added++;
		}
		if (!$added) {
			throw new \Exception('add_images needs images');
		}
		$this->save_images($product_id, $ids);
		return $added;
	}

	/**
	 * Take images off the product. The files stay in the media library.
	 *
	 * @param  int   $product_id Product id.
	 * @param  array $op         Operation.
	 * @return int Writes made.
	 */
	private function op_delete_images($product_id, array $op)
	{
		$remove = array_map('absint', (array) ($op['image_ids'] ?? array()));
		$ids = $this->image_ids(wc_get_product($product_id));
		$kept = array_values(array_diff($ids, $remove));
		if (count($kept) === count($ids)) {
			return 0;
		}
		$this->save_images($product_id, $kept);
		return 1;
	}

	/**
	 * Move images to new positions (0 = the featured image).
	 *
	 * @param  int   $product_id Product id.
	 * @param  array $op         Operation.
	 * @return int Writes made.
	 */
	private function op_reorder_images($product_id, array $op)
	{
		$ids = $this->image_ids(wc_get_product($product_id));
		$before = $ids;
		foreach ((array) ($op['moves'] ?? array()) as $move) {
			$at = array_search(absint($move['image_id'] ?? 0), $ids, true);
			if (false === $at) {
				continue;
			}
			$moved = array_splice($ids, $at, 1);
			array_splice($ids, min(absint($move['position'] ?? 0), count($ids)), 0, $moved);
		}
		if ($ids === $before) {
			return 0;
		}
		$this->save_images($product_id, $ids);
		return 1;
	}

	/**
	 * Alt text on images that are on this product.
	 *
	 * @param  int   $product_id Product id.
	 * @param  array $op         Operation.
	 * @return int Writes made.
	 * @throws \Exception When an image is not on the product.
	 */
	private function op_set_image_alt($product_id, array $op)
	{
		$ids = $this->image_ids(wc_get_product($product_id));
		$writes = 0;
		foreach ((array) ($op['images'] ?? array()) as $image) {
			$attachment = absint($image['image_id'] ?? 0);
			if (!in_array($attachment, $ids, true)) {
				throw new \Exception(sprintf('image %d is not on this product', $attachment));
			}
			update_post_meta($attachment, '_wp_attachment_image_alt', sanitize_text_field((string) ($image['alt'] ?? '')));
			$writes++;
		}
		return $writes;
	}

	/**
	 * Existing category ids from the request.
	 *
	 * @param  array $op Operation.
	 * @return int[]
	 * @throws \Exception When a category does not exist.
	 */
	private function categories(array $op)
	{
		$ids = array();
		foreach ((array) ($op['collection_ids'] ?? array()) as $id) {
			$id = absint($id);
			if (!$id || !term_exists($id, 'product_cat')) {
				throw new \Exception(sprintf('category %d does not exist', $id));
			}
			$ids[] = $id;
		}
		return $ids;
	}

	/**
	 * Add the product to categories.
	 *
	 * @param  int   $product_id Product id.
	 * @param  array $op         Operation.
	 * @return int Writes made.
	 */
	private function op_add_to_collections($product_id, array $op)
	{
		$ids = $this->categories($op);
		wp_set_object_terms($product_id, $ids, 'product_cat', true);
		return count($ids);
	}

	/**
	 * Remove the product from categories.
	 *
	 * @param  int   $product_id Product id.
	 * @param  array $op         Operation.
	 * @return int Writes made.
	 */
	private function op_remove_from_collections($product_id, array $op)
	{
		$ids = $this->categories($op);
		wp_remove_object_terms($product_id, $ids, 'product_cat');
		return count($ids);
	}

	// ------------------------------------------------------------------
	// Variant structure
	// ------------------------------------------------------------------

	/**
	 * The product, which must be variable for a structural edit.
	 *
	 * @param  int $product_id Product id.
	 * @return \WC_Product_Variable
	 * @throws \Exception When the product has no variations to restructure.
	 */
	private function variable($product_id)
	{
		$product = wc_get_product($product_id);
		if (!$product || !$product->is_type('variable')) {
			throw new \Exception('this is not a variable product, so it has no options or variations to change');
		}
		return $product;
	}

	/**
	 * A variation option by its key.
	 *
	 * @param  \WC_Product $product Product.
	 * @param  mixed       $key     Option id from the snapshot.
	 * @return \WC_Product_Attribute
	 * @throws \Exception When the product has no such option.
	 */
	private function option($product, $key)
	{
		$attributes = $product->get_attributes();
		$key = (string) $key;
		if (!isset($attributes[$key]) || !is_object($attributes[$key]) || !$attributes[$key]->get_variation()) {
			throw new \Exception(sprintf('option %s is not on this product', $key));
		}
		return $attributes[$key];
	}

	/**
	 * Make sure an option has a value, and return what variations store for it.
	 *
	 * @param  \WC_Product_Attribute $attribute Option, changed in place.
	 * @param  string                $name      Value name.
	 * @return string
	 * @throws \Exception When the value cannot be created.
	 */
	private function ensure_value($attribute, $name)
	{
		$name = sanitize_text_field((string) $name);
		if ('' === $name) {
			throw new \Exception('an option value cannot be empty');
		}
		if (!$attribute->is_taxonomy() && false !== strpos($name, '|')) {
			throw new \Exception('an option value cannot contain "|"; WooCommerce uses it to separate values');
		}
		foreach ($this->attribute_values($attribute) as $value) {
			if (0 === strcasecmp($value['name'], $name)) {
				return $value['stored'];
			}
		}
		if (!$attribute->is_taxonomy()) {
			$attribute->set_options(array_merge(array_values($attribute->get_options()), array($name)));
			return $name;
		}
		$taxonomy = $attribute->get_name();
		$term = get_term_by('name', $name, $taxonomy);
		if (!$term) {
			$made = wp_insert_term($name, $taxonomy);
			if (is_wp_error($made)) {
				throw new \Exception($made->get_error_message());
			}
			$term = get_term($made['term_id'], $taxonomy);
		}
		$attribute->set_options(array_merge(array_map('absint', $attribute->get_options()), array((int) $term->term_id)));
		return $term->slug;
	}

	/**
	 * Save a product's attributes and refresh what its variations derive.
	 *
	 * @param \WC_Product $product    Product.
	 * @param array       $attributes Full attribute list.
	 */
	private function save_options($product, array $attributes)
	{
		// fresh objects: WooCommerce skips a save when it is handed the
		// same attribute instances back, even if their options changed
		$copies = array();
		foreach ($attributes as $key => $attribute) {
			$copies[$key] = is_object($attribute) ? clone $attribute : $attribute;
		}
		$product->set_attributes($copies);
		$product->save();
	}

	/**
	 * Add values to an option. No variation is created.
	 *
	 * @param  int   $product_id Product id.
	 * @param  array $op         Operation.
	 * @return int Writes made.
	 */
	private function op_add_option_values($product_id, array $op)
	{
		$product = $this->variable($product_id);
		$attribute = $this->option($product, $op['option_id'] ?? '');
		foreach ((array) ($op['values'] ?? array()) as $name) {
			$this->ensure_value($attribute, $name);
		}
		$this->save_options($product, $product->get_attributes());
		return 1;
	}

	/**
	 * Rename values of a product-level option, on the option and on every
	 * variation that uses them.
	 *
	 * @param  int   $product_id Product id.
	 * @param  array $op         Operation.
	 * @return int Writes made.
	 * @throws \Exception When the rename would reach beyond this product.
	 */
	private function op_rename_option($product_id, array $op)
	{
		if (isset($op['name'])) {
			throw new \Exception('renaming the option itself is not available on WooCommerce yet; its values can be renamed');
		}
		$product = $this->variable($product_id);
		$key = (string) ($op['option_id'] ?? '');
		$attribute = $this->option($product, $key);
		if ($attribute->is_taxonomy()) {
			throw new \Exception('this option is a global attribute shared with other products; rename its terms under Products > Attributes in WordPress');
		}
		$values = $this->attribute_values($attribute, $product_id);
		$options = array_values($attribute->get_options());
		$renames = array();
		foreach ((array) ($op['values'] ?? array()) as $change) {
			$name = sanitize_text_field((string) ($change['name'] ?? ''));
			$at = array_search((string) ($change['value_id'] ?? ''), wp_list_pluck($values, 'id'), true);
			if (false !== strpos($name, '|')) {
				throw new \Exception('an option value cannot contain "|"; WooCommerce uses it to separate values');
			}
			if (false === $at || '' === $name) {
				throw new \Exception(sprintf('value %s is not on this option', $change['value_id'] ?? ''));
			}
			foreach ($options as $index => $existing) {
				if ($index !== $at && 0 === strcasecmp((string) $existing, $name)) {
					throw new \Exception(sprintf('this option already has a value called %s', $name));
				}
			}
			$renames[(string) $options[$at]] = $name;
			$options[$at] = $name;
		}
		if (!$renames) {
			throw new \Exception('rename_option needs values');
		}
		$attribute->set_options($options);
		$this->save_options($product, $product->get_attributes());
		// the ids follow the values to their new names
		$this->value_ids($product_id, $key, array_map('strval', $options), $renames);
		foreach ($product->get_children() as $variation_id) {
			$variation = wc_get_product($variation_id);
			$held = $variation ? $variation->get_attributes() : array();
			if (isset($held[$key]) && isset($renames[(string) $held[$key]])) {
				$held[$key] = $renames[(string) $held[$key]];
				$variation->set_attributes($held);
				$variation->save();
			}
		}
		\WC_Product_Variable::sync($product_id);
		return 1;
	}

	/**
	 * Create variations. A value the option lacks is added to it first.
	 *
	 * @param  int   $product_id Product id.
	 * @param  array $op         Operation.
	 * @param  array $created    Ids this call created, by reference.
	 * @return int Writes made.
	 * @throws \Exception When a combination is incomplete or already exists.
	 */
	private function op_add_variants($product_id, array $op, array &$created)
	{
		$product = $this->variable($product_id);
		$attributes = $product->get_attributes();
		$labels = array();
		foreach ($attributes as $key => $attribute) {
			if (is_object($attribute) && $attribute->get_variation()) {
				$labels[strtolower(wc_attribute_label($attribute->get_name(), $product))] = (string) $key;
			}
		}
		$keys = array_values(array_unique($labels));
		// the key, with or without WooCommerce's pa_ prefix, works too
		foreach ($keys as $key) {
			$labels += array($key => $key, preg_replace('/^pa_/', '', $key) => $key);
		}
		$taken = array();
		foreach ($product->get_children() as $variation_id) {
			$variation = wc_get_product($variation_id);
			if ($variation) {
				$held = $variation->get_attributes();
				ksort($held);
				$taken[] = wp_json_encode($held);
			}
		}

		$planned = array();
		foreach ((array) ($op['variants'] ?? array()) as $fields) {
			$combo = array();
			foreach ((array) ($fields['option_values'] ?? array()) as $pair) {
				$label = strtolower(trim((string) ($pair['option'] ?? '')));
				if (!isset($labels[$label])) {
					throw new \Exception(sprintf('option %s is not on this product', $pair['option'] ?? ''));
				}
				$combo[$labels[$label]] = $this->ensure_value($attributes[$labels[$label]], $pair['value'] ?? '');
			}
			if (count($combo) !== count($keys)) {
				throw new \Exception('each new variation needs a value for every option: ' . implode(', ', $keys));
			}
			ksort($combo);
			$signature = wp_json_encode($combo);
			if (in_array($signature, $taken, true)) {
				throw new \Exception(sprintf('a variation with %s already exists', implode(' / ', $combo)));
			}
			$taken[] = $signature;
			$planned[] = array($combo, $fields);
		}
		if (!$planned) {
			throw new \Exception('add_variants needs variants');
		}

		if (count($taken) > self::MAX_VARIATIONS) {
			throw new \Exception(sprintf('a product can have at most %d variations here', self::MAX_VARIATIONS));
		}
		// every variation is built and checked before anything is saved
		$ready = array();
		$skus = array();
		foreach ($planned as $plan) {
			$variation = new \WC_Product_Variation();
			$variation->set_parent_id($product_id);
			$variation->set_attributes($plan[0]);
			$this->write_variant_fields($variation, $plan[1], $product_id);
			$sku = (string) $variation->get_sku('edit');
			if ('' !== $sku && isset($skus[$sku])) {
				throw new \Exception(sprintf('SKU %s is given to two variations in this operation', $sku));
			}
			$skus[$sku] = true;
			if (isset($plan[1]['stock']) && is_numeric($plan[1]['stock'])) {
				$variation->set_manage_stock(true);
				$variation->set_stock_quantity(max(0, (int) $plan[1]['stock']));
			}
			$ready[] = $variation;
		}
		// new values go on the options before any variation uses them
		$this->save_options($product, $attributes);
		foreach ($ready as $variation) {
			$created['variantIds'][] = (string) $variation->save();
		}
		\WC_Product_Variable::sync($product_id);
		return count($planned);
	}

	/**
	 * Delete variations. The product keeps at least one.
	 *
	 * @param  int   $product_id Product id.
	 * @param  array $op         Operation.
	 * @return int Writes made.
	 * @throws \Exception When a variation is not this product's, or none would remain.
	 */
	private function op_delete_variants($product_id, array $op)
	{
		$product = $this->variable($product_id);
		$children = array_map('absint', $product->get_children());
		$ids = array_values(array_unique(array_map('absint', (array) ($op['variant_ids'] ?? array()))));
		foreach ($ids as $id) {
			if (!in_array($id, $children, true)) {
				throw new \Exception(sprintf('variant %d is not on this product', $id));
			}
		}
		if (!$ids || count($ids) >= count($children)) {
			throw new \Exception('a variable product must keep at least one variation');
		}
		foreach ($ids as $id) {
			wc_get_product($id)->delete(true);
		}
		\WC_Product_Variable::sync($product_id);
		return count($ids);
	}

	/**
	 * Remove values from an option, with the variations that use them.
	 *
	 * @param  int   $product_id Product id.
	 * @param  array $op         Operation.
	 * @return int Writes made.
	 * @throws \Exception When no value or no variation would remain.
	 */
	private function op_delete_option_values($product_id, array $op)
	{
		$product = $this->variable($product_id);
		$key = (string) ($op['option_id'] ?? '');
		$attribute = $this->option($product, $key);
		$wanted = array_map('strval', (array) ($op['value_ids'] ?? array()));
		$gone = array();
		$kept = array();
		foreach ($this->attribute_values($attribute, $product_id) as $value) {
			if (in_array($value['id'], $wanted, true)) {
				$gone[] = $value['stored'];
			} else {
				$kept[] = $attribute->is_taxonomy() ? absint(substr($value['id'], 1)) : $value['stored'];
			}
		}
		if (count($gone) !== count($wanted)) {
			throw new \Exception('one of those values is not on this option');
		}
		if (!$kept) {
			throw new \Exception('an option must keep at least one value');
		}
		$doomed = array();
		$children = $product->get_children();
		foreach ($children as $variation_id) {
			$variation = wc_get_product($variation_id);
			$held = $variation ? $variation->get_attributes() : array();
			if (isset($held[$key]) && in_array((string) $held[$key], $gone, true)) {
				$doomed[] = $variation;
			}
		}
		if (count($doomed) >= count($children)) {
			throw new \Exception('every variation uses those values; a variable product must keep at least one variation');
		}
		foreach ($doomed as $variation) {
			$variation->delete(true);
		}
		$attribute->set_options($kept);
		$this->save_options($product, $product->get_attributes());
		\WC_Product_Variable::sync($product_id);
		return 1 + count($doomed);
	}

	/**
	 * Add options with one value each, given to every existing variation.
	 *
	 * @param  int   $product_id Product id.
	 * @param  array $op         Operation.
	 * @return int Writes made.
	 * @throws \Exception When an option exists or brings several values.
	 */
	private function op_add_options($product_id, array $op)
	{
		$product = $this->variable($product_id);
		$attributes = $product->get_attributes();
		$added = array();
		foreach ((array) ($op['options'] ?? array()) as $option) {
			$name = sanitize_text_field((string) ($option['name'] ?? ''));
			$values = array_values(array_filter(array_map('sanitize_text_field', (array) ($option['values'] ?? array())), 'strlen'));
			$key = sanitize_title($name);
			$clash = '' === $key || isset($attributes[$key]) || isset($added[$key]);
			foreach ($attributes as $existing) {
				// a global attribute's key differs from its label
				$clash = $clash || (is_object($existing) && 0 === strcasecmp(wc_attribute_label($existing->get_name(), $product), $name));
			}
			if ($clash) {
				throw new \Exception(sprintf('this product already has an option called %s', $name));
			}
			if (1 !== count($values)) {
				throw new \Exception('a new option needs exactly one value, which every existing variation takes; add more values afterwards with add_option_values');
			}
			$attribute = new \WC_Product_Attribute();
			$attribute->set_name($name);
			$attribute->set_options($values);
			$attribute->set_position(count($attributes));
			$attribute->set_visible(true);
			$attribute->set_variation(true);
			$attributes[$key] = $attribute;
			$added[$key] = $values[0];
		}
		if (!$added) {
			throw new \Exception('add_options needs options');
		}
		$this->save_options($product, $attributes);
		foreach ($product->get_children() as $variation_id) {
			$variation = wc_get_product($variation_id);
			if ($variation) {
				$variation->set_attributes(array_merge($variation->get_attributes(), $added));
				$variation->save();
			}
		}
		\WC_Product_Variable::sync($product_id);
		return count($added);
	}

	/**
	 * Remove options that have a single value. Variations are kept.
	 *
	 * @param  int   $product_id Product id.
	 * @param  array $op         Operation.
	 * @return int Writes made.
	 * @throws \Exception When an option has several values or is the last one.
	 */
	private function op_delete_options($product_id, array $op)
	{
		$product = $this->variable($product_id);
		$attributes = $product->get_attributes();
		$keys = array_map('strval', (array) ($op['option_ids'] ?? array()));
		foreach ($keys as $key) {
			$attribute = $this->option($product, $key);
			if (count($attribute->get_options()) > 1) {
				throw new \Exception(sprintf('option %s has several values; only an option with a single value can be deleted', $key));
			}
			unset($attributes[$key]);
		}
		$left = 0;
		foreach ($attributes as $attribute) {
			$left += is_object($attribute) && $attribute->get_variation() ? 1 : 0;
		}
		if (!$keys || !$left) {
			throw new \Exception('a variable product must keep at least one option');
		}
		$this->save_options($product, $attributes);
		foreach ($product->get_children() as $variation_id) {
			$variation = wc_get_product($variation_id);
			if ($variation) {
				$variation->set_attributes(array_diff_key($variation->get_attributes(), array_flip($keys)));
				$variation->save();
			}
		}
		\WC_Product_Variable::sync($product_id);
		return count($keys);
	}
}
