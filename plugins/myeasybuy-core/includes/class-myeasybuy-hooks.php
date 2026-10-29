<?php
/**
 * Central hook definitions and event dispatchers for myeasybuy-core.
 *
 * @package MyEasyBuy_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manages myeasybuy Action/Filter hooks and bridges WooCommerce events.
 */
class MyEasyBuy_Hooks {

	/** @var string Order status changed action. */
	public const ACTION_ORDER_STATUS_CHANGED = 'myeasybuy_order_status_changed';

	/** @var string Inbound inspection completed action. */
	public const ACTION_ITEM_INBOUND_COMPLETED = 'myeasybuy_item_inbound_completed';

	/** @var string International shipment dispatched action. */
	public const ACTION_INTERNATIONAL_SHIPPED = 'myeasybuy_international_shipped';

	/** @var string Second payment shipping fee filter. */
	public const FILTER_CALCULATE_SECOND_PAYMENT = 'myeasybuy_calculate_second_payment';

	/** @var string Manual product entry completed action. */
	public const ACTION_MANUAL_ENTRY_COMPLETED = 'myeasybuy_manual_entry_completed';

	/** @var string International tracking added action. */
	public const ACTION_INTERNATIONAL_TRACKING_ADDED = 'myeasybuy_international_tracking_added';

	/** @var string International package delivered action. */
	public const ACTION_ORDER_DELIVERED = 'myeasybuy_order_delivered';

	/** @var string Shipping rate calculator filter. */
	public const FILTER_SHIPPING_RATE_CALCULATOR = 'myeasybuy_shipping_rate_calculator';

	/**
	 * Register internal listeners.
	 */
	public static function init(): void {
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_wc_order_status_changed' ), 10, 4 );
	}

	/**
	 * Bridge WooCommerce status changes to myeasybuy_order_status_changed.
	 *
	 * @param int      $order_id   Order ID.
	 * @param string   $old_status Previous status (without wc- prefix).
	 * @param string   $new_status New status (without wc- prefix).
	 * @param WC_Order $order      Order object.
	 */
	public static function on_wc_order_status_changed( $order_id, $old_status, $new_status, $order ): void {
		unset( $order );

		self::dispatch_order_status_changed(
			(int) $order_id,
			self::normalize_hook_status( (string) $old_status ),
			self::normalize_hook_status( (string) $new_status )
		);
	}

	/**
	 * Dispatch myeasybuy_order_status_changed.
	 *
	 * @param int    $order_id   Order ID.
	 * @param string $old_status Previous status (wc- prefixed).
	 * @param string $new_status New status (wc- prefixed).
	 */
	public static function dispatch_order_status_changed( int $order_id, string $old_status, string $new_status ): void {
		$old_status = MyEasyBuy_Order_Statuses::normalize_status( $old_status );
		$new_status = MyEasyBuy_Order_Statuses::normalize_status( $new_status );

		if ( $old_status === $new_status ) {
			return;
		}

		/**
		 * Fires when a myeasybuy order transitions to a new WooCommerce status.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $order_id   WooCommerce order ID.
		 * @param string $old_status Previous status slug (wc- prefixed).
		 * @param string $new_status New status slug (wc- prefixed).
		 */
		do_action( self::ACTION_ORDER_STATUS_CHANGED, $order_id, $old_status, $new_status );
	}

	/**
	 * Dispatch inbound inspection completed event.
	 *
	 * Expected $inbound_data keys (extensible via filter):
	 * - photos (array of URLs)
	 * - weight_kg (float)
	 * - length_cm, width_cm, height_cm (float)
	 * - shelf_code (string)
	 * - domestic_tracking (string)
	 * - inspected_by (string|int)
	 * - inspected_at (string ISO8601)
	 *
	 * @param int                  $order_id     WooCommerce order ID.
	 * @param array<string, mixed> $inbound_data Inbound payload.
	 */
	public static function dispatch_item_inbound_completed( int $order_id, array $inbound_data ): void {
		/**
		 * Filter inbound payload before the action is fired.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $inbound_data Inbound data.
		 * @param int                  $order_id     Order ID.
		 */
		$inbound_data = apply_filters( 'myeasybuy_item_inbound_data', $inbound_data, $order_id );

		/**
		 * Fires when warehouse staff completes inbound inspection for an order.
		 *
		 * @since 1.0.0
		 *
		 * @param int                  $order_id     WooCommerce order ID.
		 * @param array<string, mixed> $inbound_data Inspection payload.
		 */
		do_action( self::ACTION_ITEM_INBOUND_COMPLETED, $order_id, $inbound_data );
	}

