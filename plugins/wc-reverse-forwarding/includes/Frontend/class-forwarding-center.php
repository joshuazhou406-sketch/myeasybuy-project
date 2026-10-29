<?php
/**
 * Standalone forwarding center page (/forwarding-center/).
 *
 * @package ReverseForwarding
 */

namespace ReverseForwarding\Frontend;

use ReverseForwarding\Models\Package;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Serves the forwarding center as its own page outside WooCommerce My Account.
 */
class Forwarding_Center {

	public const SLUG = 'forwarding-center';

	public const QUERY_VAR = 'wc_rf_forwarding_center';

	public const LEGACY = 'legacy';

	public const REWRITE_OPTION  = 'wc_rf_rewrite_version';
	public const REWRITE_VERSION = '2.0.0';

	public const TEMPLATE_PATH = 'wc-reverse-forwarding/';

	/**
	 * Register routing, rendering and asset hooks.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_action( 'init', array( __CLASS__, 'maybe_flush_rewrite' ), 99 );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_var' ) );
		add_action( 'parse_query', array( __CLASS__, 'parse_query' ) );
		add_filter( 'posts_pre_query', array( __CLASS__, 'skip_main_query' ), 10, 2 );
		add_filter( 'pre_handle_404', array( __CLASS__, 'prevent_404' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect_legacy' ), 5 );
		add_filter( 'template_include', array( __CLASS__, 'template_include' ), 1000 );
		add_filter( 'document_title_parts', array( __CLASS__, 'document_title' ) );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 120 );
		add_filter( 'woocommerce_login_redirect', array( __CLASS__, 'login_redirect' ) );
	}

	/**
	 * /forwarding-center/ plus a legacy /my-account/forwarding-center/ redirect.
	 */
	public static function add_rewrite_rules(): void {
		add_rewrite_rule( '^' . preg_quote( self::SLUG, '#' ) . '/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );

		$account_id  = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'myaccount' ) : 0;
		$account_uri = $account_id > 0 ? (string) get_page_uri( $account_id ) : '';
		if ( '' !== $account_uri ) {
			add_rewrite_rule(
				'^' . preg_quote( $account_uri, '#' ) . '/' . preg_quote( self::SLUG, '#' ) . '/?$',
				'index.php?' . self::QUERY_VAR . '=' . self::LEGACY,
				'top'
			);
		}
	}

	/**
	 * Flush rewrite rules once per routing version.
	 */
	public static function maybe_flush_rewrite(): void {
		if ( get_option( self::REWRITE_OPTION ) === self::REWRITE_VERSION ) {
			return;
		}

		flush_rewrite_rules( false );
		update_option( self::REWRITE_OPTION, self::REWRITE_VERSION, false );
	}

	/**
	 * @param array<int, string> $vars Public query vars.
	 * @return array<int, string>
	 */
	public static function add_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Whether the current request is the forwarding center page.
	 */
	public static function is_page(): bool {
		return '1' === (string) get_query_var( self::QUERY_VAR );
	}

	/**
	 * Public page URL.
	 */
	public static function get_url(): string {
		$url = get_option( 'permalink_structure' )
			? home_url( user_trailingslashit( self::SLUG ) )
			: add_query_arg( self::QUERY_VAR, '1', home_url( '/' ) );

		/**
		 * Filter the forwarding center page URL.
		 *
		 * @since 2.0.0
		 *
		 * @param string $url Page URL.
		 */
		return (string) apply_filters( 'wc_rf_forwarding_center_url', $url );
	}

	/**
	 * Keep the request from being treated as the blog home (and front page).
	 */
	public static function parse_query( WP_Query $query ): void {
		if ( ! self::is_own_query( $query ) ) {
			return;
		}

		$query->is_home = false;
		$query->is_404  = false;
	}

	/**
	 * Skip the posts query for this page.
	 *
	 * @param array<int, mixed>|null $posts Short-circuit value.
	 * @return array<int, mixed>|null
	 */
	public static function skip_main_query( $posts, WP_Query $query ) {
		return self::is_own_query( $query ) ? array() : $posts;
	}

	/**
	 * @param bool $preempt Whether to short-circuit 404 handling.
	 */
	public static function prevent_404( $preempt ): bool {
		return self::is_page() ? true : (bool) $preempt;
	}

