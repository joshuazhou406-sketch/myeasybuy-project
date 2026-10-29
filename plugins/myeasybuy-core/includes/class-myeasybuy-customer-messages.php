<?php
/**
 * Customer My Account — messages (我的消息).
 *
 * @package MyEasyBuy_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Inbox for messages the shop sends to a customer.
 */
class MyEasyBuy_Customer_Messages {

	public const ENDPOINT = 'messages';

	public const META = '_myeasybuy_messages';

	private const DEFAULT_PER_PAGE = 20;

	private const MAX_STORED = 200;

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'add_endpoint' ) );
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'add_query_var' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'account_menu_items' ), 135 );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'render_endpoint' ) );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_filter( 'document_title_parts', array( __CLASS__, 'document_title' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 120 );
		add_action( 'admin_post_myeasybuy_messages_update', array( __CLASS__, 'handle_update' ) );
		add_action( MyEasyBuy_Hooks::ACTION_ORDER_STATUS_CHANGED, array( __CLASS__, 'on_order_status_changed' ), 20, 3 );
		add_action( 'woocommerce_new_customer_note', array( __CLASS__, 'on_customer_note' ) );

		if ( get_option( 'myeasybuy_messages_endpoint_flushed' ) !== '1.16.0' ) {
			add_action( 'init', array( __CLASS__, 'maybe_flush_rewrite' ), 99 );
		}
	}

	/**
	 * Whether the current request is the messages screen.
	 */
	public static function is_page(): bool {
		return function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( self::ENDPOINT );
	}

	/**
	 * Account messages URL.
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
		update_option( 'myeasybuy_messages_endpoint_flushed', '1.16.0', false );
	}

	/**
	 * Place 我的消息 after 包裹预演.
	 *
	 * @param array<string, string> $items Menu items.
	 * @return array<string, string>
	 */
	public static function account_menu_items( array $items ): array {
		$label  = __( '我的消息', 'myeasybuy-core' );
		$anchor = isset( $items['rehearsal'] ) ? 'rehearsal' : 'parcels';
		unset( $items[ self::ENDPOINT ] );

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
			$classes[] = 'meb-account-messages';
		}

		return $classes;
	}

	/**
	 * @param array<string, string> $parts Title parts.
	 * @return array<string, string>
	 */
	public static function document_title( array $parts ): array {
		if ( self::is_page() ) {
			$parts['title'] = __( '我的消息', 'myeasybuy-core' );
		}

		return $parts;
	}

	/**
	 * Assets for the messages screen.
	 */
	public static function enqueue_assets(): void {
		if ( ! self::is_page() ) {
			return;
		}

		wp_enqueue_style(
			'myeasybuy-customer-messages',
			MYEASYBUY_CORE_URL . 'assets/css/customer-messages.css',
			array( 'myeasybuy-storefront-palette' ),
			MYEASYBUY_CORE_VERSION
		);

		wp_enqueue_script(
			'myeasybuy-customer-messages',
			MYEASYBUY_CORE_URL . 'assets/js/customer-messages.js',
			array(),
			MYEASYBUY_CORE_VERSION,
			true
		);
	}

	/**
	 * Render the messages screen.
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
		$result  = self::query_rows( get_current_user_id(), $filters, $page );
		$counts  = self::counts( get_current_user_id() );
		$tabs    = self::tabs();

		include MYEASYBUY_CORE_DIR . 'templates/account/messages.php';
	}

	/**
	 * @return array<string, string>
	 */
	public static function tabs(): array {
		return array(
			'all'    => __( '全部', 'myeasybuy-core' ),
			'unread' => __( '未读', 'myeasybuy-core' ),
		);
	}

	/**
	 * @return int[]
	 */
	public static function per_page_options(): array {
		return array( 10, 20, 50 );
	}

	/**
	 * Store a message for a customer. Newest first.
	 *
	 * @param array{title?: string, body?: string, order_id?: int, order_number?: string, product?: string, parcel?: string, key?: string} $message Message fields.
	 */
	public static function push( int $user_id, array $message ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		$title = trim( (string) ( $message['title'] ?? '' ) );
		if ( '' === $title ) {
			return;
		}

		$stored = self::all( $user_id );
		$key    = (string) ( $message['key'] ?? '' );
		if ( '' !== $key && self::has_key( $stored, $key ) ) {
			return;
		}

		$record = array(
			'id'           => self::next_id( $stored ),
			'created_at'   => wp_date( 'Y-m-d H:i' ),
			'title'        => $title,
			'body'         => trim( (string) ( $message['body'] ?? '' ) ),
			'read'         => false,
			'order_id'     => absint( $message['order_id'] ?? 0 ),
			'order_number' => (string) ( $message['order_number'] ?? '' ),
			'product'      => (string) ( $message['product'] ?? '' ),
			'parcel'       => (string) ( $message['parcel'] ?? '' ),
			'key'          => $key,
		);

		array_unshift( $stored, $record );
		if ( count( $stored ) > self::MAX_STORED ) {
			$stored = array_slice( $stored, 0, self::MAX_STORED );
		}

		update_user_meta( $user_id, self::META, $stored );
	}

	/**
	 * Tell the customer when their order moves to a status they can act on.
	 *
	 * @param int    $order_id   Order ID.
	 * @param string $old_status Previous status, wc- prefixed.
	 * @param string $new_status New status, wc- prefixed.
	 */
	public static function on_order_status_changed( int $order_id, string $old_status, string $new_status ): void {
		unset( $old_status );

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$user_id = (int) $order->get_customer_id();
		if ( $user_id <= 0 || self::is_second_payment( $order ) ) {
			return;
		}

		$status = str_replace( 'wc-', '', $new_status );
		$copy   = self::status_copy( $status, $order );
		if ( null === $copy ) {
			return;
		}

		$product = self::first_product_name( $order );
		$parcel  = '';
		if ( class_exists( 'MyEasyBuy_Customer_Consolidation' ) ) {
			$parcel = (string) $order->get_meta( MyEasyBuy_Customer_Consolidation::META_GROUP_ID, true );
		}

		self::push(
			$user_id,
			array(
				'title'        => $copy['title'],
				'body'         => $copy['body'],
				'order_id'     => $order->get_id(),
				'order_number' => (string) $order->get_order_number(),
				'product'      => $product,
				'parcel'       => $parcel,
				'key'          => 'status:' . $order->get_id() . ':' . $status,
			)
		);
	}

	/**
	 * Keep WooCommerce customer order notes in the same inbox.
	 *
	 * @param array<string, mixed> $note Note payload.
	 */
	public static function on_customer_note( $note ): void {
		if ( ! is_array( $note ) ) {
			return;
		}

		$order = wc_get_order( absint( $note['order_id'] ?? 0 ) );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$body = trim( wp_strip_all_tags( (string) ( $note['customer_note'] ?? '' ) ) );
		if ( '' === $body || self::is_second_payment( $order ) ) {
			return;
		}

		$product = self::first_product_name( $order );

		self::push(
			(int) $order->get_customer_id(),
			array(
				'title'        => sprintf(
					/* translators: %s: order number */
					__( '订单 %s 有新留言', 'myeasybuy-core' ),
					$order->get_order_number()
				),
				'body'         => $body,
				'order_id'     => $order->get_id(),
				'order_number' => (string) $order->get_order_number(),
				'product'      => $product,
				'key'          => 'note:' . $order->get_id() . ':' . md5( $body ),
			)
		);
	}

	/**
	 * Mark selected messages read, or delete them.
	 */
	public static function handle_update(): void {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
			exit;
		}

		check_admin_referer( 'myeasybuy_messages_update' );

		$user_id = get_current_user_id();
		$action  = isset( $_POST['meb_do'] ) ? sanitize_key( wp_unslash( (string) $_POST['meb_do'] ) ) : '';
		$ids     = isset( $_POST['message_ids'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['message_ids'] ) ) : array();
		$ids     = array_values( array_filter( $ids ) );
		$stored  = self::all( $user_id );

		if ( $ids ) {
			if ( 'delete' === $action ) {
				$stored = array_values(
					array_filter(
						$stored,
						static function ( $message ) use ( $ids ): bool {
							return ! in_array( (string) ( $message['id'] ?? '' ), $ids, true );
						}
					)
				);
			} elseif ( 'read' === $action ) {
				foreach ( $stored as &$message ) {
					if ( in_array( (string) ( $message['id'] ?? '' ), $ids, true ) ) {
						$message['read'] = true;
					}
				}
				unset( $message );
			}
			update_user_meta( $user_id, self::META, $stored );
		}

		$tab  = isset( $_POST['meb_tab'] ) ? sanitize_key( wp_unslash( (string) $_POST['meb_tab'] ) ) : 'all';
		$q    = isset( $_POST['meb_q'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['meb_q'] ) ) : '';
		$per  = isset( $_POST['meb_per'] ) ? absint( $_POST['meb_per'] ) : self::DEFAULT_PER_PAGE;
		$page = isset( $_POST['meb_paged'] ) ? absint( $_POST['meb_paged'] ) : 1;

		wp_safe_redirect( self::page_url( array( 'tab' => $tab, 'q' => $q, 'per' => $per ), max( 1, $page ) ) );
		exit;
	}

	/**
	 * @return array{tab: string, q: string, per: int, active: bool}
	 */
	private static function read_filters(): array {
		$tab = isset( $_GET['meb_tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['meb_tab'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( self::tabs()[ $tab ] ) ) {
			$tab = 'all';
		}

		$q   = isset( $_GET['meb_q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['meb_q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per = isset( $_GET['meb_per'] ) ? absint( $_GET['meb_per'] ) : self::DEFAULT_PER_PAGE; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $per, self::per_page_options(), true ) ) {
			$per = self::DEFAULT_PER_PAGE;
		}

		return array(
			'tab'    => $tab,
			'q'      => $q,
			'per'    => $per,
			'active' => '' !== $q,
		);
	}

	/**
	 * @param array{tab: string, q: string, per: int, active: bool} $filters Filters.
	 * @return array{rows: array<int, array<string, mixed>>, total: int, pages: int}
	 */
	private static function query_rows( int $user_id, array $filters, int $page ): array {
		$rows = array();
		foreach ( self::all( $user_id ) as $message ) {
			if ( 'unread' === $filters['tab'] && ! empty( $message['read'] ) ) {
				continue;
			}
			if ( '' !== $filters['q'] && ! self::matches( $message, $filters['q'] ) ) {
				continue;
			}
			$rows[] = $message;
		}

		$total  = count( $rows );
		$pages  = max( 1, (int) ceil( $total / $filters['per'] ) );
		$page   = min( $page, $pages );
		$offset = ( $page - 1 ) * $filters['per'];

		return array(
			'rows'  => array_slice( $rows, $offset, $filters['per'] ),
			'total' => $total,
			'pages' => $pages,
		);
	}

	/**
	 * @return array{all: int, unread: int}
	 */
	private static function counts( int $user_id ): array {
		$all    = self::all( $user_id );
		$unread = 0;
		foreach ( $all as $message ) {
			if ( empty( $message['read'] ) ) {
				++$unread;
			}
		}

		return array(
			'all'    => count( $all ),
			'unread' => $unread,
		);
	}

	/**
	 * @param array<string, mixed> $message Message.
	 */
	private static function matches( array $message, string $query ): bool {
		$needle = function_exists( 'mb_strtolower' ) ? mb_strtolower( $query ) : strtolower( $query );
		$hay    = array(
			(string) ( $message['title'] ?? '' ),
			(string) ( $message['body'] ?? '' ),
			(string) ( $message['order_number'] ?? '' ),
			(string) ( $message['order_id'] ?? '' ),
			(string) ( $message['product'] ?? '' ),
			(string) ( $message['parcel'] ?? '' ),
		);

		foreach ( $hay as $value ) {
			$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );
			if ( '' !== $value && false !== ( function_exists( 'mb_strpos' ) ? mb_strpos( $value, $needle ) : strpos( $value, $needle ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Messages list URL, keeping the current filters.
	 *
	 * @param array{tab?: string, q?: string, per?: int} $overrides Query overrides.
	 */
	public static function page_url( array $overrides = array(), int $page = 1 ): string {
		$filters = self::read_filters();
		$tab     = $overrides['tab'] ?? $filters['tab'];
		$q       = $overrides['q'] ?? $filters['q'];
		$per     = isset( $overrides['per'] ) ? absint( $overrides['per'] ) : $filters['per'];
		if ( ! isset( self::tabs()[ $tab ] ) ) {
			$tab = 'all';
		}
		if ( ! in_array( $per, self::per_page_options(), true ) ) {
			$per = self::DEFAULT_PER_PAGE;
		}

		$args = array();
		if ( 'all' !== $tab ) {
			$args['meb_tab'] = $tab;
		}
		if ( '' !== $q ) {
			$args['meb_q'] = $q;
		}
		if ( self::DEFAULT_PER_PAGE !== $per ) {
			$args['meb_per'] = $per;
		}

		$url = wc_get_endpoint_url( self::ENDPOINT, $page > 1 ? (string) $page : '', wc_get_page_permalink( 'myaccount' ) );

		return $args ? add_query_arg( $args, $url ) : $url;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function all( int $user_id ): array {
		$stored = get_user_meta( $user_id, self::META, true );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * @param array<int, array<string, mixed>> $messages Messages.
	 */
	private static function has_key( array $messages, string $key ): bool {
		foreach ( $messages as $message ) {
			if ( $key === (string) ( $message['key'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<int, array<string, mixed>> $messages Messages.
	 */
	private static function next_id( array $messages ): string {
		$prefix = 'MS' . wp_date( 'ymd' );
		$count  = 0;
		foreach ( $messages as $message ) {
			if ( str_starts_with( (string) ( $message['id'] ?? '' ), $prefix ) ) {
				++$count;
			}
		}

		return $prefix . str_pad( (string) ( $count + 1 ), 3, '0', STR_PAD_LEFT );
	}

	private static function is_second_payment( WC_Order $order ): bool {
		if ( ! class_exists( 'MyEasyBuy_Consolidation' ) ) {
			return false;
		}

		return MyEasyBuy_Consolidation::PAYMENT_TYPE_SECOND === (string) $order->get_meta( MyEasyBuy_Consolidation::META_PAYMENT_TYPE, true );
	}

	private static function first_product_name( WC_Order $order ): string {
		foreach ( $order->get_items() as $item ) {
			$name = $item->get_name();
			if ( '' !== $name ) {
				return $name;
			}
		}

		return '';
	}

	/**
	 * @return array{title: string, body: string}|null
	 */
	private static function status_copy( string $status, WC_Order $order ): ?array {
		$number = $order->get_order_number();

		switch ( $status ) {
			case 'processing':
				return array(
					'title' => sprintf( __( '订单 %s 已支付', 'myeasybuy-core' ), $number ),
					'body'  => __( '我们已收到付款，开始为您处理。', 'myeasybuy-core' ),
				);
			case 'pending-manual':
				return array(
					'title' => sprintf( __( '订单 %s 待人工补录', 'myeasybuy-core' ), $number ),
					'body'  => __( '订单已提交，正在补录商品信息。', 'myeasybuy-core' ),
				);
			case 'purchasing':
				return array(
					'title' => sprintf( __( '订单 %s 正在代采', 'myeasybuy-core' ), $number ),
					'body'  => __( '平台正在为您采购商品。', 'myeasybuy-core' ),
				);
			case 'cn-shipped':
				return array(
					'title' => sprintf( __( '订单 %s 国内已发货', 'myeasybuy-core' ), $number ),
					'body'  => __( '商品已从卖家发出，正在运往仓库。', 'myeasybuy-core' ),
				);
			case 'in-warehouse':
				return array(
					'title' => sprintf( __( '订单 %s 已入库', 'myeasybuy-core' ), $number ),
					'body'  => __( '仓库已验货入库，可以提交包裹。', 'myeasybuy-core' ),
				);
			case 'consolidating':
				return array(
					'title' => sprintf( __( '订单 %s 正在合箱', 'myeasybuy-core' ), $number ),
					'body'  => __( '合箱申请已提交，仓库正在处理。', 'myeasybuy-core' ),
				);
			case 'awaiting-shipping-fee':
			case 'awaiting-shipping':
				return array(
					'title' => sprintf( __( '订单 %s 待付国际运费', 'myeasybuy-core' ), $number ),
					'body'  => __( '国际运费已生成，请前往我的包裹支付。', 'myeasybuy-core' ),
				);
			case 'paid-shipping':
				return array(
					'title' => sprintf( __( '订单 %s 已付国际运费', 'myeasybuy-core' ), $number ),
					'body'  => __( '运费已支付，仓库将尽快安排发货。', 'myeasybuy-core' ),
				);
			case 'int-shipped':
				return array(
					'title' => sprintf( __( '订单 %s 已发出', 'myeasybuy-core' ), $number ),
					'body'  => __( '包裹已交运，请留意物流更新。', 'myeasybuy-core' ),
				);
			case 'completed':
				return array(
					'title' => sprintf( __( '订单 %s 已完成', 'myeasybuy-core' ), $number ),
					'body'  => __( '订单已完成。如已收到包裹，欢迎再来选购。', 'myeasybuy-core' ),
				);
			case 'cancelled':
				return array(
					'title' => sprintf( __( '订单 %s 已取消', 'myeasybuy-core' ), $number ),
					'body'  => __( '该订单已取消。', 'myeasybuy-core' ),
				);
			case 'refunded':
				return array(
					'title' => sprintf( __( '订单 %s 已退款', 'myeasybuy-core' ), $number ),
					'body'  => __( '退款已处理。', 'myeasybuy-core' ),
				);
			case 'failed':
				return array(
					'title' => sprintf( __( '订单 %s 支付未成功', 'myeasybuy-core' ), $number ),
					'body'  => __( '支付没有完成，请重新付款。', 'myeasybuy-core' ),
				);
			default:
				return null;
		}
	}
}
