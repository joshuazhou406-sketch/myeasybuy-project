<?php
/**
 * Account profile.
 *
 * Variables are set by MyEasyBuy_Customer_Profile::render_endpoint().
 *
 * @package MyEasyBuy_Core
 *
 * @var string                                                                 $tab
 * @var array<string, string>                                                  $tabs
 * @var array{name: string, phone: string, birthday: string, country: string, gender: string, email: string, letter: string, public_id: string} $profile
 * @var array<int, array<string, string>>                                     $addresses
 * @var array{name: string, phone: string, country: string, state: string, city: string, address: string, postcode: string, empty: bool} $billing
 * @var string                                                                 $panel
 * @var bool                                                                   $adding
 * @var array<string, string>|null                                             $editing
 * @var array<string, string>                                                  $countries
 * @var int                                                                    $user_id
 */

defined( 'ABSPATH' ) || exit;

$country_label = static function ( string $code ) use ( $countries ): string {
	return $countries[ $code ] ?? $code;
};

$blank = array(
	'id'       => '',
	'name'     => '',
	'phone'    => '',
	'country'  => '',
	'state'    => '',
	'city'     => '',
	'address'  => '',
	'postcode' => '',
);
$form_address = is_array( $editing ) ? $editing : $blank;
$show_address_form = 'address' === $tab && ( $adding || is_array( $editing ) );
$show_billing_form = 'billing' === $tab && $adding;
?>
<div class="meb-acct">
	<nav class="meb-acct__tabs" aria-label="<?php esc_attr_e( '账户信息', 'myeasybuy-core' ); ?>">
		<?php foreach ( $tabs as $key => $label ) : ?>
			<a class="meb-acct__tab<?php echo $tab === $key ? ' is-active' : ''; ?>" href="<?php echo esc_url( MyEasyBuy_Customer_Profile::page_url( array( 'tab' => $key ) ) ); ?>"<?php echo $tab === $key ? ' aria-current="page"' : ''; ?>>
				<?php echo esc_html( $label ); ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<?php if ( 'profile' === $tab ) : ?>
		<section class="meb-acct__profile">
			<h2><?php esc_html_e( '编辑个人资料', 'myeasybuy-core' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="myeasybuy_save_profile">
				<?php wp_nonce_field( 'myeasybuy_save_profile' ); ?>

				<div class="meb-acct__avatar-block">
					<span class="meb-acct__label"><?php esc_html_e( '头像', 'myeasybuy-core' ); ?></span>
					<span class="meb-acct__avatar" aria-hidden="true"><?php echo esc_html( $profile['letter'] ); ?></span>
				</div>

				<label class="meb-acct__field">
					<span><?php esc_html_e( '用户名', 'myeasybuy-core' ); ?></span>
					<input type="text" name="display_name" value="<?php echo esc_attr( $profile['name'] ); ?>" autocomplete="nickname" required>
				</label>

				<label class="meb-acct__field">
					<span><?php esc_html_e( '电话', 'myeasybuy-core' ); ?></span>
					<input type="tel" name="phone" value="<?php echo esc_attr( $profile['phone'] ); ?>" placeholder="<?php esc_attr_e( '输入您的电话', 'myeasybuy-core' ); ?>" autocomplete="tel">
				</label>

				<label class="meb-acct__field">
					<span><?php esc_html_e( '生日', 'myeasybuy-core' ); ?></span>
					<span class="meb-acct__date">
						<span class="meb-acct__date-icon" aria-hidden="true">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg>
						</span>
						<input type="text" name="birthday" value="<?php echo esc_attr( $profile['birthday'] ); ?>" placeholder="<?php esc_attr_e( '选择', 'myeasybuy-core' ); ?>" aria-label="<?php esc_attr_e( '生日', 'myeasybuy-core' ); ?>" data-meb-date autocomplete="bday">
					</span>
				</label>

				<label class="meb-acct__field">
					<span><?php esc_html_e( '国家', 'myeasybuy-core' ); ?></span>
					<select name="country" class="<?php echo '' !== $profile['country'] ? 'has-value' : ''; ?>" aria-label="<?php esc_attr_e( '国家', 'myeasybuy-core' ); ?>">
						<option value=""><?php esc_html_e( '选择国家', 'myeasybuy-core' ); ?></option>
						<?php foreach ( $countries as $code => $country_name ) : ?>
							<option value="<?php echo esc_attr( $code ); ?>"<?php selected( $profile['country'], $code ); ?>><?php echo esc_html( $country_name ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>

				<fieldset class="meb-acct__gender">
					<legend><?php esc_html_e( '性别', 'myeasybuy-core' ); ?></legend>
					<label class="meb-acct__choice">
						<input type="radio" name="gender" value="male"<?php checked( $profile['gender'], 'male' ); ?>>
						<?php esc_html_e( '男性', 'myeasybuy-core' ); ?>
					</label>
					<label class="meb-acct__choice">
						<input type="radio" name="gender" value="female"<?php checked( $profile['gender'], 'female' ); ?>>
						<?php esc_html_e( '女性', 'myeasybuy-core' ); ?>
					</label>
				</fieldset>

				<button type="submit" class="meb-acct__save"><?php esc_html_e( '保存', 'myeasybuy-core' ); ?></button>
			</form>
		</section>

	<?php elseif ( 'address' === $tab ) : ?>
		<section class="meb-acct__address">
			<div class="meb-acct__address-head">
				<?php if ( $show_address_form ) : ?>
					<a class="meb-acct__add" href="<?php echo esc_url( MyEasyBuy_Customer_Profile::page_url( array( 'tab' => 'address' ) ) ); ?>"><?php esc_html_e( '返回', 'myeasybuy-core' ); ?></a>
				<?php else : ?>
					<a class="meb-acct__add" href="<?php echo esc_url( MyEasyBuy_Customer_Profile::page_url( array( 'tab' => 'address', 'add' => '1' ) ) ); ?>"><?php esc_html_e( '+ 添加收件地址', 'myeasybuy-core' ); ?></a>
				<?php endif; ?>
				<p>
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: %d: address count */
							__( '您已提交 <span>%d</span> 收件地址', 'myeasybuy-core' ),
							count( $addresses )
						)
					);
					?>
				</p>
			</div>

			<?php if ( $show_address_form ) : ?>
				<form class="meb-acct__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="myeasybuy_save_address">
					<input type="hidden" name="address_id" value="<?php echo esc_attr( (string) $form_address['id'] ); ?>">
					<?php wp_nonce_field( 'myeasybuy_save_address' ); ?>
					<h2><?php echo esc_html( '' !== (string) $form_address['id'] ? __( '编辑收件地址', 'myeasybuy-core' ) : __( '添加收件地址', 'myeasybuy-core' ) ); ?></h2>
					<?php
					$values        = $form_address;
					$require_phone = true;
					include __DIR__ . '/profile-address-fields.php';
					?>
					<div class="meb-acct__form-actions">
						<button type="submit" class="meb-acct__save"><?php esc_html_e( '保存', 'myeasybuy-core' ); ?></button>
						<a class="meb-acct__cancel" href="<?php echo esc_url( MyEasyBuy_Customer_Profile::page_url( array( 'tab' => 'address' ) ) ); ?>"><?php esc_html_e( '取消', 'myeasybuy-core' ); ?></a>
					</div>
				</form>
			<?php elseif ( array() === $addresses ) : ?>
				<div class="meb-acct__empty">
					<div class="meb-acct__map" aria-hidden="true">
						<svg viewBox="0 0 160 160">
							<circle cx="80" cy="80" r="62" fill="#eef3f8"/>
							<path d="M38 96c10-8 18-6 28-14 8-6 12-18 24-20 14-2 18 10 30 8 10-2 16 6 22 12" fill="none" stroke="#d5deea" stroke-width="10" stroke-linecap="round"/>
							<path d="M46 78h28M92 70h22" stroke="#f7f9fc" stroke-width="4" stroke-linecap="round" stroke-dasharray="2 7"/>
							<circle cx="104" cy="58" r="16" fill="#ff7e21" opacity="0.2"/>
							<path d="M104 40c-8 0-14 6-14 14 0 10 14 22 14 22s14-12 14-22c0-8-6-14-14-14z" fill="#ff7e21"/>
							<circle cx="104" cy="54" r="4" fill="#fff"/>
						</svg>
					</div>
					<p><?php esc_html_e( '您还没有添加收件地址，请添加收件地址。', 'myeasybuy-core' ); ?></p>
				</div>
			<?php else : ?>
				<ul class="meb-acct__cards">
					<?php foreach ( $addresses as $address ) : ?>
						<li class="meb-acct__card">
							<div>
								<strong><?php echo esc_html( (string) $address['name'] ); ?></strong>
								<span><?php echo esc_html( (string) $address['phone'] ); ?></span>
								<?php if ( 'yes' === (string) ( $address['is_default'] ?? '' ) ) : ?>
									<em><?php esc_html_e( '默认', 'myeasybuy-core' ); ?></em>
								<?php endif; ?>
								<p>
									<?php
									echo esc_html(
										trim(
											$country_label( (string) $address['country'] ) . ' ' . (string) $address['state'] . ' ' . (string) $address['city'] . ' ' . (string) $address['address'] . ' ' . (string) $address['postcode']
										)
									);
									?>
								</p>
							</div>
							<div class="meb-acct__card-actions">
								<a href="<?php echo esc_url( MyEasyBuy_Customer_Profile::page_url( array( 'tab' => 'address', 'edit' => (string) $address['id'] ) ) ); ?>"><?php esc_html_e( '编辑', 'myeasybuy-core' ); ?></a>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="myeasybuy_delete_address">
									<input type="hidden" name="address_id" value="<?php echo esc_attr( (string) $address['id'] ); ?>">
									<?php wp_nonce_field( 'myeasybuy_delete_address' ); ?>
									<button type="submit" class="meb-acct__text" data-meb-confirm="<?php esc_attr_e( '确定删除这个收件地址吗？', 'myeasybuy-core' ); ?>"><?php esc_html_e( '删除', 'myeasybuy-core' ); ?></button>
								</form>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>

	<?php elseif ( 'security' === $tab ) : ?>
		<section class="meb-acct__security">
			<div class="meb-acct__banner">
				<span class="meb-acct__avatar" aria-hidden="true"><?php echo esc_html( $profile['letter'] ); ?></span>
				<strong class="meb-acct__banner-name"><?php echo esc_html( $profile['name'] ); ?></strong>
				<span class="meb-acct__id"><b>ID</b> <?php echo esc_html( $profile['public_id'] ); ?></span>
				<span class="meb-acct__mail">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/></svg>
					<?php echo esc_html( $profile['email'] ); ?>
				</span>
			</div>

			<article class="meb-acct__row">
				<span class="meb-acct__mark is-ok" aria-hidden="true">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/></svg>
				</span>
				<div>
					<h3><?php esc_html_e( '修改密码', 'myeasybuy-core' ); ?></h3>
					<p><?php esc_html_e( '更高强度的密码让您的账户更加安全。建议定期更改密码，密码需由数字和字母组成，长度大于6位。', 'myeasybuy-core' ); ?></p>
					<?php if ( 'password' === $panel ) : ?>
						<form class="meb-acct__panel" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="myeasybuy_change_password">
							<?php wp_nonce_field( 'myeasybuy_change_password' ); ?>
							<label><?php esc_html_e( '当前密码', 'myeasybuy-core' ); ?><input type="password" name="current_password" autocomplete="current-password" required></label>
							<label><?php esc_html_e( '新密码', 'myeasybuy-core' ); ?><input type="password" name="new_password" autocomplete="new-password" required></label>
							<label><?php esc_html_e( '确认新密码', 'myeasybuy-core' ); ?><input type="password" name="confirm_password" autocomplete="new-password" required></label>
							<button type="submit" class="meb-acct__save"><?php esc_html_e( '保存', 'myeasybuy-core' ); ?></button>
						</form>
					<?php endif; ?>
				</div>
				<a class="meb-acct__jump" href="<?php echo esc_url( MyEasyBuy_Customer_Profile::page_url( array( 'tab' => 'security', 'panel' => 'password' ) ) ); ?>"><?php esc_html_e( '修改密码 >', 'myeasybuy-core' ); ?></a>
			</article>

			<article class="meb-acct__row">
				<?php if ( MyEasyBuy_Customer_Profile::has_trade_password( $user_id ) ) : ?>
					<span class="meb-acct__mark is-ok" aria-hidden="true">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/></svg>
					</span>
				<?php else : ?>
					<span class="meb-acct__mark is-warn" aria-hidden="true">!</span>
				<?php endif; ?>
				<div>
					<h3><?php echo esc_html( MyEasyBuy_Customer_Profile::has_trade_password( $user_id ) ? __( '交易密码', 'myeasybuy-core' ) : __( '新交易密码', 'myeasybuy-core' ) ); ?></h3>
					<p><?php esc_html_e( '使用余额进行交易时需要验证密码，以增强账户的安全性。', 'myeasybuy-core' ); ?></p>
					<?php if ( 'trade' === $panel ) : ?>
						<form class="meb-acct__panel" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="myeasybuy_set_trade_password">
							<?php wp_nonce_field( 'myeasybuy_set_trade_password' ); ?>
							<?php if ( MyEasyBuy_Customer_Profile::has_trade_password( $user_id ) ) : ?>
								<label><?php esc_html_e( '当前交易密码', 'myeasybuy-core' ); ?><input type="password" name="trade_current" autocomplete="current-password" required></label>
							<?php endif; ?>
							<label><?php esc_html_e( '交易密码', 'myeasybuy-core' ); ?><input type="password" name="trade_password" autocomplete="new-password" required></label>
							<label><?php esc_html_e( '确认交易密码', 'myeasybuy-core' ); ?><input type="password" name="trade_confirm" autocomplete="new-password" required></label>
							<button type="submit" class="meb-acct__save"><?php esc_html_e( '保存', 'myeasybuy-core' ); ?></button>
						</form>
					<?php endif; ?>
				</div>
				<a class="meb-acct__jump" href="<?php echo esc_url( MyEasyBuy_Customer_Profile::page_url( array( 'tab' => 'security', 'panel' => 'trade' ) ) ); ?>">
					<?php echo esc_html( MyEasyBuy_Customer_Profile::has_trade_password( $user_id ) ? __( '修改密码 >', 'myeasybuy-core' ) : __( '设置密码 >', 'myeasybuy-core' ) ); ?>
				</a>
			</article>

			<article class="meb-acct__row">
				<?php if ( MyEasyBuy_Customer_Profile::auto_balance_enabled( $user_id ) ) : ?>
					<span class="meb-acct__mark is-ok" aria-hidden="true">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/></svg>
					</span>
				<?php else : ?>
					<span class="meb-acct__mark is-warn" aria-hidden="true">!</span>
				<?php endif; ?>
				<div>
					<h3>
						<?php esc_html_e( '自动余额补款', 'myeasybuy-core' ); ?>
						<em><?php esc_html_e( '节省时间，助您更快收到包裹', 'myeasybuy-core' ); ?></em>
					</h3>
					<p><?php esc_html_e( '开启后，若账户有余额且补款金额低于设定阈值，系统将自动使用可用余额完成支付。', 'myeasybuy-core' ); ?></p>
					<p class="meb-acct__note"><?php esc_html_e( '适用于订单补款与运费补款。', 'myeasybuy-core' ); ?></p>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="myeasybuy_toggle_balance">
					<?php wp_nonce_field( 'myeasybuy_toggle_balance' ); ?>
					<button type="submit" class="meb-acct__jump">
						<?php echo esc_html( MyEasyBuy_Customer_Profile::auto_balance_enabled( $user_id ) ? __( '开启 >', 'myeasybuy-core' ) : __( '关闭 >', 'myeasybuy-core' ) ); ?>
					</button>
				</form>
			</article>
		</section>

	<?php else : ?>
		<section class="meb-acct__billing">
			<?php if ( $show_billing_form ) : ?>
				<form class="meb-acct__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="myeasybuy_save_billing">
					<?php wp_nonce_field( 'myeasybuy_save_billing' ); ?>
					<h2><?php esc_html_e( '信用卡账单地址', 'myeasybuy-core' ); ?></h2>
					<?php
					$require_phone = false;
					$values = array(
						'name'     => $billing['name'],
						'phone'    => $billing['phone'],
						'country'  => $billing['country'],
						'state'    => $billing['state'],
						'city'     => $billing['city'],
						'address'  => $billing['address'],
						'postcode' => $billing['postcode'],
					);
					include __DIR__ . '/profile-address-fields.php';
					?>
					<div class="meb-acct__form-actions">
						<button type="submit" class="meb-acct__save"><?php esc_html_e( '保存', 'myeasybuy-core' ); ?></button>
						<a class="meb-acct__cancel" href="<?php echo esc_url( MyEasyBuy_Customer_Profile::page_url( array( 'tab' => 'billing' ) ) ); ?>"><?php esc_html_e( '取消', 'myeasybuy-core' ); ?></a>
					</div>
				</form>
			<?php elseif ( $billing['empty'] ) : ?>
				<div class="meb-acct__dash">
					<p><?php esc_html_e( '如果您计划使用信用卡付款，请设置信用卡账单地址。', 'myeasybuy-core' ); ?></p>
					<a class="meb-acct__solid" href="<?php echo esc_url( MyEasyBuy_Customer_Profile::page_url( array( 'tab' => 'billing', 'add' => '1' ) ) ); ?>"><?php esc_html_e( '添加账单地址', 'myeasybuy-core' ); ?></a>
				</div>
			<?php else : ?>
				<article class="meb-acct__card">
					<div>
						<strong><?php echo esc_html( $billing['name'] ); ?></strong>
						<span><?php echo esc_html( $billing['phone'] ); ?></span>
						<p>
							<?php
							echo esc_html(
								trim(
									$country_label( $billing['country'] ) . ' ' . $billing['state'] . ' ' . $billing['city'] . ' ' . $billing['address'] . ' ' . $billing['postcode']
								)
							);
							?>
						</p>
					</div>
					<a href="<?php echo esc_url( MyEasyBuy_Customer_Profile::page_url( array( 'tab' => 'billing', 'add' => '1' ) ) ); ?>"><?php esc_html_e( '编辑', 'myeasybuy-core' ); ?></a>
				</article>
			<?php endif; ?>
		</section>
	<?php endif; ?>
</div>
