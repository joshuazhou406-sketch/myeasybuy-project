<?php
/**
 * Wire OMS into myeasybuy-core and reverse-forwarding events.
 *
 * @package MyEasyBuy_OMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Automatic 一段 / 二段 triggers.
 */
class MyEasyBuy_OMS_Hooks {

	/**
	 * Register listeners.
	 */
	public static function init(): void {
		add_action( 'myeasybuy_order_status_changed', array( __CLASS__, 'on_order_status_changed' ), 20, 3 );
		add_action( 'myeasybuy_customer_consolidation_requested', array( __CLASS__, 'on_wc_consolidation_requested' ), 20, 4 );
		add_action( 'myeasybuy_second_payment_completed', array( __CLASS__, 'on_second_payment_completed' ), 20, 2 );
		add_action( 'wc_rf_package_declared', array( __CLASS__, 'on_rf_package_declared' ), 20, 3 );
		add_action( 'wc_rf_package_cancelled', array( __CLASS__, 'on_rf_package_cancelled' ), 20, 2 );
		add_action( 'wc_rf_consolidation_paid', array( __CLASS__, 'on_rf_consolidation_paid' ), 20, 2 );
		add_action( 'wc_rf_consolidation_requested', array( __CLASS__, 'on_rf_consolidation_requested' ), 20, 3 );
	}

	/**
	 * Domestic shipped → 一段预报.
	 *
	 * @param int    $order_id   Order ID.
	 * @param string $old_status Previous.
	 * @param string $new_status New.
	 */
	public static function on_order_status_changed( $order_id, $old_status, $new_status ): void {
		unset( $old_status );

		$service = MyEasyBuy_OMS_Service::instance();
		if ( ! $service->is_enabled() || empty( $service->get_settings()['auto_leg1'] ) ) {
			return;
		}

		if ( ! class_exists( 'MyEasyBuy_Order_Statuses' ) ) {
			return;
		}

		$new_status = MyEasyBuy_Order_Statuses::normalize_status( (string) $new_status );
		if ( MyEasyBuy_Order_Statuses::STATUS_CN_SHIPPED !== $new_status ) {
			return;
		}

		$result = $service->forecast_wc_order( (int) $order_id );
		if ( is_wp_error( $result ) ) {
			self::note_order( (int) $order_id, $result->get_error_message(), true );
			return;
		}

		self::note_order(
			(int) $order_id,
			sprintf(
				/* translators: 1: hawb, 2: fpx */
				__( '已向 4PX OMS 下发一段预报：%1$s / %2$s', 'meb-oms' ),
				(string) ( $result['customer_hawb_code'] ?? '' ),
				(string) ( $result['fpx_track_no'] ?? '-' )
			)
		);
	}

	/**
	 * Customer 合箱申请: tag group; optionally send 二段 immediately.
	 *
	 * @param string $group_id  Group.
	 * @param int[]  $order_ids Orders.
	 * @param string $channel   Channel.
	 * @param int    $user_id   User.
	 */
	public static function on_wc_consolidation_requested( $group_id, $order_ids, $channel, $user_id ): void {
		unset( $channel, $user_id );

		$service = MyEasyBuy_OMS_Service::instance();
		if ( ! $service->is_enabled() ) {
			return;
		}

		$service->tag_wc_group( (array) $order_ids, (string) $group_id );

		if ( empty( $service->get_settings()['leg2_on_request'] ) ) {
			return;
		}

		$result = $service->create_leg2_for_wc_group( array_map( 'absint', (array) $order_ids ), (string) $group_id );
		if ( is_wp_error( $result ) ) {
			self::note_order( (int) ( $order_ids[0] ?? 0 ), $result->get_error_message(), true );
		}
	}

