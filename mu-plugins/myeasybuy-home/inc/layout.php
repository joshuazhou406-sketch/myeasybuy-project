<?php
/**
 * Site-wide layout: shared header/footer, theme chrome removal, assets.
 *
 * @package MyEasyBuy_Home
 */

defined( 'ABSPATH' ) || exit;

function meb_layout_active(): bool {
	return ! is_admin() && ! is_customize_preview() && apply_filters( 'myeasybuy_layout_enabled', true );
}

/**
 * @return array<string, mixed>
 */
function meb_urls(): array {
	static $urls = null;
	if ( null !== $urls ) {
		return $urls;
	}

	$has_wc  = function_exists( 'WC' );
	$account = $has_wc ? wc_get_page_permalink( 'myaccount' ) : wp_login_url();
	$reg     = class_exists( 'MyEasyBuy_Registration' );
	$register = $reg ? MyEasyBuy_Registration::get_register_url() : wp_registration_url();

	$cart = $has_wc ? wc_get_cart_url() : home_url( '/' );
	if ( class_exists( 'MyEasyBuy_Customer_Cart' ) ) {
		$cart = MyEasyBuy_Customer_Cart::get_endpoint_url();
	}

	$urls = array(
		'has_wc'   => $has_wc,
		'account'  => ( ! is_user_logged_in() && $register ) ? $register : $account,
		'cart'     => $cart,
		'checkout' => $has_wc ? wc_get_checkout_url() : home_url( '/' ),
		'shop'     => $has_wc ? wc_get_page_permalink( 'shop' ) : home_url( '/' ),
		'register' => $register,
		'login'    => $reg ? MyEasyBuy_Registration::get_login_url() : wp_login_url(),
		'forward'  => class_exists( '\ReverseForwarding\Frontend\Forwarding_Center' ) ? \ReverseForwarding\Frontend\Forwarding_Center::get_url() : home_url( '/forwarding-center/' ),
		'email'    => apply_filters( 'meb_home_service_email', get_option( 'woocommerce_email_from_address', '' ) ),
	);

	return $urls;
}

function meb_endpoint_url( string $endpoint ): string {
	$url = function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( $endpoint ) : meb_urls()['account'];
	if ( is_user_logged_in() || ! class_exists( 'MyEasyBuy_Login_Required' ) ) {
		return $url;
	}

	return MyEasyBuy_Login_Required::get_login_url( $url );
}

/**
 * Keep a same-site return URL when linking between login and register.
 */
function meb_with_return_url( string $url ): string {
	if ( ! class_exists( 'MyEasyBuy_Login_Required' ) ) {
		return $url;
	}

	$return_url = MyEasyBuy_Login_Required::get_requested_return_url();

	return '' === $return_url ? $url : add_query_arg( MyEasyBuy_Login_Required::REDIRECT_ARG, rawurlencode( $return_url ), $url );
}

function meb_cart_count(): int {
	return ( function_exists( 'WC' ) && WC()->cart ) ? (int) WC()->cart->get_cart_contents_count() : 0;
}

