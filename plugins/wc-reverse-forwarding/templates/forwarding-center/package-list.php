<?php
/**
 * Buyer package list with pending / inbound / transit tabs.
 *
 * This template can be overridden by copying it to:
 * yourtheme/wc-reverse-forwarding/forwarding-center/package-list.php
 *
 * @package ReverseForwarding
 *
 * @var array<int, array<string, mixed>> $pending_packages
 * @var array<int, array<string, mixed>> $inbound_packages
 * @var array<int, array<string, mixed>> $transit_packages
 * @var string                           $active_tab
 * @var string                           $action_url
 * @var string                           $redirect_to
 * @var string                           $consolidate_action
 * @var string                           $consolidate_nonce_action
 * @var string                           $consolidate_nonce_name
 * @var string                           $cancel_action
 * @var string                           $cancel_nonce_action
 * @var string                           $cancel_nonce_name
 */

defined( 'ABSPATH' ) || exit;

use ReverseForwarding\Frontend\Forwarding_Center;
use ReverseForwarding\Frontend\Package_Actions;

$pending_packages = isset( $pending_packages ) && is_array( $pending_packages ) ? $pending_packages : array();
$inbound_packages = isset( $inbound_packages ) && is_array( $inbound_packages ) ? $inbound_packages : array();
$transit_packages = isset( $transit_packages ) && is_array( $transit_packages ) ? $transit_packages : array();
$active_tab       = isset( $active_tab ) ? sanitize_key( (string) $active_tab ) : 'pending';
$action_url       = isset( $action_url ) ? (string) $action_url : admin_url( 'admin-post.php' );
$redirect_to      = isset( $redirect_to ) ? (string) $redirect_to : '';

$consolidate_action       = isset( $consolidate_action ) ? (string) $consolidate_action : Package_Actions::ACTION_CONSOLIDATE;
$consolidate_nonce_action = isset( $consolidate_nonce_action ) ? (string) $consolidate_nonce_action : Package_Actions::ACTION_CONSOLIDATE;
$consolidate_nonce_name   = isset( $consolidate_nonce_name ) ? (string) $consolidate_nonce_name : Package_Actions::NONCE_CONSOLIDATE;
$cancel_action            = isset( $cancel_action ) ? (string) $cancel_action : Package_Actions::ACTION_CANCEL;
$cancel_nonce_action      = isset( $cancel_nonce_action ) ? (string) $cancel_nonce_action : Package_Actions::ACTION_CANCEL;
$cancel_nonce_name        = isset( $cancel_nonce_name ) ? (string) $cancel_nonce_name : Package_Actions::NONCE_CANCEL;

$tabs = array(
	'pending' => array(
		'label' => __( '待入库（已预报）', 'wc-rf' ),
		'empty' => __( '暂无已预报、待入库的包裹。', 'wc-rf' ),
		'items' => $pending_packages,
	),
	'inbound' => array(
		'label' => __( '已到仓待合箱', 'wc-rf' ),
		'empty' => __( '暂无已到仓、可合箱的包裹。', 'wc-rf' ),
		'items' => $inbound_packages,
	),
	'transit' => array(
		'label' => __( '转运中', 'wc-rf' ),
		'empty' => __( '暂无转运中的包裹。', 'wc-rf' ),
		'items' => $transit_packages,
	),
);

if ( ! isset( $tabs[ $active_tab ] ) ) {
	$active_tab = 'pending';
}

/**
 * Render a single package row.
 *
 * @param array<string, mixed> $package Package.
 * @param string               $tab     Tab key.
 */
