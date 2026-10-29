<?php
/**
 * Account cart layout.
 *
 * Variables are set by MyEasyBuy_Customer_Cart::render_endpoint().
 *
 * @package MyEasyBuy_Core
 *
 * @var array<int, string>               $steps
 * @var array<int, array<string, mixed>> $items
 * @var array{retail: float, discount: float, total: float} $totals
 * @var bool                             $is_empty
 * @var string                           $shop_url
 * @var string                           $checkout
 * @var string                           $estimate
 */

defined( 'ABSPATH' ) || exit;

$brand = get_bloginfo( 'name' );
?>
<div class="meb-cart<?php echo $is_empty ? ' is-empty' : ' is-filled'; ?>">
	<h1 class="meb-cart__title"><?php esc_html_e( '购物车', 'myeasybuy-core' ); ?></h1>

	<ol class="meb-cart__steps" aria-label="<?php esc_attr_e( '购物流程', 'myeasybuy-core' ); ?>">
		<?php foreach ( $steps as $index => $label ) : ?>
			<?php $current = 0 === $index; ?>
			<li class="meb-cart__step<?php echo $current ? ' is-current' : ''; ?>"<?php echo $current ? ' aria-current="step"' : ''; ?>>
				<span class="meb-cart__step-num"><?php echo esc_html( (string) ( $index + 1 ) ); ?></span>
				<span class="meb-cart__step-label"><?php echo esc_html( $label ); ?></span>
			</li>
		<?php endforeach; ?>
	</ol>

	<div class="meb-cart__layout">
		<div class="meb-cart__main">
			<?php if ( $is_empty ) : ?>
				<div class="meb-cart__empty">
					<div class="meb-cart__bag" aria-hidden="true">
						<svg viewBox="0 0 200 220" class="meb-cart__bag-svg">
							<ellipse cx="100" cy="206" rx="48" ry="8" fill="rgba(20,20,20,0.06)"/>
							<path d="M68 96V74a32 32 0 0 1 64 0v22" fill="none" stroke="#d5d9e0" stroke-width="5" stroke-linecap="round"/>
							<path d="M44 90h112l-11 108a12 12 0 0 1-12 10H67a12 12 0 0 1-12-10L44 90z" fill="#fff" stroke="#e6e8ee" stroke-width="1.5"/>
						</svg>
						<span class="meb-cart__brand"><?php echo esc_html( $brand ); ?></span>
					</div>
					<p>
						<a href="<?php echo esc_url( $shop_url ); ?>"><?php esc_html_e( '您的购物车目前是空的。快去购物犒劳一下自己吧！', 'myeasybuy-core' ); ?></a>
					</p>
				</div>
			<?php else : ?>
				<form class="meb-cart__form" method="post" action="<?php echo esc_url( MyEasyBuy_Customer_Cart::get_endpoint_url() ); ?>">
					<?php wp_nonce_field( 'woocommerce-cart', 'woocommerce-cart-nonce' ); ?>
					<input type="hidden" name="update_cart" value="1">
					<ul class="meb-cart__items">
						<?php foreach ( $items as $item ) : ?>
							<li class="meb-cart__item">
								<?php if ( $item['url'] ) : ?>
									<a class="meb-cart__media" href="<?php echo esc_url( (string) $item['url'] ); ?>">
										<?php echo wp_kses_post( (string) $item['image'] ); ?>
									</a>
								<?php else : ?>
									<span class="meb-cart__media"><?php echo wp_kses_post( (string) $item['image'] ); ?></span>
								<?php endif; ?>

								<div class="meb-cart__info">
									<?php if ( $item['url'] ) : ?>
										<a class="meb-cart__name" href="<?php echo esc_url( (string) $item['url'] ); ?>"><?php echo esc_html( (string) $item['name'] ); ?></a>
									<?php else : ?>
										<span class="meb-cart__name"><?php echo esc_html( (string) $item['name'] ); ?></span>
									<?php endif; ?>

									<?php if ( ! empty( $item['meta'] ) ) : ?>
										<div class="meb-cart__meta"><?php echo wp_kses_post( (string) $item['meta'] ); ?></div>
									<?php endif; ?>

									<?php if ( '' !== $item['note'] ) : ?>
										<p class="meb-cart__note"><?php echo esc_html( sprintf( /* translators: %s: shopper note */ __( '备注：%s', 'myeasybuy-core' ), (string) $item['note'] ) ); ?></p>
									<?php endif; ?>

									<div class="meb-cart__controls">
										<?php if ( ! empty( $item['sold_individually'] ) ) : ?>
											<input type="hidden" name="cart[<?php echo esc_attr( (string) $item['key'] ); ?>][qty]" value="1">
											<span class="meb-cart__qty-fixed">× 1</span>
										<?php else : ?>
											<div class="meb-cart__qty">
												<button type="button" class="meb-cart__qty-btn" data-meb-qty="-1" aria-label="<?php esc_attr_e( '减少数量', 'myeasybuy-core' ); ?>">−</button>
												<input
													type="number"
													name="cart[<?php echo esc_attr( (string) $item['key'] ); ?>][qty]"
													value="<?php echo esc_attr( (string) $item['qty'] ); ?>"
													min="1"
													<?php if ( (int) $item['max'] > 0 ) : ?>
														max="<?php echo esc_attr( (string) $item['max'] ); ?>"
													<?php endif; ?>
													step="1"
													inputmode="numeric"
													aria-label="<?php esc_attr_e( '数量', 'myeasybuy-core' ); ?>"
												>
												<button type="button" class="meb-cart__qty-btn" data-meb-qty="1" aria-label="<?php esc_attr_e( '增加数量', 'myeasybuy-core' ); ?>">+</button>
											</div>
										<?php endif; ?>
										<a class="meb-cart__remove" href="<?php echo esc_url( (string) $item['remove_url'] ); ?>"><?php esc_html_e( '删除', 'myeasybuy-core' ); ?></a>
									</div>
								</div>

								<span class="meb-cart__line-price"><?php echo esc_html( (string) $item['price'] ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				</form>
			<?php endif; ?>
		</div>

		<aside class="meb-cart__summary" aria-label="<?php esc_attr_e( '订单摘要', 'myeasybuy-core' ); ?>">
			<h2 class="meb-cart__summary-title"><?php esc_html_e( '订单摘要', 'myeasybuy-core' ); ?></h2>
			<p class="meb-cart__summary-note"><?php esc_html_e( '最终价格将在下一页确认', 'myeasybuy-core' ); ?></p>

			<div class="meb-cart__row">
				<span><?php esc_html_e( '零售价:', 'myeasybuy-core' ); ?></span>
				<span class="is-price"><?php echo esc_html( MyEasyBuy_Customer_Cart::money( (float) $totals['retail'] ) ); ?></span>
			</div>
			<div class="meb-cart__row">
				<span><?php esc_html_e( '折扣:', 'myeasybuy-core' ); ?></span>
				<span class="is-price"><?php echo esc_html( MyEasyBuy_Customer_Cart::money( (float) $totals['discount'], true ) ); ?></span>
			</div>
			<div class="meb-cart__row meb-cart__row--total">
				<span><?php esc_html_e( '预计总额:', 'myeasybuy-core' ); ?></span>
				<span class="is-price"><?php echo esc_html( MyEasyBuy_Customer_Cart::money( (float) $totals['total'] ) ); ?></span>
			</div>
			<p class="meb-cart__saved">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: formatted amount */
						__( '已节省: %s', 'myeasybuy-core' ),
						MyEasyBuy_Customer_Cart::money( (float) $totals['discount'] )
					)
				);
				?>
			</p>
			<p class="meb-cart__ship"><?php esc_html_e( '不包括国际运费', 'myeasybuy-core' ); ?></p>

			<?php if ( $is_empty ) : ?>
				<span class="meb-cart__submit is-disabled" aria-disabled="true"><?php esc_html_e( '提交', 'myeasybuy-core' ); ?></span>
			<?php else : ?>
				<a class="meb-cart__submit" href="<?php echo esc_url( $checkout ); ?>"><?php esc_html_e( '提交', 'myeasybuy-core' ); ?></a>
			<?php endif; ?>

			<div class="meb-cart__tip">
				<strong><?php esc_html_e( '温馨提示', 'myeasybuy-core' ); ?></strong>
				<p>
					<?php esc_html_e( '此价格反映的是平台上产品的成本，不包括国际运费。您可以直接在', 'myeasybuy-core' ); ?>
					<a href="<?php echo esc_url( $estimate ); ?>"><?php esc_html_e( '运费估算页面', 'myeasybuy-core' ); ?></a><?php esc_html_e( '查看。', 'myeasybuy-core' ); ?>
				</p>
			</div>
		</aside>
	</div>
</div>
