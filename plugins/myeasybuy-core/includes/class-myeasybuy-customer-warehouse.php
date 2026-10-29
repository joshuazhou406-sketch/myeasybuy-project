<?php
/**
 * Customer My Account — warehouse (我的仓库).
 *
 * @package MyEasyBuy_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lists inbound packages and starts a consolidation from My Account.
 */
class MyEasyBuy_Customer_Warehouse {

	public const ENDPOINT = 'warehouse';

	private const DEFAULT_PER_PAGE = 50;

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'add_endpoint' ) );
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'add_query_var' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'account_menu_items' ), 120 );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'render_endpoint' ) );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_filter( 'document_title_parts', array( __CLASS__, 'document_title' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 120 );

		if ( get_option( 'myeasybuy_warehouse_endpoint_flushed' ) !== '1.13.0' ) {
			add_action( 'init', array( __CLASS__, 'maybe_flush_rewrite' ), 99 );
		}
	}

	/**
	 * Whether the current request is the warehouse screen.
	 */
	public static function is_page(): bool {
		return function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( self::ENDPOINT );
	}

	/**
	 * Account warehouse URL.
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
		update_option( 'myeasybuy_warehouse_endpoint_flushed', '1.13.0', false );
	}

	/**
	 * Place 我的仓库 after 我的订单.
	 *
	 * @param array<string, string> $items Menu items.
	 * @return array<string, string>
	 */
	public static function account_menu_items( array $items ): array {
		$label = __( '我的仓库', 'myeasybuy-core' );
		unset( $items[ self::ENDPOINT ] );

		$new = array();
		foreach ( $items as $key => $item_label ) {
			$new[ $key ] = $item_label;
			if ( 'orders' === $key ) {
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
			$classes[] = 'meb-account-warehouse';
		}

		return $classes;
	}

	/**
	 * @param array<string, string> $parts Title parts.
	 * @return array<string, string>
	 */
	public static function document_title( array $parts ): array {
		if ( self::is_page() ) {
			$parts['title'] = __( '我的仓库', 'myeasybuy-core' );
		}

		return $parts;
	}

	/**
	 * Assets for the warehouse screen.
	 */
	public static function enqueue_assets(): void {
		if ( ! self::is_page() ) {
			return;
		}

		wp_enqueue_style(
			'myeasybuy-customer-warehouse',
			MYEASYBUY_CORE_URL . 'assets/css/customer-warehouse.css',
			array( 'myeasybuy-storefront-palette' ),
			MYEASYBUY_CORE_VERSION
		);

		wp_enqueue_script(
			'myeasybuy-customer-warehouse',
			MYEASYBUY_CORE_URL . 'assets/js/customer-warehouse.js',
			array(),
			MYEASYBUY_CORE_VERSION,
			true
		);
	}

	/**
	 * Render the warehouse screen.
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
		$steps   = self::flow_steps();
		$days        = class_exists( 'MyEasyBuy_Customer_Inbound' ) ? MyEasyBuy_Customer_Inbound::get_free_storage_days() : 90;
		$channels    = class_exists( 'MyEasyBuy_Consolidation' ) ? MyEasyBuy_Consolidation::get_channels() : array();
		$air         = class_exists( 'MyEasyBuy_Consolidation' ) ? MyEasyBuy_Consolidation::CHANNEL_AIR : '';
		$preselected = self::preselected_ids();

		include MYEASYBUY_CORE_DIR . 'templates/account/warehouse.php';
	}

	/**
	 * @return array<string, string>
	 */
	public static function tabs(): array {
		return array(
			'ready' => __( '可创建包裹', 'myeasybuy-core' ),
			'hold'  => __( '暂不可创建包裹', 'myeasybuy-core' ),
		);
	}

	/**
	 * @return array<int, array{key: string, label: string, state: string, url: string}>
	 */
	public static function flow_steps(): array {
		$cart = class_exists( 'MyEasyBuy_Customer_Cart' ) ? MyEasyBuy_Customer_Cart::get_endpoint_url() : '';

		return array(
			array(
				'key'   => 'pick',
				'label' => __( '选择商品', 'myeasybuy-core' ),
				'state' => 'done',
				'url'   => $cart,
			),
			array(
				'key'   => 'pack',
				'label' => __( '提交包裹', 'myeasybuy-core' ),
				'state' => 'current',
				'url'   => '',
			),
			array(
				'key'   => 'freight',
				'label' => __( '支付国际运费', 'myeasybuy-core' ),
				'state' => 'wait',
				'url'   => '',
			),
			array(
				'key'   => 'receive',
				'label' => __( '等待收货', 'myeasybuy-core' ),
				'state' => 'wait',
				'url'   => '',
			),
			array(
				'key'   => 'done',
				'label' => __( '已送达', 'myeasybuy-core' ),
				'state' => 'wait',
				'url'   => '',
			),
		);
	}

	/**
	 * Order IDs carried over from the old 合箱申请 address.
	 *
	 * @return int[]
	 */
	private static function preselected_ids(): array {
		if ( empty( $_GET['meb_ids'] ) || ! is_string( $_GET['meb_ids'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return array();
		}

		$raw = sanitize_text_field( wp_unslash( $_GET['meb_ids'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return array_values( array_filter( array_map( 'absint', explode( ',', $raw ) ) ) );
	}

	/**
	 * Statuses included in a tab.
	 *
	 * @return string[]
	 */
	private static function tab_statuses( string $tab ): array {
		if ( 'hold' === $tab ) {
			return array( 'pending-manual', 'processing', 'purchasing', 'cn-shipped' );
		}

		return array( 'in-warehouse' );
	}

	/**
	 * Counts for the account overview. Same buckets as the warehouse tabs.
	 *
	 * @return array{ready: int, hold: int}
	 */
	public static function summary_counts(): array {
		$counts = array(
			'ready' => 0,
			'hold'  => 0,
		);

		foreach ( array_keys( $counts ) as $tab ) {
			$found = wc_get_orders(
				array(
					'customer' => get_current_user_id(),
					'limit'    => 100,
					'paginate' => false,
					'status'   => self::tab_statuses( $tab ),
				)
			);
			if ( ! is_array( $found ) ) {
				continue;
			}
			foreach ( $found as $order ) {
				if ( $order instanceof WC_Order && ! self::is_second_payment( $order ) ) {
					++$counts[ $tab ];
				}
			}
		}

		return $counts;
	}

	/**
	 * @return array{tab: string, q: string, cover: string, from: string, to: string, per: int, active: bool}
	 */
	private static function read_filters(): array {
		$tab = isset( $_GET['meb_tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['meb_tab'] ) ) : 'ready'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( self::tabs()[ $tab ] ) ) {
			$tab = 'ready';
		}

		$q     = isset( $_GET['meb_q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['meb_q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$cover = isset( $_GET['meb_cover'] ) ? sanitize_key( wp_unslash( (string) $_GET['meb_cover'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $cover, array( '', 'yes', 'no' ), true ) ) {
			$cover = '';
		}

		$per = isset( $_GET['meb_per'] ) ? absint( wp_unslash( (string) $_GET['meb_per'] ) ) : self::DEFAULT_PER_PAGE; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $per, array( 10, 20, 50 ), true ) ) {
			$per = self::DEFAULT_PER_PAGE;
		}

		$from = self::read_date( 'meb_from' );
		$to   = self::read_date( 'meb_to' );

		return array(
			'tab'    => $tab,
			'q'      => $q,
			'cover'  => $cover,
			'from'   => $from,
			'to'     => $to,
			'per'    => $per,
			'active' => '' !== $q || '' !== $cover || '' !== $from || '' !== $to,
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
	 * @param array{tab: string, q: string, cover: string, from: string, to: string, per: int, active: bool} $filters Filters.
	 * @param int                                                                                            $page    Page.
	 * @return array{rows: array<int, array<string, mixed>>, total: int, pages: int, page: int}
	 */
	private static function query_rows( array $filters, int $page ): array {
		$args = array(
			'customer' => get_current_user_id(),
			'limit'    => 100,
			'orderby'  => 'date',
			'order'    => 'DESC',
			'paginate' => false,
			'status'   => self::tab_statuses( $filters['tab'] ),
		);

		if ( '' !== $filters['from'] || '' !== $filters['to'] ) {
			$start                = '' !== $filters['from'] ? $filters['from'] : '2000-01-01';
			$end                  = '' !== $filters['to'] ? $filters['to'] : gmdate( 'Y-m-d' );
			$args['date_created'] = $start . '...' . $end;
		}

		$found = wc_get_orders( $args );
		$rows  = array();

		foreach ( $found as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			if ( self::is_second_payment( $order ) ) {
				continue;
			}
			if ( '' !== $filters['q'] && ! self::order_matches( $order, $filters['q'] ) ) {
				continue;
			}
			if ( ! self::cover_matches( $order, $filters['cover'] ) ) {
				continue;
			}
			$rows[] = self::present_row( $order, 'ready' === $filters['tab'] );
		}

		$total  = count( $rows );
		$pages  = max( 1, (int) ceil( $total / $filters['per'] ) );
		$page   = min( $page, $pages );
		$offset = ( $page - 1 ) * $filters['per'];

		return array(
			'rows'  => array_slice( $rows, $offset, $filters['per'] ),
			'total' => $total,
			'pages' => $pages,
			'page'  => $page,
		);
	}

	/**
	 * Fee-only international shipping orders are not warehouse goods.
	 */
	private static function is_second_payment( WC_Order $order ): bool {
		if ( ! class_exists( 'MyEasyBuy_Consolidation' ) ) {
			return false;
		}

		return MyEasyBuy_Consolidation::PAYMENT_TYPE_SECOND === (string) $order->get_meta( MyEasyBuy_Consolidation::META_PAYMENT_TYPE, true );
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
			$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $value ) : strtolower( (string) $value );
			if ( '' !== $value && false !== ( function_exists( 'mb_strpos' ) ? mb_strpos( $value, $needle ) : strpos( $value, $needle ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Protection support is stored on the order when it has been marked.
	 */
	private static function cover_matches( WC_Order $order, string $cover ): bool {
		if ( '' === $cover ) {
			return true;
		}

		$supported = 'yes' === (string) $order->get_meta( '_myeasybuy_protection', true );

		return 'yes' === $cover ? $supported : ! $supported;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function present_row( WC_Order $order, bool $selectable ): array {
		$items = $order->get_items( 'line_item' );
		$first = $items ? reset( $items ) : null;
		$name  = '';
		$thumb = '';
		$qty   = 0;

		foreach ( $items as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$qty += (int) $item->get_quantity();
			if ( '' === $name ) {
				$name = $item->get_name();
			}
		}

		if ( $first instanceof WC_Order_Item_Product ) {
			$product = $first->get_product();
			if ( $product instanceof WC_Product ) {
				$thumb = $product->get_image( array( 48, 48 ), array( 'class' => 'meb-wh__thumb', 'alt' => '' ) );
			}
		}

		$summary = class_exists( 'MyEasyBuy_Customer_Inbound' )
			? MyEasyBuy_Customer_Inbound::get_package_summary( $order )
			: array();

		if ( '' === $thumb && ! empty( $summary['photos'][0] ) ) {
			$thumb = '<img class="meb-wh__thumb" src="' . esc_url( (string) $summary['photos'][0] ) . '" alt="">';
		}

		$weight = (int) ( $summary['weight_grams'] ?? $order->get_meta( '_myeasybuy_actual_weight', true ) );
		$size   = (string) ( $summary['dimensions'] ?? $order->get_meta( '_myeasybuy_dimensions', true ) );
		$inbound = (string) ( $summary['inbound_at_label'] ?? '' );
		if ( '' === $inbound || '—' === $inbound ) {
			$inbound = '—';
		}

		$extra = count( $items ) - 1;
		if ( $extra > 0 && '' !== $name ) {
			$name .= sprintf(
				/* translators: %d: additional item count */
				__( ' 等%d件', 'myeasybuy-core' ),
				$extra + 1
			);
		}

		$service = trim( (string) $order->get_meta( '_myeasybuy_value_added', true ) );
		if ( '' === $service ) {
			$service = __( '无', 'myeasybuy-core' );
		}

		$status = class_exists( 'MyEasyBuy_Customer_Orders' )
			? MyEasyBuy_Customer_Orders::status_label( $order )
			: wc_get_order_status_name( $order->get_status() );

		return array(
			'id'         => (int) $order->get_id(),
			'name'       => '' !== $name ? $name : sprintf( /* translators: %s: order number */ __( '订单 %s', 'myeasybuy-core' ), $order->get_order_number() ),
			'order_no'   => (string) $order->get_order_number(),
			'thumb'      => $thumb,
			'inbound'    => $inbound,
			'qty'        => $qty,
			'weight'     => $weight > 0 ? (string) $weight : '',
			'size'       => '' !== $size ? $size : '—',
			'service'    => $service,
			'status'     => $status,
			'type'       => self::goods_type( $order ),
			'view'       => $order->get_view_order_url(),
			'selectable' => $selectable,
		);
	}

	/**
	 * Taobao / JD when the order or its items record a source.
	 */
	private static function goods_type( WC_Order $order ): string {
		$blob = strtolower( (string) $order->get_meta( '_myeasybuy_source_url', true ) );

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			foreach ( $item->get_meta_data() as $meta ) {
				if ( ! is_scalar( $meta->value ) ) {
					continue;
				}
				$key   = strtolower( (string) $meta->key );
				$value = strtolower( (string) $meta->value );
				if ( false !== strpos( $key, 'platform' ) ) {
					if ( false !== strpos( $value, 'jd' ) || false !== strpos( $value, '京东' ) ) {
						return __( '京东', 'myeasybuy-core' );
					}
					if ( false !== strpos( $value, 'taobao' ) || false !== strpos( $value, 'tb' ) || false !== strpos( $value, '淘宝' ) ) {
						return __( '淘宝', 'myeasybuy-core' );
					}
				}
				$blob .= ' ' . $value;
			}
			$product = $item->get_product();
			if ( $product instanceof WC_Product ) {
				$blob .= ' ' . strtolower( (string) $product->get_meta( '_myeasybuy_source_url' ) );
			}
		}

		if ( false !== strpos( $blob, 'jd.com' ) || false !== strpos( $blob, 'jingdong' ) ) {
			return __( '京东', 'myeasybuy-core' );
		}
		if ( false !== strpos( $blob, 'taobao' ) || false !== strpos( $blob, 'tmall' ) ) {
			return __( '淘宝', 'myeasybuy-core' );
		}

		return __( '代购', 'myeasybuy-core' );
	}

	/**
	 * Stepper icon. Markup is fixed, not user content.
	 */
	public static function icon( string $key ): string {
		$icons = array(
			'pick'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="19" r="1.2"/><circle cx="17" cy="19" r="1.2"/><path d="M3 4h2.2l1.6 9.2a1 1 0 0 0 1 .8h8.8a1 1 0 0 0 1-.8L19.2 8H7"/><path d="M12 10v3.2"/><path d="m10.4 11.6 1.6 1.6 1.6-1.6"/></svg>',
			'pack'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8.5 12 4l8 4.5V18L12 22 4 18z"/><path d="m4 8.5 8 4.5 8-4.5"/><path d="M12 13v9"/><path d="M15 6h4.2V10"/><path d="M19.2 6 14.5 10.5"/></svg>',
			'freight' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3 16h18l-1.6 3.2a1 1 0 0 1-.9.6H5.5a1 1 0 0 1-.9-.6z"/><path d="M6 16V9.5h7.5L18 16"/><path d="M12 4.2 10.2 8h3.6z"/></svg>',
			'receive' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8.5 11 5l7 3.5V16L11 19.5 4 16z"/><path d="m4 8.5 7 3.5 7-3.5"/><circle cx="17.5" cy="16.5" r="4"/><path d="M17.5 14.8V16.6l1.2.8"/></svg>',
			'done'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12a7 7 0 1 1-2-5"/><path d="M19 4.5V9h-4.5"/><path d="m8.5 12.2 2.2 2.2 4-4.2"/></svg>',
		);

		return $icons[ $key ] ?? '';
	}

	/**
	 * Warehouse URL, keeping the current filters.
	 *
	 * @param array{tab?: string, q?: string, cover?: string, from?: string, to?: string, per?: int} $overrides Query overrides.
	 * @param int                                                                                    $page      Page number.
	 */
	public static function page_url( array $overrides = array(), int $page = 1 ): string {
		$filters = self::read_filters();
		$args    = array(
			'meb_tab'   => $overrides['tab'] ?? $filters['tab'],
			'meb_q'     => $overrides['q'] ?? $filters['q'],
			'meb_cover' => $overrides['cover'] ?? $filters['cover'],
			'meb_from'  => $overrides['from'] ?? $filters['from'],
			'meb_to'    => $overrides['to'] ?? $filters['to'],
			'meb_per'   => (string) ( $overrides['per'] ?? $filters['per'] ),
		);

		$args = array_filter(
			$args,
			static function ( $value, $key ): bool {
				if ( 'meb_tab' === $key ) {
					return 'ready' !== $value && '' !== $value;
				}
				if ( 'meb_per' === $key ) {
					return (string) self::DEFAULT_PER_PAGE !== (string) $value && '' !== $value;
				}
				return '' !== $value;
			},
			ARRAY_FILTER_USE_BOTH
		);

		$url = wc_get_endpoint_url( self::ENDPOINT, $page > 1 ? $page : '', wc_get_page_permalink( 'myaccount' ) );

		return $args ? add_query_arg( $args, $url ) : $url;
	}
}
