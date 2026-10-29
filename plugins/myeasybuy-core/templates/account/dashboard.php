<?php
/**
 * Account overview.
 *
 * Variables are set by MyEasyBuy_Customer_Dashboard::render().
 *
 * @package MyEasyBuy_Core
 *
 * @var string $letter
 * @var string $name
 * @var string $public
 * @var string $cad
 * @var string $cny
 * @var string $wallet
 * @var string $recharge
 * @var string $coupon_url
 * @var string $profile_url
 * @var string $cart_url
 * @var array<int, array{title: string, amount: string, rule: string, expires: string}> $coupons
 * @var array<int, array{name: string, url: string, image: string, price: string}> $cart_items
 * @var array{unpaid?: int, confirm?: int, shipping?: int} $orders
 * @var array{ready?: int, hold?: int} $warehouse
 * @var array{supplement?: int, unpaid?: int, shipping?: int} $parcels
 * @var array{unpaid: int, processing: int, completed: int} $rehearsal
 */

defined( 'ABSPATH' ) || exit;

$order_url = class_exists( 'MyEasyBuy_Customer_Orders' ) ? wc_get_account_endpoint_url( 'orders' ) : '';
$warehouse_url = class_exists( 'MyEasyBuy_Customer_Warehouse' ) ? MyEasyBuy_Customer_Warehouse::get_endpoint_url() : '';
$parcel_url = class_exists( 'MyEasyBuy_Customer_Parcels' ) ? MyEasyBuy_Customer_Parcels::get_endpoint_url() : '';
$rehearsal_url = class_exists( 'MyEasyBuy_Customer_Rehearsal' ) ? MyEasyBuy_Customer_Rehearsal::get_endpoint_url() : '';

$order_stats = array(
	array(
		'label' => __( '待付款', 'myeasybuy-core' ),
		'count' => (int) ( $orders['unpaid'] ?? 0 ),
		'url'   => class_exists( 'MyEasyBuy_Customer_Orders' ) ? MyEasyBuy_Customer_Orders::page_url( array( 'tab' => 'unpaid' ) ) : $order_url,
	),
	array(
		'label' => __( '待确认', 'myeasybuy-core' ),
		'count' => (int) ( $orders['confirm'] ?? 0 ),
		'url'   => class_exists( 'MyEasyBuy_Customer_Orders' ) ? MyEasyBuy_Customer_Orders::page_url( array( 'tab' => 'confirm' ) ) : $order_url,
	),
	array(
		'label' => __( '派送中', 'myeasybuy-core' ),
		'count' => (int) ( $orders['shipping'] ?? 0 ),
		'url'   => class_exists( 'MyEasyBuy_Customer_Orders' ) ? MyEasyBuy_Customer_Orders::page_url( array( 'tab' => 'shipping' ) ) : $order_url,
	),
);

$warehouse_stats = array(
	array(
		'label' => __( '可创建包裹', 'myeasybuy-core' ),
		'count' => (int) ( $warehouse['ready'] ?? 0 ),
		'url'   => class_exists( 'MyEasyBuy_Customer_Warehouse' ) ? MyEasyBuy_Customer_Warehouse::page_url( array( 'tab' => 'ready' ) ) : $warehouse_url,
	),
	array(
		'label' => __( '暂不可创建包裹', 'myeasybuy-core' ),
		'count' => (int) ( $warehouse['hold'] ?? 0 ),
		'url'   => class_exists( 'MyEasyBuy_Customer_Warehouse' ) ? MyEasyBuy_Customer_Warehouse::page_url( array( 'tab' => 'hold' ) ) : $warehouse_url,
	),
);

