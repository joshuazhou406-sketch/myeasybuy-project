<?php
/**
 * Consolidation packing and second-payment (international shipping) logic.
 *
 * @package MyEasyBuy_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles shipping rate calculation, second-payment orders, and payment callbacks.
 */
class MyEasyBuy_Consolidation {

	public const CHANNEL_AIR = 'canada_air_express';
	public const CHANNEL_SEA = 'canada_sea_freight';

	public const META_CONSOLIDATED_WEIGHT  = '_myeasybuy_consolidated_weight_kg';
	public const META_CONSOLIDATED_VOLUME  = '_myeasybuy_consolidated_volume_cm3';
	public const META_SHIPPING_CHANNEL     = '_myeasybuy_shipping_channel';
	public const META_SECOND_PAYMENT_FEE   = '_myeasybuy_second_payment_fee';
	public const META_SECOND_PAYMENT_ORDER = '_myeasybuy_second_payment_order_id';
	public const META_PARENT_ORDER_ID      = '_myeasybuy_parent_order_id';
	public const META_PAYMENT_TYPE         = '_myeasybuy_payment_type';
	public const META_SECOND_PAYMENT_PAID  = '_myeasybuy_second_payment_paid';

	public const PAYMENT_TYPE_SECOND = 'second';
	public const FEE_LINE_NAME       = 'International Shipping Fee (二价国际运费)';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'handle_payment_complete' ), 20 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'handle_payment_complete' ), 20 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'handle_payment_complete' ), 20 );
		add_action( 'woocommerce_account_dashboard', array( __CLASS__, 'render_customer_second_payment_notice' ), 15 );
	}

	/**
	 * Available shipping channels.
	 *
	 * @return array<string, string>
	 */
	public static function get_channels(): array {
		$channels = array(
			self::CHANNEL_AIR => __( '加拿大空运专线', 'myeasybuy-core' ),
			self::CHANNEL_SEA => __( '海运大包', 'myeasybuy-core' ),
		);

		/**
		 * Filter available consolidation shipping channels.
		 *
		 * @since 1.3.0
		 *
		 * @param array<string, string> $channels Channel slug => label.
		 */
		return apply_filters( 'myeasybuy_shipping_channels', $channels );
	}

	/**
	 * Calculate international shipping fee.
	 *
	 * @param float  $weight_kg  Actual weight in kilograms.
	 * @param float  $volume_cm3 Volume in cubic centimeters.
	 * @param string $channel    Shipping channel slug.
	 */
	public static function calculate_shipping_rate( float $weight_kg, float $volume_cm3, string $channel ): float {
		$weight_kg  = max( 0.0, $weight_kg );
		$volume_cm3 = max( 0.0, $volume_cm3 );
		$default    = self::default_shipping_rate( $weight_kg, $volume_cm3, $channel );

		/**
		 * Filter consolidated international shipping fee.
		 *
		 * @since 1.3.0
		 *
		 * @param float  $fee        Calculated fee in store currency.
		 * @param float  $weight_kg  Billable weight input (kg).
		 * @param float  $volume_cm3 Package volume (cm³).
		 * @param string $channel    Channel slug.
		 */
		$fee = apply_filters( 'myeasybuy_shipping_rate_calculator', $default, $weight_kg, $volume_cm3, $channel );

		return max( 0.0, round( (float) $fee, wc_get_price_decimals() ) );
	}

	/**
	 * Default shipping algorithms for air and sea channels.
	 *
	 * @param float  $weight_kg  Actual weight (kg).
	 * @param float  $volume_cm3 Volume (cm³).
	 * @param string $channel    Channel slug.
	 */
	public static function default_shipping_rate( float $weight_kg, float $volume_cm3, string $channel ): float {
		if ( self::CHANNEL_AIR === $channel ) {
			$volumetric_kg = $volume_cm3 / 6000;
			$billable_kg   = max( $weight_kg, $volumetric_kg );

			return self::calculate_tiered_fee( $billable_kg, 0.5, 15.0, 0.5, 6.0 );
		}

		if ( self::CHANNEL_SEA === $channel ) {
			return self::calculate_tiered_fee( $weight_kg, 1.0, 10.0, 1.0, 3.0 );
		}

		return 0.0;
	}

	/**
	 * Tiered weight-based fee calculation.
	 *
	 * @param float $weight_kg   Billable weight.
	 * @param float $first_unit  First weight unit (kg).
	 * @param float $first_price First tier price.
	 * @param float $add_unit    Additional weight unit (kg).
	 * @param float $add_price   Price per additional unit.
	 */
	public static function calculate_tiered_fee(
		float $weight_kg,
		float $first_unit,
		float $first_price,
		float $add_unit,
		float $add_price
	): float {
		if ( $weight_kg <= 0 || $first_unit <= 0 || $add_unit <= 0 ) {
			return 0.0;
		}

		$fee = $first_price;

		if ( $weight_kg > $first_unit ) {
			$extra      = $weight_kg - $first_unit;
			$increments = (int) ceil( $extra / $add_unit );
			$fee       += $increments * $add_price;
		}

		return $fee;
	}

	/**
	 * Customer multi-select consolidation request from inbound packages.
	 *
	 * @param int[]  $order_ids  Selected parent order IDs.
	 * @param string $channel    Preferred shipping channel slug.
	 * @param int    $user_id    Requesting customer user ID.
	 * @return array{group_id: string, count: int, order_ids: int[]}|WP_Error
	 */
	public static function request_customer_consolidation( array $order_ids, string $channel, int $user_id ) {
		$order_ids = array_values( array_unique( array_filter( array_map( 'absint', $order_ids ) ) ) );

		if ( empty( $order_ids ) ) {
			return new WP_Error(
				'myeasybuy_no_packages',
				__( '请至少勾选一个已入库包裹。', 'myeasybuy-core' ),
				array( 'status' => 400 )
			);
		}

		if ( ! array_key_exists( $channel, self::get_channels() ) ) {
			return new WP_Error(
				'myeasybuy_invalid_channel',
				__( '请选择有效的物流渠道。', 'myeasybuy-core' ),
				array( 'status' => 400 )
			);
		}

		if ( $user_id <= 0 ) {
			return new WP_Error(
				'myeasybuy_not_logged_in',
				__( '请先登录。', 'myeasybuy-core' ),
				array( 'status' => 401 )
			);
		}

		$orders = array();
		foreach ( $order_ids as $oid ) {
			$order = wc_get_order( $oid );
			if ( ! $order instanceof WC_Order ) {
				return new WP_Error(
					'myeasybuy_invalid_order',
					sprintf(
						/* translators: %d: order id */
						__( '订单 #%d 不存在。', 'myeasybuy-core' ),
						$oid
					),
					array( 'status' => 404 )
				);
			}

			if ( (int) $order->get_customer_id() !== $user_id ) {
				return new WP_Error(
					'myeasybuy_forbidden_order',
					sprintf(
						/* translators: %d: order id */
						__( '订单 #%d 不属于当前账户。', 'myeasybuy-core' ),
						$oid
					),
					array( 'status' => 403 )
				);
			}

			if ( self::PAYMENT_TYPE_SECOND === $order->get_meta( self::META_PAYMENT_TYPE, true ) ) {
				return new WP_Error(
					'myeasybuy_fee_order',
					sprintf(
						/* translators: %d: order id */
						__( '订单 #%d 为运费账单，不能合箱。', 'myeasybuy-core' ),
						$oid
					),
					array( 'status' => 400 )
				);
			}

			$status = MyEasyBuy_Order_Statuses::normalize_status( $order->get_status() );
			if ( MyEasyBuy_Order_Statuses::STATUS_IN_WAREHOUSE !== $status ) {
				return new WP_Error(
					'myeasybuy_invalid_status',
					sprintf(
						/* translators: %d: order id */
						__( '订单 #%d 尚未入库，无法合箱。', 'myeasybuy-core' ),
						$oid
					),
					array( 'status' => 400 )
				);
			}

			$orders[] = $order;
		}

		$group_id = 'cg-' . gmdate( 'YmdHis' ) . '-' . strtolower( wp_generate_password( 6, false, false ) );
		$channel_label = self::get_channels()[ $channel ];
		$now = current_time( 'mysql' );

		foreach ( $orders as $order ) {
			$order->update_meta_data( MyEasyBuy_Customer_Consolidation::META_GROUP_ID, $group_id );
			$order->update_meta_data( MyEasyBuy_Customer_Consolidation::META_REQUESTED_CHANNEL, $channel );
			$order->update_meta_data( MyEasyBuy_Customer_Consolidation::META_REQUESTED_AT, $now );
			$order->update_meta_data( MyEasyBuy_Customer_Consolidation::META_REQUESTED_BY, $user_id );
			// Prefill warehouse form with customer preference until second payment is generated.
			$order->update_meta_data( self::META_SHIPPING_CHANNEL, $channel );
			$order->update_status(
				str_replace( 'wc-', '', MyEasyBuy_Order_Statuses::STATUS_CONSOLIDATING ),
				sprintf(
					/* translators: 1: group id, 2: channel label, 3: package count */
					__( '客户提交合箱申请（批次 %1$s，渠道：%2$s，共 %3$d 个包裹）。', 'myeasybuy-core' ),
					$group_id,
					$channel_label,
					count( $orders )
				)
			);
			$order->save();
		}

		/**
		 * Fires after a customer consolidation request is accepted.
		 *
		 * @since 1.7.0
		 *
		 * @param string   $group_id Consolidation group id.
		 * @param int[]    $order_ids Order IDs in the group.
		 * @param string   $channel   Channel slug.
		 * @param int      $user_id   Customer user ID.
		 */
		do_action( 'myeasybuy_customer_consolidation_requested', $group_id, $order_ids, $channel, $user_id );

		return array(
			'group_id'  => $group_id,
			'count'     => count( $orders ),
			'order_ids' => $order_ids,
		);
	}

	/**
	 * Generate second-payment order and mark parent as awaiting shipping fee.
	 *
	 * @param int    $order_id   Parent WooCommerce order ID.
	 * @param float  $weight_kg  Consolidated weight (kg).
	 * @param float  $volume_cm3 Consolidated volume (cm³).
	 * @param string $channel    Shipping channel slug.
	 * @return int|WP_Error Second-payment order ID.
	 */
	public static function generate_second_payment( int $order_id, float $weight_kg, float $volume_cm3, string $channel ) {
		$parent = wc_get_order( $order_id );
		if ( ! $parent ) {
			return new WP_Error( 'myeasybuy_invalid_order', __( 'Order not found.', 'myeasybuy-core' ), array( 'status' => 404 ) );
		}

		$allowed_statuses = array(
			MyEasyBuy_Order_Statuses::STATUS_CONSOLIDATING,
			MyEasyBuy_Order_Statuses::STATUS_IN_WAREHOUSE,
			MyEasyBuy_Order_Statuses::STATUS_AWAITING_SHIPPING_FEE,
		);

		$current = MyEasyBuy_Order_Statuses::normalize_status( $parent->get_status() );
		if ( ! in_array( $current, $allowed_statuses, true ) ) {
			return new WP_Error(
				'myeasybuy_invalid_status',
				__( 'Order is not eligible for consolidation billing.', 'myeasybuy-core' ),
				array( 'status' => 400 )
			);
		}

		if ( ! array_key_exists( $channel, self::get_channels() ) ) {
			return new WP_Error(
				'myeasybuy_invalid_channel',
				__( 'Invalid shipping channel.', 'myeasybuy-core' ),
				array( 'status' => 400 )
			);
		}

		if ( $weight_kg <= 0 || $volume_cm3 <= 0 ) {
			return new WP_Error(
				'myeasybuy_invalid_package',
				__( 'Weight and volume must be greater than zero.', 'myeasybuy-core' ),
				array( 'status' => 400 )
			);
		}

		$fee = self::calculate_shipping_rate( $weight_kg, $volume_cm3, $channel );
		if ( $fee <= 0 ) {
			return new WP_Error(
				'myeasybuy_zero_fee',
				__( 'Calculated shipping fee must be greater than zero.', 'myeasybuy-core' ),
				array( 'status' => 400 )
			);
		}

		self::remove_unpaid_second_payment_order( $parent );

		$child = wc_create_order(
			array(
				'customer_id' => $parent->get_customer_id(),
				'status'      => 'pending',
			)
		);

		if ( is_wp_error( $child ) ) {
			return $child;
		}

		$fee_item = new WC_Order_Item_Fee();
		$fee_item->set_name( self::FEE_LINE_NAME );
		$fee_item->set_amount( $fee );
		$fee_item->set_total( $fee );
		$fee_item->set_tax_status( 'none' );
		$child->add_item( $fee_item );

		$child->set_billing_address( $parent->get_address( 'billing' ) );
		$child->set_shipping_address( $parent->get_address( 'shipping' ) );
		$child->set_currency( $parent->get_currency() );

		$child->update_meta_data( self::META_PARENT_ORDER_ID, $order_id );
		$child->update_meta_data( self::META_PAYMENT_TYPE, self::PAYMENT_TYPE_SECOND );
		$child->update_meta_data( self::META_SHIPPING_CHANNEL, $channel );
		$child->update_meta_data( self::META_CONSOLIDATED_WEIGHT, $weight_kg );
		$child->update_meta_data( self::META_CONSOLIDATED_VOLUME, $volume_cm3 );

		$child->calculate_totals();
		$child->save();

		$parent->update_meta_data( self::META_CONSOLIDATED_WEIGHT, $weight_kg );
		$parent->update_meta_data( self::META_CONSOLIDATED_VOLUME, $volume_cm3 );
		$parent->update_meta_data( self::META_SHIPPING_CHANNEL, $channel );
		$parent->update_meta_data( self::META_SECOND_PAYMENT_FEE, $fee );
		$parent->update_meta_data( self::META_SECOND_PAYMENT_ORDER, $child->get_id() );
		$parent->update_meta_data( self::META_SECOND_PAYMENT_PAID, 'no' );
		$parent->save();

		MyEasyBuy_Hooks::update_order_status(
			$order_id,
			MyEasyBuy_Order_Statuses::STATUS_AWAITING_SHIPPING_FEE,
			sprintf(
				/* translators: 1: fee amount, 2: second payment order ID */
				__( 'Second payment generated: %1$s (Order #%2$d).', 'myeasybuy-core' ),
				wc_price( $fee, array( 'currency' => $parent->get_currency() ) ),
				$child->get_id()
			)
		);

		$sibling_ids = self::apply_second_payment_to_group_siblings(
			$parent,
			(int) $child->get_id(),
			$fee,
			$weight_kg,
			$volume_cm3,
			$channel
		);

		$all_parent_ids = array_values( array_unique( array_merge( array( $order_id ), $sibling_ids ) ) );
		$child->update_meta_data( '_myeasybuy_consolidation_parent_ids', $all_parent_ids );
		$group_id = (string) $parent->get_meta( MyEasyBuy_Customer_Consolidation::META_GROUP_ID, true );
		if ( '' !== $group_id ) {
			$child->update_meta_data( MyEasyBuy_Customer_Consolidation::META_GROUP_ID, $group_id );
		}
		$child->save();

		/**
		 * Fires after a second-payment order is created for consolidated shipping.
		 *
		 * @since 1.3.0
		 *
		 * @param int    $order_id            Parent order ID.
		 * @param int    $second_payment_id   Child payment order ID.
		 * @param float  $fee                 Shipping fee amount.
		 * @param string $channel             Shipping channel slug.
		 */
		do_action( 'myeasybuy_second_payment_generated', $order_id, $child->get_id(), $fee, $channel );

		self::notify_customer_second_payment( $parent, (int) $child->get_id(), $fee, $channel, $all_parent_ids );

		return (int) $child->get_id();
	}

	/**
	 * Email customer when international shipping fee bill is ready.
	 *
	 * @param WC_Order $parent         Primary parent order.
	 * @param int      $child_id       Second-payment order ID.
	 * @param float    $fee            Fee amount.
	 * @param string   $channel        Channel slug.
	 * @param int[]    $all_parent_ids Related parent order IDs.
	 */
	private static function notify_customer_second_payment( WC_Order $parent, int $child_id, float $fee, string $channel, array $all_parent_ids ): void {
		$email = $parent->get_billing_email();
		if ( ! is_email( $email ) ) {
			return;
		}

		$child = wc_get_order( $child_id );
		if ( ! $child ) {
			return;
		}

		$channel_label = self::get_channels()[ $channel ] ?? $channel;
		$order_list    = implode(
			', ',
			array_map(
				static function ( $id ) {
					return '#' . (int) $id;
				},
				$all_parent_ids
			)
		);

		$subject = sprintf(
			/* translators: %s: parent order number */
			__( '[MyEasyBuy] 国际运费二价待支付 — 订单 %s', 'myeasybuy-core' ),
			$order_list
		);

		$body  = '<div style="font-family:Arial,sans-serif;color:#222;line-height:1.6;">';
		$body .= '<p>' . esc_html__( '您好，仓库已完成合箱称重，国际运费二价账单已生成。', 'myeasybuy-core' ) . '</p>';
		$body .= '<ul>';
		$body .= '<li><strong>' . esc_html__( '相关订单', 'myeasybuy-core' ) . ':</strong> ' . esc_html( $order_list ) . '</li>';
		$body .= '<li><strong>' . esc_html__( '物流渠道', 'myeasybuy-core' ) . ':</strong> ' . esc_html( $channel_label ) . '</li>';
		$body .= '<li><strong>' . esc_html__( '应付金额', 'myeasybuy-core' ) . ':</strong> ' . wp_kses_post( wc_price( $fee, array( 'currency' => $parent->get_currency() ) ) ) . '</li>';
		$body .= '</ul>';
		$body .= '<p><a href="' . esc_url( $child->get_checkout_payment_url() ) . '">' . esc_html__( '立即支付国际运费', 'myeasybuy-core' ) . '</a></p>';
		$body .= '</div>';

		/**
		 * Filter whether to send the second-payment email.
		 *
		 * @since 1.8.0
		 *
		 * @param bool     $send   Whether to send.
		 * @param WC_Order $parent Parent order.
		 * @param int      $child_id Child payment order ID.
		 */
		if ( (bool) apply_filters( 'myeasybuy_send_second_payment_email', true, $parent, $child_id ) ) {
			wp_mail( $email, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
		}
	}

	/**
	 * Orders that share the same customer consolidation group.
	 *
	 * @param WC_Order $order Source order.
	 * @return WC_Order[]
	 */
	public static function get_group_sibling_orders( WC_Order $order ): array {
		$group_id = (string) $order->get_meta( MyEasyBuy_Customer_Consolidation::META_GROUP_ID, true );
		if ( '' === $group_id ) {
			return array();
		}

		$ids = wc_get_orders(
			array(
				'limit'      => 50,
				'return'     => 'ids',
				'meta_key'   => MyEasyBuy_Customer_Consolidation::META_GROUP_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $group_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		$siblings = array();
		$source_id = (int) $order->get_id();
		foreach ( (array) $ids as $oid ) {
			$oid = (int) $oid;
			if ( $oid <= 0 || $oid === $source_id ) {
				continue;
			}
			$sibling = wc_get_order( $oid );
			if ( $sibling instanceof WC_Order ) {
				$siblings[] = $sibling;
			}
		}

		return $siblings;
	}

	/**
	 * Mirror second-payment billing state onto other packages in the same consolidation batch.
	 *
	 * @param WC_Order $primary     Order used to generate the fee bill.
	 * @param int      $child_id    Second-payment order ID.
	 * @param float    $fee         Fee amount.
	 * @param float    $weight_kg   Consolidated weight.
	 * @param float    $volume_cm3  Consolidated volume.
	 * @param string   $channel     Channel slug.
	 * @return int[] Sibling order IDs updated.
	 */
	public static function apply_second_payment_to_group_siblings(
		WC_Order $primary,
		int $child_id,
		float $fee,
		float $weight_kg,
		float $volume_cm3,
		string $channel
	): array {
		$updated = array();
		$primary_id = (int) $primary->get_id();

		foreach ( self::get_group_sibling_orders( $primary ) as $sibling ) {
			$sid = (int) $sibling->get_id();
			$status = MyEasyBuy_Order_Statuses::normalize_status( $sibling->get_status() );

			// Skip fee-only children and already-shipped packages.
			if ( self::PAYMENT_TYPE_SECOND === $sibling->get_meta( self::META_PAYMENT_TYPE, true ) ) {
				continue;
			}

			$terminal = array(
				MyEasyBuy_Order_Statuses::STATUS_PAID_SHIPPING,
				MyEasyBuy_Order_Statuses::STATUS_INT_SHIPPED,
			);
			if ( in_array( $status, $terminal, true ) && 'yes' === $sibling->get_meta( self::META_SECOND_PAYMENT_PAID, true ) ) {
				continue;
			}

			$sibling->update_meta_data( self::META_CONSOLIDATED_WEIGHT, $weight_kg );
			$sibling->update_meta_data( self::META_CONSOLIDATED_VOLUME, $volume_cm3 );
			$sibling->update_meta_data( self::META_SHIPPING_CHANNEL, $channel );
			$sibling->update_meta_data( self::META_SECOND_PAYMENT_FEE, $fee );
			$sibling->update_meta_data( self::META_SECOND_PAYMENT_ORDER, $child_id );
			$sibling->update_meta_data( self::META_SECOND_PAYMENT_PAID, 'no' );
			$sibling->save();

			MyEasyBuy_Hooks::update_order_status(
				$sid,
				MyEasyBuy_Order_Statuses::STATUS_AWAITING_SHIPPING_FEE,
				sprintf(
					/* translators: 1: primary order ID, 2: fee amount, 3: second payment order ID */
					__( '同批次合箱二价已随订单 #%1$d 生成：%2$s（支付订单 #%3$d）。', 'myeasybuy-core' ),
					$primary_id,
					wc_price( $fee, array( 'currency' => $primary->get_currency() ) ),
					$child_id
				)
			);

			$updated[] = $sid;
		}

		return $updated;
	}

	/**
	 * Repair stuck consolidating siblings when another package in the batch already has a fee bill.
	 *
	 * @param WC_Order $order Candidate order still on the consolidation queue.
	 * @return bool True when this order was reconciled away from the packing queue.
	 */
	public static function reconcile_group_billing_from_siblings( WC_Order $order ): bool {
		$queue_statuses = array(
			MyEasyBuy_Order_Statuses::STATUS_CONSOLIDATING,
			MyEasyBuy_Order_Statuses::STATUS_IN_WAREHOUSE,
		);
		$status = MyEasyBuy_Order_Statuses::normalize_status( $order->get_status() );

		$own_child = (int) $order->get_meta( self::META_SECOND_PAYMENT_ORDER, true );
		if ( $own_child > 0 ) {
			if ( in_array( $status, $queue_statuses, true ) ) {
				$paid = (string) $order->get_meta( self::META_SECOND_PAYMENT_PAID, true );
				MyEasyBuy_Hooks::update_order_status(
					(int) $order->get_id(),
					( 'yes' === $paid )
						? MyEasyBuy_Order_Statuses::STATUS_PAID_SHIPPING
						: MyEasyBuy_Order_Statuses::STATUS_AWAITING_SHIPPING_FEE,
					__( '合箱二价已生成，订单已移出合箱打包工单。', 'myeasybuy-core' )
				);
			}
			return true;
		}

		foreach ( self::get_group_sibling_orders( $order ) as $sibling ) {
			$child_id = (int) $sibling->get_meta( self::META_SECOND_PAYMENT_ORDER, true );
			if ( $child_id <= 0 ) {
				continue;
			}

			$fee       = (float) $sibling->get_meta( self::META_SECOND_PAYMENT_FEE, true );
			$weight_kg = (float) $sibling->get_meta( self::META_CONSOLIDATED_WEIGHT, true );
			$volume    = (float) $sibling->get_meta( self::META_CONSOLIDATED_VOLUME, true );
			$channel   = (string) $sibling->get_meta( self::META_SHIPPING_CHANNEL, true );
			$paid      = (string) $sibling->get_meta( self::META_SECOND_PAYMENT_PAID, true );

			$order->update_meta_data( self::META_CONSOLIDATED_WEIGHT, $weight_kg );
			$order->update_meta_data( self::META_CONSOLIDATED_VOLUME, $volume );
			$order->update_meta_data( self::META_SHIPPING_CHANNEL, $channel );
			$order->update_meta_data( self::META_SECOND_PAYMENT_FEE, $fee );
			$order->update_meta_data( self::META_SECOND_PAYMENT_ORDER, $child_id );
			$order->update_meta_data( self::META_SECOND_PAYMENT_PAID, $paid ? $paid : 'no' );
			$order->save();

			$target_status = ( 'yes' === $paid )
				? MyEasyBuy_Order_Statuses::STATUS_PAID_SHIPPING
				: MyEasyBuy_Order_Statuses::STATUS_AWAITING_SHIPPING_FEE;

			MyEasyBuy_Hooks::update_order_status(
				(int) $order->get_id(),
				$target_status,
				sprintf(
					/* translators: 1: sibling order ID, 2: second payment order ID */
					__( '同批次订单 #%1$d 已生成二价，本单同步关联支付订单 #%2$d。', 'myeasybuy-core' ),
					(int) $sibling->get_id(),
					$child_id
				)
			);

			return true;
		}

		return false;
	}

	/**
	 * Record international shipment and update parent order status.
	 *
	 * @param int    $order_id        Parent order ID.
	 * @param string $carrier         Carrier name.
	 * @param string $tracking_number Tracking number.
	 * @return true|WP_Error
	 */
	public static function record_international_shipment( int $order_id, string $carrier, string $tracking_number ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'myeasybuy_invalid_order', __( 'Order not found.', 'myeasybuy-core' ), array( 'status' => 404 ) );
		}

		$status  = MyEasyBuy_Order_Statuses::normalize_status( $order->get_status() );
		$allowed = array(
			MyEasyBuy_Order_Statuses::STATUS_PAID_SHIPPING,
		);

		if ( ! in_array( $status, $allowed, true ) ) {
			return new WP_Error(
				'myeasybuy_invalid_status',
				__( 'Order is not ready for international shipment.', 'myeasybuy-core' ),
				array( 'status' => 400 )
			);
		}

		$carrier         = sanitize_text_field( $carrier );
		$tracking_number = sanitize_text_field( $tracking_number );

		if ( '' === $carrier || '' === $tracking_number ) {
			return new WP_Error(
				'myeasybuy_missing_tracking',
				__( 'Carrier and tracking number are required.', 'myeasybuy-core' ),
				array( 'status' => 400 )
			);
		}

		MyEasyBuy_Hooks::dispatch_international_tracking_added( $order_id, $carrier, $tracking_number );

		$order->update_meta_data( '_myeasybuy_int_carrier', $carrier );
		$order->update_meta_data( '_myeasybuy_int_tracking_number', $tracking_number );
		$order->save();

		MyEasyBuy_Hooks::update_order_status(
			$order_id,
			MyEasyBuy_Order_Statuses::STATUS_INT_SHIPPED,
			sprintf(
				/* translators: 1: carrier, 2: tracking number */
				__( 'International shipment: %1$s / %2$s', 'myeasybuy-core' ),
				$carrier,
				$tracking_number
			)
		);

		/**
		 * Fires when an order is dispatched internationally (legacy hook).
		 *
		 * @since 1.0.0
		 */
		do_action(
			MyEasyBuy_Hooks::ACTION_INTERNATIONAL_SHIPPED,
			$order_id,
			$tracking_number,
			$carrier
		);

		return true;
	}

	/**
	 * Handle WooCommerce payment complete for second-payment child orders.
	 *
	 * @param int $order_id Paid order ID.
	 */
	public static function handle_payment_complete( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		if ( self::PAYMENT_TYPE_SECOND !== $order->get_meta( self::META_PAYMENT_TYPE, true ) ) {
			return;
		}

		if ( ! $order->is_paid() ) {
			return;
		}

		$parent_id = (int) $order->get_meta( self::META_PARENT_ORDER_ID, true );
		if ( $parent_id <= 0 ) {
			return;
		}

		$parent = wc_get_order( $parent_id );
		if ( ! $parent ) {
			return;
		}

		if ( 'yes' === $parent->get_meta( self::META_SECOND_PAYMENT_PAID, true ) ) {
			return;
		}

		$parent->update_meta_data( self::META_SECOND_PAYMENT_PAID, 'yes' );
		$parent->save();

		MyEasyBuy_Hooks::update_order_status(
			$parent_id,
			MyEasyBuy_Order_Statuses::STATUS_PAID_SHIPPING,
			sprintf(
				/* translators: %d: second payment order ID */
				__( 'Second payment completed via order #%d.', 'myeasybuy-core' ),
				$order_id
			)
		);

		foreach ( self::get_group_sibling_orders( $parent ) as $sibling ) {
			if ( self::PAYMENT_TYPE_SECOND === $sibling->get_meta( self::META_PAYMENT_TYPE, true ) ) {
				continue;
			}

			$sibling_child = (int) $sibling->get_meta( self::META_SECOND_PAYMENT_ORDER, true );
			if ( $sibling_child > 0 && $sibling_child !== $order_id ) {
				continue;
			}

			if ( 'yes' === $sibling->get_meta( self::META_SECOND_PAYMENT_PAID, true ) ) {
				continue;
			}

			$sibling->update_meta_data( self::META_SECOND_PAYMENT_ORDER, $order_id );
			$sibling->update_meta_data( self::META_SECOND_PAYMENT_PAID, 'yes' );
			$sibling->save();

			MyEasyBuy_Hooks::update_order_status(
				(int) $sibling->get_id(),
				MyEasyBuy_Order_Statuses::STATUS_PAID_SHIPPING,
				sprintf(
					/* translators: 1: primary parent order ID, 2: second payment order ID */
					__( '同批次国际运费已随订单 #%1$d 支付完成（支付订单 #%2$d）。', 'myeasybuy-core' ),
					$parent_id,
					$order_id
				)
			);
		}

		/**
		 * Fires when second payment (international shipping fee) is paid.
		 *
		 * @since 1.3.0
		 *
		 * @param int $parent_order_id Parent order ID.
		 * @param int $payment_order_id Second-payment order ID.
		 */
		do_action( 'myeasybuy_second_payment_completed', $parent_id, $order_id );
	}

	/**
	 * Show pending second-payment notice on customer My Account dashboard.
	 */
	public static function render_customer_second_payment_notice(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$orders = wc_get_orders(
			array(
				'customer_id' => get_current_user_id(),
				'status'      => array( str_replace( 'wc-', '', MyEasyBuy_Order_Statuses::STATUS_AWAITING_SHIPPING_FEE ) ),
				'limit'       => 10,
			)
		);

		if ( empty( $orders ) ) {
			return;
		}

		echo '<div class="woocommerce-message myeasybuy-second-payment-notice">';
		echo '<p><strong>' . esc_html__( 'International shipping payment required', 'myeasybuy-core' ) . '</strong></p>';
		echo '<ul>';

		$seen_children = array();

		foreach ( $orders as $parent ) {
			$child_id = (int) $parent->get_meta( self::META_SECOND_PAYMENT_ORDER, true );
			if ( $child_id <= 0 || isset( $seen_children[ $child_id ] ) ) {
				continue;
			}

			$child = wc_get_order( $child_id );
			if ( ! $child || ! $child->needs_payment() ) {
				continue;
			}

			$seen_children[ $child_id ] = true;
			$parent_ids                 = $child->get_meta( '_myeasybuy_consolidation_parent_ids', true );
			if ( ! is_array( $parent_ids ) || empty( $parent_ids ) ) {
				$parent_ids = array( (int) $parent->get_id() );
				foreach ( self::get_group_sibling_orders( $parent ) as $sibling ) {
					$parent_ids[] = (int) $sibling->get_id();
				}
				$parent_ids = array_values( array_unique( array_filter( array_map( 'absint', $parent_ids ) ) ) );
			}

			$order_list = implode(
				', ',
				array_map(
					static function ( $id ) {
						return '#' . (int) $id;
					},
					$parent_ids
				)
			);

			printf(
				'<li>%1$s <a class="button" href="%2$s">%3$s</a></li>',
				sprintf(
					/* translators: 1: parent order numbers, 2: formatted fee */
					esc_html__( '订单 %1$s — 国际运费 %2$s', 'myeasybuy-core' ),
					esc_html( $order_list ),
					wp_strip_all_tags( wc_price( (float) $child->get_total(), array( 'currency' => $child->get_currency() ) ) )
				),
				esc_url( $child->get_checkout_payment_url() ),
				esc_html__( '立即支付', 'myeasybuy-core' )
			);
		}

		echo '</ul></div>';
	}

	/**
	 * Get second-payment order for a parent order.
	 *
	 * @param int $parent_order_id Parent order ID.
	 * @return WC_Order|null
	 */
	public static function get_second_payment_order( int $parent_order_id ): ?WC_Order {
		$parent = wc_get_order( $parent_order_id );
		if ( ! $parent ) {
			return null;
		}

		$child_id = (int) $parent->get_meta( self::META_SECOND_PAYMENT_ORDER, true );
		if ( $child_id <= 0 ) {
			return null;
		}

		$child = wc_get_order( $child_id );
		return $child instanceof WC_Order ? $child : null;
	}

	/**
	 * Delete an unpaid second-payment child order before regenerating.
	 *
	 * @param WC_Order $parent Parent order.
	 */
	private static function remove_unpaid_second_payment_order( WC_Order $parent ): void {
		$child_id = (int) $parent->get_meta( self::META_SECOND_PAYMENT_ORDER, true );
		if ( $child_id <= 0 ) {
			return;
		}

		$child = wc_get_order( $child_id );
		if ( ! $child || $child->is_paid() ) {
			return;
		}

		$child->delete( true );
	}
}
