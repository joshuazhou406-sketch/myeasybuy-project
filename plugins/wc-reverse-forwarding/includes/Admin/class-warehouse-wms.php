<?php
/**
 * Admin WMS barcode inbound workbench.
 *
 * @package ReverseForwarding
 */

namespace ReverseForwarding\Admin;

use ReverseForwarding\Frontend\My_Account;
use ReverseForwarding\Models\Package;
use ReverseForwarding\Shipping\Four_Px;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * 集运仓入库台: scan, weigh, measure, photo, shelf.
 */
class Warehouse_Wms {

	public const PAGE_SLUG   = 'wc-rf-wms';
	public const REST_NS     = 'wc-rf/v1';
	public const AJAX_LOOKUP = 'wc_rf_wms_lookup';
	public const AJAX_RECEIVE = 'wc_rf_wms_receive';
	public const NONCE_ACTION = 'wc_rf_wms';

	/**
	 * Register admin + API hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest' ) );
		add_action( 'wp_ajax_' . self::AJAX_LOOKUP, array( __CLASS__, 'ajax_lookup' ) );
		add_action( 'wp_ajax_' . self::AJAX_RECEIVE, array( __CLASS__, 'ajax_receive' ) );
	}

	/**
	 * Whether the current user may use this workbench.
	 */
	public static function current_user_can(): bool {
		return Package::warehouse_can();
	}

	/**
	 * Standalone admin menu.
	 */
	public static function register_menu(): void {
		$cap = current_user_can( 'manage_woocommerce' )
			? 'manage_woocommerce'
			: ( current_user_can( 'myeasybuy_manage_warehouse' ) ? 'myeasybuy_manage_warehouse' : 'manage_woocommerce' );

		add_menu_page(
			__( '集运仓入库台', 'wc-rf' ),
			__( '集运仓入库台', 'wc-rf' ),
			$cap,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' ),
			'dashicons-clipboard',
			56
		);

		add_submenu_page(
			self::PAGE_SLUG,
			__( '入库扫码', 'wc-rf' ),
			__( '入库扫码', 'wc-rf' ),
			$cap,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);

		if ( class_exists( 'MyEasyBuy_Admin_Workflow' ) ) {
			add_submenu_page(
				self::PAGE_SLUG,
				__( '4PX 国际发货', 'wc-rf' ),
				__( '4PX 国际发货', 'wc-rf' ),
				$cap,
				'wc-rf-4px-shipping',
				array( Four_Px::class, 'redirect_int_shipping' )
			);
		}

		if ( current_user_can( 'manage_woocommerce' ) ) {
			add_submenu_page(
				self::PAGE_SLUG,
				__( '4PX 物流对接', 'wc-rf' ),
				__( '4PX 物流对接', 'wc-rf' ),
				'manage_woocommerce',
				'wc-rf-4px-settings',
				array( Four_Px::class, 'redirect_settings' )
			);
		}
	}

