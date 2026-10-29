<?php
/**
 * Shop page — category browsing over live Taobao / JD search (product-purchase-workflow).
 * Header/footer come from parts/ via wp_body_open / wp_footer.
 *
 * @package MyEasyBuy_Home
 */

defined( 'ABSPATH' ) || exit;

$meb_u        = meb_urls();
$meb_platform = class_exists( 'PPW_Search' ) ? PPW_Search::default_platform() : 'taobao';

// Each sub-tab value is the marketplace search keyword.
$meb_cats = apply_filters(
	'meb_shop_categories',
	array(
		'recommend'     => array(
			'label' => '推荐',
			'icon'  => 'thumb',
			'subs'  => array( '全部' => '热卖爆款', '新品' => '新款上市', '国货精品' => '国货精品', '居家好物' => '居家好物' ),
		),
		'mens-shoes'    => array(
			'label' => '男鞋',
			'icon'  => 'shoe',
			'subs'  => array( '全部' => '男鞋', '休闲鞋' => '男士休闲鞋', '运动鞋' => '男士运动鞋', '靴子' => '男靴', '凉鞋拖鞋' => '男士凉鞋拖鞋', '鞋配件' => '鞋垫 鞋带' ),
		),
		'mens-clothing' => array(
			'label' => '男装',
			'icon'  => 'jacket',
			'subs'  => array( '全部' => '男装', 'T恤' => '男士T恤', '卫衣' => '男士卫衣', '外套' => '男士外套', '裤子' => '男士裤子', '衬衫' => '男士衬衫' ),
		),
		'womens'        => array(
			'label' => '女装',
			'icon'  => 'shirt',
			'subs'  => array( '全部' => '女装', '连衣裙' => '连衣裙', '上衣' => '女士上衣', '外套' => '女士外套', '半身裙' => '半身裙', '裤子' => '女裤' ),
		),
		'underwear'     => array(
			'label' => '内衣睡衣',
			'icon'  => 'moon',
			'subs'  => array( '全部' => '内衣 睡衣', '睡衣家居服' => '睡衣 家居服', '文胸' => '文胸', '内裤' => '内裤', '袜子' => '袜子' ),
		),
		'accessories'   => array(
			'label' => '配饰',
			'icon'  => 'watch',
			'subs'  => array( '全部' => '配饰', '手表' => '手表', '包包' => '包包', '帽子' => '帽子', '首饰' => '首饰 项链', '眼镜' => '眼镜' ),
		),
		'electronics'   => array(
			'label' => '数码电子',
			'icon'  => 'mouse',
			'subs'  => array( '全部' => '数码配件', '耳机' => '蓝牙耳机', '鼠标键盘' => '鼠标 键盘', '充电' => '充电宝 数据线', '手机壳' => '手机壳' ),
		),
		'fragrance'     => array(
			'label' => '香水',
			'icon'  => 'bottle',
			'subs'  => array( '全部' => '香水', '女士香水' => '女士香水', '男士香水' => '男士香水', '香薰' => '香薰 蜡烛' ),
		),
		'sports'        => array(
			'label' => '运动户外',
			'icon'  => 'ball',
			'subs'  => array( '全部' => '运动户外', '健身' => '健身器材', '露营' => '露营装备', '游泳' => '泳镜 泳衣', '骑行' => '骑行装备' ),
		),
		'toys'          => array(
			'label' => '玩具游戏',
			'icon'  => 'game',
			'subs'  => array( '全部' => '玩具', '潮玩手办' => '潮玩 手办', '积木' => '积木', '毛绒玩具' => '毛绒玩具', '游戏周边' => '游戏周边' ),
		),
		'beauty'        => array(
			'label' => '美妆个护',
			'icon'  => 'lips',
			'subs'  => array( '全部' => '美妆', '彩妆' => '彩妆', '护肤' => '护肤品', '美发' => '美发 护发', '美甲' => '美甲' ),
		),
		'pets'          => array(
			'label' => '宠物用品',
			'icon'  => 'paw',
			'subs'  => array( '全部' => '宠物用品', '猫咪' => '猫咪用品', '狗狗' => '狗狗用品', '宠物玩具' => '宠物玩具' ),
		),
		'home'          => array(
			'label' => '家居生活',
			'icon'  => 'home',
			'subs'  => array( '全部' => '家居好物', '收纳' => '收纳', '厨房' => '厨房用品', '床品' => '床上用品', '装饰' => '家居装饰' ),
		),
	)
);

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$meb_active = isset( $_GET['cat'] ) ? sanitize_key( wp_unslash( $_GET['cat'] ) ) : '';
if ( ! isset( $meb_cats[ $meb_active ] ) ) {
	$meb_active = (string) array_key_first( $meb_cats );
}