$parcel_stats = array(
	array(
		'label' => __( '待补款', 'myeasybuy-core' ),
		'count' => (int) ( $parcels['supplement'] ?? 0 ),
		'url'   => class_exists( 'MyEasyBuy_Customer_Parcels' ) ? MyEasyBuy_Customer_Parcels::page_url( array( 'tab' => 'supplement' ) ) : $parcel_url,
	),
	array(
		'label' => __( '待付款', 'myeasybuy-core' ),
		'count' => (int) ( $parcels['unpaid'] ?? 0 ),
		'url'   => class_exists( 'MyEasyBuy_Customer_Parcels' ) ? MyEasyBuy_Customer_Parcels::page_url( array( 'tab' => 'unpaid' ) ) : $parcel_url,
	),
	array(
		'label' => __( '寄送中', 'myeasybuy-core' ),
		'count' => (int) ( $parcels['shipping'] ?? 0 ),
		'url'   => class_exists( 'MyEasyBuy_Customer_Parcels' ) ? MyEasyBuy_Customer_Parcels::page_url( array( 'tab' => 'shipping' ) ) : $parcel_url,
	),
);

$rehearsal_stats = array(
	array(
		'label' => __( '待支付', 'myeasybuy-core' ),
		'count' => (int) $rehearsal['unpaid'],
		'url'   => class_exists( 'MyEasyBuy_Customer_Rehearsal' ) ? MyEasyBuy_Customer_Rehearsal::page_url( array( 'tab' => 'unpaid' ) ) : $rehearsal_url,
	),
	array(
		'label' => __( '处理中', 'myeasybuy-core' ),
		'count' => (int) $rehearsal['processing'],
		'url'   => class_exists( 'MyEasyBuy_Customer_Rehearsal' ) ? MyEasyBuy_Customer_Rehearsal::page_url( array( 'tab' => 'processing' ) ) : $rehearsal_url,
	),
	array(
		'label' => __( '已完成', 'myeasybuy-core' ),
		'count' => (int) $rehearsal['completed'],
		'url'   => class_exists( 'MyEasyBuy_Customer_Rehearsal' ) ? MyEasyBuy_Customer_Rehearsal::page_url( array( 'tab' => 'completed' ) ) : $rehearsal_url,
	),
);