	/**
	 * Dispatch international shipment event and optionally update order status.
	 *
	 * @param int    $order_id        WooCommerce order ID.
	 * @param string $tracking_number International tracking number.
	 * @param string $carrier         Carrier code or name.
	 * @param bool   $update_status   Whether to set order to wc-int-shipped.
	 */
	public static function dispatch_international_shipped(
		int $order_id,
		string $tracking_number,
		string $carrier,
		bool $update_status = true
	): void {
		$tracking_number = sanitize_text_field( $tracking_number );
		$carrier         = sanitize_text_field( $carrier );

		self::dispatch_international_tracking_added( $order_id, $carrier, $tracking_number );

		/**
		 * Fires when an order is dispatched internationally.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $order_id        WooCommerce order ID.
		 * @param string $tracking_number Tracking number.
		 * @param string $carrier         Carrier identifier.
		 */
		do_action( self::ACTION_INTERNATIONAL_SHIPPED, $order_id, $tracking_number, $carrier );

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$order->update_meta_data( '_myeasybuy_int_tracking_number', $tracking_number );
		$order->update_meta_data( '_myeasybuy_int_carrier', $carrier );
		$order->save();

		if ( $update_status ) {
			$order->update_status(
				str_replace( 'wc-', '', MyEasyBuy_Order_Statuses::STATUS_INT_SHIPPED ),
				__( 'International shipment recorded by myeasybuy-core.', 'myeasybuy-core' )
			);
		}
	}

	/**
	 * Dispatch international tracking added event.
	 *
	 * @param int    $order_id        WooCommerce order ID.
	 * @param string $carrier         Carrier name or code.
	 * @param string $tracking_number Tracking number.
	 */
	public static function dispatch_international_tracking_added( int $order_id, string $carrier, string $tracking_number ): void {
		$carrier         = sanitize_text_field( $carrier );
		$tracking_number = sanitize_text_field( $tracking_number );

		/**
		 * Fires when warehouse staff adds international carrier tracking.
		 *
		 * @since 1.3.0
		 *
		 * @param int    $order_id        WooCommerce order ID.
		 * @param string $carrier         Carrier identifier.
		 * @param string $tracking_number Tracking number.
		 */
		do_action( self::ACTION_INTERNATIONAL_TRACKING_ADDED, $order_id, $carrier, $tracking_number );
	}

	/**
	 * Calculate second payment (international shipping fee) via filter chain.
	 *
	 * @param array<string, mixed> $package_meta Package/consolidation metadata.
	 * @return float|WP_Error Final shipping fee in store currency.
	 */
	public static function calculate_second_payment( array $package_meta ) {
		if ( isset( $package_meta['weight_kg'], $package_meta['volume_cm3'], $package_meta['channel'] ) ) {
			return MyEasyBuy_Consolidation::calculate_shipping_rate(
				(float) $package_meta['weight_kg'],
				(float) $package_meta['volume_cm3'],
				(string) $package_meta['channel']
			);
		}

		$default_fee = isset( $package_meta['shipping_fee'] )
			? (float) $package_meta['shipping_fee']
			: 0.0;

		/**
		 * Filter the calculated second-payment shipping fee.
		 *
		 * @since 1.0.0
		 *
		 * @param float                $shipping_fee Default/base shipping fee.
		 * @param array<string, mixed> $package_meta Package weight, dimensions, destination, etc.
		 */
		$fee = apply_filters( self::FILTER_CALCULATE_SECOND_PAYMENT, $default_fee, $package_meta );

		if ( is_wp_error( $fee ) ) {
			return $fee;
		}

		return max( 0.0, (float) $fee );
	}

	/**
	 * Dispatch manual product entry completed event.
	 *
	 * @param int $order_id WooCommerce order ID.
	 */
	public static function dispatch_manual_entry_completed( int $order_id ): void {
		/**
		 * Fires when a buyer completes manual JD product entry for an order.
		 *
		 * @since 1.1.0
		 *
		 * @param int $order_id WooCommerce order ID.
		 */
		do_action( self::ACTION_MANUAL_ENTRY_COMPLETED, $order_id );
	}

	/**
	 * Programmatically update order status (for REST/WMS callers).
	 *
	 * @param int    $order_id   Order ID.
	 * @param string $new_status Target status (wc- prefixed or raw).
	 * @param string $note       Optional order note.
	 * @return true|WP_Error
	 */
	public static function update_order_status( int $order_id, string $new_status, string $note = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'myeasybuy_invalid_order', __( 'Order not found.', 'myeasybuy-core' ), array( 'status' => 404 ) );
		}

		$new_status = MyEasyBuy_Order_Statuses::normalize_status( $new_status );

		$allowed = array_keys( MyEasyBuy_Order_Statuses::get_definitions() );
		$allowed = array_merge( $allowed, array_keys( wc_get_order_statuses() ) );

		if ( ! in_array( $new_status, $allowed, true ) ) {
			return new WP_Error( 'myeasybuy_invalid_status', __( 'Invalid order status.', 'myeasybuy-core' ), array( 'status' => 400 ) );
		}

		$order->update_status( str_replace( 'wc-', '', $new_status ), $note );

		return true;
	}

	/**
	 * Normalize status for hook payloads (always wc- prefixed).
	 *
	 * @param string $status Raw status.
	 */
	private static function normalize_hook_status( string $status ): string {
		return MyEasyBuy_Order_Statuses::normalize_status( $status );
	}
}
