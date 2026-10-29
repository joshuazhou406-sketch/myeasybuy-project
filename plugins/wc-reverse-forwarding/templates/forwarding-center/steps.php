<?php
/**
 * Four-step forwarding flow cards.
 *
 * This template can be overridden by copying it to:
 * yourtheme/wc-reverse-forwarding/forwarding-center/steps.php
 *
 * @package ReverseForwarding
 */

defined( 'ABSPATH' ) || exit;

use ReverseForwarding\Frontend\Forwarding_Center;

$steps = apply_filters(
	'wc_rf_forwarding_center_steps',
	array(
		array(
			'title' => __( '获取仓位码', 'wc-rf' ),
			'desc'  => __( '登录后自动生成专属仓位码，收件人须带上它', 'wc-rf' ),
			'icon'  => 'tag',
		),
		array(
			'title' => __( '快递预报', 'wc-rf' ),
			'desc'  => __( '寄往集运仓后，填写国内快递单号', 'wc-rf' ),
			'icon'  => 'clip',
		),
		array(
			'title' => __( '入库验货', 'wc-rf' ),
			'desc'  => __( '仓库签收、称重拍照，包裹进入「已到仓」', 'wc-rf' ),
			'icon'  => 'warehouse',
		),
		array(
			'title' => __( '合箱发货', 'wc-rf' ),
			'desc'  => __( '勾选包裹申请合箱，支付运费后发往海外', 'wc-rf' ),
			'icon'  => 'plane',
		),
	)
);
?>
<section class="wc-rf-fc-steps" aria-label="<?php esc_attr_e( '转运流程', 'wc-rf' ); ?>">
	<?php foreach ( $steps as $index => $step ) : ?>
		<article class="wc-rf-fc-step">
			<div class="wc-rf-fc-step__body">
				<span class="wc-rf-fc-step__num"><?php echo esc_html( sprintf( '%02d', $index + 1 ) ); ?></span>
				<h3 class="wc-rf-fc-step__title"><?php echo esc_html( $step['title'] ); ?></h3>
				<p class="wc-rf-fc-step__desc"><?php echo esc_html( $step['desc'] ); ?></p>
			</div>
			<div class="wc-rf-fc-step__art" aria-hidden="true">
				<?php echo Forwarding_Center::icon( $step['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		</article>
	<?php endforeach; ?>
</section>
