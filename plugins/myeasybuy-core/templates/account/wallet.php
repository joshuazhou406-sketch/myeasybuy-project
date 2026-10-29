<?php
/**
 * Account wallet.
 *
 * Variables are set by MyEasyBuy_Customer_Wallet::render_endpoint().
 *
 * @package MyEasyBuy_Core
 *
 * @var int                                                                 $balance
 * @var array{tab: string, from: string, to: string, type: string, panel: string} $filters
 * @var array<int, array{id: string, type: string, cents: int, balance: int, note: string, time: int}> $rows
 * @var array<int, array{title: string, amount: string, rule: string, expires: string}> $coupons
 * @var array<string, string>                                              $types
 * @var array<string, string>                                              $tabs
 * @var bool                                                               $need_pin
 */

defined( 'ABSPATH' ) || exit;

$is_balance = 'balance' === $filters['tab'];
$cny        = MyEasyBuy_Customer_Wallet::format_amount( $balance );
$cad        = MyEasyBuy_Customer_Wallet::format_cad( $balance );
?>
<div class="meb-wallet">
	<nav class="meb-wallet__tabs" aria-label="<?php esc_attr_e( '钱包', 'myeasybuy-core' ); ?>">
		<?php foreach ( $tabs as $key => $label ) : ?>
			<a class="meb-wallet__tab<?php echo $filters['tab'] === $key ? ' is-active' : ''; ?>" href="<?php echo esc_url( MyEasyBuy_Customer_Wallet::page_url( array( 'tab' => $key ) ) ); ?>"<?php echo $filters['tab'] === $key ? ' aria-current="page"' : ''; ?>>
				<?php echo esc_html( $label ); ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<?php if ( $is_balance ) : ?>
		<section class="meb-wallet__banner" aria-label="<?php esc_attr_e( '余额', 'myeasybuy-core' ); ?>">
			<p class="meb-wallet__balance">
				<span class="meb-wallet__coin" aria-hidden="true">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
						<circle cx="12" cy="12" r="9"/>
						<rect x="7" y="9" width="10" height="7" rx="1.2"/>
						<path d="M7 11.5h10"/>
					</svg>
				</span>
				<span><?php esc_html_e( '余额', 'myeasybuy-core' ); ?></span>
				<strong>CNY <?php echo esc_html( $cny ); ?></strong>
				<span class="meb-wallet__approx">≈</span>
				<strong>C$ <?php echo esc_html( $cad ); ?></strong>
			</p>
			<div class="meb-wallet__actions">
				<button type="button" class="meb-wallet__recharge" data-meb-wallet-open="recharge"><?php esc_html_e( '充值', 'myeasybuy-core' ); ?></button>
				<button type="button" class="meb-wallet__withdraw" data-meb-wallet-open="withdraw"><?php esc_html_e( '提现', 'myeasybuy-core' ); ?></button>
			</div>
		</section>

		<form class="meb-wallet__filters" method="get" action="<?php echo esc_url( MyEasyBuy_Customer_Wallet::get_endpoint_url() ); ?>">
			<span class="meb-wallet__filters-label"><?php esc_html_e( '余额详情', 'myeasybuy-core' ); ?></span>
			<div class="meb-wallet__dates">
				<span class="meb-wallet__icon" aria-hidden="true">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg>
				</span>
				<input type="text" name="meb_from" value="<?php echo esc_attr( $filters['from'] ); ?>" placeholder="<?php esc_attr_e( '开始日期', 'myeasybuy-core' ); ?>" aria-label="<?php esc_attr_e( '开始日期', 'myeasybuy-core' ); ?>" inputmode="numeric" data-meb-date autocomplete="off">
				<span class="meb-wallet__dates-sep" aria-hidden="true">-</span>
				<input type="text" name="meb_to" value="<?php echo esc_attr( $filters['to'] ); ?>" placeholder="<?php esc_attr_e( '结束日期', 'myeasybuy-core' ); ?>" aria-label="<?php esc_attr_e( '结束日期', 'myeasybuy-core' ); ?>" inputmode="numeric" data-meb-date autocomplete="off">
			</div>
			<label class="meb-wallet__select">
				<select class="<?php echo '' !== $filters['type'] ? 'has-value' : ''; ?>" name="meb_type" aria-label="<?php esc_attr_e( '类别', 'myeasybuy-core' ); ?>" data-meb-wallet-filter>
					<option value=""><?php esc_html_e( '类别', 'myeasybuy-core' ); ?></option>
					<?php foreach ( $types as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>"<?php selected( $filters['type'], $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<button type="submit" class="meb-wallet__bill"><?php esc_html_e( '账单', 'myeasybuy-core' ); ?></button>
		</form>

		<div class="meb-wallet__table-wrap">
			<table class="meb-wallet__table">
				<thead>
					<tr>
						<th><?php esc_html_e( '时间', 'myeasybuy-core' ); ?></th>
						<th><?php esc_html_e( '类别', 'myeasybuy-core' ); ?></th>
						<th><?php esc_html_e( '收入/支出(元)', 'myeasybuy-core' ); ?></th>
						<th><?php esc_html_e( '余额(元)', 'myeasybuy-core' ); ?></th>
						<th><?php esc_html_e( '交易编号', 'myeasybuy-core' ); ?></th>
						<th><?php esc_html_e( '备注', 'myeasybuy-core' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr>
							<td colspan="6">
								<div class="meb-wallet__empty">
									<div class="meb-wallet__art" aria-hidden="true">
										<svg viewBox="0 0 180 150">
											<g transform="rotate(-14 78 62)">
												<rect x="48" y="36" width="78" height="50" rx="8" fill="#e4edf6"/>
												<rect x="58" y="46" width="36" height="5" rx="2" fill="#f7fafc"/>
											</g>
											<g transform="rotate(10 96 70)">
												<rect x="58" y="44" width="78" height="50" rx="8" fill="#f3f7fb" stroke="#d5e1ee" stroke-width="1"/>
												<rect x="68" y="54" width="32" height="5" rx="2" fill="#d7e2ef"/>
											</g>
											<rect x="34" y="68" width="112" height="58" rx="14" fill="#d5e2f0"/>
											<path d="M34 86h112v28c0 7.7-6.3 14-14 14H48c-7.7 0-14-6.3-14-14V86z" fill="#c5d4e6"/>
											<path d="M34 82c22 10 40 10 56 0s34-10 56 0v8H34v-8z" fill="#e7eef6"/>
											<rect x="112" y="90" width="22" height="14" rx="4" fill="#eef3f8"/>
											<circle cx="122" cy="97" r="2.4" fill="#b7c6d8"/>
											<circle cx="132" cy="58" r="15" fill="#ff7e21" opacity="0.2"/>
											<circle cx="132" cy="58" r="11" fill="#ff7e21"/>
											<path d="M132 52v7.2" stroke="#fff" stroke-width="2.2" stroke-linecap="round"/>
											<circle cx="132" cy="62.6" r="1.15" fill="#fff"/>
										</svg>
									</div>
									<p><?php esc_html_e( '没有为您查询到结果', 'myeasybuy-core' ); ?></p>
								</div>
							</td>
						</tr>
					<?php else : ?>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row['time'] > 0 ? wp_date( 'Y-m-d H:i:s', $row['time'] ) : '' ); ?></td>
								<td><?php echo esc_html( $types[ $row['type'] ] ?? $row['type'] ); ?></td>
								<td class="<?php echo $row['cents'] >= 0 ? 'is-in' : 'is-out'; ?>"><?php echo esc_html( MyEasyBuy_Customer_Wallet::format_amount( $row['cents'], true ) ); ?></td>
								<td><?php echo esc_html( MyEasyBuy_Customer_Wallet::format_amount( $row['balance'] ) ); ?></td>
								<td><?php echo esc_html( $row['id'] ); ?></td>
								<td><?php echo esc_html( $row['note'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>

		<div class="meb-wallet__mask<?php echo 'recharge' === $filters['panel'] ? ' is-open' : ''; ?>" data-meb-wallet-dialog="recharge"<?php echo 'recharge' === $filters['panel'] ? '' : ' hidden'; ?>>
			<div class="meb-wallet__dialog" role="dialog" aria-modal="true" aria-labelledby="meb-wallet-recharge-title">
				<h3 id="meb-wallet-recharge-title"><?php esc_html_e( '充值', 'myeasybuy-core' ); ?></h3>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="myeasybuy_wallet_recharge">
					<?php wp_nonce_field( 'myeasybuy_wallet_recharge' ); ?>
					<label class="meb-wallet__field">
						<span><?php esc_html_e( '充值金额 (CNY)', 'myeasybuy-core' ); ?></span>
						<input type="text" name="amount" inputmode="decimal" autocomplete="off" required placeholder="<?php esc_attr_e( '请输入金额', 'myeasybuy-core' ); ?>">
					</label>
					<label class="meb-wallet__field">
						<span><?php esc_html_e( '备注', 'myeasybuy-core' ); ?></span>
						<input type="text" name="note" maxlength="80" autocomplete="off" placeholder="<?php esc_attr_e( '选填', 'myeasybuy-core' ); ?>">
					</label>
					<div class="meb-wallet__dialog-actions">
						<button type="button" class="meb-wallet__cancel" data-meb-wallet-close><?php esc_html_e( '取消', 'myeasybuy-core' ); ?></button>
						<button type="submit" class="meb-wallet__confirm"><?php esc_html_e( '确认充值', 'myeasybuy-core' ); ?></button>
					</div>
				</form>
			</div>
		</div>

		<div class="meb-wallet__mask<?php echo 'withdraw' === $filters['panel'] ? ' is-open' : ''; ?>" data-meb-wallet-dialog="withdraw"<?php echo 'withdraw' === $filters['panel'] ? '' : ' hidden'; ?>>
			<div class="meb-wallet__dialog" role="dialog" aria-modal="true" aria-labelledby="meb-wallet-withdraw-title">
				<h3 id="meb-wallet-withdraw-title"><?php esc_html_e( '提现', 'myeasybuy-core' ); ?></h3>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="myeasybuy_wallet_withdraw">
					<?php wp_nonce_field( 'myeasybuy_wallet_withdraw' ); ?>
					<label class="meb-wallet__field">
						<span><?php esc_html_e( '提现金额 (CNY)', 'myeasybuy-core' ); ?></span>
						<input type="text" name="amount" inputmode="decimal" autocomplete="off" required placeholder="<?php esc_attr_e( '请输入金额', 'myeasybuy-core' ); ?>">
					</label>
					<?php if ( $need_pin ) : ?>
						<label class="meb-wallet__field">
							<span><?php esc_html_e( '交易密码', 'myeasybuy-core' ); ?></span>
							<input type="password" name="trade_password" autocomplete="current-password" required>
						</label>
					<?php endif; ?>
					<label class="meb-wallet__field">
						<span><?php esc_html_e( '备注', 'myeasybuy-core' ); ?></span>
						<input type="text" name="note" maxlength="80" autocomplete="off" placeholder="<?php esc_attr_e( '选填', 'myeasybuy-core' ); ?>">
					</label>
					<div class="meb-wallet__dialog-actions">
						<button type="button" class="meb-wallet__cancel" data-meb-wallet-close><?php esc_html_e( '取消', 'myeasybuy-core' ); ?></button>
						<button type="submit" class="meb-wallet__confirm"><?php esc_html_e( '确认提现', 'myeasybuy-core' ); ?></button>
					</div>
				</form>
			</div>
		</div>
	<?php else : ?>
		<?php if ( empty( $coupons ) ) : ?>
			<div class="meb-wallet__empty meb-wallet__empty--page">
				<div class="meb-wallet__art" aria-hidden="true">
					<svg viewBox="0 0 180 150">
						<rect x="42" y="48" width="96" height="62" rx="10" fill="#e7eef6"/>
						<path d="M42 68h96" stroke="#d5e0ee" stroke-width="2"/>
						<circle cx="42" cy="79" r="8" fill="#fff"/>
						<circle cx="138" cy="79" r="8" fill="#fff"/>
						<rect x="58" y="58" width="40" height="8" rx="2" fill="#c5d4e6"/>
						<rect x="58" y="88" width="64" height="6" rx="2" fill="#d7e2ef"/>
						<circle cx="128" cy="52" r="16" fill="#ff7e21" opacity="0.18"/>
						<circle cx="128" cy="52" r="12" fill="#ff7e21"/>
						<path d="M128 45.5v8" stroke="#fff" stroke-width="2.2" stroke-linecap="round"/>
						<circle cx="128" cy="57.2" r="1.2" fill="#fff"/>
					</svg>
				</div>
				<p><?php esc_html_e( '您还没有优惠券。', 'myeasybuy-core' ); ?></p>
			</div>
		<?php else : ?>
			<ul class="meb-wallet__coupons">
				<?php foreach ( $coupons as $coupon ) : ?>
					<li class="meb-wallet__coupon">
						<div class="meb-wallet__coupon-amount"><?php echo esc_html( $coupon['amount'] ); ?></div>
						<div>
							<p class="meb-wallet__coupon-title"><?php echo esc_html( $coupon['title'] ); ?></p>
							<?php if ( '' !== $coupon['rule'] ) : ?>
								<p class="meb-wallet__coupon-rule"><?php echo esc_html( $coupon['rule'] ); ?></p>
							<?php endif; ?>
							<?php if ( '' !== $coupon['expires'] ) : ?>
								<p class="meb-wallet__coupon-rule"><?php echo esc_html( $coupon['expires'] ); ?></p>
							<?php endif; ?>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	<?php endif; ?>
</div>
