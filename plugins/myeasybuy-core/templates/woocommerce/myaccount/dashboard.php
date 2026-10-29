<?php
/**
 * My Account overview.
 *
 * Replaces the WooCommerce dashboard intro. Existing account notices still
 * print through woocommerce_account_dashboard.
 *
 * @package MyEasyBuy_Core
 * @version 4.4.0
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_account_dashboard' );

/**
 * Deprecated woocommerce_before_my_account action.
 *
 * @deprecated 2.6.0
 */
do_action( 'woocommerce_before_my_account' );

/**
 * Deprecated woocommerce_after_my_account action.
 *
 * @deprecated 2.6.0
 */
do_action( 'woocommerce_after_my_account' );