$shown_coupons = array_slice( $coupons, 0, 3 );
?>
<div class="meb-overview">
	<div class="meb-overview__hero">
		<a class="meb-overview__profile" href="<?php echo esc_url( $profile_url ); ?>">
			<span class="meb-overview__avatar" aria-hidden="true"><?php echo esc_html( function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $letter ) : strtoupper( $letter ) ); ?></span>
			<span class="meb-overview__who">
				<strong><?php echo esc_html( $name ); ?></strong>
				<span><?php echo esc_html( sprintf( /* translators: %s: customer code */ __( 'Id: %s', 'myeasybuy-core' ), $public ) ); ?></span>
			</span>
		</a>

		<section class="meb-overview__balance" aria-label="<?php esc_attr_e( '余额', 'myeasybuy-core' ); ?>">
			<a class="meb-overview__balance-main" href="<?php echo esc_url( $wallet ); ?>">
				<span><?php esc_html_e( '余额', 'myeasybuy-core' ); ?></span>
				<strong>C$<?php echo esc_html( $cad ); ?></strong>
				<em>CNY <?php echo esc_html( $cny ); ?></em>
			</a>
			<p class="meb-overview__balance-links">
				<a href="<?php echo esc_url( $recharge ); ?>"><?php esc_html_e( '充值', 'myeasybuy-core' ); ?></a>
				<a href="<?php echo esc_url( $wallet ); ?>"><?php esc_html_e( '详情', 'myeasybuy-core' ); ?></a>
			</p>
		</section>
	</div>

	<section class="meb-overview__card">
		<header class="meb-overview__head">
			<h2><?php esc_html_e( '优惠券', 'myeasybuy-core' ); ?></h2>
			<a href="<?php echo esc_url( $coupon_url ); ?>"><?php esc_html_e( '全部', 'myeasybuy-core' ); ?></a>
		</header>
		<?php if ( empty( $shown_coupons ) ) : ?>
			<p class="meb-overview__empty"><?php esc_html_e( '暂无优惠券', 'myeasybuy-core' ); ?></p>
		<?php else : ?>
			<ul class="meb-overview__coupons">
				<?php foreach ( $shown_coupons as $coupon ) : ?>
					<li>
						<a href="<?php echo esc_url( $coupon_url ); ?>">
							<strong><?php echo esc_html( $coupon['amount'] ); ?></strong>
							<span><?php echo esc_html( $coupon['title'] ); ?></span>
							<?php if ( '' !== $coupon['rule'] ) : ?>
								<em><?php echo esc_html( $coupon['rule'] ); ?></em>
							<?php endif; ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</section>

	<section class="meb-overview__card">
		<header class="meb-overview__head">
			<h2><a href="<?php echo esc_url( $cart_url ); ?>"><?php esc_html_e( '我的购物车', 'myeasybuy-core' ); ?></a></h2>
		</header>
		<?php if ( empty( $cart_items ) ) : ?>
			<p class="meb-overview__empty"><?php esc_html_e( '购物车是空的', 'myeasybuy-core' ); ?></p>
		<?php else : ?>
			<ul class="meb-overview__cart">
				<?php foreach ( $cart_items as $item ) : ?>
					<li>
						<?php if ( '' !== $item['url'] ) : ?>
							<a class="meb-overview__media" href="<?php echo esc_url( $item['url'] ); ?>"><?php echo wp_kses_post( $item['image'] ); ?></a>
						<?php else : ?>
							<span class="meb-overview__media"><?php echo wp_kses_post( $item['image'] ); ?></span>
						<?php endif; ?>
						<div>
							<?php if ( '' !== $item['url'] ) : ?>
								<a class="meb-overview__name" href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['name'] ); ?></a>
							<?php else : ?>
								<span class="meb-overview__name"><?php echo esc_html( $item['name'] ); ?></span>
							<?php endif; ?>
							<p class="meb-overview__price"><?php echo esc_html( $item['price'] ); ?></p>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</section>

	<section class="meb-overview__card">
		<header class="meb-overview__head">
			<h2><?php esc_html_e( '购物历程', 'myeasybuy-core' ); ?></h2>
		</header>
		<div class="meb-overview__journey">
			<?php
			$groups = array(
				array(
					'title' => __( '我的订单', 'myeasybuy-core' ),
					'url'   => $order_url,
					'stats' => $order_stats,
				),
				array(
					'title' => __( '我的仓库', 'myeasybuy-core' ),
					'url'   => $warehouse_url,
					'stats' => $warehouse_stats,
				),
				array(
					'title' => __( '我的包裹', 'myeasybuy-core' ),
					'url'   => $parcel_url,
					'stats' => $parcel_stats,
				),
			);
			foreach ( $groups as $group ) :
				?>
				<div class="meb-overview__group">
					<h3><a href="<?php echo esc_url( $group['url'] ); ?>"><?php echo esc_html( $group['title'] ); ?></a></h3>
					<ul>
						<?php foreach ( $group['stats'] as $stat ) : ?>
							<li>
								<a href="<?php echo esc_url( $stat['url'] ); ?>">
									<strong><?php echo esc_html( (string) $stat['count'] ); ?></strong>
									<span><?php echo esc_html( $stat['label'] ); ?></span>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endforeach; ?>
		</div>
	</section>

	<section class="meb-overview__card meb-overview__card--rehearsal">
		<header class="meb-overview__head">
			<h2><a href="<?php echo esc_url( $rehearsal_url ); ?>"><?php esc_html_e( '包裹预演', 'myeasybuy-core' ); ?></a></h2>
			<a href="<?php echo esc_url( $rehearsal_url ); ?>"><?php esc_html_e( '详情', 'myeasybuy-core' ); ?></a>
		</header>
		<ul class="meb-overview__stats">
			<?php foreach ( $rehearsal_stats as $stat ) : ?>
				<li>
					<a href="<?php echo esc_url( $stat['url'] ); ?>">
						<strong><?php echo esc_html( (string) $stat['count'] ); ?></strong>
						<span><?php echo esc_html( $stat['label'] ); ?></span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	</section>
</div>
