<?php
/**
 * REST API endpoints for WMS, frontend, and third-party integrations.
 *
 * @package MyEasyBuy_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers myeasybuy REST routes under myeasybuy/v1.
 */
class MyEasyBuy_REST_API {

	public const REST_NAMESPACE = 'myeasybuy/v1';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register REST routes.
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/orders/by-cn-tracking/(?P<tracking>[a-zA-Z0-9]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_order_by_cn_tracking' ),
				'permission_callback' => array( __CLASS__, 'can_manage_warehouse' ),
				'args'                => array(
					'tracking' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => array( __CLASS__, 'validate_tracking_number' ),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/wms/inbound',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'wms_inbound' ),
				'permission_callback' => array( __CLASS__, 'can_manage_warehouse' ),
				'args'                => self::wms_inbound_args(),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/user/packages',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_user_packages' ),
				'permission_callback' => array( __CLASS__, 'can_read_user_packages' ),
				'args'                => array(
					'user_id' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'default'           => 0,
					),
					'status'  => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/orders/(?P<id>\d+)/status',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_order_status' ),
					'permission_callback' => array( __CLASS__, 'can_manage_orders' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'validate_callback' => array( __CLASS__, 'validate_order_id' ),
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'update_order_status' ),
					'permission_callback' => array( __CLASS__, 'can_manage_orders' ),
					'args'                => array(
						'id'     => array(
							'required'          => true,
							'validate_callback' => array( __CLASS__, 'validate_order_id' ),
						),
						'status' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'note'   => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
							'default'           => '',
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/orders/(?P<id>\d+)/inbound',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'complete_inbound' ),
				'permission_callback' => array( __CLASS__, 'can_manage_warehouse' ),
				'args'                => self::inbound_args(),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/orders/(?P<id>\d+)/international-ship',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'international_ship' ),
				'permission_callback' => array( __CLASS__, 'can_manage_warehouse' ),
				'args'                => array(
					'id'               => array(
						'required'          => true,
						'validate_callback' => array( __CLASS__, 'validate_order_id' ),
					),
					'tracking_number'  => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'carrier'          => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'update_status'    => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/second-payment/calculate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'calculate_second_payment' ),
				'permission_callback' => array( __CLASS__, 'can_manage_orders' ),
				'args'                => array(
					'package_meta' => array(
						'required' => true,
						'type'     => 'object',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/orders/(?P<id>\d+)/consolidation',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'generate_consolidation_payment' ),
				'permission_callback' => array( __CLASS__, 'can_manage_orders' ),
				'args'                => array(
					'id'         => array(
						'required'          => true,
						'validate_callback' => array( __CLASS__, 'validate_order_id' ),
					),
					'weight_kg'  => array(
						'required' => true,
						'type'     => 'number',
					),
					'volume_cm3' => array(
						'required' => true,
						'type'     => 'number',
					),
					'channel'    => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/statuses',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_statuses' ),
				'permission_callback' => array( __CLASS__, 'can_manage_orders' ),
			)
		);
	}

	/**
	 * GET /orders/{id}/status
	 *
	 * @param WP_REST_Request $request Request object.
	 */
	public static function get_order_status( WP_REST_Request $request ): WP_REST_Response {
		$order_id = (int) $request['id'];
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			return new WP_REST_Response(
				array( 'message' => __( 'Order not found.', 'myeasybuy-core' ) ),
				404
			);
		}