	/**
	 * Second payment paid → 二段出库通知.
	 *
	 * @param int $parent_id Parent order.
	 * @param int $child_id  Fee order.
	 */
	public static function on_second_payment_completed( $parent_id, $child_id ): void {
		unset( $child_id );

		$service = MyEasyBuy_OMS_Service::instance();
		if ( ! $service->is_enabled() || empty( $service->get_settings()['auto_leg2'] ) ) {
			return;
		}

		$order = wc_get_order( (int) $parent_id );
		if ( ! $order ) {
			return;
		}

		$ids      = array( (int) $parent_id );
		$group_id = '';
		if ( class_exists( 'MyEasyBuy_Customer_Consolidation' ) ) {
			$group_id = (string) $order->get_meta( MyEasyBuy_Customer_Consolidation::META_GROUP_ID, true );
			if ( class_exists( 'MyEasyBuy_Consolidation' ) ) {
				foreach ( MyEasyBuy_Consolidation::get_group_sibling_orders( $order ) as $sibling ) {
					$ids[] = (int) $sibling->get_id();
				}
			}
		}

		$result = $service->create_leg2_for_wc_group( $ids, $group_id );
		if ( is_wp_error( $result ) ) {
			self::note_order( (int) $parent_id, $result->get_error_message(), true );
			return;
		}

		self::note_order(
			(int) $parent_id,
			sprintf(
				/* translators: %s: hawb */
				__( '已向 4PX OMS 下发二段合包出库通知：%s', 'meb-oms' ),
				(string) ( $result['customer_hawb_code'] ?? '' )
			)
		);
	}

	/**
	 * RF inbound declaration → 一段预报.
	 *
	 * @param int                  $package_id Package ID.
	 * @param int                  $user_id    Owner.
	 * @param array<string, mixed> $fields     Fields.
	 */
	public static function on_rf_package_declared( $package_id, $user_id, $fields ): void {
		unset( $user_id, $fields );

		$service = MyEasyBuy_OMS_Service::instance();
		if ( ! $service->is_enabled() || empty( $service->get_settings()['auto_leg1'] ) ) {
			return;
		}

		$service->forecast_rf_package( (int) $package_id );
	}

	/**
	 * RF cancel pending package → cancel 一段 if still P.
	 *
	 * @param int                  $package_id Package ID.
	 * @param array<string, mixed> $row        Package row.
	 */
	public static function on_rf_package_cancelled( $package_id, $row ): void {
		unset( $row );

		$service = MyEasyBuy_OMS_Service::instance();
		if ( ! $service->is_enabled() ) {
			return;
		}

		$oms = MyEasyBuy_OMS_Repository::get_by_source( MyEasyBuy_OMS_Service::SOURCE_RF, (int) $package_id, 1 );
		if ( $oms && 'P' === (string) $oms['oms_status'] && ! empty( $oms['fpx_track_no'] ) ) {
			$service->cancel_leg1( (int) $oms['id'] );
		}
	}

	/**
	 * RF buyer selected packages.
	 *
	 * @param array<int, array<string, mixed>> $packages Packages.
	 * @param int                              $user_id  User.
	 * @param int                              $consolidation_id Consolidation.
	 */
	public static function on_rf_consolidation_requested( $packages, $user_id, $consolidation_id ): void {
		unset( $user_id );

		$service = MyEasyBuy_OMS_Service::instance();
		if ( ! $service->is_enabled() || empty( $service->get_settings()['leg2_on_request'] ) ) {
			return;
		}

		$ids = array();
		foreach ( (array) $packages as $package ) {
			$ids[] = (int) ( $package['id'] ?? 0 );
		}

		$service->create_leg2_for_rf( $ids, (int) $consolidation_id );
	}

	/**
	 * RF fee paid → 二段.
	 *
	 * @param int      $consolidation_id Consolidation.
	 * @param WC_Order $order            Checkout order.
	 */
	public static function on_rf_consolidation_paid( $consolidation_id, $order ): void {
		$service = MyEasyBuy_OMS_Service::instance();
		if ( ! $service->is_enabled() || empty( $service->get_settings()['auto_leg2'] ) ) {
			return;
		}

		$ids = array();
		if ( class_exists( '\ReverseForwarding\Models\Package' ) ) {
			$packages = \ReverseForwarding\Models\Package::list_for_consolidation( (int) $consolidation_id );
			foreach ( $packages as $package ) {
				$ids[] = (int) $package['id'];
			}
		}

		if ( empty( $ids ) ) {
			return;
		}

		$result = $service->create_leg2_for_rf( $ids, (int) $consolidation_id );
		if ( is_wp_error( $result ) && $order instanceof WC_Order ) {
			$order->add_order_note( '[OMS] ' . $result->get_error_message() );
		}
	}

	/**
	 * Order note helper.
	 */
	private static function note_order( int $order_id, string $message, bool $error = false ): void {
		if ( $order_id <= 0 || '' === $message ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$order->add_order_note( ( $error ? '[OMS 失败] ' : '[OMS] ' ) . $message );
	}
}
