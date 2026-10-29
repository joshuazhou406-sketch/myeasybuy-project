<?php
/**
 * Account orders list.
 *
 * Variables are set by MyEasyBuy_Customer_Orders::render_endpoint().
 *
 * @package MyEasyBuy_Core
 *
 * @var array{tab: string, q: string, status: string, from: string, to: string, active: bool} $filters
 * @var array{orders: WC_Order[], total: int, pages: int}                                    $result
 * @var array<string, string>                                                                $tabs
 * @var array<string, string>                                                                $statuses
 * @var int                                                                                  $page
 */

defined( 'ABSPATH' ) || exit;

$steps    = MyEasyBuy_Customer_Orders::flow_steps();
$orders   = $result['orders'];
$is_empty = empty( $orders );
?>
<div class="meb-orders">
	<ol class="meb-orders__flow" aria-label="<?php esc_attr_e( '订单流程', 'myeasybuy-core' ); ?>">
		<?php foreach ( $steps as $index => $step ) : ?>
			<?php if ( $index > 0 ) : ?>
				<li class="meb-orders__flow-arrow" aria-hidden="true">
					<svg viewBox="0 0 20 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M1 1.5 6 6 1 10.5M11 1.5 16 6 11 10.5"/></svg>
				</li>
			<?php endif; ?>
			<li class="meb-orders__flow-step">
				<span class="meb-orders__flow-num"><?php echo esc_html( $step['num'] ); ?></span>
				<span class="meb-orders__flow-label"><?php echo esc_html( $step['label'] ); ?></span>
			</li>
		<?php endforeach; ?>
	</ol>

	<nav class="meb-orders__tabs" aria-label="<?php esc_attr_e( '订单分类', 'myeasybuy-core' ); ?>">
		<?php foreach ( $tabs as $key => $label ) : ?>
			<a class="meb-orders__tab<?php echo $filters['tab'] === $key ? ' is-active' : ''; ?>" href="<?php echo esc_url( MyEasyBuy_Customer_Orders::page_url( array( 'tab' => $key ), 1 ) ); ?>"<?php echo $filters['tab'] === $key ? ' aria-current="page"' : ''; ?>>
				<?php echo esc_html( $label ); ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<form class="meb-orders__filters" method="get" action="<?php echo esc_url( wc_get_account_endpoint_url( 'orders' ) ); ?>">
		<?php if ( 'all' !== $filters['tab'] ) : ?>
			<input type="hidden" name="meb_tab" value="<?php echo esc_attr( $filters['tab'] ); ?>">
		<?php endif; ?>

		<label class="meb-orders__search">
			<span class="meb-orders__icon" aria-hidden="true">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
			</span>
			<input type="search" name="meb_q" value="<?php echo esc_attr( $filters['q'] ); ?>" placeholder="<?php esc_attr_e( '名称/订单号/商品名称', 'myeasybuy-core' ); ?>" aria-label="<?php esc_attr_e( '名称/订单号/商品名称', 'myeasybuy-core' ); ?>">
		</label>

		<label class="meb-orders__select">
			<select class="<?php echo '' !== $filters['status'] ? 'has-value' : ''; ?>" name="meb_status" aria-label="<?php esc_attr_e( '订单状态', 'myeasybuy-core' ); ?>">
				<option value=""><?php esc_html_e( '订单状态', 'myeasybuy-core' ); ?></option>
				<?php foreach ( $statuses as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>"<?php selected( $filters['status'], $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>

		<div class="meb-orders__dates">
			<span class="meb-orders__icon" aria-hidden="true">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg>
			</span>
			<input type="text" name="meb_from" value="<?php echo esc_attr( $filters['from'] ); ?>" placeholder="<?php esc_attr_e( '开始日期', 'myeasybuy-core' ); ?>" aria-label="<?php esc_attr_e( '开始日期', 'myeasybuy-core' ); ?>" inputmode="numeric" data-meb-date autocomplete="off">
			<span class="meb-orders__dates-sep" aria-hidden="true">-</span>
			<input type="text" name="meb_to" value="<?php echo esc_attr( $filters['to'] ); ?>" placeholder="<?php esc_attr_e( '结束日期', 'myeasybuy-core' ); ?>" aria-label="<?php esc_attr_e( '结束日期', 'myeasybuy-core' ); ?>" inputmode="numeric" data-meb-date autocomplete="off">
		</div>

		<button type="submit" class="meb-orders__submit"><?php esc_html_e( '搜索', 'myeasybuy-core' ); ?></button>
	</form>

	<?php if ( $is_empty ) : ?>
		<div class="meb-orders__empty">
			<div class="meb-orders__clip" aria-hidden="true">
				<svg viewBox="0 0 140 150">
					<circle cx="70" cy="28" r="14" fill="#d7dde8"/>
					<path d="M52 46c2-8 10-12 18-12s16 4 18 12v6H52z" fill="#d7dde8"/>
					<rect x="34" y="48" width="72" height="86" rx="12" fill="#e6ebf3"/>
					<rect x="54" y="40" width="32" height="16" rx="5" fill="#cfd6e2"/>
					<rect x="48" y="74" width="44" height="7" rx="3.5" fill="#f4f7fb"/>
					<rect x="48" y="90" width="34" height="7" rx="3.5" fill="#f4f7fb"/>
					<rect x="48" y="106" width="38" height="7" rx="3.5" fill="#f4f7fb"/>
					<circle cx="96" cy="118" r="16" fill="#ff7e21"/>
					<path d="M96 108v12" stroke="#fff" stroke-width="3" stroke-linecap="round"/>
					<circle cx="96" cy="125" r="1.8" fill="#fff"/>
				</svg>
			</div>
			<p>
				<?php
				echo esc_html(
					$filters['active']
						? __( '没有符合条件的订单', 'myeasybuy-core' )
						: __( '目前没有订单', 'myeasybuy-core' )
				);
				?>
			</p>
		</div>
	<?php else : ?>
		<ul class="meb-orders__list">
			<?php foreach ( $orders as $order ) : ?>
				<?php
				$items      = $order->get_items();
				$first      = $items ? reset( $items ) : null;
				$thumb      = '';
				$first_name = '';
				if ( $first instanceof WC_Order_Item_Product ) {
					$product    = $first->get_product();
					$first_name = $first->get_name();
					if ( $product instanceof WC_Product ) {
						$thumb = $product->get_image( 'woocommerce_thumbnail', array( 'class' => 'meb-orders__thumb' ) );
					}
				}
				$count = $order->get_item_count() - $order->get_item_count_refunded();
				$view  = $order->get_view_order_url();
				?>
				<li class="meb-orders__card">
					<a class="meb-orders__media" href="<?php echo esc_url( $view ); ?>">
						<?php
						if ( $thumb ) {
							echo wp_kses_post( $thumb );
						}
						?>
					</a>
					<div class="meb-orders__info">
						<a class="meb-orders__name" href="<?php echo esc_url( $view ); ?>"><?php echo esc_html( $first_name ? $first_name : sprintf( /* translators: %s: order number */ __( '订单 %s', 'myeasybuy-core' ), $order->get_order_number() ) ); ?></a>
						<p class="meb-orders__meta">
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: order number, 2: date */
									__( '订单号 %1$s · %2$s', 'myeasybuy-core' ),
									$order->get_order_number(),
									$order->get_date_created() ? wc_format_datetime( $order->get_date_created(), 'Y-m-d' ) : ''
								)
							);
							?>
						</p>
						<?php if ( $count > 1 ) : ?>
							<p class="meb-orders__meta">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %d: item count */
										__( '共 %d 件商品', 'myeasybuy-core' ),
										$count
									)
								);
								?>
							</p>
						<?php endif; ?>
					</div>
					<div class="meb-orders__side">
						<span class="meb-orders__badge"><?php echo esc_html( MyEasyBuy_Customer_Orders::status_label( $order ) ); ?></span>
						<strong class="meb-orders__total"><?php echo esc_html( MyEasyBuy_Customer_Orders::format_total( $order ) ); ?></strong>
						<a class="meb-orders__view" href="<?php echo esc_url( $view ); ?>"><?php esc_html_e( '查看', 'myeasybuy-core' ); ?></a>
						<?php if ( $order->needs_payment() ) : ?>
							<a class="meb-orders__pay" href="<?php echo esc_url( $order->get_checkout_payment_url() ); ?>"><?php esc_html_e( '去付款', 'myeasybuy-core' ); ?></a>
						<?php endif; ?>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>

		<?php if ( $result['pages'] > 1 ) : ?>
			<nav class="meb-orders__pager" aria-label="<?php esc_attr_e( '订单分页', 'myeasybuy-core' ); ?>">
				<?php if ( $page > 1 ) : ?>
					<a href="<?php echo esc_url( MyEasyBuy_Customer_Orders::page_url( array(), $page - 1 ) ); ?>"><?php esc_html_e( '上一页', 'myeasybuy-core' ); ?></a>
				<?php endif; ?>
				<span><?php echo esc_html( sprintf( '%d / %d', $page, $result['pages'] ) ); ?></span>
				<?php if ( $page < $result['pages'] ) : ?>
					<a href="<?php echo esc_url( MyEasyBuy_Customer_Orders::page_url( array(), $page + 1 ) ); ?>"><?php esc_html_e( '下一页', 'myeasybuy-core' ); ?></a>
				<?php endif; ?>
			</nav>
		<?php endif; ?>
	<?php endif; ?>
</div>