		return new WP_REST_Response(
			array(
				'order_id' => $order_id,
				'status'   => MyEasyBuy_Order_Statuses::normalize_status( $order->get_status() ),
				'meta'     => array(
					'int_tracking_number' => $order->get_meta( '_myeasybuy_int_tracking_number', true ),
					'int_carrier'         => $order->get_meta( '_myeasybuy_int_carrier', true ),
				),
			),
			200
		);
	}

	/**
	 * POST/PATCH /orders/{id}/status
	 *
	 * @param WP_REST_Request $request Request object.
	 */
	public static function update_order_status( WP_REST_Request $request ) {
		$result = MyEasyBuy_Hooks::update_order_status(
			(int) $request['id'],
			(string) $request['status'],
			(string) $request['note']
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				'order_id' => (int) $request['id'],
				'status'   => MyEasyBuy_Order_Statuses::normalize_status( (string) $request['status'] ),
				'message'  => __( 'Order status updated.', 'myeasybuy-core' ),
			),
			200
		);
	}

	/**
	 * POST /orders/{id}/inbound
	 *
	 * @param WP_REST_Request $request Request object.
	 */
	public static function complete_inbound( WP_REST_Request $request ) {
		$order_id = (int) $request['id'];
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			return new WP_Error( 'myeasybuy_invalid_order', __( 'Order not found.', 'myeasybuy-core' ), array( 'status' => 404 ) );
		}

		$inbound_data = self::build_inbound_payload_from_request( $request );
		$result       = self::process_inbound_for_order( $order_id, $inbound_data, (string) $request->get_param( 'target_status' ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * GET /orders/by-cn-tracking/{tracking}
	 *
	 * @param WP_REST_Request $request Request object.
	 */
	public static function get_order_by_cn_tracking( WP_REST_Request $request ) {
		$tracking = sanitize_text_field( (string) $request['tracking'] );
		$order    = self::find_order_by_cn_tracking( $tracking );

		if ( ! $order ) {
			return new WP_Error(
				'myeasybuy_tracking_not_found',
				__( 'No order matched the domestic tracking number.', 'myeasybuy-core' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response(
			self::format_order_tracking_response( $order ),
			200
		);
	}

	/**
	 * POST /wms/inbound
	 *
	 * @param WP_REST_Request $request Request object.
	 */
	public static function wms_inbound( WP_REST_Request $request ) {
		$order_id = absint( $request->get_param( 'order_id' ) );
		$tracking = sanitize_text_field( (string) $request->get_param( 'domestic_tracking' ) );

		if ( $order_id <= 0 && '' === $tracking ) {
			return new WP_Error(
				'myeasybuy_missing_identifier',
				__( 'Provide order_id or domestic_tracking.', 'myeasybuy-core' ),
				array( 'status' => 400 )
			);
		}

		$order = $order_id > 0 ? wc_get_order( $order_id ) : self::find_order_by_cn_tracking( $tracking );

		if ( ! $order ) {
			return new WP_Error( 'myeasybuy_invalid_order', __( 'Order not found.', 'myeasybuy-core' ), array( 'status' => 404 ) );
		}

		$inbound_data = self::build_inbound_payload_from_request( $request );
		if ( '' === $inbound_data['domestic_tracking'] && $tracking ) {
			$inbound_data['domestic_tracking'] = $tracking;
		}

		$result = self::process_inbound_for_order(
			(int) $order->get_id(),
			$inbound_data,
			MyEasyBuy_Order_Statuses::STATUS_IN_WAREHOUSE
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * GET /user/packages
	 *
	 * @param WP_REST_Request $request Request object.
	 */
	public static function get_user_packages( WP_REST_Request $request ): WP_REST_Response {
		$user_id = absint( $request->get_param( 'user_id' ) );
		if ( $user_id <= 0 ) {
			$user_id = get_current_user_id();
		}

		$status_filter = sanitize_text_field( (string) $request->get_param( 'status' ) );
		$statuses      = self::get_package_status_slugs( $status_filter );

		$orders = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'status'      => $statuses,
				'limit'       => 100,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);

		$packages = array();
		foreach ( $orders as $order ) {
			$packages[] = self::format_order_package_response( $order );
		}

		return new WP_REST_Response(
			array(
				'user_id'  => $user_id,
				'count'    => count( $packages ),
				'packages' => $packages,
			),
			200
		);
	}

	/**
	 * POST /orders/{id}/international-ship
	 *
	 * @param WP_REST_Request $request Request object.
	 */
	public static function international_ship( WP_REST_Request $request ) {
		$result = MyEasyBuy_Consolidation::record_international_shipment(
			(int) $request['id'],
			(string) $request['carrier'],
			(string) $request['tracking_number']
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				'order_id'        => (int) $request['id'],
				'tracking_number' => (string) $request['tracking_number'],
				'carrier'         => (string) $request['carrier'],
				'message'         => __( 'International shipment recorded.', 'myeasybuy-core' ),
			),
			200
		);
	}

	/**
	 * POST /second-payment/calculate
	 *
	 * @param WP_REST_Request $request Request object.
	 */
	public static function calculate_second_payment( WP_REST_Request $request ) {
		$package_meta = (array) $request->get_param( 'package_meta' );

		if ( empty( $package_meta ) ) {
			$package_meta = array(
				'weight_kg'  => $request->get_param( 'weight_kg' ),
				'volume_cm3' => $request->get_param( 'volume_cm3' ),
				'channel'    => $request->get_param( 'channel' ),
			);
		}

		$fee = MyEasyBuy_Hooks::calculate_second_payment( $package_meta );

		if ( is_wp_error( $fee ) ) {
			return $fee;
		}

		return new WP_REST_Response(
			array(
				'shipping_fee' => $fee,
				'currency'     => get_woocommerce_currency(),
				'package_meta' => $package_meta,
			),
			200
		);
	}

	/**
	 * POST /orders/{id}/consolidation
	 *
	 * @param WP_REST_Request $request Request object.
	 */
	public static function generate_consolidation_payment( WP_REST_Request $request ) {
		$result = MyEasyBuy_Consolidation::generate_second_payment(
			(int) $request['id'],
			(float) $request['weight_kg'],
			(float) $request['volume_cm3'],
			(string) $request['channel']
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$parent = wc_get_order( (int) $request['id'] );
		$child  = wc_get_order( (int) $result );

		return new WP_REST_Response(
			array(
				'parent_order_id'        => (int) $request['id'],
				'second_payment_order_id' => (int) $result,
				'shipping_fee'           => $parent ? (float) $parent->get_meta( MyEasyBuy_Consolidation::META_SECOND_PAYMENT_FEE, true ) : 0,
				'payment_url'            => $child ? $child->get_checkout_payment_url() : '',
				'message'                => __( 'Second payment order created.', 'myeasybuy-core' ),
			),
			200
		);
	}

	/**
	 * GET /statuses — list myeasybuy custom statuses.
	 */
	public static function list_statuses(): WP_REST_Response {
		$statuses = array();

		foreach ( MyEasyBuy_Order_Statuses::get_definitions() as $slug => $args ) {
			$statuses[] = array(
				'slug'  => $slug,
				'label' => $args['label'],
			);
		}

		return new WP_REST_Response( array( 'statuses' => $statuses ), 200 );
	}

	/**
	 * Inbound endpoint argument schema.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function inbound_args(): array {
		return array(
			'id'                => array(
				'required'          => true,
				'validate_callback' => array( __CLASS__, 'validate_order_id' ),
			),
			'photos'            => array(
				'type'    => 'array',
				'items'   => array( 'type' => 'string' ),
				'sanitize_callback' => array( __CLASS__, 'sanitize_url_array' ),
			),
			'weight_kg'         => array( 'type' => 'number' ),
			'weight_grams'      => array( 'type' => 'integer' ),
			'length_cm'         => array( 'type' => 'number' ),
			'width_cm'          => array( 'type' => 'number' ),
			'height_cm'         => array( 'type' => 'number' ),
			'shelf_code'        => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'domestic_tracking' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'inspected_by'      => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'target_status'     => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => MyEasyBuy_Order_Statuses::STATUS_IN_WAREHOUSE,
			),
		);
	}

	/**
	 * WMS inbound endpoint argument schema.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function wms_inbound_args(): array {
		return array(
			'order_id'          => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'domestic_tracking' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'photos'            => array(
				'type'              => 'array',
				'items'             => array( 'type' => 'string' ),
				'sanitize_callback' => array( __CLASS__, 'sanitize_url_array' ),
			),
			'weight_kg'         => array( 'type' => 'number' ),
			'weight_grams'      => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'length_cm'         => array( 'type' => 'number' ),
			'width_cm'          => array( 'type' => 'number' ),
			'height_cm'         => array( 'type' => 'number' ),
			'shelf_code'        => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'shelf_no'          => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'inspected_by'      => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * Build normalized inbound payload from a REST request.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array<string, mixed>
	 */
	private static function build_inbound_payload_from_request( WP_REST_Request $request ): array {
		$weight_kg = (float) $request->get_param( 'weight_kg' );
		$grams     = absint( $request->get_param( 'weight_grams' ) );

		if ( $weight_kg <= 0 && $grams > 0 ) {
			$weight_kg = round( $grams / 1000, 3 );
		}

		$shelf = sanitize_text_field( (string) $request->get_param( 'shelf_code' ) );
		if ( '' === $shelf ) {
			$shelf = sanitize_text_field( (string) $request->get_param( 'shelf_no' ) );
		}

		$inspected_by = sanitize_text_field( (string) $request->get_param( 'inspected_by' ) );
		if ( '' === $inspected_by && is_user_logged_in() ) {
			$inspected_by = (string) get_current_user_id();
		}

		return array(
			'photos'            => array_values( array_filter( array_map( 'esc_url_raw', (array) $request->get_param( 'photos' ) ) ) ),
			'weight_kg'         => $weight_kg,
			'weight_grams'      => $grams > 0 ? $grams : (int) round( $weight_kg * 1000 ),
			'length_cm'         => (float) $request->get_param( 'length_cm' ),
			'width_cm'          => (float) $request->get_param( 'width_cm' ),
			'height_cm'         => (float) $request->get_param( 'height_cm' ),
			'shelf_code'        => $shelf,
			'domestic_tracking' => sanitize_text_field( (string) $request->get_param( 'domestic_tracking' ) ),
			'inspected_by'      => $inspected_by,
			'inspected_at'      => gmdate( 'c' ),
		);
	}

	/**
	 * Persist inbound data, fire hooks, and update order status.
	 *
	 * @param int                  $order_id      Order ID.
	 * @param array<string, mixed> $inbound_data  Normalized inbound payload.
	 * @param string               $target_status Target WooCommerce status (wc- prefixed).
	 * @return array<string, mixed>|WP_Error
	 */
	private static function process_inbound_for_order( int $order_id, array $inbound_data, string $target_status ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'myeasybuy_invalid_order', __( 'Order not found.', 'myeasybuy-core' ), array( 'status' => 404 ) );
		}

		if ( $inbound_data['weight_grams'] <= 0 || $inbound_data['length_cm'] <= 0 || $inbound_data['width_cm'] <= 0 || $inbound_data['height_cm'] <= 0 || '' === $inbound_data['shelf_code'] ) {
			return new WP_Error(
				'myeasybuy_invalid_inbound',
				__( 'Weight, dimensions, and shelf location are required.', 'myeasybuy-core' ),
				array( 'status' => 400 )
			);
		}

		$dimensions = sprintf(
			'%sx%sx%s',
			$inbound_data['length_cm'],
			$inbound_data['width_cm'],
			$inbound_data['height_cm']
		);

		$order->update_meta_data( '_myeasybuy_actual_weight', (int) $inbound_data['weight_grams'] );
		$order->update_meta_data( '_myeasybuy_dimensions', $dimensions );
		$order->update_meta_data( '_myeasybuy_inspection_photos', $inbound_data['photos'] );
		$order->update_meta_data( '_myeasybuy_shelf_no', $inbound_data['shelf_code'] );

		foreach ( $inbound_data as $key => $value ) {
			if ( is_scalar( $value ) && '' !== (string) $value ) {
				$order->update_meta_data( '_myeasybuy_inbound_' . $key, $value );
			}
		}

		$order->save();

		MyEasyBuy_Hooks::dispatch_item_inbound_completed( $order_id, $inbound_data );

		if ( '' === $target_status ) {
			$target_status = MyEasyBuy_Order_Statuses::STATUS_IN_WAREHOUSE;
		}

		MyEasyBuy_Hooks::update_order_status(
			$order_id,
			$target_status,
			__( 'Inbound inspection completed via myeasybuy REST API.', 'myeasybuy-core' )
		);

		return array(
			'order_id'     => $order_id,
			'status'       => MyEasyBuy_Order_Statuses::normalize_status( $target_status ),
			'inbound_data' => $inbound_data,
			'message'      => __( 'Inbound inspection recorded.', 'myeasybuy-core' ),
		);
	}

	/**
	 * Find order by domestic CN tracking meta.
	 *
	 * @param string $tracking Tracking number.
	 * @return WC_Order|null
	 */
	private static function find_order_by_cn_tracking( string $tracking ): ?WC_Order {
		$tracking = trim( $tracking );
		if ( '' === $tracking ) {
			return null;
		}

		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'meta_key'   => '_myeasybuy_cn_tracking',
				'meta_value' => $tracking,
			)
		);

		return ! empty( $orders ) ? $orders[0] : null;
	}

	/**
	 * Format order lookup response for warehouse scanners.
	 *
	 * @param WC_Order $order Order object.
	 * @return array<string, mixed>
	 */
	private static function format_order_tracking_response( WC_Order $order ): array {
		return array(
			'order_id'          => (int) $order->get_id(),
			'order_number'      => $order->get_order_number(),
			'status'            => MyEasyBuy_Order_Statuses::normalize_status( $order->get_status() ),
			'customer_id'       => (int) $order->get_customer_id(),
			'customer_name'     => trim( $order->get_formatted_billing_full_name() ),
			'domestic_carrier'  => (string) $order->get_meta( '_myeasybuy_cn_carrier', true ),
			'domestic_tracking' => (string) $order->get_meta( '_myeasybuy_cn_tracking', true ),
			'items'             => self::format_order_line_items( $order ),
			'meta'              => array(
				'shelf_no'      => (string) $order->get_meta( '_myeasybuy_shelf_no', true ),
				'actual_weight' => (int) $order->get_meta( '_myeasybuy_actual_weight', true ),
				'dimensions'    => (string) $order->get_meta( '_myeasybuy_dimensions', true ),
			),
		);
	}

	/**
	 * Format order as a buyer-facing warehouse package.
	 *
	 * @param WC_Order $order Order object.
	 * @return array<string, mixed>
	 */
	private static function format_order_package_response( WC_Order $order ): array {
		$summary = class_exists( 'MyEasyBuy_Customer_Inbound' )
			? MyEasyBuy_Customer_Inbound::get_package_summary( $order )
			: array();

		return array(
			'order_id'                   => (int) $order->get_id(),
			'order_number'               => $order->get_order_number(),
			'status'                     => MyEasyBuy_Order_Statuses::normalize_status( $order->get_status() ),
			'status_label'               => self::get_status_label( $order->get_status() ),
			'date_created'               => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : null,
			'domestic_tracking'          => (string) $order->get_meta( '_myeasybuy_cn_tracking', true ),
			'shelf_no'                   => (string) ( $summary['shelf_no'] ?? $order->get_meta( '_myeasybuy_shelf_no', true ) ),
			'weight_grams'               => (int) ( $summary['weight_grams'] ?? $order->get_meta( '_myeasybuy_actual_weight', true ) ),
			'dimensions'                 => (string) ( $summary['dimensions'] ?? $order->get_meta( '_myeasybuy_dimensions', true ) ),
			'inspection_photos'          => $summary['photos'] ?? self::normalize_photo_list( $order->get_meta( '_myeasybuy_inspection_photos', true ) ),
			'inbound_at'                 => (string) ( $summary['inbound_at'] ?? '' ),
			'free_storage_days'          => (int) ( $summary['free_storage_days'] ?? 0 ),
			'storage_deadline'           => (string) ( $summary['storage_deadline'] ?? '' ),
			'storage_remaining_days'     => (int) ( $summary['storage_remaining_days'] ?? 0 ),
			'storage_remaining_label'    => (string) ( $summary['storage_remaining_label'] ?? '' ),
			'items'                      => self::format_order_line_items( $order ),
		);
	}

	/**
	 * Serialize order line items for API consumers.
	 *
	 * @param WC_Order $order Order object.
	 * @return array<int, array<string, mixed>>
	 */
	private static function format_order_line_items( WC_Order $order ): array {
		$items = array();

		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$items[] = array(
				'item_id'    => (int) $item_id,
				'name'       => $item->get_name(),
				'quantity'   => (int) $item->get_quantity(),
				'source_url' => (string) $item->get_meta( '_myeasybuy_source_url', true ),
				'image_url'  => (string) $item->get_meta( '_myeasybuy_image_url', true ),
				'price_rmb'  => (string) $item->get_meta( '_myeasybuy_price_rmb', true ),
				'sku_spec'   => (string) $item->get_meta( '_myeasybuy_sku_spec', true ),
			);
		}

		return $items;
	}

	/**
	 * Status slugs used for buyer package listings.
	 *
	 * @param string $status_filter Optional single-status filter (wc- prefixed or raw).
	 * @return array<int, string>
	 */
	private static function get_package_status_slugs( string $status_filter ): array {
		if ( '' !== $status_filter ) {
			return array( str_replace( 'wc-', '', MyEasyBuy_Order_Statuses::normalize_status( $status_filter ) ) );
		}

		return array(
			str_replace( 'wc-', '', MyEasyBuy_Order_Statuses::STATUS_IN_WAREHOUSE ),
			str_replace( 'wc-', '', MyEasyBuy_Order_Statuses::STATUS_CONSOLIDATING ),
		);
	}

	/**
	 * Resolve human-readable status label.
	 *
	 * @param string $status Raw order status.
	 */
	private static function get_status_label( string $status ): string {
		$definitions = MyEasyBuy_Order_Statuses::get_definitions();
		$normalized  = MyEasyBuy_Order_Statuses::normalize_status( $status );

		return $definitions[ $normalized ]['label'] ?? $status;
	}

	/**
	 * Normalize inspection photo meta to URL list.
	 *
	 * @param mixed $raw Raw meta value.
	 * @return array<int, string>
	 */
	private static function normalize_photo_list( $raw ): array {
		if ( is_string( $raw ) && '' !== $raw ) {
			if ( filter_var( $raw, FILTER_VALIDATE_URL ) ) {
				return array( esc_url_raw( $raw ) );
			}

			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$raw = $decoded;
			}
		}

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$urls = array();
		foreach ( $raw as $entry ) {
			if ( is_numeric( $entry ) ) {
				$url = wp_get_attachment_url( (int) $entry );
				if ( $url ) {
					$urls[] = esc_url_raw( $url );
				}
				continue;
			}

			if ( is_string( $entry ) && '' !== $entry ) {
				$urls[] = esc_url_raw( $entry );
			}
		}

		return array_values( array_filter( $urls ) );
	}

	/**
	 * Sanitize an array of URL strings.
	 *
	 * @param mixed $value Raw value.
	 * @return array<int, string>
	 */
	public static function sanitize_url_array( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'esc_url_raw', $value ) ) );
	}

	/**
	 * Validate domestic tracking number format.
	 *
	 * @param mixed           $value   Param value.
	 * @param WP_REST_Request $request Request.
	 * @param string          $param   Param name.
	 */
	public static function validate_tracking_number( $value, WP_REST_Request $request, string $param ): bool {
		unset( $request, $param );

		return is_string( $value ) && 1 === preg_match( '/^[a-zA-Z0-9]+$/', $value );
	}

	/**
	 * Validate order ID exists.
	 *
	 * @param mixed           $value   Param value.
	 * @param WP_REST_Request $request Request.
	 * @param string          $param   Param name.
	 */
	public static function validate_order_id( $value, WP_REST_Request $request, string $param ): bool {
		unset( $request, $param );

		$order = wc_get_order( (int) $value );

		return (bool) $order;
	}

	/**
	 * Permission: manage shop orders (WMS admin / shop manager).
	 */
	public static function can_manage_orders(): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		/**
		 * Filter REST permission for order management endpoints.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $allowed Default capability check result.
		 */
		return apply_filters(
			'myeasybuy_rest_can_manage_orders',
			current_user_can( 'manage_woocommerce' ) || current_user_can( 'edit_shop_orders' )
		);
	}

	/**
	 * Permission: warehouse operations (Application Passwords supported via authenticated user).
	 */
	public static function can_manage_warehouse(): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		/**
		 * Filter REST permission for warehouse endpoints.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $allowed Default capability check result.
		 */
		return (bool) apply_filters(
			'myeasybuy_rest_can_manage_warehouse',
			current_user_can( 'manage_options' )
			|| current_user_can( 'manage_woocommerce' )
			|| current_user_can( MyEasyBuy_Roles::CAP_WAREHOUSE )
		);
	}

	/**
	 * Permission: read buyer warehouse packages.
	 *
	 * @param WP_REST_Request $request Request object.
	 */
	public static function can_read_user_packages( WP_REST_Request $request ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$user_id = absint( $request->get_param( 'user_id' ) );
		if ( $user_id <= 0 ) {
			/**
			 * Filter REST permission for reading the current user's packages.
			 *
			 * @since 1.3.0
			 *
			 * @param bool $allowed Default capability check result.
			 */
			return apply_filters( 'myeasybuy_rest_can_read_own_packages', current_user_can( 'read' ) );
		}

		if ( (int) get_current_user_id() === $user_id ) {
			return current_user_can( 'read' );
		}

		return self::can_manage_orders();
	}
}
