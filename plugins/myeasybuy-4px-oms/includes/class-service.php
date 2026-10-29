<?php
/**
 * OMS consolidation business service.
 *
 * @package MyEasyBuy_OMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Maps reverse-shopping / forwarding objects to 一段 / 二段 OMS orders.
 */
class MyEasyBuy_OMS_Service {

	public const OPTION_KEY = 'myeasybuy_oms_settings';

	public const SOURCE_WC = 'wc_order';
	public const SOURCE_RF = 'rf_package';

	public const META_LEG1_HAWB = '_myeasybuy_oms_leg1_hawb';
	public const META_LEG1_FPX  = '_myeasybuy_oms_leg1_fpx';
	public const META_LEG2_HAWB = '_myeasybuy_oms_leg2_hawb';
	public const META_LEG2_FPX  = '_myeasybuy_oms_leg2_fpx';
	public const META_STATUS    = '_myeasybuy_oms_status';
	public const META_PHOTOS    = '_myeasybuy_oms_photos';

	/**
	 * @var MyEasyBuy_OMS_Service|null
	 */
	private static ?MyEasyBuy_OMS_Service $instance = null;

	/**
	 * @var MyEasyBuy_OMS_Client
	 */
	private MyEasyBuy_OMS_Client $client;

	/**
	 * Singleton.
	 */
	public static function instance(): MyEasyBuy_OMS_Service {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->client = new MyEasyBuy_OMS_Client();
	}

	/**
	 * API client.
	 */
	public function client(): MyEasyBuy_OMS_Client {
		return $this->client;
	}

	/**
	 * Default settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function default_settings(): array {
		return array(
			'enabled'                 => 1,
			'use_shared_credentials'  => 1,
			'sandbox_mode'            => 1,
			'transport'               => 'path',
			'api_base_url'            => '',
			'api_version'             => '1.0.0',
			'app_key'                 => '',
			'app_secret'              => '',
			'access_token'            => '',
			'method_create'           => 'api.standard.order.create',
			'method_update'           => 'api.standard.order.update',
			'method_cancel'           => 'api.standard.order.cancel',
			'method_shelf_return'     => 'api.standard.order.shelfReturn',
			'sale_pk_code'            => 'A65',
			'sale_pk_code_sea'        => '',
			'parcel_source'           => 'FLASH',
			'business_type'           => 'JYS',
			'fulfill_mode'            => 'SELLER_DROP_OFF',
			'country_code'            => 'CA',
			'auto_leg1'               => 1,
			'auto_leg2'               => 1,
			'leg2_on_request'         => 0,
			'callback_token'          => '',
			'default_return_type'     => 'EXPRESS_RETURN',
			'return_name'             => '',
			'return_phone'            => '',
			'return_address'          => '',
		);
	}

	/**
	 * Merged settings.
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
	 * Whether OMS calls should run.
	 */
	public function is_enabled(): bool {
		$settings = $this->get_settings();
		return ! empty( $settings['enabled'] );
	}

	/**
	 * Public callback URL for OMS configuration.
	 */
	public function callback_url(): string {
		$url      = rest_url( 'myeasybuy-oms/v1/callback' );
		$settings = $this->get_settings();
		$token    = trim( (string) ( $settings['callback_token'] ?? '' ) );
		if ( '' !== $token ) {
			$url = add_query_arg( 'token', $token, $url );
		}

		return $url;
	}

	/**
	 * Status labels.
	 *
	 * @return array<string, string>
	 */
	public static function status_labels(): array {
		return array(
			'P' => __( '已预报', 'meb-oms' ),
			'V' => __( '已揽收', 'meb-oms' ),
			'N' => __( '已签收', 'meb-oms' ),
			'H' => __( '已入库', 'meb-oms' ),
			'S' => __( '已上架', 'meb-oms' ),
			'X' => __( '已合箱', 'meb-oms' ),
			'C' => __( '已出库', 'meb-oms' ),
			'T' => __( '已退件', 'meb-oms' ),
			'R' => __( '已拒收', 'meb-oms' ),
			'E' => __( '异常', 'meb-oms' ),
		);
	}

	/**
	 * Pipeline steps from the alignment document.
	 *
	 * @return array<int, array{key:string,label:string,dir:string,api:string}>
	 */
	public static function pipeline_steps(): array {
		return array(
			array( 'key' => 'leg1_create', 'label' => __( '一段包裹预报', 'meb-oms' ), 'dir' => 'out', 'api' => 'POST /api/standard/order/create' ),
			array( 'key' => 'leg1_update', 'label' => __( '一段预报更新', 'meb-oms' ), 'dir' => 'out', 'api' => 'POST /api/standard/order/update' ),
			array( 'key' => 'no_forecast', 'label' => __( '到仓无预报拉单', 'meb-oms' ), 'dir' => 'in', 'api' => __( '客户回调 URL', 'meb-oms' ) ),
			array( 'key' => 'sign_in', 'label' => __( '签收 / 拒收', 'meb-oms' ), 'dir' => 'in', 'api' => 'CW_SIGN_IN' ),
			array( 'key' => 'inbound', 'label' => __( '安检入库', 'meb-oms' ), 'dir' => 'in', 'api' => 'CW_INBOUND' ),
			array( 'key' => 'upshelf', 'label' => __( '上架', 'meb-oms' ), 'dir' => 'in', 'api' => 'CW_UPSHELF' ),
			array( 'key' => 'leg2_create', 'label' => __( '合包 / 二段出库通知', 'meb-oms' ), 'dir' => 'out', 'api' => 'CONSOLIDATED_2' ),
			array( 'key' => 'picking', 'label' => __( '拣货播种合箱', 'meb-oms' ), 'dir' => 'wh', 'api' => __( '仓内作业', 'meb-oms' ) ),
			array( 'key' => 'consoed', 'label' => __( '合箱完成', 'meb-oms' ), 'dir' => 'in', 'api' => 'CW_CONSOED' ),
			array( 'key' => 'outbound', 'label' => __( '大包出库交接', 'meb-oms' ), 'dir' => 'in', 'api' => 'OUTBOUND' ),
		);
	}