$meb_reasons = apply_filters(
	'meb_shop_reasons',
	array(
		array( '安全支付', '支持 PayPal、信用卡等主流支付方式，资金安全有保障。' ),
		array( '1:1 质检拍照', '商品到仓后拍照质检，确认无误再发货。' ),
		array( '多条国际线路', '多种物流渠道可选，时效与价格灵活搭配。' ),
		array( '全程订单通知', '下单、到仓、发货每个节点都有邮件提醒。' ),
		array( '专属客服', '服务时间 09:00 - 21:30 (UTC+8)，问题及时响应。' ),
	)
);

$meb_promo_url = is_user_logged_in() ? meb_endpoint_url( 'consolidation' ) : $meb_u['register'];
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<nav class="meb-catbar" aria-label="商品分类">
	<div class="meb-container meb-catbar__inner">
		<button type="button" class="meb-catbar__all" data-meb-allcats aria-expanded="false" aria-controls="meb-allcats">
			<?php echo meb_icon( 'menu' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>全部分类
		</button>
		<div class="meb-scroller meb-catbar__scroller">
			<ul class="meb-catbar__list" data-meb-scroll>
				<?php foreach ( $meb_cats as $meb_slug => $meb_cat ) : ?>
					<li><button type="button" class="meb-catbar__link<?php echo $meb_slug === $meb_active ? ' is-active' : ''; ?>" data-meb-cat="<?php echo esc_attr( $meb_slug ); ?>"><?php echo esc_html( $meb_cat['label'] ); ?></button></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<span class="meb-catbar__arrows">
			<button type="button" class="meb-mini-arrow" data-meb-scroll-btn="-1" aria-label="向左滚动"><?php echo meb_icon( 'left' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
			<button type="button" class="meb-mini-arrow" data-meb-scroll-btn="1" aria-label="向右滚动"><?php echo meb_icon( 'arrow' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
		</span>
	</div>

	<div id="meb-allcats" class="meb-allcats" hidden>
		<div class="meb-container meb-allcats__grid">
			<?php foreach ( $meb_cats as $meb_slug => $meb_cat ) : ?>
				<div class="meb-allcats__group">
					<button type="button" class="meb-allcats__title" data-meb-cat="<?php echo esc_attr( $meb_slug ); ?>">
						<?php echo meb_icon( $meb_cat['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo esc_html( $meb_cat['label'] ); ?>
					</button>
					<ul>
						<?php foreach ( array_slice( $meb_cat['subs'], 1, null, true ) as $meb_sub => $meb_kw ) : ?>
							<li><button type="button" data-meb-cat="<?php echo esc_attr( $meb_slug ); ?>" data-meb-sub="<?php echo esc_attr( $meb_sub ); ?>"><?php echo esc_html( $meb_sub ); ?></button></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
</nav>

<main id="content" class="meb-main meb-shop-main">
	<div class="meb-container">

		<section class="meb-shop-top">
			<form class="meb-shop-search" data-meb-shop-search role="search">
				<input type="search" class="meb-shop-search__input" name="q" placeholder="请输入商品名称 / 粘贴淘宝、京东商品链接" autocomplete="off" aria-label="搜索商品" minlength="2" required>
				<span class="meb-search__platforms" role="tablist" aria-label="商品平台">
					<?php foreach ( array( 'taobao' => array( '淘', '淘宝' ), 'jd' => array( '京', '京东' ) ) as $meb_key => $meb_label ) : ?>
						<button type="button" class="meb-badge meb-badge--<?php echo esc_attr( $meb_key ); ?><?php echo $meb_key === $meb_platform ? ' is-active' : ''; ?>" data-meb-shop-platform="<?php echo esc_attr( $meb_key ); ?>" role="tab" aria-selected="<?php echo $meb_key === $meb_platform ? 'true' : 'false'; ?>" title="<?php echo esc_attr( $meb_label[1] ); ?>"><?php echo esc_html( $meb_label[0] ); ?></button>
					<?php endforeach; ?>
				</span>
				<a class="meb-shop-search__camera" href="<?php echo esc_url( home_url( '/#meb-search' ) ); ?>" title="以图搜图" aria-label="以图搜图"><?php echo meb_icon( 'camera' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
				<button type="submit" class="meb-shop-search__submit" aria-label="搜索"><?php echo meb_icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
			</form>

			<a class="meb-promo meb-promo--coupon" href="<?php echo esc_url( $meb_promo_url ); ?>">
				<span class="meb-promo__big">
					<?php echo meb_icon( 'ticket' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<small>新人专享</small>
				</span>
				<span class="meb-promo__body">
					<strong>注册领运费优惠 <?php echo meb_icon( 'arrow' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></strong>
					<span class="meb-promo__timer"><?php echo meb_icon( 'clock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><b data-meb-countdown>--:--:--</b></span>
				</span>
			</a>
		</section>

		<section class="meb-why" data-meb-why>
			<div class="meb-why__row">
				<h2 class="meb-why__title"><?php echo meb_icon( 'shield' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>为什么选择 <?php echo esc_html( get_bloginfo( 'name' ) ); ?>？</h2>
				<ul class="meb-why__list">
					<?php foreach ( $meb_reasons as $meb_reason ) : ?>
						<li><?php echo esc_html( $meb_reason[0] ); ?></li>
					<?php endforeach; ?>
				</ul>
				<button type="button" class="meb-why__toggle" data-meb-why-toggle aria-expanded="false" aria-label="查看更多保障"><?php echo meb_icon( 'down' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
			</div>
			<dl class="meb-why__details" hidden>
				<?php foreach ( $meb_reasons as $meb_reason ) : ?>
					<div><dt><?php echo esc_html( $meb_reason[0] ); ?></dt><dd><?php echo esc_html( $meb_reason[1] ); ?></dd></div>
				<?php endforeach; ?>
			</dl>
		</section>

		<section class="meb-cats" aria-label="分类">
			<button type="button" class="meb-round-arrow" data-meb-scroll-btn="-1" aria-label="上一组"><?php echo meb_icon( 'left' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
			<ul class="meb-cats__list" data-meb-scroll>
				<?php foreach ( $meb_cats as $meb_slug => $meb_cat ) : ?>
					<li>
						<button type="button" class="meb-cat<?php echo $meb_slug === $meb_active ? ' is-active' : ''; ?>" data-meb-cat="<?php echo esc_attr( $meb_slug ); ?>">
							<span class="meb-cat__tile"><?php echo meb_icon( $meb_cat['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
							<span class="meb-cat__label"><?php echo esc_html( $meb_cat['label'] ); ?></span>
						</button>
					</li>
				<?php endforeach; ?>
			</ul>
			<button type="button" class="meb-round-arrow" data-meb-scroll-btn="1" aria-label="下一组"><?php echo meb_icon( 'arrow' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
		</section>

		<section class="meb-shop-list" data-meb-shop data-platform="<?php echo esc_attr( $meb_platform ); ?>">
			<div class="meb-subs">
				<?php foreach ( $meb_cats as $meb_slug => $meb_cat ) : ?>
					<ul class="meb-subs__list" data-meb-subs="<?php echo esc_attr( $meb_slug ); ?>"<?php echo $meb_slug === $meb_active ? '' : ' hidden'; ?>>
						<?php
						$meb_first = true;
						foreach ( $meb_cat['subs'] as $meb_sub => $meb_kw ) :
							?>
							<li><button type="button" class="meb-subs__tab<?php echo $meb_first ? ' is-active' : ''; ?>" data-meb-sub="<?php echo esc_attr( $meb_sub ); ?>" data-keyword="<?php echo esc_attr( $meb_kw ); ?>"><?php echo esc_html( $meb_sub ); ?></button></li>
							<?php
							$meb_first = false;
						endforeach;
						?>
					</ul>
				<?php endforeach; ?>
				<div class="meb-subs__search" data-meb-search-label hidden>
					搜索：<strong data-meb-search-term></strong>
					<button type="button" data-meb-search-clear aria-label="清除搜索"><?php echo meb_icon( 'close' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
				</div>
				<button type="button" class="meb-subs__more" data-meb-allcats aria-controls="meb-allcats">更多</button>
			</div>

			<ul class="ppw-jd-grid meb-shop-grid" data-meb-grid aria-live="polite"></ul>
			<p class="meb-shop-status" data-meb-status hidden></p>
			<div class="meb-shop-more">
				<button type="button" class="meb-btn meb-btn--ghost" data-meb-more hidden>查看更多商品</button>
			</div>
		</section>

	</div>
</main>

<?php wp_footer(); ?>
</body>
</html>
