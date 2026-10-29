<?php
/**
 * Customer My Account — parcel rehearsal (包裹预演).
 *
 * @package MyEasyBuy_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lists rehearsal requests and accepts new ones from 我的仓库.
 */
class MyEasyBuy_Customer_Rehearsal {

	public const ENDPOINT = 'rehearsal';

	public const META = '_myeasybuy_rehearsals';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'add_endpoint' ) );
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'add_query_var' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'account_menu_items' ), 130 );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'render_endpoint' ) );
		add_action( 'admin_post_myeasybuy_request_rehearsal', array( __CLASS__, 'handle_request' ) );
		add_action( 'admin_post_myeasybuy_cancel_rehearsal', array( __CLASS__, 'handle_cancel' ) );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_filter( 'document_title_parts', array( __CLASS__, 'document_title' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 120 );

		if ( get_option( 'myeasybuy_rehearsal_endpoint_flushed' ) !== '1.14.0' ) {
			add_action( 'init', array( __CLASS__, 'maybe_flush_rewrite' ), 99 );
		}
	}

	/**
	 * Whether the current request is the rehearsal screen.
	 */
	public static function is_page(): bool {
		return function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( self::ENDPOINT );
	}

	/**
	 * Account rehearsal URL.
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
		update_option( 'myeasybuy_rehearsal_endpoint_flushed', '1.14.0', false );
	}

	/**
	 * Place 包裹预演 after 我的包裹.
	 *
	 * @param array<string, string> $items Menu items.
	 * @return array<string, string>
	 */
	public static function account_menu_items( array $items ): array {
		$label = __( '包裹预演', 'myeasybuy-core' );
		unset( $items[ self::ENDPOINT ] );
		$anchor = isset( $items['parcels'] ) ? 'parcels' : 'warehouse';

		$new = array();
		foreach ( $items as $key => $item_label ) {
			$new[ $key ] = $item_label;
			if ( $anchor === $key ) {
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
			$classes[] = 'meb-account-rehearsal';
		}

		return $classes;
	}

	/**
	 * @param array<string, string> $parts Title parts.
	 * @return array<string, string>
	 */
	public static function document_title( array $parts ): array {
		if ( self::is_page() ) {
			$parts['title'] = __( '包裹预演', 'myeasybuy-core' );
		}

		return $parts;
	}

	/**
	 * Assets for the rehearsal screen.
	 */
	public static function enqueue_assets(): void {
		if ( ! self::is_page() ) {
			return;
		}

		wp_enqueue_style(
			'myeasybuy-customer-rehearsal',
			MYEASYBUY_CORE_URL . 'assets/css/customer-rehearsal.css',
			array( 'myeasybuy-storefront-palette' ),
			MYEASYBUY_CORE_VERSION
		);

		wp_enqueue_script(
			'myeasybuy-customer-rehearsal',
			MYEASYBUY_CORE_URL . 'assets/js/customer-rehearsal.js',
			array(),
			MYEASYBUY_CORE_VERSION,
			true
		);
	}

	/**
	 * Render the rehearsal list.
	 */
	public static function render_endpoint(): void {
		if ( ! is_user_logged_in() ) {
			echo '<p>' . esc_html__( '请先登录。', 'myeasybuy-core' ) . '</p>';
			return;
		}

		$filters = self::read_filters();
		$result  = self::query_rows( get_current_user_id(), $filters );
		$tabs    = self::tabs();
		$steps   = self::flow_steps();

		include MYEASYBUY_CORE_DIR . 'templates/account/rehearsal.php';
	}

	/**
	 * @return array<string, string>
	 */
	public static function statuses(): array {
		return array(
			'unpaid'     => __( '待支付', 'myeasybuy-core' ),
			'processing' => __( '处理中', 'myeasybuy-core' ),
			'completed'  => __( '已完成', 'myeasybuy-core' ),
			'cancelled'  => __( '已取消', 'myeasybuy-core' ),
		);
	}

	/**
	 * @return array<string, string>
	 */
	public static function tabs(): array {
		return array(
			'all'        => __( '全部包裹', 'myeasybuy-core' ),
			'unpaid'     => __( '待支付', 'myeasybuy-core' ),
			'processing' => __( '处理中', 'myeasybuy-core' ),
			'completed'  => __( '已完成', 'myeasybuy-core' ),
			'cancelled'  => __( '已取消', 'myeasybuy-core' ),
		);
	}

	/**
	 * @return array<int, array{key: string, label: string, state: string}>
	 */
	public static function flow_steps(): array {
		return array(
			array(
				'key'   => 'submit',
				'label' => __( '提交预演', 'myeasybuy-core' ),
				'state' => 'current',
			),
			array(
				'key'   => 'photo',
				'label' => __( '图清预演成功', 'myeasybuy-core' ),
				'state' => 'wait',
			),
			array(
				'key'   => 'freight',
				'label' => __( '运费', 'myeasybuy-core' ),
				'state' => 'wait',
			),
			array(
				'key'   => 'wait',
				'label' => __( '等待收货', 'myeasybuy-core' ),
				'state' => 'wait',
			),
			array(
				'key'   => 'confirm',
				'label' => __( '确认收货', 'myeasybuy-core' ),
				'state' => 'wait',
			),
		);
	}

	/**
	 * Stepper icon. Markup is fixed, not user content.
	 */
	public static function icon( string $key ): string {
		$icons = array(
			'submit'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="3.5" width="14" height="17" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/></svg>',
			'photo'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8.5 12 4l8 4.5V18L12 22 4 18z"/><circle cx="12" cy="13" r="2.2"/></svg>',
			'freight' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="7" width="11" height="8" rx="1"/><path d="M14 10h4l3 3v2h-7z"/><circle cx="7.5" cy="17.5" r="1.4"/><circle cx="17.5" cy="17.5" r="1.4"/></svg>',
			'wait'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3 16h14l2-6H8z"/><path d="M3 16v3h2M17 16v3"/><path d="M18 8.5 21 6M18 5.5V9h3"/></svg>',
			'confirm' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="4" width="16" height="16" rx="2"/><path d="m8 12.2 2.6 2.6L16.2 9"/></svg>',
		);

		return $icons[ $key ] ?? '';
	}

	/**
	 * Saved rehearsals for a customer.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_records( int $user_id ): array {
		$raw = get_user_meta( $user_id, self::META, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$statuses = self::statuses();
		$rows     = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) || empty( $row['id'] ) ) {
				continue;
			}
			$status = (string) ( $row['status'] ?? '' );
			if ( ! isset( $statuses[ $status ] ) ) {
				continue;
			}
			$rows[] = $row;
		}

		return $rows;
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
	 * @param array{tab: string, q: string, from: string, to: string, active: bool} $filters Filters.
	 * @return array{rows: array<int, array<string, mixed>>, counts: array<string, int>}
	 */
	private static function query_rows( int $user_id, array $filters ): array {
		$records = self::get_records( $user_id );
		$counts  = array(
			'unpaid'     => 0,
			'processing' => 0,
		);

		foreach ( $records as $row ) {
			$status = (string) $row['status'];
			if ( isset( $counts[ $status ] ) ) {
				++$counts[ $status ];
			}
		}

		$rows = array();
		foreach ( $records as $row ) {
			if ( 'all' !== $filters['tab'] && (string) $row['status'] !== $filters['tab'] ) {
				continue;
			}
			if ( ! self::matches_query( $row, $filters['q'] ) ) {
				continue;
			}
			if ( ! self::matches_dates( $row, $filters['from'], $filters['to'] ) ) {
				continue;
			}
			$rows[] = $row;
		}

		return array(
			'rows'   => $rows,
			'counts' => $counts,
		);
	}

	/**
	 * @param array<string, mixed> $row Record.
	 */
	private static function matches_query( array $row, string $query ): bool {
		if ( '' === $query ) {
			return true;
		}

		$needle = function_exists( 'mb_strtolower' ) ? mb_strtolower( $query ) : strtolower( $query );
		$hay    = array( (string) ( $row['id'] ?? '' ) );
		if ( ! empty( $row['names'] ) && is_array( $row['names'] ) ) {
			foreach ( $row['names'] as $name ) {
				$hay[] = (string) $name;
			}
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
	 * @param array<string, mixed> $row Record.
	 */
	private static function matches_dates( array $row, string $from, string $to ): bool {
		if ( '' === $from && '' === $to ) {
			return true;
		}

		$created = substr( (string) ( $row['created_at'] ?? '' ), 0, 10 );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $created ) ) {
			return false;
		}
		if ( '' !== $from && $created < $from ) {
			return false;
		}
		if ( '' !== $to && $created > $to ) {
			return false;
		}

		return true;
	}

	/**
	 * Rehearsal list URL, keeping the current filters.
	 *
	 * @param array{tab?: string, q?: string, from?: string, to?: string} $overrides Query overrides.
	 */
	public static function page_url( array $overrides = array() ): string {
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

		$url = self::get_endpoint_url();

		return $args ? add_query_arg( $args, $url ) : $url;
	}

	/**
	 * Create a rehearsal from selected warehouse orders.
	 */
	public static function handle_request(): void {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( '请先登录。', 'myeasybuy-core' ) );
		}

		self::load_notices();
		check_admin_referer( 'myeasybuy_request_rehearsal', 'myeasybuy_rehearsal_nonce' );

		$user_id = get_current_user_id();
		$ids     = isset( $_POST['order_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['order_ids'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$ids     = array_values( array_unique( array_filter( $ids ) ) );
		$orders  = array();

		foreach ( $ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order || (int) $order->get_customer_id() !== $user_id ) {
				continue;
			}
			if ( 'in-warehouse' !== $order->get_status() ) {
				continue;
			}
			if ( class_exists( 'MyEasyBuy_Consolidation' ) && MyEasyBuy_Consolidation::PAYMENT_TYPE_SECOND === (string) $order->get_meta( MyEasyBuy_Consolidation::META_PAYMENT_TYPE, true ) ) {
				continue;
			}
			$orders[] = $order;
		}

		$back = class_exists( 'MyEasyBuy_Customer_Warehouse' ) ? MyEasyBuy_Customer_Warehouse::get_endpoint_url() : self::get_endpoint_url();

		if ( empty( $orders ) ) {
			wc_add_notice( __( '请选择已入库、可以预演的商品。', 'myeasybuy-core' ), 'error' );
			wp_safe_redirect( $back );
			exit;
		}

		$records   = self::get_records( $user_id );
		$records   = array_merge( array( self::build_record( $orders, count( $records ) ) ), $records );
		update_user_meta( $user_id, self::META, $records );

		wc_add_notice( __( '已提交包裹预演。仓库完成图清后，会在这里更新重量、尺寸和运费。', 'myeasybuy-core' ), 'success' );
		wp_safe_redirect( self::get_endpoint_url() );
		exit;
	}

	/**
	 * Cancel a rehearsal that is still open.
	 */
	public static function handle_cancel(): void {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( '请先登录。', 'myeasybuy-core' ) );
		}

		self::load_notices();
		check_admin_referer( 'myeasybuy_cancel_rehearsal', 'myeasybuy_rehearsal_nonce' );

		$user_id = get_current_user_id();
		$id      = isset( $_POST['rehearsal_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['rehearsal_id'] ) ) : '';
		$records = self::get_records( $user_id );
		$found   = false;

		foreach ( $records as $index => $row ) {
			if ( (string) $row['id'] !== $id ) {
				continue;
			}
			if ( in_array( (string) $row['status'], array( 'processing', 'unpaid' ), true ) ) {
				$records[ $index ]['status'] = 'cancelled';
				$found                       = true;
			}
			break;
		}

		if ( $found ) {
			update_user_meta( $user_id, self::META, $records );
			wc_add_notice( __( '已取消该预演。', 'myeasybuy-core' ), 'success' );
		} else {
			wc_add_notice( __( '这份预演不能取消。', 'myeasybuy-core' ), 'error' );
		}

		wp_safe_redirect( self::get_endpoint_url() );
		exit;
	}

	/**
	 * WooCommerce notices are not loaded on admin-post until the cart session starts.
	 */
	private static function load_notices(): void {
		if ( function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}
		if ( ! function_exists( 'wc_add_notice' ) && defined( 'WC_ABSPATH' ) ) {
			include_once WC_ABSPATH . 'includes/wc-notice-functions.php';
		}
	}

	/**
	 * @param WC_Order[] $orders Warehouse orders.
	 * @return array<string, mixed>
	 */
	private static function build_record( array $orders, int $existing_count ): array {
		$names  = array();
		$qty    = 0;
		$weight = 0;
		$sizes  = array();

		foreach ( $orders as $order ) {
			foreach ( $order->get_items( 'line_item' ) as $item ) {
				if ( ! $item instanceof WC_Order_Item_Product ) {
					continue;
				}
				$qty    += (int) $item->get_quantity();
				$names[] = $item->get_name();
			}
			$grams = (int) $order->get_meta( '_myeasybuy_actual_weight', true );
			if ( $grams > 0 ) {
				$weight += $grams;
			}
			$size = trim( (string) $order->get_meta( '_myeasybuy_dimensions', true ) );
			if ( '' !== $size ) {
				$sizes[] = $size;
			}
		}

		$number = 'RH' . wp_date( 'ymd' ) . str_pad( (string) ( $existing_count + 1 ), 3, '0', STR_PAD_LEFT );

		return array(
			'id'         => $number,
			'created_at' => wp_date( 'Y-m-d H:i' ),
			'status'     => 'processing',
			'names'      => $names,
			'qty'        => $qty,
			'weight_g'   => $weight,
			'dimensions' => 1 === count( $sizes ) ? $sizes[0] : '',
			'route'      => '',
			'fee'        => '',
		);
	}
}