	/**
	 * REST lookup + receive.
	 */
	public static function register_rest(): void {
		register_rest_route(
			self::REST_NS,
			'/wms/lookup',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'current_user_can' ),
				'callback'            => array( __CLASS__, 'rest_lookup' ),
			)
		);

		register_rest_route(
			self::REST_NS,
			'/wms/receive',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'current_user_can' ),
				'callback'            => array( __CLASS__, 'rest_receive' ),
			)
		);
	}

	/**
	 * Assets on this screen only.
	 *
	 * @param string $hook Current admin hook.
	 */
	public static function enqueue_assets( string $hook ): void {
		if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'wc-rf-wms',
			plugins_url( 'assets/css/wms-scanner.css', WC_RF_PLUGIN_FILE ),
			array(),
			WC_RF_VERSION
		);

		wp_enqueue_script(
			'wc-rf-wms',
			plugins_url( 'assets/js/wms-scanner.js', WC_RF_PLUGIN_FILE ),
			array(),
			WC_RF_VERSION,
			true
		);

		wp_localize_script(
			'wc-rf-wms',
			'wcRfWms',
			array(
				'restLookup'  => esc_url_raw( rest_url( self::REST_NS . '/wms/lookup' ) ),
				'restReceive' => esc_url_raw( rest_url( self::REST_NS . '/wms/receive' ) ),
				'restNonce'   => wp_create_nonce( 'wp_rest' ),
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'ajaxNonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'i18n'        => array(
					'needTracking' => __( '请先扫描快递单号。', 'wc-rf' ),
					'needWeight'   => __( '请填写实测重量。', 'wc-rf' ),
					'lookupFail'   => __( '查询失败，请重试。', 'wc-rf' ),
					'receiveFail'  => __( '入库失败，请重试。', 'wc-rf' ),
					'found'        => __( '已匹配预报包裹', 'wc-rf' ),
					'unclaimed'    => __( '未预报，可登记为无主认领件', 'wc-rf' ),
					'stored'       => __( '入库成功', 'wc-rf' ),
					'locked'       => __( '该包裹已进入合箱/转运，不能再入库。', 'wc-rf' ),
				),
			)
		);
	}

	/**
	 * Workbench HTML.
	 */
	public static function render_page(): void {
		if ( ! self::current_user_can() ) {
			wp_die( esc_html__( '您没有权限访问集运仓入库台。', 'wc-rf' ) );
		}

		$recent = Package::recent_stored( 10 );
		include WC_RF_PLUGIN_DIR . 'templates/admin/wms-workbench.php';
	}

	/**
	 * REST: lookup by tracking.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function rest_lookup( WP_REST_Request $request ): WP_REST_Response {
		$tracking = Package::normalize_tracking( (string) $request->get_param( 'tracking_number' ) );
		return new WP_REST_Response( self::lookup_payload( $tracking ), 200 );
	}

	/**
	 * REST: receive inbound (supports multipart photos).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function rest_receive( WP_REST_Request $request ) {
		$result = self::receive_from_request(
			array(
				'tracking_number'  => (string) $request->get_param( 'tracking_number' ),
				'weight'           => $request->get_param( 'weight' ),
				'length'           => $request->get_param( 'length' ),
				'width'            => $request->get_param( 'width' ),
				'height'           => $request->get_param( 'height' ),
				'shelf_location'   => (string) $request->get_param( 'shelf_location' ),
				'declared_name'    => (string) $request->get_param( 'declared_name' ),
				'express_company'  => (string) $request->get_param( 'express_company' ),
				'allow_unclaimed'  => (bool) $request->get_param( 'allow_unclaimed' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'package' => self::present_package( $result ),
			),
			200
		);
	}

	/**
	 * admin-ajax lookup fallback.
	 */
	public static function ajax_lookup(): void {
		self::guard_ajax();
		$tracking = Package::normalize_tracking( (string) wp_unslash( $_POST['tracking_number'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		wp_send_json( self::lookup_payload( $tracking ) );
	}

	/**
	 * admin-ajax receive fallback.
	 */
	public static function ajax_receive(): void {
		self::guard_ajax();

		$result = self::receive_from_request(
			array(
				'tracking_number' => (string) wp_unslash( $_POST['tracking_number'] ?? '' ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'weight'          => wp_unslash( $_POST['weight'] ?? 0 ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'length'          => wp_unslash( $_POST['length'] ?? 0 ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'width'           => wp_unslash( $_POST['width'] ?? 0 ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'height'          => wp_unslash( $_POST['height'] ?? 0 ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'shelf_location'  => (string) wp_unslash( $_POST['shelf_location'] ?? '' ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'declared_name'   => (string) wp_unslash( $_POST['declared_name'] ?? '' ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'express_company' => (string) wp_unslash( $_POST['express_company'] ?? '' ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'allow_unclaimed' => ! empty( $_POST['allow_unclaimed'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			)
		);

		if ( is_wp_error( $result ) ) {
			$error_data = $result->get_error_data();
			wp_send_json_error(
				array(
					'message'   => $result->get_error_message(),
					'unclaimed' => is_array( $error_data ) && ! empty( $error_data['unclaimed'] ),
				),
				400
			);
		}

		wp_send_json_success( array( 'package' => self::present_package( $result ) ) );
	}

	/**
	 * Shared lookup payload.
	 *
	 * @return array<string, mixed>
	 */
	public static function lookup_payload( string $tracking ): array {
		if ( '' === $tracking ) {
			return array(
				'found'   => false,
				'message' => __( '请扫描快递单号。', 'wc-rf' ),
			);
		}

		$row = Package::find_by_tracking( $tracking );
		if ( ! $row ) {
			return array(
				'found'     => false,
				'unclaimed' => true,
				'tracking'  => $tracking,
				'message'   => __( '未找到预报记录，可登记为无主认领件。', 'wc-rf' ),
			);
		}

		return array(
			'found'   => true,
			'locked'  => in_array( (string) $row['status'], array( Package::STATUS_APPLIED, Package::STATUS_CONSOLIDATING, Package::STATUS_IN_TRANSIT ), true ),
			'package' => self::present_package( $row ),
		);
	}

	/**
	 * Buyer-facing card + warehouse extras.
	 *
	 * @param array<string, mixed> $row Package.
	 * @return array<string, mixed>
	 */
	public static function present_package( array $row ): array {
		$user_id = (int) $row['user_id'];
		$user    = $user_id > 0 ? get_userdata( $user_id ) : false;
		$code    = $user_id > 0 ? My_Account::ensure_warehouse_code( $user_id ) : '';

		return array(
			'id'               => (int) $row['id'],
			'tracking_number'  => (string) $row['tracking_number'],
			'express_company'  => (string) $row['express_company'],
			'declared_name'    => (string) $row['declared_name'],
			'status'           => (string) $row['status'],
			'weight'           => (float) $row['weight'],
			'length'           => (float) $row['length'],
			'width'            => (float) $row['width'],
			'height'           => (float) $row['height'],
			'shelf_location'   => (string) $row['shelf_location'],
			'user_id'          => $user_id,
			'warehouse_code'   => $code,
			'user_label'       => $user ? $user->display_name : __( '无主认领件', 'wc-rf' ),
			'user_email'       => $user ? $user->user_email : '',
			'unclaimed'        => 0 === $user_id,
			'photo_ids'        => Package::photo_ids( $row ),
		);
	}

	/**
	 * @param array<string, mixed> $input Posted fields.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function receive_from_request( array $input ) {
		$input['photo_ids'] = self::handle_photo_uploads();
		return Package::receive_from_warehouse( $input );
	}

	/**
	 * @return array<int, int>
	 */
	private static function handle_photo_uploads(): array {
		if ( empty( $_FILES['photos'] ) || ! is_array( $_FILES['photos'] ) ) {
			return array();
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$files = $_FILES['photos'];
		$ids   = array();

		if ( ! is_array( $files['name'] ) ) {
			$id = media_handle_upload( 'photos', 0 );
			if ( ! is_wp_error( $id ) ) {
				$ids[] = (int) $id;
			}
			return $ids;
		}

		$count = count( $files['name'] );
		for ( $i = 0; $i < $count && $i < 8; $i++ ) {
			if ( empty( $files['name'][ $i ] ) || (int) $files['error'][ $i ] !== UPLOAD_ERR_OK ) {
				continue;
			}

			$_FILES['wc_rf_photo_single'] = array(
				'name'     => $files['name'][ $i ],
				'type'     => $files['type'][ $i ],
				'tmp_name' => $files['tmp_name'][ $i ],
				'error'    => $files['error'][ $i ],
				'size'     => $files['size'][ $i ],
			);

			$id = media_handle_upload( 'wc_rf_photo_single', 0 );
			if ( ! is_wp_error( $id ) ) {
				$ids[] = (int) $id;
			}
		}

		unset( $_FILES['wc_rf_photo_single'] );

		return $ids;
	}

	private static function guard_ajax(): void {
		if ( ! self::current_user_can() ) {
			wp_send_json_error( array( 'message' => __( '无权操作入库台。', 'wc-rf' ) ), 403 );
		}

		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
	}
}