function meb_icon( string $name ): string {
	$paths = array(
		'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
		'cart'   => '<circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.6 12.4a1 1 0 0 0 1 .8h9.7a1 1 0 0 0 1-.8L21 7H6"/>',
		'user'   => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-7 8-7s8 3 8 7"/>',
		'bell'   => '<path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.9 1.9 0 0 0 3.4 0"/>',
		'megaph' => '<path d="m3 11 15-6v14L3 13z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
		'box'    => '<path d="M21 8 12 3 3 8v8l9 5 9-5z"/><path d="m3 8 9 5 9-5M12 13v8"/>',
		'calc'   => '<rect x="5" y="2" width="14" height="20" rx="2"/><path d="M8 6h8M8 11h.01M12 11h.01M16 11h.01M8 15h.01M12 15h.01M16 15h.01M8 19h8"/>',
		'clip'   => '<rect x="5" y="4" width="14" height="18" rx="2"/><path d="M9 2h6v4H9zM9 11h6M9 15h6"/>',
		'doc'    => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h5"/>',
		'camera' => '<path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/>',
		'help'   => '<path d="M3 18v-6a9 9 0 0 1 18 0v6"/><path d="M21 19a2 2 0 0 1-2 2h-1v-6h3zM3 19a2 2 0 0 0 2 2h1v-6H3z"/>',
		'top'    => '<path d="M5 3h14M12 21V8M6 13l6-6 6 6"/>',
		'arrow'  => '<path d="m9 18 6-6-6-6"/>',
		'bag'    => '<path d="M6 7h12l1 14H5z"/><path d="M9 10V6a3 3 0 0 1 6 0v4"/>',
		'store'  => '<path d="M3 9 4.5 4h15L21 9"/><path d="M3 9a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"/><path d="M5 11.5V20h14v-8.5M10 20v-5h4v5"/>',
		'menu'   => '<path d="M4 6h16M4 12h16M4 18h16"/>',
		'left'   => '<path d="m15 18-6-6 6-6"/>',
		'down'   => '<path d="m6 9 6 6 6-6"/>',
		'close'  => '<path d="M18 6 6 18M6 6l12 12"/>',
		'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>',
		'ticket' => '<path d="M3 7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v3a2 2 0 0 0 0 4v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-3a2 2 0 0 0 0-4z"/><path d="M14 6v2M14 11v2M14 16v2"/>',
		'clock'  => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
		'thumb'  => '<path d="M7 10v11H3V10z"/><path d="m7 10 4-8a3 3 0 0 1 3 3v4h5.5a2 2 0 0 1 2 2.3l-1.4 8A2 2 0 0 1 18.1 21H7"/>',
		'shoe'   => '<path d="M2 17v-5l4-1 3-5 3 3c2 2 4 3 7 3.5 1.7.3 3 1.6 3 3.3V17z"/><path d="M2 17h20v2H2zM10 10.5l1.5 1.5M12.5 9 14 10.5"/>',
		'jacket' => '<path d="M9 3h6l5 4v14h-4v-8M9 3 4 7v14h4v-8"/><path d="M9 3a3 3 0 0 0 6 0M12 6v15"/>',
		'shirt'  => '<path d="M8 3 3 6l2 5 2-1v11h10V10l2 1 2-5-5-3a4 4 0 0 1-8 0z"/>',
		'moon'   => '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
		'watch'  => '<rect x="6" y="6" width="12" height="12" rx="3"/><path d="m9 6 1-4h4l1 4M9 18l1 4h4l1-4M12 9v3l2 1"/>',
		'mouse'  => '<rect x="6" y="2" width="12" height="20" rx="6"/><path d="M12 6v4"/>',
		'bottle' => '<rect x="5" y="9" width="14" height="13" rx="2"/><path d="M9 9V6h6v3M10 6V3h4v3M9 15h6"/>',
		'ball'   => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3v18M5.6 5.6a9 9 0 0 1 0 12.8M18.4 5.6a9 9 0 0 0 0 12.8"/>',
		'game'   => '<rect x="2" y="7" width="20" height="11" rx="5"/><path d="M7 11v3M5.5 12.5h3M15 12h.01M18 12h.01"/>',
		'lips'   => '<path d="M9 22h6V11H9z"/><path d="M10 11V6l4-3v8"/>',
		'paw'    => '<circle cx="5.5" cy="10" r="2"/><circle cx="9" cy="5.5" r="2"/><circle cx="15" cy="5.5" r="2"/><circle cx="18.5" cy="10" r="2"/><path d="M12 12c-3 0-6 4-6 6.5S8 21 12 21s6-.5 6-2.5S15 12 12 12z"/>',
		'home'   => '<path d="m3 11 9-8 9 8"/><path d="M5 10v11h14V10M10 21v-6h4v6"/>',
	);

	return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ( $paths[ $name ] ?? '' ) . '</svg>';
}

