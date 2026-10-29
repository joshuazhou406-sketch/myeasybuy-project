<?php
/**
 * Forwarding center banner with the buyer's warehouse code and package counts.
 *
 * This template can be overridden by copying it to:
 * yourtheme/wc-reverse-forwarding/forwarding-center/hero.php
 *
 * @package ReverseForwarding
 *
 * @var bool                  $logged_in      Whether the buyer is signed in.
 * @var string                $warehouse_code Buyer warehouse code.
 * @var array<string, int>    $counts         pending / inbound / transit counts.
 * @var string                $login_url      Login URL returning here.
 * @var string                $register_url   Registration URL.
 */

defined( 'ABSPATH' ) || exit;

use ReverseForwarding\Frontend\Forwarding_Center;

$logged_in      = ! empty( $logged_in );
$warehouse_code = isset( $warehouse_code ) ? (string) $warehouse_code : '';
$counts         = isset( $counts ) && is_array( $counts ) ? $counts : array();
$login_url      = isset( $login_url ) ? (string) $login_url : '';
$register_url   = isset( $register_url ) ? (string) $register_url : '';

$stats = array(
	'pending' => __( '待入库', 'wc-rf' ),
	'inbound' => __( '已到仓', 'wc-rf' ),
	'transit' => __( '转运中', 'wc-rf' ),
);
?>
<section class="wc-rf-fc-hero">
	<div class="wc-rf-fc-hero__copy">
		<span class="wc-rf-fc-hero__eyebrow"><?php esc_html_e( 'Forwarding Center', 'wc-rf' ); ?></span>
		<h1 class="wc-rf-fc-hero__title"><?php esc_html_e( '转运中心', 'wc-rf' ); ?></h1>
		<p class="wc-rf-fc-hero__subtitle"><?php esc_html_e( '国内自购包裹寄仓 · 合箱打包 · 直邮海外', 'wc-rf' ); ?></p>
		<div class="wc-rf-fc-hero__actions">
			<?php if ( $logged_in ) : ?>
				<a class="wc-rf-btn" href="#wc-rf-declare"><?php esc_html_e( '快递预报', 'wc-rf' ); ?></a>
				<a class="wc-rf-btn wc-rf-btn--ghost" href="#wc-rf-packages"><?php esc_html_e( '我的包裹', 'wc-rf' ); ?></a>
			<?php else : ?>
				<a class="wc-rf-btn" href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( '登录后使用', 'wc-rf' ); ?></a>
				<a class="wc-rf-btn wc-rf-btn--ghost" href="<?php echo esc_url( $register_url ); ?>"><?php esc_html_e( '免费注册', 'wc-rf' ); ?></a>
			<?php endif; ?>
		</div>
	</div>

	<?php if ( $logged_in ) : ?>
		<div class="wc-rf-fc-hero__panel">
			<p class="wc-rf-fc-hero__panel-label"><?php esc_html_e( '我的仓位码', 'wc-rf' ); ?></p>
			<div class="wc-rf-fc-hero__code-row">
				<strong class="wc-rf-fc-hero__code"><?php echo esc_html( $warehouse_code ); ?></strong>
				<button type="button" class="wc-rf-copy" data-wc-rf-copy="<?php echo esc_attr( $warehouse_code ); ?>"><?php esc_html_e( '复制', 'wc-rf' ); ?></button>
			</div>
			<ul class="wc-rf-fc-hero__stats">
				<?php foreach ( $stats as $key => $label ) : ?>
					<li>
						<a href="<?php echo esc_url( add_query_arg( 'rf_tab', $key, Forwarding_Center::get_url() ) . '#wc-rf-packages' ); ?>">
							<strong><?php echo esc_html( (string) (int) ( $counts[ $key ] ?? 0 ) ); ?></strong>
							<span><?php echo esc_html( $label ); ?></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php else : ?>
		<div class="wc-rf-fc-hero__art" aria-hidden="true">
			<span class="wc-rf-fc-hero__coin wc-rf-fc-hero__coin--a"></span>
			<span class="wc-rf-fc-hero__coin wc-rf-fc-hero__coin--b"></span>
			<span class="wc-rf-fc-hero__bubble"><?php echo Forwarding_Center::icon( 'plane' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
		</div>
	<?php endif; ?>
</section>
