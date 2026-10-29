<?php
/**
 * Customer My Account — wallet (我的钱包).
 *
 * @package MyEasyBuy_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Balance ledger and coupon list inside My Account.
 */
class MyEasyBuy_Customer_Wallet {

	public const ENDPOINT = 'wallet';

	private const META_BALANCE = '_myeasybuy_wallet_balance_cny';

	private const META_TX = '_myeasybuy_wallet_transactions';

	private const META_COUPONS = '_myeasybuy_wallet_coupons';

	private const MAX_STORED = 200;

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'add_endpoint' ) );
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'add_query_var' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'account_menu_items' ), 145 );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'render_endpoint' ) );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_filter( 'document_title_parts', array( __CLASS__, 'document_title' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 120 );
		add_action( 'admin_post_myeasybuy_wallet_recharge', array( __CLASS__, 'handle_recharge' ) );
		add_action( 'admin_post_myeasybuy_wallet_withdraw', array( __CLASS__, 'handle_withdraw' ) );

		if ( get_option( 'myeasybuy_wallet_endpoint_flushed' ) !== '1.18.0' ) {
			add_action( 'init', array( __CLASS__, 'maybe_flush_rewrite' ), 99 );
		}
	}

	/**
	 * Whether the current request is the wallet screen.
	 */
	public static function is_page(): bool {
		return function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( self::ENDPOINT );
	}

	/**
	 * Account wallet URL.
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
		update_option( 'myeasybuy_wallet_endpoint_flushed', '1.18.0', false );
	}

	/**
	 * Place 我的钱包 after 账户信息.
	 *
	 * @param array<string, string> $items Menu items.
	 * @return array<string, string>
	 */
	public static function account_menu_items( array $items ): array {
		$label  = __( '我的钱包', 'myeasybuy-core' );
		$anchor = isset( $items['profile'] ) ? 'profile' : 'messages';
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
			$classes[] = 'meb-account-wallet';
		}

		return $classes;
	}

	/**
	 * @param array<string, string> $parts Title parts.
	 * @return array<string, string>
	 */
	public static function document_title( array $parts ): array {
		if ( self::is_page() ) {
			$parts['title'] = __( '我的钱包', 'myeasybuy-core' );
		}

		return $parts;
	}

	/**
	 * Assets for the wallet screen.
	 */
	public static function enqueue_assets(): void {
		if ( ! self::is_page() ) {
			return;
		}

		wp_enqueue_style(
			'myeasybuy-customer-wallet',
			MYEASYBUY_CORE_URL . 'assets/css/customer-wallet.css',
			array( 'myeasybuy-storefront-palette' ),
			MYEASYBUY_CORE_VERSION
		);

		wp_enqueue_script(
			'myeasybuy-customer-wallet',
			MYEASYBUY_CORE_URL . 'assets/js/customer-wallet.js',
			array(),
			MYEASYBUY_CORE_VERSION,
			true
		);
	}

	/**
	 * Render the wallet screen.
	 */
	public static function render_endpoint(): void {
		if ( ! is_user_logged_in() ) {
			echo '<p>' . esc_html__( '请先登录。', 'myeasybuy-core' ) . '</p>';
			return;
		}

		$user_id  = get_current_user_id();
		$filters  = self::read_filters();
		$balance  = self::balance_cents( $user_id );
		$rows     = self::filter_transactions( self::transactions( $user_id ), $filters );
		$coupons  = self::coupons( $user_id );
		$types    = self::types();
		$tabs     = self::tabs();
		$need_pin = MyEasyBuy_Customer_Profile::has_trade_password( $user_id );

		include MYEASYBUY_CORE_DIR . 'templates/account/wallet.php';
	}

	/**
	 * @return array<string, string>
	 */
	public static function tabs(): array {
		return array(
			'balance' => __( '余额', 'myeasybuy-core' ),
			'coupons' => __( '优惠券', 'myeasybuy-core' ),
		);
	}

	/**
	 * Ledger categories shown in the filter.
	 *
	 * @return array<string, string>
	 */
	public static function types(): array {
		return array(
			'recharge' => __( '充值', 'myeasybuy-core' ),
			'withdraw' => __( '提现', 'myeasybuy-core' ),
			'consume'  => __( '消费', 'myeasybuy-core' ),
			'refund'   => __( '退款', 'myeasybuy-core' ),
			'freight'  => __( '运费', 'myeasybuy-core' ),
		);
	}

	/**
	 * Balance in fen (0.01 CNY).
	 */
	public static function balance_cents( int $user_id ): int {
		return max( 0, (int) get_user_meta( $user_id, self::META_BALANCE, true ) );
	}

	/**
	 * Coupons stored for a customer.
	 *
	 * @return array<int, array{title: string, amount: string, rule: string, expires: string}>
	 */
	public static function list_coupons( int $user_id ): array {
		return self::coupons( $user_id );
	}

	/**
	 * Format fen as 0.00.
	 */
	public static function format_amount( int $cents, bool $signed = false ): string {
		$number = number_format( abs( $cents ) / 100, 2, '.', ',' );
		if ( ! $signed ) {
			return $number;
		}
		if ( $cents > 0 ) {
			return '+' . $number;
		}
		if ( $cents < 0 ) {
			return '-' . $number;
		}

		return $number;
	}

	/**
	 * Approximate CAD value of a CNY fen amount.
	 */
	public static function format_cad( int $cents ): string {
		$cad = ( abs( $cents ) / 100 ) * self::cad_per_cny();

		return number_format( $cad, 2, '.', ',' );
	}

	/**
	 * Wallet URL, keeping the requested tab and dialog.
	 *
	 * @param array{tab?: string, panel?: string} $overrides Query overrides.
	 */
	public static function page_url( array $overrides = array() ): string {
		$tab = $overrides['tab'] ?? self::current_tab();
		if ( ! isset( self::tabs()[ $tab ] ) ) {
			$tab = 'balance';
		}

		$args = array();
		if ( 'balance' !== $tab ) {
			$args['meb_tab'] = $tab;
		}
		if ( ! empty( $overrides['panel'] ) && isset( self::panels()[ $overrides['panel'] ] ) ) {
			$args['meb_panel'] = $overrides['panel'];
		}

		$url = self::get_endpoint_url();

		return $args ? add_query_arg( $args, $url ) : $url;
	}

	/**
	 * Credit the wallet.
	 */
	public static function handle_recharge(): void {
		self::guard( 'myeasybuy_wallet_recharge' );
		$cents = self::posted_cents();
		if ( $cents <= 0 ) {
			self::fail( __( '请输入大于 0 的金额。', 'myeasybuy-core' ), 'recharge' );
		}
		if ( $cents > 10000000 ) {
			self::fail( __( '单笔充值不能超过 100,000.00 元。', 'myeasybuy-core' ), 'recharge' );
		}

		$note = self::posted_note();
		self::record( get_current_user_id(), $cents, 'recharge', '' !== $note ? $note : __( '充值', 'myeasybuy-core' ) );
		self::done( __( '充值成功。', 'myeasybuy-core' ) );
	}

	/**
	 * Debit the wallet.
	 */
	public static function handle_withdraw(): void {
		self::guard( 'myeasybuy_wallet_withdraw' );
		$user_id = get_current_user_id();
		$cents   = self::posted_cents();
		if ( $cents <= 0 ) {
			self::fail( __( '请输入大于 0 的金额。', 'myeasybuy-core' ), 'withdraw' );
		}
		if ( $cents > self::balance_cents( $user_id ) ) {
			self::fail( __( '余额不足。', 'myeasybuy-core' ), 'withdraw' );
		}
		if ( MyEasyBuy_Customer_Profile::has_trade_password( $user_id ) ) {
			$pin = isset( $_POST['trade_password'] ) ? (string) wp_unslash( $_POST['trade_password'] ) : '';
			if ( ! MyEasyBuy_Customer_Profile::verify_trade_password( $user_id, $pin ) ) {
				self::fail( __( '交易密码不正确。', 'myeasybuy-core' ), 'withdraw' );
			}
		}

		$note = self::posted_note();
		self::record( $user_id, -$cents, 'withdraw', '' !== $note ? $note : __( '提现', 'myeasybuy-core' ) );
		self::done( __( '提现成功。', 'myeasybuy-core' ) );
	}

	/**
	 * @return array<string, string>
	 */
	private static function panels(): array {
		return array(
			'recharge' => 'recharge',
			'withdraw' => 'withdraw',
		);
	}

	/**
	 * @return array{tab: string, from: string, to: string, type: string, panel: string}
	 */
	private static function read_filters(): array {
		$from = isset( $_GET['meb_from'] ) ? self::date_or_empty( sanitize_text_field( wp_unslash( (string) $_GET['meb_from'] ) ) ) : '';
		$to   = isset( $_GET['meb_to'] ) ? self::date_or_empty( sanitize_text_field( wp_unslash( (string) $_GET['meb_to'] ) ) ) : '';
		if ( '' !== $from && '' !== $to && $from > $to ) {
			$swap = $from;
			$from = $to;
			$to   = $swap;
		}

		$type = isset( $_GET['meb_type'] ) ? sanitize_key( wp_unslash( (string) $_GET['meb_type'] ) ) : '';
		if ( ! isset( self::types()[ $type ] ) ) {
			$type = '';
		}

		$panel = isset( $_GET['meb_panel'] ) ? sanitize_key( wp_unslash( (string) $_GET['meb_panel'] ) ) : '';
		if ( ! isset( self::panels()[ $panel ] ) ) {
			$panel = '';
		}

		return array(
			'tab'   => self::current_tab(),
			'from'  => $from,
			'to'    => $to,
			'type'  => $type,
			'panel' => $panel,
		);
	}

	private static function current_tab(): string {
		$tab = isset( $_GET['meb_tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['meb_tab'] ) ) : 'balance';

		return isset( self::tabs()[ $tab ] ) ? $tab : 'balance';
	}

	/**
	 * @return array<int, array{id: string, type: string, cents: int, balance: int, note: string, time: int}>
	 */
	private static function transactions( int $user_id ): array {
		$stored = get_user_meta( $user_id, self::META_TX, true );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$rows = array();
		foreach ( $stored as $row ) {
			if ( ! is_array( $row ) || empty( $row['id'] ) ) {
				continue;
			}
			$rows[] = array(
				'id'      => (string) $row['id'],
				'type'    => isset( self::types()[ (string) ( $row['type'] ?? '' ) ] ) ? (string) $row['type'] : 'consume',
				'cents'   => (int) ( $row['cents'] ?? 0 ),
				'balance' => max( 0, (int) ( $row['balance'] ?? 0 ) ),
				'note'    => (string) ( $row['note'] ?? '' ),
				'time'    => (int) ( $row['time'] ?? 0 ),
			);
		}

		return $rows;
	}

	/**
	 * @param array<int, array{id: string, type: string, cents: int, balance: int, note: string, time: int}> $rows Rows.
	 * @param array{from: string, to: string, type: string}                                                $filters Filters.
	 * @return array<int, array{id: string, type: string, cents: int, balance: int, note: string, time: int}>
	 */
	private static function filter_transactions( array $rows, array $filters ): array {
		return array_values(
			array_filter(
				$rows,
				static function ( array $row ) use ( $filters ): bool {
					if ( '' !== $filters['type'] && $filters['type'] !== $row['type'] ) {
						return false;
					}
					$day = $row['time'] > 0 ? wp_date( 'Y-m-d', $row['time'] ) : '';
					if ( '' !== $filters['from'] && ( '' === $day || $day < $filters['from'] ) ) {
						return false;
					}
					if ( '' !== $filters['to'] && ( '' === $day || $day > $filters['to'] ) ) {
						return false;
					}

					return true;
				}
			)
		);
	}

	/**
	 * @return array<int, array{title: string, amount: string, rule: string, expires: string}>
	 */
	private static function coupons( int $user_id ): array {
		$stored = get_user_meta( $user_id, self::META_COUPONS, true );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$rows = array();
		foreach ( $stored as $row ) {
			if ( ! is_array( $row ) || '' === trim( (string) ( $row['title'] ?? '' ) ) ) {
				continue;
			}
			$rows[] = array(
				'title'   => (string) $row['title'],
				'amount'  => (string) ( $row['amount'] ?? '' ),
				'rule'    => (string) ( $row['rule'] ?? '' ),
				'expires' => (string) ( $row['expires'] ?? '' ),
			);
		}

		return $rows;
	}

	/**
	 * Append a ledger row and update the stored balance.
	 */
	private static function record( int $user_id, int $signed_cents, string $type, string $note ): void {
		$balance = self::balance_cents( $user_id ) + $signed_cents;
		if ( $balance < 0 ) {
			$balance = 0;
		}

		update_user_meta( $user_id, self::META_BALANCE, $balance );

		$rows   = self::transactions( $user_id );
		$rows   = array_merge(
			array(
				array(
					'id'      => 'T' . wp_date( 'ymdHis' ) . wp_rand( 100, 999 ),
					'type'    => $type,
					'cents'   => $signed_cents,
					'balance' => $balance,
					'note'    => $note,
					'time'    => time(),
				),
			),
			$rows
		);
		update_user_meta( $user_id, self::META_TX, array_slice( $rows, 0, self::MAX_STORED ) );
	}

	private static function posted_cents(): int {
		$raw = isset( $_POST['amount'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['amount'] ) ) : '';
		$raw = str_replace( array( ',', ' ' ), '', $raw );
		if ( ! is_numeric( $raw ) ) {
			return 0;
		}

		return (int) round( ( (float) $raw ) * 100 );
	}

	private static function posted_note(): string {
		$note = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['note'] ) ) : '';

		return function_exists( 'mb_substr' ) ? mb_substr( $note, 0, 80 ) : substr( $note, 0, 80 );
	}

	private static function date_or_empty( string $value ): string {
		$date = DateTime::createFromFormat( '!Y-m-d', $value );

		return ( $date && $date->format( 'Y-m-d' ) === $value ) ? $value : '';
	}

	/**
	 * CAD received for 1 CNY. The banner shows this as an estimate.
	 */
	private static function cad_per_cny(): float {
		$rate = (float) apply_filters( 'myeasybuy_wallet_cad_per_cny', 0.19 );

		return $rate > 0 ? $rate : 0.19;
	}

	private static function fail( string $message, string $panel ): void {
		self::load_notices();
		wc_add_notice( $message, 'error' );
		wp_safe_redirect( self::page_url( array( 'panel' => $panel ) ) );
		exit;
	}

	private static function done( string $message ): void {
		self::load_notices();
		wc_add_notice( $message, 'success' );
		wp_safe_redirect( self::page_url() );
		exit;
	}

	private static function guard( string $action ): void {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
			exit;
		}
		check_admin_referer( $action );
	}

	private static function load_notices(): void {
		if ( function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}
		if ( ! function_exists( 'wc_add_notice' ) && defined( 'WC_ABSPATH' ) ) {
			include_once WC_ABSPATH . 'includes/wc-notice-functions.php';
		}
	}
}
