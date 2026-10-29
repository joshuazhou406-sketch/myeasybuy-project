<?php
/**
 * Customer My Account — shopping cart (购物车).
 *
 * @package MyEasyBuy_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the cart inside My Account and sends the standalone cart URL there.
 */
class MyEasyBuy_Customer_Cart {

	public const ENDPOINT = 'cart';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'add_endpoint' ) );
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'add_query_var' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'account_menu_items' ), 100 );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'render_endpoint' ) );
		add_filter( 'woocommerce_get_cart_url', array( __CLASS__, 'filter_cart_url' ) );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_legacy_cart' ), 20 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_filter( 'document_title_parts', array( __CLASS__, 'document_title' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 120 );
		add_filter( 'woocommerce_add_success', array( __CLASS__, 'localize_notice' ) );

		if ( get_option( 'myeasybuy_cart_endpoint_flushed' ) !== '1.11.2' ) {
			add_action( 'init', array( __CLASS__, 'maybe_flush_rewrite' ), 99 );
		}
	}

	/**
	 * Whether the current request is the account cart.
	 */
	public static function is_page(): bool {
		return function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( self::ENDPOINT );
	}

	/**
	 * Account cart URL.
	 */
	public static function get_endpoint_url(): string {
		return wc_get_account_endpoint_url( self::ENDPOINT );
	}

	/**
	 * Register the My Account endpoint.
	 */
	public static function add_endpoint(): void {
		add_rewrite_endpoint( self::ENDPOINT, EP_PAGES );
	}

	/**
	 * Expose the endpoint to WooCommerce query vars.
	 *
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
		update_option( 'myeasybuy_cart_endpoint_flushed', '1.11.2', false );
	}

	/**
	 * Put 购物车 at the top of the account menu.
	 *
	 * @param array<string, string> $items Menu items.
	 * @return array<string, string>
	 */
	public static function account_menu_items( array $items ): array {
		$label = __( '购物车', 'myeasybuy-core' );
		unset( $items[ self::ENDPOINT ] );

		return array( self::ENDPOINT => $label ) + $items;
	}

	/**
	 * Cart links (header, notices, remove URLs) open the account cart.
	 *
	 * @param string $url Original cart URL.
	 */
	public static function filter_cart_url( string $url ): string {
		unset( $url );

		return self::get_endpoint_url();
	}

	/**
	 * Logged-in visits to the old /cart/ page land in the account cart.
	 * Guests are already sent to login by MyEasyBuy_Login_Required.
	 */
	public static function redirect_legacy_cart(): void {
		if ( ! is_user_logged_in() || ! function_exists( 'is_cart' ) || ! is_cart() ) {
			return;
		}

		wp_safe_redirect( self::get_endpoint_url() );
		exit;
	}

	/**
	 * @param array<int, string> $classes Body classes.
	 * @return array<int, string>
	 */
	public static function body_class( array $classes ): array {
		if ( self::is_page() ) {
			$classes[] = 'meb-account-cart';
		}

		return $classes;
	}

	/**
	 * @param array<string, string> $parts Title parts.
	 * @return array<string, string>
	 */
	public static function document_title( array $parts ): array {
		if ( self::is_page() ) {
			$parts['title'] = __( '购物车', 'myeasybuy-core' );
		}

		return $parts;
	}

	/**
	 * Front-end assets for the account cart only.
	 */
	public static function enqueue_assets(): void {
		if ( ! self::is_page() ) {
			return;
		}

		wp_enqueue_style(
			'myeasybuy-customer-cart',
			MYEASYBUY_CORE_URL . 'assets/css/customer-cart.css',
			array( 'myeasybuy-storefront-palette' ),
			MYEASYBUY_CORE_VERSION
		);

		wp_enqueue_script(
			'myeasybuy-customer-cart',
			MYEASYBUY_CORE_URL . 'assets/js/customer-cart.js',
			array(),
			MYEASYBUY_CORE_VERSION,
			true
		);
	}

	/**
	 * Keep cart feedback in Chinese.
	 *
	 * @param string $message Notice HTML.
	 */
	public static function localize_notice( string $message ): string {
		$message = str_replace( 'Cart updated.', '购物车已更新。', $message );
		$message = str_replace( ' removed.', ' 已移出购物车。', $message );
		$message = str_replace( 'Undo?', '撤销？', $message );

		return $message;
	}

	/**
	 * Render the account cart.
	 */
	public static function render_endpoint(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			echo '<p>' . esc_html__( '购物车暂时无法使用。', 'myeasybuy-core' ) . '</p>';
			return;
		}

		$cart = WC()->cart;
		if ( ! $cart->is_empty() ) {
			$cart->calculate_totals();
		}

		$items    = self::prepare_items( $cart );
		$totals   = self::prepare_totals( $cart );
		$is_empty = empty( $items );

		$shop_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );
		$checkout = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/' );
		$estimate = class_exists( 'MyEasyBuy_Customer_Warehouse' )
			? MyEasyBuy_Customer_Warehouse::get_endpoint_url()
			: $shop_url;

		$steps = array(
			__( '选择商品', 'myeasybuy-core' ),
			__( '确认订单', 'myeasybuy-core' ),
			__( '支付产品费用', 'myeasybuy-core' ),
			__( '检验及仓储', 'myeasybuy-core' ),
			__( '提交包裹', 'myeasybuy-core' ),
			__( '支付国际运费', 'myeasybuy-core' ),
			__( '等待收货', 'myeasybuy-core' ),
		);

		include MYEASYBUY_CORE_DIR . 'templates/account/cart.php';
	}

	/**
	 * Display rows for the current cart.
	 *
	 * @param WC_Cart $cart Cart.
	 * @return array<int, array<string, mixed>>
	 */
	private static function prepare_items( WC_Cart $cart ): array {
		$rows = array();

		foreach ( $cart->get_cart() as $key => $cart_item ) {
			$product = $cart_item['data'] ?? null;
			if ( ! $product instanceof WC_Product || ! $product->exists() ) {
				continue;
			}

			$max = $product->get_max_purchase_quantity();
			$rows[] = array(
				'key'               => (string) $key,
				'name'              => $product->get_name(),
				'url'               => $product->is_visible() ? $product->get_permalink( $cart_item ) : '',
				'image'             => $product->get_image(
					'woocommerce_thumbnail',
					array(
						'class' => 'meb-cart__thumb',
						'alt'   => $product->get_name(),
					)
				),
				'qty'               => max( 1, (int) $cart_item['quantity'] ),
				'max'               => (int) $max,
				'sold_individually' => $product->is_sold_individually(),
				'meta'              => wc_get_formatted_cart_item_data( $cart_item ),
				'note'              => isset( $cart_item['meb_item_note'] ) ? (string) $cart_item['meb_item_note'] : '',
				'price'             => self::money( self::line_amount( $cart_item ) ),
				'remove_url'        => wc_get_cart_remove_url( (string) $key ),
			);
		}

		return $rows;
	}

	/**
	 * Retail, discount, and payable product total (international shipping excluded).
	 *
	 * @param WC_Cart $cart Cart.
	 * @return array{retail: float, discount: float, total: float}
	 */
	private static function prepare_totals( WC_Cart $cart ): array {
		$retail  = 0.0;
		$payable = 0.0;

		foreach ( $cart->get_cart() as $cart_item ) {
			$product = $cart_item['data'] ?? null;
			$qty     = isset( $cart_item['quantity'] ) ? (float) $cart_item['quantity'] : 0.0;
			if ( ! $product instanceof WC_Product || $qty <= 0 ) {
				continue;
			}

			$line    = self::line_amount( $cart_item );
			$payable += $line;

			$regular = (float) $product->get_regular_price();
			if ( $regular > 0 ) {
				if ( self::prices_include_tax() ) {
					$regular = (float) wc_get_price_including_tax(
						$product,
						array(
							'qty'   => 1,
							'price' => $regular,
						)
					);
				}
				$retail += $regular * $qty;
			} else {
				$retail += self::prices_include_tax()
					? (float) ( $cart_item['line_subtotal'] ?? 0 ) + (float) ( $cart_item['line_subtotal_tax'] ?? 0 )
					: (float) ( $cart_item['line_subtotal'] ?? 0 );
			}
		}

		$decimals = wc_get_price_decimals();
		$retail   = round( $retail, $decimals );
		$payable  = round( $payable, $decimals );
		if ( $retail < $payable ) {
			$retail = $payable;
		}

		return array(
			'retail'   => $retail,
			'discount' => round( $retail - $payable, $decimals ),
			'total'    => $payable,
		);
	}

	/**
	 * Line amount shown to the customer.
	 *
	 * @param array<string, mixed> $cart_item Cart row.
	 */
	private static function line_amount( array $cart_item ): float {
		$net = (float) ( $cart_item['line_total'] ?? 0 );
		if ( ! self::prices_include_tax() ) {
			return $net;
		}

		return $net + (float) ( $cart_item['line_tax'] ?? 0 );
	}

	/**
	 * Whether cart prices are shown inclusive of tax.
	 */
	private static function prices_include_tax(): bool {
		return 'incl' === get_option( 'woocommerce_tax_display_cart' );
	}

	/**
	 * Format an amount as C$0.00 when the store currency is CAD.
	 */
	public static function money( float $amount, bool $with_minus = false ): string {
		$decimals = wc_get_price_decimals();
		$number   = number_format(
			abs( $amount ),
			$decimals,
			wc_get_price_decimal_separator(),
			wc_get_price_thousand_separator()
		);

		if ( 'CAD' === get_woocommerce_currency() ) {
			$symbol = 'C$';
		} else {
			$symbol = html_entity_decode( (string) get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
		}

		$sign = ( $with_minus || $amount < 0 ) ? '-' : '';

		return $sign . $symbol . $number;
	}
}
