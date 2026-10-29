<?php
/**
 * Shared site footer, floating dock and sign-up bar.
 *
 * @package MyEasyBuy_Home
 */

defined( 'ABSPATH' ) || exit;

$meb_u = meb_urls();
?>
<footer class="meb-footer">
	<div class="meb-container">
		<div class="meb-footer__cols">
			<div class="meb-footer__col">
				<h6>客户服务</h6>
				<ul>
					<li>服务时间：09:00 - 21:30 (UTC+8)</li>
					<?php if ( $meb_u['email'] ) : ?>
						<li><a href="mailto:<?php echo esc_attr( $meb_u['email'] ); ?>">Email: <?php echo esc_html( $meb_u['email'] ); ?></a></li>
					<?php endif; ?>
				</ul>
			</div>
			<div class="meb-footer__col">
				<h6>代购指南</h6>
				<ul>
					<li><a href="<?php echo esc_url( $meb_u['shop'] ); ?>">浏览商品</a></li>
					<li><a href="<?php echo esc_url( home_url( '/#meb-search' ) ); ?>" data-meb-focus-search>搜索 / 粘贴链接下单</a></li>
					<li><a href="<?php echo esc_url( $meb_u['register'] ); ?>">注册账户</a></li>
				</ul>
			</div>
			<div class="meb-footer__col">
				<h6>订单与配送</h6>
				<ul>
					<li><a href="<?php echo esc_url( meb_endpoint_url( 'orders' ) ); ?>">我的订单</a></li>
					<li><a href="<?php echo esc_url( meb_endpoint_url( 'consolidation' ) ); ?>">合箱发货</a></li>
					<li><a href="<?php echo esc_url( $meb_u['forward'] ); ?>">转运中心</a></li>
				</ul>
			</div>
			<div class="meb-footer__col">
				<h6>购物</h6>
				<ul>
					<li><a href="<?php echo esc_url( $meb_u['cart'] ); ?>">购物车</a></li>
					<li><a href="<?php echo esc_url( $meb_u['checkout'] ); ?>">结算</a></li>
					<li><a href="<?php echo esc_url( $meb_u['account'] ); ?>">个人中心</a></li>
				</ul>
			</div>
			<div class="meb-footer__col">
				<h6>关于</h6>
				<ul>
					<li><a href="<?php echo esc_url( home_url( '/' ) ); ?>">关于 <?php echo esc_html( get_bloginfo( 'name' ) ); ?></a></li>
					<?php if ( get_privacy_policy_url() ) : ?>
						<li><a href="<?php echo esc_url( get_privacy_policy_url() ); ?>">隐私政策</a></li>
					<?php endif; ?>
				</ul>
			</div>
		</div>

		<div class="meb-footer__brand">
			<div>
				<?php meb_logo(); ?>
				<p><?php echo esc_html( get_bloginfo( 'name' ) ); ?> 提供中国商品代购与转运服务，通过高效物流与以用户为中心的服务，持续降低跨境购物门槛。</p>
			</div>
			<ul class="meb-pay" aria-label="支付方式">
				<li>PayPal</li>
				<li>VISA</li>
				<li>Mastercard</li>
				<li>Amex</li>
				<li>Apple Pay</li>
				<li>Google Pay</li>
			</ul>
		</div>

		<div class="meb-footer__bottom">
			<span>&copy; <?php echo esc_html( wp_date( 'Y' ) . ' ' . get_bloginfo( 'name' ) ); ?> 版权所有</span>
		</div>
	</div>
</footer>

<aside class="meb-dock" aria-label="快捷工具">
	<div class="meb-dock__clock">
		<strong>UTC+8</strong>
		<span data-meb-clock-date></span>
		<span data-meb-clock-time></span>
	</div>
	<a href="<?php echo esc_url( $meb_u['cart'] ); ?>" aria-label="购物车"><?php echo meb_icon( 'cart' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
	<a href="<?php echo esc_url( $meb_u['account'] ); ?>" aria-label="个人中心"><?php echo meb_icon( 'user' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
	<?php if ( $meb_u['email'] ) : ?>
		<a href="mailto:<?php echo esc_attr( $meb_u['email'] ); ?>" aria-label="联系客服"><?php echo meb_icon( 'help' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
	<?php endif; ?>
	<button type="button" data-meb-top aria-label="回到顶部"><?php echo meb_icon( 'top' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
</aside>

<?php if ( ! is_user_logged_in() && ! ( function_exists( 'is_checkout' ) && ( is_checkout() || is_account_page() ) ) ) : ?>
	<div class="meb-signup" data-meb-signup hidden>
		<div class="meb-container meb-signup__inner">
			<?php meb_logo(); ?>
			<div class="meb-signup__copy">
				<strong>注册有礼，马上开始海淘！</strong>
				<span>注册 <?php echo esc_html( get_bloginfo( 'name' ) ); ?> 账户，即可下单代购、合箱发货。</span>
			</div>
			<a class="meb-signup__cta" href="<?php echo esc_url( $meb_u['register'] ); ?>">立即注册</a>
			<button type="button" class="meb-signup__close" data-meb-signup-close aria-label="关闭提示">&times;</button>
		</div>
	</div>
<?php endif; ?>
