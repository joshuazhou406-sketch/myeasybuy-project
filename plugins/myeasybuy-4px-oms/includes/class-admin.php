<?php
/**
 * Admin settings and consolidation console.
 *
 * @package MyEasyBuy_OMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Warehouse-facing OMS screens.
 */
class MyEasyBuy_OMS_Admin {

	public const PAGE_SETTINGS = 'myeasybuy-oms-settings';
	public const PAGE_CONSOLE  = 'myeasybuy-oms-console';

	/**
	 * Register menus and handlers.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menus' ), 70 );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_post_meb_oms_action', array( __CLASS__, 'handle_action' ) );
		add_action( 'admin_post_meb_oms_simulate', array( __CLASS__, 'handle_simulate' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_metabox' ) );
	}

	/**
	 * Capability for these screens.
	 */
	public static function cap(): string {
		if ( class_exists( 'MyEasyBuy_Roles' ) ) {
			return MyEasyBuy_Roles::CAP_WAREHOUSE;
		}

		return 'manage_woocommerce';
	}

	/**
	 * Menus under 集运仓储.
	 */
	public static function register_menus(): void {
		$parent = class_exists( 'MyEasyBuy_Admin_Workflow' )
			? MyEasyBuy_Admin_Workflow::MENU_WAREHOUSE
			: 'woocommerce';

		add_submenu_page(
			$parent,
			__( '4PX 集运 OMS', 'meb-oms' ),
			__( '4PX 集运 OMS', 'meb-oms' ),
			self::cap(),
			self::PAGE_CONSOLE,
			array( __CLASS__, 'render_console' )
		);

		add_submenu_page(
			$parent,
			__( 'OMS 对接设置', 'meb-oms' ),
			__( 'OMS 对接设置', 'meb-oms' ),
			'manage_woocommerce',
			self::PAGE_SETTINGS,
			array( __CLASS__, 'render_settings' )
		);
	}

