<?php
/**
 * Reuse myeasybuy-core 4PX 物流对接 for forwarding outbound.
 *
 * @package ReverseForwarding
 */

namespace ReverseForwarding\Shipping;

use ReverseForwarding\Checkout\Cart_Integration;
use ReverseForwarding\Frontend\My_Account;
use ReverseForwarding\Models\Consolidation;
use ReverseForwarding\Models\Package;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Paid consolidations enter the existing 国际发货 queue; 4PX payload uses
 * the procurement warehouse as sender.
 */
class Four_Px {

	public const PROMOTE_OPTION = 'wc_rf_4px_queue_promoted';

	/**
	 * Register hooks into the shared 4PX / 国际发货 UI.
	 */
	public static function init(): void {
		add_action( 'wc_rf_consolidation_paid', array( __CLASS__, 'on_paid' ), 10, 2 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'maybe_queue_from_order' ), 25, 2 );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'maybe_queue_from_id' ), 15 );
		add_filter( 'myeasybuy_4px_create_order_payload', array( __CLASS__, 'filter_create_payload' ), 10, 2 );
		add_action( 'myeasybuy_international_shipped', array( __CLASS__, 'on_international_shipped' ), 10, 3 );
		add_action( 'myeasybuy_international_tracking_added', array( __CLASS__, 'on_tracking_added' ), 10, 3 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_promote_paid_orders' ), 50 );
	}

	/**
	 * After RF settlement, seed 4PX meta and move the WC order to 国际发货.
	 *
	 * @param int      $id    Consolidation ID.
	 * @param WC_Order $order Checkout order.
	 */
	public static function on_paid( int $id, $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		self::queue_for_outbound( $id, $order );
	}

	/**
	 * @param int           $order_id Order ID.
	 * @param WC_Order|null $order    Order.
	 */
	public static function maybe_queue_from_order( int $order_id, $order = null ): void {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$id = absint( $order->get_meta( Cart_Integration::ORDER_META ) );
		if ( $id <= 0 ) {
			return;
		}

		self::queue_for_outbound( $id, $order );
	}

	/**
	 * @param int $order_id Order ID.
	 */
	public static function maybe_queue_from_id( int $order_id ): void {
		self::maybe_queue_from_order( $order_id );
	}

	/**
	 * Write 代购-compatible weight/dims and put the order on 国际发货.
	 */
	public static function queue_for_outbound( int $consolidation_id, WC_Order $order ): void {
		$row = Consolidation::get( $consolidation_id );
		if ( null === $row || Consolidation::STATUS_PAID !== $row['status'] ) {
			return;
		}

		self::seed_order_meta( $row, $order );

		if ( ! class_exists( 'MyEasyBuy_Order_Statuses' ) ) {
			return;
		}

		$current = \MyEasyBuy_Order_Statuses::normalize_status( $order->get_status() );
		$skip    = array(
			\MyEasyBuy_Order_Statuses::STATUS_PAID_SHIPPING,
			\MyEasyBuy_Order_Statuses::STATUS_INT_SHIPPED,
			'wc-completed',
			'wc-cancelled',
			'wc-refunded',
			'wc-failed',
		);
		if ( in_array( $current, $skip, true ) ) {
			return;
		}

		$order->update_status(
			str_replace( 'wc-', '', \MyEasyBuy_Order_Statuses::STATUS_PAID_SHIPPING ),
			__( '集运费用已付，进入 4PX 国际发货队列。', 'wc-rf' )
		);
	}

	/**
	 * Replace sender + parcel lines for RF checkout orders.
	 *
	 * @param array<string, mixed> $payload 4PX body.
	 * @param WC_Order             $order   Order.
	 * @return array<string, mixed>
	 */
	public static function filter_create_payload( $payload, $order ) {
		if ( ! is_array( $payload ) || ! $order instanceof WC_Order ) {
			return $payload;
		}

		$id = absint( $order->get_meta( Cart_Integration::ORDER_META ) );
		if ( $id <= 0 ) {
			return $payload;
		}

		$row = Consolidation::get( $id );
		if ( null === $row ) {
			return $payload;
		}

		$packages = Package::list_for_consolidation( $id );
		$sender   = self::sender_from_warehouse();
		if ( array() !== $sender ) {
			$payload['sender'] = array_merge( (array) ( $payload['sender'] ?? array() ), $sender );
		}

		$built = self::build_parcel_list( $row, $packages, $order );
		if ( array() !== $built ) {
			$payload['parcel_list'] = $built;
		}

		$payload['ref_no'] = 'RF' . $id . 'O' . $order->get_id() . 'T' . time();

		return $payload;
	}

	/**
	 * @param int    $order_id Order ID.
	 * @param string $tracking Tracking.
	 * @param string $carrier  Carrier.
	 */
	public static function on_international_shipped( $order_id, $tracking = '', $carrier = '4PX' ): void {
		self::sync_outbound( (int) $order_id, (string) $tracking, (string) $carrier );
	}

	/**
	 * @param int    $order_id Order ID.
	 * @param string $carrier  Carrier.
	 * @param string $tracking Tracking.
	 */
	public static function on_tracking_added( $order_id, $carrier = '4PX', $tracking = '' ): void {
		self::sync_outbound( (int) $order_id, (string) $tracking, (string) $carrier );
	}

	/**
	 * One-time: move already-paid RF orders into the 国际发货 queue.
	 */
	public static function maybe_promote_paid_orders(): void {
		if ( get_option( self::PROMOTE_OPTION ) === '1' ) {
			return;
		}
		if ( ! function_exists( 'wc_get_order' ) || ! class_exists( 'MyEasyBuy_Order_Statuses' ) ) {
			return;
		}

		global $wpdb;
		$table = Consolidation::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE status = %s AND order_id > 0",
				Consolidation::STATUS_PAID
			)
		);

		if ( is_array( $ids ) ) {
			foreach ( $ids as $id ) {
				$row = Consolidation::get( (int) $id );
				if ( null === $row ) {
					continue;
				}
				$order = wc_get_order( (int) $row['order_id'] );
				if ( $order instanceof WC_Order ) {
					self::queue_for_outbound( (int) $id, $order );
				}
			}
		}

		update_option( self::PROMOTE_OPTION, '1', false );
	}

	/**
	 * Redirect submenu into the shared 国际发货 screen.
	 */
	public static function redirect_int_shipping(): void {
		if ( ! Package::warehouse_can() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wc-rf' ) );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=myeasybuy-int-shipping' ) );
		exit;
	}

	/**
	 * Redirect submenu into 4PX 物流对接 settings.
	 */
	public static function redirect_settings(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wc-rf' ) );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=myeasybuy-4px-settings' ) );
		exit;
	}

	/**
	 * Write tracking back to the custom consolidation + packages.
	 */
	private static function sync_outbound( int $order_id, string $tracking, string $carrier ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$id = absint( $order->get_meta( Cart_Integration::ORDER_META ) );
		if ( $id <= 0 ) {
			$by_order = Consolidation::get_by_order( $order_id );
			$id       = (int) ( $by_order['id'] ?? 0 );
		}
		if ( $id <= 0 ) {
			return;
		}

		$tracking = sanitize_text_field( $tracking );
		if ( '' === $tracking ) {
			$tracking = sanitize_text_field( (string) $order->get_meta( '_myeasybuy_int_tracking_number', true ) );
		}
		if ( '' === $tracking ) {
			return;
		}

		$carrier_code = strtolower( sanitize_text_field( $carrier ) );
		if ( '' === $carrier_code ) {
			$carrier_code = '4px';
		}

		Consolidation::mark_shipped( $id, $tracking, $carrier_code );
		Package::mark_in_transit( $id );
	}

	/**
	 * Copy chargeable weight / outer dims onto the WC order for 4PX defaults.
	 *
	 * @param array<string, mixed> $row Consolidation.
	 */
	private static function seed_order_meta( array $row, WC_Order $order ): void {
		$packages = Package::list_for_consolidation( (int) $row['id'] );
		$dims     = self::outer_dims( $packages );
		$weight   = (float) $row['chargeable_weight'];
		if ( $weight <= 0 ) {
			$weight = self::actual_weight( $packages );
		}

		$channel = class_exists( 'MyEasyBuy_Consolidation' )
			? \MyEasyBuy_Consolidation::CHANNEL_AIR
			: 'canada_air_express';

		$order->update_meta_data( '_myeasybuy_consolidated_weight_kg', $weight );
		$order->update_meta_data( '_myeasybuy_consolidated_volume_cm3', $dims['length'] * $dims['width'] * $dims['height'] );
		$order->update_meta_data( '_myeasybuy_dimensions', $dims['length'] . 'x' . $dims['width'] . 'x' . $dims['height'] );
		$order->update_meta_data( '_myeasybuy_shipping_channel', $channel );
		$order->save();
	}

	/**
	 * 4PX sender = procurement / RF warehouse card.
	 *
	 * @return array<string, string>
	 */
	private static function sender_from_warehouse(): array {
		$wh      = My_Account::get_warehouse();
		$street  = trim( $wh['address_1'] . ( '' !== $wh['address_2'] ? ' ' . $wh['address_2'] : '' ) );
		$phone   = $wh['phone'];
		$email   = '';

		if ( class_exists( 'MyEasyBuy_4PX_Service' ) ) {
			$settings = \MyEasyBuy_4PX_Service::instance()->get_settings();
			if ( '' === $phone ) {
				$phone = (string) ( $settings['4px_shipper_phone'] ?? '' );
			}
			$email = (string) ( $settings['4px_shipper_email'] ?? '' );
		}

		return array_filter(
			array(
				'first_name' => $wh['recipient_base'],
				'company'    => $wh['company'],
				'phone'      => $phone,
				'email'      => $email,
				'country'    => $wh['country'] ?: 'CN',
				'state'      => self::map_region( $wh['state'] ),
				'city'       => self::map_region( $wh['city'] ),
				'post_code'  => $wh['postcode'],
				'street'     => $street,
			),
			static function ( $value ) {
				return '' !== (string) $value;
			}
		);
	}

	/**
	 * @param array<string, mixed>             $row      Consolidation.
	 * @param array<int, array<string, mixed>> $packages Packages.
	 * @return array<int, array<string, mixed>>
	 */
	private static function build_parcel_list( array $row, array $packages, WC_Order $order ): array {
		$weight = (float) $row['chargeable_weight'];
		if ( $weight <= 0 ) {
			$weight = self::actual_weight( $packages );
		}
		$weight = max( 0.01, $weight );
		$dims   = self::outer_dims( $packages );
		$items  = array();
		$value  = 0.0;
		$count  = max( 1, count( $packages ) );

		foreach ( $packages as $package ) {
			$name   = (string) ( $package['declared_name'] ?? '' );
			$name   = '' !== $name ? $name : __( '日用百货', 'wc-rf' );
			$pkg_kg = (float) ( $package['weight'] ?? 0 );
			$share  = $pkg_kg > 0 ? $pkg_kg : round( $weight / $count, 3 );
			$price  = 1.0;
			$value += $price;
			$items[] = array(
				'declare_product_name_cn'     => mb_substr( $name, 0, 60 ),
				'declare_product_name_en'     => mb_substr( $name, 0, 60 ),
				'declare_product_code_qty'    => 1,
				'unit_price'                  => $price,
				'currency'                    => $order->get_currency() ?: 'CAD',
				'declare_product_code_weight' => round( max( 0.01, $share ), 3 ),
			);
		}

		if ( array() === $items ) {
			$items[] = array(
				'declare_product_name_cn'     => __( '日用百货', 'wc-rf' ),
				'declare_product_name_en'     => 'General merchandise',
				'declare_product_code_qty'    => 1,
				'unit_price'                  => 1,
				'currency'                    => $order->get_currency() ?: 'CAD',
				'declare_product_code_weight' => $weight,
			);
			$value = 1.0;
		}

		return array(
			array(
				'weight'               => round( $weight, 3 ),
				'length'               => $dims['length'],
				'width'                => $dims['width'],
				'height'               => $dims['height'],
				'parcel_value'         => round( max( 1, $value ), 2 ),
				'currency'             => $order->get_currency() ?: 'CAD',
				'include_battery'      => 'N',
				'declare_product_info' => $items,
			),
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $packages Packages.
	 * @return array{length: float, width: float, height: float}
	 */
	private static function outer_dims( array $packages ): array {
		$length = 0.0;
		$width  = 0.0;
		$height = 0.0;

		foreach ( $packages as $package ) {
			$length = max( $length, (float) ( $package['length'] ?? 0 ) );
			$width  = max( $width, (float) ( $package['width'] ?? 0 ) );
			$height += (float) ( $package['height'] ?? 0 );
		}

		return array(
			'length' => max( 5.0, $length ),
			'width'  => max( 5.0, $width ),
			'height' => max( 5.0, $height ),
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $packages Packages.
	 */
	private static function actual_weight( array $packages ): float {
		$total = 0.0;
		foreach ( $packages as $package ) {
			$total += (float) ( $package['weight'] ?? 0 );
		}

		return $total > 0 ? $total : 0.5;
	}

	/**
	 * Map common CN admin names to 4PX-friendly English.
	 */
	private static function map_region( string $value ): string {
		$map = array(
			'广东省' => 'Guangdong',
			'广东'   => 'Guangdong',
			'深圳市' => 'Shenzhen',
			'深圳'   => 'Shenzhen',
			'广州市' => 'Guangzhou',
			'广州'   => 'Guangzhou',
			'东莞市' => 'Dongguan',
			'东莞'   => 'Dongguan',
			'佛山市' => 'Foshan',
			'佛山'   => 'Foshan',
		);

		return $map[ $value ] ?? $value;
	}
}
