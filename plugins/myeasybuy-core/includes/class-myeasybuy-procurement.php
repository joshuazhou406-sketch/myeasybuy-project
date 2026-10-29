<?php
/**
 * JD Auto-Procurement Engine — queue jobs for RPA / purchasing agents.
 *
 * @package MyEasyBuy_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates and exposes JD procurement jobs after first-payment completion.
 */
class MyEasyBuy_Procurement {

	public const DB_VERSION        = '1.1.0';
	public const DB_VERSION_OPTION = 'myeasybuy_procurement_db_version';
	public const TABLE_SUFFIX      = 'myeasybuy_procurement_jobs';
	public const BACKFILL_OPTION   = 'myeasybuy_purchasing_backfill_v161';

	public const STATUS_PENDING    = 'pending';
	public const STATUS_PROCESSING = 'processing';
	public const STATUS_COMPLETED  = 'completed';
	public const STATUS_FAILED     = 'failed';

	public const META_CN_TRACKING_NO = '_myeasybuy_cn_tracking_no';
	public const META_JD_ORDER_SN    = '_myeasybuy_jd_order_sn';
	public const META_MEMBER_NO      = '_myeasybuy_member_no';
	public const ACTION_COMPLETED    = 'myeasybuy_auto_procurement_completed';

	public const OPTION_WAREHOUSE = 'myeasybuy_procurement_warehouse';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		self::maybe_upgrade_db();

		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'on_payment_complete' ), 30 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_status_ready_for_procurement' ), 30 );
		add_action( 'woocommerce_order_status_purchasing', array( __CLASS__, 'on_status_ready_for_procurement' ), 30 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_order_status_changed' ), 40, 4 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_warehouse_settings' ) );
		add_action( 'admin_menu', array( __CLASS__, 'maybe_redirect_legacy_warehouse_settings' ), 5 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_backfill_purchasing_orders' ), 40 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_repair_second_payment_rollbacks' ), 45 );
	}

	/**
	 * Create / upgrade custom table on activation.
	 */
	public static function activate(): void {
		self::create_table();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );

		if ( false === get_option( self::OPTION_WAREHOUSE, false ) ) {
			add_option( self::OPTION_WAREHOUSE, self::default_warehouse_address() );
		}
	}

	/**
	 * Ensure DB schema is current.
	 */
	private static function maybe_upgrade_db(): void {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}
		self::create_table();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Full table name with WP prefix.
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	/**
	 * Create procurement jobs table.
	 */
	public static function create_table(): void {
		global $wpdb;

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_id bigint(20) unsigned NOT NULL,
			order_item_id bigint(20) unsigned NOT NULL DEFAULT 0,
			product_id bigint(20) unsigned NOT NULL DEFAULT 0,
			platform varchar(16) NOT NULL DEFAULT 'jd',
			jd_sku_id varchar(64) NOT NULL DEFAULT '',
			jd_product_url text NULL,
			quantity int(11) NOT NULL DEFAULT 1,
			shipping_address longtext NULL,
			status varchar(32) NOT NULL DEFAULT 'pending',
			retry_count int(11) NOT NULL DEFAULT 0,
			jd_order_sn varchar(128) NOT NULL DEFAULT '',
			paid_amount decimal(12,2) NULL,
			currency varchar(8) NOT NULL DEFAULT 'CNY',
			error_message text NULL,
			agent_payload longtext NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			completed_at datetime NULL,
			PRIMARY KEY  (id),
			KEY order_id (order_id),
			KEY status (status),
			KEY platform (platform),
			KEY jd_sku_id (jd_sku_id),
			KEY order_item_id (order_item_id)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Default Shenzhen consolidation warehouse address.
	 *
	 * @return array<string, string>
	 */
	public static function default_warehouse_address(): array {
		return array(
			'first_name' => '深圳集运仓',
			'last_name'  => '',
			'company'    => 'MyEasyBuy Warehouse',
			'address_1'  => '宝安区福永街道怀德社区怀德南路',
			'address_2'  => '',
			'city'       => '深圳市',
			'state'      => '广东省',
			'postcode'   => '518103',
			'country'    => 'CN',
			'phone'      => '',
		);
	}

	/**
	 * WooCommerce payment complete — enqueue procurement jobs.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function on_payment_complete( $order_id ): void {
		self::enqueue_jobs_for_order( (int) $order_id );
	}

	/**
	 * Order entered processing / purchasing — enqueue if not already.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function on_status_ready_for_procurement( $order_id ): void {
		self::enqueue_jobs_for_order( (int) $order_id );
	}

	/**
	 * Create procurement jobs for marketplace-linked line items on an order.
	 *
	 * Always moves paid first-payment orders into 「平台代采中」 so buyer queues
	 * see them even when no auto-RPA job can be created (e.g. Taobao-only).
	 *
	 * @param int $order_id Order ID.
	 * @return array<int, int> Created job IDs.
	 */
	public static function enqueue_jobs_for_order( int $order_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array();
		}

		// Skip second-payment / fee-only child orders.
		if ( self::is_second_payment_order( $order ) ) {
			return array();
		}

		// Reverse-forwarding checkout is not a 代购 first payment.
		if ( self::is_reverse_forwarding_order( $order ) ) {
			return array();
		}

		if ( ! $order->is_paid() && ! in_array( $order->get_status(), array( 'processing', 'purchasing' ), true ) ) {
			return array();
		}

		$created = array();

		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$market = self::extract_marketplace_meta_from_item( $item );
			if ( empty( $market['item_id'] ) && empty( $market['product_url'] ) ) {
				continue;
			}

			if ( self::job_exists_for_item( $order_id, (int) $item_id ) ) {
				continue;
			}

			$shipping = self::build_shipping_address( $order );
			$job_id   = self::insert_job(
				array(
					'order_id'         => $order_id,
					'order_item_id'    => (int) $item_id,
					'product_id'       => (int) $item->get_product_id(),
					'platform'         => (string) $market['platform'],
					'jd_sku_id'        => (string) $market['item_id'],
					'jd_product_url'   => (string) $market['product_url'],
					'quantity'         => max( 1, (int) $item->get_quantity() ),
					'shipping_address' => wp_json_encode( $shipping, JSON_UNESCAPED_UNICODE ),
					'status'           => self::STATUS_PENDING,
					'retry_count'      => 0,
				)
			);

			if ( $job_id > 0 ) {
				$created[] = $job_id;
			}
		}

		// First payment received → always enter buyer procurement queue.
		self::mark_order_purchasing( $order_id, ! empty( $created ) );

		if ( ! empty( $created ) ) {
			$order->update_meta_data( '_myeasybuy_procurement_job_ids', $created );
			$order->save();

			/**
			 * Fires after procurement jobs are created for an order.
			 *
			 * @since 1.5.0
			 *
			 * @param int   $order_id Order ID.
			 * @param int[] $created  Job IDs.
			 */
			do_action( 'myeasybuy_procurement_jobs_created', $order_id, $created );
		}

		return $created;
	}

	/**
	 * Whether this is a second-payment (intl shipping fee) child order.
	 *
	 * @param WC_Order $order Order.
	 */
	private static function is_second_payment_order( WC_Order $order ): bool {
		return class_exists( 'MyEasyBuy_Consolidation' )
			&& MyEasyBuy_Consolidation::PAYMENT_TYPE_SECOND === $order->get_meta( MyEasyBuy_Consolidation::META_PAYMENT_TYPE, true );
	}

	/**
	 * Whether this is a wc-reverse-forwarding consolidation checkout order.
	 *
	 * @param WC_Order $order Order.
	 */
	private static function is_reverse_forwarding_order( WC_Order $order ): bool {
		return absint( $order->get_meta( '_wc_rf_consolidation_id' ) ) > 0;
	}

	/**
	 * Statuses at/after domestic express handoff (buyer procurement finished).
	 *
	 * @return string[]
	 */
	public static function statuses_past_buyer_procurement(): array {
		return array(
			MyEasyBuy_Order_Statuses::STATUS_CN_SHIPPED,
			MyEasyBuy_Order_Statuses::STATUS_IN_WAREHOUSE,
			MyEasyBuy_Order_Statuses::STATUS_CONSOLIDATING,
			MyEasyBuy_Order_Statuses::STATUS_AWAITING_SHIPPING_FEE,
			MyEasyBuy_Order_Statuses::STATUS_PAID_SHIPPING,
			MyEasyBuy_Order_Statuses::STATUS_INT_SHIPPED,
			'wc-completed',
		);
	}

	/**
	 * Whether the order has left the domestic buyer procurement stage.
	 *
	 * @param WC_Order|int $order Order or ID.
	 */
	public static function is_order_past_buyer_procurement( $order ): bool {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( (int) $order );
		}
		if ( ! $order instanceof WC_Order ) {
			return false;
		}

		$status = MyEasyBuy_Order_Statuses::normalize_status( $order->get_status() );
		return in_array( $status, self::statuses_past_buyer_procurement(), true );
	}

	/**
	 * Whether domestic express entry may still change order status.
	 *
	 * @param WC_Order|int $order Order or ID.
	 */
	public static function is_order_eligible_for_cn_shipping( $order ): bool {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( (int) $order );
		}
		if ( ! $order instanceof WC_Order || self::is_second_payment_order( $order ) ) {
			return false;
		}

		if ( self::is_order_past_buyer_procurement( $order ) ) {
			return false;
		}

		// Paid second fee must never be pulled back to cn-shipped.
		if ( class_exists( 'MyEasyBuy_Consolidation' )
			&& 'yes' === $order->get_meta( MyEasyBuy_Consolidation::META_SECOND_PAYMENT_PAID, true ) ) {
			return false;
		}

		$status = MyEasyBuy_Order_Statuses::normalize_status( $order->get_status() );
		return in_array(
			$status,
			array(
				MyEasyBuy_Order_Statuses::STATUS_PURCHASING,
				'wc-processing',
			),
			true
		);
	}

	/**
	 * Mark open procurement jobs completed when the order leaves buyer stage.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $reason   Optional note stored in error_message for audit.
	 * @return int Rows updated.
	 */
	public static function complete_open_jobs_for_order( int $order_id, string $reason = '' ): int {
		global $wpdb;

		$order_id = absint( $order_id );
		if ( $order_id <= 0 ) {
			return 0;
		}

		$table   = self::table_name();
		$open    = array( self::STATUS_PENDING, self::STATUS_PROCESSING, self::STATUS_FAILED );
		$in      = implode( ',', array_fill( 0, count( $open ), '%s' ) );
		$now     = current_time( 'mysql', true );
		$message = $reason !== ''
			? $reason
			: __( 'Auto-closed: order already past domestic procurement.', 'myeasybuy-core' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET status = %s,
					completed_at = %s,
					error_message = %s,
					updated_at = %s
				WHERE order_id = %d AND status IN ({$in})",
				array_merge(
					array(
						self::STATUS_COMPLETED,
						$now,
						$message,
						$now,
						$order_id,
					),
					$open
				)
			)
		);

		return is_numeric( $updated ) ? (int) $updated : 0;
	}

	/**
	 * Close stale jobs when WooCommerce status advances past buyer procurement.
	 *
	 * @param int      $order_id   Order ID.
	 * @param string   $from       Previous status.
	 * @param string   $to         New status.
	 * @param WC_Order $order      Order object.
	 */
	public static function on_order_status_changed( $order_id, $from, $to, $order ): void {
		unset( $from );
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( (int) $order_id );
		}
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$to = MyEasyBuy_Order_Statuses::normalize_status( (string) $to );
		if ( ! in_array( $to, self::statuses_past_buyer_procurement(), true ) ) {
			return;
		}

		self::complete_open_jobs_for_order(
			(int) $order->get_id(),
			sprintf(
				/* translators: %s: order status slug */
				__( 'Auto-closed after order moved to %s.', 'myeasybuy-core' ),
				$to
			)
		);
	}

	/**
	 * Restore orders that were incorrectly rolled back after second payment was paid.
	 *
	 * @param WC_Order $order Order.
	 * @return bool True when status was repaired.
	 */
	public static function maybe_repair_second_payment_rollback( WC_Order $order ): bool {
		if ( ! class_exists( 'MyEasyBuy_Consolidation' ) ) {
			return false;
		}
		if ( self::is_second_payment_order( $order ) ) {
			return false;
		}
		if ( 'yes' !== $order->get_meta( MyEasyBuy_Consolidation::META_SECOND_PAYMENT_PAID, true ) ) {
			return false;
		}

		$status = MyEasyBuy_Order_Statuses::normalize_status( $order->get_status() );
		$rolled_back = array(
			MyEasyBuy_Order_Statuses::STATUS_PURCHASING,
			MyEasyBuy_Order_Statuses::STATUS_CN_SHIPPED,
			MyEasyBuy_Order_Statuses::STATUS_IN_WAREHOUSE,
			MyEasyBuy_Order_Statuses::STATUS_CONSOLIDATING,
			MyEasyBuy_Order_Statuses::STATUS_AWAITING_SHIPPING_FEE,
			'wc-processing',
		);

		if ( ! in_array( $status, $rolled_back, true ) ) {
			return false;
		}

		$int_tracking = (string) $order->get_meta( '_myeasybuy_int_tracking_number', true );
		$target       = '' !== $int_tracking
			? MyEasyBuy_Order_Statuses::STATUS_INT_SHIPPED
			: MyEasyBuy_Order_Statuses::STATUS_PAID_SHIPPING;

		MyEasyBuy_Hooks::update_order_status(
			(int) $order->get_id(),
			$target,
			__( '已修复：二价已支付，国内发货录入不得回退流程状态。', 'myeasybuy-core' )
		);

		self::complete_open_jobs_for_order(
			(int) $order->get_id(),
			__( 'Auto-closed after restoring paid second-payment status.', 'myeasybuy-core' )
		);

		return true;
	}

	/**
	 * Move a paid first-payment order into 「平台代采中」 when appropriate.
	 *
	 * @param int  $order_id     Order ID.
	 * @param bool $jobs_queued  Whether auto jobs were created.
	 */
	public static function mark_order_purchasing( int $order_id, bool $jobs_queued = false ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order || self::is_second_payment_order( $order ) || self::is_reverse_forwarding_order( $order ) ) {
			return;
		}

		$current = MyEasyBuy_Order_Statuses::normalize_status( $order->get_status() );

		// Do not pull orders backward from later warehouse / shipping stages.
		$blocked = array(
			MyEasyBuy_Order_Statuses::STATUS_PURCHASING,
			MyEasyBuy_Order_Statuses::STATUS_CN_SHIPPED,
			MyEasyBuy_Order_Statuses::STATUS_IN_WAREHOUSE,
			MyEasyBuy_Order_Statuses::STATUS_CONSOLIDATING,
			MyEasyBuy_Order_Statuses::STATUS_AWAITING_SHIPPING_FEE,
			MyEasyBuy_Order_Statuses::STATUS_PAID_SHIPPING,
			MyEasyBuy_Order_Statuses::STATUS_INT_SHIPPED,
			'wc-completed',
			'wc-cancelled',
			'wc-refunded',
			'wc-failed',
			MyEasyBuy_Order_Statuses::STATUS_PENDING_MANUAL,
		);

		if ( in_array( $current, $blocked, true ) ) {
			return;
		}

		$note = $jobs_queued
			? __( 'First payment received; marketplace procurement jobs queued.', 'myeasybuy-core' )
			: __( 'First payment received; awaiting domestic buyer procurement.', 'myeasybuy-core' );

		MyEasyBuy_Hooks::update_order_status(
			$order_id,
			MyEasyBuy_Order_Statuses::STATUS_PURCHASING,
			$note
		);
	}

	/**
	 * One-time: move stuck paid 「processing」 first-payment orders into purchasing.
	 */
	public static function maybe_backfill_purchasing_orders(): void {
		if ( get_option( self::BACKFILL_OPTION ) === '1' ) {
			return;
		}

		if ( ! function_exists( 'wc_get_orders' ) ) {
			return;
		}

		$orders = wc_get_orders(
			array(
				'status' => array( 'processing' ),
				'limit'  => 200,
				'return' => 'objects',
			)
		);

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			if ( self::is_second_payment_order( $order ) ) {
				continue;
			}
			if ( ! $order->is_paid() ) {
				continue;
			}
			self::enqueue_jobs_for_order( (int) $order->get_id() );
		}

		update_option( self::BACKFILL_OPTION, '1', false );
	}

	/**
	 * One-time repair: orders with paid second fee that were rolled back to earlier statuses.
	 */
	public static function maybe_repair_second_payment_rollbacks(): void {
		$option = 'myeasybuy_repair_paid_shipping_v172';
		if ( get_option( $option ) === '1' ) {
			return;
		}

		if ( ! function_exists( 'wc_get_orders' ) || ! class_exists( 'MyEasyBuy_Consolidation' ) ) {
			return;
		}

		$orders = wc_get_orders(
			array(
				'status'     => array(
					'purchasing',
					'cn-shipped',
					'in-warehouse',
					'consolidating',
					'awaiting-shipping-fee',
					'processing',
				),
				'limit'      => 100,
				'return'     => 'objects',
				'meta_key'   => MyEasyBuy_Consolidation::META_SECOND_PAYMENT_PAID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		foreach ( $orders as $order ) {
			if ( $order instanceof WC_Order ) {
				self::maybe_repair_second_payment_rollback( $order );
			}
		}

		update_option( $option, '1', false );
	}

	/**
	 * Extract marketplace identifiers (JD / Taobao) from an order line item.
	 *
	 * @param WC_Order_Item_Product $item Line item.
	 * @return array{platform: string, item_id: string, product_url: string}
	 */
	public static function extract_marketplace_meta_from_item( WC_Order_Item_Product $item ): array {
		$jd = self::extract_jd_meta_from_item( $item );
		if ( ! empty( $jd['jd_sku_id'] ) || ! empty( $jd['jd_product_url'] ) ) {
			return array(
				'platform'    => 'jd',
				'item_id'     => (string) $jd['jd_sku_id'],
				'product_url' => (string) $jd['jd_product_url'],
			);
		}

		$product_id   = (int) $item->get_product_id();
		$variation_id = (int) $item->get_variation_id();

		$tb_id  = '';
		$tb_url = '';
		$id_keys = array( '_ppw_tb_num_iid', '_tb_num_iid', '_myeasybuy_tb_num_iid' );
		$url_keys = array( '_ppw_tb_source_url', '_tb_product_url', '_myeasybuy_tb_source_url' );

		foreach ( $id_keys as $key ) {
			$val = (string) $item->get_meta( $key, true );
			if ( '' === $val && $variation_id ) {
				$val = (string) get_post_meta( $variation_id, $key, true );
			}
			if ( '' === $val && $product_id ) {
				$val = (string) get_post_meta( $product_id, $key, true );
			}
			if ( '' !== $val ) {
				$tb_id = preg_replace( '/\D/', '', $val ) ?: $val;
				break;
			}
		}

		foreach ( $url_keys as $key ) {
			$val = (string) $item->get_meta( $key, true );
			if ( '' === $val && $variation_id ) {
				$val = (string) get_post_meta( $variation_id, $key, true );
			}
			if ( '' === $val && $product_id ) {
				$val = (string) get_post_meta( $product_id, $key, true );
			}
			if ( '' !== $val ) {
				$tb_url = esc_url_raw( $val );
				break;
			}
		}

		// SKU prefix fallback: tb-{id} / jd-{id}.
		if ( '' === $tb_id && '' === $jd['jd_sku_id'] ) {
			$product = $item->get_product();
			$sku     = $product ? (string) $product->get_sku() : '';
			if ( preg_match( '/^tb-(\d+)/i', $sku, $m ) ) {
				$tb_id = $m[1];
			} elseif ( preg_match( '/^jd-(\d+)/i', $sku, $m ) ) {
				return array(
					'platform'    => 'jd',
					'item_id'     => $m[1],
					'product_url' => 'https://item.jd.com/' . $m[1] . '.html',
				);
			}
		}

		$platform_meta = (string) $item->get_meta( '_ppw_source_platform', true );
		if ( '' === $platform_meta && $product_id ) {
			$platform_meta = (string) get_post_meta( $product_id, '_ppw_source_platform', true );
		}

		if ( '' !== $tb_id || '' !== $tb_url || 'taobao' === $platform_meta ) {
			if ( '' === $tb_url && '' !== $tb_id ) {
				$tb_url = 'https://item.taobao.com/item.htm?id=' . $tb_id;
			}
			return array(
				'platform'    => 'taobao',
				'item_id'     => (string) $tb_id,
				'product_url' => (string) $tb_url,
			);
		}

		return array(
			'platform'    => 'jd',
			'item_id'     => '',
			'product_url' => '',
		);
	}

	/**
	 * Extract JD identifiers from an order line item / product.
	 *
	 * @param WC_Order_Item_Product $item Line item.
	 * @return array{jd_sku_id: string, jd_product_url: string}
	 */
	public static function extract_jd_meta_from_item( WC_Order_Item_Product $item ): array {
		$product_id   = (int) $item->get_product_id();
		$variation_id = (int) $item->get_variation_id();

		$sku_keys = array(
			'_jd_sku_id',
			'_ppw_jd_sku_id',
			'_ppw_jd_num_iid',
			'_myeasybuy_jd_sku_id',
		);

		$url_keys = array(
			'_jd_product_url',
			'_ppw_jd_source_url',
			'_myeasybuy_source_url',
		);

		$jd_sku_id = '';
		$jd_url    = '';

		foreach ( $sku_keys as $key ) {
			$val = (string) $item->get_meta( $key, true );
			if ( '' === $val && $variation_id ) {
				$val = (string) get_post_meta( $variation_id, $key, true );
			}
			if ( '' === $val && $product_id ) {
				$val = (string) get_post_meta( $product_id, $key, true );
			}
			if ( '' !== $val ) {
				$jd_sku_id = preg_replace( '/\D/', '', $val ) ?: $val;
				break;
			}
		}

		foreach ( $url_keys as $key ) {
			$val = (string) $item->get_meta( $key, true );
			if ( '' === $val && $variation_id ) {
				$val = (string) get_post_meta( $variation_id, $key, true );
			}
			if ( '' === $val && $product_id ) {
				$val = (string) get_post_meta( $product_id, $key, true );
			}
			if ( '' !== $val ) {
				$jd_url = esc_url_raw( $val );
				break;
			}
		}

		// Derive sku from JD URL if missing.
		if ( '' === $jd_sku_id && $jd_url && preg_match( '/(?:item\.jd\.com\/|sku=)(\d+)/', $jd_url, $m ) ) {
			$jd_sku_id = $m[1];
		}

		if ( '' === $jd_url && '' !== $jd_sku_id ) {
			$jd_url = 'https://item.jd.com/' . $jd_sku_id . '.html';
		}

		/**
		 * Filter extracted JD meta for a line item.
		 *
		 * @since 1.5.0
		 *
		 * @param array<string, string> $jd   Extracted data.
		 * @param WC_Order_Item_Product $item Line item.
		 */
		return apply_filters(
			'myeasybuy_procurement_jd_meta',
			array(
				'jd_sku_id'      => (string) $jd_sku_id,
				'jd_product_url' => (string) $jd_url,
			),
			$item
		);
	}

	/**
	 * Build warehouse shipping address with member code suffix.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string, mixed>
	 */
	public static function build_shipping_address( WC_Order $order ): array {
		$warehouse = wp_parse_args(
			(array) get_option( self::OPTION_WAREHOUSE, array() ),
			self::default_warehouse_address()
		);

		$member_no = self::get_member_number( $order );
		$base_name = trim( (string) ( $warehouse['first_name'] ?? '深圳集运仓' ) );
		$full_name = $base_name . ' - ' . $member_no;

		$address = array(
			'first_name' => $full_name,
			'last_name'  => '',
			'company'    => (string) ( $warehouse['company'] ?? '' ),
			'address_1'  => (string) ( $warehouse['address_1'] ?? '' ),
			'address_2'  => (string) ( $warehouse['address_2'] ?? '' ),
			'city'       => (string) ( $warehouse['city'] ?? '' ),
			'state'      => (string) ( $warehouse['state'] ?? '' ),
			'postcode'   => (string) ( $warehouse['postcode'] ?? '' ),
			'country'    => (string) ( $warehouse['country'] ?? 'CN' ),
			'phone'      => (string) ( $warehouse['phone'] ?? '' ),
			'member_no'  => $member_no,
			'formatted'  => trim(
				$full_name . '，' .
				( $warehouse['state'] ?? '' ) .
				( $warehouse['city'] ?? '' ) .
				( $warehouse['address_1'] ?? '' ) . ' ' .
				( $warehouse['address_2'] ?? '' ) . ' ' .
				( $warehouse['postcode'] ?? '' )
			),
		);

		/**
		 * Filter procurement shipping address payload.
		 *
		 * @since 1.5.0
		 *
		 * @param array<string, mixed> $address Address.
		 * @param WC_Order             $order   Order.
		 */
		return apply_filters( 'myeasybuy_procurement_shipping_address', $address, $order );
	}

	/**
	 * Resolve buyer member number (e.g. CAN0098).
	 *
	 * @param WC_Order $order Order.
	 */
	public static function get_member_number( WC_Order $order ): string {
		$user_id = (int) $order->get_customer_id();

		if ( $user_id > 0 ) {
			$stored = (string) get_user_meta( $user_id, self::META_MEMBER_NO, true );
			if ( '' !== $stored ) {
				return strtoupper( $stored );
			}

			$generated = sprintf( 'CAN%04d', $user_id );
			update_user_meta( $user_id, self::META_MEMBER_NO, $generated );
			return $generated;
		}

		return sprintf( 'CAN%04d', (int) $order->get_id() );
	}

	/**
	 * Whether a job already exists for this order item.
	 *
	 * @param int $order_id      Order ID.
	 * @param int $order_item_id Item ID.
	 */
	private static function job_exists_for_item( int $order_id, int $order_item_id ): bool {
		global $wpdb;
		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE order_id = %d AND order_item_id = %d AND status IN (%s, %s, %s) LIMIT 1",
				$order_id,
				$order_item_id,
				self::STATUS_PENDING,
				self::STATUS_PROCESSING,
				self::STATUS_COMPLETED
			)
		);

		return ! empty( $found );
	}

	/**
	 * Insert a procurement job row.
	 *
	 * @param array<string, mixed> $data Job data.
	 * @return int Job ID.
	 */
	private static function insert_job( array $data ): int {
		global $wpdb;

		$row = array(
			'order_id'         => (int) $data['order_id'],
			'order_item_id'    => (int) $data['order_item_id'],
			'product_id'       => (int) $data['product_id'],
			'jd_sku_id'        => (string) $data['jd_sku_id'],
			'jd_product_url'   => (string) ( $data['jd_product_url'] ?? '' ),
			'quantity'         => (int) $data['quantity'],
			'shipping_address' => (string) ( $data['shipping_address'] ?? '' ),
			'status'           => (string) ( $data['status'] ?? self::STATUS_PENDING ),
			'retry_count'      => (int) ( $data['retry_count'] ?? 0 ),
			'created_at'       => current_time( 'mysql', true ),
			'updated_at'       => current_time( 'mysql', true ),
		);
		$formats = array( '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s' );

		if ( self::table_has_platform_column() ) {
			// Insert platform after product_id.
			$row = array(
				'order_id'         => $row['order_id'],
				'order_item_id'    => $row['order_item_id'],
				'product_id'       => $row['product_id'],
				'platform'         => sanitize_key( (string) ( $data['platform'] ?? 'jd' ) ) ?: 'jd',
				'jd_sku_id'        => $row['jd_sku_id'],
				'jd_product_url'   => $row['jd_product_url'],
				'quantity'         => $row['quantity'],
				'shipping_address' => $row['shipping_address'],
				'status'           => $row['status'],
				'retry_count'      => $row['retry_count'],
				'created_at'       => $row['created_at'],
				'updated_at'       => $row['updated_at'],
			);
			$formats = array( '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert( self::table_name(), $row, $formats );

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Whether procurement jobs table has platform column.
	 */
	private static function table_has_platform_column(): bool {
		global $wpdb;
		$table = self::table_name();
		static $has = null;
		if ( null !== $has ) {
			return $has;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$col = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'platform'" );
		$has = ! empty( $col );
		return $has;
	}

	/**
	 * Register REST routes for RPA agents.
	 */
	public static function register_rest_routes(): void {
		register_rest_route(
			MyEasyBuy_REST_API::REST_NAMESPACE,
			'/procurement/pending-jobs',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_get_pending_jobs' ),
				'permission_callback' => array( __CLASS__, 'can_manage_procurement' ),
				'args'                => array(
					'limit' => array(
						'type'              => 'integer',
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			MyEasyBuy_REST_API::REST_NAMESPACE,
			'/procurement/update-job-status',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_update_job_status' ),
				'permission_callback' => array( __CLASS__, 'can_manage_procurement' ),
				'args'                => array(
					'job_id'      => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'status'      => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'jd_order_sn' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
					'paid_amount' => array(
						'type'    => 'number',
						'default' => null,
					),
					'currency'    => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => 'CNY',
					),
					'error_message' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
						'default'           => '',
					),
					'cn_tracking' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
					'cn_carrier'  => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
					'target_status' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
					'message'       => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
						'default'           => '',
					),
				),
			)
		);
	}

	/**
	 * Permission for procurement agent APIs (Application Passwords supported).
	 */
	public static function can_manage_procurement(): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		/**
		 * Filter procurement API permission.
		 *
		 * @since 1.5.0
		 *
		 * @param bool $allowed Default capability check.
		 */
		return (bool) apply_filters(
			'myeasybuy_rest_can_manage_procurement',
			current_user_can( 'manage_woocommerce' )
			|| current_user_can( MyEasyBuy_Roles::CAP_BUYER )
			|| current_user_can( 'edit_shop_orders' )
		);
	}

	/**
	 * GET /procurement/pending-jobs
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function rest_get_pending_jobs( WP_REST_Request $request ) {
		global $wpdb;

		$limit = max( 1, min( 100, (int) $request->get_param( 'limit' ) ) );
		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s ORDER BY id ASC LIMIT %d",
				self::STATUS_PENDING,
				$limit
			),
			ARRAY_A
		);

		$jobs = array();
		foreach ( (array) $rows as $row ) {
			$address = json_decode( (string) ( $row['shipping_address'] ?? '' ), true );
			if ( ! is_array( $address ) ) {
				$address = array();
			}

			$jobs[] = array(
				'job_id'           => (int) $row['id'],
				'order_id'         => (int) $row['order_id'],
				'order_item_id'    => (int) $row['order_item_id'],
				'product_id'       => (int) $row['product_id'],
				'jd_sku_id'        => (string) $row['jd_sku_id'],
				'jd_product_url'   => (string) $row['jd_product_url'],
				'quantity'         => (int) $row['quantity'],
				'shipping_address' => $address,
				'status'           => (string) $row['status'],
				'retry_count'      => (int) $row['retry_count'],
				'created_at'       => (string) $row['created_at'],
			);
		}

		return new WP_REST_Response(
			array(
				'count' => count( $jobs ),
				'jobs'  => $jobs,
			),
			200
		);
	}

	/**
	 * POST /procurement/update-job-status
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function rest_update_job_status( WP_REST_Request $request ) {
		$job_id = (int) $request->get_param( 'job_id' );
		$status = sanitize_key( (string) $request->get_param( 'status' ) );

		$allowed = array(
			self::STATUS_PENDING,
			self::STATUS_PROCESSING,
			self::STATUS_COMPLETED,
			self::STATUS_FAILED,
		);

		if ( ! in_array( $status, $allowed, true ) ) {
			return new WP_Error(
				'myeasybuy_invalid_job_status',
				__( 'Invalid job status. Use pending, processing, completed, or failed.', 'myeasybuy-core' ),
				array( 'status' => 400 )
			);
		}

		$job = self::get_job( $job_id );
		if ( ! $job ) {
			return new WP_Error( 'myeasybuy_job_not_found', __( 'Procurement job not found.', 'myeasybuy-core' ), array( 'status' => 404 ) );
		}

		$jd_order_sn   = sanitize_text_field( (string) $request->get_param( 'jd_order_sn' ) );
		$paid_amount   = $request->get_param( 'paid_amount' );
		$currency      = sanitize_text_field( (string) $request->get_param( 'currency' ) ) ?: 'CNY';
		$error_message = sanitize_textarea_field( (string) $request->get_param( 'error_message' ) );
		$cn_tracking   = sanitize_text_field( (string) $request->get_param( 'cn_tracking' ) );
		$cn_carrier    = sanitize_text_field( (string) $request->get_param( 'cn_carrier' ) );
		$target_status = sanitize_text_field( (string) $request->get_param( 'target_status' ) );
		$message       = sanitize_textarea_field( (string) $request->get_param( 'message' ) );

		$update = array(
			'status'     => $status,
			'updated_at' => current_time( 'mysql', true ),
		);
		$formats = array( '%s', '%s' );

		if ( self::STATUS_FAILED === $status ) {
			$update['retry_count']   = (int) $job['retry_count'] + 1;
			$update['error_message'] = $error_message;
			$formats[]               = '%d';
			$formats[]               = '%s';
		}

		if ( '' !== $jd_order_sn ) {
			$update['jd_order_sn'] = $jd_order_sn;
			$formats[]             = '%s';
		}

		if ( null !== $paid_amount && '' !== $paid_amount ) {
			$update['paid_amount'] = (float) $paid_amount;
			$update['currency']    = $currency;
			$formats[]             = '%f';
			$formats[]             = '%s';
		}

		if ( self::STATUS_COMPLETED === $status ) {
			$update['completed_at'] = current_time( 'mysql', true );
			$formats[]              = '%s';
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			self::table_name(),
			$update,
			array( 'id' => $job_id ),
			$formats,
			array( '%d' )
		);

		$order_id = (int) $job['order_id'];
		$order    = wc_get_order( $order_id );

		if ( $order && '' !== $jd_order_sn ) {
			$order->update_meta_data( self::META_JD_ORDER_SN, $jd_order_sn );
			$order->update_meta_data( self::META_CN_TRACKING_NO, $jd_order_sn );

			if ( '' !== $cn_tracking ) {
				$order->update_meta_data( '_myeasybuy_cn_tracking', $cn_tracking );
			}
			if ( '' !== $cn_carrier ) {
				$order->update_meta_data( '_myeasybuy_cn_carrier', $cn_carrier );
			}
			if ( null !== $paid_amount && '' !== $paid_amount ) {
				$order->update_meta_data( '_myeasybuy_jd_paid_amount', (float) $paid_amount );
				$order->update_meta_data( '_myeasybuy_jd_paid_currency', $currency );
			}

			$order->save();
		}

		if ( $order && '' !== $message ) {
			$order->add_order_note( $message );
		}

		if ( $order && self::STATUS_COMPLETED === $status && '' !== $jd_order_sn ) {
			$block_status = self::is_order_past_buyer_procurement( $order )
				|| ( class_exists( 'MyEasyBuy_Consolidation' )
					&& 'yes' === $order->get_meta( MyEasyBuy_Consolidation::META_SECOND_PAYMENT_PAID, true ) );

			if ( ! $block_status ) {
				if ( '' === $target_status ) {
					// Domestic express tracking present → cn-shipped; otherwise purchasing (JD order placed).
					$target_status = '' !== $cn_tracking
						? MyEasyBuy_Order_Statuses::STATUS_CN_SHIPPED
						: MyEasyBuy_Order_Statuses::STATUS_PURCHASING;
				}

				MyEasyBuy_Hooks::update_order_status(
					$order_id,
					$target_status,
					sprintf(
						/* translators: %s: JD order SN */
						__( 'JD auto-procurement completed. JD order: %s', 'myeasybuy-core' ),
						$jd_order_sn
					)
				);

				/**
				 * Fires when an RPA agent reports JD procurement completion.
				 *
				 * @since 1.5.0
				 *
				 * @param int    $order_id    WooCommerce order ID.
				 * @param string $jd_order_sn JD domestic order number.
				 */
				do_action( self::ACTION_COMPLETED, $order_id, $jd_order_sn );
			}
		}

		$fresh = self::get_job( $job_id );

		return new WP_REST_Response(
			array(
				'job'     => $fresh,
				'message' => __( 'Procurement job updated.', 'myeasybuy-core' ),
			),
			200
		);
	}

	/**
	 * Fetch procurement jobs by status list.
	 *
	 * @param array<int, string> $statuses Statuses.
	 * @param int                $limit    Max rows.
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_jobs( array $statuses, int $limit = 50 ): array {
		global $wpdb;

		$statuses = array_values( array_filter( array_map( 'sanitize_key', $statuses ) ) );
		if ( empty( $statuses ) ) {
			return array();
		}

		$limit = max( 1, min( 200, $limit ) );
		$table = self::table_name();
		$in    = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$sql = $wpdb->prepare(
			"SELECT * FROM {$table} WHERE status IN ({$in}) ORDER BY id DESC LIMIT %d",
			...array_merge( $statuses, array( $limit ) )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Fetch a job row by ID.
	 *
	 * @param int $job_id Job ID.
	 * @return array<string, mixed>|null
	 */
	public static function get_job( int $job_id ): ?array {
		global $wpdb;
		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $job_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Redirect legacy WooCommerce「采购仓库地址」URL into 4PX settings.
	 */
	public static function maybe_redirect_legacy_warehouse_settings(): void {
		if ( ! is_admin() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		if ( 'myeasybuy-procurement-warehouse' !== $page ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=myeasybuy-4px-settings#myeasybuy-procurement-warehouse' ) );
		exit;
	}

	/**
	 * Register warehouse + free-storage options on the shared 4PX settings group.
	 */
	public static function register_warehouse_settings(): void {
		register_setting(
			'myeasybuy_4px_settings_group',
			self::OPTION_WAREHOUSE,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_warehouse_settings' ),
				'default'           => self::default_warehouse_address(),
			)
		);

		register_setting(
			'myeasybuy_4px_settings_group',
			'myeasybuy_free_storage_days',
			array(
				'type'              => 'integer',
				'sanitize_callback' => static function ( $value ) {
					$days = absint( $value );
					return $days > 0 ? min( 365, $days ) : 90;
				},
				'default'           => 90,
			)
		);
	}

	/**
	 * Sanitize warehouse option.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, string>
	 */
	public static function sanitize_warehouse_settings( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::default_warehouse_address();
		$out      = array();

		foreach ( $defaults as $key => $default ) {
			$out[ $key ] = sanitize_text_field( (string) ( $input[ $key ] ?? $default ) );
		}

		return $out;
	}

	/**
	 * Render procurement warehouse fields (embedded in 4PX settings).
	 */
	public static function render_warehouse_settings_fields(): void {
		$settings = wp_parse_args(
			(array) get_option( self::OPTION_WAREHOUSE, array() ),
			self::default_warehouse_address()
		);
		$fields   = array(
			'first_name' => __( '收货人姓名前缀', 'myeasybuy-core' ),
			'company'    => __( '公司', 'myeasybuy-core' ),
			'address_1'  => __( '地址行 1', 'myeasybuy-core' ),
			'address_2'  => __( '地址行 2', 'myeasybuy-core' ),
			'city'       => __( '城市', 'myeasybuy-core' ),
			'state'      => __( '省/州', 'myeasybuy-core' ),
			'postcode'   => __( '邮编', 'myeasybuy-core' ),
			'country'    => __( '国家代码', 'myeasybuy-core' ),
			'phone'      => __( '电话', 'myeasybuy-core' ),
		);
		?>
		<h2 id="myeasybuy-procurement-warehouse"><?php esc_html_e( '采购仓库地址（代采收货）', 'myeasybuy-core' ); ?></h2>
		<p class="description">
			<?php esc_html_e( '采购 Agent 下单与集运转运中心共用此地址；代采收货人附带 CAN 会员号，集运收货人附带 CA- 仓位码。', 'myeasybuy-core' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<?php foreach ( $fields as $key => $label ) : ?>
				<tr>
					<th scope="row"><label for="wh_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
					<td>
						<input
							type="text"
							class="regular-text"
							id="wh_<?php echo esc_attr( $key ); ?>"
							name="<?php echo esc_attr( self::OPTION_WAREHOUSE ); ?>[<?php echo esc_attr( $key ); ?>]"
							value="<?php echo esc_attr( (string) $settings[ $key ] ); ?>"
						/>
					</td>
				</tr>
			<?php endforeach; ?>
			<tr>
				<th scope="row"><label for="myeasybuy_free_storage_days"><?php esc_html_e( '免费保管天数', 'myeasybuy-core' ); ?></label></th>
				<td>
					<input
						type="number"
						min="1"
						max="365"
						class="small-text"
						id="myeasybuy_free_storage_days"
						name="myeasybuy_free_storage_days"
						value="<?php echo esc_attr( (string) ( class_exists( 'MyEasyBuy_Customer_Inbound' ) ? MyEasyBuy_Customer_Inbound::get_free_storage_days() : 90 ) ); ?>"
					/>
					<p class="description"><?php esc_html_e( '包裹入库验货后的免费保管天数，将显示在客户邮件、弹窗与订单详情中。', 'myeasybuy-core' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}
}
