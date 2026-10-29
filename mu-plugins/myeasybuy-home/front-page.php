<?php
/**
 * Front page — ootdbuy-style landing layout. Header/footer come from parts/ via wp_body_open / wp_footer.
 *
 * @package MyEasyBuy_Home
 */

defined( 'ABSPATH' ) || exit;

$meb_u = meb_urls();

$meb_slide_url = static function ( string $file ): string {
	$path = MYEASYBUY_HOME_DIR . 'assets/slides/' . $file;

	return add_query_arg( 'ver', (string) filemtime( $path ), MYEASYBUY_HOME_URL . 'assets/slides/' . $file );
};

$meb_slides = apply_filters(
	'meb_home_slides',
	array(
		array(
			'image' => $meb_slide_url( 'slide-rewards.jpg' ),
			'alt'   => 'Myeasybuy — Maximize your season with Rewards',
			'url'   => $meb_u['shop'],
		),
		array(
			'image' => $meb_slide_url( 'slide-festive.jpg' ),
			'alt'   => 'Shop Festively, Save, Earn! Maximize your season with Rewards',
			'url'   => $meb_u['shop'],
		),
		array(
			'image' => $meb_slide_url( 'slide-joy.jpg' ),
			'alt'   => 'Spread Joy, Gift, Earn! Turn your festive cheer into rewards',
			'url'   => $meb_u['shop'],
		),
	)
);

$meb_services = array(
	array(
		'title' => '代购中心',
		'desc'  => '浏览商城，淘宝、京东好物一键代购',
		'cta'   => '立即查看',
		'url'   => $meb_u['shop'],
		'icon'  => 'store',
	),
	array(
		'title' => '转运中心',
		'desc'  => '管理自购寄仓包裹，仓库代收后帮您发往国外',
		'cta'   => '立即查看',
		'url'   => $meb_u['forward'],
		'icon'  => 'doc',
	),
);

$meb_reviews = apply_filters(
	'meb_home_reviews',
	array(
		array(
			'name' => 'Emily W.',
			'text' => 'This was my first time using a Chinese purchasing agent, and the process was very clear. The quality inspection photos were detailed, and I saved quite a bit on shipping costs by consolidating my orders.！',
		),
		array(
			'name' => 'Kevin L.',
			'text' => 'Customer service was very responsive, and I received email notifications at every stage of the order, which gave me peace of mind.。',
		),
		array(
			'name' => 'Sophia C.',
			'text' => 'I highly recommend this forwarding service! The processing of items sent to the warehouse is seamless, and there is a wide range of shipping routes to choose from.。',
		),
	)
);

