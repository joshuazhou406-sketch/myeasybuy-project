<?php
/**
 * Customer My Account — orders list (我的订单).
 *
 * @package MyEasyBuy_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Replaces the WooCommerce orders list with the account orders screen.
 */
class MyEasyBuy_Customer_Orders {

	private const PER_PAGE = 10;

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'replace_endpoint' ), 20 );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'account_menu_items' ), 110 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_filter( 'document_title_parts', array( __CLASS__, 'document_title' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 120 );
	}

	/**
	 * Orders list (not the single order view).
	 */
	public static function is_list(): bool {
		return function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'orders' );
	}

	/**
	 * Orders list or a single order.
	 */
	public static function is_page(): bool {
		return self::is_list() || ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'view-order' ) );
	}

	/**
	 * Swap the default orders table for this screen.
	 */
	public static function replace_endpoint(): void {
		remove_action( 'woocommerce_account_orders_endpoint', 'woocommerce_account_orders' );
		add_action( 'woocommerce_account_orders_endpoint', array( __CLASS__, 'render_endpoint' ) );
	}

	/**
	 * Label the account item in Chinese.
	 *
	 * @param array<string, string> $items Menu items.
	 * @return array<string, string>
	 */
	public static function account_menu_items( array $items ): array {
		if ( isset( $items['orders'] ) ) {
			$items['orders'] = __( '我的订单', 'myeasybuy-core' );
		}

		return $items;
	}

	/**
	 * @param array<int, string> $classes Body classes.
	 * @return array<int, string>
	 */
	public static function body_class( array $classes ): array {
		if ( self::is_list() ) {
			$classes[] = 'meb-account-orders';
		}

		return $classes;
	}

	/**
	 * @param array<string, string> $parts Title parts.
	 * @return array<string, string>
	 */
	public static function document_title( array $parts ): array {
		if ( self::is_list() ) {
			$parts['title'] = __( '我的订单', 'myeasybuy-core' );
		}

		return $parts;
	}

	/**
	 * Assets for the orders list.
	 */
	public static function enqueue_assets(): void {
		if ( ! self::is_list() ) {
			return;
		}

		wp_enqueue_style(
			'myeasybuy-customer-orders',
			MYEASYBUY_CORE_URL . 'assets/css/customer-orders.css',
			array( 'myeasybuy-storefront-palette' ),
			MYEASYBUY_CORE_VERSION
		);

		wp_enqueue_script(
			'myeasybuy-customer-orders',
			MYEASYBUY_CORE_URL . 'assets/js/customer-orders.js',
			array(),
			MYEASYBUY_CORE_VERSION,
			true
		);
	}

	/**
	 * Render the orders list.
	 *
	 * @param int|string $current_page Page number from the endpoint.
	 */
	public static function render_endpoint( $current_page = 1 ): void {
		if ( ! is_user_logged_in() ) {
			echo '<p>' . esc_html__( '请先登录。', 'myeasybuy-core' ) . '</p>';
			return;
		}

		$filters  = self::read_filters();
		$page     = max( 1, absint( $current_page ) );
		$result   = self::query_orders( $filters, $page );
		$tabs     = self::tabs();
		$statuses = self::status_options();

		include MYEASYBUY_CORE_DIR . 'templates/account/orders.php';
	}

	/**
	 * @return array<string, string>
	 */
	public static function tabs(): array {
		return array(
			'all'      => __( '全部', 'myeasybuy-core' ),
			'unpaid'   => __( '待付款', 'myeasybuy-core' ),
			'confirm'  => __( '待确认', 'myeasybuy-core' ),
			'shipping' => __( '派送中', 'myeasybuy-core' ),
		);
	}

	/**
	 * @return array<int, array{num: string, label: string}>
	 */
	public static function flow_steps(): array {
		return array(
			array(
				'num'   => '01',
				'label' => __( '下订单', 'myeasybuy-core' ),
			),
			array(
				'num'   => '02',
				'label' => __( '仓库质检', 'myeasybuy-core' ),
			),
			array(
				'num'   => '03',
				'label' => __( '提交包裹', 'myeasybuy-core' ),
			),
			array(
				'num'   => '04',
				'label' => __( '签收快递', 'myeasybuy-core' ),
			),
		);
	}

	/**
	 * Customer-facing status labels for the dropdown and badges.
	 *
	 * @return array<string, string>
	 */
	public static function status_options(): array {
		$options = array(
			'pending'    => __( '待付款', 'myeasybuy-core' ),
			'on-hold'    => __( '待处理', 'myeasybuy-core' ),
			'failed'     => __( '支付失败', 'myeasybuy-core' ),
			'processing' => __( '处理中', 'myeasybuy-core' ),
			'completed'  => __( '已完成', 'myeasybuy-core' ),
			'cancelled'  => __( '已取消', 'myeasybuy-core' ),
			'refunded'   => __( '已退款', 'myeasybuy-core' ),
		);

		if ( class_exists( 'MyEasyBuy_Order_Statuses' ) ) {
			foreach ( MyEasyBuy_Order_Statuses::get_definitions() as $status => $args ) {
				$key             = str_replace( 'wc-', '', (string) $status );
				$options[ $key ] = (string) $args['label'];
			}
		}

		return $options;
	}

	/**
	 * Statuses included in a tab. Empty means every status.
	 *
	 * @return string[]
	 */
	private static function tab_statuses( string $tab ): array {
		switch ( $tab ) {
			case 'unpaid':
				return array( 'pending', 'on-hold', 'failed', 'awaiting-shipping-fee' );
			case 'confirm':
				return array( 'pending-manual', 'processing', 'purchasing' );
			case 'shipping':
				return array( 'cn-shipped', 'int-shipped' );
			default:
				return array();
		}
	}

	/**
	 * Counts for the account overview. Same buckets as the order tabs.
	 *
	 * @return array{unpaid: int, confirm: int, shipping: int}
	 */
	public static function summary_counts(): array {
		$counts = array(
			'unpaid'   => 0,
			'confirm'  => 0,
			'shipping' => 0,
		);

		foreach ( array_keys( $counts ) as $tab ) {
			$found = wc_get_orders(
				array(
					'customer' => get_current_user_id(),
					'limit'    => 100,
					'paginate' => false,
					'return'   => 'ids',
					'status'   => self::tab_statuses( $tab ),
				)
			);
			$counts[ $tab ] = is_array( $found ) ? count( $found ) : 0;
		}

		return $counts;
	}

	/**
	 * @return array{tab: string, q: string, status: string, from: string, to: string, active: bool}
	 */
	private static function read_filters(): array {
		$tab = isset( $_GET['meb_tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['meb_tab'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( self::tabs()[ $tab ] ) ) {
			$tab = 'all';
		}

		$q      = isset( $_GET['meb_q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['meb_q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['meb_status'] ) ? sanitize_key( wp_unslash( (string) $_GET['meb_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $status && ! isset( self::status_options()[ $status ] ) ) {
			$status = '';
		}

		$from = self::read_date( 'meb_from' );
		$to   = self::read_date( 'meb_to' );

		return array(
			'tab'    => $tab,
			'q'      => $q,
			'status' => $status,
			'from'   => $from,
			'to'     => $to,
			'active'   => '' !== $q || '' !== $status || '' !== $from || '' !== $to,
		);
	}

	/**
	 * @param string $key Query arg.
	 */
	private static function read_date( string $key ): string {
		if ( empty( $_GET[ $key ] ) || ! is_string( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return '';
		}

		$value = sanitize_text_field( wp_unslash( $_GET[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$date  = DateTime::createFromFormat( '!Y-m-d', $value );

		return ( $date && $date->format( 'Y-m-d' ) === $value ) ? $value : '';
	}

	/**
	 * @param array{tab: string, q: string, status: string, from: string, to: string, active: bool} $filters Filters.
	 * @param int                                                                                  $page    Page.
	 * @return array{orders: WC_Order[], total: int, pages: int}
	 */
	private static function query_orders( array $filters, int $page ): array {
		$statuses = self::tab_statuses( $filters['tab'] );
		if ( '' !== $filters['status'] ) {
			if ( empty( $statuses ) || in_array( $filters['status'], $statuses, true ) ) {
				$statuses = array( $filters['status'] );
			} else {
				return array(
					'orders' => array(),
					'total'  => 0,
					'pages'  => 1,
				);
			}
		}

		$args = array(
			'customer' => get_current_user_id(),
			'limit'    => 100,
			'orderby'  => 'date',
			'order'    => 'DESC',
			'paginate' => false,
			'status'   => empty( $statuses ) ? 'any' : $statuses,
		);

		if ( '' !== $filters['from'] || '' !== $filters['to'] ) {
			$start = '' !== $filters['from'] ? $filters['from'] : '2000-01-01';
			$end   = '' !== $filters['to'] ? $filters['to'] : gmdate( 'Y-m-d' );
			$args['date_created'] = $start . '...' . $end;
		}

		$found = wc_get_orders( $args );
		$rows  = array();

		foreach ( $found as $order ) {
			if ( ! $order instanceof WC_Order || in_array( $order->get_status(), array( 'checkout-draft', 'trash', 'auto-draft' ), true ) ) {
				continue;
			}
			if ( '' !== $filters['q'] && ! self::order_matches( $order, $filters['q'] ) ) {
				continue;
			}
			$rows[] = $order;
		}

		$total  = count( $rows );
		$pages  = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$page   = min( $page, $pages );
		$offset = ( $page - 1 ) * self::PER_PAGE;

		return array(
			'orders' => array_slice( $rows, $offset, self::PER_PAGE ),
			'total'  => $total,
			'pages'  => $pages,
		);
	}

	/**
	 * Match order number or a product name.
	 */
	private static function order_matches( WC_Order $order, string $query ): bool {
		$needle = function_exists( 'mb_strtolower' ) ? mb_strtolower( $query ) : strtolower( $query );
		$hay    = array(
			(string) $order->get_order_number(),
			(string) $order->get_id(),
		);

		foreach ( $order->get_items() as $item ) {
			$hay[] = $item->get_name();
		}

		foreach ( $hay as $value ) {
			$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );
			if ( '' !== $value && false !== ( function_exists( 'mb_strpos' ) ? mb_strpos( $value, $needle ) : strpos( $value, $needle ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Orders list URL, keeping the current filters.
	 *
	 * @param array{tab?: string, q?: string, status?: string, from?: string, to?: string} $overrides Query overrides.
	 * @param int                                                                          $page      Page number.
	 */
	public static function page_url( array $overrides = array(), int $page = 1 ): string {
		$filters = self::read_filters();
		$args    = array(
			'meb_tab'    => $overrides['tab'] ?? $filters['tab'],
			'meb_q'      => $overrides['q'] ?? $filters['q'],
			'meb_status' => $overrides['status'] ?? $filters['status'],
			'meb_from'   => $overrides['from'] ?? $filters['from'],
			'meb_to'     => $overrides['to'] ?? $filters['to'],
		);

		$args = array_filter(
			$args,
			static function ( $value, $key ): bool {
				if ( 'meb_tab' === $key ) {
					return 'all' !== $value && '' !== $value;
				}
				return '' !== $value;
			},
			ARRAY_FILTER_USE_BOTH
		);

		$url = wc_get_endpoint_url( 'orders', $page > 1 ? $page : '', wc_get_page_permalink( 'myaccount' ) );

		return $args ? add_query_arg( $args, $url ) : $url;
	}

	/**
	 * Short label for an order status.
	 */
	public static function status_label( WC_Order $order ): string {
		$status = $order->get_status();
		$options = self::status_options();

		return $options[ $status ] ?? wc_get_order_status_name( $status );
	}

	/**
	 * Format an order total as C$ when the store uses CAD.
	 */
	public static function format_total( WC_Order $order ): string {
		if ( class_exists( 'MyEasyBuy_Customer_Cart' ) && $order->get_currency() === get_woocommerce_currency() ) {
			return MyEasyBuy_Customer_Cart::money( (float) $order->get_total() );
		}

		return wp_strip_all_tags( $order->get_formatted_order_total() );
	}
}
