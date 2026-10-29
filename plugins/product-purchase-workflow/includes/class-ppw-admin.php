<?php
/**
 * Admin settings for OneBound API and pricing (Taobao + JD).
 *
 * @package Product_Purchase_Workflow
 */

defined( 'ABSPATH' ) || exit;

class PPW_Admin {

	/**
	 * Register admin hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	/**
	 * Add settings page under Settings menu.
	 */
	public static function add_menu() {
		add_options_page(
			__( '商品搜索设置', 'product-purchase-workflow' ),
			__( '商品搜索设置', 'product-purchase-workflow' ),
			'manage_options',
			'ppw-settings',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Register plugin settings.
	 */
	public static function register_settings() {
		register_setting(
			'ppw_settings_group',
			'ppw_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => self::defaults(),
			)
		);

		add_settings_section(
			'ppw_general_section',
			__( '通用', 'product-purchase-workflow' ),
			static function () {
				echo '<p>' . esc_html__( '首页默认展示淘宝商品；可通过界面按钮切换到京东。搜索框支持关键词、图片搜索（淘宝/京东 item_search_img）或粘贴商品链接。', 'product-purchase-workflow' ) . '</p>';
			},
			'ppw-settings'
		);

		add_settings_field(
			'ppw_default_platform',
			__( '默认平台', 'product-purchase-workflow' ),
			array( __CLASS__, 'render_field' ),
			'ppw-settings',
			'ppw_general_section',
			array( 'field_id' => 'default_platform' )
		);

		add_settings_field(
			'ppw_page_size',
			__( '每次展示商品数量', 'product-purchase-workflow' ),
			array( __CLASS__, 'render_field' ),
			'ppw-settings',
			'ppw_general_section',
			array( 'field_id' => 'page_size' )
		);

		add_settings_section(
			'ppw_tb_api_section',
			__( '淘宝商品搜索 (item_search)', 'product-purchase-workflow' ),
			static function () {
				echo '<p>' . esc_html__( 'OneBound 淘宝关键字搜索。默认 URL：', 'product-purchase-workflow' );
				echo ' <code>https://api-gw.onebound.cn/taobao/item_search</code></p>';
			},
			'ppw-settings'
		);

		foreach ( array(
			'tb_api_key'    => __( '淘宝 API Key', 'product-purchase-workflow' ),
			'tb_api_secret' => __( '淘宝 API Secret', 'product-purchase-workflow' ),
			'tb_api_url'    => __( '淘宝搜索 API URL', 'product-purchase-workflow' ),
		) as $field_id => $label ) {
			add_settings_field(
				'ppw_' . $field_id,
				$label,
				array( __CLASS__, 'render_field' ),
				'ppw-settings',
				'ppw_tb_api_section',
				array( 'field_id' => $field_id )
			);
		}

		add_settings_section(
			'ppw_tb_item_get_section',
			__( '淘宝商品详情 (item_get_pro)', 'product-purchase-workflow' ),
			static function () {
				echo '<p>' . esc_html__( '默认 URL：', 'product-purchase-workflow' );
				echo ' <code>https://api-gw.onebound.cn/taobao/item_get_pro</code>。';
				echo esc_html__( ' Key/Secret 留空则回退使用上方淘宝搜索凭证（或共用京东 Key）。', 'product-purchase-workflow' ) . '</p>';
			},
			'ppw-settings'
		);

		foreach ( array(
			'tb_item_get_api_key'    => __( '淘宝 item_get API Key', 'product-purchase-workflow' ),
			'tb_item_get_api_secret' => __( '淘宝 item_get API Secret', 'product-purchase-workflow' ),
			'tb_item_get_api_url'    => __( '淘宝 item_get API URL', 'product-purchase-workflow' ),
		) as $field_id => $label ) {
			add_settings_field(
				'ppw_' . $field_id,
				$label,
				array( __CLASS__, 'render_field' ),
				'ppw-settings',
				'ppw_tb_item_get_section',
				array( 'field_id' => $field_id )
			);
		}

		add_settings_section(
			'ppw_api_section',
			__( '京东商品搜索 (item_search)', 'product-purchase-workflow' ),
			static function () {
				echo '<p>' . esc_html__( 'OneBound 京东关键字搜索。默认 URL：', 'product-purchase-workflow' );
				echo ' <code>https://api-gw.onebound.cn/jd/item_search</code></p>';
			},
			'ppw-settings'
		);

		foreach ( array(
			'api_key'    => __( '京东 API Key', 'product-purchase-workflow' ),
			'api_secret' => __( '京东 API Secret', 'product-purchase-workflow' ),
			'api_url'    => __( '京东搜索 API URL', 'product-purchase-workflow' ),
		) as $field_id => $label ) {
			add_settings_field(
				'ppw_' . $field_id,
				$label,
				array( __CLASS__, 'render_field' ),
				'ppw-settings',
				'ppw_api_section',
				array( 'field_id' => $field_id )
			);
		}

		add_settings_section(
			'ppw_item_get_section',
			__( '京东商品详情 (item_get)', 'product-purchase-workflow' ),
			static function () {
				echo '<p>' . esc_html__( 'Key/Secret 留空则回退使用上方京东搜索凭证。', 'product-purchase-workflow' ) . '</p>';
			},
			'ppw-settings'
		);

		foreach ( array(
			'item_get_api_key'    => __( '京东 item_get API Key', 'product-purchase-workflow' ),
			'item_get_api_secret' => __( '京东 item_get API Secret', 'product-purchase-workflow' ),
			'item_get_api_url'    => __( '京东 item_get API URL', 'product-purchase-workflow' ),
		) as $field_id => $label ) {
			add_settings_field(
				'ppw_' . $field_id,
				$label,
				array( __CLASS__, 'render_field' ),
				'ppw-settings',
				'ppw_item_get_section',
				array( 'field_id' => $field_id )
			);
		}

		add_settings_section(
			'ppw_wc_section',
			__( 'WooCommerce 自动上架', 'product-purchase-workflow' ),
			static function () {
				echo '<p>' . esc_html__( '若商店中不存在该商品，将自动创建 WooCommerce 商品并上架（价格使用 CAD 定价引擎计算）。', 'product-purchase-workflow' ) . '</p>';
			},
			'ppw-settings'
		);

		add_settings_field(
			'ppw_auto_publish_wc',
			__( '自动上架', 'product-purchase-workflow' ),
			array( __CLASS__, 'render_field' ),
			'ppw-settings',
			'ppw_wc_section',
			array( 'field_id' => 'auto_publish_wc' )
		);

		add_settings_section(
			'ppw_pricing_section',
			__( 'CAD 定价引擎', 'product-purchase-workflow' ),
			static function () {
				echo '<p>' . esc_html__( '定价公式：CAD 价格 = 人民币价格 × 汇率 × (1 + 加价比例)', 'product-purchase-workflow' ) . '</p>';
			},
			'ppw-settings'
		);

		foreach ( array(
			'base_rate' => __( '汇率 (RMB → CAD)', 'product-purchase-workflow' ),
			'margin'    => __( '平台加价比例', 'product-purchase-workflow' ),
		) as $field_id => $label ) {
			add_settings_field(
				'ppw_' . $field_id,
				$label,
				array( __CLASS__, 'render_field' ),
				'ppw-settings',
				'ppw_pricing_section',
				array( 'field_id' => $field_id )
			);
		}

		add_settings_section(
			'ppw_dev_section',
			__( '开发 / 调试', 'product-purchase-workflow' ),
			static function () {
				echo '<p>' . esc_html__( 'API 不可用时可用演示数据测试 UI。正式环境请关闭。', 'product-purchase-workflow' ) . '</p>';
			},
			'ppw-settings'
		);

		add_settings_field(
			'ppw_demo_mode',
			__( '演示模式', 'product-purchase-workflow' ),
			array( __CLASS__, 'render_field' ),
			'ppw-settings',
			'ppw_dev_section',
			array( 'field_id' => 'demo_mode' )
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function defaults() {
		return ppw_default_settings();
	}

	/**
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed>
	 */
	public static function sanitize_settings( $input ) {
		$input = is_array( $input ) ? $input : array();

		return array(
			'api_key'                => sanitize_text_field( (string) ( $input['api_key'] ?? '' ) ),
			'api_secret'             => sanitize_text_field( (string) ( $input['api_secret'] ?? '' ) ),
			'api_url'                => esc_url_raw( (string) ( $input['api_url'] ?? 'https://api-gw.onebound.cn/jd/item_search' ) ),
			'item_get_api_key'       => sanitize_text_field( (string) ( $input['item_get_api_key'] ?? '' ) ),
			'item_get_api_secret'    => sanitize_text_field( (string) ( $input['item_get_api_secret'] ?? '' ) ),
			'item_get_api_url'       => esc_url_raw( (string) ( $input['item_get_api_url'] ?? 'https://api-gw.onebound.cn/jd/item_get' ) ),
			'tb_api_key'             => sanitize_text_field( (string) ( $input['tb_api_key'] ?? '' ) ),
			'tb_api_secret'          => sanitize_text_field( (string) ( $input['tb_api_secret'] ?? '' ) ),
			'tb_api_url'             => esc_url_raw( (string) ( $input['tb_api_url'] ?? 'https://api-gw.onebound.cn/taobao/item_search' ) ),
			'tb_item_get_api_key'    => sanitize_text_field( (string) ( $input['tb_item_get_api_key'] ?? '' ) ),
			'tb_item_get_api_secret' => sanitize_text_field( (string) ( $input['tb_item_get_api_secret'] ?? '' ) ),
			'tb_item_get_api_url'    => esc_url_raw( (string) ( $input['tb_item_get_api_url'] ?? 'https://api-gw.onebound.cn/taobao/item_get_pro' ) ),
			'auto_publish_wc'        => ! empty( $input['auto_publish_wc'] ) ? 1 : 0,
			'base_rate'              => max( 0.0001, (float) ( $input['base_rate'] ?? 0.193 ) ),
			'margin'                 => max( 0, (float) ( $input['margin'] ?? 0.03 ) ),
			'page_size'              => min( 20, max( 1, (int) ( $input['page_size'] ?? 20 ) ) ),
			'demo_mode'              => ! empty( $input['demo_mode'] ) ? 1 : 0,
			'default_platform'       => PPW_Platform::normalize( (string) ( $input['default_platform'] ?? 'taobao' ) ),
		);
	}

	/**
	 * @param array<string, mixed> $args Field args.
	 */
	public static function render_field( $args ) {
		$field_id = (string) ( $args['field_id'] ?? '' );
		$settings = get_option( 'ppw_settings', self::defaults() );
		$value    = $settings[ $field_id ] ?? '';

		if ( 'default_platform' === $field_id ) {
			printf(
				'<select name="ppw_settings[%1$s]" id="ppw_%1$s">
					<option value="taobao" %2$s>%3$s</option>
					<option value="jd" %4$s>%5$s</option>
				</select>',
				esc_attr( $field_id ),
				selected( 'taobao', (string) $value, false ),
				esc_html__( '淘宝（默认）', 'product-purchase-workflow' ),
				selected( 'jd', (string) $value, false ),
				esc_html__( '京东', 'product-purchase-workflow' )
			);
			return;
		}

		if ( 'page_size' === $field_id ) {
			printf(
				'<input type="number" name="ppw_settings[%1$s]" id="ppw_%1$s" value="%2$s" min="1" max="20" class="small-text" />',
				esc_attr( $field_id ),
				esc_attr( (string) $value )
			);
			echo '<p class="description">' . esc_html__( '首页搜索每页最多 20 个商品。', 'product-purchase-workflow' ) . '</p>';
			return;
		}

		if ( 'base_rate' === $field_id ) {
			printf(
				'<input type="number" step="0.0001" name="ppw_settings[%1$s]" id="ppw_%1$s" value="%2$s" class="small-text" />',
				esc_attr( $field_id ),
				esc_attr( (string) $value )
			);
			return;
		}

		if ( 'margin' === $field_id ) {
			printf(
				'<input type="number" step="0.01" name="ppw_settings[%1$s]" id="ppw_%1$s" value="%2$s" class="small-text" />',
				esc_attr( $field_id ),
				esc_attr( (string) $value )
			);
			return;
		}

		if ( 'demo_mode' === $field_id || 'auto_publish_wc' === $field_id ) {
			$label = ( 'demo_mode' === $field_id )
				? __( 'API 失败时使用演示商品数据', 'product-purchase-workflow' )
				: __( '商店无此商品时自动创建并上架', 'product-purchase-workflow' );
			printf(
				'<label><input type="checkbox" name="ppw_settings[%1$s]" id="ppw_%1$s" value="1" %2$s /> %3$s</label>',
				esc_attr( $field_id ),
				checked( 1, (int) $value, false ),
				esc_html( $label )
			);
			return;
		}

		$url_fields = array( 'api_url', 'item_get_api_url', 'tb_api_url', 'tb_item_get_api_url' );
		if ( in_array( $field_id, $url_fields, true ) ) {
			printf(
				'<input type="url" name="ppw_settings[%1$s]" id="ppw_%1$s" value="%2$s" class="large-text" />',
				esc_attr( $field_id ),
				esc_attr( (string) $value )
			);
			return;
		}

		$secret_fields = array( 'api_secret', 'item_get_api_secret', 'tb_api_secret', 'tb_item_get_api_secret' );
		$type          = in_array( $field_id, $secret_fields, true ) ? 'password' : 'text';

		printf(
			'<input type="%1$s" name="ppw_settings[%2$s]" id="ppw_%2$s" value="%3$s" class="regular-text" autocomplete="off" />',
			esc_attr( $type ),
			esc_attr( $field_id ),
			esc_attr( (string) $value )
		);

		if ( in_array( $field_id, array( 'tb_api_key', 'tb_api_secret' ), true ) ) {
			echo '<p class="description">' . esc_html__( '可留空：将回退使用下方京东 API Key / Secret（同一 OneBound 账号）。', 'product-purchase-workflow' ) . '</p>';
		}
	}

	/**
	 * Render settings page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( '商品搜索设置', 'product-purchase-workflow' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'ppw_settings_group' );
				do_settings_sections( 'ppw-settings' );
				submit_button();
				?>
			</form>
			<hr />
			<h2><?php esc_html_e( '最近一次图片搜索', 'product-purchase-workflow' ); ?></h2>
			<?php
			$last = class_exists( 'PPW_Link_Parser_Agent' ) ? PPW_Link_Parser_Agent::get_last_img_search() : array();
			if ( empty( $last ) ) :
				?>
				<p><?php esc_html_e( '尚无记录。请在首页上传商品图片搜索后再回来查看。', 'product-purchase-workflow' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:900px">
					<tbody>
						<?php foreach ( $last as $k => $v ) : ?>
							<tr>
								<th style="width:140px"><?php echo esc_html( (string) $k ); ?></th>
								<td><code><?php echo esc_html( is_scalar( $v ) ? (string) $v : wp_json_encode( $v, JSON_UNESCAPED_UNICODE ) ); ?></code></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<hr />
			<h2><?php esc_html_e( '使用说明', 'product-purchase-workflow' ); ?></h2>
			<ol>
				<li><?php esc_html_e( '在 OneBound 注册并获取 API Key / Secret。', 'product-purchase-workflow' ); ?></li>
				<li><?php esc_html_e( '填写淘宝 / 京东配置并保存（API URL 保持默认即可）。', 'product-purchase-workflow' ); ?></li>
				<li><?php esc_html_e( '图片搜索：淘宝支持本地上传（upload_img + item_search_img）。京东官方接口主要接受 360buyimg / jfs 图片地址；本地图会先淘宝识图再按关键词搜京东。', 'product-purchase-workflow' ); ?></li>
				<li><?php esc_html_e( '激活「Product Purchase Workflow」插件。', 'product-purchase-workflow' ); ?></li>
				<li><?php esc_html_e( '访问首页默认搜索淘宝商品；点击「京东」可切换平台；也可使用「图片搜索」。', 'product-purchase-workflow' ); ?></li>
				<li><?php esc_html_e( '点击商品查看详情；若 WooCommerce 中无该商品会自动上架。', 'product-purchase-workflow' ); ?></li>
			</ol>
		</div>
		<?php
	}
}
