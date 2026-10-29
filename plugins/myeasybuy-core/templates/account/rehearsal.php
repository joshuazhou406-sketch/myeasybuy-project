<?php
/**
 * Account parcel rehearsal list.
 *
 * Variables are set by MyEasyBuy_Customer_Rehearsal::render_endpoint().
 *
 * @package MyEasyBuy_Core
 *
 * @var array{tab: string, q: string, from: string, to: string, active: bool}     $filters
 * @var array{rows: array<int, array<string, mixed>>, counts: array<string, int>} $result
 * @var array<string, string>                                                     $tabs
 * @var array<int, array{key: string, label: string, state: string}>              $steps
 */

defined( 'ABSPATH' ) || exit;

$rows     = $result['rows'];
$counts   = $result['counts'];
$is_empty = empty( $rows );
$labels   = MyEasyBuy_Customer_Rehearsal::statuses();
?>
<div class="meb-rh">
	<div class="meb-rh__crumb">
		<span><?php esc_html_e( '包裹预演流程', 'myeasybuy-core' ); ?></span>
		<button type="button" class="meb-rh__guide-btn" data-meb-guide aria-expanded="false" aria-controls="meb-rh-guide">
			<?php esc_html_e( '包裹预演操作流程', 'myeasybuy-core' ); ?> &gt;
		</button>
	</div>

	<ol class="meb-rh__guide" id="meb-rh-guide" data-meb-guide-panel hidden>
		<li><?php esc_html_e( '在我的仓库勾选已入库商品，提交预演。', 'myeasybuy-core' ); ?></li>
		<li><?php esc_html_e( '仓库完成图清预演，确认实际重量和尺寸。', 'myeasybuy-core' ); ?></li>
		<li><?php esc_html_e( '确认发送路线和运费。', 'myeasybuy-core' ); ?></li>
		<li><?php esc_html_e( '支付后等待收货。', 'myeasybuy-core' ); ?></li>
		<li><?php esc_html_e( '收到包裹后确认收货。', 'myeasybuy-core' ); ?></li>
	</ol>

	<ol class="meb-rh__flow" aria-label="<?php esc_attr_e( '包裹预演流程', 'myeasybuy-core' ); ?>">
		<?php foreach ( $steps as $index => $step ) : ?>
			<?php if ( $index > 0 ) : ?>
				<li class="meb-rh__line" aria-hidden="true"></li>
			<?php endif; ?>
			<li>
				<div class="meb-rh__step is-<?php echo esc_attr( $step['state'] ); ?>"<?php echo 'current' === $step['state'] ? ' aria-current="step"' : ''; ?>>
					<span class="meb-rh__icon" aria-hidden="true"><?php echo MyEasyBuy_Customer_Rehearsal::icon( $step['key'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<span><?php echo esc_html( $step['label'] ); ?></span>
				</div>
			</li>
		<?php endforeach; ?>
	</ol>

	<nav class="meb-rh__tabs" aria-label="<?php esc_attr_e( '预演分类', 'myeasybuy-core' ); ?>">
		<?php foreach ( $tabs as $key => $label ) : ?>
			<?php
			$text = $label;
			if ( isset( $counts[ $key ] ) ) {
				$text .= '(' . (int) $counts[ $key ] . ')';
			}
			?>
			<a class="meb-rh__tab<?php echo $filters['tab'] === $key ? ' is-active' : ''; ?>" href="<?php echo esc_url( MyEasyBuy_Customer_Rehearsal::page_url( array( 'tab' => $key ) ) ); ?>"<?php echo $filters['tab'] === $key ? ' aria-current="page"' : ''; ?>>
				<?php echo esc_html( $text ); ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<form class="meb-rh__filters" method="get" action="<?php echo esc_url( MyEasyBuy_Customer_Rehearsal::get_endpoint_url() ); ?>">
		<?php if ( 'all' !== $filters['tab'] ) : ?>
			<input type="hidden" name="meb_tab" value="<?php echo esc_attr( $filters['tab'] ); ?>">
		<?php endif; ?>

		<label class="meb-rh__field">
			<span><?php esc_html_e( '包裹编号', 'myeasybuy-core' ); ?></span>
			<input type="search" name="meb_q" value="<?php echo esc_attr( $filters['q'] ); ?>" placeholder="<?php esc_attr_e( '包裹编号', 'myeasybuy-core' ); ?>" aria-label="<?php esc_attr_e( '包裹编号', 'myeasybuy-core' ); ?>">
		</label>

		<div class="meb-rh__field">
			<span><?php esc_html_e( '创建时间', 'myeasybuy-core' ); ?></span>
			<div class="meb-rh__dates">
				<input type="text" name="meb_from" value="<?php echo esc_attr( $filters['from'] ); ?>" placeholder="<?php esc_attr_e( '开始时间', 'myeasybuy-core' ); ?>" aria-label="<?php esc_attr_e( '开始时间', 'myeasybuy-core' ); ?>" inputmode="numeric" data-meb-date autocomplete="off">
				<span class="meb-rh__sep" aria-hidden="true">-</span>
				<input type="text" name="meb_to" value="<?php echo esc_attr( $filters['to'] ); ?>" placeholder="<?php esc_attr_e( '结束时间', 'myeasybuy-core' ); ?>" aria-label="<?php esc_attr_e( '结束时间', 'myeasybuy-core' ); ?>" inputmode="numeric" data-meb-date autocomplete="off">
				<a class="meb-rh__reset" href="<?php echo esc_url( MyEasyBuy_Customer_Rehearsal::page_url( array( 'q' => '', 'from' => '', 'to' => '' ) ) ); ?>" aria-label="<?php esc_attr_e( '重置', 'myeasybuy-core' ); ?>">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 12a8 8 0 1 1-2.3-5.7"/><path d="M20 4v5h-5"/></svg>
				</a>
			</div>
		</div>

		<button type="submit" class="meb-rh__submit" aria-label="<?php esc_attr_e( '搜索', 'myeasybuy-core' ); ?>">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="11" cy="11" r="6.5"/><path d="m16 16 4 4"/></svg>
		</button>
	</form>

	<div class="meb-rh__scroll">
		<table class="meb-rh__table">
			<thead>
				<tr>
					<th><?php esc_html_e( '包裹信息', 'myeasybuy-core' ); ?></th>
					<th><?php esc_html_e( '重量(克)', 'myeasybuy-core' ); ?></th>
					<th><?php esc_html_e( '尺寸(cm)', 'myeasybuy-core' ); ?></th>
					<th><?php esc_html_e( '发送路线', 'myeasybuy-core' ); ?></th>
					<th><?php esc_html_e( '运费', 'myeasybuy-core' ); ?></th>
					<th><?php esc_html_e( '状态', 'myeasybuy-core' ); ?></th>
					<th><?php esc_html_e( '操作', 'myeasybuy-core' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( $is_empty ) : ?>
					<tr class="meb-rh__empty">
						<td colspan="7">
							<?php if ( $filters['active'] ) : ?>
								<p><?php esc_html_e( '没有符合条件的包裹', 'myeasybuy-core' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<?php
						$names  = isset( $row['names'] ) && is_array( $row['names'] ) ? $row['names'] : array();
						$status = (string) ( $row['status'] ?? '' );
						$weight = (int) ( $row['weight_g'] ?? 0 );
						$size   = trim( (string) ( $row['dimensions'] ?? '' ) );
						$route  = trim( (string) ( $row['route'] ?? '' ) );
						$fee    = trim( (string) ( $row['fee'] ?? '' ) );
						$title  = (string) ( $names[0] ?? '' );
						$extra  = count( $names ) - 1;
						if ( $extra > 0 ) {
							$title .= sprintf(
								/* translators: %d: item count */
								__( ' 等%d件', 'myeasybuy-core' ),
								count( $names )
							);
						}
						?>
						<tr>
							<td>
								<div class="meb-rh__parcel">
									<strong><?php echo esc_html( (string) $row['id'] ); ?></strong>
									<?php if ( '' !== $title ) : ?>
										<span><?php echo esc_html( $title ); ?></span>
									<?php endif; ?>
									<span class="meb-rh__time"><?php echo esc_html( (string) ( $row['created_at'] ?? '' ) ); ?></span>
								</div>
							</td>
							<td><?php echo esc_html( $weight > 0 ? (string) $weight : '—' ); ?></td>
							<td><?php echo esc_html( '' !== $size ? $size : '—' ); ?></td>
							<td><?php echo esc_html( '' !== $route ? $route : '—' ); ?></td>
							<td><?php echo esc_html( '' !== $fee ? $fee : '—' ); ?></td>
							<td><span class="meb-rh__status is-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $labels[ $status ] ?? $status ); ?></span></td>
							<td>
								<?php if ( in_array( $status, array( 'processing', 'unpaid' ), true ) ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-meb-cancel>
										<?php wp_nonce_field( 'myeasybuy_cancel_rehearsal', 'myeasybuy_rehearsal_nonce' ); ?>
										<input type="hidden" name="action" value="myeasybuy_cancel_rehearsal">
										<input type="hidden" name="rehearsal_id" value="<?php echo esc_attr( (string) $row['id'] ); ?>">
										<button type="submit" class="meb-rh__cancel"><?php esc_html_e( '取消', 'myeasybuy-core' ); ?></button>
									</form>
								<?php else : ?>
									—
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>
</div>
