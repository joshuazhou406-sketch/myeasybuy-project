<?php
/**
 * WMS inbound workbench.
 *
 * @package ReverseForwarding
 *
 * @var array<int, array<string, mixed>> $recent
 */

defined( 'ABSPATH' ) || exit;

use ReverseForwarding\Frontend\My_Account;

$recent = isset( $recent ) && is_array( $recent ) ? $recent : array();
?>
<div class="wrap wc-rf-wms">
	<h1><?php esc_html_e( '集运仓入库台', 'wc-rf' ); ?></h1>
	<p class="wc-rf-wms__lead">
		<?php esc_html_e( '扫描国内快递单号，核对预报信息后录入实重、尺寸、货架并拍照留底。成功后自动回到单号框，可连续扫码。', 'wc-rf' ); ?>
	</p>
	<p class="wc-rf-wms__outbound">
		<?php if ( class_exists( 'MyEasyBuy_Admin_Workflow' ) ) : ?>
			<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=myeasybuy-int-shipping' ) ); ?>">
				<?php esc_html_e( '4PX 国际发货', 'wc-rf' ); ?>
			</a>
		<?php endif; ?>
		<?php if ( current_user_can( 'manage_woocommerce' ) ) : ?>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=myeasybuy-4px-settings' ) ); ?>">
				<?php esc_html_e( '4PX 物流对接', 'wc-rf' ); ?>
			</a>
		<?php endif; ?>
		<span class="description">
			<?php esc_html_e( '已付集运单进入同一国际发货队列；仓库地址与采购仓一致。', 'wc-rf' ); ?>
		</span>
	</p>

	<div id="wc-rf-wms-toast" class="wc-rf-wms-toast" hidden role="status"></div>

	<div class="wc-rf-wms__grid">
		<form id="wc-rf-wms-form" class="wc-rf-wms-card" autocomplete="off">
			<div class="wc-rf-wms-scan">
				<label for="wc-rf-wms-tracking"><?php esc_html_e( '快递单号（扫码枪）', 'wc-rf' ); ?></label>
				<input
					type="text"
					id="wc-rf-wms-tracking"
					name="tracking_number"
					class="wc-rf-wms-scan__input"
					autofocus
					required
					maxlength="100"
					placeholder="<?php esc_attr_e( '对准输入框扫描，回车查询', 'wc-rf' ); ?>"
				/>
			</div>

			<div id="wc-rf-wms-owner" class="wc-rf-wms-owner is-empty">
				<p class="wc-rf-wms-owner__hint"><?php esc_html_e( '扫描后显示归属会员与申报品名。', 'wc-rf' ); ?></p>
				<div class="wc-rf-wms-owner__body" hidden>
					<p><strong data-wms-code></strong> <span data-wms-user></span></p>
					<p data-wms-email></p>
					<p><?php esc_html_e( '申报品名', 'wc-rf' ); ?>：<span data-wms-name></span></p>
					<p><?php esc_html_e( '快递公司', 'wc-rf' ); ?>：<span data-wms-company></span></p>
				</div>
			</div>

			<div class="wc-rf-wms-fields">
				<p>
					<label for="wc-rf-wms-name"><?php esc_html_e( '申报品名', 'wc-rf' ); ?></label>
					<input type="text" id="wc-rf-wms-name" name="declared_name" maxlength="255" />
				</p>
				<p>
					<label for="wc-rf-wms-company"><?php esc_html_e( '快递公司', 'wc-rf' ); ?></label>
					<input type="text" id="wc-rf-wms-company" name="express_company" maxlength="100" />
				</p>
				<p>
					<label for="wc-rf-wms-weight"><?php esc_html_e( '实重 (kg)', 'wc-rf' ); ?></label>
					<input type="number" id="wc-rf-wms-weight" name="weight" min="0.001" step="0.001" required />
				</p>
				<p>
					<label for="wc-rf-wms-shelf"><?php esc_html_e( '货架号', 'wc-rf' ); ?></label>
					<input type="text" id="wc-rf-wms-shelf" name="shelf_location" maxlength="100" />
				</p>
				<p>
					<label for="wc-rf-wms-length"><?php esc_html_e( '长 (cm)', 'wc-rf' ); ?></label>
					<input type="number" id="wc-rf-wms-length" name="length" min="0" step="0.01" />
				</p>
				<p>
					<label for="wc-rf-wms-width"><?php esc_html_e( '宽 (cm)', 'wc-rf' ); ?></label>
					<input type="number" id="wc-rf-wms-width" name="width" min="0" step="0.01" />
				</p>
				<p>
					<label for="wc-rf-wms-height"><?php esc_html_e( '高 (cm)', 'wc-rf' ); ?></label>
					<input type="number" id="wc-rf-wms-height" name="height" min="0" step="0.01" />
				</p>
				<p class="wc-rf-wms-fields__photos">
					<label for="wc-rf-wms-photos"><?php esc_html_e( '入库拍照', 'wc-rf' ); ?></label>
					<input
						type="file"
						id="wc-rf-wms-photos"
						name="photos[]"
						accept="image/*"
						capture="environment"
						multiple
					/>
				</p>
			</div>

			<label class="wc-rf-wms-unclaimed" id="wc-rf-wms-unclaimed-row" hidden>
				<input type="checkbox" id="wc-rf-wms-unclaimed" name="allow_unclaimed" value="1" />
				<?php esc_html_e( '登记为无主认领件', 'wc-rf' ); ?>
			</label>

			<p class="wc-rf-wms-actions">
				<button type="submit" class="button button-primary button-hero" id="wc-rf-wms-submit">
					<?php esc_html_e( '确认入库', 'wc-rf' ); ?>
				</button>
			</p>
		</form>

		<aside class="wc-rf-wms-card wc-rf-wms-recent">
			<h2><?php esc_html_e( '最近入库', 'wc-rf' ); ?></h2>
			<ul id="wc-rf-wms-recent">
				<?php if ( empty( $recent ) ) : ?>
					<li class="wc-rf-wms-recent__empty"><?php esc_html_e( '暂无记录。', 'wc-rf' ); ?></li>
				<?php else : ?>
					<?php foreach ( $recent as $row ) : ?>
						<?php
						$uid  = (int) $row['user_id'];
						$code = $uid > 0 ? My_Account::ensure_warehouse_code( $uid ) : __( '无主', 'wc-rf' );
						?>
						<li>
							<strong><?php echo esc_html( (string) $row['tracking_number'] ); ?></strong>
							<span><?php echo esc_html( $code . ' · ' . (string) $row['declared_name'] ); ?></span>
							<span><?php echo esc_html( wc_format_decimal( (float) $row['weight'], 3 ) . ' kg · ' . (string) $row['shelf_location'] ); ?></span>
						</li>
					<?php endforeach; ?>
				<?php endif; ?>
			</ul>
		</aside>
	</div>
</div>
