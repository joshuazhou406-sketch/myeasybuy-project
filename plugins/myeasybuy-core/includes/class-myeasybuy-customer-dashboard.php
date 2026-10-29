<?php
/**
 * Customer My Account — overview (总览).
 *
 * @package MyEasyBuy_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renames the account dashboard, and shows the balances and counts that already exist.
 */
class MyEasyBuy_Customer_Dashboard {

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'account_menu_items' ), 300 );
		add_filter( 'woocommerce_locate_template', array( __CLASS__, 'locate_dashboard' ), 99, 3 );
		add_action( 'woocommerce_account_dashboard', array( __CLASS__, 'render' ), 20 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_filter( 'document_title_parts', array( __CLASS__, 'document_title' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 120 );
	}

	/**
	 * The My Account root, before any endpoint.
	 */
	public static function is_page(): bool {
		return function_exists( 'is_account_page' )
			&& is_account_page()
			&& function_exists( 'is_wc_endpoint_url' )
			&& ! is_wc_endpoint_url();
	}

	/**
	 * Put 总览 first, keep 购物车 next, and rename logout.
	 *
	 * @param array<string, string> $items Menu items.
	 * @return array<string, string>
	 */
	public static function account_menu_items( array $items ): array {
		if ( isset( $items['dashboard'] ) ) {
			$items['dashboard'] = __( '总览', 'myeasybuy-core' );
		}
		if ( isset( $items['customer-logout'] ) ) {
			$items['customer-logout'] = __( '退出登录', 'myeasybuy-core' );
		}

		$ordered = array();
		foreach ( array( 'dashboard', 'cart' ) as $key ) {
			if ( ! isset( $items[ $key ] ) ) {
				continue;
			}
			$ordered[ $key ] = $items[ $key ];
			unset( $items[ $key ] );
		}

		$logout = null;
		if ( isset( $items['customer-logout'] ) ) {
			$logout = $items['customer-logout'];
			unset( $items['customer-logout'] );
		}

		foreach ( $items as $key => $label ) {
			$ordered[ $key ] = $label;
		}
		if ( null !== $logout ) {
			$ordered['customer-logout'] = $logout;
		}

		return $ordered;
	}

	/**
	 * Replace the English WooCommerce dashboard intro.
	 *
	 * @param string $template      Located template.
	 * @param string $template_name Template name.
	 * @param string $template_path Template path.
	 */
	public static function locate_dashboard( string $template, string $template_name, string $template_path ): string {
		unset( $template_path );
		if ( 'myaccount/dashboard.php' !== $template_name ) {
			return $template;
		}

		$custom = MYEASYBUY_CORE_DIR . 'templates/woocommerce/myaccount/dashboard.php';

		return file_exists( $custom ) ? $custom : $template;
	}

	/**
	 * @param array<int, string> $classes Body classes.
	 * @return array<int, string>
	 */
	public static function body_class( array $classes ): array {
		if ( self::is_page() ) {
			$classes[] = 'meb-account-dashboard';
		}

		return $classes;
	}

	/**
	 * @param array<string, string> $parts Title parts.
	 * @return array<string, string>
	 */
	public static function document_title( array $parts ): array {
		if ( self::is_page() ) {
			$parts['title'] = __( '总览', 'myeasybuy-core' );
		}

		return $parts;
	}

	/**
	 * Assets for the overview.
	 */
	public static function enqueue_assets(): void {
		if ( ! self::is_page() ) {
			return;
		}

		wp_enqueue_style(
			'myeasybuy-customer-dashboard',
			MYEASYBUY_CORE_URL . 'assets/css/customer-dashboard.css',
			array( 'myeasybuy-storefront-palette' ),
			MYEASYBUY_CORE_VERSION
		);
	}

	/**
	 * Render the overview under any existing account notices.
	 */
	public static function render(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user_id = get_current_user_id();
		$profile = class_exists( 'MyEasyBuy_Customer_Profile' ) ? MyEasyBuy_Customer_Profile::profile( $user_id ) : array();
		$letter  = isset( $profile['letter'] ) ? (string) $profile['letter'] : '';
		$name    = isset( $profile['name'] ) ? (string) $profile['name'] : wp_get_current_user()->display_name;
		$public  = isset( $profile['public_id'] ) ? (string) $profile['public_id'] : (string) $user_id;

		$cents    = class_exists( 'MyEasyBuy_Customer_Wallet' ) ? MyEasyBuy_Customer_Wallet::balance_cents( $user_id ) : 0;
		$cad      = class_exists( 'MyEasyBuy_Customer_Wallet' ) ? MyEasyBuy_Customer_Wallet::format_cad( $cents ) : '0.00';
		$cny      = class_exists( 'MyEasyBuy_Customer_Wallet' ) ? MyEasyBuy_Customer_Wallet::format_amount( $cents ) : '0.00';
		$wallet   = class_exists( 'MyEasyBuy_Customer_Wallet' ) ? MyEasyBuy_Customer_Wallet::get_endpoint_url() : '';
		$recharge = class_exists( 'MyEasyBuy_Customer_Wallet' ) ? MyEasyBuy_Customer_Wallet::page_url( array( 'panel' => 'recharge' ) ) : $wallet;
		$coupons  = class_exists( 'MyEasyBuy_Customer_Wallet' ) ? MyEasyBuy_Customer_Wallet::list_coupons( $user_id ) : array();
		$coupon_url = class_exists( 'MyEasyBuy_Customer_Wallet' ) ? MyEasyBuy_Customer_Wallet::page_url( array( 'tab' => 'coupons' ) ) : $wallet;

		$profile_url = class_exists( 'MyEasyBuy_Customer_Profile' ) ? MyEasyBuy_Customer_Profile::get_endpoint_url() : wc_get_page_permalink( 'myaccount' );
		$cart_url    = class_exists( 'MyEasyBuy_Customer_Cart' ) ? MyEasyBuy_Customer_Cart::get_endpoint_url() : wc_get_cart_url();
		$cart_items  = self::cart_preview();

		$orders = class_exists( 'MyEasyBuy_Customer_Orders' ) ? MyEasyBuy_Customer_Orders::summary_counts() : array();
		$warehouse = class_exists( 'MyEasyBuy_Customer_Warehouse' ) ? MyEasyBuy_Customer_Warehouse::summary_counts() : array();
		$parcels = class_exists( 'MyEasyBuy_Customer_Parcels' ) ? MyEasyBuy_Customer_Parcels::summary_counts() : array();
		$rehearsal = self::rehearsal_counts( $user_id );

		include MYEASYBUY_CORE_DIR . 'templates/account/dashboard.php';
	}

	/**
	 * First few cart lines for the overview card.
	 *
	 * @return array<int, array{name: string, url: string, image: string, price: string}>
	 */
	private static function cart_preview(): array {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return array();
		}

		$rows = array();
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product = $cart_item['data'] ?? null;
			if ( ! $product instanceof WC_Product || ! $product->exists() ) {
				continue;
			}

			$qty = max( 1, (int) ( $cart_item['quantity'] ?? 1 ) );
			$rows[] = array(
				'name'  => $product->get_name(),
				'url'   => $product->is_visible() ? (string) $product->get_permalink( $cart_item ) : '',
				'image' => $product->get_image(
					'woocommerce_thumbnail',
					array(
						'class' => 'meb-overview__thumb',
						'alt'   => $product->get_name(),
					)
				),
				'price' => wp_strip_all_tags( WC()->cart->get_product_subtotal( $product, $qty ) ),
			);

			if ( count( $rows ) >= 3 ) {
				break;
			}
		}

		return $rows;
	}

	/**
	 * @return array{unpaid: int, processing: int, completed: int}
	 */
	private static function rehearsal_counts( int $user_id ): array {
		$counts = array(
			'unpaid'     => 0,
			'processing' => 0,
			'completed'  => 0,
		);
		if ( ! class_exists( 'MyEasyBuy_Customer_Rehearsal' ) ) {
			return $counts;
		}

		foreach ( MyEasyBuy_Customer_Rehearsal::get_records( $user_id ) as $row ) {
			$status = (string) ( $row['status'] ?? '' );
			if ( isset( $counts[ $status ] ) ) {
				++$counts[ $status ];
			}
		}

		return $counts;
	}
}
