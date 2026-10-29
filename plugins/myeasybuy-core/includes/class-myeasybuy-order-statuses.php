<?php
/**
 * Custom WooCommerce order statuses for myeasybuy lifecycle.
 *
 * @package MyEasyBuy_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and manages myeasybuy custom order statuses.
 */
class MyEasyBuy_Order_Statuses {

	public const STATUS_PENDING_MANUAL         = 'wc-pending-manual';
	public const STATUS_PURCHASING           = 'wc-purchasing';
	public const STATUS_CN_SHIPPED           = 'wc-cn-shipped';
	public const STATUS_IN_WAREHOUSE         = 'wc-in-warehouse';
	public const STATUS_CONSOLIDATING          = 'wc-consolidating';
	public const STATUS_AWAITING_SHIPPING_FEE = 'wc-awaiting-shipping-fee';
	public const STATUS_PAID_SHIPPING         = 'wc-paid-shipping';
	public const STATUS_INT_SHIPPED            = 'wc-int-shipped';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register_statuses' ) );
		add_filter( 'wc_order_statuses', array( __CLASS__, 'add_to_order_statuses' ) );
		add_filter( 'woocommerce_reports_order_statuses', array( __CLASS__, 'add_to_reports' ) );
		add_filter( 'woocommerce_valid_order_statuses_for_payment', array( __CLASS__, 'valid_for_payment' ), 10, 2 );
		add_filter( 'woocommerce_valid_order_statuses_for_payment_complete', array( __CLASS__, 'valid_for_payment_complete' ) );
		add_action( 'admin_head', array( __CLASS__, 'admin_status_styles' ) );
		add_filter( 'bulk_actions-edit-shop_order', array( __CLASS__, 'register_bulk_actions' ) );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( __CLASS__, 'register_bulk_actions' ) );
	}

	/**
	 * All custom statuses with labels and admin badge colors.
	 *
	 * @return array<string, array{label: string, label_count: string, color: string, paid_after_first?: bool}>
	 */
	public static function get_definitions(): array {
		$definitions = array(
			self::STATUS_PENDING_MANUAL          => array(
				'label'       => __( '待人工补录', 'myeasybuy-core' ),
				'label_count' => _n_noop(
					'待人工补录 <span class="count">(%s)</span>',
					'待人工补录 <span class="count">(%s)</span>',
					'myeasybuy-core'
				),
				'color'       => '#94660c',
			),
			self::STATUS_PURCHASING              => array(
				'label'       => __( '平台代采中', 'myeasybuy-core' ),
				'label_count' => _n_noop(
					'平台代采中 <span class="count">(%s)</span>',
					'平台代采中 <span class="count">(%s)</span>',
					'myeasybuy-core'
				),
				'color'       => '#007cba',
				'paid_after_first' => true,
			),
			self::STATUS_CN_SHIPPED              => array(
				'label'       => __( '国内已发货', 'myeasybuy-core' ),
				'label_count' => _n_noop(
					'国内已发货 <span class="count">(%s)</span>',
					'国内已发货 <span class="count">(%s)</span>',
					'myeasybuy-core'
				),
				'color'       => '#2e4453',
				'paid_after_first' => true,
			),
			self::STATUS_IN_WAREHOUSE            => array(
				'label'       => __( '已入库验货', 'myeasybuy-core' ),
				'label_count' => _n_noop(
					'已入库验货 <span class="count">(%s)</span>',
					'已入库验货 <span class="count">(%s)</span>',
					'myeasybuy-core'
				),
				'color'       => '#007017',
				'paid_after_first' => true,
			),
			self::STATUS_CONSOLIDATING           => array(
				'label'       => __( '申请合箱中', 'myeasybuy-core' ),
				'label_count' => _n_noop(
					'申请合箱中 <span class="count">(%s)</span>',
					'申请合箱中 <span class="count">(%s)</span>',
					'myeasybuy-core'
				),
				'color'       => '#674399',
				'paid_after_first' => true,
			),
			self::STATUS_AWAITING_SHIPPING_FEE   => array(
				'label'       => __( '待付国际运费', 'myeasybuy-core' ),
				'label_count' => _n_noop(
					'待付国际运费 <span class="count">(%s)</span>',
					'待付国际运费 <span class="count">(%s)</span>',
					'myeasybuy-core'
				),
				'color'       => '#b32d2e',
				'paid_after_first' => true,
			),
			self::STATUS_PAID_SHIPPING           => array(
				'label'       => __( '已付二价/待出库', 'myeasybuy-core' ),
				'label_count' => _n_noop(
					'已付二价/待出库 <span class="count">(%s)</span>',
					'已付二价/待出库 <span class="count">(%s)</span>',
					'myeasybuy-core'
				),
				'color'       => '#2271b1',
				'paid_after_first' => true,
			),
			self::STATUS_INT_SHIPPED             => array(
				'label'       => __( '国际已发货', 'myeasybuy-core' ),
				'label_count' => _n_noop(
					'国际已发货 <span class="count">(%s)</span>',
					'国际已发货 <span class="count">(%s)</span>',
					'myeasybuy-core'
				),
				'color'       => '#005177',
				'paid_after_first' => true,
			),
		);

		/**
		 * Filter custom order status definitions before registration.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, array<string, mixed>> $definitions Status definitions.
		 */
		return apply_filters( 'myeasybuy_order_status_definitions', $definitions );
	}

	/**
	 * Register post statuses with WordPress.
	 */
	public static function register_statuses(): void {
		foreach ( self::get_definitions() as $status => $args ) {
			register_post_status(
				$status,
				array(
					'label'                     => $args['label'],
					'public'                    => true,
					'exclude_from_search'       => false,
					'show_in_admin_all_list'    => true,
					'show_in_admin_status_list' => true,
					'label_count'               => $args['label_count'],
				)
			);
		}
	}

	/**
	 * Add statuses to WooCommerce order status dropdown and filters.
	 *
	 * @param array<string, string> $order_statuses Existing statuses.
	 * @return array<string, string>
	 */
	public static function add_to_order_statuses( array $order_statuses ): array {
		$custom = array();

		foreach ( self::get_definitions() as $status => $args ) {
			$custom[ $status ] = $args['label'];
		}

		// Insert after "Processing" to keep myeasybuy flow grouped in admin UI.
		$insert_after = 'wc-processing';
		$position     = array_search( $insert_after, array_keys( $order_statuses ), true );

		if ( false === $position ) {
			return array_merge( $order_statuses, $custom );
		}

		$before = array_slice( $order_statuses, 0, $position + 1, true );
		$after  = array_slice( $order_statuses, $position + 1, null, true );

		return $before + $custom + $after;
	}

	/**
	 * Include custom statuses in WooCommerce reports where applicable.
	 *
	 * @param array<int, string> $statuses Report statuses.
	 * @return array<int, string>
	 */
	public static function add_to_reports( array $statuses ): array {
		foreach ( array_keys( self::get_definitions() ) as $status ) {
			if ( ! in_array( $status, $statuses, true ) ) {
				$statuses[] = $status;
			}
		}

		return $statuses;
	}

	/**
	 * Statuses valid for second-payment checkout.
	 *
	 * @param array<int, string> $statuses   Valid statuses.
	 * @param WC_Order           $order      Order object.
	 * @return array<int, string>
	 */
	public static function valid_for_payment( array $statuses, $order ): array {
		unset( $order );

		$statuses[] = self::STATUS_AWAITING_SHIPPING_FEE;

		return array_values( array_unique( $statuses ) );
	}

	/**
	 * Statuses considered paid after first payment completes.
	 *
	 * @param array<int, string> $statuses Valid statuses.
	 * @return array<int, string>
	 */
	public static function valid_for_payment_complete( array $statuses ): array {
		foreach ( self::get_definitions() as $status => $args ) {
			if ( ! empty( $args['paid_after_first'] ) ) {
				$statuses[] = $status;
			}
		}

		return array_values( array_unique( $statuses ) );
	}

	/**
	 * Register bulk actions in order list (classic + HPOS).
	 *
	 * @param array<string, string> $actions Bulk actions.
	 * @return array<string, string>
	 */
	public static function register_bulk_actions( array $actions ): array {
		foreach ( self::get_definitions() as $status => $args ) {
			$key             = 'mark_' . str_replace( 'wc-', '', $status );
			$actions[ $key ] = sprintf(
				/* translators: %s: order status label */
				__( 'Change status to %s', 'myeasybuy-core' ),
				$args['label']
			);
		}

		return $actions;
	}

	/**
	 * Inline admin styles for status badge colors.
	 */
	public static function admin_status_styles(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || ! in_array( $screen->id, array( 'edit-shop_order', 'woocommerce_page_wc-orders', 'shop_order' ), true ) ) {
			return;
		}

		echo '<style>';
		foreach ( self::get_definitions() as $status => $args ) {
			$slug = str_replace( 'wc-', '', $status );
			printf(
				'mark.order-status.status-%1$s, .order-status.status-%1$s { background-color: %2$s; color: #fff; }',
				esc_attr( $slug ),
				esc_attr( $args['color'] )
			);
		}
		echo '</style>';
	}

	/**
	 * Check if a status slug belongs to myeasybuy custom statuses.
	 *
	 * @param string $status Status slug (with or without wc- prefix).
	 */
	public static function is_custom_status( string $status ): bool {
		if ( 0 !== strpos( $status, 'wc-' ) ) {
			$status = 'wc-' . $status;
		}

		return array_key_exists( $status, self::get_definitions() );
	}

	/**
	 * Normalize status slug to wc-prefixed form.
	 *
	 * @param string $status Raw status.
	 */
	public static function normalize_status( string $status ): string {
		$status = str_replace( 'wc-', '', $status );

		return 'wc-' . $status;
	}
}
