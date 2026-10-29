<?php
/**
 * Sync Taobao / JD products into WooCommerce (simple + variable with variations).
 *
 * @package Product_Purchase_Workflow
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

final class PPW_WooCommerce_Sync {

	/**
	 * Find existing WC product by marketplace num_iid.
	 */
	public static function find_by_item_id( string $num_iid, string $platform = PPW_Platform::TAOBAO ): ?int {
		$platform = PPW_Platform::normalize( $platform );
		$num_iid  = preg_replace( '/\D/', '', $num_iid );
		if ( '' === $num_iid ) {
			return null;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$post_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
				WHERE meta_key = %s AND meta_value = %s
				LIMIT 1",
				PPW_Platform::meta_num_iid( $platform ),
				$num_iid
			)
		);

		if ( $post_id ) {
			return (int) $post_id;
		}

		if ( function_exists( 'wc_get_product_id_by_sku' ) ) {
			$by_sku = wc_get_product_id_by_sku( self::make_sku( $num_iid, $platform ) );
			if ( $by_sku ) {
				return (int) $by_sku;
			}
		}

		return null;
	}

	/**
	 * @deprecated Use find_by_item_id().
	 */
	public static function find_by_jd_id( string $num_iid ): ?int {
		return self::find_by_item_id( $num_iid, PPW_Platform::JD );
	}

	/**
	 * Find or create WooCommerce product from JD item data.
	 *
	 * @param array<string, mixed> $item Normalized item_get data.
	 * @return array{product_id: int, created: bool, url: string}|WP_Error
	 */
	public static function ensure_product( array $item ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return new WP_Error(
				'ppw_wc_missing',
				__( 'WooCommerce 未安装或未启用。', 'product-purchase-workflow' )
			);
		}

		$num_iid  = (string) ( $item['num_iid'] ?? '' );
		$platform = PPW_Platform::normalize( (string) ( $item['platform'] ?? PPW_Platform::TAOBAO ) );
		$item['platform'] = $platform;
		$existing = self::find_by_item_id( $num_iid, $platform );

		if ( $existing ) {
			self::update_product_from_item( $existing, $item );
			return array(
				'product_id' => $existing,
				'created'    => false,
				'url'        => get_permalink( $existing ),
			);
		}

		$settings = get_option( 'ppw_settings', ppw_default_settings() );
		if ( empty( $settings['auto_publish_wc'] ) ) {
			return new WP_Error(
				'ppw_wc_not_found',
				__( '商店中暂无此商品，且未开启自动上架。', 'product-purchase-workflow' )
			);
		}

		$product_id = self::create_product( $item );
		if ( is_wp_error( $product_id ) ) {
			return $product_id;
		}

		return array(
			'product_id' => $product_id,
			'created'    => true,
			'url'        => get_permalink( $product_id ),
		);
	}

	/**
	 * Whether item should be synced as a variable product.
	 *
	 * @param array<string, mixed> $item Item data.
	 */
	private static function should_create_variable( array $item ): bool {
		$skus = $item['skus'] ?? array();
		if ( ! is_array( $skus ) || count( $skus ) < 1 ) {
			return false;
		}

		// Single SKU with no selectable attributes → keep simple.
		if ( 1 === count( $skus ) ) {
			$attrs = $skus[0]['attributes'] ?? array();
			return is_array( $attrs ) && count( $attrs ) > 0;
		}

		return true;
	}

	/**
	 * @param array<string, mixed> $item Item data.
	 * @return int|WP_Error
	 */
	private static function create_product( array $item ) {
		$num_iid = (string) ( $item['num_iid'] ?? '' );
		$title   = (string) ( $item['title'] ?? '' );

		if ( '' === trim( $title ) ) {
			return new WP_Error( 'ppw_wc_no_title', __( '商品标题为空，无法上架。', 'product-purchase-workflow' ) );
		}

		$price_cad = (float) ( $item['price_cad'] ?? 0 );
		if ( $price_cad <= 0 && empty( $item['skus'] ) ) {
			return new WP_Error( 'ppw_wc_no_price', __( '商品价格无效，无法上架。', 'product-purchase-workflow' ) );
		}

		if ( self::should_create_variable( $item ) ) {
			return self::create_variable_product( $item );
		}

		return self::create_simple_product( $item );
	}

	/**
	 * @param array<string, mixed> $item Item data.
	 * @return int|WP_Error
	 */
	private static function create_simple_product( array $item ) {
		$num_iid   = (string) ( $item['num_iid'] ?? '' );
		$title     = (string) ( $item['title'] ?? '' );
		$price_cad = (float) ( $item['price_cad'] ?? 0 );

		$product = new WC_Product_Simple();
		$product->set_name( $title );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'visible' );
		$product->set_sku( self::make_sku( $num_iid, PPW_Platform::normalize( (string) ( $item['platform'] ?? PPW_Platform::TAOBAO ) ) ) );
		$product->set_regular_price( wc_format_decimal( $price_cad, wc_get_price_decimals() ) );
		$product->set_description( self::build_description( $item ) );
		$product->set_short_description( (string) ( $item['desc_short'] ?? wp_trim_words( $title, 20 ) ) );

		if ( ! empty( $item['stock'] ) && (int) $item['stock'] > 0 ) {
			$product->set_manage_stock( true );
			$product->set_stock_quantity( (int) $item['stock'] );
			$product->set_stock_status( 'instock' );
		} else {
			$product->set_stock_status( 'instock' );
		}

		$product_id = $product->save();
		if ( ! $product_id ) {
			return new WP_Error( 'ppw_wc_create_failed', __( '创建 WooCommerce 商品失败。', 'product-purchase-workflow' ) );
		}

		self::save_platform_meta( $product_id, $item );
		self::assign_images( $product_id, $item );

		return (int) $product_id;
	}

	/**
	 * Create a variable product with attributes + variations from JD SKUs.
	 *
	 * @param array<string, mixed> $item Item data.
	 * @return int|WP_Error
	 */
	private static function create_variable_product( array $item ) {
		$num_iid = (string) ( $item['num_iid'] ?? '' );
		$title   = (string) ( $item['title'] ?? '' );
		$skus    = is_array( $item['skus'] ?? null ) ? $item['skus'] : array();

		$attributes_map = self::resolve_attributes_map( $item );
		if ( empty( $attributes_map ) ) {
			// Cannot build selectable options — fall back to simple.
			return self::create_simple_product( $item );
		}

		$product = new WC_Product_Variable();
		$product->set_name( $title );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'visible' );
		$product->set_sku( self::make_sku( $num_iid, PPW_Platform::normalize( (string) ( $item['platform'] ?? PPW_Platform::TAOBAO ) ) ) );
		$product->set_description( self::build_description( $item ) );
		$product->set_short_description( (string) ( $item['desc_short'] ?? wp_trim_words( $title, 20 ) ) );
		$product->set_stock_status( 'instock' );
		$product->set_attributes( self::build_wc_attributes( $attributes_map ) );

		$product_id = $product->save();
		if ( ! $product_id ) {
			return new WP_Error( 'ppw_wc_create_failed', __( '创建 WooCommerce 可变商品失败。', 'product-purchase-workflow' ) );
		}

		self::save_platform_meta( $product_id, $item );
		self::assign_images( $product_id, $item );
		self::sync_variations( $product_id, $item, $skus, $attributes_map );

		WC_Product_Variable::sync( $product_id );
		wc_delete_product_transients( $product_id );

		return (int) $product_id;
	}

	/**
	 * @param array<string, mixed> $item Item data.
	 */
	private static function update_product_from_item( int $product_id, array $item ): void {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}

		if ( ! empty( $item['title'] ) ) {
			$product->set_name( (string) $item['title'] );
		}

		$wants_variable = self::should_create_variable( $item );
		$is_variable    = $product->is_type( 'variable' );

		// Upgrade simple → variable when SKUs become available.
		if ( $wants_variable && ! $is_variable ) {
			$product->save();
			wp_set_object_terms( $product_id, 'variable', 'product_type' );
			$product = new WC_Product_Variable( $product_id );
			$is_variable = true;
		}

		if ( $is_variable && $wants_variable ) {
			$attributes_map = self::resolve_attributes_map( $item );
			$skus           = is_array( $item['skus'] ?? null ) ? $item['skus'] : array();

			if ( ! empty( $attributes_map ) ) {
				$product->set_attributes( self::build_wc_attributes( $attributes_map ) );
				$product->set_description( self::build_description( $item ) );
				$product->save();

				self::sync_variations( $product_id, $item, $skus, $attributes_map );
				WC_Product_Variable::sync( $product_id );
				wc_delete_product_transients( $product_id );
			}
		} else {
			if ( ! empty( $item['price_cad'] ) ) {
				$product->set_regular_price( wc_format_decimal( (float) $item['price_cad'], wc_get_price_decimals() ) );
			}
			$product->save();
		}

		self::save_platform_meta( $product_id, $item );

		if ( ! has_post_thumbnail( $product_id ) ) {
			self::assign_images( $product_id, $item );
		}
	}

	/**
	 * @param array<string, mixed> $item Item data.
	 * @return array<string, array<int, string>>
	 */
	private static function resolve_attributes_map( array $item ): array {
		$attributes = $item['attributes'] ?? array();
		if ( is_array( $attributes ) && ! empty( $attributes ) ) {
			return $attributes;
		}

		$map = array();
		foreach ( (array) ( $item['skus'] ?? array() ) as $sku ) {
			if ( empty( $sku['attributes'] ) || ! is_array( $sku['attributes'] ) ) {
				continue;
			}
			foreach ( $sku['attributes'] as $name => $value ) {
				$name  = (string) $name;
				$value = (string) $value;
				if ( '' === $name || '' === $value ) {
					continue;
				}
				if ( ! isset( $map[ $name ] ) ) {
					$map[ $name ] = array();
				}
				if ( ! in_array( $value, $map[ $name ], true ) ) {
					$map[ $name ][] = $value;
				}
			}
		}

		return $map;
	}

	/**
	 * Stable slug for custom attribute names (including Chinese).
	 */
	private static function attribute_slug( string $name ): string {
		$slug = sanitize_title( $name );
		if ( '' !== $slug ) {
			return $slug;
		}

		return 'ppw_' . substr( md5( $name ), 0, 12 );
	}

	/**
	 * @param array<string, array<int, string>> $attributes_map Attribute name => options.
	 * @return array<string, WC_Product_Attribute>
	 */
	private static function build_wc_attributes( array $attributes_map ): array {
		$position   = 0;
		$attributes = array();

		foreach ( $attributes_map as $name => $options ) {
			$options = array_values( array_unique( array_filter( array_map( 'strval', (array) $options ) ) ) );
			if ( empty( $options ) ) {
				continue;
			}

			$attribute = new WC_Product_Attribute();
			$attribute->set_id( 0 );
			$attribute->set_name( (string) $name );
			$attribute->set_options( $options );
			$attribute->set_position( $position );
			$attribute->set_visible( true );
			$attribute->set_variation( true );

			$attributes[ self::attribute_slug( (string) $name ) ] = $attribute;
			++$position;
		}

		return $attributes;
	}

	/**
	 * Create or update variations for a variable product.
	 *
	 * @param int                               $product_id     Parent product ID.
	 * @param array<string, mixed>              $item           Parent item data.
	 * @param array<int, array<string, mixed>>  $skus           Normalized SKUs.
	 * @param array<string, array<int, string>> $attributes_map Attribute map.
	 */
	private static function sync_variations( int $product_id, array $item, array $skus, array $attributes_map ): void {
		$pricing      = new PPW_Pricing_Agent();
		$parent_price = (float) ( $item['price_cad'] ?? 0 );
		$seen_ids     = array();

		foreach ( $skus as $sku ) {
			$attrs = $sku['attributes'] ?? array();
			if ( ! is_array( $attrs ) || empty( $attrs ) ) {
				continue;
			}

			// Ensure variation attributes cover all parent attributes.
			$variation_attrs = array();
			foreach ( array_keys( $attributes_map ) as $attr_name ) {
				$slug = self::attribute_slug( (string) $attr_name );
				$variation_attrs[ $slug ] = isset( $attrs[ $attr_name ] )
					? (string) $attrs[ $attr_name ]
					: '';
			}

			// Skip incomplete combinations.
			if ( in_array( '', $variation_attrs, true ) ) {
				continue;
			}

			$variation_id = self::find_variation_by_jd_sku( $product_id, (string) ( $sku['sku_id'] ?? '' ) );
			if ( ! $variation_id ) {
				$variation_id = self::find_variation_by_attributes( $product_id, $variation_attrs );
			}

			$variation = $variation_id
				? new WC_Product_Variation( $variation_id )
				: new WC_Product_Variation();

			$variation->set_parent_id( $product_id );
			$variation->set_attributes( $variation_attrs );
			$variation->set_status( 'publish' );

			$sku_id = preg_replace( '/\D/', '', (string) ( $sku['sku_id'] ?? '' ) );
			if ( $sku_id ) {
				$variation->set_sku( self::make_variation_sku( (string) ( $item['num_iid'] ?? '' ), $sku_id, PPW_Platform::normalize( (string) ( $item['platform'] ?? PPW_Platform::TAOBAO ) ) ) );
			}

			$price_rmb = (float) ( $sku['price'] ?? 0 );
			if ( $price_rmb <= 0 ) {
				$price_rmb = (float) ( $item['price_rmb'] ?? 0 );
			}
			$price_cad = $price_rmb > 0 ? $pricing->calculate_cad_price( $price_rmb ) : $parent_price;
			if ( $price_cad > 0 ) {
				$variation->set_regular_price( wc_format_decimal( $price_cad, wc_get_price_decimals() ) );
			}

			$qty = (int) ( $sku['quantity'] ?? 0 );
			if ( $qty > 0 ) {
				$variation->set_manage_stock( true );
				$variation->set_stock_quantity( $qty );
				$variation->set_stock_status( 'instock' );
			} else {
				$variation->set_manage_stock( false );
				$variation->set_stock_status( 'instock' );
			}

			$saved_id = $variation->save();
			if ( ! $saved_id ) {
				continue;
			}

			$vplatform = PPW_Platform::normalize( (string) ( $item['platform'] ?? PPW_Platform::TAOBAO ) );
			$vprefix   = PPW_Platform::JD === $vplatform ? '_ppw_jd_' : '_ppw_tb_';
			update_post_meta( $saved_id, $vprefix . 'sku_id', (string) ( $sku['sku_id'] ?? '' ) );
			update_post_meta( $saved_id, $vprefix . 'price_rmb', $price_rmb );
			update_post_meta( $saved_id, $vprefix . 'properties', (string) ( $sku['properties'] ?? '' ) );
			update_post_meta( $saved_id, $vprefix . 'properties_name', (string) ( $sku['properties_name'] ?? '' ) );

			$seen_ids[] = (int) $saved_id;
		}

		// Remove stale variations that no longer exist in JD data.
		$parent = wc_get_product( $product_id );
		if ( $parent && $parent->is_type( 'variable' ) ) {
			foreach ( $parent->get_children() as $child_id ) {
				if ( ! in_array( (int) $child_id, $seen_ids, true ) ) {
					$child = wc_get_product( $child_id );
					if ( $child ) {
						$child->delete( true );
					}
				}
			}
		}
	}

	/**
	 * Find existing variation by JD sku_id meta.
	 */
	private static function find_variation_by_jd_sku( int $parent_id, string $jd_sku_id ): int {
		$jd_sku_id = trim( $jd_sku_id );
		if ( '' === $jd_sku_id ) {
			return 0;
		}

		$children = wc_get_products(
			array(
				'type'   => 'variation',
				'parent' => $parent_id,
				'limit'  => -1,
				'return' => 'ids',
			)
		);

		foreach ( $children as $child_id ) {
			$meta_jd = (string) get_post_meta( (int) $child_id, '_ppw_jd_sku_id', true );
			$meta_tb = (string) get_post_meta( (int) $child_id, '_ppw_tb_sku_id', true );
			if ( $meta_jd === $jd_sku_id || $meta_tb === $jd_sku_id ) {
				return (int) $child_id;
			}
		}

		return 0;
	}

	/**
	 * Find variation matching attribute values.
	 *
	 * @param array<string, string> $attributes Slug => value.
	 */
	private static function find_variation_by_attributes( int $parent_id, array $attributes ): int {
		$data_store = WC_Data_Store::load( 'product' );
		if ( ! method_exists( $data_store, 'find_matching_product_variation' ) ) {
			return 0;
		}

		$parent = wc_get_product( $parent_id );
		if ( ! $parent ) {
			return 0;
		}

		$match_attrs = array();
		foreach ( $attributes as $slug => $value ) {
			$match_attrs[ 'attribute_' . $slug ] = $value;
		}

		$found = $data_store->find_matching_product_variation( $parent, $match_attrs );
		return $found ? (int) $found : 0;
	}

	/**
	 * @param array<string, mixed> $item Item data.
	 */
	private static function save_platform_meta( int $product_id, array $item ): void {
		$platform = PPW_Platform::normalize( (string) ( $item['platform'] ?? PPW_Platform::TAOBAO ) );
		$prefix   = PPW_Platform::JD === $platform ? '_ppw_jd_' : '_ppw_tb_';

		update_post_meta( $product_id, PPW_Platform::meta_num_iid( $platform ), (string) ( $item['num_iid'] ?? '' ) );
		update_post_meta( $product_id, $prefix . 'price_rmb', (float) ( $item['price_rmb'] ?? 0 ) );
		update_post_meta( $product_id, $prefix . 'price_cad', (float) ( $item['price_cad'] ?? 0 ) );
		update_post_meta( $product_id, PPW_Platform::meta_source_url( $platform ), esc_url_raw( (string) ( $item['detail_url'] ?? '' ) ) );
		update_post_meta( $product_id, $prefix . 'sku_count', is_array( $item['skus'] ?? null ) ? count( $item['skus'] ) : 0 );
		update_post_meta( $product_id, $prefix . 'attributes', wp_json_encode( $item['attributes'] ?? array(), JSON_UNESCAPED_UNICODE ) );
		update_post_meta( $product_id, '_ppw_source_platform', $platform );
	}

	/** @deprecated Use save_platform_meta(). */
	private static function save_jd_meta( int $product_id, array $item ): void {
		if ( empty( $item['platform'] ) ) {
			$item['platform'] = PPW_Platform::JD;
		}
		self::save_platform_meta( $product_id, $item );
	}

	/**
	 * @param array<string, mixed> $item Item data.
	 */
	private static function assign_images( int $product_id, array $item ): void {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$images = $item['images'] ?? array();
		if ( empty( $images ) && ! empty( $item['pic_url'] ) ) {
			$images = array( $item['pic_url'] );
		}

		$gallery_ids = array();

		foreach ( array_values( $images ) as $index => $image_url ) {
			$attachment_id = self::sideload_image( (string) $image_url, $product_id );
			if ( ! $attachment_id ) {
				continue;
			}

			if ( 0 === $index ) {
				set_post_thumbnail( $product_id, $attachment_id );
			} else {
				$gallery_ids[] = $attachment_id;
			}
		}

		if ( ! empty( $gallery_ids ) ) {
			update_post_meta( $product_id, '_product_image_gallery', implode( ',', $gallery_ids ) );
		}
	}

	private static function sideload_image( string $url, int $post_id ): int {
		$url = trim( $url );
		if ( '' === $url ) {
			return 0;
		}

		$tmp = download_url( $url, 30 );
		if ( is_wp_error( $tmp ) ) {
			return 0;
		}

		$path = (string) ( wp_parse_url( $url, PHP_URL_PATH ) ?: '' );
		$name = basename( $path );
		if ( '' === $name || false === strpos( $name, '.' ) ) {
			$name = 'ppw-product-' . $post_id . '.jpg';
		}

		$file = array(
			'name'     => $name,
			'tmp_name' => $tmp,
		);

		$attachment_id = media_handle_sideload( $file, $post_id );
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp );
			return 0;
		}

		return (int) $attachment_id;
	}

	/**
	 * @param array<string, mixed> $item Item data.
	 */
	private static function build_description( array $item ): string {
		$parts = array();

		if ( ! empty( $item['desc'] ) ) {
			$parts[] = wp_kses_post( (string) $item['desc'] );
		}

		if ( ! empty( $item['desc_images'] ) && is_array( $item['desc_images'] ) ) {
			foreach ( $item['desc_images'] as $img_url ) {
				$parts[] = '<p><img src="' . esc_url( (string) $img_url ) . '" alt="" loading="lazy" /></p>';
			}
		}

		if ( ! empty( $item['skus'] ) && is_array( $item['skus'] ) ) {
			$parts[] = '<h3>' . esc_html__( '可选规格', 'product-purchase-workflow' ) . '</h3><ul class="ppw-jd-sku-list">';
			foreach ( $item['skus'] as $sku ) {
				$label = (string) ( $sku['properties_name'] ?? '' );
				if ( ! empty( $sku['attributes'] ) && is_array( $sku['attributes'] ) ) {
					$pairs = array();
					foreach ( $sku['attributes'] as $n => $v ) {
						$pairs[] = $n . ': ' . $v;
					}
					$label = implode( ' / ', $pairs );
				}
				if ( '' !== $label ) {
					$parts[] = '<li>' . esc_html( $label ) . '</li>';
				}
			}
			$parts[] = '</ul>';
		}

		if ( empty( $parts ) && ! empty( $item['desc_short'] ) ) {
			$parts[] = '<p>' . esc_html( (string) $item['desc_short'] ) . '</p>';
		}

		$source = (string) ( $item['detail_url'] ?? '' );
		if ( $source ) {
			$parts[] = '<p><a href="' . esc_url( $source ) . '" target="_blank" rel="noopener noreferrer">' .
				esc_html__( '查看原商品页', 'product-purchase-workflow' ) . '</a></p>';
		}

		return implode( "\n", $parts );
	}

	private static function make_sku( string $num_iid, string $platform = PPW_Platform::TAOBAO ): string {
		return PPW_Platform::sku_prefix( $platform ) . preg_replace( '/\D/', '', $num_iid );
	}

	private static function make_variation_sku( string $num_iid, string $sku_id, string $platform = PPW_Platform::TAOBAO ): string {
		return PPW_Platform::sku_prefix( $platform ) . preg_replace( '/\D/', '', $num_iid ) . '-' . preg_replace( '/\D/', '', $sku_id );
	}
}
