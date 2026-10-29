<?php
/**
 * Single product purchase / preview page (agent-storefront style).
 * Header/footer come from parts/ via wp_body_open / wp_footer.
 *
 * @package MyEasyBuy_Home
 */

defined( 'ABSPATH' ) || exit;

global $post;

$meb_u       = meb_urls();
$meb_product = function_exists( 'wc_get_product' ) ? wc_get_product( get_the_ID() ) : null;
if ( ! $meb_product instanceof WC_Product ) {
	status_header( 404 );
	nocache_headers();
	include get_query_template( '404' );
	return;
}

$meb_pid         = $meb_product->get_id();
$meb_is_variable = $meb_product->is_type( 'variable' );
$meb_permalink   = $meb_product->get_permalink();
$meb_platform    = (string) get_post_meta( $meb_pid, '_ppw_source_platform', true );
$meb_prefix      = 'jd' === $meb_platform ? '_ppw_jd_' : '_ppw_tb_';
$meb_num_iid     = (string) get_post_meta( $meb_pid, $meb_prefix . 'num_iid', true );
$meb_source_url  = (string) get_post_meta( $meb_pid, $meb_prefix . 'source_url', true );

// Gallery URLs: featured + gallery attachments + remote URLs from cached item_get / description.
$meb_images = array();
$meb_push   = static function ( string $url ) use ( &$meb_images ): void {
	$url = trim( $url );
	if ( '' !== $url && ! in_array( $url, $meb_images, true ) ) {
		$meb_images[] = $url;
	}
};

$meb_att_ids = array_filter( array_merge( array( $meb_product->get_image_id() ), $meb_product->get_gallery_image_ids() ) );
foreach ( $meb_att_ids as $meb_att_id ) {
	$meb_src = wp_get_attachment_image_url( (int) $meb_att_id, 'large' );
	if ( $meb_src ) {
		$meb_push( $meb_src );
	}
}

$meb_cached_item = ( '' !== $meb_num_iid )
	? get_transient( 'ppw_item_v3_' . md5( ( $meb_platform ?: 'taobao' ) . '|' . $meb_num_iid ) )
	: false;
if ( is_array( $meb_cached_item ) && ! empty( $meb_cached_item['images'] ) && is_array( $meb_cached_item['images'] ) ) {
	foreach ( $meb_cached_item['images'] as $meb_img ) {
		$meb_push( (string) $meb_img );
	}
}

if ( empty( $meb_images ) ) {
	$meb_push( wc_placeholder_img_src( 'large' ) );
}

// Variations + attribute options for custom swatch / pill UI.
$meb_attributes = array();
$meb_variations = array();
$meb_swatch_img = array();

if ( $meb_is_variable && $meb_product instanceof WC_Product_Variable ) {
	$meb_attributes = $meb_product->get_variation_attributes();

	foreach ( $meb_product->get_children() as $meb_vid ) {
		$meb_var = wc_get_product( $meb_vid );
		if ( ! $meb_var instanceof WC_Product_Variation || ! $meb_var->is_purchasable() ) {
			continue;
		}
		$meb_vimg = $meb_var->get_image_id() ? (string) wp_get_attachment_image_url( (int) $meb_var->get_image_id(), 'large' ) : '';
		$meb_vatt = $meb_var->get_variation_attributes();

		$meb_variations[] = array(
			'variation_id' => $meb_var->get_id(),
			'price'        => (float) $meb_var->get_price(),
			'price_html'   => 'C$' . number_format( (float) $meb_var->get_price(), 2 ),
			'in_stock'     => $meb_var->is_in_stock(),
			'stock_qty'    => $meb_var->get_stock_quantity(),
			'image'        => $meb_vimg,
			'attributes'   => $meb_vatt,
		);

		foreach ( $meb_vatt as $meb_ak => $meb_av ) {
			if ( $meb_vimg && '' !== (string) $meb_av && empty( $meb_swatch_img[ $meb_ak ][ $meb_av ] ) ) {
				$meb_swatch_img[ $meb_ak ][ $meb_av ] = $meb_vimg;
			}
		}
	}
}

