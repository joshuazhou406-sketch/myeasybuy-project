<?php
/**
 * Domestic parcel pre-alert form.
 *
 * This template can be overridden by copying it to:
 * yourtheme/wc-reverse-forwarding/forwarding-center/package-declare.php
 *
 * @package ReverseForwarding
 *
 * @var array<string, string> $companies    Carrier options.
 * @var string                $action_url   Form action.
 * @var string                $redirect_to  Return URL.
 * @var string                $action_name  admin-post action.
 * @var string                $nonce_action Nonce action.
 * @var string                $nonce_name   Nonce field name.
 */

defined( 'ABSPATH' ) || exit;

use ReverseForwarding\Frontend\Forwarding_Center;

$companies    = isset( $companies ) && is_array( $companies ) ? $companies : array();
$action_url   = isset( $action_url ) ? (string) $action_url : admin_url( 'admin-post.php' );
$redirect_to  = isset( $redirect_to ) ? (string) $redirect_to : '';
$action_name  = isset( $action_name ) ? (string) $action_name : 'wc_rf_declare_package';
$nonce_action = isset( $nonce_action ) ? (string) $nonce_action : 'wc_rf_declare_package';
$nonce_name   = isset( $nonce_name ) ? (string) $nonce_name : 'wc_rf_declare_nonce';
?>
<section class="wc-rf-fc-card wc-rf-fc-declare" id="wc-rf-declare">
	<header class="wc-rf-fc-card__head">
		<span class="wc-rf-fc-card__icon"><?php echo Forwarding_Center::icon( 'clip' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
		<div>
			<h2 class="wc-rf-fc-card__title"><?php esc_html_e( '快递预报', 'wc-rf' ); ?></h2>
			<p class="wc-rf-fc-card__hint"><?php esc_html_e( '寄出后请填写国内快递单号，仓库扫码入库时按单号匹配您的仓位。', 'wc-rf' ); ?></p>
		</div>
	</header>

	<form class="wc-rf-fc-form" method="post" action="<?php echo esc_url( $action_url ); ?>">
		<?php wp_nonce_field( $nonce_action, $nonce_name ); ?>
		<input type="hidden" name="action" value="<?php echo esc_attr( $action_name ); ?>" />
		<input type="hidden" name="redirect_to" value="<?php echo esc_url( $redirect_to ); ?>" />

		<div class="wc-rf-fc-form__field">
			<label for="wc-rf-tracking-number"><?php esc_html_e( '快递单号', 'wc-rf' ); ?> <span class="wc-rf-fc-form__req">*</span></label>
			<input type="text" name="tracking_number" id="wc-rf-tracking-number" required maxlength="100" autocomplete="off" placeholder="<?php esc_attr_e( '例如：SF1234567890', 'wc-rf' ); ?>" />
		</div>

		<div class="wc-rf-fc-form__split">
			<div class="wc-rf-fc-form__field">
				<label for="wc-rf-express-company"><?php esc_html_e( '快递公司', 'wc-rf' ); ?> <span class="wc-rf-fc-form__req">*</span></label>
				<select name="express_company" id="wc-rf-express-company" required>
					<option value=""><?php esc_html_e( '请选择', 'wc-rf' ); ?></option>
					<?php foreach ( $companies as $value => $label ) : ?>
						<option value="<?php echo esc_attr( (string) $value ); ?>"><?php echo esc_html( (string) $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="wc-rf-fc-form__field is-hidden" id="wc-rf-express-other-row">
				<label for="wc-rf-express-other"><?php esc_html_e( '其他快递公司', 'wc-rf' ); ?> <span class="wc-rf-fc-form__req">*</span></label>
				<input type="text" name="express_company_other" id="wc-rf-express-other" maxlength="100" />
			</div>
		</div>

		<div class="wc-rf-fc-form__field">
			<label for="wc-rf-declared-name"><?php esc_html_e( '申报品名', 'wc-rf' ); ?> <span class="wc-rf-fc-form__req">*</span></label>
			<input type="text" name="declared_name" id="wc-rf-declared-name" required maxlength="255" placeholder="<?php esc_attr_e( '例如：服装、化妆品、数码配件', 'wc-rf' ); ?>" />
		</div>

		<button type="submit" class="wc-rf-btn wc-rf-btn--block"><?php esc_html_e( '提交预报', 'wc-rf' ); ?></button>
	</form>
</section>