function meb_logo(): void {
	if ( has_custom_logo() ) {
		the_custom_logo();
		return;
	}
	printf(
		'<a class="meb-logo" href="%1$s" rel="home"><span class="meb-logo__mark">%2$s</span><span class="meb-logo__text">%3$s</span></a>',
		esc_url( home_url( '/' ) ),
		meb_icon( 'bag' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		esc_html( strtolower( get_bloginfo( 'name' ) ) )
	);
}

add_action(
	'template_redirect',
	static function () {
		if ( ! meb_layout_active() ) {
			return;
		}

		foreach ( array( 'shopire_site_preloader', 'shopire_site_main_header', 'shopire_footer_top', 'shopire_footer_widget', 'shopire_footer_bottom', 'shopire_top_scroller', 'shopire_footer_mobile_menu' ) as $hook ) {
			remove_all_actions( $hook );
		}

		if ( ! meb_is_auth_page() ) {
			add_action( 'wp_body_open', 'meb_render_header', 1 );
			add_action( 'wp_footer', 'meb_render_footer', 1 );
		} elseif ( meb_is_register_page() ) {
			remove_action( 'woocommerce_register_form', 'wc_registration_privacy_policy_text', 20 );
		}
	}
);

function meb_render_header(): void {
	include MYEASYBUY_HOME_DIR . 'parts/header.php';
}

function meb_render_footer(): void {
	include MYEASYBUY_HOME_DIR . 'parts/footer.php';
}

add_filter(
	'body_class',
	static function ( array $classes ): array {
		if ( ! meb_layout_active() ) {
			return $classes;
		}

		$classes[] = 'meb-site';
		if ( is_front_page() ) {
			$classes[] = 'meb-home';
		}
		if ( meb_is_shop_page() ) {
			$classes[] = 'meb-shop';
		}
		if ( meb_is_product_page() ) {
			$classes[] = 'meb-product';
		}
		if ( meb_is_auth_page() ) {
			$classes[] = 'meb-auth';
		}
		if ( meb_is_register_page() ) {
			$classes[] = 'meb-register';
		}
		if ( meb_is_login_page() ) {
			$classes[] = 'meb-login';
		}
		if ( function_exists( 'is_woocommerce' ) ) {
			$is_form_page = is_cart() || is_checkout() || is_account_page();
			if ( $is_form_page || is_woocommerce() ) {
				$classes[] = 'meb-wc-full';
			}
			if ( $is_form_page ) {
				$classes[] = 'meb-wc-forms';
			}
		}

		return $classes;
	}
);

function meb_is_shop_page(): bool {
	return meb_layout_active() && function_exists( 'is_shop' ) && is_shop() && ! is_search() && apply_filters( 'myeasybuy_shop_enabled', true );
}

function meb_is_product_page(): bool {
	return meb_layout_active() && function_exists( 'is_product' ) && is_product() && apply_filters( 'myeasybuy_product_enabled', true );
}

function meb_is_register_page(): bool {
	if ( ! meb_layout_active() || ! apply_filters( 'myeasybuy_register_enabled', true ) ) {
		return false;
	}

	$page_id = class_exists( 'MyEasyBuy_Registration' )
		? (int) get_option( MyEasyBuy_Registration::OPTION_REGISTER_PAGE_ID, 0 )
		: 0;

	return $page_id > 0 && is_page( $page_id );
}

function meb_is_login_page(): bool {
	if ( ! meb_layout_active() || ! apply_filters( 'myeasybuy_login_enabled', true ) ) {
		return false;
	}

	$page_id = class_exists( 'MyEasyBuy_Registration' )
		? (int) get_option( MyEasyBuy_Registration::OPTION_LOGIN_PAGE_ID, 0 )
		: 0;

	return $page_id > 0 && is_page( $page_id );
}

function meb_is_auth_page(): bool {
	return meb_is_register_page() || meb_is_login_page();
}

add_filter(
	'template_include',
	static function ( $template ) {
		if ( meb_layout_active() && is_front_page() && apply_filters( 'myeasybuy_home_enabled', true ) ) {
			return MYEASYBUY_HOME_DIR . 'front-page.php';
		}

		if ( meb_is_shop_page() ) {
			return MYEASYBUY_HOME_DIR . 'shop.php';
		}

		if ( meb_is_product_page() ) {
			return MYEASYBUY_HOME_DIR . 'single-product.php';
		}

		if ( meb_is_register_page() ) {
			return MYEASYBUY_HOME_DIR . 'register.php';
		}

		if ( meb_is_login_page() ) {
			return MYEASYBUY_HOME_DIR . 'login.php';
		}

		return $template;
	},
	999
);

// "Buy now" on the product page adds the item and goes straight to checkout.
add_filter(
	'woocommerce_add_to_cart_redirect',
	static function ( string $url ): string {
		if ( ! empty( $_REQUEST['meb_buy_now'] ) && function_exists( 'wc_get_checkout_url' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return wc_get_checkout_url();
		}
		return $url;
	}
);

add_filter(
	'woocommerce_add_cart_item_data',
	static function ( array $cart_item_data ): array {
		if ( ! empty( $_POST['meb_item_note'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$note = sanitize_textarea_field( wp_unslash( (string) $_POST['meb_item_note'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( '' !== $note ) {
				$cart_item_data['meb_item_note'] = $note;
			}
		}
		return $cart_item_data;
	}
);

add_filter(
	'woocommerce_get_item_data',
	static function ( array $item_data, array $cart_item ): array {
		if ( ! empty( $cart_item['meb_item_note'] ) ) {
			$item_data[] = array(
				'key'   => 'Order Remark',
				'value' => wc_clean( (string) $cart_item['meb_item_note'] ),
			);
		}
		return $item_data;
	},
	10,
	2
);

add_action(
	'woocommerce_checkout_create_order_line_item',
	static function ( $item, $cart_item_key, array $values ): void {
		unset( $cart_item_key );
		if ( ! empty( $values['meb_item_note'] ) ) {
			$item->add_meta_data( 'Order Remark', sanitize_textarea_field( (string) $values['meb_item_note'] ), true );
		}
	},
	10,
	3
);

add_filter( 'ppw_should_load_assets', static fn ( bool $load ): bool => $load || meb_is_shop_page() );

// Priority 110 so these load after myeasybuy-storefront-palette (100).
add_action(
	'wp_enqueue_scripts',
	static function () {
		if ( ! meb_layout_active() ) {
			return;
		}

		$asset = static function ( string $file ): array {
			return array( MYEASYBUY_HOME_URL . 'assets/' . $file, (string) filemtime( MYEASYBUY_HOME_DIR . 'assets/' . $file ) );
		};

		list( $src, $ver ) = $asset( 'site.css' );
		wp_enqueue_style( 'myeasybuy-site', $src, array(), $ver );
		list( $src, $ver ) = $asset( 'site.js' );
		wp_enqueue_script( 'myeasybuy-site', $src, array(), $ver, true );

		if ( is_front_page() ) {
			list( $src, $ver ) = $asset( 'home.css' );
			wp_enqueue_style( 'myeasybuy-home', $src, array( 'myeasybuy-site' ), $ver );
			list( $src, $ver ) = $asset( 'home.js' );
			wp_enqueue_script( 'myeasybuy-home', $src, array( 'myeasybuy-site' ), $ver, true );
		}

		if ( wp_script_is( 'ppw-jd-search', 'enqueued' ) ) {
			list( $src, $ver ) = $asset( 'product-sync.js' );
			wp_enqueue_script( 'myeasybuy-product-sync', $src, array( 'myeasybuy-site', 'ppw-jd-search' ), $ver, true );
		}

		if ( meb_is_shop_page() ) {
			list( $src, $ver ) = $asset( 'shop.css' );
			wp_enqueue_style( 'myeasybuy-shop', $src, array( 'myeasybuy-site' ), $ver );
			list( $src, $ver ) = $asset( 'shop.js' );
			wp_enqueue_script( 'myeasybuy-shop', $src, array( 'myeasybuy-site', 'ppw-jd-search', 'myeasybuy-product-sync' ), $ver, true );
		}

		if ( meb_is_product_page() ) {
			list( $src, $ver ) = $asset( 'product.css' );
			wp_enqueue_style( 'myeasybuy-product', $src, array( 'myeasybuy-site' ), $ver );
			list( $src, $ver ) = $asset( 'product.js' );
			wp_enqueue_script( 'myeasybuy-product', $src, array( 'myeasybuy-site' ), $ver, true );
		}

		if ( meb_is_auth_page() ) {
			list( $src, $ver ) = $asset( 'register.css' );
			wp_enqueue_style( 'myeasybuy-register', $src, array( 'myeasybuy-site' ), $ver );
			list( $src, $ver ) = $asset( 'register.js' );
			wp_enqueue_script( 'myeasybuy-register', $src, array( 'myeasybuy-site' ), $ver, true );
		}
	},
	110
);
