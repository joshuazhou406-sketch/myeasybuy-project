<?php
/**
 * 4PX (递四方) international tracking integration.
 *
 * @package MyEasyBuy_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles 4PX API signing, tracking lookup, and delivery automation.
 */
class MyEasyBuy_4PX_Service {

	public const OPTION_KEY = 'myeasybuy_4px_settings';

	public const CRON_HOOK = 'myeasybuy_4px_track_polling';

	public const META_LATEST_TRACK = '_myeasybuy_latest_track';

	public const META_TRACK_HISTORY = '_myeasybuy_track_history';

	public const META_DELIVERED_AT = '_myeasybuy_delivered_at';

	public const META_4PX_REF_NO       = '_myeasybuy_4px_ref_no';
	public const META_4PX_ORDER_NO     = '_myeasybuy_4px_order_no';
	public const META_4PX_LABEL_URL    = '_myeasybuy_4px_label_url';
	public const META_4PX_PRODUCT_CODE = '_myeasybuy_4px_product_code';
	public const META_4PX_RAW_CREATE   = '_myeasybuy_4px_create_response';

	/** Direct shipping — create forecast order. */
	public const API_ORDER_CREATE = 'ds.xms.order.create';

	/** Direct shipping — query order / label / channel no. */
	public const API_ORDER_GET = 'ds.xms.order.get';

	/** Direct shipping — cancel. */
	public const API_ORDER_CANCEL = 'ds.xms.order.cancel';

	/** Direct shipping — update forecast weight. */
	public const API_ORDER_UPDATE_WEIGHT = 'ds.xms.order.updateweight';

	/** Direct shipping — get freight. */
	public const API_ORDER_GET_FREIGHT = 'ds.xms.order.getFreight';

	/** Label — get single label. */
	public const API_LABEL_GET = 'ds.xms.label.get';

	/** Logistics products list. */
	public const API_PRODUCT_LIST = 'ds.xms.logistics_product.getlist';

	/** Estimated cost / rate quote. */
	public const API_ESTIMATED_COST = 'ds.xms.estimated_cost.get';

	/** Tracking query. */
	public const API_METHOD_TRACK = 'tr.order.tracking.get';

	/** Alternate tracking method name used by some partners. */
	public const API_METHOD_TRACK_LEGACY = 'logistic.track.get';

	/** Subscribe tracking number push. */
	public const API_TRACK_SUBSCRIBE = 'gpn.track.subscribe.trackNo';

	public const ACTION_ORDER_DELIVERED = 'myeasybuy_order_delivered';

	/**
	 * Singleton instance.
	 *
	 * @var MyEasyBuy_4PX_Service|null
	 */
	private static ?MyEasyBuy_4PX_Service $instance = null;

	/**
	 * Get singleton instance.
	 */
	public static function instance(): MyEasyBuy_4PX_Service {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		$service = self::instance();

		add_action( 'admin_menu', array( $service, 'register_settings_page' ), 60 );
		add_action( 'admin_init', array( $service, 'register_settings' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'register_cron_schedules' ) );
		add_action( 'admin_post_myeasybuy_4px_create_shipment', array( $service, 'handle_create_shipment' ) );
		add_action( 'admin_post_myeasybuy_4px_refresh_label', array( $service, 'handle_refresh_label' ) );
		add_action( 'admin_post_myeasybuy_4px_sync_tracking', array( $service, 'handle_sync_tracking' ) );

		/*
		 * Existing hook fires as: do_action( 'myeasybuy_international_shipped', $order_id, $tracking_number, $carrier ).
		 * Accept up to 3 args so carrier filtering works.
		 */
		add_action( 'myeasybuy_international_shipped', array( $service, 'on_international_shipped' ), 10, 3 );
		add_action( self::CRON_HOOK, array( $service, 'poll_international_shipments' ) );

		self::ensure_cron_scheduled();
	}

