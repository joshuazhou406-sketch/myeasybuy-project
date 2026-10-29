<?php
/**
 * Domestic consolidation warehouse address card.
 *
 * This template can be overridden by copying it to:
 * yourtheme/wc-reverse-forwarding/forwarding-center/warehouse-address.php
 *
 * @package ReverseForwarding
 *
 * @var string $warehouse_code Unique buyer warehouse code (e.g. CA-8015).
 * @var string $recipient      Receiver name including warehouse code.
 * @var string $company        Warehouse company name.
 * @var string $phone          Warehouse phone.
 * @var string $address        Full street address.
 * @var string $postcode       Postal code.
 * @var string $country        Country code.
 * @var string $copy_all       Plain-text block for one-click copy.
 */

defined( 'ABSPATH' ) || exit;

use ReverseForwarding\Frontend\Forwarding_Center;

$recipient = isset( $recipient ) ? (string) $recipient : '';
$company   = isset( $company ) ? (string) $company : '';
$phone     = isset( $phone ) ? (string) $phone : '';
$address   = isset( $address ) ? (string) $address : '';
$postcode  = isset( $postcode ) ? (string) $postcode : '';
$copy_all  = isset( $copy_all ) ? (string) $copy_all : '';

$rows = array(
	array( __( '收件人', 'wc-rf' ), $recipient, true ),
	array( __( '电话', 'wc-rf' ), $phone, true ),
	array( __( '地址', 'wc-rf' ), $address, true ),
	array( __( '邮编', 'wc-rf' ), $postcode, true ),
	array( __( '公司', 'wc-rf' ), $company, false ),
);
?>
<section class="wc-rf-fc-card wc-rf-fc-address" id="wc-rf-address">
	<header class="wc-rf-fc-card__head">
		<span class="wc-rf-fc-card__icon"><?php echo Forwarding_Center::icon( 'pin' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
		<div>
			<h2 class="wc-rf-fc-card__title"><?php esc_html_e( '国内集运仓专属地址', 'wc-rf' ); ?></h2>
			<p class="wc-rf-fc-card__hint"><?php esc_html_e( '下单时填写以下地址（与代采采购仓相同）。', 'wc-rf' ); ?></p>
		</div>
	</header>

	<p class="wc-rf-fc-address__alert">
		<?php esc_html_e( '收件人姓名必须包含您的仓位码，否则仓库无法识别包裹。', 'wc-rf' ); ?>
	</p>

	<dl class="wc-rf-fc-address__fields">
		<?php foreach ( $rows as $row ) : ?>
			<?php
			list( $label, $value, $copyable ) = $row;
			if ( '' === $value && ! $copyable ) {
				continue;
			}
			?>
			<div class="wc-rf-fc-address__row">
				<dt><?php echo esc_html( $label ); ?></dt>
				<dd>
					<span><?php echo esc_html( '' !== $value ? $value : '—' ); ?></span>
					<?php if ( $copyable && '' !== $value ) : ?>
						<button type="button" class="wc-rf-copy" data-wc-rf-copy="<?php echo esc_attr( $value ); ?>"><?php esc_html_e( '复制', 'wc-rf' ); ?></button>
					<?php endif; ?>
				</dd>
			</div>
		<?php endforeach; ?>
	</dl>

	<button type="button" class="wc-rf-btn wc-rf-btn--block" data-wc-rf-copy="<?php echo esc_attr( $copy_all ); ?>">
		<?php echo Forwarding_Center::icon( 'copy' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<span data-wc-rf-copy-label><?php esc_html_e( '一键复制全部地址', 'wc-rf' ); ?></span>
	</button>
</section>
