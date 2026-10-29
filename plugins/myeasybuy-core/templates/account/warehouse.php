<?php
/**
 * Account warehouse.
 *
 * Variables are set by MyEasyBuy_Customer_Warehouse::render_endpoint().
 *
 * @package MyEasyBuy_Core
 *
 * @var array{tab: string, q: string, cover: string, from: string, to: string, per: int, active: bool} $filters
 * @var array{rows: array<int, array<string, mixed>>, total: int, pages: int, page: int}             $result
 * @var array<string, string>                                                                        $tabs
 * @var array<int, array{key: string, label: string, state: string, url: string}>                    $steps
 * @var int                                                                                          $days
 * @var array<string, string>                                                                        $channels
 * @var string                                                                                       $air
 * @var int[]                                                                                        $preselected
 */

defined( 'ABSPATH' ) || exit;

$rows     = $result['rows'];
$page     = (int) $result['page'];
$is_empty = empty( $rows );
$is_ready = 'ready' === $filters['tab'];
$hint     = $is_ready
	? __( '请先选择要创建包裹的商品', 'myeasybuy-core' )
	: __( '这些商品暂不可创建包裹', 'myeasybuy-core' );
?>
<div class="meb-wh" data-block-hint="<?php echo esc_attr( $hint ); ?>"<?php echo $preselected ? ' data-open-create="1"' : ''; ?>>
	<ol class="meb-wh__flow" aria-label="<?php esc_attr_e( '包裹流程', 'myeasybuy-core' ); ?>">
		<?php foreach ( $steps as $index => $step ) : ?>
			<?php if ( $index > 0 ) : ?>
				<li class="meb-wh__line<?php echo 1 === $index ? ' is-solid' : ''; ?>" aria-hidden="true"></li>
			<?php endif; ?>
			<li>
				<?php
				$step_class = 'meb-wh__step is-' . $step['state'];
				$icon       = MyEasyBuy_Customer_Warehouse::icon( $step['key'] );
				if ( '' !== $step['url'] ) :
					?>
					<a class="<?php echo esc_attr( $step_class ); ?>" href="<?php echo esc_url( $step['url'] ); ?>">
						<span class="meb-wh__icon" aria-hidden="true"><?php echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<span><?php echo esc_html( $step['label'] ); ?></span>
					</a>
				<?php else : ?>
					<div class="<?php echo esc_attr( $step_class ); ?>"<?php echo 'current' === $step['state'] ? ' aria-current="step"' : ''; ?>>
						<span class="meb-wh__icon" aria-hidden="true"><?php echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<span><?php echo esc_html( $step['label'] ); ?></span>
					</div>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ol>

	<div class="meb-wh__head">
		<nav class="meb-wh__tabs" aria-label="<?php esc_attr_e( '仓库分类', 'myeasybuy-core' ); ?>">
			<?php foreach ( $tabs as $key => $label ) : ?>
				<a class="meb-wh__tab<?php echo $filters['tab'] === $key ? ' is-active' : ''; ?>" href="<?php echo esc_url( MyEasyBuy_Customer_Warehouse::page_url( array( 'tab' => $key ), 1 ) ); ?>"<?php echo $filters['tab'] === $key ? ' aria-current="page"' : ''; ?>>
					<?php echo esc_html( $label ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<button type="button" class="meb-wh__assist" data-meb-help aria-expanded="false" aria-controls="meb-wh-help">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M5 16v-1a7 7 0 0 1 14 0v1"/><rect x="3" y="15" width="4" height="6" rx="1.5"/><rect x="17" y="15" width="4" height="6" rx="1.5"/></svg>
			<?php esc_html_e( '仓库助手', 'myeasybuy-core' ); ?>
		</button>
	</div>

	<div class="meb-wh__help" id="meb-wh-help" data-meb-help-panel hidden>
		<p><?php esc_html_e( '已入库并完成验货的商品，可以勾选后创建包裹。', 'myeasybuy-core' ); ?></p>
		<p><?php esc_html_e( '还在代购或国内运输中的商品，放在「暂不可创建包裹」。', 'myeasybuy-core' ); ?></p>
		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: free storage days */
					__( '入库后免费保管 %d 天。多个商品可以一起提交，仓库合箱后再支付国际运费。', 'myeasybuy-core' ),
					(int) $days
				)
			);
			?>
		</p>
	</div>

	<form class="meb-wh__filters" method="get" action="<?php echo esc_url( MyEasyBuy_Customer_Warehouse::get_endpoint_url() ); ?>">
		<?php if ( ! $is_ready ) : ?>
			<input type="hidden" name="meb_tab" value="<?php echo esc_attr( $filters['tab'] ); ?>">
		<?php endif; ?>
		<?php if ( 50 !== (int) $filters['per'] ) : ?>
			<input type="hidden" name="meb_per" value="<?php echo esc_attr( (string) $filters['per'] ); ?>">
		<?php endif; ?>

		<label class="meb-wh__field">
			<span><?php esc_html_e( '订单编号', 'myeasybuy-core' ); ?></span>
			<input type="search" name="meb_q" value="<?php echo esc_attr( $filters['q'] ); ?>" placeholder="<?php esc_attr_e( '商品名称 / 订单编号', 'myeasybuy-core' ); ?>" aria-label="<?php esc_attr_e( '商品名称 / 订单编号', 'myeasybuy-core' ); ?>">
		</label>

		<label class="meb-wh__field">
			<span><?php esc_html_e( '保障服务支持状态', 'myeasybuy-core' ); ?></span>
			<span class="meb-wh__select">
				<select name="meb_cover" aria-label="<?php esc_attr_e( '保障服务支持状态', 'myeasybuy-core' ); ?>">
					<option value=""><?php esc_html_e( '全部', 'myeasybuy-core' ); ?></option>
					<option value="yes"<?php selected( $filters['cover'], 'yes' ); ?>><?php esc_html_e( '支持', 'myeasybuy-core' ); ?></option>
					<option value="no"<?php selected( $filters['cover'], 'no' ); ?>><?php esc_html_e( '不支持', 'myeasybuy-core' ); ?></option>
				</select>
			</span>
		</label>

		<div class="meb-wh__field">
			<span><?php esc_html_e( '创建时间', 'myeasybuy-core' ); ?></span>
			<div class="meb-wh__dates">
				<input type="text" name="meb_from" value="<?php echo esc_attr( $filters['from'] ); ?>" placeholder="<?php esc_attr_e( '开始时间', 'myeasybuy-core' ); ?>" aria-label="<?php esc_attr_e( '开始时间', 'myeasybuy-core' ); ?>" inputmode="numeric" data-meb-date autocomplete="off">
				<span class="meb-wh__sep" aria-hidden="true">-</span>
				<input type="text" name="meb_to" value="<?php echo esc_attr( $filters['to'] ); ?>" placeholder="<?php esc_attr_e( '结束时间', 'myeasybuy-core' ); ?>" aria-label="<?php esc_attr_e( '结束时间', 'myeasybuy-core' ); ?>" inputmode="numeric" data-meb-date autocomplete="off">
				<a class="meb-wh__reset" href="<?php echo esc_url( MyEasyBuy_Customer_Warehouse::page_url( array( 'q' => '', 'cover' => '', 'from' => '', 'to' => '' ), 1 ) ); ?>" aria-label="<?php esc_attr_e( '重置', 'myeasybuy-core' ); ?>">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 12a8 8 0 1 1-2.3-5.7"/><path d="M20 4v5h-5"/></svg>
				</a>
			</div>
		</div>

		<button type="submit" class="meb-wh__submit" aria-label="<?php esc_attr_e( '搜索', 'myeasybuy-core' ); ?>">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="11" cy="11" r="6.5"/><path d="m16 16 4 4"/></svg>
		</button>
	</form>

	<div class="meb-wh__scroll">
		<table class="meb-wh__table">
			<thead>
				<tr>
					<th><?php esc_html_e( '商品信息', 'myeasybuy-core' ); ?></th>
					<th><?php esc_html_e( '入库时间', 'myeasybuy-core' ); ?></th>
					<th><?php esc_html_e( '数量', 'myeasybuy-core' ); ?></th>
					<th><?php esc_html_e( '重量(g)', 'myeasybuy-core' ); ?></th>
					<th><?php esc_html_e( '尺寸(cm)', 'myeasybuy-core' ); ?></th>
					<th><?php esc_html_e( '增值服务', 'myeasybuy-core' ); ?></th>
					<th><?php esc_html_e( '订单状态', 'myeasybuy-core' ); ?></th>
					<th><?php esc_html_e( '商品类型', 'myeasybuy-core' ); ?></th>
					<th><?php esc_html_e( '操作', 'myeasybuy-core' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( $is_empty ) : ?>
					<tr class="meb-wh__empty">
						<td colspan="9">
							<div class="meb-wh__box" aria-hidden="true">
								<svg viewBox="0 0 88 72" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
									<path d="M16 28 44 16l28 12v24L44 64 16 52z"/>
									<path d="M16 28l28 12 28-12"/>
									<path d="M44 40v24"/>
									<path d="M16 28 8 20l28-12 8 8"/>
									<path d="M72 28l8-8-28-12-8 8"/>
								</svg>
							</div>
							<p>
								<?php
								echo esc_html(
									$filters['active']
										? __( '没有符合条件的包裹', 'myeasybuy-core' )
										: __( '暂无数据', 'myeasybuy-core' )
								);
								?>
							</p>
						</td>
					</tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td>
								<div class="meb-wh__product">
									<?php if ( ! empty( $row['selectable'] ) ) : ?>
										<input class="meb-wh__check" type="checkbox" value="<?php echo esc_attr( (string) $row['id'] ); ?>" data-qty="<?php echo esc_attr( (string) $row['qty'] ); ?>" data-weight="<?php echo esc_attr( (string) $row['weight'] ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: product name */ __( '选择 %s', 'myeasybuy-core' ), (string) $row['name'] ) ); ?>"<?php checked( in_array( (int) $row['id'], $preselected, true ) ); ?>>
									<?php endif; ?>
									<?php if ( ! empty( $row['thumb'] ) ) : ?>
										<span class="meb-wh__media"><?php echo wp_kses_post( (string) $row['thumb'] ); ?></span>
									<?php else : ?>
										<span class="meb-wh__media meb-wh__media--empty" aria-hidden="true"></span>
									<?php endif; ?>
									<span class="meb-wh__copy">
										<a class="meb-wh__name" href="<?php echo esc_url( (string) $row['view'] ); ?>"><?php echo esc_html( (string) $row['name'] ); ?></a>
										<span class="meb-wh__sub">
											<?php
											echo esc_html(
												sprintf(
													/* translators: %s: order number */
													__( '订单编号 %s', 'myeasybuy-core' ),
													(string) $row['order_no']
												)
											);
											?>
										</span>
									</span>
								</div>
							</td>
							<td><?php echo esc_html( (string) $row['inbound'] ); ?></td>
							<td><?php echo esc_html( (string) $row['qty'] ); ?></td>
							<td><?php echo esc_html( '' !== (string) $row['weight'] ? (string) $row['weight'] : '—' ); ?></td>
							<td><?php echo esc_html( (string) $row['size'] ); ?></td>
							<td><?php echo esc_html( (string) $row['service'] ); ?></td>
							<td><?php echo esc_html( (string) $row['status'] ); ?></td>
							<td><?php echo esc_html( (string) $row['type'] ); ?></td>
							<td><a class="meb-wh__view" href="<?php echo esc_url( (string) $row['view'] ); ?>"><?php esc_html_e( '查看', 'myeasybuy-core' ); ?></a></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>

	<div class="meb-wh__pager">
		<?php if ( $page > 1 ) : ?>
			<a class="meb-wh__page" href="<?php echo esc_url( MyEasyBuy_Customer_Warehouse::page_url( array(), $page - 1 ) ); ?>" aria-label="<?php esc_attr_e( '上一页', 'myeasybuy-core' ); ?>">&lsaquo;</a>
		<?php else : ?>
			<span class="meb-wh__page is-disabled" aria-hidden="true">&lsaquo;</span>
		<?php endif; ?>
		<span class="meb-wh__page is-current"><?php echo esc_html( (string) $page ); ?></span>
		<?php if ( $page < (int) $result['pages'] ) : ?>
			<a class="meb-wh__page" href="<?php echo esc_url( MyEasyBuy_Customer_Warehouse::page_url( array(), $page + 1 ) ); ?>" aria-label="<?php esc_attr_e( '下一页', 'myeasybuy-core' ); ?>">&rsaquo;</a>
		<?php else : ?>
			<span class="meb-wh__page is-disabled" aria-hidden="true">&rsaquo;</span>
		<?php endif; ?>
		<label class="meb-wh__per">
			<select data-meb-per data-base="<?php echo esc_url( MyEasyBuy_Customer_Warehouse::page_url( array( 'per' => 50 ), 1 ) ); ?>" aria-label="<?php esc_attr_e( '每页条数', 'myeasybuy-core' ); ?>">
				<?php foreach ( array( 10, 20, 50 ) as $size ) : ?>
					<option value="<?php echo esc_attr( (string) $size ); ?>"<?php selected( (int) $filters['per'], $size ); ?>><?php echo esc_html( sprintf( /* translators: %d: page size */ __( '%d 条/页', 'myeasybuy-core' ), $size ) ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
	</div>

	<div class="meb-wh__bar">
		<label class="meb-wh__all">
			<input type="checkbox" data-meb-all <?php disabled( $is_empty || ! $is_ready ); ?>>
			<?php esc_html_e( '全选', 'myeasybuy-core' ); ?>
		</label>
		<div class="meb-wh__bar-actions">
			<button type="button" class="meb-wh__rehearse" data-meb-preview-open><?php esc_html_e( '包裹预演', 'myeasybuy-core' ); ?></button>
			<button type="button" class="meb-wh__create" data-meb-create><?php esc_html_e( '创建包裹', 'myeasybuy-core' ); ?></button>
		</div>
	</div>
	<p class="meb-wh__hint" data-meb-hint hidden></p>

	<div class="meb-wh__mask" data-meb-preview hidden>
		<div class="meb-wh__dialog" role="dialog" aria-modal="true" aria-labelledby="meb-wh-preview-title">
			<h3 id="meb-wh-preview-title"><?php esc_html_e( '包裹预演', 'myeasybuy-core' ); ?></h3>
			<dl class="meb-wh__stats">
				<div>
					<dt><?php esc_html_e( '已选商品', 'myeasybuy-core' ); ?></dt>
					<dd data-meb-preview-count>0</dd>
				</div>
				<div>
					<dt><?php esc_html_e( '数量', 'myeasybuy-core' ); ?></dt>
					<dd data-meb-preview-qty>0</dd>
				</div>
				<div>
					<dt><?php esc_html_e( '重量', 'myeasybuy-core' ); ?></dt>
					<dd data-meb-preview-weight>—</dd>
				</div>
			</dl>
			<p><?php esc_html_e( '提交预演后可在「包裹预演」查看进度。创建包裹时在本页选择物流渠道。', 'myeasybuy-core' ); ?></p>
			<div class="meb-wh__dialog-actions">
				<button type="button" class="meb-wh__rehearse" data-meb-preview-close><?php esc_html_e( '关闭', 'myeasybuy-core' ); ?></button>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-meb-rehearsal-form>
					<?php wp_nonce_field( 'myeasybuy_request_rehearsal', 'myeasybuy_rehearsal_nonce' ); ?>
					<input type="hidden" name="action" value="myeasybuy_request_rehearsal">
					<span data-meb-rehearsal-ids></span>
					<button type="submit" class="meb-wh__submit-rehearsal"><?php esc_html_e( '提交预演', 'myeasybuy-core' ); ?></button>
				</form>
				<button type="button" class="meb-wh__create" data-meb-create><?php esc_html_e( '创建包裹', 'myeasybuy-core' ); ?></button>
			</div>
		</div>
	</div>

	<div class="meb-wh__mask" data-meb-create-dialog hidden>
		<div class="meb-wh__dialog meb-wh__dialog--create" role="dialog" aria-modal="true" aria-labelledby="meb-wh-create-title">
			<h3 id="meb-wh-create-title"><?php esc_html_e( '创建包裹', 'myeasybuy-core' ); ?></h3>
			<p><?php esc_html_e( '请选择发往加拿大的物流渠道。提交后仓库开始合箱，称重后会生成国际运费账单。', 'myeasybuy-core' ); ?></p>
			<p class="meb-wh__picked"><?php esc_html_e( '已选', 'myeasybuy-core' ); ?> <strong data-meb-create-count>0</strong> <?php esc_html_e( '个商品', 'myeasybuy-core' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-meb-create-form>
				<?php wp_nonce_field( 'myeasybuy_request_consolidation', 'myeasybuy_consolidation_nonce' ); ?>
				<input type="hidden" name="action" value="myeasybuy_request_consolidation">
				<input type="hidden" name="redirect_to" value="<?php echo esc_url( MyEasyBuy_Customer_Warehouse::get_endpoint_url() ); ?>">
				<span data-meb-create-ids></span>
				<fieldset class="meb-wh__channels">
					<legend><?php esc_html_e( '物流渠道', 'myeasybuy-core' ); ?></legend>
					<?php foreach ( $channels as $slug => $label ) : ?>
						<label class="meb-wh__channel">
							<span class="meb-wh__channel-line">
								<input type="radio" name="channel" value="<?php echo esc_attr( $slug ); ?>" required>
								<span class="meb-wh__channel-label"><?php echo esc_html( $label ); ?></span>
							</span>
							<span class="meb-wh__channel-hint">
								<?php
								echo esc_html(
									$air === $slug
										? __( '时效较快，按实重与体积重取大计费。', 'myeasybuy-core' )
										: __( '更经济，适合大件或不急的包裹，按实重计费。', 'myeasybuy-core' )
								);
								?>
							</span>
						</label>
					<?php endforeach; ?>
				</fieldset>
				<div class="meb-wh__dialog-actions">
					<button type="button" class="meb-wh__rehearse" data-meb-create-close><?php esc_html_e( '取消', 'myeasybuy-core' ); ?></button>
					<button type="submit" class="meb-wh__submit-rehearsal"><?php esc_html_e( '提交', 'myeasybuy-core' ); ?></button>
				</div>
			</form>
		</div>
	</div>
</div>
