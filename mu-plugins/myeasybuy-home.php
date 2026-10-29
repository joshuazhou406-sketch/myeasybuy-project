<?php
/**
 * Plugin Name: MyEasyBuy Layout
 * Description: Theme-independent storefront layout (ootdbuy-style): shared header/footer on every page plus a custom front page. Disable with the `myeasybuy_layout_enabled` filter or by deleting this file.
 * Version:     1.1.0
 *
 * @package MyEasyBuy_Home
 */

defined( 'ABSPATH' ) || exit;

define( 'MYEASYBUY_HOME_DIR', __DIR__ . '/myeasybuy-home/' );
define( 'MYEASYBUY_HOME_URL', content_url( 'mu-plugins/myeasybuy-home/' ) );

require_once MYEASYBUY_HOME_DIR . 'inc/layout.php';
