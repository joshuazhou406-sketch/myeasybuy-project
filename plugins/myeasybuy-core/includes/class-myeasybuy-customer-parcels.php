<?php
/**
 * Customer My Account — parcels (我的包裹).
 *
 * @package MyEasyBuy_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lists international parcels after a shipping bill exists.
 */
class MyEasyBuy_Customer_Parcels {

	public const ENDPOINT = 'parcels';

	private const PER_PAGE = 10;

	private const META_BALANCE_DUE = '_myeasybuy_balance_due';

	private const META_DELIVERED = '_myeasybuy_is_delivered';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'add_endpoint' ) );
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'add_query_var' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'account_menu_items' ), 125 );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'render_endpoint' ) );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_filter( 'document_title_parts', array( __CLASS__, 'document_title' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 120 );

		if ( get_option( 'myeasybuy_parcels_endpoint_flushed' ) !== '1.15.0' ) {
			add_action( 'init', array( __CLASS__, 'maybe_flush_rewrite' ), 99 );
		}
	}

	/**
	 * Whether the current request is the parcels screen.
	 */
	public static function is_page(): bool {
		return function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( self::ENDPOINT );
	}

	/**
	 * Account parcels URL.
	 */
	public static function get_endpoint_url(): string {
		return wc_get_account_endpoint_url( self::ENDPOINT );
	}

	/**
	 * Register the My Account endpoint. EP_PAGES only, so it does not steal a root URL.
	 */
	public static function add_endpoint(): void {
		add_rewrite_endpoint( self::ENDPOINT, EP_PAGES );
	}

	/**
	 * @param array<string, string> $vars Query vars.
	 * @return array<string, string>
	 */
	public static function add_query_var( array $vars ): array {
		$vars[ self::ENDPOINT ] = self::ENDPOINT;
		return $vars;
	}

	/**
	 * Flush rewrite rules once after the endpoint is added.
	 */
	public static function maybe_flush_rewrite(): void {
		self::add_endpoint();
		flush_rewrite_rules( false );
		update_option( 'myeasybuy_parcels_endpoint_flushed', '1.15.0', false );
	}

	/**
	 * Place 我的包裹 after 我的仓库.
	 *
	 * @param array<string, string> $items Menu items.
	 * @return array<string, string>
	 */
	public static function account_menu_items( array $items ): array {
		$label = __( '我的包裹', 'myeasybuy-core' );
		unset( $items[ self::ENDPOINT ] );

		$new = array();
		foreach ( $items as $key => $item_label ) {
			$new[ $key ] = $item_label;
			if ( 'warehouse' === $key ) {
				$new[ self::ENDPOINT ] = $label;
			}
		}

		if ( ! isset( $new[ self::ENDPOINT ] ) ) {
			$new[ self::ENDPOINT ] = $label;
		}

		return $new;
	}

	/**
	 * @param array<int, string> $classes Body classes.
	 * @return array<int, string>
	 */
	public static function body_class( array $classes ): array {
		if ( self::is_page() ) {
			$classes[] = 'meb-account-parcels';
		}

		return $classes;
	}

	/**
	 * @param array<string, string> $parts Title parts.
	 * @return array<string, string>
	 */
	public static function document_title( array $parts ): array {
		if ( self::is_page() ) {
			$parts['title'] = __( '我的包裹', 'myeasybuy-core' );
		}

		return $parts;
	}

	/**
	 * Assets for the parcels screen.
	 */
	public static function enqueue_assets(): void {
		if ( ! self::is_page() ) {
			return;
		}

		wp_enqueue_style(
			'myeasybuy-customer-parcels',
			MYEASYBUY_CORE_URL . 'assets/css/customer-parcels.css',
			array( 'myeasybuy-storefront-palette' ),
			MYEASYBUY_CORE_VERSION
		);

		wp_enqueue_script(
			'myeasybuy-customer-parcels',
			MYEASYBUY_CORE_URL . 'assets/js/customer-parcels.js',
			array(),
			MYEASYBUY_CORE_VERSION,
			true
		);
	}

	/**
	 * Render the parcels screen.
	 *
	 * @param int|string $current_page Page number from the endpoint.
	 */
	public static function render_endpoint( $current_page = 1 ): void {
		if ( ! is_user_logged_in() ) {
			echo '<p>' . esc_html__( '请先登录。', 'myeasybuy-core' ) . '</p>';
			return;
		}

		$filters = self::read_filters();
		$page    = max( 1, absint( $current_page ) );
		$result  = self::query_rows( $filters, $page );
		$tabs    = self::tabs();

		include MYEASYBUY_CORE_DIR . 'templates/account/parcels.php';
	}

	/**
	 * @return array<string, string>
	 */
	public static function tabs(): array {
		return array(
			'all'        => __( '所有包裹', 'myeasybuy-core' ),
			'supplement' => __( '待补款', 'myeasybuy-core' ),
			'unpaid'     => __( '待付款', 'myeasybuy-core' ),
			'shipping'   => __( '寄送中', 'myeasybuy-core' ),
			'received'   => __( '已收到', 'myeasybuy-core' ),
			'cancelled'  => __( '已取消', 'myeasybuy-core' ),
			'done'       => __( '完全的', 'myeasybuy-core' ),
		);
	}

	/**
	 * Counts for the account overview. Same grouping rules as the parcels list.
	 *
	 * @return array{supplement: int, unpaid: int, shipping: int}
	 */
	public static function summary_counts(): array {
		$counts = array(
			'supplement' => 0,
			'unpaid'     => 0,
			'shipping'   => 0,
		);

		$found = wc_get_orders(
			array(
				'customer' => get_current_user_id(),
				'limit'    => 100,
				'orderby'  => 'date',
				'order'    => 'DESC',
				'paginate' => false,
				'status'   => self::candidate_statuses(),
			)
		);

		$groups = array();
		foreach ( $found as $order ) {
			if ( ! $order instanceof WC_Order || ! self::is_parcel( $order ) ) {
				continue;
			}
			$key = self::group_key( $order );
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array();
			}
			$groups[ $key ][] = $order;
		}

		foreach ( $groups as $orders ) {
			foreach ( array_keys( $counts ) as $tab ) {
				if ( self::group_in_tab( $orders, $tab ) ) {
					++$counts[ $tab ];
				}
			}
		}

		return $counts;
	}

	/**
	 * @return array<int, array{num: string, label: string}>
	 */
	public static function flow_steps(): array {
		return array(
			array(
				'num'   => '01',
				'label' => __( '支付国际运费', 'myeasybuy-core' ),
			),
			array(
				'num'   => '02',
				'label' => __( '等待收货', 'myeasybuy-core' ),
			),
			array(
				'num'   => '03',
				'label' => __( '已送达', 'myeasybuy-core' ),
			),
			array(
				'num'   => '04',
				'label' => __( '分享得奖励', 'myeasybuy-core' ),
			),
		);
	}

	/**
	 * @return array{tab: string, q: string, from: string, to: string, active: bool}
	 */
	private static function read_filters(): array {
		$tab = isset( $_GET['meb_tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['meb_tab'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( self::tabs()[ $tab ] ) ) {
			$tab = 'all';
		}

		$q    = isset( $_GET['meb_q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['meb_q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$from = self::read_date( 'meb_from' );
		$to   = self::read_date( 'meb_to' );

		return array(
			'tab'    => $tab,
			'q'      => $q,
			'from'   => $from,
			'to'     => $to,
			'active' => '' !== $q || '' !== $from || '' !== $to,
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
	 * Statuses that can become a parcel row before the PHP filter.
	 *
	 * @return string[]
	 */
	private static function candidate_statuses(): array {
		return array(
			'wc-awaiting-shipping',
			'awaiting-shipping-fee',
			'paid-shipping',
			'int-shipped',
			'completed',
			'cancelled',
		);
	}

	/**
	 * Posts storage keeps order statuses at 20 characters, so wc-awaiting-shipping-fee is stored as wc-awaiting-shipping.
	 * Queries must pass that stored value; get_status() still returns awaiting-shipping.
	 */
	private static function is_unpaid_shipping( string $status ): bool {
		return in_array( $status, array( 'awaiting-shipping-fee', 'awaiting-shipping' ), true );
	}

	/**
	 * @param array{tab: string, q: string, from: string, to: string, active: bool} $filters Filters.
	 * @param int                                                                   $page    Page.
	 * @return array{rows: array<int, array<string, mixed>>, total: int, pages: int}
	 */
	private static function query_rows( array $filters, int $page ): array {
		$found = wc_get_orders(
			array(
				'customer' => get_current_user_id(),
				'limit'    => 100,
				'orderby'  => 'date',
				'order'    => 'DESC',
				'paginate' => false,
				'status'   => self::candidate_statuses(),
			)
		);

		$groups = array();
		$keys   = array();

		foreach ( $found as $order ) {
			if ( ! $order instanceof WC_Order || ! self::is_parcel( $order ) ) {
				continue;
			}

			$key = self::group_key( $order );
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array();
				$keys[]         = $key;
			}
			$groups[ $key ][] = $order;
		}

		$rows = array();
		foreach ( $keys as $key ) {
			$orders = $groups[ $key ];
			if ( ! self::group_in_tab( $orders, $filters['tab'] ) ) {
				continue;
			}
			if ( '' !== $filters['q'] && ! self::group_matches( $orders, $filters['q'] ) ) {
				continue;
			}
			if ( ! self::group_in_dates( $orders[0], $filters['from'], $filters['to'] ) ) {
				continue;
			}
			$rows[] = self::present( $orders );
		}

		$total  = count( $rows );
		$pages  = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$page   = min( $page, $pages );
		$offset = ( $page - 1 ) * self::PER_PAGE;

		return array(
			'rows'  => array_slice( $rows, $offset, self::PER_PAGE ),
			'total' => $total,
			'pages' => $pages,
		);
	}

	/**
	 * A goods order that has entered the international parcel flow.
	 */
	private static function is_parcel( WC_Order $order ): bool {
		if ( self::is_second_payment( $order ) ) {
			return false;
		}

		$status = $order->get_status();
		if ( in_array( $status, array( 'awaiting-shipping-fee', 'awaiting-shipping', 'paid-shipping', 'int-shipped', 'completed' ), true ) ) {
			return true;
		}

		if ( 'cancelled' === $status ) {
			return self::had_parcel( $order );
		}

		return self::needs_supplement( $order );
	}

	/**
	 * Second-payment children are the shipping bill, not a parcel row.
	 */
	private static function is_second_payment( WC_Order $order ): bool {
		if ( ! class_exists( 'MyEasyBuy_Consolidation' ) ) {
			return false;
		}

		return MyEasyBuy_Consolidation::PAYMENT_TYPE_SECOND === (string) $order->get_meta( MyEasyBuy_Consolidation::META_PAYMENT_TYPE, true );
	}

	/**
	 * Cancelled orders stay on this page only after they were already a parcel.
	 */
	private static function had_parcel( WC_Order $order ): bool {
		if ( class_exists( 'MyEasyBuy_Customer_Consolidation' ) ) {
			$group = (string) $order->get_meta( MyEasyBuy_Customer_Consolidation::META_GROUP_ID, true );
			if ( '' !== $group ) {
				return true;
			}
		}

		if ( class_exists( 'MyEasyBuy_Consolidation' ) ) {
			$child = (int) $order->get_meta( MyEasyBuy_Consolidation::META_SECOND_PAYMENT_ORDER, true );
			$fee   = (float) $order->get_meta( MyEasyBuy_Consolidation::META_SECOND_PAYMENT_FEE, true );
			if ( $child > 0 || $fee > 0 ) {
				return true;
			}
		}

		return false;
	}

	private static function needs_supplement( WC_Order $order ): bool {
		return 'yes' === (string) $order->get_meta( self::META_BALANCE_DUE, true );
	}

	private static function is_delivered( WC_Order $order ): bool {
		return 'yes' === (string) $order->get_meta( self::META_DELIVERED, true ) || 'completed' === $order->get_status();
	}

	private static function group_key( WC_Order $order ): string {
		if ( class_exists( 'MyEasyBuy_Customer_Consolidation' ) ) {
			$group = (string) $order->get_meta( MyEasyBuy_Customer_Consolidation::META_GROUP_ID, true );
			if ( '' !== $group ) {
				return 'g:' . $group;
			}
		}

		return 'o:' . $order->get_id();
	}

	/**
	 * @param WC_Order[] $orders Orders in one parcel.
	 */
	private static function group_in_tab( array $orders, string $tab ): bool {
		$primary = $orders[0];
		$status  = $primary->get_status();

		switch ( $tab ) {
			case 'supplement':
				foreach ( $orders as $order ) {
					if ( self::needs_supplement( $order ) ) {
						return true;
					}
				}
				return false;
			case 'unpaid':
				return self::is_unpaid_shipping( $status );
			case 'shipping':
				return in_array( $status, array( 'paid-shipping', 'int-shipped' ), true ) && ! self::is_delivered( $primary );
			case 'received':
				return self::is_delivered( $primary );
			case 'cancelled':
				return 'cancelled' === $status;
			case 'done':
				return 'completed' === $status;
			default:
				return true;
		}
	}

	/**
	 * @param WC_Order[] $orders Orders in one parcel.
	 */
	private static function group_matches( array $orders, string $query ): bool {
		$needle = function_exists( 'mb_strtolower' ) ? mb_strtolower( $query ) : strtolower( $query );

		foreach ( $orders as $order ) {
			$hay = array(
				(string) $order->get_order_number(),
				(string) $order->get_id(),
			);

			if ( class_exists( 'MyEasyBuy_Customer_Consolidation' ) ) {
				$hay[] = (string) $order->get_meta( MyEasyBuy_Customer_Consolidation::META_GROUP_ID, true );
			}

			foreach ( $order->get_items() as $item ) {
				$hay[] = $item->get_name();
			}

			foreach ( $hay as $value ) {
				$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );
				if ( '' !== $value && false !== ( function_exists( 'mb_strpos' ) ? mb_strpos( $value, $needle ) : strpos( $value, $needle ) ) ) {
					return true;
				}
			}
		}

		return false;
	}

	private static function group_in_dates( WC_Order $order, string $from, string $to ): bool {
		if ( '' === $from && '' === $to ) {
			return true;
		}

		$created = $order->get_date_created();
		if ( ! $created ) {
			return false;
		}

		$day = $created->date( 'Y-m-d' );
		if ( '' !== $from && $day < $from ) {
			return false;
		}
		if ( '' !== $to && $day > $to ) {
			return false;
		}

		return true;
	}

	/**
	 * @param WC_Order[] $orders Orders in one parcel, newest first.
	 * @return array<string, mixed>
	 */
	private static function present( array $orders ): array {
		$primary = $orders[0];
		$name    = '';
		$thumb   = '';
		$count   = 0;

		foreach ( $orders as $order ) {
			$count += $order->get_item_count() - $order->get_item_count_refunded();
			foreach ( $order->get_items() as $item ) {
				if ( '' === $name ) {
					$name = $item->get_name();
				}
				if ( '' === $thumb && $item instanceof WC_Order_Item_Product ) {
					$product = $item->get_product();
					if ( $product instanceof WC_Product ) {
						$thumb = $product->get_image( 'woocommerce_thumbnail', array( 'class' => 'meb-parcels__thumb' ) );
					}
				}
			}
		}

		if ( '' === $name ) {
			$name = sprintf(
				/* translators: %s: order number */
				__( '包裹 %s', 'myeasybuy-core' ),
				$primary->get_order_number()
			);
		}

		$created = $primary->get_date_created();

		return array(
			'name'   => $name,
			'count'  => $count,
			'number' => $primary->get_order_number(),
			'date'   => $created ? wc_format_datetime( $created, 'Y-m-d' ) : '',
			'status' => self::status_label( $primary ),
			'total'  => self::format_amount( $primary ),
			'view'   => $primary->get_view_order_url(),
			'pay'    => self::pay_url( $primary ),
			'thumb'  => $thumb,
		);
	}

	/**
	 * Customer-facing parcel status.
	 */
	public static function status_label( WC_Order $order ): string {
		if ( self::needs_supplement( $order ) && 'completed' !== $order->get_status() && 'cancelled' !== $order->get_status() ) {
			return __( '待补款', 'myeasybuy-core' );
		}

		switch ( $order->get_status() ) {
			case 'awaiting-shipping-fee':
			case 'awaiting-shipping':
				return __( '待付款', 'myeasybuy-core' );
			case 'paid-shipping':
			case 'int-shipped':
				return self::is_delivered( $order ) ? __( '已收到', 'myeasybuy-core' ) : __( '寄送中', 'myeasybuy-core' );
			case 'completed':
				return __( '完全的', 'myeasybuy-core' );
			case 'cancelled':
				return __( '已取消', 'myeasybuy-core' );
			default:
				return wc_get_order_status_name( $order->get_status() );
		}
	}

	/**
	 * International shipping fee when one has been billed, otherwise the order total.
	 */
	private static function format_amount( WC_Order $order ): string {
		$fee = 0.0;
		if ( class_exists( 'MyEasyBuy_Consolidation' ) ) {
			$fee = (float) $order->get_meta( MyEasyBuy_Consolidation::META_SECOND_PAYMENT_FEE, true );
		}

		$value = $fee > 0 ? $fee : (float) $order->get_total();

		if ( class_exists( 'MyEasyBuy_Customer_Cart' ) && $order->get_currency() === get_woocommerce_currency() ) {
			return MyEasyBuy_Customer_Cart::money( $value );
		}

		return wp_strip_all_tags( wc_price( $value, array( 'currency' => $order->get_currency() ) ) );
	}

	/**
	 * Checkout URL for an unpaid international shipping bill.
	 */
	private static function pay_url( WC_Order $order ): string {
		if ( class_exists( 'MyEasyBuy_Consolidation' ) ) {
			$child_id = (int) $order->get_meta( MyEasyBuy_Consolidation::META_SECOND_PAYMENT_ORDER, true );
			if ( $child_id > 0 ) {
				$child = wc_get_order( $child_id );
				if ( $child instanceof WC_Order && $child->needs_payment() ) {
					return $child->get_checkout_payment_url();
				}
			}
		}

		if ( $order->needs_payment() ) {
			return $order->get_checkout_payment_url();
		}

		return '';
	}

	/**
	 * Parcels list URL, keeping the current filters.
	 *
	 * @param array{tab?: string, q?: string, from?: string, to?: string} $overrides Query overrides.
	 * @param int                                                          $page      Page number.
	 */
	public static function page_url( array $overrides = array(), int $page = 1 ): string {
		$filters = self::read_filters();
		$args    = array(
			'meb_tab'  => $overrides['tab'] ?? $filters['tab'],
			'meb_q'    => $overrides['q'] ?? $filters['q'],
			'meb_from' => $overrides['from'] ?? $filters['from'],
			'meb_to'   => $overrides['to'] ?? $filters['to'],
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

		$url = wc_get_endpoint_url( self::ENDPOINT, $page > 1 ? (string) $page : '', wc_get_page_permalink( 'myaccount' ) );

		return $args ? add_query_arg( $args, $url ) : $url;
	}
}
