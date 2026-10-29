<?php
/**
 * Port of product_purchase_workflow.php — purchase workflow + OneBound JD search.
 *
 * @package Product_Purchase_Workflow
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

enum PPW_Workflow_State: string {
	case INIT                  = 'INIT';
	case PARSE_LINK            = 'PARSE_LINK';
	case MANUAL_SPEC_PENDING   = 'MANUAL_SPEC_PENDING';
	case PRICING_ENGINE        = 'PRICING_ENGINE';
	case CREATE_INVOICE        = 'CREATE_INVOICE';
	case COMPLETED             = 'COMPLETED';
	case FAILED                = 'FAILED';
}

final class PPW_Logger {
	private const NAME = 'ProductPurchaseWorkflow';

	public static function info( string $message ): void {
		self::log( 'INFO', $message );
	}

	public static function warning( string $message ): void {
		self::log( 'WARNING', $message );
	}

	public static function error( string $message ): void {
		self::log( 'ERROR', $message );
	}

	private static function log( string $level, string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( gmdate( 'Y-m-d H:i:s' ) . ' - ' . self::NAME . " - {$level} - {$message}" );
		}
	}
}

final class PPW_Product_Spec {
	public function __construct(
		public readonly string $title,
		public readonly string $image_url,
		public readonly float $price_rmb,
		public readonly string $detail_url = '',
		public readonly string $item_id = '',
	) {
		if ( trim( $title ) === '' ) {
			throw new InvalidArgumentException( 'title must not be empty' );
		}
		if ( $price_rmb <= 0.0 ) {
			throw new InvalidArgumentException( 'price_rmb must be greater than 0' );
		}
	}

	/** @return array<string, mixed> */
	public function to_array(): array {
		return array(
			'title'      => $this->title,
			'image_url'  => $this->image_url,
			'price_rmb'  => $this->price_rmb,
			'detail_url' => $this->detail_url,
			'item_id'    => $this->item_id,
		);
	}
}

final class PPW_Order_Invoice {
	public function __construct(
		public readonly string $order_id,
		public readonly string $source_url,
		public readonly string $title,
		public readonly string $image_url,
		public readonly float $price_rmb,
		public readonly float $price_cad,
		public readonly float $exchange_rate,
		public readonly float $margin_percentage,
		public readonly string $status = 'PENDING_FIRST_PAYMENT',
	) {}

	/** @return array<string, mixed> */
	public function to_array(): array {
		return array(
			'order_id'           => $this->order_id,
			'source_url'         => $this->source_url,
			'title'              => $this->title,
			'image_url'          => $this->image_url,
			'price_rmb'          => $this->price_rmb,
			'price_cad'          => $this->price_cad,
			'exchange_rate'      => $this->exchange_rate,
			'margin_percentage'  => $this->margin_percentage,
			'status'             => $this->status,
		);
	}

	public function to_json( int $flags = JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ): string {
		return wp_json_encode( $this->to_array(), $flags ) ?: '{}';
	}
}

final class PPW_Link_Parser_Agent {
	/** RMB lower bound for JD search (0 = no lower limit). */
	public const SEARCH_START_PRICE = '0';

	/** RMB upper bound for JD search (0 = no upper limit). */
	public const SEARCH_END_PRICE = '0';

	private string $name;
	private string $platform = PPW_Platform::TAOBAO;
	private string $api_key = '';
	private string $api_secret = '';
	private string $api_url = '';

	public function __construct(
		string $name = 'LinkParserAgent',
		?string $api_key = null,
		?string $api_secret = null,
		?string $api_url = null,
		string $platform = PPW_Platform::TAOBAO,
	) {
		$this->name     = $name;
		$this->platform = PPW_Platform::normalize( $platform );
		$creds          = PPW_Platform::search_credentials( $this->platform );

		$this->api_key    = $api_key ?? $creds['key'];
		$this->api_secret = $api_secret ?? $creds['secret'];
		$this->api_url    = $api_url ?? $creds['url'];
	}

	public function clean_url( string $raw_url ): string {
		return trim( $raw_url );
	}

	/**
	 * Set marketplace platform and reload credentials.
	 */
	public function set_platform( string $platform ): void {
		$this->platform   = PPW_Platform::normalize( $platform );
		$creds            = PPW_Platform::search_credentials( $this->platform );
		$this->api_key    = $creds['key'];
		$this->api_secret = $creds['secret'];
		$this->api_url    = $creds['url'];
	}

