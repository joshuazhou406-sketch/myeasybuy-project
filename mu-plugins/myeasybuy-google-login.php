<?php
/**
 * Plugin Name: MyEasyBuy Google Login
 * Description: Wires the custom Sign up / Log in "Continue with Google" buttons to Nextend Social Login.
 * Version:     1.0.0
 *
 * @package MyEasyBuy_Home
 */

defined( 'ABSPATH' ) || exit;

/**
 * Nextend Google login URL with a safe return URL (requested redirect_to, else My Account).
 */
function meb_google_auth_url(): string {
	if ( ! class_exists( 'NextendSocialLogin' ) || empty( NextendSocialLogin::$enabledProviders['google'] ) ) {
		return '';
	}
	$return = class_exists( 'MyEasyBuy_Login_Required' ) ? MyEasyBuy_Login_Required::get_requested_return_url() : '';
	if ( '' === $return ) {
		$return = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/' );
	}
	return add_query_arg( 'redirect', rawurlencode( $return ), NextendSocialLogin::$enabledProviders['google']->getLoginUrl() );
}

// login.php falls back to this filter too, so one hook wires both pages.
add_filter(
	'myeasybuy_google_signup_url',
	function ( $url ) {
		return $url ? $url : meb_google_auth_url();
	}
);

// Let Nextend create accounts when WooCommerce registration is on (WP "Anyone can register" stays off).
// Nextend's own check runs at priority 10 and returns false; this runs after it.
add_filter(
	'nsl_is_register_allowed',
	function ( $allowed ) {
		return $allowed || 'yes' === get_option( 'woocommerce_enable_myaccount_registration' );
	},
	20
);

// Google sign-ups become WooCommerce customers, not subscribers.
add_action(
	'nsl_register_new_user',
	function ( $user_id ) {
		$user = get_userdata( $user_id );
		if ( $user && array_values( $user->roles ) === array( 'subscriber' ) ) {
			$user->set_role( 'customer' );
		}
		update_user_meta( $user_id, '_myeasybuy_signup_method', 'google' );
	}
);