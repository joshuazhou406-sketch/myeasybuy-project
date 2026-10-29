<?php
/**
 * OneBound item_get API client (Taobao / JD).
 *
 * @package Product_Purchase_Workflow
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

final class PPW_Item_Get {

	const META_KEY    = '_ppw_jd_num_iid';
	const META_KEY_TB = '_ppw_tb_num_iid';

	/**
	 * Fetch product detail by num_iid.
	 *
	 * @param string               $num_iid   Product ID.
	 * @param array<string, mixed> $fallback  Optional search-card snapshot.
	 * @param string               $platform  taobao|jd.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function fetch( string $num_iid, array $fallback = array(), string $platform = PPW_Platform::TAOBAO ) {
		$platform = PPW_Platform::normalize( $platform );
		$num_iid  = preg_replace( '/\D/', '', $num_iid );
		if ( '' === $num_iid ) {
			return new WP_Error( 'ppw_invalid_item_id', __( '无效的商品 ID。', 'product-purchase-workflow' ) );
		}

		$creds = PPW_Platform::item_get_credentials( $platform );
		if ( '' === $creds['key'] || '' === $creds['secret'] ) {
			return new WP_Error(
				'ppw_missing_item_get_credentials',
				sprintf(
					/* translators: %s: platform label */
					__( '请先在后台配置 %s item_get 的 API Key 和 Secret。', 'product-purchase-workflow' ),
					PPW_Platform::label( $platform )
				)
			);
		}

		$cache_key = 'ppw_item_v3_' . md5( $platform . '|' . $num_iid );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		// Taobao Global listings (region=GLOBAL) fail OneBound item_get with format_check=fail.
		$world_tried = false;
		if ( PPW_Platform::TAOBAO === $platform && ! empty( $fallback['global'] ) ) {
			$world_tried = true;
			$world       = self::fetch_from_world_page( $num_iid );
			if ( null !== $world ) {
				set_transient( $cache_key, $world, HOUR_IN_SECONDS );
				PPW_Logger::info( "[PPW_Item_Get] using world.taobao.com page for {$num_iid}." );
				return $world;
			}
		}

		$params        = self::build_request_params( $creds['key'], $creds['secret'], $num_iid, $platform );
		$servers       = PPW_Platform::item_get_servers( $creds['url'], $platform );
		$last_error    = null;
		$denied_path   = '';
		$unreadable    = false;
		$server_count  = count( $servers );

		for ( $i = 0; $i < $server_count; $i++ ) {
			$server_base = $servers[ $i ];
			$path        = untrailingslashit( (string) ( wp_parse_url( $server_base, PHP_URL_PATH ) ?: '' ) );
			if ( '' !== $denied_path && $path === $denied_path ) {
				continue;
			}

			$url      = rtrim( $server_base, '/' ) . '/?' . http_build_query( $params );
			$response = self::request_api( $url );

			if ( is_wp_error( $response ) ) {
				$last_error = $response;
				PPW_Logger::warning( '[PPW_Item_Get] Request failed on ' . $server_base . ': ' . $response->get_error_message() );
				continue;
			}

			$error = self::parse_api_error( $response );
			if ( is_wp_error( $error ) ) {
				$last_error = $error;
				PPW_Logger::warning( '[PPW_Item_Get] API error on ' . $server_base . ': ' . $error->get_error_message() );
				$code = (string) ( $response['error_code'] ?? '' );
				// 4005 means this API is not enabled; other hosts of the same endpoint will fail the same way.
				if ( '4005' === $code && '' !== $path ) {
					$denied_path = $path;
				}
				$raw_item = $response['item'] ?? null;
				if ( is_array( $raw_item ) && 'fail' === (string) ( $raw_item['format_check'] ?? '' ) ) {
					$unreadable = true;
					break;
				}
				continue;
			}

			$item = $response['item'] ?? null;
			if ( empty( $item ) || ! is_array( $item ) ) {
				$last_error = new WP_Error( 'ppw_item_get_empty', __( '未获取到商品详情。', 'product-purchase-workflow' ) );
				continue;
			}

			$normalized = self::normalize_item( $item, $num_iid, $platform );
			set_transient( $cache_key, $normalized, HOUR_IN_SECONDS );

			return $normalized;
		}

		$fallback_item = self::resolve_fallback_item( $num_iid, $fallback, $platform );
		if ( null !== $fallback_item ) {
			PPW_Logger::info( "[PPW_Item_Get] item_get unavailable for {$platform}:{$num_iid}, using search snapshot fallback." );
			return $fallback_item;
		}

		if ( PPW_Platform::TAOBAO === $platform && $unreadable && ! $world_tried ) {
			$world = self::fetch_from_world_page( $num_iid );
			if ( null !== $world ) {
				set_transient( $cache_key, $world, HOUR_IN_SECONDS );
				PPW_Logger::info( "[PPW_Item_Get] item_get unreadable for {$num_iid}, using world.taobao.com page." );
				return $world;
			}
		}

		if ( $last_error instanceof WP_Error ) {
			$message = $last_error->get_error_message();
			return new WP_Error(
				$last_error->get_error_code(),
				$message . ' ' . __( '已尝试多个 OneBound 节点；可使用搜索列表数据上架，请刷新页面后重试。', 'product-purchase-workflow' )
			);
		}

		return new WP_Error( 'ppw_item_get_failed', __( '无法获取商品详情。', 'product-purchase-workflow' ) );
	}

	/**
	 * Cache a search-result snapshot for later item_get fallback.
	 *
	 * @param array<string, mixed> $product Normalized search product.
	 */
	public static function cache_search_snapshot( array $product ): void {
		$item_id  = preg_replace( '/\D/', '', (string) ( $product['item_id'] ?? '' ) );
		$platform = PPW_Platform::normalize( (string) ( $product['platform'] ?? PPW_Platform::TAOBAO ) );
		if ( '' === $item_id ) {
			return;
		}

		set_transient( self::search_snapshot_key( $item_id, $platform ), $product, DAY_IN_SECONDS );
	}

	/**
	 * @return array<string, string>
	 */
	private static function build_request_params( string $api_key, string $secret, string $num_iid, string $platform = PPW_Platform::JD ): array {
		$params = array(
			'key'         => $api_key,
			'secret'      => $secret,
			'num_iid'     => $num_iid,
			'cache'       => 'no',
			'lang'        => 'zh-CN',
			'result_type' => 'json',
		);

		if ( PPW_Platform::JD === PPW_Platform::normalize( $platform ) ) {
			$params['domain_type'] = 'jd';
			$params['watermark']   = '1';
		}

		return $params;
	}

	/**
	 * @deprecated Use PPW_Platform::item_get_servers().
	 * @return array<int, string>
	 */
	private static function get_api_servers( string $configured_url ): array {
		return PPW_Platform::item_get_servers( $configured_url, PPW_Platform::JD );
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	private static function request_api( string $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 25,
				'sslverify' => true,
				'headers'   => array(
					'Accept'     => 'application/json',
					'Connection' => 'close',
					'User-Agent' => 'MyEasyBuy-ProductPurchaseWorkflow/' . PPW_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code >= 400 ) {
			return new WP_Error(
				'ppw_item_get_http',
				sprintf( __( '商品详情请求失败，HTTP %d', 'product-purchase-workflow' ), $code )
			);
		}

		try {
			$data = json_decode( $body, true, 512, JSON_THROW_ON_ERROR );
		} catch ( Throwable $e ) {
			return new WP_Error( 'ppw_item_get_json', $e->getMessage() );
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'ppw_item_get_json', __( '商品详情响应格式无效。', 'product-purchase-workflow' ) );
		}

		return $data;
	}

	/**
	 * @param array<string, mixed> $fallback Client-provided snapshot.
	 * @return array<string, mixed>|null
	 */
	private static function resolve_fallback_item( string $num_iid, array $fallback, string $platform = PPW_Platform::JD ): ?array {
		if ( ! empty( $fallback['title'] ) && ! empty( $fallback['price_rmb'] ) ) {
			return self::normalize_from_search_snapshot( $fallback, $num_iid, $platform );
		}

		$cached = get_transient( self::search_snapshot_key( $num_iid, $platform ) );
		if ( is_array( $cached ) && ! empty( $cached['title'] ) ) {
			return self::normalize_from_search_snapshot( $cached, $num_iid, $platform );
		}

		return null;
	}

	/**
	 * Build item_get-shaped payload from item_search snapshot.
	 *
	 * @param array<string, mixed> $snapshot Search product row.
	 * @param string               $num_iid  Product ID.
	 * @return array<string, mixed>
	 */
	private static function normalize_from_search_snapshot( array $snapshot, string $num_iid, string $platform = PPW_Platform::TAOBAO ): array {
		$platform   = PPW_Platform::normalize( (string) ( $snapshot['platform'] ?? $platform ) );
		$pricing    = new PPW_Pricing_Agent();
		$price_rmb  = (float) ( $snapshot['price_rmb'] ?? 0 );
		$image      = self::normalize_image_url( (string) ( $snapshot['image'] ?? ( $snapshot['pic_url'] ?? '' ) ) );
		$detail_url = (string) ( $snapshot['url'] ?? ( $snapshot['detail_url'] ?? '' ) );

		if ( '' === $detail_url ) {
			$detail_url = PPW_Platform::default_item_url( $platform, $num_iid );
		}

		return array(
			'num_iid'              => $num_iid,
			'title'                => (string) ( $snapshot['title'] ?? '' ),
			'desc_short'           => (string) ( $snapshot['title'] ?? '' ),
			'desc'                 => '',
			'price_rmb'            => $price_rmb,
			'price_cad'            => $pricing->calculate_cad_price( $price_rmb ),
			'orginal_price'        => $price_rmb,
			'pic_url'              => $image,
			'images'               => $image ? array( $image ) : array(),
			'desc_images'          => array(),
			'detail_url'           => $detail_url,
			'shop'                 => (string) ( $snapshot['shop'] ?? '' ),
			'stock'                => 0,
			'props_list'           => array(),
			'skus'                 => array(),
			'platform'             => $platform,
			'from_search_fallback' => true,
		);
	}

	public static function search_snapshot_key( string $num_iid, string $platform = PPW_Platform::TAOBAO ): string {
		$platform = PPW_Platform::normalize( $platform );
		return 'ppw_search_item_' . $platform . '_' . md5( $num_iid );
	}

	/**
	 * @param array<string, mixed> $item Raw item.
	 * @param string               $num_iid Requested ID.
	 * @return array<string, mixed>
	 */
	private static function normalize_item( array $item, string $num_iid, string $platform = PPW_Platform::TAOBAO ): array {
		$platform = PPW_Platform::normalize( $platform );
		$pricing   = new PPW_Pricing_Agent();
		$price_rmb = self::extract_price( $item );
		$price_cad = $pricing->calculate_cad_price( $price_rmb );

		$images = array();
		$main   = self::normalize_image_url( (string) ( $item['pic_url'] ?? ( $item['sku_pic'] ?? '' ) ) );
		if ( $main ) {
			$images[] = $main;
		}

		if ( ! empty( $item['item_imgs'] ) && is_array( $item['item_imgs'] ) ) {
			foreach ( $item['item_imgs'] as $img ) {
				$url = is_array( $img ) ? ( $img['url'] ?? '' ) : (string) $img;
				$url = self::normalize_image_url( $url );
				if ( $url && ! in_array( $url, $images, true ) ) {
					$images[] = $url;
				}
			}
		}

		$desc_images = array();
		if ( ! empty( $item['desc_img'] ) && is_array( $item['desc_img'] ) ) {
			foreach ( $item['desc_img'] as $img ) {
				$url = is_array( $img ) ? ( $img['url'] ?? '' ) : (string) $img;
				$url = self::normalize_image_url( $url );
				if ( $url ) {
					$desc_images[] = $url;
				}
			}
		}

		$shop = '';
		if ( ! empty( $item['seller_info'] ) && is_array( $item['seller_info'] ) ) {
			$shop = (string) ( $item['seller_info']['shop_name'] ?? ( $item['seller_info']['nick'] ?? '' ) );
		}
		if ( '' === $shop ) {
			$shop = (string) ( $item['nick'] ?? '' );
		}

		$detail_url = (string) ( $item['detail_url'] ?? '' );
		if ( '' === $detail_url ) {
			$detail_url = PPW_Platform::default_item_url( $platform, $num_iid );
		}

		$skus       = self::normalize_skus( $item['skus'] ?? null );
		$attributes = self::build_attributes_from_item( $item, $skus );

		return array(
			'num_iid'              => (string) ( $item['num_iid'] ?? $num_iid ),
			'title'                => (string) ( $item['title'] ?? '' ),
			'desc_short'           => (string) ( $item['desc_short'] ?? '' ),
			'desc'                 => (string) ( $item['desc'] ?? '' ),
			'price_rmb'            => $price_rmb,
			'price_cad'            => $price_cad,
			'orginal_price'        => (float) ( $item['orginal_price'] ?? 0 ),
			'pic_url'              => $main,
			'images'               => $images,
			'desc_images'          => $desc_images,
			'detail_url'           => $detail_url,
			'shop'                 => $shop,
			'stock'                => (int) ( $item['num'] ?? 0 ),
			'props_list'           => is_array( $item['props_list'] ?? null ) ? $item['props_list'] : array(),
			'props_name'           => (string) ( $item['props_name'] ?? '' ),
			'attributes'           => $attributes,
			'skus'                 => $skus,
			'platform'             => $platform,
			'from_search_fallback' => false,
		);
	}

	/**
	 * @param mixed $skus Raw skus node.
	 * @return array<int, array<string, mixed>>
	 */
	private static function normalize_skus( $skus ): array {
		if ( empty( $skus ) || ! is_array( $skus ) ) {
			return array();
		}

		$list = $skus['sku'] ?? $skus;
		if ( isset( $list['sku_id'] ) || isset( $list['properties_name'] ) ) {
			$list = array( $list );
		}

		$result = array();
		foreach ( $list as $sku ) {
			if ( ! is_array( $sku ) ) {
				continue;
			}

			$properties_name = (string) ( $sku['properties_name'] ?? '' );
			$attributes      = self::parse_properties_name( $properties_name );

			// Fallback: properties may be "0:3;1:0" without names.
			if ( empty( $attributes ) && ! empty( $sku['properties'] ) ) {
				$attributes = array(
					__( '规格', 'product-purchase-workflow' ) => (string) $sku['properties'],
				);
			}

			$result[] = array(
				'sku_id'          => (string) ( $sku['sku_id'] ?? '' ),
				'properties'      => (string) ( $sku['properties'] ?? '' ),
				'properties_name' => $properties_name,
				'attributes'      => $attributes,
				'price'           => (float) preg_replace( '/[^\d.]/', '', (string) ( $sku['price'] ?? 0 ) ),
				'orginal_price'   => (float) preg_replace( '/[^\d.]/', '', (string) ( $sku['orginal_price'] ?? 0 ) ),
				'quantity'        => (int) ( $sku['quantity'] ?? 0 ),
				'sku_url'         => (string) ( $sku['sku_url'] ?? '' ),
			);
		}

		return $result;
	}

	/**
	 * Parse JD properties_name like "0:3:尺码:XL;1:0:颜色:花色".
	 *
	 * @return array<string, string> Attribute name => value.
	 */
	public static function parse_properties_name( string $properties_name ): array {
		$properties_name = trim( $properties_name );
		if ( '' === $properties_name ) {
			return array();
		}

		$attributes = array();
		$segments   = array_filter( array_map( 'trim', explode( ';', $properties_name ) ) );

		foreach ( $segments as $segment ) {
			$parts = explode( ':', $segment );
			if ( count( $parts ) >= 4 ) {
				// Format: attr_id:value_id:attr_name:value_name...
				$attr_name  = trim( (string) $parts[2] );
				$value_name = trim( implode( ':', array_slice( $parts, 3 ) ) );
				if ( '' !== $attr_name && '' !== $value_name ) {
					$attributes[ $attr_name ] = $value_name;
				}
				continue;
			}

			if ( 2 === count( $parts ) ) {
				// Format from props_list value: "尺码:XL".
				$attr_name  = trim( (string) $parts[0] );
				$value_name = trim( (string) $parts[1] );
				if ( '' !== $attr_name && '' !== $value_name ) {
					$attributes[ $attr_name ] = $value_name;
				}
			}
		}

		return $attributes;
	}

	/**
	 * Build WooCommerce-ready attribute map from props_list / props_name / skus.
	 *
	 * @param array<string, mixed>             $item Raw API item.
	 * @param array<int, array<string, mixed>> $skus Normalized SKUs.
	 * @return array<string, array<int, string>> Attribute name => unique values.
	 */
	private static function build_attributes_from_item( array $item, array $skus ): array {
		$attributes = array();

		// Prefer SKU attributes (customer-selectable options).
		foreach ( $skus as $sku ) {
			if ( empty( $sku['attributes'] ) || ! is_array( $sku['attributes'] ) ) {
				continue;
			}
			foreach ( $sku['attributes'] as $name => $value ) {
				$name  = (string) $name;
				$value = (string) $value;
				if ( '' === $name || '' === $value ) {
					continue;
				}
				if ( ! isset( $attributes[ $name ] ) ) {
					$attributes[ $name ] = array();
				}
				if ( ! in_array( $value, $attributes[ $name ], true ) ) {
					$attributes[ $name ][] = $value;
				}
			}
		}

		if ( ! empty( $attributes ) ) {
			return $attributes;
		}

		// Fallback: props_name "0:0:尺码:S;0:1:尺码:M;1:0:颜色:花色".
		$props_name = (string) ( $item['props_name'] ?? '' );
		if ( '' !== $props_name ) {
			foreach ( array_filter( array_map( 'trim', explode( ';', $props_name ) ) ) as $segment ) {
				$parts = explode( ':', $segment );
				if ( count( $parts ) < 4 ) {
					continue;
				}
				$name  = trim( (string) $parts[2] );
				$value = trim( implode( ':', array_slice( $parts, 3 ) ) );
				if ( '' === $name || '' === $value ) {
					continue;
				}
				if ( ! isset( $attributes[ $name ] ) ) {
					$attributes[ $name ] = array();
				}
				if ( ! in_array( $value, $attributes[ $name ], true ) ) {
					$attributes[ $name ][] = $value;
				}
			}
		}

		if ( ! empty( $attributes ) ) {
			return $attributes;
		}

		// Last fallback: props_list {"0:0":"尺码:S","1:0":"颜色:花色"}.
		if ( ! empty( $item['props_list'] ) && is_array( $item['props_list'] ) ) {
			foreach ( $item['props_list'] as $value ) {
				$parsed = self::parse_properties_name( (string) $value );
				foreach ( $parsed as $name => $option ) {
					if ( ! isset( $attributes[ $name ] ) ) {
						$attributes[ $name ] = array();
					}
					if ( ! in_array( $option, $attributes[ $name ], true ) ) {
						$attributes[ $name ][] = $option;
					}
				}
			}
		}

		return $attributes;
	}

	/**
	 * @param array<string, mixed> $item Item data.
	 */
	private static function extract_price( array $item ): float {
		$candidates = array(
			$item['price'] ?? null,
			$item['orginal_price'] ?? null,
			$item['total_price'] ?? null,
		);

		foreach ( $candidates as $candidate ) {
			if ( null === $candidate || '' === $candidate ) {
				continue;
			}
			$price = (float) preg_replace( '/[^\d.]/', '', (string) $candidate );
			if ( $price > 0 ) {
				return $price;
			}
		}

		return 0.0;
	}

	private static function normalize_image_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		if ( str_starts_with( $url, '//' ) ) {
			return 'https:' . $url;
		}
		return $url;
	}

	/**
	 * Taobao Global SEO page. OneBound item_get returns format_check=fail for these IDs.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function fetch_from_world_page( string $num_iid ): ?array {
		$url      = 'https://world.taobao.com/item/' . rawurlencode( $num_iid ) . '.htm';
		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 20,
				'sslverify' => true,
				'headers'   => array(
					'Accept'          => 'text/html,application/xhtml+xml',
					'Accept-Language' => 'zh-CN,zh;q=0.9,en;q=0.8',
					'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			PPW_Logger::warning( '[PPW_Item_Get] world.taobao.com request failed: ' . $response->get_error_message() );
			return null;
		}

		if ( (int) wp_remote_retrieve_response_code( $response ) >= 400 ) {
			return null;
		}

		return self::parse_world_html( (string) wp_remote_retrieve_body( $response ), $num_iid, $url );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function parse_world_html( string $html, string $num_iid, string $page_url ): ?array {
		$normal = null;
		$marker = 'var b = ';
		$start  = strpos( $html, $marker );
		$end    = false !== $start ? strpos( $html, '};for (var k in a)', $start ) : false;
		if ( false !== $start && false !== $end ) {
			$json_start = $start + strlen( $marker );
			$json       = substr( $html, $json_start, $end - $json_start + 1 );
			$data       = json_decode( $json, true );
			if ( is_array( $data ) ) {
				$candidate = $data['loaderData']['pdp-pc']['data']['httpData']['normalItemResponse'] ?? null;
				if ( is_array( $candidate ) ) {
					$normal = $candidate;
				}
			}
		}

		$title  = '';
		$price  = 0.0;
		$images = array();
		$shop   = '';
		$desc   = '';
		$props  = array();

		if ( is_array( $normal ) && ! empty( $normal['itemExist'] ) && is_array( $normal['item'] ?? null ) ) {
			$item  = $normal['item'];
			$title = trim( (string) ( $item['title'] ?? '' ) );
			$price = (float) ( $normal['itemPrice']['promotionPrice'] ?? ( $normal['itemPrice']['originalPrice'] ?? 0 ) );
			foreach ( (array) ( $item['images'] ?? array() ) as $image ) {
				$image = self::normalize_image_url( (string) $image );
				if ( $image && ! in_array( $image, $images, true ) ) {
					$images[] = $image;
				}
			}
			if ( is_array( $normal['seller'] ?? null ) ) {
				$shop = trim( (string) ( $normal['seller']['shopName'] ?? '' ) );
			}
			$location = trim( (string) ( $item['location'] ?? '' ) );
			if ( '' !== $location ) {
				$props['发货地'] = $location;
			}
		}

		if ( '' === $title && preg_match( '/property="og:title"\s+content="([^"]+)"/i', $html, $m ) ) {
			$title = trim( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) );
			$title = preg_replace( '/\s*-\s*淘[宝寶]Taobao.*$/u', '', $title ) ?: $title;
		}
		if ( $price <= 0 && preg_match( '/class="text-basic currency">\s*([0-9]+(?:\.[0-9]+)?)\s*</', $html, $m ) ) {
			$price = (float) $m[1];
		}
		if ( empty( $images ) && preg_match( '/property="og:image"\s+content="([^"]+)"/i', $html, $m ) ) {
			$image = self::normalize_image_url( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) );
			if ( $image ) {
				$images[] = $image;
			}
		}
		if ( '' === $desc && preg_match( '/name="description"\s+content="([^"]+)"/i', $html, $m ) ) {
			$desc = trim( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) );
		}
		if ( '' === $shop && preg_match( '/class="view-basic title">([^<]{2,80})<\/div>/', $html, $m ) ) {
			$shop = trim( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) );
		}

		$title = trim( $title );
		if ( '' === $title || $price <= 0 ) {
			return null;
		}

		$pricing = new PPW_Pricing_Agent();

		return array(
			'num_iid'              => $num_iid,
			'title'                => $title,
			'desc_short'           => '' !== $desc ? $desc : $title,
			'desc'                 => $desc,
			'price_rmb'            => $price,
			'price_cad'            => $pricing->calculate_cad_price( $price ),
			'orginal_price'        => $price,
			'pic_url'              => $images[0] ?? '',
			'images'               => $images,
			'desc_images'          => array(),
			'detail_url'           => $page_url,
			'shop'                 => $shop,
			'stock'                => 0,
			'props_list'           => $props,
			'props_name'           => '',
			'attributes'           => array(),
			'skus'                 => array(),
			'platform'             => PPW_Platform::TAOBAO,
			'from_search_fallback' => false,
			'from_world_page'      => true,
		);
	}

	/**
	 * @param array<string, mixed> $data API response.
	 * @return true|WP_Error
	 */
	private static function parse_api_error( array $data ) {
		if ( ! empty( $data['error_code'] ) && '0000' === (string) $data['error_code'] ) {
			return true;
		}

		if ( ! empty( $data['error'] ) ) {
			$message = (string) $data['error'];
			if ( ! empty( $data['reason'] ) ) {
				$message = (string) $data['reason'];
			}
			if ( ! empty( $data['error_code'] ) ) {
				$message .= ' (code: ' . $data['error_code'] . ')';
			}
			return new WP_Error( 'ppw_onebound_error', $message );
		}

		if ( isset( $data['success'] ) && ( 0 === (int) $data['success'] || '0' === $data['success'] ) ) {
			return new WP_Error(
				'ppw_onebound_error',
				(string) ( $data['reason'] ?? __( 'OneBound API 请求失败。', 'product-purchase-workflow' ) )
			);
		}

		return true;
	}
}