	/**
	 * Search products by keyword.
	 *
	 * @param string $keyword   Search keyword.
	 * @param int    $page_size Page size (max 20).
	 * @param int    $page      Page number (1-based).
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function search_products( string $keyword, int $page_size = 20, int $page = 1 ) {
		if ( empty( $this->api_key ) || empty( $this->api_secret ) ) {
			return new WP_Error(
				'ppw_missing_credentials',
				sprintf(
					/* translators: %s: platform label */
					__( '请先在后台「设置 → 商品搜索设置」中配置 %s API Key 和 Secret。', 'product-purchase-workflow' ),
					PPW_Platform::label( $this->platform )
				)
			);
		}

		$page_size = min( max( 1, $page_size ), 20 );
		$page      = max( 1, $page );
		PPW_Logger::info( "[{$this->name}] 搜索 {$this->platform}，关键词: {$keyword}，页码: {$page}，数量: {$page_size}" );

		$params = array(
			'key'           => $this->api_key,
			'secret'        => $this->api_secret,
			'q'             => $keyword,
			// OneBound JD often 5000s if start/end are non-zero; keep both "0".
			'start_price'   => (string) self::SEARCH_START_PRICE,
			'end_price'     => (string) self::SEARCH_END_PRICE,
			'page'          => (string) $page,
			'cat'           => '0',
			'discount_only' => '',
			'sort'          => '',
			'seller_info'   => 'no',
			'nick'          => '',
			'ppath'         => '',
			'imgid'         => '',
			'filter'        => '',
			'cache'         => 'no',
			'lang'          => 'zh-CN',
			'page_size'     => (string) $page_size,
			'result_type'   => 'json',
		);

		$servers    = PPW_Platform::search_servers( $this->api_url, $this->platform );
		$last_error = null;

		foreach ( $servers as $server_base ) {
			$url = rtrim( $server_base, '/' ) . '/?' . http_build_query( $params );

			try {
				$result = $this->request_and_parse( $url, $page_size );
				if ( is_wp_error( $result ) ) {
					$last_error = $result;
					continue;
				}
				return $result;
			} catch ( Throwable $e ) {
				$last_error = new WP_Error( 'ppw_api_error', $e->getMessage() );
			}
		}

		if ( self::is_demo_mode_enabled() ) {
			PPW_Logger::warning( "[{$this->name}] API 不可用，使用演示商品数据。" );
			return self::get_demo_products( $keyword, $page_size, $this->platform );
		}

		return $last_error ?? new WP_Error(
			'ppw_api_unreachable',
			sprintf(
				/* translators: %s: platform label */
				__( '无法连接%s搜索 API，请检查网络或 API 配置。', 'product-purchase-workflow' ),
				PPW_Platform::label( $this->platform )
			)
		);
	}

	/**
	 * Search products by image (OneBound item_search_img).
	 *
	 * Local images are uploaded via upload_img first. Taobao uses numeric imgid or URL (+ app_ver=2).
	 * JD local images fall back to Taobao visual → keyword search when needed.
	 *
	 * @param string $image_source Public image URL, data-URI, raw base64, or file:/path.
	 * @param int    $page_size    Page size (max 20).
	 * @param int    $page         Page number (1-based).
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function search_products_by_image( string $image_source, int $page_size = 20, int $page = 1 ) {
		if ( empty( $this->api_key ) || empty( $this->api_secret ) ) {
			return new WP_Error(
				'ppw_missing_credentials',
				sprintf(
					/* translators: %s: platform label */
					__( '请先在后台「设置 → 商品搜索设置」中配置 %s API Key 和 Secret。', 'product-purchase-workflow' ),
					PPW_Platform::label( $this->platform )
				)
			);
		}

		$image_source = trim( $image_source );
		if ( '' === $image_source ) {
			return new WP_Error( 'ppw_missing_image', __( '请上传或粘贴一张商品图片。', 'product-purchase-workflow' ) );
		}

		$page_size = min( max( 1, $page_size ), 20 );
		$page      = max( 1, $page );

		$imgid = $this->resolve_imgid_for_search( $image_source );
		if ( is_wp_error( $imgid ) ) {
			self::remember_img_search(
				array(
					'ok'       => false,
					'phase'    => 'resolve_imgid',
					'platform' => $this->platform,
					'error'    => $imgid->get_error_message(),
					'time'     => gmdate( 'c' ),
				)
			);
			return $imgid;
		}

		// JD native item_search_img only accepts 360buyimg/jfs URLs. Anything else (local upload,
		// Taobao-style name, temp URL) must use Taobao visual → JD keyword bridge.
		if ( PPW_Platform::JD === $this->platform
			&& ( 'jd_needs_bridge' === $imgid || ! self::is_jd_compatible_imgid( (string) $imgid ) ) ) {
			return $this->search_jd_via_taobao_visual( $image_source, $page_size, $page );
		}

		PPW_Logger::info( "[{$this->name}] 以图搜图 {$this->platform}，imgid=" . self::mask_imgid( $imgid ) . "，页码: {$page}" );

		$attempts = $this->build_img_search_attempts( $imgid, $page, $page_size );
		$servers  = array_slice( PPW_Platform::img_search_servers( $this->platform ), 0, 2 );
		$last_error = null;
		$tried      = array();

		foreach ( $attempts as $attempt ) {
			foreach ( $servers as $server_base ) {
				$method = (string) ( $attempt['method'] ?? 'get' );
				$params = is_array( $attempt['params'] ?? null ) ? $attempt['params'] : array();
				$body   = is_array( $attempt['body'] ?? null ) ? $attempt['body'] : array();

				$tried[] = array(
					'endpoint' => $server_base,
					'mode'     => $attempt['mode'] ?? '',
					'method'   => $method,
					'imgid'    => self::mask_imgid( (string) ( $body['imgid'] ?? $params['imgid'] ?? '' ) ),
				);

				try {
					if ( 'post' === $method ) {
						$query = $params;
						unset( $query['imgid'] );
						$url    = rtrim( $server_base, '/' ) . '/?' . http_build_query( $query );
						$result = $this->request_and_parse_post( $url, $body, $page_size );
					} else {
						$url    = rtrim( $server_base, '/' ) . '/?' . http_build_query( $params );
						$result = $this->request_and_parse( $url, $page_size );
					}

					if ( is_wp_error( $result ) ) {
						$last_error = $result;
						$data       = $result->get_error_data();
						$code       = is_array( $data ) ? (string) ( $data['onebound_code'] ?? '' ) : '';
						// Keep trying alternate modes for data errors; stop on auth.
						if ( '4005' === $code ) {
							self::remember_img_search(
								array(
									'ok'       => false,
									'phase'    => 'item_search_img',
									'platform' => $this->platform,
									'tried'    => $tried,
									'error'    => $result->get_error_message(),
									'time'     => gmdate( 'c' ),
								)
							);
							return $result;
						}
						continue;
					}

					self::remember_img_search(
						array(
							'ok'       => true,
							'phase'    => 'item_search_img',
							'platform' => $this->platform,
							'mode'     => $attempt['mode'] ?? '',
							'method'   => $method,
							'endpoint' => $server_base,
							'count'    => count( $result ),
							'time'     => gmdate( 'c' ),
						)
					);
					return $result;
				} catch ( Throwable $e ) {
					$last_error = new WP_Error( 'ppw_api_error', $e->getMessage() );
				}
			}
		}

		self::remember_img_search(
			array(
				'ok'       => false,
				'phase'    => 'item_search_img',
				'platform' => $this->platform,
				'tried'    => $tried,
				'error'    => $last_error instanceof WP_Error ? $last_error->get_error_message() : 'unknown',
				'time'     => gmdate( 'c' ),
			)
		);

		// OneBound jd.item_search_img currently requires a JD CDN / jfs URL; local BASE64 often
		// returns 5000. Fall back: Taobao visual search → keyword → JD item_search.
		if ( PPW_Platform::JD === $this->platform ) {
			$bridged = $this->search_jd_via_taobao_visual( $image_source, $page_size, $page, $last_error );
			if ( ! is_wp_error( $bridged ) ) {
				return $bridged;
			}
			if ( $bridged instanceof WP_Error ) {
				$last_error = $bridged;
			}
		}

		if ( self::is_demo_mode_enabled() ) {
			PPW_Logger::warning( "[{$this->name}] 以图搜图 API 不可用，使用演示商品数据。" );
			return self::get_demo_products( __( '以图搜图', 'product-purchase-workflow' ), $page_size, $this->platform );
		}

		return $last_error ?? new WP_Error(
			'ppw_img_search_unreachable',
			sprintf(
				/* translators: %s: platform label */
				__( '无法连接%s以图搜图 API，请检查网络或 API 配置。', 'product-purchase-workflow' ),
				PPW_Platform::label( $this->platform )
			)
		);
	}

	/**
	 * JD local-image fallback: Taobao item_search_img → derive keyword → JD item_search.
	 *
	 * @param WP_Error|null $prior Prior JD API error (for diagnostics).
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	private function search_jd_via_taobao_visual( string $image_source, int $page_size, int $page, $prior = null ) {
		$tb = new self( $this->name . '/tb-bridge', null, null, null, PPW_Platform::TAOBAO );
		$tb_products = $tb->search_products_by_image( $image_source, min( 8, max( 3, $page_size ) ), 1 );

		if ( is_wp_error( $tb_products ) ) {
			$tb_msg = self::sanitize_api_error_message( $tb_products->get_error_message() );
			self::remember_img_search(
				array(
					'ok'       => false,
					'phase'    => 'jd_via_taobao',
					'platform' => PPW_Platform::JD,
					'mode'     => 'jd_via_taobao_title',
					'error'    => $tb_msg,
					'prior'    => $prior instanceof WP_Error ? $prior->get_error_message() : null,
					'time'     => gmdate( 'c' ),
				)
			);
			return new WP_Error(
				'ppw_jd_img_bridge_failed',
				sprintf(
					/* translators: %s: underlying Taobao visual-search error */
					__( '京东本地图需先淘宝识图再搜京东，但淘宝识图失败：%s。请换一张清晰 JPG/PNG，或改用淘宝以图搜图 / 京东关键词搜索。', 'product-purchase-workflow' ),
					$tb_msg
				),
				array( 'onebound_code' => '5000' )
			);
		}

		$keyword = self::title_to_search_keyword( $tb_products );
		if ( '' === $keyword ) {
			return new WP_Error(
				'ppw_jd_img_bridge_nokeyword',
				__( '已识别到相似商品，但无法生成京东搜索关键词，请改用关键词搜索。', 'product-purchase-workflow' )
			);
		}

		PPW_Logger::info( "[{$this->name}] 京东本地图回退：淘宝识图 → 关键词「{$keyword}」" );

		$jd_products = $this->search_products( $keyword, $page_size, $page );
		if ( is_wp_error( $jd_products ) ) {
			$jd_msg = self::sanitize_api_error_message( $jd_products->get_error_message() );
			self::remember_img_search(
				array(
					'ok'             => false,
					'phase'          => 'jd_via_taobao',
					'platform'       => PPW_Platform::JD,
					'mode'           => 'jd_via_taobao_title',
					'bridge_keyword' => $keyword,
					'error'          => $jd_msg,
					'time'           => gmdate( 'c' ),
				)
			);
			return new WP_Error(
				$jd_products->get_error_code(),
				sprintf(
					/* translators: 1: keyword derived from Taobao visual search, 2: JD search error */
					__( '已识图并生成京东关键词「%1$s」，但京东关键词搜索失败：%2$s', 'product-purchase-workflow' ),
					$keyword,
					$jd_msg
				),
				$jd_products->get_error_data()
			);
		}

		self::remember_img_search(
			array(
				'ok'             => true,
				'phase'          => 'jd_via_taobao',
				'platform'       => PPW_Platform::JD,
				'mode'           => 'jd_via_taobao_title',
				'bridge_keyword' => $keyword,
				'count'          => count( $jd_products ),
				'time'           => gmdate( 'c' ),
			)
		);

		return $jd_products;
	}

	/**
	 * Build a short JD keyword from Taobao visual-search titles.
	 *
	 * @param array<int, array<string, mixed>> $products Taobao products.
	 */
	private static function title_to_search_keyword( array $products ): string {
		foreach ( $products as $product ) {
			if ( ! is_array( $product ) ) {
				continue;
			}
			$title = trim( wp_strip_all_tags( (string) ( $product['title'] ?? '' ) ) );
			if ( '' === $title ) {
				continue;
			}
			$title = preg_replace( '/【[^】]*】/u', '', $title ) ?? $title;
			$title = preg_replace( '/\([^)]*\)/u', '', $title ) ?? $title;
			$title = preg_replace( '/（[^）]*）/u', '', $title ) ?? $title;
			$title = preg_replace( '/\s+/u', ' ', $title ) ?? $title;
			$title = trim( $title );
			if ( function_exists( 'mb_substr' ) ) {
				$title = mb_substr( $title, 0, 40 );
			} else {
				$title = substr( $title, 0, 80 );
			}
			$title = trim( $title, " \t\n\r\0\x0B-_/\\|," );
			if ( strlen( $title ) >= 4 ) {
				return $title;
			}
		}
		return '';
	}

	/**
	 * Build item_search_img parameter variants.
	 *
	 * JD expects a JD-format image URL / jfs path, or BASE64 via POST.
	 * Taobao expects numeric imgid or URL (+ app_ver=2).
	 *
	 * @return array<int, array{mode:string,method?:string,params:array<string,string>,body?:array<string,string>}>
	 */
	private function build_img_search_attempts( string $imgid, int $page, int $page_size ): array {
		$auth = array(
			'key'         => $this->api_key,
			'secret'      => $this->api_secret,
			'page'        => (string) $page,
			'cache'       => 'no',
			'lang'        => 'cn',
			'result_type' => 'json',
		);

		$attempts  = array();
		$is_obtemp = str_starts_with( $imgid, 'obtemp:' );
		$ob_url    = $is_obtemp ? substr( $imgid, strlen( 'obtemp:' ) ) : '';
		$is_b64    = str_starts_with( $imgid, 'b64payload:' );
		$b64       = $is_b64 ? substr( $imgid, strlen( 'b64payload:' ) ) : '';
		$is_url    = (bool) preg_match( '#^https?://#i', $imgid );
		$is_num    = (bool) preg_match( '#^\d{6,}$#', $imgid );
		$is_jfs    = (bool) preg_match( '#(^|/)jfs/#i', $imgid );
		$is_cdn    = $is_url && self::is_marketplace_image_url( $imgid );

		if ( PPW_Platform::TAOBAO === $this->platform ) {
			if ( $is_b64 && '' !== $b64 ) {
				// Local phone photos often only get OneBound temp upload URLs (GET → 5000).
				// Official item_search_img also accepts POST imgid=BASE64.
				$attempts[] = array(
					'mode'   => 'taobao_post_base64',
					'method' => 'post',
					'params' => $auth,
					'body'   => array( 'imgid' => $b64 ),
				);
				$attempts[] = array(
					'mode'   => 'taobao_post_base64_app_ver_2',
					'method' => 'post',
					'params' => array_merge( $auth, array( 'app_ver' => '2' ) ),
					'body'   => array( 'imgid' => $b64 ),
				);
				$attempts[] = array(
					'mode'   => 'taobao_post_data_uri',
					'method' => 'post',
					'params' => array_merge( $auth, array( 'app_ver' => '2' ) ),
					'body'   => array( 'imgid' => 'data:image/jpeg;base64,' . $b64 ),
				);
			} elseif ( $is_obtemp && '' !== $ob_url ) {
				// Temp upload host: POST first (GET historically returns 5000).
				$attempts[] = array(
					'mode'   => 'taobao_obtemp_post',
					'method' => 'post',
					'params' => $auth,
					'body'   => array( 'imgid' => $ob_url ),
				);
				$attempts[] = array(
					'mode'   => 'taobao_obtemp_post_app_ver_2',
					'method' => 'post',
					'params' => array_merge( $auth, array( 'app_ver' => '2' ) ),
					'body'   => array( 'imgid' => $ob_url ),
				);
			} else {
				$base = array_merge( $auth, array( 'imgid' => $imgid ) );
				if ( $is_num ) {
					$attempts[] = array(
						'mode'   => 'taobao_imgid',
						'method' => 'get',
						'params' => $base,
					);
				}
				if ( $is_cdn ) {
					$attempts[] = array(
						'mode'   => 'taobao_url_app_ver_2',
						'method' => 'get',
						'params' => array_merge( $base, array( 'app_ver' => '2' ) ),
					);
					$attempts[] = array(
						'mode'   => 'taobao_url_raw',
						'method' => 'get',
						'params' => $base,
					);
				}
				if ( ! $is_num && ! $is_url ) {
					$attempts[] = array(
						'mode'   => 'taobao_imgid_raw',
						'method' => 'get',
						'params' => $base,
					);
					$attempts[] = array(
						'mode'   => 'taobao_force_app_ver_2',
						'method' => 'get',
						'params' => array_merge( $base, array( 'app_ver' => '2' ) ),
					);
				}
			}
		} else {
			// JD: prefer JD URL / jfs GET; local uploads use POST base64 (docs).
			if ( $is_b64 && '' !== $b64 ) {
				$attempts[] = array(
					'mode'   => 'jd_post_base64',
					'method' => 'post',
					'params' => $auth,
					'body'   => array( 'imgid' => $b64 ),
				);
				$attempts[] = array(
					'mode'   => 'jd_post_data_uri',
					'method' => 'post',
					'params' => $auth,
					'body'   => array( 'imgid' => 'data:image/jpeg;base64,' . $b64 ),
				);
			} elseif ( self::is_jd_compatible_imgid( $imgid ) ) {
				$attempts[] = array(
					'mode'   => 'jd_imgid_get',
					'method' => 'get',
					'params' => array_merge( $auth, array( 'imgid' => $imgid ) ),
				);
				if ( $is_url || $is_jfs ) {
					$attempts[] = array(
						'mode'   => 'jd_imgid_post',
						'method' => 'post',
						'params' => $auth,
						'body'   => array( 'imgid' => $imgid ),
					);
				}
			} else {
				// Non-JD id/name from upload_img — try GET then POST, but expect base64 path above.
				$attempts[] = array(
					'mode'   => 'jd_imgid_raw_get',
					'method' => 'get',
					'params' => array_merge( $auth, array( 'imgid' => $imgid ) ),
				);
				$attempts[] = array(
					'mode'   => 'jd_imgid_raw_post',
					'method' => 'post',
					'params' => $auth,
					'body'   => array( 'imgid' => $imgid ),
				);
			}
		}

		// De-dupe identical request fingerprints.
		$unique = array();
		$seen   = array();
		foreach ( $attempts as $attempt ) {
			$key = md5(
				wp_json_encode(
					array(
						$attempt['method'] ?? 'get',
						$attempt['params'] ?? array(),
						$attempt['body'] ?? array(),
					)
				)
			);
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$unique[]     = $attempt;
		}

		return $unique;
	}

	/**
	 * Persist last image-search diagnostic (no secrets).
	 *
	 * @param array<string, mixed> $info Info.
	 */
	public static function remember_img_search( array $info ): void {
		update_option( 'ppw_last_img_search', $info, false );
		PPW_Logger::info( '[img_search] ' . wp_json_encode( $info ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function get_last_img_search(): array {
		$last = get_option( 'ppw_last_img_search', array() );
		return is_array( $last ) ? $last : array();
	}

	/**
	 * Mask imgid for logs.
	 */
	private static function mask_imgid( string $imgid ): string {
		$imgid = trim( $imgid );
		if ( 'jd_needs_bridge' === $imgid ) {
			return 'jd_needs_bridge';
		}
		if ( str_starts_with( $imgid, 'obtemp:' ) ) {
			return 'obtemp:' . self::mask_imgid( substr( $imgid, strlen( 'obtemp:' ) ) );
		}
		if ( str_starts_with( $imgid, 'b64payload:' ) ) {
			return 'b64payload:' . strlen( $imgid ) . 'b';
		}
		if ( strlen( $imgid ) <= 12 ) {
			return $imgid;
		}
		if ( preg_match( '#^https?://#i', $imgid ) ) {
			return substr( $imgid, 0, 48 ) . '…';
		}
		if ( self::looks_like_base64_blob( $imgid ) ) {
			return 'base64:' . strlen( $imgid ) . 'b';
		}
		return substr( $imgid, 0, 6 ) . '…' . substr( $imgid, -4 );
	}

	/**
	 * Whether value is a OneBound temporary image host (IP:port /image/…).
	 * upload_img often returns these; item_search_img GET with them returns 5000.
	 */
	private static function is_onebound_temp_image_url( string $value ): bool {
		$value = trim( $value );
		if ( ! preg_match( '#^https?://#i', $value ) ) {
			return false;
		}
		$host = strtolower( (string) wp_parse_url( $value, PHP_URL_HOST ) );
		$port = wp_parse_url( $value, PHP_URL_PORT );
		$path = (string) wp_parse_url( $value, PHP_URL_PATH );
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return true;
		}
		if ( null !== $port && ! in_array( (int) $port, array( 80, 443 ), true ) ) {
			return true;
		}
		return (bool) preg_match( '#/image/\d{4}-\d{2}-\d{2}/#', $path );
	}

	/**
	 * Whether value is a marketplace CDN URL usable as item_search_img imgid.
	 */
	private static function is_marketplace_image_url( string $value ): bool {
		$value = trim( $value );
		if ( ! preg_match( '#^https?://#i', $value ) ) {
			return false;
		}
		if ( self::is_onebound_temp_image_url( $value ) ) {
			return false;
		}

		$host = strtolower( (string) wp_parse_url( $value, PHP_URL_HOST ) );
		$port = wp_parse_url( $value, PHP_URL_PORT );
		if ( '' === $host ) {
			return false;
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		if ( null !== $port && ! in_array( (int) $port, array( 80, 443 ), true ) ) {
			return false;
		}

		return (bool) preg_match(
			'#(?:^|\.)(?:alicdn|taobao|tmall|tbcdn|360buyimg|jd)\.(?:com|cn|hk)$#i',
			$host
		);
	}

	/**
	 * Whether extracted upload_img value can be passed to item_search_img as GET imgid.
	 */
	private static function is_usable_search_imgid( string $imgid, string $platform = '' ): bool {
		$imgid    = trim( $imgid );
		$platform = PPW_Platform::normalize( $platform );
		if ( '' === $imgid || self::looks_like_base64_blob( $imgid ) ) {
			return false;
		}
		if ( self::is_onebound_temp_image_url( $imgid ) ) {
			return false;
		}
		if ( preg_match( '#^\d{6,}$#', $imgid ) ) {
			return true;
		}
		if ( self::is_marketplace_image_url( $imgid ) ) {
			if ( PPW_Platform::JD === $platform ) {
				return self::is_jd_compatible_imgid( $imgid );
			}
			return true;
		}
		// Taobao-style image name (TB… / O1CN…) — not an http URL.
		if ( ! preg_match( '#^https?://#i', $imgid ) && preg_match( '#^[A-Za-z0-9_\-!.]{8,}$#', $imgid ) ) {
			return PPW_Platform::JD !== $platform;
		}
		return false;
	}

	/**
	 * Whether value is usable as jd.item_search_img imgid (JD CDN / jfs path).
	 */
	private static function is_jd_compatible_imgid( string $value ): bool {
		$value = trim( $value );
		if ( '' === $value ) {
			return false;
		}
		if ( preg_match( '#(^|/)jfs/#i', $value ) ) {
			return true;
		}
		$host = strtolower( (string) wp_parse_url( $value, PHP_URL_HOST ) );
		return (bool) preg_match( '#(?:360buyimg\.com|jd\.com|jd\.hk)$#i', $host )
			&& self::is_marketplace_image_url( $value );
	}

	/**
	 * Encode local/data-URI/remote image to raw base64 (no data: prefix).
	 *
	 * @return string Empty string on failure.
	 */
	private static function encode_image_source_as_base64( string $source ): string {
		$source = trim( $source );
		$cleanup = array();

		if ( preg_match( '#^https?://#i', $source ) ) {
			$response = wp_remote_get(
				$source,
				array(
					'timeout'   => 30,
					'sslverify' => true,
					'headers'   => array(
						'User-Agent' => 'MyEasyBuy-ProductPurchaseWorkflow/1.5',
					),
				)
			);
			if ( is_wp_error( $response ) ) {
				return '';
			}
			$bytes = (string) wp_remote_retrieve_body( $response );
			if ( '' === $bytes || (int) wp_remote_retrieve_response_code( $response ) >= 400 ) {
				return '';
			}
			$tmp = wp_tempnam( 'ppw-jd-dl-' );
			if ( ! $tmp ) {
				return '';
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			if ( false === file_put_contents( $tmp, $bytes ) ) {
				wp_delete_file( $tmp );
				return '';
			}
			$source    = 'file:' . $tmp;
			$cleanup[] = $tmp;
		}

		$prepared = self::prepare_local_image_file( $source );
		if ( is_wp_error( $prepared ) || 'file' !== ( $prepared['mode'] ?? '' ) || empty( $prepared['path'] ) ) {
			foreach ( $cleanup as $path ) {
				if ( is_file( $path ) ) {
					wp_delete_file( $path );
				}
			}
			return '';
		}

		$path = (string) $prepared['path'];
		if ( ! in_array( $path, $cleanup, true ) ) {
			$cleanup[] = $path;
		}

		$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		foreach ( $cleanup as $cpath ) {
			if ( is_file( $cpath ) ) {
				wp_delete_file( $cpath );
			}
		}

		if ( ! is_string( $bytes ) || '' === $bytes ) {
			return '';
		}

		return base64_encode( $bytes );
	}

	/**
	 * Resolve OneBound imgid for item_search_img.
	 *
	 * @param string $image_source URL / data-URI / base64 / file:/path.
	 * @return string|WP_Error
	 */
	private function resolve_imgid_for_search( string $image_source ) {
		$source = trim( $image_source );
		$is_local = str_starts_with( $source, 'file:' ) ||
			preg_match( '#^data:image/[^;]+;base64,#i', $source ) ||
			( ! preg_match( '#^https?://#i', $source ) && preg_match( '#^[A-Za-z0-9+/=\s]+$#', $source ) && strlen( $source ) > 200 );

		// Local upload / data-URI / raw base64.
		if ( $is_local ) {
			if ( PPW_Platform::JD === $this->platform ) {
				// OneBound jd.item_search_img rejects local uploads / Taobao-style names (5000).
				// Always bridge via Taobao visual → JD item_search keyword.
				return 'jd_needs_bridge';
			}

			$uploaded = $this->upload_image_for_search( $source );
			if ( ! is_wp_error( $uploaded ) && self::is_usable_search_imgid( $uploaded, PPW_Platform::TAOBAO ) ) {
				return $uploaded;
			}
			// Phone photos often only get OneBound temp URLs (IP:5027) — GET item_search_img → 5000.
			// Prefer BASE64 POST; keep temp URL as last-resort POST attempt.
			$b64 = self::encode_image_source_as_base64( $source );
			if ( '' !== $b64 ) {
				return 'b64payload:' . $b64;
			}
			if ( ! is_wp_error( $uploaded ) && self::is_onebound_temp_image_url( $uploaded ) ) {
				return 'obtemp:' . $uploaded;
			}
			if ( is_wp_error( $uploaded ) ) {
				return new WP_Error(
					$uploaded->get_error_code(),
					self::sanitize_api_error_message( $uploaded->get_error_message() ),
					$uploaded->get_error_data()
				);
			}
			return new WP_Error(
				'ppw_upload_no_imgid',
				__( '图片已上传，但未返回可用于搜图的 ID。请换一张较小的 JPG/PNG 重试。', 'product-purchase-workflow' )
			);
		}

		if ( preg_match( '#^https?://#i', $source ) ) {
			$host = strtolower( (string) wp_parse_url( $source, PHP_URL_HOST ) );

			$is_tb_cdn = (bool) preg_match( '#(?:alicdn\.com|taobao\.com|tmall\.com)$#i', $host );
			$is_jd_cdn = (bool) preg_match( '#(?:360buyimg\.com|jd\.com|jd\.hk)$#i', $host );

			// Prefer upload→usable imgid for Taobao. Fall back to CDN URL or BASE64 POST.
			if ( PPW_Platform::TAOBAO === $this->platform ) {
				$uploaded = $this->upload_image_for_search( $source );
				if ( ! is_wp_error( $uploaded ) && self::is_usable_search_imgid( $uploaded, PPW_Platform::TAOBAO ) ) {
					return $uploaded;
				}
				if ( $is_tb_cdn || self::is_marketplace_image_url( $source ) ) {
					return $source;
				}
				$b64 = self::encode_image_source_as_base64( $source );
				if ( '' !== $b64 ) {
					return 'b64payload:' . $b64;
				}
				return is_wp_error( $uploaded )
					? new WP_Error(
						$uploaded->get_error_code(),
						self::sanitize_api_error_message( $uploaded->get_error_message() ),
						$uploaded->get_error_data()
					)
					: new WP_Error( 'ppw_upload_failed', __( '图片上传失败，请换一张较小的 JPG/PNG 商品图重试。', 'product-purchase-workflow' ) );
			}

			if ( PPW_Platform::JD === $this->platform ) {
				if ( self::is_jd_compatible_imgid( $source ) || $is_jd_cdn ) {
					return $source;
				}
				// Non-JD URL: use Taobao visual → JD keyword bridge.
				return 'jd_needs_bridge';
			}
		}

		return new WP_Error( 'ppw_invalid_image', __( '无法识别的图片数据，请重新上传。', 'product-purchase-workflow' ) );
	}

	/**
	 * Upload via a specific marketplace API (e.g. always use taobao.upload_img).
	 *
	 * @param string $imgcode  Image source.
	 * @param string $platform taobao|jd.
	 * @return string|WP_Error
	 */
	private function upload_image_via_platform( string $imgcode, string $platform ) {
		$platform = PPW_Platform::normalize( $platform );
		$agent    = new self( $this->name . '/upload-' . $platform, null, null, null, $platform );
		return $agent->upload_image_for_search( $imgcode );
	}

	/**
	 * Upload image via OneBound upload_img and return imgid / URL for search.
	 *
	 * Prefers multipart binary upload (avoids base64 "+" corruption in form encoding).
	 * Also tries official-style POST imgcode=base64 (see OneBound PHP sample params).
	 *
	 * @param string $imgcode Image URL, data-URI/base64, or "file:/path".
	 * @return string|WP_Error
	 */
	private function upload_image_for_search( string $imgcode ) {
		$servers     = array_slice( PPW_Platform::upload_img_servers( $this->platform ), 0, 2 );
		$last_error  = null;
		$cleanup     = null;
		$temp_imgid  = ''; // OneBound temp URL — not usable as GET imgid, but may help diagnostics.

		$prepared = self::prepare_local_image_file( $imgcode );
		if ( is_wp_error( $prepared ) ) {
			// Remote URL — send as imgcode URL string.
			if ( preg_match( '#^https?://#i', trim( $imgcode ) ) ) {
				$prepared = array(
					'mode' => 'url',
					'url'  => trim( $imgcode ),
				);
			} else {
				return $prepared;
			}
		} else {
			$cleanup = $prepared['path'] ?? null;
		}

		$query = array(
			'key'         => $this->api_key,
			'secret'      => $this->api_secret,
			'result_type' => 'json',
			'lang'        => 'cn',
			'cache'       => 'no',
		);

		$type_variants = array( '1', '' );

		try {
			foreach ( $type_variants as $img_type ) {
				foreach ( $servers as $server_base ) {
					$url = rtrim( $server_base, '/' ) . '/?' . http_build_query( $query );

					$transports = array();
					if ( 'file' === ( $prepared['mode'] ?? '' ) ) {
						$transports[] = array(
							'mode' => 'multipart',
							'run'  => static function () use ( $url, $prepared, $img_type ) {
								return self::post_multipart_upload(
									$url,
									(string) $prepared['path'],
									(string) ( $prepared['filename'] ?? 'search.jpg' ),
									(string) ( $prepared['mime'] ?? 'image/jpeg' ),
									$img_type
								);
							},
						);
						// Official PHP sample param is imgcode (base64 POST) + optional img_type.
						$transports[] = array(
							'mode' => 'imgcode_base64',
							'run'  => static function () use ( $url, $prepared, $img_type ) {
								$bytes = file_get_contents( (string) $prepared['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
								if ( false === $bytes || '' === $bytes ) {
									return new WP_Error( 'ppw_upload_read', __( '无法读取上传的图片。', 'product-purchase-workflow' ) );
								}
								$body = array( 'imgcode' => base64_encode( $bytes ) );
								if ( '' !== $img_type ) {
									$body['img_type'] = $img_type;
								}
								return self::post_form_urlencoded( $url, $body );
							},
						);
					} else {
						$transports[] = array(
							'mode' => 'imgcode_url',
							'run'  => static function () use ( $url, $prepared, $imgcode, $img_type ) {
								$body = array(
									'imgcode' => (string) ( $prepared['url'] ?? $imgcode ),
								);
								if ( '' !== $img_type ) {
									$body['img_type'] = $img_type;
								}
								return self::post_form_urlencoded( $url, $body );
							},
						);
					}

					foreach ( $transports as $transport ) {
						$response = call_user_func( $transport['run'] );
						if ( is_wp_error( $response ) ) {
							$last_error = $response;
							continue;
						}

						$code = (int) wp_remote_retrieve_response_code( $response );
						$raw  = (string) wp_remote_retrieve_body( $response );
						$data = json_decode( $raw, true );

						if ( $code >= 400 || ! is_array( $data ) ) {
							$last_error = new WP_Error(
								'ppw_upload_http_error',
								__( '图片上传接口请求失败。', 'product-purchase-workflow' ),
								array( 'raw' => substr( $raw, 0, 300 ) )
							);
							continue;
						}

						$error = self::parse_api_error( $data );
						if ( is_wp_error( $error ) ) {
							$last_error = $error;
							$data_code  = $error->get_error_data();
							self::remember_img_search(
								array(
									'ok'        => false,
									'phase'     => 'upload_img',
									'platform'  => $this->platform,
									'endpoint'  => $server_base,
									'mode'      => $transport['mode'],
									'img_type'  => $img_type,
									'error'     => self::sanitize_api_error_message( $error->get_error_message() ),
									'raw'       => self::summarize_api_payload( $data ),
									'time'      => gmdate( 'c' ),
								)
							);
							if ( is_array( $data_code ) && isset( $data_code['onebound_code'] ) && '4005' === (string) $data_code['onebound_code'] ) {
								return $error;
							}
							continue;
						}

						$imgid = self::extract_uploaded_imgid( $data, $this->platform );
						if ( '' !== $imgid && self::is_usable_search_imgid( $imgid, $this->platform ) ) {
							self::remember_img_search(
								array(
									'ok'       => true,
									'phase'    => 'upload_img',
									'platform' => $this->platform,
									'endpoint' => $server_base,
									'mode'     => $transport['mode'],
									'img_type' => $img_type,
									'imgid'    => self::mask_imgid( $imgid ),
									'raw'      => self::summarize_api_payload( $data ),
									'time'     => gmdate( 'c' ),
								)
							);
							PPW_Logger::info( "[{$this->name}] upload_img 成功，imgid=" . self::mask_imgid( $imgid ) );
							return $imgid;
						}

						// Keep searching other transports — temp IP:5027 URLs cause item_search_img 5000.
						if ( '' !== $imgid && self::is_onebound_temp_image_url( $imgid ) ) {
							$temp_imgid = $imgid;
							self::remember_img_search(
								array(
									'ok'       => false,
									'phase'    => 'upload_img',
									'platform' => $this->platform,
									'endpoint' => $server_base,
									'mode'     => $transport['mode'],
									'img_type' => $img_type,
									'imgid'    => self::mask_imgid( $imgid ),
									'error'    => 'rejected_onebound_temp_url',
									'raw'      => self::summarize_api_payload( $data ),
									'time'     => gmdate( 'c' ),
								)
							);
							continue;
						}

						$last_error = new WP_Error(
							'ppw_upload_no_imgid',
							__( '图片已上传，但未返回可用于搜图的 ID。', 'product-purchase-workflow' ),
							array( 'raw' => self::summarize_api_payload( $data ) )
						);
					}
				}
			}
		} finally {
			if ( is_string( $cleanup ) && is_file( $cleanup ) ) {
				wp_delete_file( $cleanup );
			}
		}

		// All transports only returned OneBound temp URLs — caller may POST them or use BASE64.
		if ( '' !== $temp_imgid ) {
			return $temp_imgid;
		}

		if ( $last_error instanceof WP_Error ) {
			return new WP_Error(
				$last_error->get_error_code(),
				self::sanitize_api_error_message( $last_error->get_error_message() ),
				$last_error->get_error_data()
			);
		}

		return new WP_Error(
			'ppw_upload_failed',
			__( '图片上传失败，请换一张较小的 JPG/PNG 商品图重试。', 'product-purchase-workflow' )
		);
	}

	/**
	 * POST application/x-www-form-urlencoded (OneBound sample style for imgcode).
	 *
	 * @param array<string, string> $body Fields.
	 * @return array|WP_Error
	 */
	private static function post_form_urlencoded( string $url, array $body ) {
		$encoded = array();
		foreach ( $body as $k => $v ) {
			$encoded[] = rawurlencode( (string) $k ) . '=' . rawurlencode( (string) $v );
		}

		return wp_remote_post(
			$url,
			array(
				'timeout'   => 90,
				'sslverify' => true,
				'headers'   => array(
					'Accept'          => 'application/json',
					'Accept-Encoding' => 'gzip',
					'Content-Type'    => 'application/x-www-form-urlencoded; charset=UTF-8',
					'User-Agent'      => 'MyEasyBuy-ProductPurchaseWorkflow/1.5',
				),
				'body'      => implode( '&', $encoded ),
			)
		);
	}

	/**
	 * Turn data-URI/base64/file path into a local JPEG temp file for multipart upload.
	 *
	 * @param string $imgcode Source.
	 * @return array{mode:string,path?:string,filename?:string,mime?:string,url?:string}|WP_Error
	 */
	private static function prepare_local_image_file( string $imgcode ) {
		$imgcode = trim( $imgcode );

		if ( str_starts_with( $imgcode, 'file:' ) ) {
			$path = substr( $imgcode, 5 );
			if ( ! is_readable( $path ) ) {
				return new WP_Error( 'ppw_upload_tmp', __( '无效的上传文件。', 'product-purchase-workflow' ) );
			}
			$compressed = self::compress_image_file( $path );
			return is_wp_error( $compressed ) ? array(
				'mode'     => 'file',
				'path'     => $path,
				'filename' => 'search.jpg',
				'mime'     => 'image/jpeg',
			) : $compressed;
		}

		if ( preg_match( '#^https?://#i', $imgcode ) ) {
			return new WP_Error( 'ppw_is_url', 'url' );
		}

		$binary = '';
		if ( preg_match( '#^data:image/[^;]+;base64,(.+)$#is', $imgcode, $m ) ) {
			$binary = base64_decode( preg_replace( '#\s+#', '', $m[1] ) ?: '', true );
		} elseif ( preg_match( '#^[A-Za-z0-9+/=\s]+$#', $imgcode ) && strlen( $imgcode ) > 200 ) {
			$binary = base64_decode( preg_replace( '#\s+#', '', $imgcode ) ?: '', true );
		}

		if ( ! is_string( $binary ) || '' === $binary ) {
			return new WP_Error( 'ppw_invalid_image', __( '无法识别的图片数据，请重新上传。', 'product-purchase-workflow' ) );
		}

		$tmp = wp_tempnam( 'ppw-img-' );
		if ( ! $tmp ) {
			return new WP_Error( 'ppw_upload_tmp', __( '无法创建临时图片文件。', 'product-purchase-workflow' ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $tmp, $binary ) ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'ppw_upload_tmp', __( '无法写入临时图片文件。', 'product-purchase-workflow' ) );
		}

		$compressed = self::compress_image_file( $tmp );
		if ( is_wp_error( $compressed ) ) {
			// Fallback to original bytes as JPEG name.
			return array(
				'mode'     => 'file',
				'path'     => $tmp,
				'filename' => 'search.jpg',
				'mime'     => 'image/jpeg',
			);
		}

		if ( ( $compressed['path'] ?? '' ) !== $tmp && is_file( $tmp ) ) {
			wp_delete_file( $tmp );
		}

		return $compressed;
	}

	/**
	 * Compress local image to JPEG ≤720px / ~300KB when GD is available.
	 *
	 * @param string $path Local path.
	 * @return array{mode:string,path:string,filename:string,mime:string}|WP_Error
	 */
	private static function compress_image_file( string $path ) {
		if ( ! function_exists( 'imagecreatefromstring' ) || ! function_exists( 'imagejpeg' ) ) {
			return new WP_Error( 'ppw_no_gd', 'no gd' );
		}

		$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $bytes || '' === $bytes ) {
			return new WP_Error( 'ppw_upload_read', __( '无法读取上传的图片。', 'product-purchase-workflow' ) );
		}

		$img = @imagecreatefromstring( $bytes );
		if ( false === $img ) {
			return new WP_Error( 'ppw_invalid_image', __( '图片无法解析，请改用 JPG/PNG。', 'product-purchase-workflow' ) );
		}

		$w = imagesx( $img );
		$h = imagesy( $img );
		if ( $w < 1 || $h < 1 ) {
			imagedestroy( $img );
			return new WP_Error( 'ppw_invalid_image', __( '图片无法解析，请改用 JPG/PNG。', 'product-purchase-workflow' ) );
		}

		$max   = 720;
		$scale = min( 1.0, $max / max( $w, $h ) );
		$nw    = max( 1, (int) round( $w * $scale ) );
		$nh    = max( 1, (int) round( $h * $scale ) );

		$dst = imagecreatetruecolor( $nw, $nh );
		if ( false === $dst ) {
			imagedestroy( $img );
			return new WP_Error( 'ppw_no_gd', 'no canvas' );
		}
		imagecopyresampled( $dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h );
		imagedestroy( $img );

		$out = wp_tempnam( 'ppw-jpg-' );
		if ( ! $out ) {
			imagedestroy( $dst );
			return new WP_Error( 'ppw_upload_tmp', __( '无法创建临时图片文件。', 'product-purchase-workflow' ) );
		}

		$quality = 78;
		imagejpeg( $dst, $out, $quality );
		imagedestroy( $dst );

		// Further reduce if still large (phone photos).
		$size = is_file( $out ) ? (int) filesize( $out ) : 0;
		if ( $size > 300000 ) {
			$img2 = @imagecreatefromjpeg( $out );
			if ( false !== $img2 ) {
				imagejpeg( $img2, $out, 62 );
				imagedestroy( $img2 );
			}
		}

		return array(
			'mode'     => 'file',
			'path'     => $out,
			'filename' => 'search.jpg',
			'mime'     => 'image/jpeg',
		);
	}

	/**
	 * Multipart POST to OneBound upload_img.
	 *
	 * @return array|WP_Error wp_remote_post response.
	 */
	private static function post_multipart_upload( string $url, string $path, string $filename, string $mime, string $img_type ) {
		if ( ! is_readable( $path ) ) {
			return new WP_Error( 'ppw_upload_tmp', __( '无效的上传文件。', 'product-purchase-workflow' ) );
		}

		$binary = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $binary ) {
			return new WP_Error( 'ppw_upload_read', __( '无法读取上传的图片。', 'product-purchase-workflow' ) );
		}

		$boundary = 'ppw' . wp_generate_password( 16, false, false );
		$body     = '';

		if ( '' !== $img_type ) {
			$body .= "--{$boundary}\r\n";
			$body .= "Content-Disposition: form-data; name=\"img_type\"\r\n\r\n";
			$body .= $img_type . "\r\n";
		}

		// OneBound accepts either imgcode (base64) or file field; send both binary file and base64-safe imgcode alternatives.
		$body .= "--{$boundary}\r\n";
		$body .= 'Content-Disposition: form-data; name="file"; filename="' . $filename . "\"\r\n";
		$body .= 'Content-Type: ' . $mime . "\r\n\r\n";
		$body .= $binary . "\r\n";

		$body .= "--{$boundary}\r\n";
		$body .= "Content-Disposition: form-data; name=\"imgcode\"; filename=\"{$filename}\"\r\n";
		$body .= 'Content-Type: ' . $mime . "\r\n\r\n";
		$body .= $binary . "\r\n";

		$body .= "--{$boundary}--\r\n";

		return wp_remote_post(
			$url,
			array(
				'timeout'   => 90,
				'sslverify' => true,
				'headers'   => array(
					'Accept'          => 'application/json',
					'Accept-Encoding' => 'gzip',
					'Content-Type'    => 'multipart/form-data; boundary=' . $boundary,
					'User-Agent'      => 'MyEasyBuy-ProductPurchaseWorkflow/1.5',
				),
				'body'      => $body,
			)
		);
	}

	/**
	 * Whether value looks like a raw base64 blob (must never be used as imgid).
	 */
	private static function looks_like_base64_blob( string $value ): bool {
		$value = trim( $value );
		if ( strlen( $value ) < 80 ) {
			return false;
		}
		if ( preg_match( '#^https?://#i', $value ) ) {
			return false;
		}
		if ( preg_match( '#^\d{6,}$#', $value ) ) {
			return false;
		}
		return (bool) preg_match( '#^[A-Za-z0-9+/=\s]+$#', $value );
	}

	/**
	 * Hide huge base64 dumps from OneBound error strings.
	 */
	private static function sanitize_api_error_message( string $message ): string {
		$message = trim( $message );
		if ( preg_match( '#error imgfile:#i', $message ) || false !== strpos( $message, '/9j/' ) || strlen( $message ) > 280 ) {
			if ( preg_match( '#\(code:\s*(\d+)\)#i', $message, $m ) ) {
				$code = $m[1];
				if ( '4003' === $code ) {
					return __( '图片文件不被 OneBound 接受（code: 4003）。请上传清晰的 JPG/PNG 商品图，建议小于 1MB。', 'product-purchase-workflow' );
				}
				return sprintf(
					/* translators: %s: error code */
					__( '图片搜索失败（code: %s）。请换一张较小的 JPG/PNG 商品图重试。', 'product-purchase-workflow' ),
					$code
				);
			}
			return __( '图片搜索失败：图片格式或大小不被接口接受，请换一张较小的 JPG/PNG 商品图。', 'product-purchase-workflow' );
		}
		return $message;
	}

	/**
	 * Extract imgid / URL from upload_img response shapes.
	 *
	 * Official sample:
	 *   items.item.name = "https://img14.360buyimg.com/n0/jfs/..."
	 *   items.item.status = "1"
	 * Taobao item_search_img accepts numeric imgid or https image URL.
	 *
	 * @param array<string, mixed> $data     API JSON.
	 * @param string               $platform taobao|jd.
	 */
	private static function extract_uploaded_imgid( array $data, string $platform = '' ): string {
		$platform   = PPW_Platform::normalize( $platform );
		$candidates = array();

		// Official field order: items.item.name / url first.
		$item = $data['items']['item'] ?? null;
		if ( is_array( $item ) ) {
			if ( isset( $item[0] ) && is_array( $item[0] ) ) {
				$item = $item[0];
			}
			foreach ( array( 'name', 'url', 'pic_url', 'imgid', 'img_id', 'id', 'pic' ) as $key ) {
				if ( ! empty( $item[ $key ] ) && is_scalar( $item[ $key ] ) ) {
					$candidates[] = (string) $item[ $key ];
				}
			}
		}

		$fallback_item = $data['item'] ?? $data['items'] ?? null;
		if ( is_array( $fallback_item ) && $fallback_item !== ( $data['items']['item'] ?? null ) ) {
			if ( isset( $fallback_item[0] ) && is_array( $fallback_item[0] ) ) {
				$fallback_item = $fallback_item[0];
			}
			foreach ( array( 'name', 'url', 'pic_url', 'imgid', 'img_id', 'id' ) as $key ) {
				if ( ! empty( $fallback_item[ $key ] ) && is_scalar( $fallback_item[ $key ] ) ) {
					$candidates[] = (string) $fallback_item[ $key ];
				}
			}
		}

		foreach ( array( 'name', 'url', 'pic_url', 'imgid', 'img_id', 'id' ) as $key ) {
			if ( ! empty( $data[ $key ] ) && is_scalar( $data[ $key ] ) ) {
				$candidates[] = (string) $data[ $key ];
			}
		}

		$numeric = array();
		$jd_ids  = array();
		$urls    = array();
		$temps   = array();
		$other   = array();

		foreach ( $candidates as $value ) {
			$value = trim( $value );
			if ( '' === $value || 'false' === strtolower( $value ) || '1' === $value || '0' === $value ) {
				continue;
			}
			if ( self::looks_like_base64_blob( $value ) ) {
				continue;
			}
			if ( str_starts_with( $value, '//' ) ) {
				$value = 'https:' . $value;
			}
			if ( self::is_jd_compatible_imgid( $value ) ) {
				$jd_ids[] = $value;
			} elseif ( preg_match( '#^\d{6,}$#', $value ) ) {
				$numeric[] = $value;
			} elseif ( self::is_onebound_temp_image_url( $value ) ) {
				$temps[] = $value;
			} elseif ( self::is_marketplace_image_url( $value ) ) {
				$urls[] = $value;
			} elseif ( preg_match( '#^https?://#i', $value ) ) {
				// Unknown https hosts — skip for search (same class of failure as temp URLs).
				continue;
			} else {
				$other[] = $value;
			}
		}

		if ( PPW_Platform::JD === $platform ) {
			if ( ! empty( $jd_ids ) ) {
				return $jd_ids[0];
			}
			// Non-JD https URLs are not usable for jd.item_search_img.
			return '';
		}

		// Taobao: numeric imgid > marketplace CDN > TB-style name.
		// Temp OneBound URLs are returned only when nothing better exists (caller should prefer BASE64).
		if ( ! empty( $numeric ) ) {
			return $numeric[0];
		}
		if ( ! empty( $urls ) ) {
			return $urls[0];
		}
		if ( ! empty( $jd_ids ) ) {
			return $jd_ids[0];
		}
		if ( ! empty( $other ) ) {
			return $other[0];
		}
		if ( ! empty( $temps ) ) {
			return $temps[0];
		}

		return '';
	}

	/**
	 * Compact API payload for diagnostics.
	 *
	 * @param array<string, mixed> $data API JSON.
	 * @return array<string, mixed>
	 */
	private static function summarize_api_payload( array $data ): array {
		$keep = array();
		foreach ( array( 'error', 'error_code', 'reason', 'items', 'item', 'request_id', 'api_type' ) as $key ) {
			if ( isset( $data[ $key ] ) ) {
				$val = $data[ $key ];
				if ( is_string( $val ) && strlen( $val ) > 200 ) {
					$val = substr( $val, 0, 120 ) . '…';
				}
				$keep[ $key ] = $val;
			}
		}
		return $keep;
	}

	/**
	 * Detect OneBound API-level errors.
	 *
	 * @param array<string, mixed> $data Parsed JSON.
	 * @return true|WP_Error
	 */
	private static function parse_api_error( array $data ) {
		if ( ! empty( $data['error_code'] ) && '0000' === (string) $data['error_code'] ) {
			return true;
		}

		if ( ! empty( $data['error'] ) ) {
			$message = self::sanitize_api_error_message( (string) $data['error'] );
			$code    = ! empty( $data['error_code'] ) ? (string) $data['error_code'] : '';
			// If sanitizer already produced a user-facing message with code, keep it.
			if ( '' !== $code && false === strpos( $message, '(code:' ) && false === strpos( $message, 'code:' ) ) {
				$message .= ' (code: ' . $code . ')';
			}
			if ( '4005' === $code || false !== strpos( $message, '无权访问' ) ) {
				$message .= ' ' . __( '请在万邦 OneBound 控制台开通对应接口：淘宝需 taobao.item_search_img + taobao.upload_img；京东需 jd.item_search_img（及 jd.upload_img）。', 'product-purchase-workflow' );
			}
			if ( '5000' === $code ) {
				// Do not assume every JD 5000 is item_search_img — keyword item_search can also 5000.
				$message .= ' ' . __( '常见原因：图片格式需为 JPG/PNG，imgid 无效，或接口暂时无结果。请换图 / 换关键词后重试。', 'product-purchase-workflow' );
			}
			return new WP_Error( 'ppw_onebound_error', $message, array( 'onebound_code' => $code ) );
		}

		if ( isset( $data['success'] ) && ( 0 === (int) $data['success'] || '0' === $data['success'] ) ) {
			$message = self::sanitize_api_error_message(
				(string) ( $data['reason'] ?? __( 'OneBound API 请求失败。', 'product-purchase-workflow' ) )
			);
			return new WP_Error( 'ppw_onebound_error', $message );
		}

		return true;
	}


	/**
	 * Perform HTTP GET request and parse product list.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	private function request_and_parse( string $url, int $page_size ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 90,
				'sslverify' => true,
				'headers'   => array(
					'Accept'          => 'application/json',
					'Accept-Encoding' => 'gzip',
					'User-Agent'      => 'MyEasyBuy-ProductPurchaseWorkflow/1.5',
				),
			)
		);

		return $this->parse_search_response( $response, $page_size );
	}

	/**
	 * Perform HTTP POST (imgid in body) and parse product list — used for JD BASE64 search.
	 *
	 * @param array<string, string> $body Form body.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	private function request_and_parse_post( string $url, array $body, int $page_size ) {
		$encoded = array();
		foreach ( $body as $k => $v ) {
			$encoded[] = rawurlencode( (string) $k ) . '=' . rawurlencode( (string) $v );
		}

		$response = wp_remote_post(
			$url,
			array(
				'timeout'   => 90,
				'sslverify' => true,
				'headers'   => array(
					'Accept'          => 'application/json',
					'Accept-Encoding' => 'gzip',
					'Content-Type'    => 'application/x-www-form-urlencoded; charset=UTF-8',
					'User-Agent'      => 'MyEasyBuy-ProductPurchaseWorkflow/1.5',
				),
				'body'      => implode( '&', $encoded ),
			)
		);

		return $this->parse_search_response( $response, $page_size );
	}

	/**
	 * Shared parse for keyword / image search HTTP responses.
	 *
	 * @param array|WP_Error $response wp_remote_* response.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	private function parse_search_response( $response, int $page_size ) {
		if ( is_wp_error( $response ) ) {
			PPW_Logger::error( "[{$this->name}] 请求失败: " . $response->get_error_message() );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code >= 400 ) {
			return new WP_Error(
				'ppw_api_http_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( '商品搜索接口请求失败，HTTP 状态码：%d', 'product-purchase-workflow' ),
					$code
				)
			);
		}

		$data  = json_decode( $body, true, 512, JSON_THROW_ON_ERROR );
		$error = self::parse_api_error( $data );

		if ( is_wp_error( $error ) ) {
			PPW_Logger::error( "[{$this->name}] API 错误: " . $error->get_error_message() );
			return $error;
		}

		$items = self::extract_items( $data );

		if ( empty( $items ) ) {
			return new WP_Error(
				'ppw_no_items',
				__( '搜索未返回商品，请更换关键词重试。', 'product-purchase-workflow' )
			);
		}

		// OneBound returns a single object when page_size=1.
		if ( isset( $items['title'] ) || isset( $items['skuName'] ) || isset( $items['num_iid'] ) ) {
			$items = array( $items );
		}

		$pricing  = new PPW_Pricing_Agent();
		$products = array();

		// Local price guard: OneBound's start_price/end_price is often ignored for JD.
		// Values are RMB (not CAD). end_price <= 0 means "no upper limit".
		$start_price = (float) self::SEARCH_START_PRICE;
		$end_price   = (float) self::SEARCH_END_PRICE;

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$normalized = $this->normalize_item( $item );
			if ( null === $normalized ) {
				continue;
			}

			$price_rmb = (float) $normalized['price_rmb'];
			if ( $start_price > 0 && $price_rmb < $start_price ) {
				continue;
			}
			if ( $end_price > 0 && $price_rmb > $end_price ) {
				continue;
			}

			$normalized['price_cad'] = $pricing->calculate_cad_price( $price_rmb );
			$products[]              = $normalized;

			if ( count( $products ) >= $page_size ) {
				break;
			}
		}

		if ( empty( $products ) ) {
			if ( $end_price > 0 || $start_price > 0 ) {
				return new WP_Error(
					'ppw_price_filter_empty',
					sprintf(
						/* translators: 1: min RMB, 2: max RMB */
						__( '未找到价格在 ¥%1$s–¥%2$s 之间的商品，请换关键词或放宽价格上限。', 'product-purchase-workflow' ),
						$start_price > 0 ? (string) $start_price : '0',
						$end_price > 0 ? (string) $end_price : '∞'
					)
				);
			}

			return new WP_Error(
				'ppw_normalize_failed',
				__( 'API 返回了商品数据，但字段解析失败。请联系管理员检查 API 响应格式。', 'product-purchase-workflow' )
			);
		}

		PPW_Logger::info( "[{$this->name}] 成功获取 " . count( $products ) . ' 个' . $this->platform . '商品。' );
		return $products;
	}

	/**
	 * Whether demo products should be used when API fails.
	 */
		private static function is_demo_mode_enabled(): bool {
		$settings = get_option( 'ppw_settings', ppw_default_settings() );
		return ! empty( $settings['demo_mode'] );
	}

	private static function get_demo_products( string $keyword, int $page_size, string $platform = PPW_Platform::TAOBAO ): array {
		$pricing  = new PPW_Pricing_Agent();
		$products = array();
		$platform = PPW_Platform::normalize( $platform );
		$base     = array(
			array( 'title' => 'Apple iPhone 15 128GB 黑色', 'price_rmb' => 5999.0 ),
			array( 'title' => 'Apple iPhone 15 Pro 256GB 原色钛金属', 'price_rmb' => 8999.0 ),
			array( 'title' => '小米 14 Ultra 16GB+512GB 黑色', 'price_rmb' => 6499.0 ),
			array( 'title' => '华为 Mate 60 Pro 12GB+512GB 雅川青', 'price_rmb' => 6999.0 ),
			array( 'title' => 'OPPO Find X7 Ultra 16GB+512GB', 'price_rmb' => 5999.0 ),
			array( 'title' => 'vivo X100 Pro 16GB+512GB 星迹蓝', 'price_rmb' => 5499.0 ),
			array( 'title' => 'Redmi K70 Pro 16GB+512GB', 'price_rmb' => 3299.0 ),
			array( 'title' => '荣耀 Magic6 Pro 16GB+512GB', 'price_rmb' => 5699.0 ),
			array( 'title' => 'Samsung Galaxy S24 Ultra 256GB', 'price_rmb' => 9699.0 ),
			array( 'title' => '一加 12 16GB+512GB 留白', 'price_rmb' => 4799.0 ),
		);

		for ( $i = 0; $i < $page_size; $i++ ) {
			$template   = $base[ $i % count( $base ) ];
			$price      = $template['price_rmb'];
			$products[] = array(
				'item_id'   => 'demo-' . $platform . '-' . ( $i + 1 ),
				'title'     => $template['title'] . ' [' . $keyword . ' 演示]',
				'price_rmb' => $price,
				'price_cad' => $pricing->calculate_cad_price( $price ),
				'image'     => '',
				'url'       => PPW_Platform::default_item_url( $platform, (string) ( 100000 + $i ) ),
				'shop'      => __( '演示数据（API 未授权）', 'product-purchase-workflow' ),
				'platform'  => $platform,
				'is_demo'   => true,
			);
		}

		return $products;
	}

	public function fetch_product_details( string $keyword_or_url ): ?PPW_Product_Spec {
		$result = $this->search_products( $keyword_or_url, 1 );

		if ( is_wp_error( $result ) || empty( $result ) ) {
			return null;
		}

		$first = $result[0];

		try {
			return new PPW_Product_Spec(
				title: (string) $first['title'],
				image_url: (string) $first['image'],
				price_rmb: (float) $first['price_rmb'],
				detail_url: (string) ( $first['url'] ?? '' ),
				item_id: (string) ( $first['item_id'] ?? '' ),
			);
		} catch ( InvalidArgumentException $e ) {
			PPW_Logger::error( "[{$this->name}] 商品数据无效: {$e->getMessage()}" );
			return null;
		}
	}

	private function normalize_item( array $item ): ?array {
		if ( ! is_array( $item ) ) {
			return null;
		}

		$title = (string) ( $item['title'] ?? ( $item['skuName'] ?? '' ) );
		if ( '' === trim( $title ) ) {
			return null;
		}

		$price = self::extract_price( $item );
		if ( $price <= 0 ) {
			PPW_Logger::warning( "[{$this->name}] 跳过无价格商品: {$title}" );
			return null;
		}

		$image = (string) ( $item['pic_url'] ?? ( $item['pic_url_main'] ?? ( $item['image'] ?? '' ) ) );
		if ( str_starts_with( $image, '//' ) ) {
			$image = 'https:' . $image;
		}

		$item_id = (string) ( $item['num_iid'] ?? ( $item['item_id'] ?? ( $item['skuId'] ?? '' ) ) );
		$url     = (string) ( $item['detail_url'] ?? ( $item['item_url'] ?? '' ) );

		if ( '' === $url && '' !== $item_id ) {
			$url = PPW_Platform::default_item_url( $this->platform, $item_id );
		}
		if ( str_starts_with( $url, '//' ) ) {
			$url = 'https:' . $url;
		}

		$shop = (string) ( $item['seller'] ?? '' );
		if ( '' === $shop && ! empty( $item['seller_info'] ) && is_array( $item['seller_info'] ) ) {
			$shop = (string) ( $item['seller_info']['shop_name'] ?? ( $item['seller_info']['nick'] ?? '' ) );
		}
		if ( '' === $shop ) {
			$shop = (string) ( $item['nick'] ?? ( $item['shop_name'] ?? '' ) );
		}

		return array(
			'item_id'   => $item_id,
			'title'     => $title,
			'price_rmb' => $price,
			'image'     => $image,
			'url'       => $url,
			'shop'      => $shop,
			'platform'  => $this->platform,
		);
	}

	private static function extract_items( array $data ): array {
		$items = array();

		if ( ! empty( $data['items']['item'] ) ) {
			$items = $data['items']['item'];
		} elseif ( ! empty( $data['items'] ) && is_array( $data['items'] ) ) {
			$items = $data['items'];
		} elseif ( ! empty( $data['item'] ) && is_array( $data['item'] ) ) {
			$items = $data['item'];
		}

		if ( empty( $items ) ) {
			return array();
		}

		// Single item object vs list.
		if ( isset( $items['title'] ) || isset( $items['skuName'] ) || isset( $items['num_iid'] ) ) {
			return array( $items );
		}

		return array_values( array_filter( $items, 'is_array' ) );
	}

	private static function extract_price( array $item ): float {
		$candidates = array(
			$item['price'] ?? null,
			$item['promotion_price'] ?? null,
			$item['orginal_price'] ?? null,
			$item['original_price'] ?? null,
			$item['zk_final_price'] ?? null,
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

}

final class PPW_Pricing_Agent {
	public readonly float $base_rate;
	public readonly float $margin;
	private readonly string $name;

	public function __construct(
		?float $base_rate = null,
		?float $margin = null,
		string $name = 'PricingAgent',
	) {
		$settings = get_option( 'ppw_settings', array() );

		$this->base_rate = $base_rate ?? ( isset( $settings['base_rate'] ) ? (float) $settings['base_rate'] : 0.193 );
		$this->margin    = $margin ?? ( isset( $settings['margin'] ) ? (float) $settings['margin'] : 0.03 );
		$this->name      = $name;
	}

	public function calculate_cad_price( float $price_rmb ): float {
		$final_rate = $this->base_rate * ( 1 + $this->margin );
		$price_cad  = round( $price_rmb * $final_rate, 2 );

		PPW_Logger::info(
			sprintf(
				'[%s] Converted RMB %.2f -> CAD %.2f (Rate: %s, Markup: %.0f%%)',
				$this->name,
				$price_rmb,
				$price_cad,
				(string) $this->base_rate,
				$this->margin * 100
			)
		);

		return $price_cad;
	}
}

final class PPW_Product_Purchase_Workflow {
	public readonly string $order_id;
	public PPW_Workflow_State $state;
	public readonly string $raw_url;
	public ?string $sanitized_url = null;
	public ?PPW_Product_Spec $product_spec = null;
	public ?PPW_Order_Invoice $invoice = null;

	private PPW_Link_Parser_Agent $link_parser;
	private PPW_Pricing_Agent $pricing_engine;

	/** @var callable(string, string): void|null */
	private $alert_callback = null;

	public function __construct(
		string $raw_url,
		?float $base_rate = null,
		?float $platform_margin = null,
	) {
		$this->order_id       = self::generate_uuid();
		$this->state          = PPW_Workflow_State::INIT;
		$this->raw_url        = $raw_url;
		$this->link_parser    = new PPW_Link_Parser_Agent();
		$this->pricing_engine = new PPW_Pricing_Agent( $base_rate, $platform_margin );
	}

	/** @param callable(string, string): void $callback */
	public function register_alert_hook( callable $callback ): void {
		$this->alert_callback = $callback;
	}

	public function step(): PPW_Workflow_State {
		PPW_Logger::info( "[Workflow-{$this->order_id}] Currently in state: {$this->state->value}" );

		switch ( $this->state ) {
			case PPW_Workflow_State::INIT:
				$this->state = PPW_Workflow_State::PARSE_LINK;
				break;

			case PPW_Workflow_State::PARSE_LINK:
				$this->sanitized_url = $this->link_parser->clean_url( $this->raw_url );
				$spec                = $this->link_parser->fetch_product_details( $this->sanitized_url );

				if ( null === $spec ) {
					PPW_Logger::info( "[Workflow-{$this->order_id}] Automated parser failed. Moving to MANUAL_SPEC_PENDING." );
					$this->state = PPW_Workflow_State::MANUAL_SPEC_PENDING;
					$this->trigger_human_in_the_loop_alert();
				} else {
					$this->product_spec = $spec;
					PPW_Logger::info( "[Workflow-{$this->order_id}] Parsing successful for: {$spec->title}" );
					$this->state = PPW_Workflow_State::PRICING_ENGINE;
				}
				break;

			case PPW_Workflow_State::MANUAL_SPEC_PENDING:
				PPW_Logger::warning( "[Workflow-{$this->order_id}] Halted in MANUAL_SPEC_PENDING. Awaiting operator input." );
				break;

			case PPW_Workflow_State::PRICING_ENGINE:
				if ( null === $this->product_spec ) {
					PPW_Logger::error( "[Workflow-{$this->order_id}] Missing product specs for pricing! Flow aborted." );
					$this->state = PPW_Workflow_State::FAILED;
				} else {
					$this->pricing_engine->calculate_cad_price( $this->product_spec->price_rmb );
					$this->state = PPW_Workflow_State::CREATE_INVOICE;
				}
				break;

			case PPW_Workflow_State::CREATE_INVOICE:
				if ( null === $this->product_spec ) {
					$this->state = PPW_Workflow_State::FAILED;
					return $this->state;
				}

				$cad_price     = $this->pricing_engine->calculate_cad_price( $this->product_spec->price_rmb );
				$this->invoice = new PPW_Order_Invoice(
					order_id: $this->order_id,
					source_url: $this->sanitized_url ?? $this->raw_url,
					title: $this->product_spec->title,
					image_url: $this->product_spec->image_url,
					price_rmb: $this->product_spec->price_rmb,
					price_cad: $cad_price,
					exchange_rate: $this->pricing_engine->base_rate,
					margin_percentage: $this->pricing_engine->margin,
				);

				PPW_Logger::info( "[Workflow-{$this->order_id}] Structured order invoice created: {$this->invoice->to_json()}" );
				$this->state = PPW_Workflow_State::COMPLETED;
				break;
		}

		return $this->state;
	}

	public function execute(): ?PPW_Order_Invoice {
		while ( ! in_array(
			$this->state,
			array(
				PPW_Workflow_State::COMPLETED,
				PPW_Workflow_State::FAILED,
				PPW_Workflow_State::MANUAL_SPEC_PENDING,
			),
			true
		) ) {
			$this->step();
		}

		return $this->invoice;
	}

	public function resolve_manual_spec( string $title, string $image_url, float $price_rmb ): ?PPW_Order_Invoice {
		if ( PPW_Workflow_State::MANUAL_SPEC_PENDING !== $this->state ) {
			PPW_Logger::error( "[Workflow-{$this->order_id}] Invalid action: Cannot resolve spec unless state is MANUAL_SPEC_PENDING." );
			return null;
		}

		PPW_Logger::info( "[Workflow-{$this->order_id}] Manual specification received from Operator. Resuming workflow." );

		try {
			$this->product_spec = new PPW_Product_Spec( $title, $image_url, $price_rmb );
			$this->state        = PPW_Workflow_State::PRICING_ENGINE;
			return $this->execute();
		} catch ( InvalidArgumentException $e ) {
			PPW_Logger::error( "[Workflow-{$this->order_id}] Operator input failed validation: {$e->getMessage()}" );
			$this->state = PPW_Workflow_State::FAILED;
			return null;
		}
	}

	private function trigger_human_in_the_loop_alert(): void {
		$source   = $this->sanitized_url ?? $this->raw_url;
		$alert_msg = "ALERT: Order ID {$this->order_id} requires manual specification entry. Source URL: {$source}";

		if ( null !== $this->alert_callback ) {
			( $this->alert_callback )( $this->order_id, $alert_msg );
		} else {
			PPW_Logger::warning( "No alert hook registered! Admin notification missed: {$alert_msg}" );
		}
	}

	private static function generate_uuid(): string {
		return wp_generate_uuid4();
	}
}