	/**
	 * 301 the old My Account endpoint to the standalone page.
	 */
	public static function maybe_redirect_legacy(): void {
		if ( self::LEGACY !== (string) get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		$tab = isset( $_GET['rf_tab'] ) ? sanitize_key( wp_unslash( $_GET['rf_tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$url = '' !== $tab ? add_query_arg( 'rf_tab', $tab, self::get_url() ) : self::get_url();

		wp_safe_redirect( $url, 301 );
		exit;
	}

	/**
	 * @param string $template Resolved template.
	 */
	public static function template_include( $template ) {
		if ( ! self::is_page() ) {
			return $template;
		}

		return wc_locate_template( 'forwarding-center/page.php', self::TEMPLATE_PATH, WC_RF_PLUGIN_DIR . 'templates/' );
	}

	/**
	 * @param array<string, string> $parts Title parts.
	 * @return array<string, string>
	 */
	public static function document_title( array $parts ): array {
		if ( self::is_page() ) {
			$parts['title'] = __( '转运中心', 'wc-rf' );
		}
		return $parts;
	}

	/**
	 * @param array<int, string> $classes Body classes.
	 * @return array<int, string>
	 */
	public static function body_class( array $classes ): array {
		if ( self::is_page() ) {
			$classes[] = 'wc-rf-fc-page';
		}
		return $classes;
	}

	/**
	 * Send buyers back here after logging in from the page's login link.
	 *
	 * @param string $redirect Redirect URL.
	 */
	public static function login_redirect( $redirect ): string {
		$query = (string) wp_parse_url( (string) $redirect, PHP_URL_QUERY );
		parse_str( $query, $args );

		$target = isset( $args['redirect_to'] ) ? (string) $args['redirect_to'] : '';
		if ( '' !== $target && 0 === strpos( $target, self::get_url() ) ) {
			return $target;
		}

		return (string) $redirect;
	}

	/**
	 * Login URL that returns to this page.
	 */
	public static function get_login_url(): string {
		if ( class_exists( 'MyEasyBuy_Login_Required' ) ) {
			return \MyEasyBuy_Login_Required::get_login_url( self::get_url() );
		}

		$login = class_exists( 'MyEasyBuy_Registration' )
			? \MyEasyBuy_Registration::get_register_url()
			: wc_get_page_permalink( 'myaccount' );

		return add_query_arg( 'redirect_to', rawurlencode( self::get_url() ), $login );
	}

	/**
	 * Register URL (MyEasyBuy registration page when available).
	 */
	public static function get_register_url(): string {
		$url = class_exists( 'MyEasyBuy_Registration' )
			? \MyEasyBuy_Registration::get_register_url()
			: wc_get_page_permalink( 'myaccount' );

		return add_query_arg( 'redirect_to', rawurlencode( self::get_url() ), $url );
	}

	/**
	 * Render the page sections inside templates/forwarding-center/page.php.
	 */
	public static function render_body(): void {
		$logged_in = is_user_logged_in() && current_user_can( 'read' );
		$lists     = $logged_in ? self::get_package_list_data() : array();

		self::get_template(
			'forwarding-center/hero.php',
			array(
				'logged_in'      => $logged_in,
				'warehouse_code' => $logged_in ? My_Account::ensure_warehouse_code() : '',
				'counts'         => array(
					'pending' => count( $lists['pending_packages'] ?? array() ),
					'inbound' => count( $lists['inbound_packages'] ?? array() ),
					'transit' => count( $lists['transit_packages'] ?? array() ),
				),
				'login_url'      => self::get_login_url(),
				'register_url'   => self::get_register_url(),
			)
		);

		if ( function_exists( 'wc_print_notices' ) ) {
			echo '<div class="wc-rf-fc__notices">';
			wc_print_notices();
			echo '</div>';
		}

		self::get_template( 'forwarding-center/steps.php' );

		if ( ! $logged_in ) {
			return;
		}

		echo '<div class="wc-rf-fc__grid">';
		self::get_template( 'forwarding-center/warehouse-address.php', My_Account::get_address_card_data( get_current_user_id() ) );
		self::get_template( 'forwarding-center/package-declare.php', Package_Actions::get_form_data() );
		echo '</div>';

		self::get_template( 'forwarding-center/package-list.php', $lists );
	}

	/**
	 * Template args for the package list tabs.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_package_list_data(): array {
		$grouped = Package::grouped_for_current_user();
		$tab     = isset( $_GET['rf_tab'] ) ? sanitize_key( wp_unslash( $_GET['rf_tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $tab, array( 'pending', 'inbound', 'transit' ), true ) ) {
			if ( ! empty( $grouped['inbound'] ) ) {
				$tab = 'inbound';
			} elseif ( ! empty( $grouped['transit'] ) && empty( $grouped['pending'] ) ) {
				$tab = 'transit';
			} else {
				$tab = 'pending';
			}
		}

		return array(
			'pending_packages'         => $grouped['pending'],
			'inbound_packages'         => $grouped['inbound'],
			'transit_packages'         => $grouped['transit'],
			'active_tab'               => $tab,
			'action_url'               => admin_url( 'admin-post.php' ),
			'redirect_to'              => self::get_url(),
			'consolidate_action'       => Package_Actions::ACTION_CONSOLIDATE,
			'consolidate_nonce_action' => Package_Actions::ACTION_CONSOLIDATE,
			'consolidate_nonce_name'   => Package_Actions::NONCE_CONSOLIDATE,
			'cancel_action'            => Package_Actions::ACTION_CANCEL,
			'cancel_nonce_action'      => Package_Actions::ACTION_CANCEL,
			'cancel_nonce_name'        => Package_Actions::NONCE_CANCEL,
		);
	}

	/**
	 * Load a plugin template, allowing theme override.
	 *
	 * Override path: yourtheme/wc-reverse-forwarding/{template_name}
	 *
	 * @param string               $template_name Relative template path.
	 * @param array<string, mixed> $args          Template variables.
	 */
	public static function get_template( string $template_name, array $args = array() ): void {
		wc_get_template( $template_name, $args, self::TEMPLATE_PATH, WC_RF_PLUGIN_DIR . 'templates/' );
	}

	/**
	 * Inline stroke icon used by the page templates.
	 */
	public static function icon( string $name ): string {
		$paths = array(
			'box'       => '<path d="M21 8 12 3 3 8v8l9 5 9-5z"/><path d="m3 8 9 5 9-5M12 13v8"/>',
			'tag'       => '<path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
			'clip'      => '<rect x="5" y="4" width="14" height="18" rx="2"/><path d="M9 2h6v4H9zM9 11h6M9 15h6"/>',
			'warehouse' => '<path d="M3 21V8l9-5 9 5v13"/><path d="M7 21v-8h10v8M7 17h10"/>',
			'plane'     => '<path d="M17.8 19.2 16 11l3.5-3.5C21 6 21.5 4 21 3c-1-.5-3 0-4.5 1.5L13 8 4.8 6.2c-.5-.1-.9.1-1.1.5l-.3.5c-.2.5-.1 1 .3 1.3L9 12l-2 3H4l-1 1 3 2 2 3 1-1v-3l3-2 3.5 5.3c.3.4.8.5 1.3.3l.5-.2c.4-.3.6-.7.5-1.2z"/>',
			'pin'       => '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/>',
			'copy'      => '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
			'list'      => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
		);

		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ( $paths[ $name ] ?? '' ) . '</svg>';
	}

	/**
	 * Front-end assets.
	 */
	public static function enqueue_assets(): void {
		if ( ! self::is_page() ) {
			return;
		}

		$css = 'assets/css/forwarding-center.css';
		$js  = 'assets/js/forwarding-center.js';

		wp_enqueue_style(
			'wc-rf-forwarding-center',
			plugins_url( $css, WC_RF_PLUGIN_FILE ),
			array(),
			WC_RF_VERSION . '.' . filemtime( WC_RF_PLUGIN_DIR . $css )
		);

		wp_enqueue_script(
			'wc-rf-forwarding-center',
			plugins_url( $js, WC_RF_PLUGIN_FILE ),
			array(),
			WC_RF_VERSION . '.' . filemtime( WC_RF_PLUGIN_DIR . $js ),
			true
		);

		wp_localize_script(
			'wc-rf-forwarding-center',
			'wcRfForwardingCenter',
			array(
				'copied'     => __( '已复制', 'wc-rf' ),
				'failed'     => __( '复制失败，请手动选择文本', 'wc-rf' ),
				'needSelect' => __( '请至少勾选一个已到仓包裹。', 'wc-rf' ),
			)
		);
	}

	/**
	 * Main query carrying this page's query var.
	 */
	private static function is_own_query( WP_Query $query ): bool {
		return $query->is_main_query() && '1' === (string) $query->get( self::QUERY_VAR );
	}
}
