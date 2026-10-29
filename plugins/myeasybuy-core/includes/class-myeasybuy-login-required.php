<?php
/**
 * Require login for add-to-cart and purchase; browsing stays public.
 *
 * @package MyEasyBuy_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends guests to the login / register screen when they try to add to cart,
 * open the cart, or check out, then returns them to where they were.
 */
class MyEasyBuy_Login_Required {

	public const REDIRECT_ARG = 'redirect_to';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_filter( 'pre_option_woocommerce_enable_guest_checkout', array( __CLASS__, 'disable_guest_checkout' ) );
		add_action( 'wp_loaded', array( __CLASS__, 'intercept_form_add_to_cart' ), 5 );
		add_action( 'wc_ajax_add_to_cart', array( __CLASS__, 'intercept_ajax_add_to_cart' ), 1 );
		add_action( 'wp_ajax_nopriv_woocommerce_add_to_cart', array( __CLASS__, 'intercept_ajax_add_to_cart' ), 1 );
		add_action( 'woocommerce_store_api_validate_add_to_cart', array( __CLASS__, 'block_store_api_add_to_cart' ) );
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_add_to_cart' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'protect_cart_and_checkout' ) );
		add_action( 'woocommerce_before_customer_login_form', array( __CLASS__, 'render_login_required_notice' ), 5 );
		add_action( 'woocommerce_login_form_end', array( __CLASS__, 'render_redirect_field' ) );
		add_action( 'woocommerce_register_form_end', array( __CLASS__, 'render_redirect_field' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_guest_script' ) );
	}

	/**
	 * Login / register URL that returns the customer to $return_url afterwards.
	 *
	 * @param string $return_url Same-site URL to return to.
	 */
	public static function get_login_url( string $return_url = '' ): string {
		$url = class_exists( 'MyEasyBuy_Registration' )
			? MyEasyBuy_Registration::get_register_url()
			: wc_get_page_permalink( 'myaccount' );

		$return_url = wp_validate_redirect( $return_url, '' );
		if ( '' !== $return_url ) {
			$url = add_query_arg( self::REDIRECT_ARG, rawurlencode( $return_url ), $url );
		}

		return $url;
	}

	/**
	 * Validated return URL passed to the login / register page.
	 */
	public static function get_requested_return_url(): string {
		if ( empty( $_GET[ self::REDIRECT_ARG ] ) || ! is_string( $_GET[ self::REDIRECT_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return '';
		}

		return wp_validate_redirect( esc_url_raw( wp_unslash( $_GET[ self::REDIRECT_ARG ] ) ), '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Accounts are required to purchase.
	 */
	public static function disable_guest_checkout(): string {
		return 'no';
	}

	/**
	 * Classic `?add-to-cart=` links and product forms (WC handles them on wp_loaded:20).
	 */
	public static function intercept_form_add_to_cart(): void {
		if ( is_user_logged_in() || wp_doing_ajax() || empty( $_REQUEST['add-to-cart'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$product_id = absint( wp_unslash( $_REQUEST['add-to-cart'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// A GET link can be replayed after login so the item still lands in the cart.
		if ( 'GET' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) {
			$return_url = self::current_url();
		} else {
			$return_url = $product_id ? (string) get_permalink( $product_id ) : (string) wc_get_raw_referer();
		}

		wp_safe_redirect( self::get_login_url( $return_url ) );
		exit;
	}

	/**
	 * Archive AJAX buttons: add-to-cart.js follows `product_url` on error.
	 */
	public static function intercept_ajax_add_to_cart(): void {
		if ( is_user_logged_in() ) {
			return;
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$return_url = $product_id ? (string) get_permalink( $product_id ) : (string) wc_get_raw_referer();

		wp_send_json(
			array(
				'error'       => true,
				'product_url' => self::get_login_url( $return_url ),
			)
		);
	}

	/**
	 * Block-based add-to-cart buttons (Store API).
	 *
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException When the shopper is a guest.
	 */
	public static function block_store_api_add_to_cart(): void {
		if ( is_user_logged_in() ) {
			return;
		}

		throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
			'myeasybuy_login_required',
			esc_html__( '请先登录或注册后再加入购物车。', 'myeasybuy-core' ),
			401
		);
	}

	/**
	 * Safety net for any other add-to-cart path.
	 *
	 * @param bool $passed Whether validation passed.
	 */
	public static function validate_add_to_cart( $passed ): bool {
		if ( ! $passed || is_user_logged_in() ) {
			return (bool) $passed;
		}

		wc_add_notice( __( '请先登录或注册后再加入购物车。', 'myeasybuy-core' ), 'error' );
		return false;
	}

	/**
	 * Guests cannot open the cart or checkout. Order pay / received keep their own key-based access.
	 */
	public static function protect_cart_and_checkout(): void {
		if ( is_user_logged_in() || ! function_exists( 'is_cart' ) ) {
			return;
		}

		$is_purchase_page = is_cart()
			|| ( is_checkout() && ! is_wc_endpoint_url( 'order-pay' ) && ! is_wc_endpoint_url( 'order-received' ) );

		if ( ! $is_purchase_page ) {
			return;
		}

		wp_safe_redirect( self::get_login_url( self::current_url() ) );
		exit;
	}

	/**
	 * Explain why the shopper landed on the login screen.
	 */
	public static function render_login_required_notice(): void {
		if ( is_user_logged_in() || '' === self::get_requested_return_url() ) {
			return;
		}

		printf(
			'<div class="woocommerce-info myeasybuy-login-required-notice">%s</div>',
			esc_html__( '加入购物车和购买需要账户，请先登录；还没有账户？注册后将自动返回刚才的页面。', 'myeasybuy-core' )
		);
	}

	/**
	 * Carry the return URL through the login / register POST (WC reads `redirect`).
	 */
	public static function render_redirect_field(): void {
		$return_url = self::get_requested_return_url();
		if ( '' === $return_url ) {
			return;
		}

		printf( '<input type="hidden" name="redirect" value="%s" />', esc_url( $return_url ) );
	}

	/**
	 * Intercept purchase clicks before any AJAX / block handlers run.
	 */
	public static function enqueue_guest_script(): void {
		if ( is_user_logged_in() || is_admin() ) {
			return;
		}

		wp_enqueue_script(
			'myeasybuy-login-required',
			MYEASYBUY_CORE_URL . 'assets/js/login-required.js',
			array(),
			MYEASYBUY_CORE_VERSION,
			true
		);

		wp_localize_script(
			'myeasybuy-login-required',
			'myeasybuyLoginRequired',
			array(
				'loginUrl'    => self::get_login_url(),
				'redirectArg' => self::REDIRECT_ARG,
			)
		);
	}

	/**
	 * Absolute URL of the current request.
	 */
	public static function current_url(): string {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$home   = wp_parse_url( home_url() );
		$origin = ( is_ssl() ? 'https' : 'http' ) . '://' . ( $home['host'] ?? '' ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );

		return $origin . $request_uri;
	}
}