	/**
	 * Create or reuse 一段预报 for a WooCommerce order.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function forecast_wc_order( int $order_id, bool $force = false ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'meb_oms_order', __( '订单不存在。', 'meb-oms' ) );
		}

		if ( class_exists( 'MyEasyBuy_Consolidation' )
			&& MyEasyBuy_Consolidation::PAYMENT_TYPE_SECOND === $order->get_meta( MyEasyBuy_Consolidation::META_PAYMENT_TYPE, true )
		) {
			return new WP_Error( 'meb_oms_fee_order', __( '运费账单不能做一段预报。', 'meb-oms' ) );
		}

		$existing = MyEasyBuy_OMS_Repository::get_by_source( self::SOURCE_WC, $order_id, 1 );
		if ( $existing && ! $force ) {
			return $existing;
		}

		$payload = $this->build_leg1_payload_from_wc( $order, $existing );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		return $this->submit_leg1( $payload, self::SOURCE_WC, $order_id, $existing );
	}

	/**
	 * Create or reuse 一段预报 for an RF package.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function forecast_rf_package( int $package_id, bool $force = false ) {
		$package = $this->get_rf_package( $package_id );
		if ( ! $package ) {
			return new WP_Error( 'meb_oms_package', __( '转运包裹不存在。', 'meb-oms' ) );
		}

		$existing = MyEasyBuy_OMS_Repository::get_by_source( self::SOURCE_RF, $package_id, 1 );
		if ( $existing && ! $force ) {
			return $existing;
		}

		$payload = $this->build_leg1_payload_from_rf( $package, $existing );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		return $this->submit_leg1( $payload, self::SOURCE_RF, $package_id, $existing );
	}

	/**
	 * Update 一段预报 (requires fpxTrackNo).
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function update_leg1( int $oms_id ) {
		$row = MyEasyBuy_OMS_Repository::get( $oms_id );
		if ( ! $row || 1 !== (int) $row['leg'] ) {
			return new WP_Error( 'meb_oms_missing', __( '一段单不存在。', 'meb-oms' ) );
		}
		if ( empty( $row['fpx_track_no'] ) ) {
			return new WP_Error( 'meb_oms_fpx', __( '缺少 fpxTrackNo，无法更新。', 'meb-oms' ) );
		}

		if ( self::SOURCE_WC === $row['source_type'] ) {
			$order = wc_get_order( (int) $row['source_id'] );
			if ( ! $order ) {
				return new WP_Error( 'meb_oms_order', __( '订单不存在。', 'meb-oms' ) );
			}
			$payload = $this->build_leg1_payload_from_wc( $order, $row );
		} else {
			$package = $this->get_rf_package( (int) $row['source_id'] );
			if ( ! $package ) {
				return new WP_Error( 'meb_oms_package', __( '转运包裹不存在。', 'meb-oms' ) );
			}
			$payload = $this->build_leg1_payload_from_rf( $package, $row );
		}

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$payload['order']['fpxTrackNo'] = $row['fpx_track_no'];
		$response = $this->client->update_order( $payload );
		$this->persist_api_result( (int) $row['id'], $payload, $response );

		return is_wp_error( $response ) ? $response : MyEasyBuy_OMS_Repository::get( (int) $row['id'] );
	}

	/**
	 * Cancel 一段 while still P.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function cancel_leg1( int $oms_id, string $return_type = '' ) {
		$row = MyEasyBuy_OMS_Repository::get( $oms_id );
		if ( ! $row || 1 !== (int) $row['leg'] ) {
			return new WP_Error( 'meb_oms_missing', __( '一段单不存在。', 'meb-oms' ) );
		}

		if ( 'P' !== (string) $row['oms_status'] ) {
			return new WP_Error( 'meb_oms_status', __( '仅已预报(P)可用取消接口，上架后请走在架退件。', 'meb-oms' ) );
		}

		$settings = $this->get_settings();
		$return_type = $return_type ?: (string) $settings['default_return_type'];
		$body        = array(
			'fpxTrackNo'        => $row['fpx_track_no'],
			'customerHawbCode'  => $row['customer_hawb_code'],
			'returnType'        => $return_type,
		);
		if ( 'EXPRESS_RETURN' === $return_type ) {
			$body['returnContact'] = $this->return_contact();
		}

		$response = $this->client->cancel_order( $body );
		if ( is_wp_error( $response ) ) {
			MyEasyBuy_OMS_Repository::update_order( (int) $row['id'], array( 'last_error' => $response->get_error_message() ) );
			return $response;
		}

		MyEasyBuy_OMS_Repository::update_order(
			(int) $row['id'],
			array(
				'oms_status'        => 'T',
				'last_action_code'  => 'CANCEL',
				'last_error'        => '',
				'request_payload'   => $body,
				'response_payload'  => $response,
			)
		);

		return MyEasyBuy_OMS_Repository::get( (int) $row['id'] );
	}

	/**
	 * Shelf return after inbound / putaway.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function shelf_return( int $oms_id, string $return_type = '' ) {
		$row = MyEasyBuy_OMS_Repository::get( $oms_id );
		if ( ! $row || 1 !== (int) $row['leg'] ) {
			return new WP_Error( 'meb_oms_missing', __( '一段单不存在。', 'meb-oms' ) );
		}

		$settings    = $this->get_settings();
		$return_type = $return_type ?: (string) $settings['default_return_type'];
		$body        = array(
			'fpxTrackNo'       => $row['fpx_track_no'],
			'customerHawbCode' => $row['customer_hawb_code'],
			'returnType'       => $return_type,
		);
		if ( 'EXPRESS_RETURN' === $return_type ) {
			$body['returnContact'] = $this->return_contact();
		}

		$response = $this->client->shelf_return( $body );
		if ( is_wp_error( $response ) ) {
			MyEasyBuy_OMS_Repository::update_order( (int) $row['id'], array( 'last_error' => $response->get_error_message() ) );
			return $response;
		}

		MyEasyBuy_OMS_Repository::update_order(
			(int) $row['id'],
			array(
				'oms_status'       => 'T',
				'last_action_code' => 'SHELF_RETURN',
				'last_error'       => '',
				'request_payload'  => $body,
				'response_payload' => $response,
			)
		);

		return MyEasyBuy_OMS_Repository::get( (int) $row['id'] );
	}

	/**
	 * Create 二段合包指令 from a WC consolidation group or RF consolidation.
	 *
	 * @param array<int, array<string, mixed>> $leg1_rows 一段 rows.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create_leg2( array $leg1_rows, string $group_id, string $source_type, int $source_id ) {
		$leg1_rows = array_values(
			array_filter(
				$leg1_rows,
				static function ( $row ) {
					return is_array( $row ) && 1 === (int) ( $row['leg'] ?? 0 );
				}
			)
		);

		if ( empty( $leg1_rows ) ) {
			return new WP_Error( 'meb_oms_empty', __( '没有可合包的一段单。', 'meb-oms' ) );
		}

		$existing = MyEasyBuy_OMS_Repository::get_by_source( $source_type, $source_id, 2 );
		if ( $existing && ! empty( $existing['fpx_track_no'] ) ) {
			return $existing;
		}

		$service_codes = array();
		foreach ( $leg1_rows as $row ) {
			$code = (string) ( $row['service_hawb_code'] ?: $row['fpx_track_no'] ?: $row['customer_hawb_code'] );
			if ( '' !== $code ) {
				$service_codes[] = $code;
			}
		}
		$service_codes = array_values( array_unique( $service_codes ) );
		if ( empty( $service_codes ) ) {
			return new WP_Error( 'meb_oms_codes', __( '一段单尚无服务商单号，无法下发二段。', 'meb-oms' ) );
		}

		$primary = $leg1_rows[0];
		$consignee = $this->consignee_for_source( $source_type, $source_id, $primary );
		$settings  = $this->get_settings();
		$hawb      = $existing['customer_hawb_code'] ?? ( 'MEB2-' . strtoupper( wp_generate_password( 10, false, false ) ) );
		$country   = (string) ( $consignee['countryCode'] ?? $settings['country_code'] );

		$order_detail = $this->base_order_block( $hawb, 'CONSOLIDATED_2', $country, $this->sale_pk_for_channel( $source_type, $source_id ) );
		$payload      = array(
			'orderDetail' => array(
				'order'            => $order_detail,
				'orderExtend'      => array( 'quantity' => count( $service_codes ) ),
				'declareItemList'  => $this->merged_declare_items( $leg1_rows, $source_type, $source_id ),
				'consignee'        => $consignee,
			),
			'oneServiceHawbCodeList' => $service_codes,
		);

		/**
		 * Filter 二段 payload.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $payload Payload.
		 * @param array<int, mixed>    $leg1_rows 一段 rows.
		 */
		$payload = apply_filters( 'myeasybuy_oms_leg2_payload', $payload, $leg1_rows, $group_id );

