<?php
/**
 * Account parcels list.
 *
 * Variables are set by MyEasyBuy_Customer_Parcels::render_endpoint().
 *
 * @package MyEasyBuy_Core
 *
 * @var array{tab: string, q: string, from: string, to: string, active: bool} $filters
 * @var array{rows: array<int, array<string, mixed>>, total: int, pages: int} $result
 * @var array<string, string>                                                 $tabs
 * @var int                                                                   $page
 */

defined( 'ABSPATH' ) || exit;

$steps    = MyEasyBuy_Customer_Parcels::flow_steps();
$rows     = $result['rows'];
$is_empty = empty( $rows );
?>
<div class="meb-parcels">
	<ol class="meb-parcels__flow" aria-label="<?php esc_attr_e( '包裹流程', 'myeasybuy-core' ); ?>">
		<?php foreach ( $steps as $index => $step ) : ?>
			<?php if ( $index > 0 ) : ?>
				<li class="meb-parcels__flow-arrow" aria-hidden="true">
					<svg viewBox="0 0 20 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M1 1.5 6 6 1 10.5M11 1.5 16 6 11 10.5"/></svg>
				</li>
			<?php endif; ?>
			<li class="meb-parcels__flow-step">
				<span class="meb-parcels__flow-num"><?php echo esc_html( $step['num'] ); ?></span>
				<span class="meb-parcels__flow-label"><?php echo esc_html( $step['label'] ); ?></span>
			</li>
		<?php endforeach; ?>
	</ol>

	<nav class="meb-parcels__tabs" aria-label="<?php esc_attr_e( '包裹分类', 'myeasybuy-core' ); ?>">
		<?php foreach ( $tabs as $key => $label ) : ?>
			<a class="meb-parcels__tab<?php echo $filters['tab'] === $key ? ' is-active' : ''; ?>" href="<?php echo esc_url( MyEasyBuy_Customer_Parcels::page_url( array( 'tab' => $key ), 1 ) ); ?>"<?php echo $filters['tab'] === $key ? ' aria-current="page"' : ''; ?>>
				<?php echo esc_html( $label ); ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<form class="meb-parcels__filters" method="get" action="<?php echo esc_url( MyEasyBuy_Customer_Parcels::get_endpoint_url() ); ?>">
		<?php if ( 'all' !== $filters['tab'] ) : ?>
			<input type="hidden" name="meb_tab" value="<?php echo esc_attr( $filters['tab'] ); ?>">
		<?php endif; ?>

		<label class="meb-parcels__search">
			<span class="meb-parcels__icon" aria-hidden="true">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
			</span>
			<input type="search" name="meb_q" value="<?php echo esc_attr( $filters['q'] ); ?>" placeholder="<?php esc_attr_e( '名称/订单号/商品名称', 'myeasybuy-core' ); ?>" aria-label="<?php esc_attr_e( '名称/订单号/商品名称', 'myeasybuy-core' ); ?>">
		</label>

		<div class="meb-parcels__dates">
			<span class="meb-parcels__icon" aria-hidden="true">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg>
			</span>
			<input type="text" name="meb_from" value="<?php echo esc_attr( $filters['from'] ); ?>" placeholder="<?php esc_attr_e( '开始日期', 'myeasybuy-core' ); ?>" aria-label="<?php esc_attr_e( '开始日期', 'myeasybuy-core' ); ?>" inputmode="numeric" data-meb-date autocomplete="off">
			<span class="meb-parcels__dates-sep" aria-hidden="true">-</span>
			<input type="text" name="meb_to" value="<?php echo esc_attr( $filters['to'] ); ?>" placeholder="<?php esc_attr_e( '结束日期', 'myeasybuy-core' ); ?>" aria-label="<?php esc_attr_e( '结束日期', 'myeasybuy-core' ); ?>" inputmode="numeric" data-meb-date autocomplete="off">
		</div>

		<button type="submit" class="meb-parcels__submit"><?php esc_html_e( '搜索', 'myeasybuy-core' ); ?></button>
	</form>

	<?php if ( $is_empty ) : ?>
		<div class="meb-parcels__empty">
			<div class="meb-parcels__box" aria-hidden="true">
				<svg viewBox="0 0 180 150">
					<ellipse cx="90" cy="136" rx="42" ry="7" fill="#eef2f7"/>
					<path d="M42 78 L90 100 L90 128 L42 106 Z" fill="#e7eef6"/>
					<path d="M90 100 L138 78 L138 106 L90 128 Z" fill="#d7e0ec"/>
					<path d="M42 78 L90 56 L138 78 L90 100 Z" fill="#f7f9fc"/>
					<path d="M42 78 L24 56 L72 36 L90 56 Z" fill="#eef3f8"/>
					<path d="M138 78 L156 56 L108 36 L90 56 Z" fill="#e4ebf4"/>
					<path d="M72 36 L90 28 L108 36 L90 56 Z" fill="#f4f7fb"/>
					<path d="M62 78 L90 92 L118 78 L90 64 Z" fill="#f0f4f9"/>
					<circle cx="124" cy="42" r="18" fill="#ff7e21" opacity="0.18"/>
					<circle cx="124" cy="42" r="13" fill="#ff7e21"/>
					<path d="M124 35.2 v8.2" stroke="#fff" stroke-width="2.4" stroke-linecap="round"/>
					<circle cx="124" cy="47.4" r="1.35" fill="#fff"/>
				</svg>
			</div>
			<p>
				<?php
				echo esc_html(
					$filters['active']
						? __( '没有符合条件的包裹', 'myeasybuy-core' )
						: __( '包裹订单支付成功后，订单将进入“包裹”页面，我们将尽快安排发货。', 'myeasybuy-core' )
				);
				?>
			</p>
		</div>
	<?php else : ?>
		<ul class="meb-parcels__list">
			<?php foreach ( $rows as $row ) : ?>
				<li class="meb-parcels__card">
					<a class="meb-parcels__media" href="<?php echo esc_url( (string) $row['view'] ); ?>">
						<?php
						if ( ! empty( $row['thumb'] ) ) {
							echo wp_kses_post( (string) $row['thumb'] );
						}
						?>
					</a>
					<div class="meb-parcels__info">
						<a class="meb-parcels__name" href="<?php echo esc_url( (string) $row['view'] ); ?>"><?php echo esc_html( (string) $row['name'] ); ?></a>
						<p class="meb-parcels__meta">
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: order number, 2: date */
									__( '订单号 %1$s · %2$s', 'myeasybuy-core' ),
									(string) $row['number'],
									(string) $row['date']
								)
							);
							?>
						</p>
						<?php if ( (int) $row['count'] > 1 ) : ?>
							<p class="meb-parcels__meta">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %d: item count */
										__( '共 %d 件商品', 'myeasybuy-core' ),
										(int) $row['count']
									)
								);
								?>
							</p>
						<?php endif; ?>
					</div>
					<div class="meb-parcels__side">
						<span class="meb-parcels__badge"><?php echo esc_html( (string) $row['status'] ); ?></span>
						<strong class="meb-parcels__total"><?php echo esc_html( (string) $row['total'] ); ?></strong>
						<a class="meb-parcels__view" href="<?php echo esc_url( (string) $row['view'] ); ?>"><?php esc_html_e( '查看', 'myeasybuy-core' ); ?></a>
						<?php if ( '' !== (string) $row['pay'] ) : ?>
							<a class="meb-parcels__pay" href="<?php echo esc_url( (string) $row['pay'] ); ?>"><?php esc_html_e( '去付款', 'myeasybuy-core' ); ?></a>
						<?php endif; ?>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>

		<?php if ( $result['pages'] > 1 ) : ?>
			<nav class="meb-parcels__pager" aria-label="<?php esc_attr_e( '包裹分页', 'myeasybuy-core' ); ?>">
				<?php if ( $page > 1 ) : ?>
					<a href="<?php echo esc_url( MyEasyBuy_Customer_Parcels::page_url( array(), $page - 1 ) ); ?>"><?php esc_html_e( '上一页', 'myeasybuy-core' ); ?></a>
				<?php endif; ?>
				<span><?php echo esc_html( sprintf( '%d / %d', $page, $result['pages'] ) ); ?></span>
				<?php if ( $page < $result['pages'] ) : ?>
					<a href="<?php echo esc_url( MyEasyBuy_Customer_Parcels::page_url( array(), $page + 1 ) ); ?>"><?php esc_html_e( '下一页', 'myeasybuy-core' ); ?></a>
				<?php endif; ?>
			</nav>
		<?php endif; ?>
	<?php endif; ?>
</div>
