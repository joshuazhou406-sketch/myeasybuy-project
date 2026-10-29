<?php
/**
 * Homepage search integration and product display (Taobao / JD).
 *
 * @package Product_Purchase_Workflow
 */

defined( 'ABSPATH' ) || exit;

class PPW_Search {

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_ppw_jd_search', array( __CLASS__, 'ajax_search' ) );
		add_action( 'wp_ajax_nopriv_ppw_jd_search', array( __CLASS__, 'ajax_search' ) );
		add_action( 'wp_ajax_ppw_img_search', array( __CLASS__, 'ajax_img_search' ) );
		add_action( 'wp_ajax_nopriv_ppw_img_search', array( __CLASS__, 'ajax_img_search' ) );
		add_action( 'Fable_Extra_Shopire_frontpage', array( __CLASS__, 'render_results_container' ), 15 );
		add_action( 'wp_footer', array( __CLASS__, 'maybe_render_results_container' ), 5 );
	}

	/**
	 * Public wrapper for asset loading checks.
	 */
	public static function should_load_assets_public(): bool {
		return self::should_load_assets();
	}

	/**
	 * Default marketplace for homepage (settings-driven, falls back to taobao).
	 */
	public static function default_platform(): string {
		$settings = get_option( 'ppw_settings', array() );
		return PPW_Platform::normalize( (string) ( $settings['default_platform'] ?? PPW_Platform::TAOBAO ) );
	}

	/**
	 * Enqueue frontend scripts and styles.
	 */
	public static function enqueue_assets() {
		if ( ! self::should_load_assets() ) {
			return;
		}

		$platform = self::default_platform();

		wp_enqueue_style(
			'ppw-jd-products',
			PPW_PLUGIN_URL . 'assets/css/ppw-jd-products.css',
			array(),
			PPW_VERSION
		);

		wp_enqueue_script(
			'ppw-jd-search',
			PPW_PLUGIN_URL . 'assets/js/ppw-jd-search.js',
			array( 'jquery' ),
			PPW_VERSION,
			true
		);

		wp_localize_script(
			'ppw-jd-search',
			'ppwSearch',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'ppw_jd_search' ),
				'isHome'          => is_front_page(),
				'keyword'         => self::get_current_keyword(),
				'platform'        => $platform,
				'defaultPlatform' => $platform,
				'labels'          => array(
					'taobao' => __( '淘宝', 'product-purchase-workflow' ),
					'jd'     => __( '京东', 'product-purchase-workflow' ),
				),
				'loading'         => __( '正在搜索商品…', 'product-purchase-workflow' ),
				'loadingMore'     => __( '正在加载更多…', 'product-purchase-workflow' ),
				'noResults'       => __( '未找到相关商品，请换个关键词试试。', 'product-purchase-workflow' ),
				'error'           => __( '搜索失败，请稍后重试。', 'product-purchase-workflow' ),
				'heading'         => __( '商品搜索结果', 'product-purchase-workflow' ),
				'buyText'         => __( '查看详情', 'product-purchase-workflow' ),
				'viewMore'        => __( 'View More', 'product-purchase-workflow' ),
				'noMore'          => __( '没有更多商品了', 'product-purchase-workflow' ),
				'detailLoading'   => __( '正在加载商品详情…', 'product-purchase-workflow' ),
				'detailError'     => __( '加载商品详情失败。', 'product-purchase-workflow' ),
				'linkLoading'     => __( '正在解析商品链接…', 'product-purchase-workflow' ),
				'linkError'       => __( '无法解析该商品链接。', 'product-purchase-workflow' ),
				'linkResolved'    => __( '已根据链接获取商品详情', 'product-purchase-workflow' ),
				'searchHint'      => __( '可输入关键词或粘贴淘宝/京东商品链接', 'product-purchase-workflow' ),
				'titleTpl'        => __( '「%1$s」的%2$s商品（已显示 %3$d 件）', 'product-purchase-workflow' ),
				'imgSearchTitle'  => __( '以图搜图 · %1$s（已显示 %2$d 件）', 'product-purchase-workflow' ),
				'imgSearching'    => __( '正在识别图片并搜索相似商品…', 'product-purchase-workflow' ),
				'imgButtonLabel'  => __( '图片搜索', 'product-purchase-workflow' ),
				'imgTooLarge'     => __( '图片过大，请选择 5MB 以内的图片。', 'product-purchase-workflow' ),
				'imgInvalidType'  => __( '请上传 JPG / PNG / WEBP / GIF 图片。', 'product-purchase-workflow' ),
			)
		);
	}

	/**
	 * @return bool
	 */
	private static function should_load_assets() {
		if ( is_front_page() ) {
			return true;
		}

		if ( is_search() && ! empty( $_GET['s'] ) ) {
			return true;
		}

		/**
		 * Load search assets and the product detail modal on additional pages.
		 *
		 * @param bool $load Default false.
		 */
		return (bool) apply_filters( 'ppw_should_load_assets', false );
	}

	/**
	 * @return string
	 */
	private static function get_current_keyword() {
		if ( ! empty( $_GET['ppw_q'] ) ) {
			return sanitize_text_field( wp_unslash( $_GET['ppw_q'] ) );
		}

		if ( ! empty( $_GET['ppw_jd'] ) ) {
			return sanitize_text_field( wp_unslash( $_GET['ppw_jd'] ) );
		}

		if ( is_search() && ! empty( $_GET['s'] ) ) {
			return sanitize_text_field( wp_unslash( $_GET['s'] ) );
		}

		return '';
	}

	/**
	 * Output results container on front page (includes platform switcher).
	 */
	public static function render_results_container() {
		$platform = self::default_platform();
		echo '<section id="ppw-jd-results-section" class="ppw-jd-results-section wf-py-default" aria-live="polite" data-platform="' . esc_attr( $platform ) . '">';
		echo '<div class="wf-container">';
		echo '<div class="ppw-platform-switcher" role="tablist" aria-label="' . esc_attr__( '商品平台', 'product-purchase-workflow' ) . '">';
		echo '<button type="button" class="ppw-platform-btn' . ( PPW_Platform::TAOBAO === $platform ? ' is-active' : '' ) . '" data-ppw-platform="taobao" role="tab" aria-selected="' . ( PPW_Platform::TAOBAO === $platform ? 'true' : 'false' ) . '">' . esc_html__( '淘宝', 'product-purchase-workflow' ) . '</button>';
		echo '<button type="button" class="ppw-platform-btn' . ( PPW_Platform::JD === $platform ? ' is-active' : '' ) . '" data-ppw-platform="jd" role="tab" aria-selected="' . ( PPW_Platform::JD === $platform ? 'true' : 'false' ) . '">' . esc_html__( '京东', 'product-purchase-workflow' ) . '</button>';
		echo '</div>';
		echo '<div id="ppw-jd-results" class="ppw-jd-results"></div>';
		echo '</div>';
		echo '</section>';
	}

	/**
	 * Fallback container for non-frontpage templates and search result pages.
	 */
	public static function maybe_render_results_container() {
		if ( did_action( 'Fable_Extra_Shopire_frontpage' ) ) {
			return;
		}

		if ( ! is_front_page() && ! is_search() ) {
			return;
		}

		self::render_results_container();
	}

	/**
	 * AJAX handler for product search (supports platform + pagination).
	 */
	public static function ajax_search() {
		check_ajax_referer( 'ppw_jd_search', 'nonce' );

		$keyword  = isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '';
		$page     = isset( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;
		$platform = PPW_Platform::normalize(
			isset( $_POST['platform'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['platform'] ) ) : self::default_platform()
		);

		if ( strlen( $keyword ) < 2 ) {
			wp_send_json_error(
				array( 'message' => __( '请输入至少 2 个字符。', 'product-purchase-workflow' ) ),
				400
			);
		}

		$settings  = get_option( 'ppw_settings', array() );
		$page_size = isset( $settings['page_size'] ) ? (int) $settings['page_size'] : 20;
		$page_size = min( max( 1, $page_size ), 20 );

		$cache_key = 'ppw_search_' . md5(
			$platform . '|' . $keyword . '|' . $page_size . '|' . $page . '|' .
			PPW_Link_Parser_Agent::SEARCH_START_PRICE . '|' .
			PPW_Link_Parser_Agent::SEARCH_END_PRICE
		);
		$cached = get_transient( $cache_key );

		if ( false !== $cached && is_array( $cached ) ) {
			if ( ! empty( $cached['products'] ) && is_array( $cached['products'] ) ) {
				foreach ( $cached['products'] as $product ) {
					if ( is_array( $product ) ) {
						PPW_Item_Get::cache_search_snapshot( $product );
					}
				}
			}
			wp_send_json_success( $cached );
		}

		$parser = new PPW_Link_Parser_Agent();
		$parser->set_platform( $platform );
		$products = $parser->search_products( $keyword, $page_size, $page );

		if ( is_wp_error( $products ) ) {
			wp_send_json_error(
				array( 'message' => $products->get_error_message() ),
				500
			);
		}

		$label = PPW_Platform::label( $platform );

		if ( empty( $products ) ) {
			if ( $page > 1 ) {
				wp_send_json_success(
					array(
						'keyword'    => $keyword,
						'platform'   => $platform,
						'page'       => $page,
						'page_size'  => $page_size,
						'count'      => 0,
						'has_more'   => false,
						'products'   => array(),
						'cards_html' => '',
						'html'       => '',
					)
				);
			}

			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: platform label */
						__( '未找到相关%s商品，请换个关键词试试。', 'product-purchase-workflow' ),
						$label
					),
				),
				404
			);
		}

		foreach ( $products as $product ) {
			if ( is_array( $product ) ) {
				PPW_Item_Get::cache_search_snapshot( $product );
			}
		}

		$has_more   = count( $products ) >= $page_size;
		$cards_html = self::render_product_cards_html( $products );
		$response   = array(
			'keyword'    => $keyword,
			'platform'   => $platform,
			'page'       => $page,
			'page_size'  => $page_size,
			'count'      => count( $products ),
			'has_more'   => $has_more,
			'products'   => $products,
			'cards_html' => $cards_html,
			'html'       => self::render_products_html( $products, $keyword, $page, $has_more, $platform ),
		);

		set_transient( $cache_key, $response, HOUR_IN_SECONDS );

		wp_send_json_success( $response );
	}

	/**
	 * AJAX: image search (taobao.item_search_img / jd.item_search_img).
	 * Reuses the same product card / detail pipeline as keyword search.
	 */
	public static function ajax_img_search() {
		check_ajax_referer( 'ppw_jd_search', 'nonce' );

		$page     = isset( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;
		$platform = PPW_Platform::normalize(
			isset( $_POST['platform'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['platform'] ) ) : self::default_platform()
		);

		$image_source = self::resolve_uploaded_image_source();
		if ( is_wp_error( $image_source ) ) {
			wp_send_json_error( array( 'message' => $image_source->get_error_message() ), 400 );
		}

		$settings  = get_option( 'ppw_settings', array() );
		$page_size = isset( $settings['page_size'] ) ? (int) $settings['page_size'] : 20;
		$page_size = min( max( 1, $page_size ), 20 );

		// Cache by image fingerprint + platform/page.
		if ( is_string( $image_source ) && str_starts_with( $image_source, 'file:' ) ) {
			$path        = substr( $image_source, 5 );
			$fingerprint = ( is_readable( $path ) ? (string) hash_file( 'sha256', $path ) : hash( 'sha256', $image_source ) );
			$fingerprint = substr( $fingerprint, 0, 32 );
		} else {
			$fingerprint = substr( hash( 'sha256', (string) $image_source ), 0, 32 );
		}
		$cache_key = 'ppw_img_' . md5( $platform . '|' . $fingerprint . '|' . $page_size . '|' . $page );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached && is_array( $cached ) ) {
			if ( ! empty( $cached['products'] ) && is_array( $cached['products'] ) ) {
				foreach ( $cached['products'] as $product ) {
					if ( is_array( $product ) ) {
						PPW_Item_Get::cache_search_snapshot( $product );
					}
				}
			}
			wp_send_json_success( $cached );
		}

		$parser = new PPW_Link_Parser_Agent();
		$parser->set_platform( $platform );
		$products = $parser->search_products_by_image( $image_source, $page_size, $page );

		if ( is_wp_error( $products ) ) {
			$status = 500;
			$data   = $products->get_error_data();
			if ( is_array( $data ) && isset( $data['onebound_code'] ) && '4005' === (string) $data['onebound_code'] ) {
				$status = 403;
			}
			wp_send_json_error(
				array(
					'message'  => $products->get_error_message(),
					'called'   => true,
					'platform' => $platform,
					'api'      => PPW_Platform::JD === $platform ? 'jd.item_search_img' : 'taobao.item_search_img',
				),
				$status
			);
		}

		$label   = PPW_Platform::label( $platform );
		$keyword = sprintf(
			/* translators: %s: platform label */
			__( '以图搜图(%s)', 'product-purchase-workflow' ),
			$label
		);

		if ( empty( $products ) ) {
			if ( $page > 1 ) {
				wp_send_json_success(
					array(
						'keyword'    => $keyword,
						'platform'   => $platform,
						'page'       => $page,
						'page_size'  => $page_size,
						'count'      => 0,
						'has_more'   => false,
						'products'   => array(),
						'cards_html' => '',
						'html'       => '',
						'mode'       => 'image',
					)
				);
			}

			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: platform label */
						__( '未找到相似的%s商品，请换一张图片试试。', 'product-purchase-workflow' ),
						$label
					),
				),
				404
			);
		}

		foreach ( $products as $product ) {
			if ( is_array( $product ) ) {
				PPW_Item_Get::cache_search_snapshot( $product );
			}
		}

		$has_more   = count( $products ) >= $page_size;
		$cards_html = self::render_product_cards_html( $products );

		$last = PPW_Link_Parser_Agent::get_last_img_search();
		if ( ! empty( $last['bridge_keyword'] ) && is_string( $last['bridge_keyword'] ) ) {
			$keyword = sprintf(
				/* translators: 1: platform label, 2: derived keyword */
				__( '以图搜图(%1$s)·关键词：%2$s', 'product-purchase-workflow' ),
				$label,
				$last['bridge_keyword']
			);
		}

		$response   = array(
			'keyword'    => $keyword,
			'platform'   => $platform,
			'page'       => $page,
			'page_size'  => $page_size,
			'count'      => count( $products ),
			'has_more'   => $has_more,
			'products'   => $products,
			'cards_html' => $cards_html,
			'html'       => self::render_products_html( $products, $keyword, $page, $has_more, $platform ),
			'mode'       => 'image',
			'bridge'     => ( ! empty( $last['mode'] ) && 'jd_via_taobao_title' === $last['mode'] ),
		);

		set_transient( $cache_key, $response, HOUR_IN_SECONDS );
		wp_send_json_success( $response );
	}

	/**
	 * Read uploaded file or image_url / image_base64 from the request.
	 *
	 * @return string|WP_Error Local "file:/path", https URL, or data-URI/base64.
	 */
	private static function resolve_uploaded_image_source() {
		if ( ! empty( $_FILES['image'] ) && is_array( $_FILES['image'] ) ) {
			$file = $_FILES['image']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated below.
			$error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
			if ( UPLOAD_ERR_OK !== $error ) {
				return new WP_Error( 'ppw_upload_err', __( '图片上传失败，请重试。', 'product-purchase-workflow' ) );
			}

			$tmp  = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
			$size = isset( $file['size'] ) ? (int) $file['size'] : 0;
			if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
				return new WP_Error( 'ppw_upload_tmp', __( '无效的上传文件。', 'product-purchase-workflow' ) );
			}
			if ( $size <= 0 || $size > 5 * MB_IN_BYTES ) {
				return new WP_Error( 'ppw_upload_size', __( '图片过大，请选择 5MB 以内的图片。', 'product-purchase-workflow' ) );
			}

			$check = wp_check_filetype_and_ext(
				$tmp,
				isset( $file['name'] ) ? (string) $file['name'] : 'image.jpg'
			);
			$ext   = strtolower( (string) ( $check['ext'] ?? '' ) );
			$allowed = array( 'jpg', 'jpeg', 'png', 'gif', 'webp' );
			if ( ! in_array( $ext, $allowed, true ) ) {
				// Fallback to mime from file info.
				$finfo = function_exists( 'mime_content_type' ) ? mime_content_type( $tmp ) : '';
				if ( ! is_string( $finfo ) || ! str_starts_with( $finfo, 'image/' ) ) {
					return new WP_Error( 'ppw_upload_type', __( '请上传 JPG / PNG / WEBP / GIF 图片。', 'product-purchase-workflow' ) );
				}
			}

			// Keep binary on disk for multipart upload_img (never send giant base64 as form field).
			return 'file:' . $tmp;
		}

		if ( ! empty( $_POST['image_base64'] ) ) {
			$raw = wp_unslash( (string) $_POST['image_base64'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$raw = trim( $raw );
			if ( '' === $raw ) {
				return new WP_Error( 'ppw_missing_image', __( '请上传或粘贴一张商品图片。', 'product-purchase-workflow' ) );
			}
			if ( strlen( $raw ) > 7 * MB_IN_BYTES ) {
				return new WP_Error( 'ppw_upload_size', __( '图片过大，请选择 5MB 以内的图片。', 'product-purchase-workflow' ) );
			}
			return $raw;
		}

		if ( ! empty( $_POST['image_url'] ) ) {
			$url = esc_url_raw( wp_unslash( (string) $_POST['image_url'] ) );
			if ( '' === $url ) {
				return new WP_Error( 'ppw_missing_image', __( '请上传或粘贴一张商品图片。', 'product-purchase-workflow' ) );
			}
			return $url;
		}

		return new WP_Error( 'ppw_missing_image', __( '请上传或粘贴一张商品图片。', 'product-purchase-workflow' ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $products Product list.
	 * @return string
	 */
	public static function render_product_cards_html( $products ) {
		if ( empty( $products ) ) {
			return '';
		}

		ob_start();
		foreach ( $products as $product ) {
			self::render_product_card( $product );
		}
		return ob_get_clean();
	}

	/**
	 * @param array<string, mixed> $product Product.
	 */
	private static function render_product_card( array $product ): void {
		$platform = PPW_Platform::normalize( (string) ( $product['platform'] ?? PPW_Platform::TAOBAO ) );
		?>
		<li class="ppw-jd-card">
			<button
				type="button"
				class="ppw-jd-card-link"
				data-item-id="<?php echo esc_attr( (string) ( $product['item_id'] ?? '' ) ); ?>"
				data-platform="<?php echo esc_attr( $platform ); ?>"
				data-jd-url="<?php echo esc_url( (string) ( $product['url'] ?? '' ) ); ?>"
				data-title="<?php echo esc_attr( (string) ( $product['title'] ?? '' ) ); ?>"
				data-price-rmb="<?php echo esc_attr( (string) ( $product['price_rmb'] ?? '' ) ); ?>"
				data-image="<?php echo esc_url( (string) ( $product['image'] ?? '' ) ); ?>"
				data-shop="<?php echo esc_attr( (string) ( $product['shop'] ?? '' ) ); ?>"
			>
				<div class="ppw-jd-card-image">
					<?php if ( ! empty( $product['image'] ) ) : ?>
						<img src="<?php echo esc_url( (string) $product['image'] ); ?>" alt="<?php echo esc_attr( (string) ( $product['title'] ?? '' ) ); ?>" loading="lazy" />
					<?php else : ?>
						<span class="ppw-jd-no-image"><?php esc_html_e( '暂无图片', 'product-purchase-workflow' ); ?></span>
					<?php endif; ?>
				</div>
				<div class="ppw-jd-card-body">
					<span class="ppw-platform-tag ppw-platform-tag--<?php echo esc_attr( $platform ); ?>"><?php echo esc_html( PPW_Platform::label( $platform ) ); ?></span>
					<h3 class="ppw-jd-card-title"><?php echo esc_html( (string) ( $product['title'] ?? '' ) ); ?></h3>
					<?php if ( ! empty( $product['shop'] ) ) : ?>
						<p class="ppw-jd-card-shop"><?php echo esc_html( (string) $product['shop'] ); ?></p>
					<?php endif; ?>
					<?php if ( ! empty( $product['price_rmb'] ) ) : ?>
						<p class="ppw-jd-card-price">
							<span class="ppw-jd-currency">¥</span><?php echo esc_html( number_format( (float) $product['price_rmb'], 2 ) ); ?>
						</p>
					<?php endif; ?>
					<?php if ( ! empty( $product['price_cad'] ) ) : ?>
						<p class="ppw-jd-card-price-cad">
							CAD <?php echo esc_html( number_format( (float) $product['price_cad'], 2 ) ); ?>
						</p>
					<?php endif; ?>
					<span class="ppw-jd-card-btn"><?php esc_html_e( '查看详情', 'product-purchase-workflow' ); ?></span>
				</div>
			</button>
		</li>
		<?php
	}

	/**
	 * @param array  $products Product list.
	 * @param string $keyword  Search keyword.
	 * @param int    $page     Current page.
	 * @param bool   $has_more Whether more pages exist.
	 * @param string $platform Platform slug.
	 * @return string
	 */
	public static function render_products_html( $products, $keyword, $page = 1, $has_more = false, $platform = 'taobao' ) {
		$platform = PPW_Platform::normalize( (string) $platform );
		$label    = PPW_Platform::label( $platform );

		if ( empty( $products ) ) {
			return '<p class="ppw-jd-empty">' . esc_html(
				sprintf(
					/* translators: %s: platform label */
					__( '未找到相关%s商品，请换个关键词试试。', 'product-purchase-workflow' ),
					$label
				)
			) . '</p>';
		}

		$is_demo = ! empty( $products[0]['is_demo'] );
		$count   = count( $products );

		ob_start();
		?>
		<?php if ( $is_demo ) : ?>
			<div class="ppw-jd-demo-notice">
				<?php
				printf(
					/* translators: %s: platform label */
					esc_html__( '当前为演示数据：OneBound API 未授权。请在后台购买正版 API 并关闭「演示模式」以显示真实%s商品。', 'product-purchase-workflow' ),
					esc_html( $label )
				);
				?>
			</div>
		<?php endif; ?>
		<div class="ppw-jd-results-header">
			<h2 class="ppw-jd-results-title" data-ppw-results-title data-keyword="<?php echo esc_attr( $keyword ); ?>" data-platform="<?php echo esc_attr( $platform ); ?>">
				<?php
				printf(
					/* translators: 1: keyword, 2: platform label, 3: product count */
					esc_html__( '「%1$s」的%2$s商品（已显示 %3$d 件）', 'product-purchase-workflow' ),
					esc_html( $keyword ),
					esc_html( $label ),
					$count
				);
				?>
			</h2>
		</div>
		<ul class="ppw-jd-grid" data-ppw-jd-grid>
			<?php echo self::render_product_cards_html( $products ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in card renderer ?>
		</ul>
		<?php if ( $has_more ) : ?>
			<div class="ppw-jd-view-more-wrap">
				<button
					type="button"
					class="ppw-jd-view-more"
					data-ppw-view-more
					data-keyword="<?php echo esc_attr( $keyword ); ?>"
					data-platform="<?php echo esc_attr( $platform ); ?>"
					data-page="<?php echo esc_attr( (string) $page ); ?>"
					<?php if ( false !== strpos( $keyword, '以图搜图' ) ) : ?>
						data-mode="image"
					<?php endif; ?>
				>
					<?php esc_html_e( 'View More', 'product-purchase-workflow' ); ?>
				</button>
			</div>
		<?php endif; ?>
		<?php
		return ob_get_clean();
	}
}