$meb_products = $meb_u['has_wc']
	? wc_get_products(
		array(
			'status'  => 'publish',
			'limit'   => 10,
			'orderby' => 'date',
			'order'   => 'DESC',
		)
	)
	: array();
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<div class="meb-notice">
	<div class="meb-container meb-notice__inner">
		<span class="meb-notice__label"><?php echo meb_icon( 'megaph' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>公告</span>
		<p class="meb-notice__text"><?php echo esc_html( apply_filters( 'meb_home_notice', get_bloginfo( 'name' ) . ' 现已支持淘宝、京东商品代购，支持合箱发货与以图搜图。' ) ); ?></p>
		<a class="meb-notice__more" href="<?php echo esc_url( $meb_u['shop'] ); ?>">更多 <?php echo meb_icon( 'arrow' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
	</div>
</div>

<main id="content" class="meb-main">
	<div class="meb-container">

		<section class="meb-hero" aria-roledescription="carousel" aria-label="活动推荐">
			<div class="meb-hero__track">
				<?php foreach ( $meb_slides as $meb_i => $meb_slide ) : ?>
					<a class="meb-hero__slide<?php echo 0 === $meb_i ? ' is-active' : ''; ?>" href="<?php echo esc_url( $meb_slide['url'] ); ?>" aria-label="<?php echo esc_attr( $meb_slide['alt'] ); ?>" aria-hidden="<?php echo 0 === $meb_i ? 'false' : 'true'; ?>">
						<img class="meb-hero__img" src="<?php echo esc_url( $meb_slide['image'] ); ?>" alt="<?php echo esc_attr( $meb_slide['alt'] ); ?>" width="1013" height="338"<?php echo 0 === $meb_i ? '' : ' loading="lazy"'; ?>>
					</a>
				<?php endforeach; ?>
			</div>
			<?php if ( count( $meb_slides ) > 1 ) : ?>
				<div class="meb-hero__dots" role="tablist">
					<?php foreach ( $meb_slides as $meb_i => $meb_slide ) : ?>
						<button type="button" class="meb-hero__dot<?php echo 0 === $meb_i ? ' is-active' : ''; ?>" data-meb-slide="<?php echo esc_attr( (string) $meb_i ); ?>" aria-label="<?php echo esc_attr( sprintf( '第 %d 张', $meb_i + 1 ) ); ?>"></button>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>

		<?php
		if ( class_exists( 'PPW_Search' ) ) {
			PPW_Search::render_results_container();
			remove_action( 'wp_footer', array( 'PPW_Search', 'maybe_render_results_container' ), 5 );
		}
		?>

		<section class="meb-services">
			<?php foreach ( $meb_services as $meb_service ) : ?>
				<article class="meb-service">
					<div class="meb-service__body">
						<h3 class="meb-service__title"><?php echo esc_html( $meb_service['title'] ); ?></h3>
						<p class="meb-service__desc"><?php echo esc_html( $meb_service['desc'] ); ?></p>
						<a class="meb-btn meb-btn--sm" href="<?php echo esc_url( $meb_service['url'] ); ?>"<?php echo ! empty( $meb_service['focus'] ) ? ' data-meb-focus-search' : ''; ?>><?php echo esc_html( $meb_service['cta'] ); ?></a>
					</div>
					<div class="meb-service__art" aria-hidden="true">
						<?php echo meb_icon( $meb_service['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
				</article>
			<?php endforeach; ?>
		</section>

		<?php if ( ! empty( $meb_products ) ) : ?>
			<section class="meb-section">
				<div class="meb-section__head">
					<div>
						<span class="meb-eyebrow">热门推荐</span>
						<h2 class="meb-section__title">大家都在买</h2>
						<p class="meb-section__lead">精选淘宝、京东热卖好物，一键代购直邮到家。</p>
					</div>
					<a class="meb-btn meb-btn--sm" href="<?php echo esc_url( $meb_u['shop'] ); ?>">查看全部商品</a>
				</div>
				<ul class="meb-products">
					<?php foreach ( $meb_products as $meb_product ) : ?>
						<li class="meb-product">
							<a href="<?php echo esc_url( $meb_product->get_permalink() ); ?>">
								<span class="meb-product__img">
									<?php echo $meb_product->get_image( 'woocommerce_thumbnail', array( 'loading' => 'lazy' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								</span>
								<span class="meb-product__title"><?php echo esc_html( $meb_product->get_name() ); ?></span>
								<span class="meb-product__price"><?php echo wp_kses_post( $meb_product->get_price_html() ); ?></span>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endif; ?>

		<section class="meb-section">
			<div class="meb-section__head">
				<div>
					<span class="meb-eyebrow">用户评价</span>
					<h2 class="meb-section__title">每个人都在用 <?php echo esc_html( strtoupper( get_bloginfo( 'name' ) ) ); ?></h2>
					<p class="meb-section__lead">越来越多的海外用户选择我们，以体验更专业的中国代购服务。.</p>
				</div>
			</div>
			<div class="meb-reviews">
				<?php foreach ( $meb_reviews as $meb_review ) : ?>
					<figure class="meb-review">
						<div class="meb-review__stars" aria-label="5 Stars">★★★★★</div>
						<blockquote class="meb-review__text"><?php echo esc_html( $meb_review['text'] ); ?></blockquote>
						<figcaption class="meb-review__author">
							<span class="meb-review__avatar"><?php echo meb_icon( 'user' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
							<?php echo esc_html( $meb_review['name'] ); ?>
						</figcaption>
					</figure>
				<?php endforeach; ?>
			</div>
		</section>

	</div>
</main>

<?php wp_footer(); ?>
</body>
</html>
