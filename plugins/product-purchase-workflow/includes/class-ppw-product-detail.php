<?php
/**
 * Product detail modal and AJAX handler.
 *
 * @package Product_Purchase_Workflow
 */

defined( 'ABSPATH' ) || exit;

class PPW_Product_Detail {

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'wp_ajax_ppw_jd_product_detail', array( __CLASS__, 'ajax_detail' ) );
		add_action( 'wp_ajax_nopriv_ppw_jd_product_detail', array( __CLASS__, 'ajax_detail' ) );
		add_action( 'wp_ajax_ppw_resolve_product_link', array( __CLASS__, 'ajax_resolve_link' ) );
		add_action( 'wp_ajax_nopriv_ppw_resolve_product_link', array( __CLASS__, 'ajax_resolve_link' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render_modal_shell' ) );
	}

	/**
	 * AJAX: paste product URL → parse ID → item_get → WC sync → detail HTML.
	 */
	public static function ajax_resolve_link() {
		check_ajax_referer( 'ppw_jd_search', 'nonce' );

		$query = '';
		if ( isset( $_POST['query'] ) ) {
			$raw = wp_unslash( (string) $_POST['query'] );
			$query = PPW_Platform::looks_like_product_url( $raw )
				? esc_url_raw( $raw )
				: sanitize_text_field( $raw );
			// esc_url_raw can empty non-http pastes like item.jd.com/... — keep original trimmed.
			if ( '' === $query ) {
				$query = sanitize_text_field( $raw );
			}
		} elseif ( isset( $_POST['url'] ) ) {
			$query = esc_url_raw( wp_unslash( (string) $_POST['url'] ) );
		}

		if ( '' === trim( $query ) ) {
			wp_send_json_error( array( 'message' => __( '请粘贴淘宝或京东商品链接。', 'product-purchase-workflow' ) ), 400 );
		}

		if ( ! PPW_Platform::looks_like_product_url( $query ) ) {
			wp_send_json_error(
				array(
					'message' => __( '未识别为商品链接，请使用关键词搜索。', 'product-purchase-workflow' ),
					'is_link' => false,
				),
				400
			);
		}

		$parsed = PPW_Platform::parse_product_url( $query );
		if ( null === $parsed || '' === ( $parsed['num_iid'] ?? '' ) ) {
			wp_send_json_error(
				array( 'message' => __( '无法从链接中解析商品 ID，请检查链接是否完整。', 'product-purchase-workflow' ) ),
				400
			);
		}

		$platform = PPW_Platform::normalize( (string) $parsed['platform'] );
		$num_iid  = (string) $parsed['num_iid'];
		$fallback = array(
			'url'      => (string) ( $parsed['url'] ?? '' ),
			'platform' => $platform,
			'global'   => ! empty( $parsed['global'] ),
			'sku_id'   => (string) ( $parsed['sku_id'] ?? '' ),
		);

		$item = PPW_Item_Get::fetch( $num_iid, $fallback, $platform );
		if ( is_wp_error( $item ) ) {
			wp_send_json_error( array( 'message' => $item->get_error_message() ), 500 );
		}

		$wc = PPW_WooCommerce_Sync::ensure_product( $item );
		if ( is_wp_error( $wc ) ) {
			wp_send_json_error( array( 'message' => $wc->get_error_message() ), 500 );
		}

		wp_send_json_success(
			array(
				'type'        => 'detail',
				'platform'    => $platform,
				'num_iid'     => $num_iid,
				'item'        => $item,
				'product_id'  => $wc['product_id'],
				'product_url' => $wc['url'],
				'created'     => $wc['created'],
				'html'        => self::render_detail_html( $item, $wc ),
			)
		);
	}

	/**
	 * AJAX: fetch item_get, sync WC, return detail HTML.
	 */
	public static function ajax_detail() {
		check_ajax_referer( 'ppw_jd_search', 'nonce' );

		$num_iid = isset( $_POST['num_iid'] ) ? sanitize_text_field( wp_unslash( $_POST['num_iid'] ) ) : '';
		if ( '' === $num_iid ) {
			wp_send_json_error( array( 'message' => __( '缺少商品 ID。', 'product-purchase-workflow' ) ), 400 );
		}

		$platform = PPW_Platform::normalize(
			isset( $_POST['platform'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['platform'] ) ) : PPW_Platform::TAOBAO
		);

		$fallback = array(
			'title'     => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['title'] ) ) : '',
			'price_rmb' => isset( $_POST['price_rmb'] ) ? (float) $_POST['price_rmb'] : 0,
			'image'     => isset( $_POST['image'] ) ? esc_url_raw( wp_unslash( (string) $_POST['image'] ) ) : '',
			'url'       => isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( (string) $_POST['url'] ) ) : '',
			'shop'      => isset( $_POST['shop'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['shop'] ) ) : '',
			'platform'  => $platform,
		);

		$item = PPW_Item_Get::fetch( $num_iid, $fallback, $platform );
		if ( is_wp_error( $item ) ) {
			wp_send_json_error( array( 'message' => $item->get_error_message() ), 500 );
		}

		$wc = PPW_WooCommerce_Sync::ensure_product( $item );
		if ( is_wp_error( $wc ) ) {
			wp_send_json_error( array( 'message' => $wc->get_error_message() ), 500 );
		}

		wp_send_json_success(
			array(
				'item'         => $item,
				'product_id'   => $wc['product_id'],
				'product_url'  => $wc['url'],
				'created'      => $wc['created'],
				'html'         => self::render_detail_html( $item, $wc ),
			)
		);
	}

	/**
	 * Modal container in footer.
	 */
	public static function render_modal_shell() {
		if ( ! PPW_Search::should_load_assets_public() ) {
			return;
		}
		?>
		<div id="ppw-jd-detail-modal" class="ppw-jd-detail-modal" aria-hidden="true">
			<div class="ppw-jd-detail-backdrop" data-ppw-close-detail></div>
			<div class="ppw-jd-detail-dialog" role="dialog" aria-modal="true" aria-labelledby="ppw-jd-detail-title">
				<button type="button" class="ppw-jd-detail-close" data-ppw-close-detail aria-label="<?php esc_attr_e( '关闭', 'product-purchase-workflow' ); ?>">&times;</button>
				<div id="ppw-jd-detail-content" class="ppw-jd-detail-content"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $item JD item.
	 * @param array<string, mixed> $wc   WC sync result.
	 */
	public static function render_detail_html( array $item, array $wc ): string {
		$product_id = (int) ( $wc['product_id'] ?? 0 );
		$created    = ! empty( $wc['created'] );
		$product_url = (string) ( $wc['url'] ?? '' );
		$images     = $item['images'] ?? array();
		if ( empty( $images ) && ! empty( $item['pic_url'] ) ) {
			$images = array( $item['pic_url'] );
		}

		ob_start();
		?>
		<div class="ppw-jd-detail-layout">
			<div class="ppw-jd-detail-gallery">
				<?php if ( ! empty( $images ) ) : ?>
					<div class="ppw-jd-detail-main-image">
						<img src="<?php echo esc_url( $images[0] ); ?>" alt="<?php echo esc_attr( $item['title'] ?? '' ); ?>" />
					</div>
					<?php if ( count( $images ) > 1 ) : ?>
						<div class="ppw-jd-detail-thumbs">
							<?php foreach ( $images as $image ) : ?>
								<button type="button" class="ppw-jd-detail-thumb" data-image="<?php echo esc_url( $image ); ?>">
									<img src="<?php echo esc_url( $image ); ?>" alt="" loading="lazy" />
								</button>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				<?php endif; ?>
			</div>
			<div class="ppw-jd-detail-info">
				<?php if ( ! empty( $item['from_world_page'] ) ) : ?>
					<span class="ppw-jd-detail-badge ppw-jd-detail-badge--fallback"><?php esc_html_e( '淘宝全球商品：详情接口读不到，已改从全球页获取。规格可能不完整。', 'product-purchase-workflow' ); ?></span>
				<?php elseif ( ! empty( $item['from_search_fallback'] ) ) : ?>
					<span class="ppw-jd-detail-badge ppw-jd-detail-badge--fallback"><?php esc_html_e( '使用搜索数据上架（暂无规格，详情 API 不可用时会缺款式）', 'product-purchase-workflow' ); ?></span>
				<?php elseif ( $created ) : ?>
					<span class="ppw-jd-detail-badge ppw-jd-detail-badge--new"><?php esc_html_e( '已自动上架到您的商店', 'product-purchase-workflow' ); ?></span>
				<?php else : ?>
					<span class="ppw-jd-detail-badge ppw-jd-detail-badge--exists"><?php esc_html_e( '商店中已有此商品', 'product-purchase-workflow' ); ?></span>
				<?php endif; ?>

				<?php
				$sku_count = is_array( $item['skus'] ?? null ) ? count( $item['skus'] ) : 0;
				if ( $sku_count > 0 ) :
					?>
					<span class="ppw-jd-detail-badge ppw-jd-detail-badge--new">
						<?php
						printf(
							/* translators: %d: number of SKUs */
							esc_html__( '已同步 %d 个规格/款式，可在商品页选择', 'product-purchase-workflow' ),
							$sku_count
						);
						?>
					</span>
				<?php endif; ?>

				<h2 id="ppw-jd-detail-title" class="ppw-jd-detail-title"><?php echo esc_html( $item['title'] ?? '' ); ?></h2>

				<?php if ( ! empty( $item['shop'] ) ) : ?>
					<p class="ppw-jd-detail-shop"><?php echo esc_html( $item['shop'] ); ?></p>
				<?php endif; ?>

				<div class="ppw-jd-detail-prices">
					<?php if ( ! empty( $item['price_rmb'] ) ) : ?>
						<p class="ppw-jd-detail-price-rmb">
							<span class="ppw-jd-currency">¥</span><?php echo esc_html( number_format( (float) $item['price_rmb'], 2 ) ); ?>
						</p>
					<?php endif; ?>
					<?php if ( ! empty( $item['price_cad'] ) ) : ?>
						<p class="ppw-jd-detail-price-cad">
							CAD <?php echo esc_html( number_format( (float) $item['price_cad'], 2 ) ); ?>
						</p>
					<?php endif; ?>
				</div>

				<?php if ( ! empty( $item['props_list'] ) && is_array( $item['props_list'] ) ) : ?>
					<ul class="ppw-jd-detail-props">
						<?php foreach ( $item['props_list'] as $key => $value ) : ?>
							<li><strong><?php echo esc_html( (string) $key ); ?>:</strong> <?php echo esc_html( (string) $value ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<?php if ( ! empty( $item['attributes'] ) && is_array( $item['attributes'] ) ) : ?>
					<div class="ppw-jd-detail-attributes">
						<h3><?php esc_html_e( '可选规格', 'product-purchase-workflow' ); ?></h3>
						<ul class="ppw-jd-detail-props">
							<?php foreach ( $item['attributes'] as $attr_name => $options ) : ?>
								<li>
									<strong><?php echo esc_html( (string) $attr_name ); ?>:</strong>
									<?php echo esc_html( is_array( $options ) ? implode( ' / ', $options ) : (string) $options ); ?>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $item['skus'] ) ) : ?>
					<div class="ppw-jd-detail-skus">
						<h3><?php esc_html_e( '规格组合', 'product-purchase-workflow' ); ?></h3>
						<ul>
							<?php foreach ( array_slice( $item['skus'], 0, 20 ) as $sku ) : ?>
								<?php
								$label = '';
								if ( ! empty( $sku['attributes'] ) && is_array( $sku['attributes'] ) ) {
									$pairs = array();
									foreach ( $sku['attributes'] as $n => $v ) {
										$pairs[] = $n . ': ' . $v;
									}
									$label = implode( ' / ', $pairs );
								}
								if ( '' === $label ) {
									$label = (string) ( $sku['properties_name'] ?? '' );
								}
								?>
								<li><?php echo esc_html( $label ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>

				<div class="ppw-jd-detail-actions">
					<?php if ( $product_id && $product_url ) : ?>
						<a class="ppw-jd-detail-btn ppw-jd-detail-btn--primary" href="<?php echo esc_url( $product_url ); ?>">
							<?php esc_html_e( '在本店查看并购买', 'product-purchase-workflow' ); ?>
						</a>
						<?php if ( function_exists( 'wc_get_cart_url' ) ) : ?>
							<a class="ppw-jd-detail-btn ppw-jd-detail-btn--cart" href="<?php echo esc_url( $product_url . '?add-to-cart=' . $product_id ); ?>">
								<?php esc_html_e( '加入购物车', 'product-purchase-workflow' ); ?>
							</a>
						<?php endif; ?>
					<?php endif; ?>
					<?php if ( ! empty( $item['detail_url'] ) ) : ?>
						<a class="ppw-jd-detail-btn ppw-jd-detail-btn--ghost" href="<?php echo esc_url( $item['detail_url'] ); ?>" target="_blank" rel="noopener noreferrer">
							<?php
							printf(
								/* translators: %s: platform label */
								esc_html__( '查看%s原页', 'product-purchase-workflow' ),
								esc_html( PPW_Platform::label( (string) ( $item['platform'] ?? PPW_Platform::TAOBAO ) ) )
							);
							?>
						</a>
					<?php endif; ?>
				</div>

				<?php if ( ! empty( $item['desc_short'] ) ) : ?>
					<div class="ppw-jd-detail-desc">
						<p><?php echo esc_html( $item['desc_short'] ); ?></p>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $item['desc_images'] ) ) : ?>
					<div class="ppw-jd-detail-desc-images">
						<?php foreach ( array_slice( $item['desc_images'], 0, 6 ) as $img ) : ?>
							<img src="<?php echo esc_url( $img ); ?>" alt="" loading="lazy" />
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