	/**
	 * Schedule cron on plugin activation.
	 */
	public static function activate(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'register_cron_schedules' ) );
		self::ensure_cron_scheduled();

		if ( false === get_option( self::OPTION_KEY, false ) ) {
			add_option( self::OPTION_KEY, self::default_settings() );
		}
	}

	/**
	 * Ensure the 6-hour polling event is scheduled.
	 */
	private static function ensure_cron_scheduled(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'myeasybuy_every_six_hours', self::CRON_HOOK );
		}
	}

	/**
	 * Clear cron on plugin deactivation.
	 */
	public static function deactivate(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		while ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
			$timestamp = wp_next_scheduled( self::CRON_HOOK );
		}
	}

	/**
	 * Default settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function default_settings(): array {
		return array(
			'4px_app_key'            => '',
			'4px_app_secret'         => '',
			'4px_access_token'       => '',
			'4px_sandbox_mode'       => 1,
			'4px_api_method'         => self::API_METHOD_TRACK,
			'4px_product_air'        => '',
			'4px_product_sea'        => '',
			'4px_shipper_name'       => 'MyEasyBuy Warehouse',
			'4px_shipper_company'    => 'MyEasyBuy',
			'4px_shipper_phone'      => '',
			'4px_shipper_email'      => '',
			'4px_shipper_country'    => 'CN',
			'4px_shipper_state'      => 'Guangdong',
			'4px_shipper_city'       => 'Shenzhen',
			'4px_shipper_post_code'  => '518000',
			'4px_shipper_street'     => '',
			'4px_duty_type'          => 'U',
		);
	}

	/**
	 * Get merged settings.
	 *
	 * @return array<string, mixed>
	 */
	public function get_settings(): array {
		return wp_parse_args(
			(array) get_option( self::OPTION_KEY, array() ),
			self::default_settings()
		);
	}

	/**
	 * Whether sandbox mode is enabled (default true).
	 */
	public function is_sandbox_mode(): bool {
		$settings = $this->get_settings();
		return ! empty( $settings['4px_sandbox_mode'] );
	}

	/**
	 * API base URL.
	 */
	public function get_api_base_url(): string {
		// Official test host per open-test.4px.com docs; sandbox alias kept as fallback filter target.
		$url = $this->is_sandbox_mode()
			? 'https://open-test.4px.com/router/api/service'
			: 'https://open.4px.com/router/api/service';

		/**
		 * Filter 4PX API endpoint URL.
		 *
		 * @since 1.4.0
		 *
		 * @param string $url          Endpoint URL.
		 * @param bool   $is_sandbox   Whether sandbox mode is active.
		 */
		return (string) apply_filters( 'myeasybuy_4px_api_base_url', $url, $this->is_sandbox_mode() );
	}

	/**
	 * Register custom cron interval (every 6 hours).
	 *
	 * @param array<string, array<string, mixed>> $schedules Cron schedules.
	 * @return array<string, array<string, mixed>>
	 */
	public static function register_cron_schedules( array $schedules ): array {
		$schedules['myeasybuy_every_six_hours'] = array(
			'interval' => 6 * HOUR_IN_SECONDS,
			'display'  => __( 'Every 6 hours (MyEasyBuy 4PX)', 'myeasybuy-core' ),
		);

		return $schedules;
	}

	/**
	 * Register settings under 集运仓储 (preferred) and WooCommerce fallback.
	 */
	public function register_settings_page(): void {
		$parent = class_exists( 'MyEasyBuy_Admin_Workflow' )
			? MyEasyBuy_Admin_Workflow::MENU_WAREHOUSE
			: 'woocommerce';

		// Credentials require WooCommerce manager; warehouse staff consume the APIs on 国际发货.
		add_submenu_page(
			$parent,
			__( '4PX 物流对接', 'myeasybuy-core' ),
			__( '4PX 物流对接', 'myeasybuy-core' ),
			'manage_woocommerce',
			'myeasybuy-4px-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register settings API fields.
	 */
	public function register_settings(): void {
		register_setting(
			'myeasybuy_4px_settings_group',
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => self::default_settings(),
			)
		);
	}

	/**
	 * Sanitize settings payload.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed>
	 */
	public function sanitize_settings( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::default_settings();
		$out      = array();

		foreach ( $defaults as $key => $default ) {
			if ( '4px_sandbox_mode' === $key ) {
				$out[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
				continue;
			}
			$out[ $key ] = sanitize_text_field( (string) ( $input[ $key ] ?? $default ) );
		}

		return $out;
	}

	/**
	 * Render settings page + integrated API matrix.
	 */
	public function render_settings_page(): void {
		if ( ! MyEasyBuy_Roles::current_user_can_warehouse() && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'myeasybuy-core' ) );
		}

		$settings = $this->get_settings();
		$apis     = self::get_integrated_api_matrix();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( '4PX 物流对接', 'myeasybuy-core' ); ?></h1>
			<p class="description">
				<?php esc_html_e( '对接递四方开放平台（open-test.4px.com / open.4px.com），并统一管理采购仓库收货地址与免费保管天数。仓储录入国际追踪单号并确认发货后，客户订单页通过「物流轨迹查询」接口展示国内+国际全程。', 'myeasybuy-core' ); ?>
				<a href="https://open-test.4px.com/v2/doc?ids=2,106" target="_blank" rel="noopener noreferrer"><?php esc_html_e( '查看官方 API 文档', 'myeasybuy-core' ); ?></a>
				<?php if ( class_exists( 'MyEasyBuy_OMS_Admin' ) ) : ?>
					&nbsp;|&nbsp;
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . MyEasyBuy_OMS_Admin::PAGE_CONSOLE ) ); ?>"><?php esc_html_e( '4PX 集运 OMS 控制台', 'myeasybuy-core' ); ?></a>
				<?php endif; ?>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'myeasybuy_4px_settings_group' ); ?>
				<h2><?php esc_html_e( '凭证与环境', 'myeasybuy-core' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="4px_app_key"><?php esc_html_e( 'App Key', 'myeasybuy-core' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="4px_app_key" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[4px_app_key]" value="<?php echo esc_attr( (string) $settings['4px_app_key'] ); ?>" autocomplete="off" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="4px_app_secret"><?php esc_html_e( 'App Secret', 'myeasybuy-core' ); ?></label></th>
						<td>
							<input type="password" class="regular-text" id="4px_app_secret" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[4px_app_secret]" value="<?php echo esc_attr( (string) $settings['4px_app_secret'] ); ?>" autocomplete="new-password" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="4px_access_token"><?php esc_html_e( 'Access Token（可选）', 'myeasybuy-core' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="4px_access_token" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[4px_access_token]" value="<?php echo esc_attr( (string) $settings['4px_access_token'] ); ?>" autocomplete="off" />
							<p class="description"><?php esc_html_e( '部分合作伙伴账号需要 OAuth access_token；直连商家账号通常可留空。', 'myeasybuy-core' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( '沙箱 / 测试环境', 'myeasybuy-core' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[4px_sandbox_mode]" value="1" <?php checked( ! empty( $settings['4px_sandbox_mode'] ) ); ?> />
								<?php esc_html_e( '启用测试环境（open-test.4px.com）', 'myeasybuy-core' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="4px_api_method"><?php esc_html_e( '轨迹接口 Method', 'myeasybuy-core' ); ?></label></th>
						<td>
							<select id="4px_api_method" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[4px_api_method]">
								<option value="<?php echo esc_attr( self::API_METHOD_TRACK ); ?>" <?php selected( $settings['4px_api_method'], self::API_METHOD_TRACK ); ?>>
									<?php echo esc_html( self::API_METHOD_TRACK ); ?>
								</option>
								<option value="<?php echo esc_attr( self::API_METHOD_TRACK_LEGACY ); ?>" <?php selected( $settings['4px_api_method'], self::API_METHOD_TRACK_LEGACY ); ?>>
									<?php echo esc_html( self::API_METHOD_TRACK_LEGACY ); ?>
								</option>
							</select>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( '物流产品映射（加拿大）', 'myeasybuy-core' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="4px_product_air"><?php esc_html_e( '空运专线产品代码', 'myeasybuy-core' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="4px_product_air" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[4px_product_air]" value="<?php echo esc_attr( (string) $settings['4px_product_air'] ); ?>" placeholder="e.g. A1" />
							<p class="description"><?php esc_html_e( '对应客户渠道「加拿大空运专线」。可先用「物流产品查询」接口核对代码。', 'myeasybuy-core' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="4px_product_sea"><?php esc_html_e( '海运大包产品代码', 'myeasybuy-core' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="4px_product_sea" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[4px_product_sea]" value="<?php echo esc_attr( (string) $settings['4px_product_sea'] ); ?>" placeholder="e.g. S1" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="4px_duty_type"><?php esc_html_e( '税金支付方式 duty_type', 'myeasybuy-core' ); ?></label></th>
						<td>
							<input type="text" class="small-text" id="4px_duty_type" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[4px_duty_type]" value="<?php echo esc_attr( (string) $settings['4px_duty_type'] ); ?>" />
							<p class="description"><?php esc_html_e( '常见：U=未税/到付相关；以 4PX 产品说明为准。', 'myeasybuy-core' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( '深圳仓寄件人', 'myeasybuy-core' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$shipper_fields = array(
						'4px_shipper_name'      => __( '姓名', 'myeasybuy-core' ),
						'4px_shipper_company'   => __( '公司', 'myeasybuy-core' ),
						'4px_shipper_phone'     => __( '电话', 'myeasybuy-core' ),
						'4px_shipper_email'     => __( '邮箱', 'myeasybuy-core' ),
						'4px_shipper_country'   => __( '国家代码', 'myeasybuy-core' ),
						'4px_shipper_state'     => __( '省/州', 'myeasybuy-core' ),
						'4px_shipper_city'      => __( '城市', 'myeasybuy-core' ),
						'4px_shipper_post_code' => __( '邮编', 'myeasybuy-core' ),
						'4px_shipper_street'    => __( '街道地址', 'myeasybuy-core' ),
					);
					foreach ( $shipper_fields as $key => $label ) :
						?>
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
							<td>
								<input type="text" class="regular-text" id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( (string) $settings[ $key ] ); ?>" />
							</td>
						</tr>
					<?php endforeach; ?>
				</table>

				<?php
				if ( class_exists( 'MyEasyBuy_Procurement' ) ) {
					MyEasyBuy_Procurement::render_warehouse_settings_fields();
				}
				?>

				<?php submit_button( __( '保存设置', 'myeasybuy-core' ) ); ?>
			</form>

			<h2><?php esc_html_e( '已对接 4PX API 一览', 'myeasybuy-core' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( '分类', 'myeasybuy-core' ); ?></th>
						<th><?php esc_html_e( 'Method', 'myeasybuy-core' ); ?></th>
						<th><?php esc_html_e( '说明', 'myeasybuy-core' ); ?></th>
						<th><?php esc_html_e( '集运仓储用途', 'myeasybuy-core' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $apis as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['category'] ); ?></td>
						<td><code><?php echo esc_html( $row['method'] ); ?></code></td>
						<td><?php echo esc_html( $row['title'] ); ?></td>
						<td><?php echo esc_html( $row['usage'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<p>
				<?php
				printf(
					/* translators: %s: cron hook name */
					esc_html__( '定时轮询任务：%s（每 6 小时）', 'myeasybuy-core' ),
					'<code>' . esc_html( self::CRON_HOOK ) . '</code>'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Integrated API matrix for admin + docs.
	 *
	 * @return array<int, array{category: string, method: string, title: string, usage: string}>
	 */
	public static function get_integrated_api_matrix(): array {
		return array(
			array(
				'category' => __( '订单操作', 'myeasybuy-core' ),
				'method'   => self::API_ORDER_CREATE,
				'title'    => __( '创建直发委托单', 'myeasybuy-core' ),
				'usage'    => __( '国际发货：一键向 4PX 预报出库包裹', 'myeasybuy-core' ),
			),
			array(
				'category' => __( '订单操作', 'myeasybuy-core' ),
				'method'   => self::API_ORDER_GET,
				'title'    => __( '查询订单', 'myeasybuy-core' ),
				'usage'    => __( '回写 4PX 单号、渠道号、面单状态', 'myeasybuy-core' ),
			),
			array(
				'category' => __( '订单操作', 'myeasybuy-core' ),
				'method'   => self::API_ORDER_CANCEL,
				'title'    => __( '取消直发委托单', 'myeasybuy-core' ),
				'usage'    => __( '预报后、货未交 4PX 前取消', 'myeasybuy-core' ),
			),
			array(
				'category' => __( '订单操作', 'myeasybuy-core' ),
				'method'   => self::API_ORDER_UPDATE_WEIGHT,
				'title'    => __( '更新预报重', 'myeasybuy-core' ),
				'usage'    => __( '合箱实重变更后同步预报重量', 'myeasybuy-core' ),
			),
			array(
				'category' => __( '费用查询', 'myeasybuy-core' ),
				'method'   => self::API_ORDER_GET_FREIGHT,
				'title'    => __( '查询订单费用信息', 'myeasybuy-core' ),
				'usage'    => __( '核对 4PX 账单费用（辅助）', 'myeasybuy-core' ),
			),
			array(
				'category' => __( '获取标签', 'myeasybuy-core' ),
				'method'   => self::API_LABEL_GET,
				'title'    => __( '获取标签', 'myeasybuy-core' ),
				'usage'    => __( '国际发货页下载/打开面单 PDF', 'myeasybuy-core' ),
			),
			array(
				'category' => __( '产品与试算', 'myeasybuy-core' ),
				'method'   => self::API_PRODUCT_LIST,
				'title'    => __( '物流产品查询', 'myeasybuy-core' ),
				'usage'    => __( '配置空运/海运产品代码', 'myeasybuy-core' ),
			),
			array(
				'category' => __( '产品与试算', 'myeasybuy-core' ),
				'method'   => self::API_ESTIMATED_COST,
				'title'    => __( '预估费用查询/运费试算', 'myeasybuy-core' ),
				'usage'    => __( '合箱前试算国际运费（可选）', 'myeasybuy-core' ),
			),
			array(
				'category' => __( '轨迹查询', 'myeasybuy-core' ),
				'method'   => self::API_METHOD_TRACK,
				'title'    => __( '物流轨迹查询', 'myeasybuy-core' ),
				'usage'    => __( '发货后轮询轨迹，客户订单页展示国内+国际全程，签收自动完结', 'myeasybuy-core' ),
			),
			array(
				'category' => __( '轨迹查询', 'myeasybuy-core' ),
				'method'   => self::API_TRACK_SUBSCRIBE,
				'title'    => __( '物流轨迹单号订阅', 'myeasybuy-core' ),
				'usage'    => __( '预报成功后订阅轨迹推送', 'myeasybuy-core' ),
			),
		);
	}

	/**
	 * Low-level signed POST to 4PX router API.
	 *
	 * @param string               $method  API method name.
	 * @param array<string, mixed> $body    Business JSON body.
	 * @param string               $version Protocol version (v query param / method version).
	 * @return array<string, mixed>|WP_Error Decoded response.
	 */
	public function api_request( string $method, array $body = array(), string $version = '1.0.0' ) {
		if ( ! $this->has_credentials() ) {
			return new WP_Error( 'myeasybuy_4px_missing_credentials', __( '4PX App Key / Secret is not configured.', 'myeasybuy-core' ) );
		}

		$settings  = $this->get_settings();
		$body_json = wp_json_encode( $body, JSON_UNESCAPED_UNICODE );
		if ( false === $body_json ) {
			return new WP_Error( 'myeasybuy_4px_json_encode', __( 'Failed to encode 4PX request body.', 'myeasybuy-core' ) );
		}

		$common_params = array(
			'method'    => $method,
			'app_key'   => (string) $settings['4px_app_key'],
			'v'         => $version,
			'timestamp' => (string) (int) round( microtime( true ) * 1000 ),
			'format'    => 'json',
			'language'  => 'cn',
		);

		$token = trim( (string) $settings['4px_access_token'] );
		if ( '' !== $token ) {
			$common_params['access_token'] = $token;
		}

		$common_params['sign'] = $this->generate_sign( $common_params, $body_json );
		$url = add_query_arg( $common_params, $this->get_api_base_url() );

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 45,
				'headers' => array(
					'Content-Type' => 'application/json; charset=utf-8',
					'Accept'       => 'application/json',
				),
				'body'    => $body_json,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );

		if ( $code >= 400 ) {
			return new WP_Error(
				'myeasybuy_4px_http_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( '4PX API HTTP error: %d', 'myeasybuy-core' ),
					$code
				),
				array( 'body' => $raw )
			);
		}

		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'myeasybuy_4px_invalid_json', __( 'Invalid JSON response from 4PX.', 'myeasybuy-core' ), array( 'body' => $raw ) );
		}

		$result_flag = isset( $data['result'] ) ? (string) $data['result'] : '1';
		if ( '1' !== $result_flag && 'true' !== strtolower( $result_flag ) && '2' !== $result_flag ) {
			$message = (string) ( $data['msg'] ?? $data['message'] ?? $data['error'] ?? __( '4PX API returned an error.', 'myeasybuy-core' ) );
			return new WP_Error( 'myeasybuy_4px_api_error', $message, array( 'response' => $data ) );
		}

		return $data;
	}

	/**
	 * Query logistics products.
	 *
	 * @param array<string, mixed> $filters Optional filters (e.g. transport_mode).
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_logistics_products( array $filters = array() ) {
		return $this->api_request( self::API_PRODUCT_LIST, $filters, '1.0.0' );
	}

	/**
	 * Estimate shipping cost.
	 *
	 * @param array<string, mixed> $payload Quote payload.
	 * @return array<string, mixed>|WP_Error
	 */
	public function estimate_cost( array $payload ) {
		return $this->api_request( self::API_ESTIMATED_COST, $payload, '1.0.0' );
	}

	/**
	 * Create 4PX direct shipping order from a WooCommerce parent order.
	 *
	 * @param int         $order_id     WC order ID.
	 * @param string|null $product_code Override logistics product code.
	 * @return array<string, mixed>|WP_Error Normalized create result.
	 */
	public function create_shipment_for_order( int $order_id, ?string $product_code = null ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'myeasybuy_invalid_order', __( 'Order not found.', 'myeasybuy-core' ) );
		}

		$payload = $this->build_create_order_payload( $order, $product_code );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		/**
		 * Filter 4PX create-order business body before request.
		 *
		 * @since 1.9.0
		 *
		 * @param array<string, mixed> $payload Payload.
		 * @param WC_Order             $order   Order.
		 */
		$payload = apply_filters( 'myeasybuy_4px_create_order_payload', $payload, $order );

		$response = $this->api_request( self::API_ORDER_CREATE, $payload, '1.1.0' );
		if ( is_wp_error( $response ) ) {
			// Fallback to public 1.0.0 if 1.1.0 unavailable.
			$response = $this->api_request( self::API_ORDER_CREATE, $payload, '1.0.0' );
		}
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data       = is_array( $response['data'] ?? null ) ? $response['data'] : array();
		$ref_no     = (string) ( $data['ref_no'] ?? $payload['ref_no'] ?? '' );
		$order_no   = (string) ( $data['4px_tracking_no'] ?? $data['ds_tracking_no'] ?? $data['fpxTrackingNo'] ?? $data['order_no'] ?? '' );
		$label_url  = (string) ( $data['label_url'] ?? $data['labelUrl'] ?? $data['label_url_info']['logistics_label'] ?? '' );
		$channel_no = (string) ( $data['logistics_channel_no'] ?? $data['shipping_no'] ?? $order_no );

		$order->update_meta_data( self::META_4PX_REF_NO, $ref_no );
		$order->update_meta_data( self::META_4PX_ORDER_NO, $order_no ?: $channel_no );
		$order->update_meta_data( self::META_4PX_PRODUCT_CODE, (string) ( $payload['logistics_service_info']['logistics_product_code'] ?? '' ) );
		$order->update_meta_data( self::META_4PX_RAW_CREATE, $response );
		if ( $label_url ) {
			$order->update_meta_data( self::META_4PX_LABEL_URL, $label_url );
		}
		if ( $channel_no ) {
			$order->update_meta_data( '_myeasybuy_int_carrier', '4PX' );
			$order->update_meta_data( '_myeasybuy_int_tracking_number', $channel_no );
		}
		$order->save();

		if ( $channel_no ) {
			$this->subscribe_tracking( $channel_no );
			if ( ! $label_url ) {
				$label = $this->get_label( $ref_no ?: $channel_no );
				if ( ! is_wp_error( $label ) ) {
					$label_data = is_array( $label['data'] ?? null ) ? $label['data'] : array();
					$label_url  = (string) ( $label_data['label_url_info']['logistics_label'] ?? $label_data['label_url'] ?? $label_data['labelUrl'] ?? '' );
					if ( $label_url ) {
						$order->update_meta_data( self::META_4PX_LABEL_URL, $label_url );
						$order->save();
					}
				}
			}
		}

		return array(
			'ref_no'          => $ref_no,
			'tracking_number' => $channel_no,
			'label_url'       => $label_url,
			'raw'             => $response,
		);
	}

	/**
	 * Query 4PX order.
	 *
	 * @param string $ref_no Merchant reference / 4PX no.
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_order( string $ref_no ) {
		return $this->api_request(
			self::API_ORDER_GET,
			array(
				'request_no'      => $ref_no,
				'request_no_type' => '1',
			),
			'1.0.0'
		);
	}

	/**
	 * Get label for a forecast order.
	 *
	 * @param string $ref_no Reference / tracking.
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_label( string $ref_no ) {
		return $this->api_request(
			self::API_LABEL_GET,
			array(
				'request_no' => $ref_no,
			),
			'1.0.0'
		);
	}

	/**
	 * Cancel 4PX order.
	 *
	 * @param string $ref_no Reference number.
	 * @return array<string, mixed>|WP_Error
	 */
	public function cancel_order( string $ref_no ) {
		return $this->api_request(
			self::API_ORDER_CANCEL,
			array(
				'request_no' => $ref_no,
			),
			'1.0.0'
		);
	}

	/**
	 * Update forecast weight.
	 *
	 * @param string $ref_no Reference.
	 * @param float  $weight_kg Weight kg.
	 * @return array<string, mixed>|WP_Error
	 */
	public function update_weight( string $ref_no, float $weight_kg ) {
		return $this->api_request(
			self::API_ORDER_UPDATE_WEIGHT,
			array(
				'request_no' => $ref_no,
				'weight'     => round( max( 0.001, $weight_kg ), 3 ),
			),
			'1.0.0'
		);
	}

	/**
	 * Subscribe tracking push for a number.
	 *
	 * @param string $tracking_number Tracking / waybill.
	 * @return array<string, mixed>|WP_Error
	 */
	public function subscribe_tracking( string $tracking_number ) {
		$tracking_number = sanitize_text_field( $tracking_number );
		if ( '' === $tracking_number ) {
			return new WP_Error( 'myeasybuy_4px_empty_tracking', __( 'Tracking number is required.', 'myeasybuy-core' ) );
		}

		return $this->api_request(
			self::API_TRACK_SUBSCRIBE,
			array(
				'trackNo' => $tracking_number,
			),
			'1.0.0'
		);
	}

	/**
	 * Build create-order business payload from WooCommerce order.
	 *
	 * @param WC_Order    $order        Order.
	 * @param string|null $product_code Product code override.
	 * @return array<string, mixed>|WP_Error
	 */
	public function build_create_order_payload( WC_Order $order, ?string $product_code = null ) {
		$settings = $this->get_settings();
		$channel  = (string) $order->get_meta( MyEasyBuy_Consolidation::META_SHIPPING_CHANNEL, true );
		if ( null === $product_code || '' === $product_code ) {
			$product_code = MyEasyBuy_Consolidation::CHANNEL_SEA === $channel
				? (string) $settings['4px_product_sea']
				: (string) $settings['4px_product_air'];
		}

		$product_code = sanitize_text_field( (string) $product_code );
		if ( '' === $product_code ) {
			return new WP_Error(
				'myeasybuy_4px_missing_product',
				__( '请先在 4PX 设置中配置空运/海运物流产品代码。', 'myeasybuy-core' )
			);
		}

		$weight_kg = (float) $order->get_meta( MyEasyBuy_Consolidation::META_CONSOLIDATED_WEIGHT, true );
		if ( $weight_kg <= 0 ) {
			$grams = (int) $order->get_meta( '_myeasybuy_actual_weight', true );
			$weight_kg = $grams > 0 ? round( $grams / 1000, 3 ) : 0.5;
		}

		$volume = (float) $order->get_meta( MyEasyBuy_Consolidation::META_CONSOLIDATED_VOLUME, true );
		$length = 20.0;
		$width  = 15.0;
		$height = 10.0;
		$dims   = (string) $order->get_meta( '_myeasybuy_dimensions', true );
		if ( preg_match( '/([\d.]+)\s*[x×*]\s*([\d.]+)\s*[x×*]\s*([\d.]+)/u', $dims, $m ) ) {
			$length = (float) $m[1];
			$width  = (float) $m[2];
			$height = (float) $m[3];
		} elseif ( $volume > 0 ) {
			$side   = max( 5.0, round( pow( $volume, 1 / 3 ), 1 ) );
			$length = $width = $height = $side;
		}

		$items          = array();
		$parcel_value   = 0.0;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$qty   = max( 1, (int) $item->get_quantity() );
			$price = (float) $item->get_total() / $qty;
			$parcel_value += (float) $item->get_total();
			$items[] = array(
				'declare_product_name_cn'   => mb_substr( $item->get_name(), 0, 60 ),
				'declare_product_name_en'   => mb_substr( $item->get_name(), 0, 60 ),
				'declare_product_code_qty'  => $qty,
				'unit_price'                => round( max( 0.01, $price ), 2 ),
				'currency'                  => $order->get_currency() ?: 'CAD',
				'declare_product_code_weight' => round( max( 0.01, $weight_kg / max( 1, count( $order->get_items( 'line_item' ) ) ) ), 3 ),
			);
		}
		if ( empty( $items ) ) {
			$items[] = array(
				'declare_product_name_cn'     => '日用百货',
				'declare_product_name_en'     => 'General merchandise',
				'declare_product_code_qty'    => 1,
				'unit_price'                  => 1,
				'currency'                    => $order->get_currency() ?: 'CAD',
				'declare_product_code_weight' => $weight_kg,
			);
		}

		$ship = $order->get_address( 'shipping' );
		if ( empty( $ship['address_1'] ) ) {
			$ship = $order->get_address( 'billing' );
		}

		$ref_no = 'MEB' . $order->get_id() . 'T' . time();

		return array(
			'ref_no'                  => $ref_no,
			'business_type'           => 'BDS',
			'duty_type'               => (string) $settings['4px_duty_type'],
			'cargo_type'              => '5',
			'logistics_service_info'  => array(
				'logistics_product_code' => $product_code,
			),
			'return_info'             => array(
				'is_return_on_domestic' => 'N',
				'is_return_on_oversea'  => 'N',
			),
			'parcel_list'             => array(
				array(
					'weight'               => round( $weight_kg, 3 ),
					'length'               => $length,
					'width'                => $width,
					'height'               => $height,
					'parcel_value'         => round( max( 1, $parcel_value ), 2 ),
					'currency'             => $order->get_currency() ?: 'CAD',
					'include_battery'      => 'N',
					'declare_product_info' => $items,
				),
			),
			'sender'                  => array(
				'first_name'  => (string) $settings['4px_shipper_name'],
				'company'     => (string) $settings['4px_shipper_company'],
				'phone'       => (string) $settings['4px_shipper_phone'],
				'email'       => (string) $settings['4px_shipper_email'],
				'country'     => (string) $settings['4px_shipper_country'],
				'state'       => (string) $settings['4px_shipper_state'],
				'city'        => (string) $settings['4px_shipper_city'],
				'post_code'   => (string) $settings['4px_shipper_post_code'],
				'street'      => (string) $settings['4px_shipper_street'],
			),
			'recipient_info'          => array(
				'first_name' => trim( (string) ( $ship['first_name'] ?? '' ) . ' ' . (string) ( $ship['last_name'] ?? '' ) ),
				'company'    => (string) ( $ship['company'] ?? '' ),
				'phone'      => (string) ( $ship['phone'] ?: $order->get_billing_phone() ),
				'email'      => (string) $order->get_billing_email(),
				'country'    => (string) ( $ship['country'] ?? 'CA' ),
				'state'      => (string) ( $ship['state'] ?? '' ),
				'city'       => (string) ( $ship['city'] ?? '' ),
				'post_code'  => (string) ( $ship['postcode'] ?? '' ),
				'street'     => trim( (string) ( $ship['address_1'] ?? '' ) . ' ' . (string) ( $ship['address_2'] ?? '' ) ),
			),
		);
	}

	/**
	 * Admin: create 4PX shipment then mark international shipped.
	 */
	public function handle_create_shipment(): void {
		if ( ! MyEasyBuy_Roles::current_user_can_warehouse() ) {
			wp_die( esc_html__( 'Permission denied.', 'myeasybuy-core' ) );
		}
		check_admin_referer( 'myeasybuy_4px_create_shipment', 'myeasybuy_nonce' );

		$order_id     = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$product_code = isset( $_POST['4px_product_code'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['4px_product_code'] ) ) : '';
		$auto_ship    = ! empty( $_POST['auto_mark_shipped'] );

		$result = $this->create_shipment_for_order( $order_id, $product_code ?: null );
		$redirect = MyEasyBuy_Admin_Workflow::page_url( MyEasyBuy_Admin_Workflow::PAGE_INT_SHIPPING, array( 'order_id' => $order_id ) );

		if ( is_wp_error( $result ) ) {
			MyEasyBuy_Admin_Workflow::redirect_with_message_public( $redirect, 'error', $result->get_error_message() );
		}

		$tracking = (string) ( $result['tracking_number'] ?? '' );
		if ( $auto_ship && $tracking ) {
			$ship = MyEasyBuy_Consolidation::record_international_shipment( $order_id, '4PX', $tracking );
			if ( is_wp_error( $ship ) ) {
				MyEasyBuy_Admin_Workflow::redirect_with_message_public(
					$redirect,
					'error',
					sprintf(
						/* translators: 1: tracking, 2: error */
						__( '4PX 预报成功（%1$s），但标记发货失败：%2$s', 'myeasybuy-core' ),
						$tracking,
						$ship->get_error_message()
					)
				);
			}
		}

		MyEasyBuy_Admin_Workflow::redirect_with_message_public(
			MyEasyBuy_Admin_Workflow::PAGE_INT_SHIPPING,
			'success',
			sprintf(
				/* translators: 1: order id, 2: tracking */
				__( '订单 #%1$d 已通过 4PX 预报，追踪号：%2$s', 'myeasybuy-core' ),
				$order_id,
				$tracking ?: '—'
			)
		);
	}

	/**
	 * Admin: refresh label URL from 4PX.
	 */
	public function handle_refresh_label(): void {
		if ( ! MyEasyBuy_Roles::current_user_can_warehouse() ) {
			wp_die( esc_html__( 'Permission denied.', 'myeasybuy-core' ) );
		}
		check_admin_referer( 'myeasybuy_4px_refresh_label', 'myeasybuy_nonce' );

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order    = wc_get_order( $order_id );
		$redirect = MyEasyBuy_Admin_Workflow::page_url( MyEasyBuy_Admin_Workflow::PAGE_INT_SHIPPING, array( 'order_id' => $order_id ) );

		if ( ! $order ) {
			MyEasyBuy_Admin_Workflow::redirect_with_message_public( $redirect, 'error', __( '订单不存在。', 'myeasybuy-core' ) );
		}

		$ref = (string) ( $order->get_meta( self::META_4PX_REF_NO, true ) ?: $order->get_meta( self::META_4PX_ORDER_NO, true ) );
		if ( '' === $ref ) {
			MyEasyBuy_Admin_Workflow::redirect_with_message_public( $redirect, 'error', __( '尚未创建 4PX 预报单。', 'myeasybuy-core' ) );
		}

		$label = $this->get_label( $ref );
		if ( is_wp_error( $label ) ) {
			MyEasyBuy_Admin_Workflow::redirect_with_message_public( $redirect, 'error', $label->get_error_message() );
		}

		$label_data = is_array( $label['data'] ?? null ) ? $label['data'] : array();
		$label_url  = (string) ( $label_data['label_url_info']['logistics_label'] ?? $label_data['label_url'] ?? $label_data['labelUrl'] ?? '' );
		if ( $label_url ) {
			$order->update_meta_data( self::META_4PX_LABEL_URL, $label_url );
			$order->save();
		}

		MyEasyBuy_Admin_Workflow::redirect_with_message_public(
			$redirect,
			'success',
			$label_url ? __( '面单已更新。', 'myeasybuy-core' ) : __( '4PX 暂未返回面单链接。', 'myeasybuy-core' )
		);
	}

	/**
	 * Admin: sync tracking once.
	 */
	public function handle_sync_tracking(): void {
		if ( ! MyEasyBuy_Roles::current_user_can_warehouse() ) {
			wp_die( esc_html__( 'Permission denied.', 'myeasybuy-core' ) );
		}
		check_admin_referer( 'myeasybuy_4px_sync_tracking', 'myeasybuy_nonce' );

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order    = wc_get_order( $order_id );
		$redirect = MyEasyBuy_Admin_Workflow::page_url( MyEasyBuy_Admin_Workflow::PAGE_INT_SHIPPING, array( 'order_id' => $order_id ) );

		if ( ! $order ) {
			MyEasyBuy_Admin_Workflow::redirect_with_message_public( $redirect, 'error', __( '订单不存在。', 'myeasybuy-core' ) );
		}

		$tracking = (string) $order->get_meta( '_myeasybuy_int_tracking_number', true );
		if ( '' === $tracking ) {
			$tracking = (string) $order->get_meta( self::META_4PX_ORDER_NO, true );
		}

		$result = $this->get_tracking( $tracking );
		if ( is_wp_error( $result ) ) {
			MyEasyBuy_Admin_Workflow::redirect_with_message_public( $redirect, 'error', $result->get_error_message() );
		}

		$this->persist_tracking_to_order( $order_id, $result, true );
		MyEasyBuy_Admin_Workflow::redirect_with_message_public( $redirect, 'success', __( '轨迹已同步。', 'myeasybuy-core' ) );
	}

	/**
	 * Call 4PX tracking API and return normalized latest track + full tracks.
	 *
	 * @param string $tracking_number International tracking / 4PX waybill number.
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_tracking( string $tracking_number ) {
		$tracking_number = sanitize_text_field( $tracking_number );
		if ( '' === $tracking_number ) {
			return new WP_Error( 'myeasybuy_4px_empty_tracking', __( 'Tracking number is required.', 'myeasybuy-core' ) );
		}

		$settings = $this->get_settings();
		$method   = (string) ( $settings['4px_api_method'] ?? self::API_METHOD_TRACK );
		$method   = (string) apply_filters( 'myeasybuy_4px_tracking_method', $method, $tracking_number );

		$body_payload = apply_filters(
			'myeasybuy_4px_tracking_body',
			array(
				'deliveryOrderNo' => $tracking_number,
			),
			$tracking_number,
			$method
		);

		$data = $this->api_request( $method, is_array( $body_payload ) ? $body_payload : array(), '1.0.0' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$tracks = $this->normalize_tracks( $this->extract_tracks( $data ) );
		$latest = $tracks[0] ?? array();

		return array(
			'tracking_number' => $tracking_number,
			'latest'          => $latest,
			'tracks'          => $tracks,
			'raw'             => $data,
			'fetched_at'      => gmdate( 'c' ),
		);
	}

	/**
	 * Whether App Key / Secret are configured.
	 */
	public function is_configured(): bool {
		return $this->has_credentials();
	}

	/**
	 * Public 4PX tracking page for a waybill number.
	 *
	 * @param string $tracking_number 4PX / channel tracking number.
	 */
	public function get_public_tracking_url( string $tracking_number ): string {
		$tracking_number = sanitize_text_field( $tracking_number );
		$url             = 'https://track.4px.com/?nums=' . rawurlencode( $tracking_number );

		/**
		 * Filter the public 4PX tracking URL shown to customers.
		 *
		 * @since 1.10.0
		 *
		 * @param string $url              Tracking URL.
		 * @param string $tracking_number  Waybill number.
		 */
		return (string) apply_filters( 'myeasybuy_4px_public_tracking_url', $url, $tracking_number );
	}

	/**
	 * Whether this order's carrier/number should be queried via 4PX.
	 *
	 * @param string $carrier         Carrier label.
	 * @param string $tracking_number Tracking / waybill number.
	 */
	public function uses_4px_tracking( string $carrier, string $tracking_number = '' ): bool {
		return $this->should_track_carrier( $carrier, $tracking_number );
	}

	/**
	 * Fetch tracking from 4PX and persist onto the order.
	 *
	 * @param int  $order_id      Order ID.
	 * @param bool $auto_complete Whether to complete the order on delivery.
	 * @return array<string, mixed>|WP_Error
	 */
	public function sync_order_tracking( int $order_id, bool $auto_complete = true ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'myeasybuy_invalid_order', __( 'Order not found.', 'myeasybuy-core' ) );
		}

		$tracking = (string) $order->get_meta( '_myeasybuy_int_tracking_number', true );
		if ( '' === $tracking ) {
			$tracking = (string) $order->get_meta( self::META_4PX_ORDER_NO, true );
		}

		$result = $this->get_tracking( $tracking );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->persist_tracking_to_order( $order_id, $result, $auto_complete );

		return $result;
	}

	/**
	 * Display payload for a customer/admin tracking timeline.
	 *
	 * @param WC_Order $order   Order.
	 * @param bool     $refresh Whether to hit 4PX now (otherwise return cached meta).
	 * @return array<string, mixed>
	 */
	public function get_order_tracking_display( WC_Order $order, bool $refresh = false ): array {
		$tracking = (string) $order->get_meta( '_myeasybuy_int_tracking_number', true );
		if ( '' === $tracking ) {
			$tracking = (string) $order->get_meta( self::META_4PX_ORDER_NO, true );
		}

		$carrier = (string) $order->get_meta( '_myeasybuy_int_carrier', true );
		$error   = '';

		if ( $refresh && '' !== $tracking && $this->has_credentials() && $this->uses_4px_tracking( $carrier, $tracking ) ) {
			$result = $this->sync_order_tracking( (int) $order->get_id(), true );
			if ( is_wp_error( $result ) ) {
				$error = $result->get_error_message();
			} else {
				$fresh = wc_get_order( $order->get_id() );
				if ( $fresh instanceof WC_Order ) {
					$order = $fresh;
				}
			}
		}

		$history = $order->get_meta( self::META_TRACK_HISTORY, true );
		$tracks  = $this->normalize_tracks( is_array( $history ) ? $history : array() );
		$latest  = $order->get_meta( self::META_LATEST_TRACK, true );
		if ( ! is_array( $latest ) || empty( $latest ) ) {
			$latest = $tracks[0] ?? array();
		} else {
			$normalized_latest = $this->normalize_tracks( array( $latest ) );
			$latest            = $normalized_latest[0] ?? array();
		}

		return array(
			'tracking_number' => $tracking,
			'carrier'         => $carrier ?: '4PX',
			'latest'          => $latest,
			'tracks'          => $tracks,
			'fetched_at'      => (string) $order->get_meta( '_myeasybuy_track_fetched_at', true ),
			'public_url'      => $this->get_public_tracking_url( $tracking ),
			'error'           => $error,
			'is_delivered'    => 'yes' === (string) $order->get_meta( '_myeasybuy_is_delivered', true ),
			'uses_4px'        => $this->uses_4px_tracking( $carrier, $tracking ),
			'is_configured'   => $this->has_credentials(),
		);
	}

	/**
	 * Callback when international shipment is recorded.
	 *
	 * @param int    $order_id        Order ID.
	 * @param string $tracking_number Tracking number.
	 * @param string $carrier         Carrier name.
	 */
	public function on_international_shipped( $order_id, $tracking_number = '', $carrier = '' ): void {
		$order_id        = absint( $order_id );
		$tracking_number = sanitize_text_field( (string) $tracking_number );
		$carrier         = sanitize_text_field( (string) $carrier );

		if ( $order_id <= 0 || '' === $tracking_number ) {
			return;
		}

		if ( ! $this->should_track_carrier( $carrier, $tracking_number ) ) {
			return;
		}

		if ( ! $this->has_credentials() ) {
			return;
		}

		$this->subscribe_tracking( $tracking_number );

		$result = $this->get_tracking( $tracking_number );
		if ( is_wp_error( $result ) ) {
			$this->log( 'Immediate tracking failed for order #' . $order_id . ': ' . $result->get_error_message() );
			return;
		}

		$this->persist_tracking_to_order( $order_id, $result, true );
	}

	/**
	 * Cron: poll all int-shipped orders that are not yet delivered.
	 */
	public function poll_international_shipments(): void {
		if ( ! $this->has_credentials() ) {
			return;
		}

		$orders = wc_get_orders(
			array(
				'status'  => array( str_replace( 'wc-', '', MyEasyBuy_Order_Statuses::STATUS_INT_SHIPPED ) ),
				'limit'   => 50,
				'orderby' => 'date',
				'order'   => 'ASC',
			)
		);

		if ( empty( $orders ) ) {
			return;
		}

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			if ( 'yes' === (string) $order->get_meta( '_myeasybuy_is_delivered', true ) ) {
				continue;
			}

			$tracking = (string) $order->get_meta( '_myeasybuy_int_tracking_number', true );
			$carrier  = (string) $order->get_meta( '_myeasybuy_int_carrier', true );

			if ( '' === $tracking || ! $this->should_track_carrier( $carrier, $tracking ) ) {
				continue;
			}

			$result = $this->get_tracking( $tracking );
			if ( is_wp_error( $result ) ) {
				$this->log( 'Polling failed for order #' . $order->get_id() . ': ' . $result->get_error_message() );
				continue;
			}

			$this->persist_tracking_to_order( (int) $order->get_id(), $result, true );
			usleep( 250000 );
		}
	}

	/**
	 * Generate 4PX MD5 signature.
	 *
	 * Algorithm: sort params by key, concatenate key+value, append request body, append app_secret, MD5 lowercase.
	 *
	 * @param array<string, mixed> $params Common query parameters (without sign).
	 * @param string               $body   JSON request body.
	 */
	private function generate_sign( array $params, string $body = '' ): string {
		unset( $params['sign'], $params['access_token'], $params['language'] );

		ksort( $params, SORT_STRING );

		$concat = '';
		foreach ( $params as $key => $value ) {
			if ( null === $value || '' === (string) $value ) {
				continue;
			}
			$concat .= (string) $key . (string) $value;
		}

		$settings = $this->get_settings();
		$secret   = (string) ( $settings['4px_app_secret'] ?? '' );
		$concat  .= $body . $secret;

		/**
		 * Filter the raw string before MD5 hashing (for debugging / custom signing).
		 *
		 * @since 1.4.0
		 *
		 * @param string               $concat Raw sign string.
		 * @param array<string, mixed> $params Sorted params.
		 * @param string               $body   Request body.
		 */
		$concat = (string) apply_filters( 'myeasybuy_4px_sign_string', $concat, $params, $body );

		return md5( $concat );
	}

	/**
	 * Persist tracking result onto the order and optionally complete if delivered.
	 *
	 * @param int                  $order_id        Order ID.
	 * @param array<string, mixed> $tracking_result Normalized tracking payload.
	 * @param bool                 $auto_complete   Whether to complete order on delivery.
	 */
	private function persist_tracking_to_order( int $order_id, array $tracking_result, bool $auto_complete ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$tracks = $this->normalize_tracks( is_array( $tracking_result['tracks'] ?? null ) ? $tracking_result['tracks'] : array() );
		$latest = is_array( $tracking_result['latest'] ?? null ) ? $tracking_result['latest'] : array();
		if ( empty( $latest ) ) {
			$latest = $tracks[0] ?? array();
		} else {
			$normalized_latest = $this->normalize_tracks( array( $latest ) );
			$latest            = $normalized_latest[0] ?? array();
		}

		$order->update_meta_data( self::META_LATEST_TRACK, $latest );
		$order->update_meta_data( self::META_TRACK_HISTORY, $tracks );
		$order->update_meta_data( '_myeasybuy_track_fetched_at', (string) ( $tracking_result['fetched_at'] ?? gmdate( 'c' ) ) );
		$order->save();

		if ( ! $auto_complete || empty( $latest ) || ! $this->is_delivered_track( $latest ) ) {
			return;
		}

		if ( 'completed' === $order->get_status() || $order->get_meta( '_myeasybuy_is_delivered', true ) ) {
			return;
		}

		$order->update_meta_data( '_myeasybuy_is_delivered', 'yes' );
		$order->update_meta_data( self::META_DELIVERED_AT, gmdate( 'c' ) );
		$order->save();

		$order->update_status(
			'completed',
			sprintf(
				/* translators: %s: latest tracking description */
				__( '4PX tracking indicates delivered: %s', 'myeasybuy-core' ),
				(string) ( $latest['description'] ?? $latest['track_code'] ?? '' )
			)
		);

		/**
		 * Fires when an international package is marked delivered via 4PX tracking.
		 *
		 * @since 1.4.0
		 *
		 * @param int                  $order_id     WooCommerce order ID.
		 * @param array<string, mixed> $latest_track Latest track payload.
		 */
		do_action( self::ACTION_ORDER_DELIVERED, $order_id, $latest );
	}

	/**
	 * Extract tracks array from various 4PX response shapes.
	 *
	 * @param array<string, mixed> $data Raw API response.
	 * @return array<int, array<string, mixed>>
	 */
	private function extract_tracks( array $data ): array {
		if ( isset( $data['data'] ) && is_string( $data['data'] ) ) {
			$decoded = json_decode( $data['data'], true );
			if ( is_array( $decoded ) ) {
				$data['data'] = $decoded;
			}
		}

		$inner = is_array( $data['data'] ?? null ) ? $data['data'] : array();

		$candidates = array(
			$inner['trackingList'] ?? null,
			$inner['tracks'] ?? null,
			$inner['tracking'] ?? null,
			$inner['trackList'] ?? null,
			$data['trackingList'] ?? null,
			$data['tracks'] ?? null,
			$inner,
			$data['data'] ?? null,
		);

		foreach ( $candidates as $candidate ) {
			if ( ! is_array( $candidate ) || empty( $candidate ) ) {
				continue;
			}

			if ( $this->is_track_node( $candidate ) ) {
				return array( $candidate );
			}

			if ( isset( $candidate[0] ) && is_array( $candidate[0] ) ) {
				return array_values( array_filter( $candidate, 'is_array' ) );
			}
		}

		return array();
	}

	/**
	 * Whether an array looks like a single tracking event.
	 *
	 * @param array<string, mixed> $candidate Candidate node.
	 */
	private function is_track_node( array $candidate ): bool {
		$keys = array(
			'tkCode',
			'track_code',
			'tkDesc',
			'trackingContent',
			'track_content',
			'occurDatetime',
			'businessLinkCode',
			'occurLocation',
			'tkLocation',
		);

		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $candidate ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalize track nodes (newest first) for storage and display.
	 *
	 * @param array<int, mixed> $tracks Raw or already-normalized tracks.
	 * @return array<int, array{track_code: string, description: string, occurred_at: string, location: string}>
	 */
	public function normalize_tracks( array $tracks ): array {
		$normalized = array();

		foreach ( $tracks as $track ) {
			if ( ! is_array( $track ) ) {
				continue;
			}

			$code = (string) ( $track['track_code'] ?? $track['tkCode'] ?? $track['businessLinkCode'] ?? $track['business_link_code'] ?? '' );
			$desc = (string) ( $track['description'] ?? $track['tkDesc'] ?? $track['trackingContent'] ?? $track['track_content'] ?? $track['tracking_content'] ?? $track['remark'] ?? '' );
			$when = (string) ( $track['occurred_at'] ?? $track['tkOccurDate'] ?? $track['occurDatetime'] ?? $track['occur_datetime'] ?? $track['occur_date'] ?? $track['track_date'] ?? $track['datetime'] ?? '' );
			$loc  = (string) ( $track['location'] ?? $track['tkLocation'] ?? $track['occurLocation'] ?? $track['occur_location'] ?? '' );

			if ( '' === $code && '' === $desc && '' === $when ) {
				continue;
			}

			$normalized[] = array(
				'track_code'  => $code,
				'description' => $desc,
				'occurred_at' => $when,
				'location'    => $loc,
			);
		}

		usort(
			$normalized,
			static function ( array $a, array $b ): int {
				return strcmp( (string) $b['occurred_at'], (string) $a['occurred_at'] );
			}
		);

		$unique = array();
		$seen   = array();
		foreach ( $normalized as $item ) {
			$key = md5( $item['occurred_at'] . '|' . $item['description'] . '|' . $item['location'] . '|' . $item['track_code'] );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$unique[]     = $item;
		}

		return $unique;
	}

	/**
	 * Whether a track entry indicates delivery.
	 *
	 * @param array<string, mixed> $track Normalized track.
	 */
	private function is_delivered_track( array $track ): bool {
		$code = strtoupper( (string) ( $track['track_code'] ?? '' ) );
		$desc = (string) ( $track['description'] ?? '' );

		$delivered_codes = array( 'P_DELIVERED', 'DELIVERED', 'GTMS_SIGNED', 'SIGNED' );

		/**
		 * Filter delivered track codes.
		 *
		 * @since 1.4.0
		 *
		 * @param array<int, string>   $delivered_codes Codes.
		 * @param array<string, mixed> $track           Track payload.
		 */
		$delivered_codes = apply_filters( 'myeasybuy_4px_delivered_codes', $delivered_codes, $track );

		if ( in_array( $code, array_map( 'strtoupper', (array) $delivered_codes ), true ) ) {
			return true;
		}

		if ( preg_match( '/delivered|签收|已签收|妥投/iu', $desc ) ) {
			return true;
		}

		/**
		 * Filter final delivered decision.
		 *
		 * @since 1.4.0
		 *
		 * @param bool                 $is_delivered Default decision.
		 * @param array<string, mixed> $track        Track payload.
		 */
		return (bool) apply_filters( 'myeasybuy_4px_is_delivered', false, $track );
	}

	/**
	 * Whether this carrier should be polled via 4PX.
	 *
	 * @param string $carrier         Carrier label from order meta.
	 * @param string $tracking_number Tracking / waybill number.
	 */
	private function should_track_carrier( string $carrier, string $tracking_number = '' ): bool {
		$carrier_l = strtolower( $carrier );
		$number_u  = strtoupper( ltrim( $tracking_number ) );
		$default   = ( '' === $carrier )
			|| false !== strpos( $carrier_l, '4px' )
			|| false !== strpos( $carrier, '递四方' )
			|| 0 === strpos( $number_u, '4PX' );

		/**
		 * Filter whether a carrier should use the 4PX tracking service.
		 *
		 * @since 1.4.0
		 *
		 * @param bool   $default          Default decision.
		 * @param string $carrier          Carrier name.
		 * @param string $tracking_number  Tracking / waybill number.
		 */
		return (bool) apply_filters( 'myeasybuy_4px_should_track_carrier', $default, $carrier, $tracking_number );
	}

	/**
	 * Whether App Key / Secret are configured.
	 */
	private function has_credentials(): bool {
		$settings = $this->get_settings();
		return '' !== trim( (string) $settings['4px_app_key'] ) && '' !== trim( (string) $settings['4px_app_secret'] );
	}

	/**
	 * Write to PHP error log when WP_DEBUG is on.
	 *
	 * @param string $message Log message.
	 */
	private function log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[MyEasyBuy 4PX] ' . $message );
		}
	}
}