		$response = $this->client->create_order( $payload );
		$ids      = $this->extract_ids( is_wp_error( $response ) ? array() : $response, $hawb );

		$oms_id = MyEasyBuy_OMS_Repository::upsert_order(
			array(
				'leg'                   => 2,
				'source_type'           => $source_type,
				'source_id'             => $source_id,
				'group_id'              => $group_id,
				'customer_hawb_code'    => $ids['customer_hawb'] ?: $hawb,
				'fpx_track_no'          => $ids['fpx'],
				'service_hawb_code'     => $ids['service'],
				'oms_status'            => is_wp_error( $response ) ? ( $existing['oms_status'] ?? 'P' ) : 'P',
				'shipping_mode'         => 'CONSOLIDATED_2',
				'country_code'          => $country,
				'sale_pk_code'          => (string) $order_detail['salePkCode'],
				'one_service_hawb_list' => $service_codes,
				'last_error'            => is_wp_error( $response ) ? $response->get_error_message() : '',
				'request_payload'       => $payload,
				'response_payload'      => is_wp_error( $response ) ? array( 'error' => $response->get_error_message() ) : $response,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$this->sync_leg2_to_sources( $source_type, $source_id, $group_id, $ids );

		return MyEasyBuy_OMS_Repository::get( $oms_id );
	}

	/**
	 * Create 二段 from a customer consolidation group of WC orders.
	 *
	 * @param int[] $order_ids Parent order IDs.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create_leg2_for_wc_group( array $order_ids, string $group_id = '' ) {
		$leg1 = array();
		foreach ( $order_ids as $oid ) {
			$row = MyEasyBuy_OMS_Repository::get_by_source( self::SOURCE_WC, (int) $oid, 1 );
			if ( $row ) {
				if ( '' !== $group_id && $group_id !== (string) $row['group_id'] ) {
					MyEasyBuy_OMS_Repository::update_order( (int) $row['id'], array( 'group_id' => $group_id ) );
					$row['group_id'] = $group_id;
				}
				$leg1[] = $row;
			}
		}

		if ( empty( $leg1 ) ) {
			return new WP_Error( 'meb_oms_no_leg1', __( '合箱批次里还没有一段预报。', 'meb-oms' ) );
		}

		$primary_id = (int) $leg1[0]['source_id'];
		$group_id   = $group_id ?: (string) ( $leg1[0]['group_id'] ?: ( 'cg-' . $primary_id ) );

		return $this->create_leg2( $leg1, $group_id, self::SOURCE_WC, $primary_id );
	}

	/**
	 * Create 二段 from RF packages / consolidation.
	 *
	 * @param int[] $package_ids Package IDs.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create_leg2_for_rf( array $package_ids, int $consolidation_id ) {
		$leg1 = array();
		foreach ( $package_ids as $pid ) {
			$row = MyEasyBuy_OMS_Repository::get_by_source( self::SOURCE_RF, (int) $pid, 1 );
			if ( $row ) {
				MyEasyBuy_OMS_Repository::update_order( (int) $row['id'], array( 'group_id' => 'rf-' . $consolidation_id ) );
				$row['group_id'] = 'rf-' . $consolidation_id;
				$leg1[]          = $row;
			}
		}

		if ( empty( $leg1 ) ) {
			return new WP_Error( 'meb_oms_no_leg1', __( '这些转运包裹还没有一段预报。', 'meb-oms' ) );
		}

		return $this->create_leg2( $leg1, 'rf-' . $consolidation_id, self::SOURCE_RF, $consolidation_id );
	}

	/**
	 * Apply an OMS / Cainiao callback to local records.
	 *
	 * @param array<string, mixed> $payload Callback body.
	 * @return array<string, mixed>
	 */
	public function apply_callback( array $payload ): array {
		$action = strtoupper( (string) $this->first_value( $payload, array( 'operationActionCode', 'actionCode', 'opCode' ) ) );
		$msg    = (string) $this->first_value( $payload, array( 'msgType', 'messageType' ) );
		$code   = (string) $this->first_value(
			$payload,
			array( 'orderCode', 'fpxTrackNo', 'customerHawbCode', 'servehawbCode', 'serveHawbCode', 'trackingNumber' )
		);
		$ext    = $this->ext_info( $payload );
		$op     = (string) ( $ext['opCode'] ?? $payload['opCode'] ?? '' );

		$row = $this->resolve_row_from_callback( $payload );
		if ( ! $row && ( false !== stripos( $op, '无预报' ) || false !== stripos( $action, 'PULL' ) ) ) {
			$row = $this->handle_no_forecast_pull( $payload );
		}

		$result = array(
			'action' => $action,
			'msg'    => $msg,
			'found'  => (bool) $row,
		);

		if ( ! $row ) {
			return $result;
		}

		$update = array(
			'last_action_code' => $action ?: $msg,
			'last_error'       => '',
		);

		$weight = (int) $this->first_value( array_merge( $payload, $ext ), array( 'weight', 'grossWeight', 'actualWeight' ) );
		if ( $weight > 0 ) {
			$update['weight_g'] = $weight;
		}
		foreach ( array( 'length' => 'length_mm', 'width' => 'width_mm', 'height' => 'height_mm' ) as $from => $to ) {
			$mm = (int) $this->first_value( array_merge( $payload, $ext ), array( $from, $from . 'Mm', $from . '_mm' ) );
			if ( $mm > 0 ) {
				$update[ $to ] = $mm;
			}
		}

		$photos = $this->collect_photos( $payload, $ext );
		if ( ! empty( $photos ) ) {
			$update['photos'] = $photos;
		}

		$district = (string) $this->first_value( array_merge( $payload, $ext ), array( 'storageDistrict', 'whCode', 'location' ) );
		if ( '' !== $district ) {
			$update['storage_district'] = $district;
		}

		$service = (string) $this->first_value( $payload, array( 'serviceHawbCode', 'servehawbCode', 'trackingNumber' ) );
		if ( '' !== $service && empty( $row['service_hawb_code'] ) ) {
			$update['service_hawb_code'] = $service;
		}

		if ( $this->is_action( $action, $msg, array( 'CW_SIGN_IN', 'SIGNIN', 'SIGN_IN' ) ) ) {
			$rejected = ( '0' !== (string) ( $payload['operateResultCode'] ?? '0' ) && '' !== (string) ( $payload['operateResultCode'] ?? '' ) && '0' !== (string) ( $payload['operateResultCode'] ?? '0' ) )
				|| false !== stripos( $op, '拒' );
			$update['oms_status'] = $rejected ? 'R' : 'N';
		} elseif ( $this->is_action( $action, $msg, array( 'CW_INBOUND', 'INBOUND' ) ) ) {
			$update['oms_status'] = 'H';
		} elseif ( $this->is_action( $action, $msg, array( 'CW_UPSHELF', 'PUTAWAY', 'UPSHELF' ) ) ) {
			$update['oms_status'] = 'S';
		} elseif ( $this->is_action( $action, $msg, array( 'CW_CONSOED', 'CONSOLIDATION' ) ) ) {
			$update['oms_status'] = 'X';
			$pieces = $payload['pieceList'] ?? $ext['pieceList'] ?? array();
			if ( is_array( $pieces ) ) {
				$update['piece_list'] = $pieces;
			}
		} elseif ( $this->is_action( $action, $msg, array( 'OUTBOUND', 'CW_OUTBOUND' ) ) ) {
			$update['oms_status'] = 'C';
		} elseif ( $this->is_action( $action, $msg, array( 'CW_EXCEPTION', 'EXCEPTION' ) ) ) {
			$update['oms_status'] = 'E';
			$update['last_error'] = (string) ( $payload['operateRemark'] ?? $op ?: __( '仓内异常', 'meb-oms' ) );
		}

		MyEasyBuy_OMS_Repository::update_order( (int) $row['id'], $update );
		$fresh = MyEasyBuy_OMS_Repository::get( (int) $row['id'] );
		if ( $fresh ) {
			$this->propagate_to_local( $fresh, $payload, $ext );
		}

		$result['oms_id'] = (int) $row['id'];
		$result['status'] = $fresh['oms_status'] ?? '';

		return $result;
	}

	/**
	 * Attach a group id onto WC 一段 rows.
	 *
	 * @param int[] $order_ids Order IDs.
	 */
	public function tag_wc_group( array $order_ids, string $group_id ): void {
		foreach ( $order_ids as $oid ) {
			$row = MyEasyBuy_OMS_Repository::get_by_source( self::SOURCE_WC, (int) $oid, 1 );
			if ( $row ) {
				MyEasyBuy_OMS_Repository::update_order( (int) $row['id'], array( 'group_id' => $group_id ) );
			}
		}
	}

	/**
	 * Submit 一段 and persist.
	 *
	 * @param array<string, mixed>      $payload  Body.
	 * @param array<string, mixed>|null $existing Existing row.
	 * @return array<string, mixed>|WP_Error
	 */
	private function submit_leg1( array $payload, string $source_type, int $source_id, ?array $existing ) {
		$hawb     = (string) ( $payload['order']['customerHawbCode'] ?? '' );
		$response = $this->client->create_order( $payload );
		$ids      = $this->extract_ids( is_wp_error( $response ) ? array() : $response, $hawb );

		// 010201 duplicate — treat as success and keep existing / returned ids.
		if ( is_wp_error( $response ) && false !== strpos( $response->get_error_message(), '010201' ) ) {
			$ids['customer_hawb'] = $ids['customer_hawb'] ?: $hawb;
			$response             = array( 'code' => '010201', 'msg' => 'duplicate', 'data' => $ids );
		}

		$oms_id = MyEasyBuy_OMS_Repository::upsert_order(
			array(
				'leg'                => 1,
				'source_type'        => $source_type,
				'source_id'          => $source_id,
				'group_id'           => $existing['group_id'] ?? '',
				'customer_hawb_code' => $ids['customer_hawb'] ?: $hawb,
				'fpx_track_no'       => $ids['fpx'] ?: ( $existing['fpx_track_no'] ?? '' ),
				'service_hawb_code'  => $ids['service'] ?: ( $existing['service_hawb_code'] ?? '' ),
				'oms_status'         => $existing['oms_status'] ?? 'P',
				'shipping_mode'      => 'CONSOLIDATED_1',
				'country_code'       => (string) ( $payload['order']['countryCode'] ?? 'CA' ),
				'sale_pk_code'       => (string) ( $payload['order']['salePkCode'] ?? '' ),
				'last_error'         => is_wp_error( $response ) ? $response->get_error_message() : '',
				'request_payload'    => $payload,
				'response_payload'   => is_wp_error( $response ) ? array( 'error' => $response->get_error_message() ) : $response,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$this->write_source_meta( $source_type, $source_id, $ids, 'P' );

		return MyEasyBuy_OMS_Repository::get( $oms_id );
	}

	/**
	 * Persist last request/response.
	 *
	 * @param array<string, mixed>        $payload  Body.
	 * @param array<string, mixed>|WP_Error $response Response.
	 */
	private function persist_api_result( int $id, array $payload, $response ): void {
		$update = array(
			'request_payload'  => $payload,
			'response_payload' => is_wp_error( $response ) ? array( 'error' => $response->get_error_message() ) : $response,
			'last_error'       => is_wp_error( $response ) ? $response->get_error_message() : '',
		);

		if ( ! is_wp_error( $response ) ) {
			$ids = $this->extract_ids( $response, '' );
			if ( $ids['fpx'] ) {
				$update['fpx_track_no'] = $ids['fpx'];
			}
			if ( $ids['service'] ) {
				$update['service_hawb_code'] = $ids['service'];
			}
		}

		MyEasyBuy_OMS_Repository::update_order( $id, $update );
	}

	/**
	 * 一段 payload from WC order.
	 *
	 * @param array<string, mixed>|null $existing Existing OMS row.
	 * @return array<string, mixed>|WP_Error
	 */
	private function build_leg1_payload_from_wc( WC_Order $order, ?array $existing ) {
		$settings = $this->get_settings();
		$hawb     = $existing['customer_hawb_code'] ?? ( 'MEB' . $order->get_id() );
		$country  = $this->order_country( $order );
		$consignee = $this->consignee_from_order( $order );
		$items     = $this->declare_items_from_wc( $order );

		$payload = array(
			'order'           => $this->base_order_block( $hawb, 'CONSOLIDATED_1', $country, $this->sale_pk_for_wc( $order ) ),
			'orderExtend'     => array( 'quantity' => 1 ),
			'declareItemList' => $items,
			'consignee'       => $consignee,
		);

		$tracking = '';
		if ( class_exists( 'MyEasyBuy_Admin_Workflow' ) ) {
			$tracking = (string) $order->get_meta( MyEasyBuy_Admin_Workflow::META_CN_TRACKING, true );
		}
		if ( '' === $tracking ) {
			$tracking = (string) $order->get_meta( '_myeasybuy_cn_tracking', true );
		}
		if ( '' !== $tracking ) {
			$payload['order']['serveHawbCode'] = $tracking;
		}

		if ( 'DOOR_PICKUP' === $settings['fulfill_mode'] || 'FORWARD_WAREHOUSE' === $settings['fulfill_mode'] ) {
			$payload['orderPickUpAddress'] = $this->pickup_address();
		}

		/**
		 * Filter 一段 payload for a WC order.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $payload Payload.
		 * @param WC_Order             $order   Order.
		 */
		return apply_filters( 'myeasybuy_oms_leg1_payload_wc', $payload, $order );
	}

	/**
	 * 一段 payload from RF package.
	 *
	 * @param array<string, mixed>      $package  Package row.
	 * @param array<string, mixed>|null $existing Existing OMS row.
	 * @return array<string, mixed>|WP_Error
	 */
	private function build_leg1_payload_from_rf( array $package, ?array $existing ) {
		$settings = $this->get_settings();
		$hawb     = $existing['customer_hawb_code'] ?? ( 'MEBRF' . (int) $package['id'] );
		$user     = new WC_Customer( (int) $package['user_id'] );
		$country  = $user->get_shipping_country() ?: $user->get_billing_country() ?: (string) $settings['country_code'];
		$name     = (string) ( $package['declared_name'] ?? 'General merchandise' );

		$payload = array(
			'order'           => $this->base_order_block( $hawb, 'CONSOLIDATED_1', $country, (string) $settings['sale_pk_code'] ),
			'orderExtend'     => array( 'quantity' => 1 ),
			'declareItemList' => array(
				array(
					'declareEnName'      => $this->ascii_name( $name ),
					'declareCnName'      => $name,
					'pieces'             => 1,
					'unitPriceOriginal'  => 1000,
				),
			),
			'consignee'       => $this->consignee_from_customer( $user, $country ),
		);

		if ( ! empty( $package['tracking_number'] ) ) {
			$payload['order']['serveHawbCode'] = (string) $package['tracking_number'];
		}

		if ( 'DOOR_PICKUP' === $settings['fulfill_mode'] || 'FORWARD_WAREHOUSE' === $settings['fulfill_mode'] ) {
			$payload['orderPickUpAddress'] = $this->pickup_address();
		}

		/**
		 * Filter 一段 payload for an RF package.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $payload Payload.
		 * @param array<string, mixed> $package Package.
		 */
		return apply_filters( 'myeasybuy_oms_leg1_payload_rf', $payload, $package );
	}

	/**
	 * Shared order block.
	 *
	 * @return array<string, mixed>
	 */
	private function base_order_block( string $hawb, string $mode, string $country, string $sale_pk ): array {
		$settings = $this->get_settings();

		return array(
			'customerHawbCode' => $hawb,
			'salePkCode'       => $sale_pk ?: (string) $settings['sale_pk_code'],
			'parcelSource'     => (string) $settings['parcel_source'],
			'businessType'     => (string) $settings['business_type'],
			'shippingMode'     => $mode,
			'countryCode'      => $country ?: (string) $settings['country_code'],
			'fulfillMode'      => (string) $settings['fulfill_mode'],
		);
	}

	/**
	 * Sale product code.
	 */
	private function sale_pk_for_wc( WC_Order $order ): string {
		$settings = $this->get_settings();
		$channel  = '';
		if ( class_exists( 'MyEasyBuy_Consolidation' ) ) {
			$channel = (string) $order->get_meta( MyEasyBuy_Consolidation::META_SHIPPING_CHANNEL, true );
			if ( MyEasyBuy_Consolidation::CHANNEL_SEA === $channel && ! empty( $settings['sale_pk_code_sea'] ) ) {
				return (string) $settings['sale_pk_code_sea'];
			}
		}

		return (string) $settings['sale_pk_code'];
	}

	/**
	 * Sale product for 二段 source.
	 */
	private function sale_pk_for_channel( string $source_type, int $source_id ): string {
		$settings = $this->get_settings();
		if ( self::SOURCE_WC === $source_type ) {
			$order = wc_get_order( $source_id );
			if ( $order ) {
				return $this->sale_pk_for_wc( $order );
			}
		}

		return (string) $settings['sale_pk_code'];
	}

	/**
	 * Declare lines from a WC order.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function declare_items_from_wc( WC_Order $order ): array {
		$items = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$qty   = max( 1, (int) $item->get_quantity() );
			$price = (float) $item->get_total() / $qty;
			$items[] = array(
				'declareEnName'     => $this->ascii_name( $item->get_name() ),
				'declareCnName'     => mb_substr( $item->get_name(), 0, 60 ),
				'pieces'            => $qty,
				'unitPriceOriginal' => max( 1, (int) round( $price * 100 ) ),
			);
		}

		if ( empty( $items ) ) {
			$items[] = array(
				'declareEnName'     => 'General merchandise',
				'declareCnName'     => '日用百货',
				'pieces'            => 1,
				'unitPriceOriginal' => 1000,
			);
		}

		return $items;
	}

	/**
	 * Merge declare items for 二段.
	 *
	 * @param array<int, array<string, mixed>> $leg1_rows Rows.
	 * @return array<int, array<string, mixed>>
	 */
	private function merged_declare_items( array $leg1_rows, string $source_type, int $source_id ): array {
		$items = array();
		if ( self::SOURCE_WC === $source_type ) {
			foreach ( $leg1_rows as $row ) {
				$order = wc_get_order( (int) $row['source_id'] );
				if ( $order ) {
					$items = array_merge( $items, $this->declare_items_from_wc( $order ) );
				}
			}
		}

		if ( empty( $items ) ) {
			$items[] = array(
				'declareEnName'     => 'Consolidated parcel',
				'declareCnName'     => '集运包裹',
				'pieces'            => count( $leg1_rows ),
				'unitPriceOriginal' => 1000,
			);
		}

		unset( $source_id );

		return $items;
	}

	/**
	 * Consignee from WC order.
	 *
	 * @return array<string, string>
	 */
	private function consignee_from_order( WC_Order $order ): array {
		$ship = $order->get_address( 'shipping' );
		if ( empty( $ship['address_1'] ) ) {
			$ship = $order->get_address( 'billing' );
		}

		$country = strtoupper( (string) ( $ship['country'] ?: 'CA' ) );

		return array(
			'fullName'      => trim( (string) ( $ship['first_name'] ?? '' ) . ' ' . (string) ( $ship['last_name'] ?? '' ) ) ?: $order->get_formatted_billing_full_name(),
			'countryCode'   => $country,
			'stateName'     => (string) ( $ship['state'] ?? '' ),
			'cityName'      => (string) ( $ship['city'] ?? '' ),
			'postCode'      => (string) ( $ship['postcode'] ?? '' ),
			'phone'         => (string) ( $ship['phone'] ?: $order->get_billing_phone() ),
			'email'         => (string) $order->get_billing_email(),
			'addressDetail' => trim( (string) ( $ship['address_1'] ?? '' ) . ' ' . (string) ( $ship['address_2'] ?? '' ) ),
		);
	}

	/**
	 * Consignee from WC customer.
	 *
	 * @return array<string, string>
	 */
	private function consignee_from_customer( WC_Customer $user, string $country ): array {
		$name = trim( $user->get_shipping_first_name() . ' ' . $user->get_shipping_last_name() );
		if ( '' === $name ) {
			$name = trim( $user->get_billing_first_name() . ' ' . $user->get_billing_last_name() );
		}

		$address = trim( $user->get_shipping_address_1() . ' ' . $user->get_shipping_address_2() );
		if ( '' === $address ) {
			$address = trim( $user->get_billing_address_1() . ' ' . $user->get_billing_address_2() );
		}

		return array(
			'fullName'      => $name ?: ( $user->get_display_name() ),
			'countryCode'   => strtoupper( $country ),
			'stateName'     => $user->get_shipping_state() ?: $user->get_billing_state(),
			'cityName'      => $user->get_shipping_city() ?: $user->get_billing_city(),
			'postCode'      => $user->get_shipping_postcode() ?: $user->get_billing_postcode(),
			'phone'         => $user->get_billing_phone(),
			'email'         => $user->get_email(),
			'addressDetail' => $address,
		);
	}

	/**
	 * Consignee for 二段.
	 *
	 * @param array<string, mixed> $primary First 一段 row.
	 * @return array<string, string>
	 */
	private function consignee_for_source( string $source_type, int $source_id, array $primary ): array {
		if ( self::SOURCE_WC === $primary['source_type'] ) {
			$order = wc_get_order( (int) $primary['source_id'] );
			if ( $order ) {
				return $this->consignee_from_order( $order );
			}
		}

		if ( self::SOURCE_RF === $source_type && class_exists( '\ReverseForwarding\Models\Consolidation' ) ) {
			$row = \ReverseForwarding\Models\Consolidation::get( $source_id );
			if ( $row && ! empty( $row['order_id'] ) ) {
				$order = wc_get_order( (int) $row['order_id'] );
				if ( $order ) {
					return $this->consignee_from_order( $order );
				}
			}
			if ( $row ) {
				$user = new WC_Customer( (int) $row['user_id'] );
				return $this->consignee_from_customer( $user, (string) $this->get_settings()['country_code'] );
			}
		}

		return array(
			'fullName'      => 'Customer',
			'countryCode'   => (string) $this->get_settings()['country_code'],
			'cityName'      => '',
			'addressDetail' => '',
		);
	}

	/**
	 * Pickup / warehouse address when required.
	 *
	 * @return array<string, string>
	 */
	private function pickup_address(): array {
		$wh = (array) get_option( 'myeasybuy_procurement_warehouse', array() );

		return array(
			'fullName'      => (string) ( $wh['first_name'] ?? 'MyEasyBuy Warehouse' ),
			'countryCode'   => (string) ( $wh['country'] ?? 'CN' ),
			'stateName'     => (string) ( $wh['state'] ?? '' ),
			'cityName'      => (string) ( $wh['city'] ?? '' ),
			'postCode'      => (string) ( $wh['postcode'] ?? '' ),
			'phone'         => (string) ( $wh['phone'] ?? '' ),
			'addressDetail' => trim( (string) ( $wh['address_1'] ?? '' ) . ' ' . (string) ( $wh['address_2'] ?? '' ) ),
		);
	}

	/**
	 * Return contact for cancel / shelfReturn.
	 *
	 * @return array<string, string>
	 */
	private function return_contact(): array {
		$settings = $this->get_settings();
		$pickup   = $this->pickup_address();

		return array(
			'fullName'      => $settings['return_name'] ?: $pickup['fullName'],
			'phone'         => $settings['return_phone'] ?: $pickup['phone'],
			'addressDetail' => $settings['return_address'] ?: $pickup['addressDetail'],
			'countryCode'   => $pickup['countryCode'] ?? 'CN',
			'cityName'      => $pickup['cityName'] ?? '',
		);
	}

	/**
	 * Extract hawb / fpx / service from OMS response.
	 *
	 * @param array<string, mixed> $response Response.
	 * @return array{customer_hawb:string,fpx:string,service:string}
	 */
	private function extract_ids( array $response, string $fallback_hawb ): array {
		$data  = is_array( $response['data'] ?? null ) ? $response['data'] : $response;
		$order = is_array( $data['order'] ?? null ) ? $data['order'] : $data;

		return array(
			'customer_hawb' => (string) ( $order['customerHawbCode'] ?? $data['customerHawbCode'] ?? $fallback_hawb ),
			'fpx'           => (string) ( $order['fpxTrackNo'] ?? $data['fpxTrackNo'] ?? $data['fpx_track_no'] ?? '' ),
			'service'       => (string) ( $order['serviceHawbCode'] ?? $data['serviceHawbCode'] ?? $data['servehawbCode'] ?? '' ),
		);
	}

	/**
	 * Write hawb onto WC / RF source.
	 *
	 * @param array{customer_hawb:string,fpx:string,service:string} $ids IDs.
	 */
	private function write_source_meta( string $source_type, int $source_id, array $ids, string $status ): void {
		if ( self::SOURCE_WC === $source_type ) {
			$order = wc_get_order( $source_id );
			if ( ! $order ) {
				return;
			}
			$order->update_meta_data( self::META_LEG1_HAWB, $ids['customer_hawb'] );
			$order->update_meta_data( self::META_LEG1_FPX, $ids['fpx'] ?: $ids['service'] );
			$order->update_meta_data( self::META_STATUS, $status );
			$order->save();
			return;
		}

		if ( self::SOURCE_RF === $source_type && class_exists( '\ReverseForwarding\Models\Package' ) ) {
			update_option( 'meb_oms_rf_meta_' . $source_id, $ids, false );
		}
	}

	/**
	 * Write 二段 numbers back.
	 *
	 * @param array{customer_hawb:string,fpx:string,service:string} $ids IDs.
	 */
	private function sync_leg2_to_sources( string $source_type, int $source_id, string $group_id, array $ids ): void {
		if ( self::SOURCE_WC === $source_type && class_exists( 'MyEasyBuy_Customer_Consolidation' ) ) {
			$orders = wc_get_orders(
				array(
					'limit'      => 50,
					'meta_key'   => MyEasyBuy_Customer_Consolidation::META_GROUP_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value' => $group_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);
			if ( empty( $orders ) ) {
				$order = wc_get_order( $source_id );
				$orders = $order ? array( $order ) : array();
			}
			foreach ( $orders as $order ) {
				if ( ! $order instanceof WC_Order ) {
					continue;
				}
				$order->update_meta_data( self::META_LEG2_HAWB, $ids['customer_hawb'] );
				$order->update_meta_data( self::META_LEG2_FPX, $ids['fpx'] ?: $ids['service'] );
				$order->save();
			}
		}
	}

	/**
	 * Push callback data into WC / RF local workflow.
	 *
	 * @param array<string, mixed> $row     OMS row.
	 * @param array<string, mixed> $payload Callback.
	 * @param array<string, mixed> $ext     extInfo.
	 */
	private function propagate_to_local( array $row, array $payload, array $ext ): void {
		$status = (string) $row['oms_status'];

		if ( self::SOURCE_WC === $row['source_type'] ) {
			$this->propagate_wc( $row, $status, $payload, $ext );
			return;
		}

		if ( self::SOURCE_RF === $row['source_type'] ) {
			$this->propagate_rf( $row, $status, $payload, $ext );
		}
	}

	/**
	 * Update WooCommerce order from OMS node.
	 *
	 * @param array<string, mixed> $row     OMS row.
	 * @param array<string, mixed> $payload Callback.
	 * @param array<string, mixed> $ext     extInfo.
	 */
	private function propagate_wc( array $row, string $status, array $payload, array $ext ): void {
		$order = wc_get_order( (int) $row['source_id'] );
		if ( ! $order || ! class_exists( 'MyEasyBuy_Hooks' ) ) {
			return;
		}

		$order->update_meta_data( self::META_STATUS, $status );
		if ( ! empty( $row['fpx_track_no'] ) ) {
			$order->update_meta_data( self::META_LEG1_FPX, (string) $row['fpx_track_no'] );
		}
		if ( ! empty( $row['photos'] ) ) {
			$order->update_meta_data( self::META_PHOTOS, $row['photos'] );
		}

		$weight_g = (int) $row['weight_g'];
		if ( $weight_g > 0 ) {
			$order->update_meta_data( '_myeasybuy_actual_weight', $weight_g );
		}
		if ( (int) $row['length_mm'] > 0 ) {
			$order->update_meta_data(
				'_myeasybuy_dimensions',
				sprintf(
					'%sx%sx%s',
					round( (int) $row['length_mm'] / 10, 1 ),
					round( (int) $row['width_mm'] / 10, 1 ),
					round( (int) $row['height_mm'] / 10, 1 )
				)
			);
		}
		if ( ! empty( $row['storage_district'] ) && class_exists( 'MyEasyBuy_Admin_Workflow' ) ) {
			$order->update_meta_data( MyEasyBuy_Admin_Workflow::META_SHELF_NO, (string) $row['storage_district'] );
		}
		$order->save();

		$current = class_exists( 'MyEasyBuy_Order_Statuses' )
			? MyEasyBuy_Order_Statuses::normalize_status( $order->get_status() )
			: $order->get_status();

		if ( in_array( $status, array( 'H', 'S' ), true ) && class_exists( 'MyEasyBuy_Order_Statuses' ) ) {
			$lock = array(
				MyEasyBuy_Order_Statuses::STATUS_CONSOLIDATING,
				MyEasyBuy_Order_Statuses::STATUS_AWAITING_SHIPPING_FEE,
				MyEasyBuy_Order_Statuses::STATUS_PAID_SHIPPING,
				MyEasyBuy_Order_Statuses::STATUS_INT_SHIPPED,
				'wc-completed',
				'wc-cancelled',
				'wc-refunded',
				'wc-failed',
			);
			if ( ! in_array( $current, $lock, true ) ) {
				MyEasyBuy_Hooks::update_order_status(
					(int) $order->get_id(),
					MyEasyBuy_Order_Statuses::STATUS_IN_WAREHOUSE,
					sprintf(
						/* translators: %s: OMS status */
						__( 'OMS 回传入库/上架（%s）。', 'meb-oms' ),
						$status
					)
				);
			}

			if ( 'H' === $status && class_exists( 'MyEasyBuy_Hooks' ) ) {
				MyEasyBuy_Hooks::dispatch_item_inbound_completed(
					(int) $order->get_id(),
					array(
						'photos'       => is_array( $row['photos'] ) ? $row['photos'] : array(),
						'weight_grams' => $weight_g,
						'length_cm'    => round( (int) $row['length_mm'] / 10, 1 ),
						'width_cm'     => round( (int) $row['width_mm'] / 10, 1 ),
						'height_cm'    => round( (int) $row['height_mm'] / 10, 1 ),
						'shelf_code'   => (string) $row['storage_district'],
						'inspected_by' => '4PX-OMS',
						'inspected_at' => gmdate( 'c' ),
					)
				);
			}
		}

		if ( 'C' === $status && 2 === (int) $row['leg'] && class_exists( 'MyEasyBuy_Consolidation' ) ) {
			$tracking = (string) ( $row['service_hawb_code'] ?: $row['fpx_track_no'] ?: $this->first_value( $payload, array( 'trackingNumber', 'orderCode' ) ) );
			if ( '' !== $tracking && MyEasyBuy_Order_Statuses::STATUS_PAID_SHIPPING === $current ) {
				MyEasyBuy_Consolidation::record_international_shipment( (int) $order->get_id(), '4PX', $tracking );
			}
		}

		if ( 'C' === $status && 1 === (int) $row['leg'] && class_exists( 'MyEasyBuy_Consolidation' ) ) {
			$leg2 = MyEasyBuy_OMS_Repository::get_by_source( self::SOURCE_WC, (int) $row['source_id'], 2 );
			$tracking = (string) ( $leg2['service_hawb_code'] ?? $leg2['fpx_track_no'] ?? $row['fpx_track_no'] ?? '' );
			if ( '' !== $tracking && MyEasyBuy_Order_Statuses::STATUS_PAID_SHIPPING === $current ) {
				MyEasyBuy_Consolidation::record_international_shipment( (int) $order->get_id(), '4PX', $tracking );
			}
		}

		unset( $ext );
	}

	/**
	 * Update RF package from OMS node.
	 *
	 * @param array<string, mixed> $row     OMS row.
	 * @param array<string, mixed> $payload Callback.
	 * @param array<string, mixed> $ext     extInfo.
	 */
	private function propagate_rf( array $row, string $status, array $payload, array $ext ): void {
		if ( ! class_exists( '\ReverseForwarding\Models\Package' ) ) {
			return;
		}

		$package_id = (int) $row['source_id'];
		$weight_kg  = (int) $row['weight_g'] > 0 ? round( (int) $row['weight_g'] / 1000, 3 ) : 0;
		$photos     = is_array( $row['photos'] ) ? wp_json_encode( $row['photos'] ) : '';

		if ( 1 === (int) $row['leg'] && in_array( $status, array( 'H', 'S' ), true ) ) {
			global $wpdb;
			$table = \ReverseForwarding\Activator::packages_table();
			$data  = array(
				'status'         => \ReverseForwarding\Models\Package::STATUS_STORED,
				'shelf_location' => (string) $row['storage_district'],
			);
			if ( $weight_kg > 0 ) {
				$data['weight'] = $weight_kg;
			}
			if ( (int) $row['length_mm'] > 0 ) {
				$data['length'] = round( (int) $row['length_mm'] / 10, 2 );
				$data['width']  = round( (int) $row['width_mm'] / 10, 2 );
				$data['height'] = round( (int) $row['height_mm'] / 10, 2 );
			}
			if ( $photos ) {
				$data['photos'] = $photos;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( $table, $data, array( 'id' => $package_id ) );
		}

		if ( 'C' === $status && class_exists( '\ReverseForwarding\Models\Consolidation' ) ) {
			$tracking = (string) ( $row['service_hawb_code'] ?: $row['fpx_track_no'] ?: $this->first_value( $payload, array( 'trackingNumber' ) ) );
			if ( '' !== $tracking && self::SOURCE_RF === $row['source_type'] && 2 === (int) $row['leg'] ) {
				\ReverseForwarding\Models\Consolidation::mark_shipped( $package_id, $tracking, '4px' );
			}
		}

		unset( $ext );
	}

	/**
	 * No-forecast pull: locate by domestic tracking and create 一段 immediately.
	 *
	 * @param array<string, mixed> $payload Callback.
	 * @return array<string, mixed>|null
	 */
	private function handle_no_forecast_pull( array $payload ): ?array {
		$code = (string) $this->first_value(
			$payload,
			array( 'servehawbCode', 'serveHawbCode', 'trackingNumber', 'orderCode', 'mailNo' )
		);
		if ( '' === $code ) {
			return null;
		}

		$existing = MyEasyBuy_OMS_Repository::find_leg1_by_any_code( $code );
		if ( $existing ) {
			return $existing;
		}

		$order_id = $this->find_wc_order_by_cn_tracking( $code );
		if ( $order_id > 0 ) {
			$result = $this->forecast_wc_order( $order_id, true );
			return is_wp_error( $result ) ? null : $result;
		}

		$package_id = $this->find_rf_package_by_tracking( $code );
		if ( $package_id > 0 ) {
			$result = $this->forecast_rf_package( $package_id, true );
			return is_wp_error( $result ) ? null : $result;
		}

		return null;
	}

	/**
	 * Resolve OMS row from callback identifiers.
	 *
	 * @param array<string, mixed> $payload Callback.
	 * @return array<string, mixed>|null
	 */
	private function resolve_row_from_callback( array $payload ): ?array {
		$codes = array(
			$this->first_value( $payload, array( 'orderCode', 'fpxTrackNo', 'customerHawbCode', 'servehawbCode', 'serveHawbCode', 'trackingNumber' ) ),
		);
		$ext = $this->ext_info( $payload );
		if ( ! empty( $ext['logisticsOrderCodes'] ) && is_array( $ext['logisticsOrderCodes'] ) ) {
			$codes = array_merge( $codes, $ext['logisticsOrderCodes'] );
		}

		foreach ( $codes as $code ) {
			$code = trim( (string) $code );
			if ( '' === $code ) {
				continue;
			}
			$row = MyEasyBuy_OMS_Repository::find_leg1_by_any_code( $code );
			if ( $row ) {
				return $row;
			}
			$row = MyEasyBuy_OMS_Repository::get_by_hawb( $code );
			if ( $row ) {
				return $row;
			}
			$row = MyEasyBuy_OMS_Repository::get_by_fpx( $code );
			if ( $row ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Find WC order by domestic tracking.
	 */
	private function find_wc_order_by_cn_tracking( string $tracking ): int {
		$keys = array( '_myeasybuy_cn_tracking' );
		if ( class_exists( 'MyEasyBuy_Admin_Workflow' ) ) {
			$keys[] = MyEasyBuy_Admin_Workflow::META_CN_TRACKING;
		}

		foreach ( array_unique( $keys ) as $key ) {
			$orders = wc_get_orders(
				array(
					'limit'      => 1,
					'return'     => 'ids',
					'meta_key'   => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value' => $tracking, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);
			if ( ! empty( $orders[0] ) ) {
				return (int) $orders[0];
			}
		}

		return 0;
	}

	/**
	 * Find RF package by tracking.
	 */
	private function find_rf_package_by_tracking( string $tracking ): int {
		global $wpdb;

		if ( ! class_exists( '\ReverseForwarding\Activator' ) ) {
			return 0;
		}

		$table = \ReverseForwarding\Activator::packages_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE tracking_number = %s LIMIT 1", $tracking ) );

		return (int) $id;
	}

	/**
	 * Load RF package ignoring owner scope.
	 *
	 * @return array<string, mixed>|null
	 */
	private function get_rf_package( int $id ): ?array {
		global $wpdb;

		if ( $id <= 0 || ! class_exists( '\ReverseForwarding\Activator' ) ) {
			return null;
		}

		$table = \ReverseForwarding\Activator::packages_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * extInfo as array.
	 *
	 * @param array<string, mixed> $payload Callback.
	 * @return array<string, mixed>
	 */
	private function ext_info( array $payload ): array {
		$ext = $payload['extInfo'] ?? array();
		if ( is_string( $ext ) ) {
			$decoded = json_decode( $ext, true );
			$ext     = is_array( $decoded ) ? $decoded : array();
		}

		return is_array( $ext ) ? $ext : array();
	}

	/**
	 * Collect photo URLs.
	 *
	 * @param array<string, mixed> $payload Callback.
	 * @param array<string, mixed> $ext     extInfo.
	 * @return array<int, string>
	 */
	private function collect_photos( array $payload, array $ext ): array {
		$out = array();
		foreach ( array( $payload, $ext ) as $set ) {
			foreach ( array( 'imgURL', 'imgUrl', 'measureImageUrl', 'photos', 'imageUrls' ) as $key ) {
				if ( empty( $set[ $key ] ) ) {
					continue;
				}
				$values = is_array( $set[ $key ] ) ? $set[ $key ] : preg_split( '/[,\s]+/', (string) $set[ $key ] );
				foreach ( (array) $values as $url ) {
					$url = esc_url_raw( (string) $url );
					if ( $url ) {
						$out[] = $url;
					}
				}
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * First non-empty value.
	 *
	 * @param array<string, mixed> $data Data.
	 * @param array<int, string>   $keys Keys.
	 */
	private function first_value( array $data, array $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $data[ $key ] ) && '' !== $data[ $key ] && null !== $data[ $key ] ) {
				return $data[ $key ];
			}
		}

		return '';
	}

	/**
	 * Action / msgType matcher.
	 *
	 * @param array<int, string> $needles Tokens.
	 */
	private function is_action( string $action, string $msg, array $needles ): bool {
		$hay = strtoupper( $action . ' ' . $msg );
		foreach ( $needles as $needle ) {
			if ( '' !== $needle && false !== strpos( $hay, strtoupper( $needle ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Destination country.
	 */
	private function order_country( WC_Order $order ): string {
		$country = $order->get_shipping_country() ?: $order->get_billing_country();
		return strtoupper( $country ?: (string) $this->get_settings()['country_code'] );
	}

	/**
	 * ASCII-ish English name.
	 */
	private function ascii_name( string $name ): string {
		$ascii = remove_accents( $name );
		$ascii = preg_replace( '/[^A-Za-z0-9 \-]/', ' ', (string) $ascii );
		$ascii = trim( (string) preg_replace( '/\s+/', ' ', (string) $ascii ) );

		return $ascii !== '' ? mb_substr( $ascii, 0, 60 ) : 'General merchandise';
	}
}