$meb_base_price = (float) $meb_product->get_price();
$meb_ships_date = wp_date( 'm/d', time() + 2 * DAY_IN_SECONDS );
$meb_inb_date   = wp_date( 'm/d', time() + 5 * DAY_IN_SECONDS );
$meb_VISIBLE    = 6;
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<main id="content" class="meb-main meb-pdp-main">
	<div class="meb-container">

		<?php if ( function_exists( 'woocommerce_output_all_notices' ) ) : ?>
			<div class="meb-pdp-notices"><?php woocommerce_output_all_notices(); ?></div>
		<?php endif; ?>

		<section class="meb-pdp" data-meb-pdp data-product-id="<?php echo esc_attr( (string) $meb_pid ); ?>" data-platform="<?php echo esc_attr( $meb_platform ?: 'taobao' ); ?>" data-num-iid="<?php echo esc_attr( $meb_num_iid ); ?>" data-variations="<?php echo esc_attr( (string) wp_json_encode( $meb_variations ) ); ?>">

			<!-- Left: vertical thumbs + main image -->
			<div class="meb-pdp__media">
				<div class="meb-pdp__thumbs" data-meb-thumbs>
					<?php foreach ( $meb_images as $meb_i => $meb_img ) : ?>
						<button type="button" class="meb-pdp__thumb<?php echo 0 === $meb_i ? ' is-active' : ''; ?><?php echo $meb_i >= $meb_VISIBLE ? ' is-extra' : ''; ?>" data-meb-thumb="<?php echo esc_url( $meb_img ); ?>"<?php echo $meb_i >= $meb_VISIBLE ? ' hidden' : ''; ?>>
							<img src="<?php echo esc_url( $meb_img ); ?>" alt="" loading="lazy">
						</button>
					<?php endforeach; ?>
					<?php if ( count( $meb_images ) > $meb_VISIBLE ) : ?>
						<button type="button" class="meb-pdp__more-thumbs" data-meb-more-thumbs>
							More<?php echo esc_html( (string) ( count( $meb_images ) - $meb_VISIBLE ) ); ?>
						</button>
					<?php endif; ?>
				</div>

				<div class="meb-pdp__stage">
					<img class="meb-pdp__main-img" data-meb-main-img src="<?php echo esc_url( $meb_images[0] ); ?>" alt="<?php echo esc_attr( $meb_product->get_name() ); ?>">
				</div>
			</div>

			<!-- Right: purchase summary -->
			<div class="meb-pdp__summary">
				<h1 class="meb-pdp__title"><?php echo esc_html( $meb_product->get_name() ); ?></h1>

				<div class="meb-pdp__meta-row">
					<button type="button" class="meb-pdp__refresh" data-meb-refresh>
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 2v6h-6"/><path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M3 22v-6h6"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/></svg>
						<span>刷新</span>
					</button>

					<div class="meb-pdp__meta-right">
						<?php if ( $meb_source_url ) : ?>
							<a class="meb-pdp__source" href="<?php echo esc_url( $meb_source_url ); ?>" target="_blank" rel="noopener noreferrer">Source Link &rsaquo;</a>
						<?php endif; ?>
						<button type="button" class="meb-pdp__share" data-meb-share>
							<span class="meb-pdp__coin" aria-hidden="true">$</span>
							<span>Share &amp; Earn</span>
						</button>
					</div>
				</div>

				<div class="meb-pdp__price" data-meb-price>
					C$<?php echo esc_html( number_format( $meb_base_price, 2 ) ); ?>
				</div>

				<!-- Two-step proxy purchasing explanation -->
				<div class="meb-pdp__flow">
					<div class="meb-pdp__flow-steps">
						<div class="meb-pdp__flow-pill">
							<strong>本次只付商品费</strong>
							<span>中国段</span>
						</div>
						<div class="meb-pdp__flow-mid">
							<span class="meb-pdp__flow-clock"><?php echo meb_icon( 'clock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
							<em>等待入库</em>
						</div>
						<div class="meb-pdp__flow-pill">
							<strong>另支付国际运费</strong>
							<span>跨境段</span>
						</div>
					</div>

					<div class="meb-pdp__flow-ship">
						<span class="meb-pdp__truck" aria-hidden="true">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
						</span>
						<span>集运物流 到库需合包</span>
						<i class="meb-pdp__sep">|</i>
						<a href="<?php echo esc_url( meb_endpoint_url( 'consolidation' ) ); ?>">预估国际运费 &rsaquo;</a>
					</div>

					<p class="meb-pdp__flow-dates">
						发货 <b><?php echo esc_html( $meb_ships_date ); ?></b> , 入库 <b><?php echo esc_html( $meb_inb_date ); ?></b>
					</p>

					<a class="meb-pdp__storage" href="<?php echo esc_url( meb_endpoint_url( 'consolidation' ) ); ?>">
						<span><?php echo meb_icon( 'shield' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> 90 免费仓储</span>
						<span>&rsaquo;</span>
					</a>
				</div>

				<!-- Trust strip -->
				<ul class="meb-pdp__trust">
					<li><?php echo meb_icon( 'shield' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span>支付安全</span></li>
					<li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 14 4 9 9 4"/><path d="M20 20v-7a4 4 0 0 0-4-4H4"/></svg><span>退货政策</span></li>
					<li><?php echo meb_icon( 'doc' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span>免责申明</span></li>
				</ul>

				<form class="meb-pdp__form cart" method="post" action="<?php echo esc_url( $meb_permalink ); ?>" enctype="multipart/form-data" data-meb-cart-form>
					<input type="hidden" name="add-to-cart" value="<?php echo esc_attr( (string) $meb_pid ); ?>">
					<?php if ( $meb_is_variable ) : ?>
						<input type="hidden" name="product_id" value="<?php echo esc_attr( (string) $meb_pid ); ?>">
						<input type="hidden" name="variation_id" value="0" data-meb-variation-id>
					<?php endif; ?>

					<?php if ( $meb_is_variable && ! empty( $meb_attributes ) ) : ?>
						<?php foreach ( $meb_attributes as $meb_attr_name => $meb_options ) : ?>
							<?php
							$meb_field_key = 'attribute_' . sanitize_title( (string) $meb_attr_name );
							$meb_label     = wc_attribute_label( (string) $meb_attr_name, $meb_product );
							$meb_is_color  = (bool) preg_match( '/color|colour|颜色|色|款/iu', $meb_label );
							?>
							<div class="meb-pdp__attr" data-meb-attr-group="<?php echo esc_attr( $meb_field_key ); ?>">
								<div class="meb-pdp__attr-head">
									<span class="meb-pdp__attr-name"><?php echo esc_html( strtoupper( $meb_label ) ); ?></span>
									<span class="meb-pdp__attr-picked" data-meb-attr-picked></span>
								</div>
								<input type="hidden" name="<?php echo esc_attr( $meb_field_key ); ?>" value="" data-meb-attr-input="<?php echo esc_attr( $meb_field_key ); ?>">
								<div class="meb-pdp__swatches">
									<?php foreach ( (array) $meb_options as $meb_idx => $meb_opt ) : ?>
										<?php
										$meb_opt = (string) $meb_opt;
										$meb_sw  = $meb_swatch_img[ $meb_field_key ][ $meb_opt ]
											?? ( $meb_is_color ? ( $meb_images[ $meb_idx % count( $meb_images ) ] ?? '' ) : '' );
										?>
										<button type="button" class="meb-pdp__swatch<?php echo $meb_sw ? ' has-thumb' : ''; ?>" data-meb-swatch="<?php echo esc_attr( $meb_field_key ); ?>" data-value="<?php echo esc_attr( $meb_opt ); ?>"<?php echo $meb_sw ? ' data-image="' . esc_url( $meb_sw ) . '"' : ''; ?>>
											<?php if ( $meb_sw ) : ?>
												<img src="<?php echo esc_url( $meb_sw ); ?>" alt="" loading="lazy">
											<?php endif; ?>
											<span><?php echo esc_html( $meb_opt ); ?></span>
										</button>
									<?php endforeach; ?>
								</div>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>

					<div class="meb-pdp__qty-row">
						<span class="meb-pdp__attr-name">数量</span>
						<div class="meb-pdp__qty">
							<button type="button" data-meb-qty="-1" aria-label="Decrease quantity">&minus;</button>
							<input type="number" name="quantity" value="1" min="1" max="999" step="1" data-meb-qty-input>
							<button type="button" data-meb-qty="1" aria-label="Increase quantity">+</button>
						</div>
					</div>

					<div class="meb-pdp__note-row">
						<label class="meb-pdp__attr-name" for="meb-order-note">订单留言</label>
						<textarea id="meb-order-note" name="meb_item_note" rows="2" placeholder="Optional: leave a note for our purchasing agent (size/color details, packaging requests, etc.)"></textarea>
					</div>

					<!-- Floating action bar: stays on-screen while browsing this product. -->
					<div class="meb-pdp__cta-card" role="region" aria-label="Purchase actions">
						<button type="submit" name="meb_action" value="cart" class="meb-pdp__btn meb-pdp__btn--cart single_add_to_cart_button" data-meb-add-cart>
							加入购物车
						</button>
						<button type="submit" name="meb_buy_now" value="1" class="meb-pdp__btn meb-pdp__btn--buy single_add_to_cart_button" data-meb-buy-now>
							立刻购买
						</button>
						<button type="button" class="meb-pdp__fav" data-meb-fav aria-pressed="false" aria-label="Favorite">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
							<span>收藏商品</span>
						</button>
					</div>
				</form>
			</div>

		</section>

		<!-- Product description / images below -->
		<section class="meb-pdp-details">
			<div class="meb-pdp-details__head">
				<h2>Product Details</h2>
			</div>
			<div class="meb-pdp-details__body">
				<?php
				$meb_desc = $meb_product->get_description();
				if ( '' !== trim( $meb_desc ) ) {
					echo wp_kses_post( wpautop( $meb_desc ) );
				} else {
					echo '<p class="meb-pdp-details__empty">No additional description provided by the seller.</p>';
				}
				?>
			</div>
		</section>

	</div>
</main>

<?php wp_footer(); ?>
</body>
</html>
