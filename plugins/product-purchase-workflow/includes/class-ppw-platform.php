<?php
/**
 * Marketplace platform helpers (Taobao / JD).
 *
 * @package Product_Purchase_Workflow
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

final class PPW_Platform {

	public const TAOBAO = 'taobao';
	public const JD     = 'jd';

	/**
	 * Normalize platform slug. Default: taobao.
	 */
	public static function normalize( string $platform ): string {
		$platform = strtolower( trim( $platform ) );
		return self::JD === $platform ? self::JD : self::TAOBAO;
	}

	/**
	 * Human label.
	 */
	public static function label( string $platform ): string {
		return self::JD === self::normalize( $platform )
			? __( '京东', 'product-purchase-workflow' )
			: __( '淘宝', 'product-purchase-workflow' );
	}

	/**
	 * Product ID meta key for WC sync.
	 */
	public static function meta_num_iid( string $platform ): string {
		return self::JD === self::normalize( $platform ) ? '_ppw_jd_num_iid' : '_ppw_tb_num_iid';
	}

	/**
	 * Source URL meta key.
	 */
	public static function meta_source_url( string $platform ): string {
		return self::JD === self::normalize( $platform ) ? '_ppw_jd_source_url' : '_ppw_tb_source_url';
	}

	/**
	 * WC SKU prefix.
	 */
	public static function sku_prefix( string $platform ): string {
		return self::JD === self::normalize( $platform ) ? 'jd-' : 'tb-';
	}

	/**
	 * Default product detail URL.
	 */
	public static function default_item_url( string $platform, string $num_iid ): string {
		$num_iid = preg_replace( '/\D/', '', $num_iid ) ?: $num_iid;
		if ( self::JD === self::normalize( $platform ) ) {
			return 'https://item.jd.com/' . $num_iid . '.html';
		}
		return 'https://item.taobao.com/item.htm?id=' . $num_iid;
	}

	/**
	 * Whether input looks like a marketplace product URL (vs keyword).
	 */
	public static function looks_like_product_url( string $input ): bool {
		$input = trim( $input );
		if ( '' === $input ) {
			return false;
		}

		if ( preg_match( '#^https?://#i', $input ) ) {
			return true;
		}

		// Scheme-less paste: item.jd.com/... or detail.tmall.com/...
		return (bool) preg_match(
			'#(?:^|://|www\.)?(?:item\.|detail\.|product\.|m\.|a\.m\.|u\.|e\.)?(?:jd\.(?:com|hk)|taobao\.com|tmall\.(?:com|hk)|tb\.cn|tmall\.tv)#i',
			$input
		);
	}

	/**
	 * Parse a Taobao / JD product URL into platform + item id.
	 *
	 * Follows common short-link redirects (m.tb.cn, u.jd.com) when needed.
	 *
	 * @return array{platform: string, num_iid: string, url: string, global: bool, sku_id: string}|null
	 */
	public static function parse_product_url( string $input ): ?array {
		$input = trim( $input );
		if ( '' === $input || ! self::looks_like_product_url( $input ) ) {
			return null;
		}

		$url = self::normalize_url_candidate( $input );
		$parsed = self::extract_id_from_url( $url );
		if ( null !== $parsed ) {
			return $parsed;
		}

		// Short / mobile share links often need a redirect to reveal the item page.
		if ( self::is_short_or_share_link( $url ) ) {
			$resolved = self::resolve_redirect_url( $url );
			if ( $resolved && $resolved !== $url ) {
				$parsed = self::extract_id_from_url( $resolved );
				if ( null !== $parsed ) {
					return $parsed;
				}
			}
		}

		return null;
	}

	/**
	 * Ensure a usable absolute URL string.
	 */
	private static function normalize_url_candidate( string $input ): string {
		$input = trim( $input );
		if ( ! preg_match( '#^https?://#i', $input ) ) {
			$input = 'https://' . ltrim( $input, '/' );
		}

		// Strip tracking wrappers that nest the real URL in a query param.
		$parts = wp_parse_url( $input );
		if ( ! empty( $parts['query'] ) ) {
			parse_str( (string) $parts['query'], $query );
			foreach ( array( 'url', 'target', 'redirect_url', 'to' ) as $key ) {
				if ( ! empty( $query[ $key ] ) && is_string( $query[ $key ] ) && self::looks_like_product_url( $query[ $key ] ) ) {
					return self::normalize_url_candidate( rawurldecode( $query[ $key ] ) );
				}
			}
		}

		return $input;
	}

	/**
	 * @return array{platform: string, num_iid: string, url: string, global: bool, sku_id: string}|null
	 */
	private static function extract_id_from_url( string $url ): ?array {
		$host = strtolower( (string) ( wp_parse_url( $url, PHP_URL_HOST ) ?: '' ) );
		$path = (string) ( wp_parse_url( $url, PHP_URL_PATH ) ?: '' );
		$query_string = (string) ( wp_parse_url( $url, PHP_URL_QUERY ) ?: '' );
		parse_str( $query_string, $query );

		$is_jd = (bool) preg_match( '/(?:^|\.)jd\.(?:com|hk)$/i', $host );
		$is_tb = (bool) preg_match( '/(?:^|\.)(?:taobao\.com|tmall\.com|tmall\.hk|tb\.cn|tmall\.tv)$/i', $host );

		if ( ! $is_jd && ! $is_tb ) {
			return null;
		}

		$platform = $is_jd ? self::JD : self::TAOBAO;
		$num_iid  = '';

		if ( $is_jd ) {
			foreach ( array( 'sku', 'wareId', 'wareid', 'productId', 'id' ) as $key ) {
				if ( ! empty( $query[ $key ] ) && preg_match( '/^\d{5,}$/', (string) $query[ $key ] ) ) {
					$num_iid = (string) $query[ $key ];
					break;
				}
			}
			if ( '' === $num_iid && preg_match( '#/(?:product/)?(\d{5,})\.html#i', $path, $m ) ) {
				$num_iid = $m[1];
			}
			if ( '' === $num_iid && preg_match( '#/(\d{5,})/?$#', $path, $m ) ) {
				$num_iid = $m[1];
			}
		} else {
			foreach ( array( 'id', 'item_id', 'itemId', 'num_iid' ) as $key ) {
				if ( ! empty( $query[ $key ] ) && preg_match( '/^\d{5,}$/', (string) $query[ $key ] ) ) {
					$num_iid = (string) $query[ $key ];
					break;
				}
			}
			// a.m.taobao.com/i123456.htm
			if ( '' === $num_iid && preg_match( '#/i(\d{5,})\.htm#i', $path, $m ) ) {
				$num_iid = $m[1];
			}
			if ( '' === $num_iid && preg_match( '#(?:item|detail)[^/]*/(\d{5,})#i', $path, $m ) ) {
				$num_iid = $m[1];
			}
		}

		$num_iid = preg_replace( '/\D/', '', $num_iid ) ?: '';
		if ( '' === $num_iid ) {
			return null;
		}

		$sku_id = '';
		foreach ( array( 'skuId', 'sku_id', 'skuid' ) as $key ) {
			if ( ! empty( $query[ $key ] ) && preg_match( '/^\d{5,}$/', (string) $query[ $key ] ) ) {
				$sku_id = (string) $query[ $key ];
				break;
			}
		}

		$global = $is_tb && (
			(bool) preg_match( '/(?:^|\.)world\.taobao\.com$/i', $host )
			|| ( ! empty( $query['region'] ) && 'GLOBAL' === strtoupper( (string) $query['region'] ) )
		);

		$item_url = self::default_item_url( $platform, $num_iid );
		if ( $global ) {
			$item_url = 'https://world.taobao.com/item/' . $num_iid . '.htm';
		}

		return array(
			'platform' => $platform,
			'num_iid'  => $num_iid,
			'url'      => $item_url,
			'global'   => $global,
			'sku_id'   => $sku_id,
		);
	}

	private static function is_short_or_share_link( string $url ): bool {
		$host = strtolower( (string) ( wp_parse_url( $url, PHP_URL_HOST ) ?: '' ) );
		return (bool) preg_match( '/(?:^|\.)(?:tb\.cn|u\.jd\.com|3\.cn)$/i', $host )
			|| (bool) preg_match( '#/(?:h\.|s/)#i', (string) ( wp_parse_url( $url, PHP_URL_PATH ) ?: '' ) );
	}

	/**
	 * Follow Location redirects to uncover the real item URL.
	 */
	private static function resolve_redirect_url( string $url ): string {
		$current = $url;
		for ( $i = 0; $i < 6; $i++ ) {
			$response = wp_remote_get(
				$current,
				array(
					'timeout'     => 12,
					'redirection' => 0,
					'sslverify'   => true,
					'headers'     => array(
						'User-Agent' => 'Mozilla/5.0 (compatible; MyEasyBuy-PPW/' . ( defined( 'PPW_VERSION' ) ? PPW_VERSION : '1.0' ) . ')',
						'Accept'     => 'text/html,application/xhtml+xml',
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				break;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$loc  = trim( (string) wp_remote_retrieve_header( $response, 'location' ) );

			if ( $loc && $code >= 300 && $code < 400 ) {
				if ( ! preg_match( '#^https?://#i', $loc ) ) {
					$parts = wp_parse_url( $current );
					$base  = ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' );
					$loc   = str_starts_with( $loc, '/' ) ? $base . $loc : rtrim( $base, '/' ) . '/' . $loc;
				}
				$current = $loc;
				$parsed  = self::extract_id_from_url( $current );
				if ( null !== $parsed ) {
					return $current;
				}
				continue;
			}

			// Some share pages embed the real URL in HTML.
			$body = (string) wp_remote_retrieve_body( $response );
			if ( $body && preg_match( '#https?://(?:item\.taobao\.com|detail\.tmall\.com|item\.jd\.com)/[^"\'\s<>]+#i', $body, $m ) ) {
				return html_entity_decode( $m[0], ENT_QUOTES );
			}

			break;
		}

		return $current;
	}

	/**
	 * Search API credentials + URL for a platform.
	 *
	 * @return array{key: string, secret: string, url: string}
	 */
	public static function search_credentials( string $platform ): array {
		$settings = get_option( 'ppw_settings', ppw_default_settings() );
		$platform = self::normalize( $platform );

		if ( self::JD === $platform ) {
			return array(
				'key'    => trim( (string) ( $settings['api_key'] ?? '' ) ),
				'secret' => trim( (string) ( $settings['api_secret'] ?? '' ) ),
				'url'    => (string) ( $settings['api_url'] ?? 'https://api-gw.onebound.cn/jd/item_search' ),
			);
		}

		$key    = trim( (string) ( $settings['tb_api_key'] ?? '' ) );
		$secret = trim( (string) ( $settings['tb_api_secret'] ?? '' ) );
		// Fall back to shared OneBound key if Taobao-specific blank.
		if ( '' === $key ) {
			$key = trim( (string) ( $settings['api_key'] ?? '' ) );
		}
		if ( '' === $secret ) {
			$secret = trim( (string) ( $settings['api_secret'] ?? '' ) );
		}

		return array(
			'key'    => $key,
			'secret' => $secret,
			'url'    => (string) ( $settings['tb_api_url'] ?? 'https://api-gw.onebound.cn/taobao/item_search' ),
		);
	}

	/**
	 * item_get credentials + URL for a platform.
	 *
	 * @return array{key: string, secret: string, url: string}
	 */
	public static function item_get_credentials( string $platform ): array {
		$settings = get_option( 'ppw_settings', ppw_default_settings() );
		$platform = self::normalize( $platform );
		$search   = self::search_credentials( $platform );

		if ( self::JD === $platform ) {
			$key    = trim( (string) ( $settings['item_get_api_key'] ?? '' ) );
			$secret = trim( (string) ( $settings['item_get_api_secret'] ?? '' ) );
			$url    = (string) ( $settings['item_get_api_url'] ?? 'https://api-gw.onebound.cn/jd/item_get' );
		} else {
			$key    = trim( (string) ( $settings['tb_item_get_api_key'] ?? '' ) );
			$secret = trim( (string) ( $settings['tb_item_get_api_secret'] ?? '' ) );
			$url    = (string) ( $settings['tb_item_get_api_url'] ?? 'https://api-gw.onebound.cn/taobao/item_get_pro' );
		}

		if ( '' === $key ) {
			$key = $search['key'];
		}
		if ( '' === $secret ) {
			$secret = $search['secret'];
		}

		return array(
			'key'    => $key,
			'secret' => $secret,
			'url'    => $url,
		);
	}

	/**
	 * API server fallbacks for search.
	 *
	 * @return array<int, string>
	 */
	public static function search_servers( string $configured_url, string $platform ): array {
		$platform = self::normalize( $platform );
		$path     = self::JD === $platform ? 'jd/item_search' : 'taobao/item_search';
		$servers  = array( $configured_url );

		foreach ( array( 'api-gw', 'api-1', 'api-2', 'api-3', 'api-4' ) as $host ) {
			$servers[] = "https://{$host}.onebound.cn/{$path}";
		}

		return array_values( array_unique( array_filter( $servers ) ) );
	}

	/**
	 * API server fallbacks for image search (item_search_img).
	 *
	 * @return array<int, string>
	 */
	public static function img_search_servers( string $platform ): array {
		$platform = self::normalize( $platform );
		$path     = self::JD === $platform ? 'jd/item_search_img' : 'taobao/item_search_img';
		$servers  = array();

		foreach ( array( 'api-gw', 'api-1', 'api-2', 'api-3', 'api-4' ) as $host ) {
			$servers[] = "https://{$host}.onebound.cn/{$path}";
		}

		return $servers;
	}

	/**
	 * API server fallbacks for upload_img (prepare imgid for image search).
	 *
	 * @return array<int, string>
	 */
	public static function upload_img_servers( string $platform ): array {
		$platform = self::normalize( $platform );
		$path     = self::JD === $platform ? 'jd/upload_img' : 'taobao/upload_img';
		$servers  = array();

		foreach ( array( 'api-gw', 'api-1', 'api-2', 'api-3', 'api-4' ) as $host ) {
			$servers[] = "https://{$host}.onebound.cn/{$path}";
		}

		return $servers;
	}

	/**
	 * API server fallbacks for item_get.
	 *
	 * @return array<int, string>
	 */
	public static function item_get_servers( string $configured_url, string $platform ): array {
		$platform  = self::normalize( $platform );
		$endpoints = self::JD === $platform
			? array( 'jd/item_get', 'jd/item_get_pro' )
			: array( 'taobao/item_get_pro', 'taobao/item_get' );

		$servers = array( $configured_url );
		foreach ( $endpoints as $endpoint ) {
			foreach ( array( 'api-gw', 'api-1', 'api-2', 'api-3', 'api-4' ) as $host ) {
				$servers[] = "https://{$host}.onebound.cn/{$endpoint}";
			}
		}

		return array_values( array_unique( array_filter( $servers ) ) );
	}
}