$render_row = static function ( array $package, string $tab ): void {
	$id         = (int) $package['id'];
	$selectable = ! empty( $package['selectable'] );
	$weight     = (float) $package['weight'];
	$photos     = isset( $package['photo_ids'] ) && is_array( $package['photo_ids'] ) ? $package['photo_ids'] : array();
	$measured   = 'inbound' === $tab || 'transit' === $tab;
	?>
	<tr class="wc-rf-package-row">
		<td class="wc-rf-fc-table__check">
			<input
				type="checkbox"
				class="wc-rf-package-check"
				<?php echo $selectable ? 'name="package_ids[]"' : ''; ?>
				value="<?php echo esc_attr( (string) $id ); ?>"
				data-weight="<?php echo esc_attr( (string) $weight ); ?>"
				data-selectable="<?php echo $selectable ? '1' : '0'; ?>"
				aria-label="<?php esc_attr_e( '选择包裹', 'wc-rf' ); ?>"
				<?php disabled( ! $selectable ); ?>
			/>
		</td>
		<td data-title="<?php esc_attr_e( '快递单号', 'wc-rf' ); ?>">
			<strong class="wc-rf-fc-table__tracking"><?php echo esc_html( (string) $package['tracking_number'] ); ?></strong>
			<div class="wc-rf-fc-table__meta"><?php echo esc_html( (string) $package['express_company'] ); ?></div>
		</td>
		<td data-title="<?php esc_attr_e( '申报品名', 'wc-rf' ); ?>">
			<?php echo esc_html( (string) $package['declared_name'] ); ?>
		</td>
		<td data-title="<?php esc_attr_e( '仓储测量', 'wc-rf' ); ?>">
			<?php if ( $measured ) : ?>
				<div>
					<?php
					echo esc_html(
						$weight > 0
							? sprintf( /* translators: %s: kg */ __( '实重 %s kg', 'wc-rf' ), number_format_i18n( $weight, 3 ) )
							: __( '实重待录入', 'wc-rf' )
					);
					?>
				</div>
				<div class="wc-rf-fc-table__meta">
					<?php
					echo esc_html(
						'' !== (string) $package['volume_label']
							? sprintf( /* translators: %s: volume */ __( '体积 %s', 'wc-rf' ), (string) $package['volume_label'] )
							: __( '体积待录入', 'wc-rf' )
					);
					?>
				</div>
				<?php if ( ! empty( $package['shelf_location'] ) ) : ?>
					<div class="wc-rf-fc-table__meta">
						<?php echo esc_html( sprintf( /* translators: %s: shelf */ __( '货架 %s', 'wc-rf' ), (string) $package['shelf_location'] ) ); ?>
					</div>
				<?php endif; ?>
			<?php else : ?>
				<span class="wc-rf-fc-badge"><?php esc_html_e( '等待入库测量', 'wc-rf' ); ?></span>
			<?php endif; ?>
		</td>
		<td data-title="<?php esc_attr_e( '入库照片', 'wc-rf' ); ?>">
			<?php if ( $measured && $photos ) : ?>
				<div class="wc-rf-fc-photos">
					<?php foreach ( $photos as $photo_id ) : ?>
						<?php
						$thumb = wp_get_attachment_image( (int) $photo_id, 'thumbnail', false, array( 'class' => 'wc-rf-fc-photos__img' ) );
						$full  = wp_get_attachment_url( (int) $photo_id );
						if ( ! $thumb ) {
							continue;
						}
						if ( $full ) {
							printf(
								'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
								esc_url( $full ),
								$thumb // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_attachment_image().
							);
						} else {
							echo $thumb; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_attachment_image().
						}
						?>
					<?php endforeach; ?>
				</div>
			<?php else : ?>
				<span class="wc-rf-fc-table__meta">—</span>
			<?php endif; ?>
		</td>
		<td data-title="<?php esc_attr_e( '预报时间', 'wc-rf' ); ?>">
			<span class="wc-rf-fc-table__meta"><?php echo esc_html( (string) $package['created_at'] ); ?></span>
			<?php if ( 'pending' === $tab ) : ?>
				<button
					type="submit"
					class="wc-rf-fc-table__cancel"
					form="wc-rf-cancel-form"
					name="package_id"
					value="<?php echo esc_attr( (string) $id ); ?>"
				><?php esc_html_e( '撤销预报', 'wc-rf' ); ?></button>
			<?php endif; ?>
		</td>
	</tr>
	<?php
};
?>
<section class="wc-rf-fc-section" id="wc-rf-packages">
	<div class="wc-rf-fc-section__head">
		<div>
			<span class="wc-rf-fc-section__eyebrow"><?php esc_html_e( 'My Parcels', 'wc-rf' ); ?></span>
			<h2 class="wc-rf-fc-section__title"><?php esc_html_e( '我的包裹', 'wc-rf' ); ?></h2>
			<p class="wc-rf-fc-section__lead"><?php esc_html_e( '勾选「已到仓」包裹后，底部会汇总数量与预估重量。待入库和转运中的包裹不可合箱。', 'wc-rf' ); ?></p>
		</div>
		<div class="wc-rf-fc-tabs" role="tablist">
			<?php foreach ( $tabs as $tab_key => $tab ) : ?>
				<button
					type="button"
					class="wc-rf-fc-tabs__tab<?php echo $active_tab === $tab_key ? ' is-active' : ''; ?>"
					role="tab"
					aria-selected="<?php echo $active_tab === $tab_key ? 'true' : 'false'; ?>"
					data-wc-rf-tab="<?php echo esc_attr( $tab_key ); ?>"
				>
					<?php echo esc_html( $tab['label'] ); ?>
					<span class="wc-rf-fc-tabs__count"><?php echo esc_html( (string) count( $tab['items'] ) ); ?></span>
				</button>
			<?php endforeach; ?>
		</div>
	</div>

	<form class="wc-rf-fc-packages" method="post" action="<?php echo esc_url( $action_url ); ?>" id="wc-rf-packages-form">
		<?php wp_nonce_field( $consolidate_nonce_action, $consolidate_nonce_name ); ?>
		<input type="hidden" name="action" value="<?php echo esc_attr( $consolidate_action ); ?>" />
		<input type="hidden" name="redirect_to" value="<?php echo esc_url( $redirect_to ); ?>" />

		<?php foreach ( $tabs as $tab_key => $tab ) : ?>
			<div
				class="wc-rf-fc-panel<?php echo $active_tab === $tab_key ? ' is-active' : ''; ?>"
				data-wc-rf-panel="<?php echo esc_attr( $tab_key ); ?>"
				role="tabpanel"
			>
				<?php if ( empty( $tab['items'] ) ) : ?>
					<div class="wc-rf-fc-empty">
						<span class="wc-rf-fc-empty__icon"><?php echo Forwarding_Center::icon( 'box' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<p><?php echo esc_html( $tab['empty'] ); ?></p>
						<?php if ( 'pending' === $tab_key ) : ?>
							<a class="wc-rf-btn wc-rf-btn--sm" href="#wc-rf-declare"><?php esc_html_e( '去预报包裹', 'wc-rf' ); ?></a>
						<?php endif; ?>
					</div>
				<?php else : ?>
					<div class="wc-rf-fc-table-wrap">
						<table class="wc-rf-fc-table">
							<thead>
								<tr>
									<th class="wc-rf-fc-table__check">
										<?php if ( 'inbound' === $tab_key ) : ?>
											<input type="checkbox" class="wc-rf-select-all" data-wc-rf-select-all="<?php echo esc_attr( $tab_key ); ?>" aria-label="<?php esc_attr_e( '全选', 'wc-rf' ); ?>" />
										<?php endif; ?>
									</th>
									<th><?php esc_html_e( '快递单号', 'wc-rf' ); ?></th>
									<th><?php esc_html_e( '申报品名', 'wc-rf' ); ?></th>
									<th><?php esc_html_e( '仓储测量', 'wc-rf' ); ?></th>
									<th><?php esc_html_e( '入库照片', 'wc-rf' ); ?></th>
									<th><?php esc_html_e( '预报时间', 'wc-rf' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $tab['items'] as $package ) : ?>
									<?php $render_row( $package, $tab_key ); ?>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>

		<div class="wc-rf-fc-bar">
			<p class="wc-rf-fc-bar__summary">
				<?php
				printf(
					/* translators: 1: selected count, 2: estimated weight */
					esc_html__( '已选 %1$s 个包裹，预估重量 %2$s kg', 'wc-rf' ),
					'<strong data-wc-rf-selected-count>0</strong>',
					'<strong data-wc-rf-selected-weight>0.000</strong>'
				);
				?>
			</p>
			<button type="submit" class="wc-rf-btn" id="wc-rf-consolidate-submit" disabled>
				<?php echo Forwarding_Center::icon( 'box' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php esc_html_e( '申请合箱打包', 'wc-rf' ); ?>
			</button>
		</div>
	</form>

	<form id="wc-rf-cancel-form" class="wc-rf-fc-hidden" method="post" action="<?php echo esc_url( $action_url ); ?>">
		<?php wp_nonce_field( $cancel_nonce_action, $cancel_nonce_name ); ?>
		<input type="hidden" name="action" value="<?php echo esc_attr( $cancel_action ); ?>" />
		<input type="hidden" name="redirect_to" value="<?php echo esc_url( $redirect_to ); ?>" />
	</form>
</section>