	/**
	 * Settings API.
	 */
	public static function register_settings(): void {
		register_setting(
			'myeasybuy_oms_settings_group',
			MyEasyBuy_OMS_Service::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => MyEasyBuy_OMS_Service::default_settings(),
			)
		);
	}

	/**
	 * Sanitize settings.
	 *
	 * @param mixed $input Raw.
	 * @return array<string, mixed>
	 */
	public static function sanitize_settings( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$defaults = MyEasyBuy_OMS_Service::default_settings();
		$out      = $defaults;

		foreach ( $defaults as $key => $default ) {
			if ( in_array( $key, array( 'enabled', 'use_shared_credentials', 'sandbox_mode', 'auto_leg1', 'auto_leg2', 'leg2_on_request' ), true ) ) {
				$out[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
				continue;
			}
			$out[ $key ] = sanitize_text_field( (string) ( $input[ $key ] ?? $default ) );
		}

		if ( empty( $out['callback_token'] ) ) {
			$current = (array) get_option( MyEasyBuy_OMS_Service::OPTION_KEY, array() );
			$out['callback_token'] = (string) ( $current['callback_token'] ?? wp_generate_password( 24, false, false ) );
		}

		if ( ! in_array( $out['transport'], array( 'path', 'router' ), true ) ) {
			$out['transport'] = 'path';
		}

		return $out;
	}

	/**
	 * Assets.
	 *
	 * @param string $hook Hook.
	 */
	public static function enqueue_assets( string $hook ): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $page, array( self::PAGE_CONSOLE, self::PAGE_SETTINGS ), true ) ) {
			return;
		}

		wp_enqueue_style(
			'meb-oms-admin',
			MEB_OMS_URL . 'assets/css/admin.css',
			array(),
			MEB_OMS_VERSION
		);
		wp_enqueue_script(
			'meb-oms-admin',
			MEB_OMS_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			MEB_OMS_VERSION,
			true
		);
	}

	/**
	 * Settings page.
	 */
	public static function render_settings(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( '权限不足。', 'meb-oms' ) );
		}

		$settings = MyEasyBuy_OMS_Service::instance()->get_settings();
		$callback = MyEasyBuy_OMS_Service::instance()->callback_url();
		?>
		<div class="wrap meb-oms-wrap">
			<h1><?php esc_html_e( '4PX / 菜鸟 OMS 集运对接', 'meb-oms' ); ?></h1>
			<p class="description">
				<?php esc_html_e( '按「一段入库 → 二段出库」对接递四方标准订单接口。系统作为客户主动调 OMS；仓内签收/入库/上架/合箱/出库通过回调回写。', 'meb-oms' ); ?>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'myeasybuy_oms_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th><?php esc_html_e( '启用对接', 'meb-oms' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( MyEasyBuy_OMS_Service::OPTION_KEY ); ?>[enabled]" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> />
								<?php esc_html_e( '开启一段预报 / 二段合包 / 回调处理', 'meb-oms' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( '凭证', 'meb-oms' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( MyEasyBuy_OMS_Service::OPTION_KEY ); ?>[use_shared_credentials]" value="1" <?php checked( ! empty( $settings['use_shared_credentials'] ) ); ?> />
								<?php esc_html_e( '复用「4PX 物流对接」里的 App Key / Secret', 'meb-oms' ); ?>
							</label>
							<p>
								<input type="text" class="regular-text" name="<?php echo esc_attr( MyEasyBuy_OMS_Service::OPTION_KEY ); ?>[app_key]" value="<?php echo esc_attr( (string) $settings['app_key'] ); ?>" placeholder="App Key（可选覆盖）" />
							</p>
							<p>
								<input type="password" class="regular-text" name="<?php echo esc_attr( MyEasyBuy_OMS_Service::OPTION_KEY ); ?>[app_secret]" value="<?php echo esc_attr( (string) $settings['app_secret'] ); ?>" placeholder="App Secret（可选覆盖）" autocomplete="new-password" />
							</p>
							<p>
								<input type="text" class="regular-text" name="<?php echo esc_attr( MyEasyBuy_OMS_Service::OPTION_KEY ); ?>[access_token]" value="<?php echo esc_attr( (string) $settings['access_token'] ); ?>" placeholder="Access Token（可选）" />
							</p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( '环境', 'meb-oms' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( MyEasyBuy_OMS_Service::OPTION_KEY ); ?>[sandbox_mode]" value="1" <?php checked( ! empty( $settings['sandbox_mode'] ) ); ?> />
								<?php esc_html_e( '测试环境 open-test.4px.com', 'meb-oms' ); ?>
							</label>
							<p>
								<select name="<?php echo esc_attr( MyEasyBuy_OMS_Service::OPTION_KEY ); ?>[transport]">
									<option value="path" <?php selected( $settings['transport'], 'path' ); ?>><?php esc_html_e( '路径模式 /api/standard/order/*', 'meb-oms' ); ?></option>
									<option value="router" <?php selected( $settings['transport'], 'router' ); ?>><?php esc_html_e( '开放平台 router + method', 'meb-oms' ); ?></option>
								</select>
							</p>
							<p>
								<input type="url" class="regular-text" name="<?php echo esc_attr( MyEasyBuy_OMS_Service::OPTION_KEY ); ?>[api_base_url]" value="<?php echo esc_attr( (string) $settings['api_base_url'] ); ?>" placeholder="<?php esc_attr_e( '自定义网关（可空）', 'meb-oms' ); ?>" />
							</p>
							<p>
								<input type="text" class="small-text" name="<?php echo esc_attr( MyEasyBuy_OMS_Service::OPTION_KEY ); ?>[api_version]" value="<?php echo esc_attr( (string) $settings['api_version'] ); ?>" />
								<span class="description">v</span>
							</p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Router method', 'meb-oms' ); ?></th>
						<td>
							<?php foreach ( array( 'method_create' => 'create', 'method_update' => 'update', 'method_cancel' => 'cancel', 'method_shelf_return' => 'shelfReturn' ) as $key => $label ) : ?>
								<p>
									<label><?php echo esc_html( $label ); ?>
										<input type="text" class="regular-text" name="<?php echo esc_attr( MyEasyBuy_OMS_Service::OPTION_KEY ); ?>[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( (string) $settings[ $key ] ); ?>" />
									</label>
								</p>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( '业务常量', 'meb-oms' ); ?></th>
						<td>
							<p><label>salePkCode <input type="text" name="<?php echo esc_attr( MyEasyBuy_OMS_Service::OPTION_KEY ); ?>[sale_pk_code]" value="<?php echo esc_attr( (string) $settings['sale_pk_code'] ); ?>" /></label></p>
							<p><label><?php esc_html_e( '海运 salePkCode', 'meb-oms' ); ?> <input type="text" name="<?php echo esc_attr( MyEasyBuy_OMS_Service::OPTION_KEY ); ?>[sale_pk_code_sea]" value="<?php echo esc_attr( (string) $settings['sale_pk_code_sea'] ); ?>" /></label></p>
							<p><label>parcelSource <input type="text" name="<?php echo esc_attr( MyEasyBuy_OMS_Service::OPTION_KEY ); ?>[parcel_source]" value="<?php echo esc_attr( (string) $settings['parcel_source'] ); ?>" /></label></p>
							<p><label>businessType <input type="text" name="<?php echo esc_attr( MyEasyBuy_OMS_Service::OPTION_KEY ); ?>[business_type]" value="<?php echo esc_attr( (string) $settings['business_type'] ); ?>" /></label></p>
							<p>
								<label>fulfillMode
									<select name="<?php echo esc_attr( MyEasyBuy_OMS_Service::OPTION_KEY ); ?>[fulfill_mode]">
										<?php foreach ( array( 'SELLER_DROP_OFF' => __( '卖家自送', 'meb-oms' ), 'DOOR_PICKUP' => __( '上门取', 'meb-oms' ), 'FORWARD_WAREHOUSE' => __( '前置仓', 'meb-oms' ) ) as $value => $label ) : ?>
											<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['fulfill_mode'], $value ); ?>><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
								</label>
							</p>
							<p><label><?php esc_html_e( '默认目的国', 'meb-oms' ); ?> <input type="text" class="small-text" name="<?php echo esc_attr( MyEasyBuy_OMS_Service::OPTION_KEY ); ?>[country_code]" value="<?php echo esc_attr( (string) $settings['country_code'] ); ?>" /></label></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( '自动触发', 'meb-oms' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( MyEasyBuy_OMS_Service::OPTION_KEY ); ?>[auto_leg1]" value="1" <?php checked( ! empty( $settings['auto_leg1'] ) ); ?> /> <?php esc_html_e( '国内发货 / 转运预报后自动下发一段', 'meb-oms' ); ?></label><br />
							<label><input type="checkbox" name="<?php echo esc_attr( MyEasyBuy_OMS_Service::OPTION_KEY ); ?>[auto_leg2]" value="1" <?php checked( ! empty( $settings['auto_leg2'] ) ); ?> /> <?php esc_html_e( '二价/集运费支付后自动下发二段合包', 'meb-oms' ); ?></label><br />
							<label><input type="checkbox" name="<?php echo esc_attr( MyEasyBuy_OMS_Service::OPTION_KEY ); ?>[leg2_on_request]" value="1" <?php checked( ! empty( $settings['leg2_on_request'] ) ); ?> /> <?php esc_html_e( '客户提交合箱申请即下发二段（不等待付款）', 'meb-oms' ); ?></label>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( '回调 URL', 'meb-oms' ); ?></th>
						<td>
							<code class="meb-oms-callback"><?php echo esc_html( $callback ); ?></code>
							<p>
								<label><?php esc_html_e( '校验 token', 'meb-oms' ); ?>
									<input type="text" class="regular-text" name="<?php echo esc_attr( MyEasyBuy_OMS_Service::OPTION_KEY ); ?>[callback_token]" value="<?php echo esc_attr( (string) $settings['callback_token'] ); ?>" />
								</label>
							</p>
							<p class="description"><?php esc_html_e( '把此 URL 配置到 OMS。统一回 {"code":"1","message":"success","data":{}}。', 'meb-oms' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( '退件联系人', 'meb-oms' ); ?></th>
						<td>
							<p><input type="text" class="regular-text" name="<?php echo esc_attr( MyEasyBuy_OMS_Service::OPTION_KEY ); ?>[return_name]" value="<?php echo esc_attr( (string) $settings['return_name'] ); ?>" placeholder="<?php esc_attr_e( '姓名', 'meb-oms' ); ?>" /></p>
							<p><input type="text" class="regular-text" name="<?php echo esc_attr( MyEasyBuy_OMS_Service::OPTION_KEY ); ?>[return_phone]" value="<?php echo esc_attr( (string) $settings['return_phone'] ); ?>" placeholder="<?php esc_attr_e( '电话', 'meb-oms' ); ?>" /></p>
							<p><input type="text" class="regular-text" name="<?php echo esc_attr( MyEasyBuy_OMS_Service::OPTION_KEY ); ?>[return_address]" value="<?php echo esc_attr( (string) $settings['return_address'] ); ?>" placeholder="<?php esc_attr_e( '地址', 'meb-oms' ); ?>" /></p>
							<p>
								<select name="<?php echo esc_attr( MyEasyBuy_OMS_Service::OPTION_KEY ); ?>[default_return_type]">
									<?php foreach ( array( 'EXPRESS_RETURN', 'CUSTOMER_PICKUP', 'EXPRESS_PICKUP' ) as $type ) : ?>
										<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $settings['default_return_type'], $type ); ?>><?php echo esc_html( $type ); ?></option>
									<?php endforeach; ?>
								</select>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( '保存 OMS 设置', 'meb-oms' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Console.
	 */
	public static function render_console(): void {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( '权限不足。', 'meb-oms' ) );
		}

		self::maybe_notice();

		$tab     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'leg1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$steps   = MyEasyBuy_OMS_Service::pipeline_steps();
		$labels  = MyEasyBuy_OMS_Service::status_labels();
		$counts  = MyEasyBuy_OMS_Repository::status_counts();
		$leg     = 'leg2' === $tab ? 2 : 1;
		$orders  = 'logs' === $tab ? array() : MyEasyBuy_OMS_Repository::query_orders(
			array(
				'leg'    => $leg,
				'search' => $search,
				'limit'  => 80,
			)
		);
		$logs    = 'logs' === $tab ? MyEasyBuy_OMS_Repository::query_callbacks( 60 ) : array();
		$base    = admin_url( 'admin.php?page=' . self::PAGE_CONSOLE );
		?>
		<div class="wrap meb-oms-wrap">
			<h1><?php esc_html_e( '4PX 集运 OMS 控制台', 'meb-oms' ); ?></h1>
			<p>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SETTINGS ) ); ?>"><?php esc_html_e( '对接设置', 'meb-oms' ); ?></a>
			</p>

			<ol class="meb-oms-pipeline">
				<?php foreach ( $steps as $index => $step ) : ?>
					<li class="meb-oms-pipeline__step meb-oms-pipeline__step--<?php echo esc_attr( $step['dir'] ); ?>">
						<span class="meb-oms-pipeline__num"><?php echo esc_html( (string) ( $index + 1 ) ); ?></span>
						<strong><?php echo esc_html( $step['label'] ); ?></strong>
						<em><?php echo esc_html( $step['api'] ); ?></em>
					</li>
				<?php endforeach; ?>
			</ol>

			<ul class="meb-oms-counts">
				<?php foreach ( $labels as $code => $label ) : ?>
					<li><span><?php echo esc_html( $code ); ?></span> <?php echo esc_html( $label ); ?> <b><?php echo esc_html( (string) ( $counts[ $code ] ?? 0 ) ); ?></b></li>
				<?php endforeach; ?>
			</ul>

			<nav class="nav-tab-wrapper">
				<a class="nav-tab <?php echo 'leg1' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( $base . '&tab=leg1' ); ?>"><?php esc_html_e( '一段预报', 'meb-oms' ); ?></a>
				<a class="nav-tab <?php echo 'leg2' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( $base . '&tab=leg2' ); ?>"><?php esc_html_e( '二段合包', 'meb-oms' ); ?></a>
				<a class="nav-tab <?php echo 'logs' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( $base . '&tab=logs' ); ?>"><?php esc_html_e( '回传日志', 'meb-oms' ); ?></a>
			</nav>

			<?php if ( 'logs' !== $tab ) : ?>
				<form method="get" class="meb-oms-search">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_CONSOLE ); ?>" />
					<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>" />
					<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( '单号 / 批次', 'meb-oms' ); ?>" />
					<button class="button"><?php esc_html_e( '搜索', 'meb-oms' ); ?></button>
				</form>

				<table class="widefat striped meb-oms-table">
					<thead>
						<tr>
							<th><?php esc_html_e( '客户单号', 'meb-oms' ); ?></th>
							<th><?php esc_html_e( 'fpxTrackNo', 'meb-oms' ); ?></th>
							<th><?php esc_html_e( '来源', 'meb-oms' ); ?></th>
							<th><?php esc_html_e( '状态', 'meb-oms' ); ?></th>
							<th><?php esc_html_e( '实测', 'meb-oms' ); ?></th>
							<th><?php esc_html_e( '操作', 'meb-oms' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php if ( empty( $orders ) ) : ?>
						<tr><td colspan="6"><?php esc_html_e( '暂无 OMS 单据。国内发货或转运预报后会自动出现。', 'meb-oms' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $orders as $row ) : ?>
						<?php
						$status = (string) $row['oms_status'];
						$source = MyEasyBuy_OMS_Service::SOURCE_WC === $row['source_type']
							? sprintf( __( '代购订单 #%d', 'meb-oms' ), (int) $row['source_id'] )
							: ( 2 === (int) $row['leg']
								? sprintf( __( '转运合箱 #%d', 'meb-oms' ), (int) $row['source_id'] )
								: sprintf( __( '转运包裹 #%d', 'meb-oms' ), (int) $row['source_id'] ) );
						$measure = '';
						if ( (int) $row['weight_g'] > 0 ) {
							$measure = sprintf( '%dg', (int) $row['weight_g'] );
							if ( (int) $row['length_mm'] > 0 ) {
								$measure .= sprintf( ' / %d×%d×%dmm', (int) $row['length_mm'], (int) $row['width_mm'], (int) $row['height_mm'] );
							}
						}
						?>
						<tr>
							<td>
								<code><?php echo esc_html( (string) $row['customer_hawb_code'] ); ?></code>
								<?php if ( ! empty( $row['group_id'] ) ) : ?>
									<br /><small><?php echo esc_html( (string) $row['group_id'] ); ?></small>
								<?php endif; ?>
							</td>
							<td>
								<?php echo esc_html( (string) ( $row['fpx_track_no'] ?: '—' ) ); ?>
								<?php if ( ! empty( $row['service_hawb_code'] ) ) : ?>
									<br /><small><?php echo esc_html( (string) $row['service_hawb_code'] ); ?></small>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $source ); ?></td>
							<td><span class="meb-oms-badge meb-oms-badge--<?php echo esc_attr( strtolower( $status ) ); ?>"><?php echo esc_html( $labels[ $status ] ?? $status ); ?></span></td>
							<td><?php echo esc_html( $measure ?: '—' ); ?></td>
							<td class="meb-oms-actions">
								<?php self::render_row_actions( $row ); ?>
							</td>
						</tr>
						<?php if ( ! empty( $row['last_error'] ) ) : ?>
							<tr class="meb-oms-error-row"><td colspan="6"><?php echo esc_html( (string) $row['last_error'] ); ?></td></tr>
						<?php endif; ?>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( '时间', 'meb-oms' ); ?></th>
							<th><?php esc_html_e( '单号', 'meb-oms' ); ?></th>
							<th><?php esc_html_e( 'action / msgType', 'meb-oms' ); ?></th>
							<th><?php esc_html_e( '仓', 'meb-oms' ); ?></th>
							<th><?php esc_html_e( '处理', 'meb-oms' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php if ( empty( $logs ) ) : ?>
						<tr><td colspan="5"><?php esc_html_e( '还没有仓侧回传。可用下方模拟器联调本地状态机。', 'meb-oms' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $logs as $log ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $log['created_at'] ); ?></td>
							<td><code><?php echo esc_html( (string) $log['order_code'] ); ?></code></td>
							<td><?php echo esc_html( trim( $log['action_code'] . ' / ' . $log['msg_type'], ' /' ) ); ?></td>
							<td><?php echo esc_html( (string) $log['wh_code'] ); ?></td>
							<td><?php echo ! empty( $log['processed'] ) ? esc_html__( '已匹配', 'meb-oms' ) : esc_html__( '未匹配', 'meb-oms' ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<div class="meb-oms-card">
				<h2><?php esc_html_e( '本地模拟回传', 'meb-oms' ); ?></h2>
				<p class="description"><?php esc_html_e( '未接通真实 OMS 前，可用此工具按节点推进代购/转运状态（签收→入库→上架→合箱→出库）。', 'meb-oms' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="meb-oms-simulate">
					<?php wp_nonce_field( 'meb_oms_simulate', 'meb_oms_nonce' ); ?>
					<input type="hidden" name="action" value="meb_oms_simulate" />
					<label>
						<?php esc_html_e( 'OMS 单 ID', 'meb-oms' ); ?>
						<input type="number" name="oms_id" min="1" required />
					</label>
					<label>
						<?php esc_html_e( '节点', 'meb-oms' ); ?>
						<select name="action_code">
							<option value="CW_SIGN_IN">CW_SIGN_IN <?php esc_html_e( '签收', 'meb-oms' ); ?></option>
							<option value="CW_INBOUND">CW_INBOUND <?php esc_html_e( '入库', 'meb-oms' ); ?></option>
							<option value="CW_UPSHELF">CW_UPSHELF <?php esc_html_e( '上架', 'meb-oms' ); ?></option>
							<option value="CW_CONSOED">CW_CONSOED <?php esc_html_e( '合箱', 'meb-oms' ); ?></option>
							<option value="CNCB_CONSO_ORDER_OUTBOUND_CALLBACK"><?php esc_html_e( '出库', 'meb-oms' ); ?></option>
							<option value="CW_EXCEPTION">CW_EXCEPTION <?php esc_html_e( '异常', 'meb-oms' ); ?></option>
						</select>
					</label>
					<label><?php esc_html_e( '重量(g)', 'meb-oms' ); ?> <input type="number" name="weight_g" value="800" /></label>
					<label><?php esc_html_e( '长宽高 mm', 'meb-oms' ); ?>
						<input type="number" name="length_mm" value="300" class="small-text" />
						<input type="number" name="width_mm" value="200" class="small-text" />
						<input type="number" name="height_mm" value="150" class="small-text" />
					</label>
					<label><?php esc_html_e( '库区', 'meb-oms' ); ?> <input type="text" name="storage_district" value="A-01" /></label>
					<button class="button button-primary"><?php esc_html_e( '写入回传', 'meb-oms' ); ?></button>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Row action forms.
	 *
	 * @param array<string, mixed> $row Order.
	 */
	private static function render_row_actions( array $row ): void {
		$id   = (int) $row['id'];
		$leg  = (int) $row['leg'];
		$stat = (string) $row['oms_status'];

		if ( 1 === $leg ) {
			self::action_button( $id, 'forecast', __( '重推一段', 'meb-oms' ) );
			if ( ! empty( $row['fpx_track_no'] ) ) {
				self::action_button( $id, 'update', __( '更新预报', 'meb-oms' ) );
			}
			if ( 'P' === $stat ) {
				self::action_button( $id, 'cancel', __( '取消', 'meb-oms' ) );
			}
			if ( in_array( $stat, array( 'H', 'S' ), true ) ) {
				self::action_button( $id, 'shelf_return', __( '在架退件', 'meb-oms' ) );
				self::action_button( $id, 'leg2', __( '下发二段', 'meb-oms' ) );
			}
		} else {
			self::action_button( $id, 'leg2', __( '重推二段', 'meb-oms' ) );
		}
	}

	/**
	 * Small POST button.
	 */
	private static function action_button( int $id, string $op, string $label ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'meb_oms_action', 'meb_oms_nonce' ); ?>
			<input type="hidden" name="action" value="meb_oms_action" />
			<input type="hidden" name="oms_id" value="<?php echo esc_attr( (string) $id ); ?>" />
			<input type="hidden" name="op" value="<?php echo esc_attr( $op ); ?>" />
			<button class="button button-small"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * Manual OMS operations.
	 */
	public static function handle_action(): void {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( '权限不足。', 'meb-oms' ) );
		}
		check_admin_referer( 'meb_oms_action', 'meb_oms_nonce' );

		$id      = isset( $_POST['oms_id'] ) ? absint( $_POST['oms_id'] ) : 0;
		$op      = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( (string) $_POST['op'] ) ) : '';
		$service = MyEasyBuy_OMS_Service::instance();
		$row     = MyEasyBuy_OMS_Repository::get( $id );
		if ( ! $row ) {
			self::redirect_console( 'error', __( '单据不存在。', 'meb-oms' ) );
		}

		$result = null;
		if ( 'forecast' === $op ) {
			$result = MyEasyBuy_OMS_Service::SOURCE_WC === $row['source_type']
				? $service->forecast_wc_order( (int) $row['source_id'], true )
				: $service->forecast_rf_package( (int) $row['source_id'], true );
		} elseif ( 'update' === $op ) {
			$result = $service->update_leg1( $id );
		} elseif ( 'cancel' === $op ) {
			$result = $service->cancel_leg1( $id );
		} elseif ( 'shelf_return' === $op ) {
			$result = $service->shelf_return( $id );
		} elseif ( 'leg2' === $op ) {
			if ( 2 === (int) $row['leg'] ) {
				$leg1 = MyEasyBuy_OMS_Repository::get_leg1_by_group( (string) $row['group_id'] );
				$result = $service->create_leg2( $leg1, (string) $row['group_id'], (string) $row['source_type'], (int) $row['source_id'] );
			} else {
				$group = (string) $row['group_id'];
				if ( MyEasyBuy_OMS_Service::SOURCE_WC === $row['source_type'] ) {
					$ids = array( (int) $row['source_id'] );
					if ( $group && class_exists( 'MyEasyBuy_Consolidation' ) ) {
						$order = wc_get_order( (int) $row['source_id'] );
						if ( $order ) {
							foreach ( MyEasyBuy_Consolidation::get_group_sibling_orders( $order ) as $sibling ) {
								$ids[] = (int) $sibling->get_id();
							}
						}
					}
					$result = $service->create_leg2_for_wc_group( $ids, $group );
				} else {
					$result = $service->create_leg2( array( $row ), $group ?: ( 'rf-one-' . $row['source_id'] ), MyEasyBuy_OMS_Service::SOURCE_RF, (int) $row['source_id'] );
				}
			}
		}

		if ( is_wp_error( $result ) ) {
			self::redirect_console( 'error', $result->get_error_message() );
		}

		self::redirect_console( 'success', __( 'OMS 指令已提交。', 'meb-oms' ) );
	}

	/**
	 * Simulate warehouse callback.
	 */
	public static function handle_simulate(): void {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( '权限不足。', 'meb-oms' ) );
		}
		check_admin_referer( 'meb_oms_simulate', 'meb_oms_nonce' );

		$id  = isset( $_POST['oms_id'] ) ? absint( $_POST['oms_id'] ) : 0;
		$row = MyEasyBuy_OMS_Repository::get( $id );
		if ( ! $row ) {
			self::redirect_console( 'error', __( '单据不存在。', 'meb-oms' ) );
		}

		$action = isset( $_POST['action_code'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['action_code'] ) ) : 'CW_INBOUND';
		$payload = array(
			'orderCode'            => (string) ( $row['fpx_track_no'] ?: $row['customer_hawb_code'] ),
			'customerHawbCode'     => (string) $row['customer_hawb_code'],
			'fpxTrackNo'           => (string) $row['fpx_track_no'],
			'operationActionCode'  => $action,
			'msgType'              => $action,
			'operateResultCode'    => '0',
			'operationTime'        => (string) round( microtime( true ) * 1000 ),
			'whCode'               => 'TEST',
			'weight'               => isset( $_POST['weight_g'] ) ? absint( $_POST['weight_g'] ) : 0,
			'length'               => isset( $_POST['length_mm'] ) ? absint( $_POST['length_mm'] ) : 0,
			'width'                => isset( $_POST['width_mm'] ) ? absint( $_POST['width_mm'] ) : 0,
			'height'               => isset( $_POST['height_mm'] ) ? absint( $_POST['height_mm'] ) : 0,
			'extInfo'              => array(
				'storageDistrict' => isset( $_POST['storage_district'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['storage_district'] ) ) : '',
				'measureImageUrl' => '',
			),
		);

		MyEasyBuy_OMS_Service::instance()->apply_callback( $payload );
		MyEasyBuy_OMS_Repository::log_callback(
			array(
				'order_code'          => $payload['orderCode'],
				'action_code'         => $action,
				'msg_type'            => 'SIMULATE',
				'operation_time'      => $payload['operationTime'],
				'wh_code'             => 'TEST',
				'operate_result_code' => '0',
				'payload'             => $payload,
				'processed'           => 1,
			)
		);

		self::redirect_console( 'success', __( '已写入模拟回传。', 'meb-oms' ) );
	}

	/**
	 * Order metabox.
	 */
	public static function register_metabox(): void {
		$screens = array( 'shop_order', 'woocommerce_page_wc-orders' );
		foreach ( $screens as $screen ) {
			add_meta_box(
				'meb-oms-box',
				__( '4PX 集运 OMS', 'meb-oms' ),
				array( __CLASS__, 'render_metabox' ),
				$screen,
				'side'
			);
		}
	}

	/**
	 * Metabox body.
	 *
	 * @param WP_Post|WC_Order $post_or_order Order.
	 */
	public static function render_metabox( $post_or_order ): void {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID ?? 0 );
		if ( ! $order ) {
			return;
		}

		$leg1 = MyEasyBuy_OMS_Repository::get_by_source( MyEasyBuy_OMS_Service::SOURCE_WC, (int) $order->get_id(), 1 );
		$leg2 = MyEasyBuy_OMS_Repository::get_by_source( MyEasyBuy_OMS_Service::SOURCE_WC, (int) $order->get_id(), 2 );
		$labels = MyEasyBuy_OMS_Service::status_labels();

		if ( ! $leg1 && ! $leg2 ) {
			echo '<p>' . esc_html__( '尚未创建 OMS 单据。', 'meb-oms' ) . '</p>';
			return;
		}

		foreach ( array( __( '一段', 'meb-oms' ) => $leg1, __( '二段', 'meb-oms' ) => $leg2 ) as $title => $row ) {
			if ( ! $row ) {
				continue;
			}
			echo '<p><strong>' . esc_html( $title ) . '</strong><br />';
			echo esc_html( (string) $row['customer_hawb_code'] ) . '<br />';
			echo esc_html( $labels[ $row['oms_status'] ] ?? $row['oms_status'] );
			echo '</p>';
		}
	}

	/**
	 * Flash notice.
	 */
	private static function maybe_notice(): void {
		if ( empty( $_GET['meb_oms_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$type = isset( $_GET['meb_oms_type'] ) ? sanitize_key( wp_unslash( (string) $_GET['meb_oms_type'] ) ) : 'success'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$msg  = sanitize_text_field( wp_unslash( (string) $_GET['meb_oms_msg'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( 'error' === $type ? 'error' : 'success' ),
			esc_html( $msg )
		);
	}

	/**
	 * Redirect helper.
	 */
	private static function redirect_console( string $type, string $message ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => self::PAGE_CONSOLE,
					'meb_oms_type' => $type,
					'meb_oms_msg'  => $message,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
