<?php
/**
 * Shared site header.
 *
 * @package MyEasyBuy_Home
 */

defined( 'ABSPATH' ) || exit;

$meb_u        = meb_urls();
$meb_is_home  = is_front_page();
$meb_count    = meb_cart_count();
$meb_platform = class_exists( 'PPW_Search' ) ? PPW_Search::default_platform() : 'taobao';
// Off the homepage the keyword is handed to the homepage search (ppw_q / ppw_platform).
$meb_attr     = $meb_is_home ? 'data-ppw-platform' : 'data-meb-platform';
?>
<header class="meb-header">
	<div class="meb-container meb-header__inner">
		<?php meb_logo(); ?>

		<div id="meb-search" class="meb-search<?php echo $meb_is_home ? ' header-search-form' : ''; ?>">
			<form <?php echo $meb_is_home ? 'name="product-search" ' : ''; ?>class="meb-search__form" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
				<span class="meb-search__icon"><?php echo meb_icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<?php if ( $meb_is_home ) : ?>
					<input type="search" name="s" class="meb-search__input header-search-input" placeholder="输入商品名称/链接" autocomplete="off" aria-label="搜索商品">
				<?php else : ?>
					<input type="search" name="ppw_q" class="meb-search__input" placeholder="输入商品名称/粘贴淘宝、京东链接" autocomplete="off" aria-label="搜索商品" required minlength="2">
					<input type="hidden" name="ppw_platform" value="<?php echo esc_attr( $meb_platform ); ?>" data-meb-platform-input>
				<?php endif; ?>
				<span class="meb-search__platforms" role="tablist" aria-label="商品平台">
					<?php foreach ( array( 'taobao' => array( '淘', '淘宝' ), 'jd' => array( '京', '京东' ) ) as $meb_key => $meb_label ) : ?>
						<button type="button" class="meb-badge meb-badge--<?php echo esc_attr( $meb_key ); ?><?php echo $meb_key === $meb_platform ? ' is-active' : ''; ?>" <?php echo esc_attr( $meb_attr ); ?>="<?php echo esc_attr( $meb_key ); ?>" role="tab" aria-selected="<?php echo $meb_key === $meb_platform ? 'true' : 'false'; ?>" title="<?php echo esc_attr( $meb_label[1] ); ?>"><?php echo esc_html( $meb_label[0] ); ?></button>
					<?php endforeach; ?>
				</span>
				<?php if ( ! $meb_is_home ) : ?>
					<a class="meb-search__camera" href="<?php echo esc_url( home_url( '/#meb-search' ) ); ?>" title="以图搜图" aria-label="以图搜图"><?php echo meb_icon( 'camera' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
				<?php endif; ?>
				<button type="submit" class="meb-search__submit">搜索</button>
			</form>
		</div>

		<nav class="meb-header__actions" aria-label="账户">
			<a class="meb-icon-btn<?php echo function_exists( 'is_shop' ) && is_shop() ? ' is-active' : ''; ?>" href="<?php echo esc_url( $meb_u['shop'] ); ?>" aria-label="商城" title="商城">
				<?php echo meb_icon( 'store' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</a>
			<a class="meb-icon-btn<?php echo class_exists( 'MyEasyBuy_Customer_Cart' ) && MyEasyBuy_Customer_Cart::is_page() ? ' is-active' : ''; ?>" href="<?php echo esc_url( $meb_u['cart'] ); ?>" aria-label="购物车">
				<?php echo meb_icon( 'cart' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php if ( $meb_count > 0 ) : ?>
					<span class="meb-dot"><?php echo esc_html( (string) $meb_count ); ?></span>
				<?php endif; ?>
			</a>
			<a class="meb-icon-btn" href="<?php echo esc_url( $meb_u['forward'] ); ?>" aria-label="转运中心" title="转运中心">
				<?php echo meb_icon( 'box' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</a>
			<a class="meb-icon-btn<?php echo class_exists( 'MyEasyBuy_Customer_Orders' ) && MyEasyBuy_Customer_Orders::is_page() ? ' is-active' : ''; ?>" href="<?php echo esc_url( meb_endpoint_url( 'orders' ) ); ?>" aria-label="我的订单">
				<?php echo meb_icon( 'bell' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</a>
			<?php if ( is_user_logged_in() ) : ?>
				<?php
				$meb_account_items = function_exists( 'wc_get_account_menu_items' ) ? wc_get_account_menu_items() : array();
				$meb_dropdown      = array();
				foreach ( array( 'dashboard', 'orders', 'warehouse', 'parcels', 'wallet', 'customer-logout' ) as $meb_key ) {
					if ( isset( $meb_account_items[ $meb_key ] ) ) {
						$meb_dropdown[ $meb_key ] = $meb_account_items[ $meb_key ];
					}
				}
				?>
				<div class="meb-account-menu" data-meb-account-menu>
					<a class="meb-btn meb-btn--sm" href="<?php echo esc_url( $meb_u['account'] ); ?>" data-meb-account-toggle aria-haspopup="true" aria-expanded="false">
						<?php echo meb_icon( 'user' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						个人中心
					</a>
					<?php if ( $meb_dropdown ) : ?>
						<div class="meb-account-menu__panel" data-meb-account-panel>
							<nav class="meb-account-menu__list" aria-label="个人中心">
								<?php foreach ( $meb_dropdown as $meb_endpoint => $meb_label ) : ?>
									<?php
									$meb_item_classes = function_exists( 'wc_get_account_menu_item_classes' ) ? wc_get_account_menu_item_classes( $meb_endpoint ) : '';
									$meb_item_current = function_exists( 'is_account_page' ) && is_account_page() && str_contains( $meb_item_classes, 'is-active' );
									?>
									<a class="<?php echo $meb_item_current ? 'is-active' : ''; ?>" href="<?php echo esc_url( wc_get_account_endpoint_url( $meb_endpoint ) ); ?>"<?php echo $meb_item_current ? ' aria-current="page"' : ''; ?>>
										<?php echo esc_html( $meb_label ); ?>
									</a>
								<?php endforeach; ?>
							</nav>
						</div>
					<?php endif; ?>
				</div>
			<?php else : ?>
				<a class="meb-btn meb-btn--sm" href="<?php echo esc_url( $meb_u['register'] ); ?>">登录/注册</a>
			<?php endif; ?>
		</nav>
	</div>
</header>
