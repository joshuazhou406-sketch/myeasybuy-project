<?php
// phpcs:ignoreFile
/**
 * Plugin Name: WTranslator
 * Plugin URI: https://wordpress.org/plugins/wtranslator/
 * Description: Translate your website into 248+ languages with a language switcher. Uses Google Translate in the visitor's browser.
 * Version: 1.0.19
 * Requires at least: 5.0
 * Requires PHP: 7.0
 * Author: WTranslator
 * Author URI: https://profiles.wordpress.org/wtranslator/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wtranslator
 * Domain Path: /languages
 *
 * @package WTranslator
 */

// phpcs:disable Squiz.Commenting.InlineComment.InvalidEndChar,Squiz.Commenting.VariableComment.MissingVar,Squiz.Commenting.FunctionComment.MissingParamTag,Squiz.Commenting.ClassComment.Missing,Squiz.PHP.CommentedOutCode.Found,Generic.CodeAnalysis.UnusedFunctionParameter.Found,Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed,WordPress.PHP.YodaConditions.NotYoda,Squiz.PHP.DisallowSizeFunctionsInLoops.Found,Universal.Operators.DisallowShortTernary.Found,Squiz.Commenting.DocCommentAlignment.SpaceBeforeStar,Universal.WhiteSpace.PrecisionAlignment.Found,Generic.CodeAnalysis.EmptyStatement.DetectedElse,Generic.CodeAnalysis.EmptyStatement.DetectedIf,Universal.Operators.StrictComparisons.LooseEqual,Squiz.PHP.NonExecutableCode.ReturnNotRequired,WordPress.PHP.StrictInArray.MissingTrueStrict,Universal.Files.SeparateFunctionsFromOO.Mixed,Squiz.Commenting.FunctionComment.WrongStyle

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
define( 'WTRANSLATOR_VERSION', '1.0.19' );
define( 'WTRANSLATOR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WTRANSLATOR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WTRANSLATOR_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'WTRANSLATOR_PRO_UPGRADE_URL', 'https://wtranslator.com/pricing/?utm_source=Plugin&utm_medium=upgrade-dashboard-notifications-menu&utm_campaign=WP' );

/**
 * Main Plugin Class
 */
class WTranslator {

	/**
	 * Single instance of the class
	 */
	private static $instance = null;

	/**
	 * Plugin settings
	 */
	private $settings = null;

	/**
	 * Tracks whether wp_head output buffering started
	 */
	private $head_buffer_started = false;

	/**
	 * Tracks whether main frontend output buffering was started (for shutdown cleanup)
	 */
	private $main_buffer_started = false;

	/**
	 * Get singleton instance
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		$this->init_hooks();
		$this->load_dependencies();
	}

	/**
	 * Initialize WordPress hooks
	 */
	private function init_hooks() {
		// Activation and deactivation hooks
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
		register_uninstall_hook( __FILE__, array( 'WTranslator', 'uninstall' ) );

		// Hide page until translation completes - feels like server translation
		// Google Translate Element API loads but page shows only after translation
		add_action( 'init', array( $this, 'start_output_buffering' ), 1 );
		add_action( 'wp_footer', array( $this, 'end_output_buffering' ), 999 );
		add_action( 'shutdown', array( $this, 'shutdown_cleanup_output_buffer' ), 0 );
		add_action( 'shutdown', array( $this, 'shutdown_cleanup_head_buffer' ), 0 );

		// Initialize plugin
		add_action( 'plugins_loaded', array( $this, 'init' ) );

		// Admin hooks
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'handle_settings_form_submit' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_menu_icon' ) );
		add_action( 'in_admin_header', array( $this, 'hide_foreign_admin_notices' ), PHP_INT_MAX );

		// Enqueue jQuery UI for sortable
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_jquery_ui' ) );

		// Frontend hooks
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );

		// Bypass Rank Math sitemap cache for language-specific sitemaps
		add_filter( 'rank_math/sitemap/cache_mode', array( $this, 'bypass_sitemap_cache_for_language' ), 10, 1 );
		add_filter( 'rank_math/sitemap/cache_directory', array( $this, 'add_language_to_cache_directory' ), 10, 1 );

		// AJAX IP detection (clean URL way)
		add_action( 'wp_ajax_wtranslator_detect_ip', array( $this, 'ajax_detect_ip' ) );
		add_action( 'wp_ajax_nopriv_wtranslator_detect_ip', array( $this, 'ajax_detect_ip' ) );

		// SEO hooks (WTranslator style)
		add_action( 'wp_head', array( $this, 'output_seo_tags' ), 1 ); // Hreflang and canonical
		add_filter( 'wpseo_canonical', array( $this, 'filter_canonical_url' ), 10, 1 ); // Yoast SEO compatibility
		add_filter( 'rank_math/frontend/canonical', array( $this, 'filter_canonical_url' ), 10, 1 ); // Rank Math compatibility

		// Meta title and description translation hooks (WTranslator style - server-side)
		// Use very high priority (9999) to ensure our translation runs LAST, after all SEO plugins
		add_filter( 'wpseo_title', array( $this, 'translate_meta_title' ), 9999, 1 ); // Yoast SEO meta title
		add_filter( 'wpseo_metadesc', array( $this, 'translate_meta_description' ), 9999, 1 ); // Yoast SEO meta description
		add_filter( 'rank_math/frontend/title', array( $this, 'translate_meta_title' ), 9999, 1 ); // Rank Math meta title
		add_filter( 'rank_math/frontend/description', array( $this, 'translate_meta_description' ), 9999, 1 ); // Rank Math meta description
		add_filter( 'document_title_parts', array( $this, 'translate_document_title' ), 9999, 1 ); // WordPress default title
		add_action( 'wp_head', array( $this, 'output_meta_description' ), 1 ); // Output meta description with translation

		// Rank Math specific filters (multiple hooks to catch all cases)
		add_filter( 'rank_math/get_meta_description', array( $this, 'translate_meta_description' ), 9999, 1 );
		add_filter( 'rank_math/head/description', array( $this, 'translate_meta_description' ), 9999, 1 );
		add_filter( 'rank_math/paper/description', array( $this, 'translate_meta_description' ), 9999, 1 );

		// Additional SEO plugins (server-side meta description)
		add_filter( 'aioseo_description', array( $this, 'translate_meta_description' ), 9999, 1 ); // All in One SEO
		add_filter( 'seopress_titles_desc', array( $this, 'translate_meta_description' ), 9999, 1 ); // SEOPress

		// Safety net: intercept any meta description tags printed in wp_head
		// (covers themes/plugins that output <meta name="description"> without filters)
		add_action( 'wp_head', array( $this, 'intercept_meta_description_tags' ), 0 ); // Very early priority
		add_action( 'wp_head', array( $this, 'end_intercept_meta_description_tags' ), 99999 ); // Very late priority
		// Hide page until translation completes - feels like server translation
		// Only hide if NOT default language
		add_action( 'template_redirect', array( $this, 'output_early_hide_script' ), 1 );
		add_action( 'wp_enqueue_scripts', array( $this, 'output_translation_loader' ), 5 ); // Register inline translation script
		add_action( 'wp_head', array( $this, 'output_prevent_flash_css' ), 0 ); // Hide page until translation completes
		add_action( 'wp_head', array( $this, 'output_default_language_show' ), 1 ); // Show page immediately for default language
		add_action( 'wp_head', array( $this, 'add_skip_meta_tags' ), 0 ); // Add notranslate meta if skipped
		add_filter( 'body_class', array( $this, 'add_skip_body_class' ) ); // Add notranslate class if skipped

		// Sync settings to Laravel when updated in WordPress
		add_action( 'updated_option_wtranslator_settings', array( $this, 'sync_settings_to_laravel' ), 10, 3 );

		// Widget rendering - WTranslator style (hooks added dynamically based on settings)
		add_action( 'init', array( $this, 'setup_widget_hooks' ) );

		// Translate post meta (for Rank Math, Yoast SEO, etc.) - only on frontend
		add_filter( 'get_post_metadata', array( $this, 'translate_post_meta' ), 10, 4 );

		// Client-side translation: Use Google Translate Element API (WTranslator style)
		// Google Translate Element API handles all translation automatically
		// This ensures smooth and fast translation

		// Enable server-side translation for meta tags and specific content
		// Meta tags (title, description) need server-side translation

		$this->enable_server_side_translation();

		add_action( 'template_redirect', array( $this, 'handle_language_switch' ) );

		// AJAX handlers
		add_action( 'wp_ajax_wtranslator_translate', array( $this, 'ajax_translate' ) );
		add_action( 'wp_ajax_nopriv_wtranslator_translate', array( $this, 'ajax_translate' ) );
		add_action( 'wp_ajax_wtranslator_clear_cache', array( $this, 'ajax_clear_cache' ) );
		add_action( 'wp_ajax_wtranslator_save_settings', array( $this, 'ajax_save_settings' ) );

		// Settings link
		add_filter( 'plugin_action_links_' . WTRANSLATOR_PLUGIN_BASENAME, array( $this, 'add_settings_link' ) );
	}

	/**
	 * Sync settings to external app. Plugin is fully standalone; no external API or sync is used.
	 */
	public function sync_settings_to_laravel( $old_value, $new_value, $option ) {
		// Plugin is fully functional without any external service; no sync.
	}

	/**
	 * Load plugin dependencies
	 */
	private function load_dependencies() {
		require_once WTRANSLATOR_PLUGIN_DIR . 'includes/class-google-translate.php';
		require_once WTRANSLATOR_PLUGIN_DIR . 'includes/class-language-manager.php';
		require_once WTRANSLATOR_PLUGIN_DIR . 'includes/class-cache-manager.php';
		require_once WTRANSLATOR_PLUGIN_DIR . 'includes/class-admin-settings.php';
	}

	/**
	 * Initialize plugin
	 */
	public function init() {
		load_plugin_textdomain(
			'wtranslator',
			false,
			dirname( WTRANSLATOR_PLUGIN_BASENAME ) . '/languages'
		);

		$this->get_settings();
	}

	/**
	 * Check and fix .htaccess if it contains incorrect RewriteBase for any supported language
	 */
	public static function check_and_fix_htaccess() {
		if ( ! defined( 'ABSPATH' ) ) {
			return;
		}

		$htaccess_file = ABSPATH . '.htaccess';

		// Initialize the WP filesystem
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		WP_Filesystem();
		global $wp_filesystem;

		if ( $wp_filesystem && $wp_filesystem->exists( $htaccess_file ) && $wp_filesystem->is_writable( $htaccess_file ) ) {
			$content = $wp_filesystem->get_contents( $htaccess_file );
			$changed = false;

			// List of all supported languages
			$languages = array(
				'af',
				'sq',
				'am',
				'ar',
				'hy',
				'az',
				'eu',
				'be',
				'bn',
				'bs',
				'bg',
				'ca',
				'ceb',
				'ny',
				'zh-CN',
				'zh-TW',
				'co',
				'hr',
				'cs',
				'da',
				'nl',
				'en',
				'eo',
				'et',
				'tl',
				'fi',
				'fr',
				'fy',
				'gl',
				'ka',
				'de',
				'el',
				'gu',
				'ht',
				'ha',
				'haw',
				'he',
				'hi',
				'hmn',
				'hu',
				'is',
				'ig',
				'id',
				'ga',
				'it',
				'ja',
				'jw',
				'kn',
				'kk',
				'km',
				'ko',
				'ku',
				'ky',
				'lo',
				'la',
				'lv',
				'lt',
				'lb',
				'mk',
				'mg',
				'ms',
				'ml',
				'mt',
				'mi',
				'mr',
				'mn',
				'my',
				'ne',
				'no',
				'ps',
				'fa',
				'pl',
				'pt',
				'pa',
				'ro',
				'ru',
				'sm',
				'gd',
				'sr',
				'st',
				'sn',
				'sd',
				'si',
				'sk',
				'sl',
				'so',
				'es',
				'su',
				'sw',
				'sv',
				'tg',
				'ta',
				'te',
				'th',
				'tr',
				'uk',
				'ur',
				'uz',
				'vi',
				'cy',
				'xh',
				'yi',
				'yo',
				'zu',
			);

			foreach ( $languages as $lang ) {
				// Check for RewriteBase /lang/
				$rewrite_base = "RewriteBase /{$lang}/";
				if ( strpos( $content, $rewrite_base ) !== false ) {
					$content = str_replace( $rewrite_base, 'RewriteBase /', $content );
					$changed = true;
				}

				// Check for RewriteRule . /lang/index.php
				$rewrite_rule = "RewriteRule . /{$lang}/index.php";
				if ( strpos( $content, $rewrite_rule ) !== false ) {
					$content = str_replace( $rewrite_rule, 'RewriteRule . /index.php', $content );
					$changed = true;
				}
			}

			if ( $changed && $wp_filesystem ) {
				$wp_filesystem->put_contents( $htaccess_file, $content, FS_CHMOD_FILE );
			}
		}
	}

	/**
	 * Plugin activation
	 */
	public function activate() {
		// Set default settings only on first install. Never overwrite existing config.
		$default_settings = array(
			'saas_api_url'               => '',
			'default_language'           => 'en',
			'translate_from'             => 'en',
			'enabled_languages'          => array( 'en', 'es', 'fr', 'de', 'bn', 'it', 'pt', 'ru', 'ja', 'zh-CN', 'ar' ),
			'switcher_style'             => 'dropdown',
			'widget_look'                => 'dropdown',
			'open_direction'             => 'down', // Default: down
			'floating_language_selector' => 'top_right', // Default: top right
			'show_floating'              => true, // Backward compatibility
			'show_in_menu'               => '', // Default: none
			'wrapper_selector'           => '.wtranslator-wrapper',
			'auto_detect'                => false, // Default: disabled
			'native_language_names'      => false, // Default: enabled
			'cache_enabled'              => true,
			'cache_duration'             => 86400, // 24 hours
			'language_order'             => array(), // Will be populated on first save
			'skipped_phrases'            => array(),
			'skipped_pages'              => array(),
			'skipped_selectors'          => '',
			'find_replace_rules'         => array(),
			'translate_json'             => false,
			'json_key_paths'             => array(),
			'html_blocks_selectors'      => '',
		);

		// add_option does nothing when the option already exists.
		add_option( 'wtranslator_settings', $default_settings );

		// Clear all transients (cache)
		global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk cleanup operation on activation
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				'_transient_wtranslator_%',
				'_transient_timeout_wtranslator_%'
			)
		);

		// Create cache directory
		$cache_dir = WP_CONTENT_DIR . '/cache/wtranslator';
		if ( ! file_exists( $cache_dir ) ) {
			wp_mkdir_p( $cache_dir );
		}

		// Flush rewrite rules
		flush_rewrite_rules();

		// Fix .htaccess if needed (remove /bn/ base if incorrectly added)
		self::check_and_fix_htaccess();
	}

	/**
	 * Plugin deactivation
	 */
	public function deactivate() {
		// Clean up rewrite rules
		flush_rewrite_rules();
	}

	/**
	 * Plugin uninstall - Remove all plugin data from database
	 * This is called when plugin is deleted from WordPress
	 */
	public static function uninstall() {
		// Check if user has permission to uninstall
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		// Check if user wants to delete data (WordPress asks for confirmation)
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			return;
		}

		global $wpdb;

		// Delete all plugin options from database
		delete_option( 'wtranslator_settings' );
		delete_option( 'wtranslator_usage_consent' );
		delete_option( 'wtranslator_usage_notice_dismissed' );

		// Delete all transients (cached data)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk cleanup on uninstall
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				'_transient_wtranslator_%',
				'_transient_timeout_wtranslator_%'
			)
		);

		// Delete all transients with different patterns
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				'_transient_wtranslator_license_%',
				'_transient_timeout_wtranslator_license_%'
			)
		);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				'_transient_wtranslator_permissions_%',
				'_transient_timeout_wtranslator_permissions_%'
			)
		);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				'_transient_wtranslator_domain_%',
				'_transient_timeout_wtranslator_domain_%'
			)
		);

		// Delete cache directory and all cache files
		$cache_dir = WP_CONTENT_DIR . '/cache/wtranslator';
		if ( file_exists( $cache_dir ) && is_dir( $cache_dir ) ) {
			// Recursively delete cache directory
			self::delete_directory( $cache_dir );
		}

		// Delete user meta (if any plugin-specific user meta exists)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk cleanup on uninstall
		$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'wtranslator_%'" );

		// Delete post meta (if any plugin-specific post meta exists)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE 'wtranslator_%'" );

		// Delete term meta (if any plugin-specific term meta exists)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}termmeta'" ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE 'wtranslator_%'" );
		}

		// Flush rewrite rules
		flush_rewrite_rules();

		// Clear any object cache
		wp_cache_flush();
	}

	/**
	 * Helper function to recursively delete directory
	 */
	private static function delete_directory( $dir ) {
		if ( ! file_exists( $dir ) ) {
			return true;
		}

		// Initialize the WP filesystem
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		// Use direct method if possible to avoid credentials check
		if ( defined( 'FS_METHOD' ) && FS_METHOD === 'direct' ) {
			WP_Filesystem();
		} else {
			// Fallback for different filesystem environments
			$creds = request_filesystem_credentials( '', '', false, false, null );
			if ( false === $creds ) {
				return false;
			}

			if ( ! WP_Filesystem( $creds ) ) {
				return false;
			}
		}

		global $wp_filesystem;

		if ( $wp_filesystem ) {
			return $wp_filesystem->delete( $dir, true );
		}

		return false;
	}

	/**
	 * Add admin menu
	 */
	public function add_admin_menu() {
		add_menu_page(
			__( 'WTranslator Settings', 'wtranslator' ),
			__( 'WTranslator', 'wtranslator' ),
			'manage_options',
			'wtranslator',
			array( $this, 'render_settings_page' ),
			'dashicons-translation',
			30
		);

		add_submenu_page(
			'wtranslator',
			__( 'WTranslator Settings', 'wtranslator' ),
			__( 'Settings', 'wtranslator' ),
			'manage_options',
			'wtranslator',
			array( $this, 'render_settings_page' )
		);

		global $submenu;
		if ( isset( $submenu['wtranslator'] ) && current_user_can( 'manage_options' ) ) {
			$submenu['wtranslator'][] = array(
				__( 'Unlock PRO', 'wtranslator' ),
				'manage_options',
				WTRANSLATOR_PRO_UPGRADE_URL,
			);
		}
	}

	/**
	 * Whether the current request is the WTranslator settings screen.
	 *
	 * @return bool
	 */
	private function is_wtranslator_admin_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && isset( $screen->id ) && 'toplevel_page_wtranslator' === $screen->id ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen check.
		return isset( $_GET['page'] ) && 'wtranslator' === sanitize_key( wp_unslash( $_GET['page'] ) );
	}

	/**
	 * Hide notices from other plugins/themes on our settings page only.
	 */
	public function hide_foreign_admin_notices() {
		if ( ! $this->is_wtranslator_admin_screen() ) {
			return;
		}

		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
		remove_all_actions( 'user_admin_notices' );
		remove_all_actions( 'network_admin_notices' );
	}

	/**
	 * Register settings
	 */
	public function register_settings() {
		register_setting(
			'wtranslator_settings_group',
			'wtranslator_settings',
			array(
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
			)
		);
	}

	/**
	 * Handle settings form submission early (admin_init), before any headers are sent,
	 * so wp_safe_redirect() always works without a JavaScript fallback.
	 */
	public function handle_settings_form_submit() {
		if (
			! isset( $_POST['wtranslator_save_settings'] ) ||
			! isset( $_POST['_wpnonce'] ) ||
			! current_user_can( 'manage_options' )
		) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'wtranslator_settings_nonce' ) ) {
			return;
		}

		if ( ! class_exists( 'WTranslator_Admin_Settings' ) ) {
			require_once WTRANSLATOR_PLUGIN_DIR . 'includes/class-admin-settings.php';
		}

		$admin_settings = new WTranslator_Admin_Settings();
		$admin_settings->save_settings();
		// save_settings() calls wp_safe_redirect() and exits.
	}

	/**
	 * Sanitize settings
	 */
	public function sanitize_settings( $input ) {
		$sanitized = array();

		if ( isset( $input['default_language'] ) ) {
			$sanitized['default_language'] = sanitize_text_field( $input['default_language'] );
		}

		if ( isset( $input['enabled_languages'] ) && is_array( $input['enabled_languages'] ) ) {
			$sanitized['enabled_languages'] = array_map( 'sanitize_text_field', $input['enabled_languages'] );
		}

		if ( isset( $input['switcher_style'] ) ) {
			$sanitized['switcher_style'] = sanitize_text_field( $input['switcher_style'] );
		}

		if ( isset( $input['widget_look'] ) ) {
			$sanitized['widget_look'] = sanitize_text_field( $input['widget_look'] );
		}

		if ( isset( $input['translate_from'] ) ) {
			$sanitized['translate_from'] = sanitize_text_field( $input['translate_from'] );
		}

		if ( isset( $input['open_direction'] ) ) {
			$sanitized['open_direction'] = sanitize_text_field( $input['open_direction'] );
		}

		if ( isset( $input['show_in_menu'] ) ) {
			$sanitized['show_in_menu'] = sanitize_text_field( $input['show_in_menu'] );
		}

		if ( isset( $input['floating_language_selector'] ) ) {
			$sanitized['floating_language_selector'] = sanitize_text_field( $input['floating_language_selector'] );
		}

		if ( isset( $input['wrapper_selector'] ) ) {
			$sanitized['wrapper_selector'] = sanitize_text_field( $input['wrapper_selector'] );
		}

		if ( isset( $input['auto_detect'] ) ) {
			$sanitized['auto_detect'] = (bool) $input['auto_detect'];
		}
		if ( isset( $input['cache_enabled'] ) ) {
			$sanitized['cache_enabled'] = (bool) $input['cache_enabled'];
		}
		if ( isset( $input['native_language_names'] ) ) {
			$sanitized['native_language_names'] = (bool) $input['native_language_names'];
		}

		if ( isset( $input['translate_json'] ) ) {
			$sanitized['translate_json'] = (bool) $input['translate_json'];
		}

		// Handle show_floating derived from floating_language_selector
		if ( isset( $input['floating_language_selector'] ) ) {
			$sanitized['show_floating'] = $input['floating_language_selector'] !== 'no';
		}

		if ( isset( $input['cache_duration'] ) ) {
			$sanitized['cache_duration'] = absint( $input['cache_duration'] );
		}

		if ( isset( $input['language_order'] ) ) {
			$order                       = is_string( $input['language_order'] ) ? explode( ',', $input['language_order'] ) : $input['language_order'];
			$sanitized['language_order'] = array_map( 'sanitize_text_field', array_filter( (array) $order ) );
		}

		if ( isset( $input['skipped_phrases'] ) && is_array( $input['skipped_phrases'] ) ) {
			$sanitized['skipped_phrases'] = array_map( 'sanitize_text_field', $input['skipped_phrases'] );
		}

		if ( isset( $input['skipped_pages'] ) && is_array( $input['skipped_pages'] ) ) {
			$sanitized['skipped_pages'] = array_map( 'sanitize_text_field', $input['skipped_pages'] );
		}

		if ( isset( $input['skipped_selectors'] ) ) {
			$sanitized['skipped_selectors'] = sanitize_textarea_field( $input['skipped_selectors'] );
		}

		if ( isset( $input['find_replace_rules'] ) && is_array( $input['find_replace_rules'] ) ) {
			$sanitized['find_replace_rules'] = array_map(
				function ( $rule ) {
					return array(
						'id'             => sanitize_text_field( $rule['id'] ?? '' ),
						'type'           => sanitize_text_field( $rule['type'] ?? 'original' ),
						'find'           => sanitize_text_field( $rule['find'] ?? '' ),
						'replace'        => sanitize_text_field( $rule['replace'] ?? '' ),
						'target_lang'    => sanitize_text_field( $rule['target_lang'] ?? 'all' ),
						'target_page'    => sanitize_text_field( $rule['target_page'] ?? 'all' ),
						'case_sensitive' => isset( $rule['case_sensitive'] ) ? (bool) $rule['case_sensitive'] : false,
					);
				},
				$input['find_replace_rules']
			);
		}

		if ( isset( $input['translate_json'] ) ) {
			$sanitized['translate_json'] = (bool) $input['translate_json'];
		}

		if ( isset( $input['json_key_paths'] ) && is_array( $input['json_key_paths'] ) ) {
			$sanitized['json_key_paths'] = array_map( 'sanitize_text_field', $input['json_key_paths'] );
		}

		if ( isset( $input['html_blocks_selectors'] ) ) {
			$sanitized['html_blocks_selectors'] = sanitize_textarea_field( $input['html_blocks_selectors'] );
		}

		// Preserve connection settings if they exist in input
		if ( isset( $input['saas_api_key'] ) ) {
			$sanitized['saas_api_key'] = sanitize_text_field( $input['saas_api_key'] );
		}
		if ( isset( $input['saas_api_url'] ) ) {
			$sanitized['saas_api_url'] = esc_url_raw( $input['saas_api_url'] );
		}
		if ( isset( $input['plugin_url'] ) ) {
			$sanitized['plugin_url'] = esc_url_raw( $input['plugin_url'] );
		}
		if ( isset( $input['connection_status'] ) ) {
			$sanitized['connection_status'] = sanitize_text_field( $input['connection_status'] );
		}

		return $sanitized;
	}

	/**
	 * Enqueue jQuery UI for sortable
	 */
	public function enqueue_jquery_ui( $hook ) {
		if ( 'toplevel_page_wtranslator' !== $hook ) {
			return;
		}

		wp_enqueue_script( 'jquery-ui-sortable' );
	}

	/**
	 * Style the static translation dashicon in the admin menu.
	 */
	public function enqueue_admin_menu_icon() {
		wp_register_style( 'wtranslator-admin-menu', false, array(), WTRANSLATOR_VERSION );
		wp_enqueue_style( 'wtranslator-admin-menu' );
		wp_add_inline_style(
			'wtranslator-admin-menu',
			'#adminmenu #toplevel_page_wtranslator .wp-menu-image:before{font-size:18px;}'
			. '#adminmenu #toplevel_page_wtranslator a[href*="wtranslator.com/pricing"]{background:#16a34a;color:#fff;font-weight:600;}'
			. '#adminmenu #toplevel_page_wtranslator a[href*="wtranslator.com/pricing"]:hover,'
			. '#adminmenu #toplevel_page_wtranslator a[href*="wtranslator.com/pricing"]:focus{background:#15803d;color:#fff;}'
		);

		wp_register_script( 'wtranslator-admin-menu', false, array(), WTRANSLATOR_VERSION, true );
		wp_enqueue_script( 'wtranslator-admin-menu' );
		wp_add_inline_script(
			'wtranslator-admin-menu',
			'document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll("#adminmenu #toplevel_page_wtranslator a[href*=\'wtranslator.com/pricing\']").forEach(function(a){a.setAttribute("target","_blank");a.setAttribute("rel","noopener noreferrer");});});'
		);
	}

	/**
	 * Enqueue admin assets
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( 'toplevel_page_wtranslator' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'wtranslator-admin',
			WTRANSLATOR_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			WTRANSLATOR_VERSION
		);

		// Frontend widget CSS for inline preview on settings page (no iframe)
		wp_enqueue_style(
			'wtranslator-frontend',
			WTRANSLATOR_PLUGIN_URL . 'assets/css/frontend.css',
			array(),
			WTRANSLATOR_VERSION
		);

		wp_enqueue_script(
			'wtranslator-admin',
			WTRANSLATOR_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery', 'jquery-ui-sortable' ),
			WTRANSLATOR_VERSION,
			true
		);

		wp_localize_script(
			'wtranslator-admin',
			'wtranslatorAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wtranslator_admin_nonce' ),
			)
		);

		// Dropdown toggle for widget preview on settings page (no iframe)
		wp_add_inline_script(
			'wtranslator-admin',
			"
            jQuery(function($){
                $(document).on('click', '.wtranslator-widget-preview-inline .wtranslator-dropdown-toggle, .wtranslator-widget-preview-inline .wtranslator-menu-toggle', function(e){
                    e.preventDefault();
                    var \$dd = $(this).closest('.wtranslator-dropdown, .wtranslator-menu-dropdown');
                    \$dd.toggleClass('active');
                });
                $(document).on('click', function(e){
                    if (!$(e.target).closest('.wtranslator-widget-preview-inline .wtranslator-dropdown, .wtranslator-widget-preview-inline .wtranslator-menu-dropdown').length) {
                        $('.wtranslator-widget-preview-inline .wtranslator-dropdown, .wtranslator-widget-preview-inline .wtranslator-menu-dropdown').removeClass('active');
                    }
                });
                // Dummy preview: language item click does nothing (no navigation), only update visual selection
                $(document).on('click', '.wtranslator-widget-preview-inline .wtranslator-lang-item, .wtranslator-widget-preview-inline .wtranslator-flag-item, .wtranslator-widget-preview-inline .wtranslator-flag-name-item', function(e){
                    e.preventDefault();
                    e.stopPropagation();
                    var \$item = $(this);
                    var \$dd = \$item.closest('.wtranslator-dropdown, .wtranslator-menu-dropdown');
                    \$dd.find('.active').removeClass('active');
                    \$item.addClass('active');
                    var \$toggle = \$dd.find('.wtranslator-dropdown-toggle, .wtranslator-menu-toggle');
                    var \$flag = \$item.find('.wtranslator-flag').first();
                    var \$name = \$item.find('.wtranslator-lang-name').first();
                    if (\$toggle.length && (\$flag.length || \$name.length)) {
                        \$toggle.find('.wtranslator-flag').first().text(\$flag.text());
                        \$toggle.find('.wtranslator-lang-name').first().text(\$name.text());
                    }
                    \$dd.removeClass('active');
                });
            });
        "
		);
	}

	/**
	 * Enqueue frontend assets
	 */
	public function enqueue_frontend_assets() {
		wp_enqueue_style(
			'wtranslator-frontend',
			WTRANSLATOR_PLUGIN_URL . 'assets/css/frontend.css',
			array(),
			WTRANSLATOR_VERSION
		);

		wp_enqueue_script(
			'wtranslator-frontend',
			WTRANSLATOR_PLUGIN_URL . 'assets/js/frontend.js',
			array( 'jquery' ),
			WTRANSLATOR_VERSION,
			true
		);

		// Add async/defer attributes for faster page loading
		add_filter(
			'script_loader_tag',
			function ( $tag, $handle ) {
				if ( $handle === 'wtranslator-frontend' ) {
					return str_replace( ' src', ' defer src', $tag );
				}
				return $tag;
			},
			10,
			2
		);

		if ( ! class_exists( 'WTranslator_Language_Manager' ) ) {
			require_once WTRANSLATOR_PLUGIN_DIR . 'includes/class-language-manager.php';
		}
		$language_manager = new WTranslator_Language_Manager();

		$settings = $this->get_settings();
		wp_localize_script(
			'wtranslator-frontend',
			'wtranslatorData',
			array(
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'nonce'            => wp_create_nonce( 'wtranslator_nonce' ),
				'currentLang'      => $this->get_current_language(),
				'defaultLang'      => $this->get_default_language(),
				'autoDetect'       => ! empty( $settings['auto_detect'] ),
				'enabledLanguages' => array_keys( $language_manager->get_enabled_languages() ),
			)
		);
	}

	/**
	 * CSS that hides Google Translate's banner/popup without disabling translation.
	 */
	private function get_google_translate_hide_css() {
		return '#google_translate_element2,div.skiptranslate,iframe.skiptranslate,iframe.goog-te-banner-frame,.goog-te-banner-frame,.goog-te-balloon-frame,#goog-gt-tt,.goog-tooltip,.VIpgJd-ZVi9od-ORHb-OEVmcd,.VIpgJd-ZVi9od-aZ2wEe-wOHMyf,.VIpgJd-ZVi9od-ORHb,.VIpgJd-ZVi9od-l4eHX-hSRGPd{display:none!important;visibility:hidden!important;opacity:0!important;pointer-events:none!important;height:0!important;max-height:0!important;width:0!important;border:none!important}body{top:0!important}font font{background-color:transparent!important;box-shadow:none!important;position:initial!important}#wpadminbar,#wpadminbar *{translate:no!important}';
	}

	/**
	 * Render settings page
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wtranslator' ) );
		}

		// Ensure class is loaded
		if ( ! class_exists( 'WTranslator_Admin_Settings' ) ) {
			require_once WTRANSLATOR_PLUGIN_DIR . 'includes/class-admin-settings.php';
		}

		try {
			$admin_settings = new WTranslator_Admin_Settings();
			$admin_settings->render();
		} catch ( Exception $e ) {
			echo '<div class="wrap"><h1>Error</h1><p>' . esc_html( $e->getMessage() ) . '</p></div>';
		}
	}

	/**
	 * Check if the current page should be skipped from translation
	 */
	public function is_page_skipped() {
		$settings = $this->get_settings();
		if ( empty( $settings['skipped_pages'] ) || ! is_array( $settings['skipped_pages'] ) ) {
			return false;
		}

		$http_host   = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$current_url = ( isset( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http' ) . '://' . $http_host . $request_uri;
		$path        = wp_parse_url( $current_url, PHP_URL_PATH ) ?: '/';

		// Normalize path: /path/
		$current_path = '/' . trim( $path, '/' ) . '/';
		if ( $current_path === '//' ) {
			$current_path = '/';
		}

		$current_lang = $this->get_current_language();
		$default_lang = $this->get_default_language();

		$clean_path = $current_path;

		foreach ( $settings['skipped_pages'] as $skipped_page ) {
			$skipped_page = trim( $skipped_page );
			if ( $skipped_page === '' ) {
				continue;
			}

			// 1. Exact URL Match (to handle very specific protocol/domain needs)
			if ( strpos( $skipped_page, 'http' ) === 0 ) {
				$norm_skipped_url = rtrim( $skipped_page, '/' );
				$norm_current_url = rtrim( explode( '?', $current_url )[0], '/' );
				if ( $norm_current_url === $norm_skipped_url ) {
					return true;
				}
			}

			// 2. Path-based match (Most common and robust)
			// Extract path from input (handles both full URLs and relative paths like /contact)
			$sp_path_raw = wp_parse_url( $skipped_page, PHP_URL_PATH ) ?: '/';
			$sp_path     = '/' . trim( $sp_path_raw, '/' ) . '/';
			if ( $sp_path === '//' ) {
				$sp_path = '/';
			}

			// Match against both the original path and the path without language prefix
			if ( $current_path === $sp_path || $clean_path === $sp_path ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Add notranslate meta tag if page is skipped
	 */
	public function add_skip_meta_tags() {
		if ( $this->is_page_skipped() ) {
			echo '<meta name="google" content="notranslate" />' . "\n";
		}
	}

	/**
	 * Add notranslate class to body if page is skipped
	 */
	public function add_skip_body_class( $classes ) {
		if ( $this->is_page_skipped() ) {
			$classes[] = 'notranslate';
		}
		return $classes;
	}    /**
	 * Check if a find/replace rule should be applied to the current page/language
	 */
	private function should_apply_rule( $rule ) {
		$current_lang = $this->get_current_language();

		// 1. Target Language Check
		if ( ! empty( $rule['target_lang'] ) && $rule['target_lang'] !== 'all' && $rule['target_lang'] !== $current_lang ) {
			return false;
		}

		// 2. Target Page Check
		if ( empty( $rule['target_page'] ) || $rule['target_page'] === 'all' || $rule['target_page'] === '*' ) {
			return true;
		}

		$http_host    = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$request_uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$current_url  = ( isset( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http' ) . '://' . $http_host . $request_uri;
		$path         = wp_parse_url( $current_url, PHP_URL_PATH ) ?: '/';
		$current_path = '/' . trim( $path, '/' ) . '/';
		if ( $current_path === '//' ) {
			$current_path = '/';
		}

		$clean_path = $current_path;

		$target_page = trim( $rule['target_page'] );

		// Exact URL Match
		if ( strpos( $target_page, 'http' ) === 0 ) {
			$norm_target_url  = rtrim( $target_page, '/' );
			$norm_current_url = rtrim( explode( '?', $current_url )[0], '/' );
			if ( $norm_current_url === $norm_target_url ) {
				return true;
			}
		}

		// Path-based match
		$tp_path_raw = wp_parse_url( $target_page, PHP_URL_PATH ) ?: '/';
		$tp_path     = '/' . trim( $tp_path_raw, '/' ) . '/';
		if ( $tp_path === '//' ) {
			$tp_path = '/';
		}

		if ( $current_path === $tp_path || $clean_path === $tp_path ) {
			return true;
		}

		return false;
	}

	/**
	 * Apply find and replace rules to the buffer
	 */
	public function apply_find_replace( $buffer, $type ) {
		if ( empty( $buffer ) ) {
			return $buffer;
		}

		$settings = $this->get_settings();
		if ( empty( $settings['find_replace_rules'] ) || ! is_array( $settings['find_replace_rules'] ) ) {
			return $buffer;
		}

		foreach ( $settings['find_replace_rules'] as $rule ) {
			if ( empty( $rule['find'] ) || empty( $rule['type'] ) || $rule['type'] !== $type ) {
				continue;
			}

			if ( ! $this->should_apply_rule( $rule ) ) {
				continue;
			}

			$find           = $rule['find'];
			$replace        = $rule['replace'] ?? '';
			$case_sensitive = ! empty( $rule['case_sensitive'] );

			if ( $case_sensitive ) {
				$buffer = str_replace( $find, $replace, $buffer );
			} else {
				$buffer = str_ireplace( $find, $replace, $buffer );
			}
		}

		return $buffer;
	}


	/**
	 * Setup widget hooks - WTranslator style
	 */
	public function setup_widget_hooks() {
		if ( is_admin() ) {
			return;
		}

		$settings = $this->get_settings();

		// Floating widget
		if ( ! empty( $settings['floating_language_selector'] ) && $settings['floating_language_selector'] !== 'no' ) {
			add_action( 'wp_footer', array( $this, 'render_floating_widget' ) );
		}

		// Menu integration
		if ( ! empty( $settings['show_in_menu'] ) ) {
			// Primary filter for menu items
			add_filter( 'wp_nav_menu_items', array( $this, 'add_language_switcher_to_menu' ), 10, 2 );

			// Alternative filter for walker-based menus
			add_filter( 'walker_nav_menu_start_el', array( $this, 'add_language_switcher_to_menu_walker' ), 10, 4 );
		}

		// Wrapper selector (for custom placement)
		if ( ! empty( $settings['wrapper_selector'] ) && trim( $settings['wrapper_selector'] ) !== '.wtranslator-wrapper' ) {
			add_action( 'wp_footer', array( $this, 'render_wrapper_widget' ) );
		}
	}

	/**
	 * Output translation loader script in head (early load)
	 */
	public function output_translation_loader() {
		// Only on frontend
		if ( is_admin() ) {
			return;
		}

		// 1. Skip Translation of Pages
		if ( $this->is_page_skipped() ) {
			$http_host    = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
			$request_uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			$current_url  = ( isset( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http' ) . '://' . $http_host . $request_uri;
			$current_path = wp_parse_url( $current_url, PHP_URL_PATH ) ?: '/';
			echo '<!-- Translate SaaS: Page skipped (' . esc_html( $current_path ) . ') -->';
			return;
		}

		$settings     = $this->get_settings();
		$current_lang = $this->get_current_language();
		$default_lang = $this->get_default_language();

		// Only if user has selected a non-default language
		if ( $current_lang === $default_lang ) {
			return;
		}

		// Find & Replace Rules for JS
		$fr_rules = array();
		if ( ! empty( $settings['find_replace_rules'] ) ) {
			foreach ( $settings['find_replace_rules'] as $rule ) {
				if ( $this->should_apply_rule( $rule ) ) {
					$fr_rules[] = array(
						'type'           => $rule['type'],
						'find'           => $rule['find'],
						'replace'        => $rule['replace'] ?? '',
						'case_sensitive' => ! empty( $rule['case_sensitive'] ),
					);
				}
			}
		}

		ob_start();
		?>
		(function() {
			var current_lang = '<?php echo esc_js( $current_lang ); ?>';
			var default_lang = '<?php echo esc_js( $default_lang ); ?>';
			
			// Only hide page if NOT default language
			if (current_lang !== default_lang) {
				// Hide page until translation completes - feels like server translation
				// Google Translate Element API loads but page shows only after translation
				document.documentElement.style.visibility = 'hidden';
				document.documentElement.style.opacity = '0';
			}
			
			// Show page function - will be called after translation completes
			window.wtranslatorShowPage = function() {
				// Remove hide style if exists
				var hideStyleEl = document.getElementById('wtranslator-prevent-flash');
				if (hideStyleEl) {
					hideStyleEl.remove();
				}
				// Show page - multiple methods to ensure it works
				if (document.documentElement) {
					document.documentElement.style.visibility = 'visible';
					document.documentElement.style.opacity = '1';
				}
				if (document.body) {
					document.body.style.visibility = 'visible';
					document.body.style.opacity = '1';
				}
				// Force show with CSS override
				var showStyle = document.createElement('style');
				showStyle.id = 'wtranslator-show-page';
				showStyle.textContent = 'html, html body { visibility: visible !important; opacity: 1 !important; }';
				document.head.appendChild(showStyle);
			};
			
			// If default language, show page immediately (no translation needed)
			if (current_lang === default_lang) {
				// Default language - show page immediately, no translation needed
				if (typeof window.wtranslatorShowPage === 'function') {
					window.wtranslatorShowPage();
				} else {
					// Direct show if function not available
					document.documentElement.style.visibility = 'visible';
					document.documentElement.style.opacity = '1';
					if (document.body) {
						document.body.style.visibility = 'visible';
						document.body.style.opacity = '1';
					}
				}
			} else {
				// Fallback: Show page after 1 second max (minimize white page time)
				setTimeout(function() {
					if (typeof window.wtranslatorShowPage === 'function') {
						window.wtranslatorShowPage();
					}
				}, 1000);
			}
			
			// Function to initialize when body is ready
			function initTranslation() {
				// Mark admin bar as notranslate BEFORE Google Translate loads
				(function() {
					var adminBar = document.getElementById('wpadminbar');
					if (adminBar) {
						adminBar.classList.add('notranslate');
						adminBar.setAttribute('translate', 'no');
						// Also mark all children
						var allElements = adminBar.querySelectorAll('*');
						for (var i = 0; i < allElements.length; i++) {
							allElements[i].classList.add('notranslate');
							allElements[i].setAttribute('translate', 'no');
						}
					}
				})();
				
				// Create Google Translate element
				if (!document.getElementById('google_translate_element2')) {
					var translateDiv = document.createElement('div');
					translateDiv.id = 'google_translate_element2';
					translateDiv.style.display = 'none';
					if (document.body) {
						document.body.appendChild(translateDiv);
					} else {
						// If body not ready, wait and try again
						setTimeout(initTranslation, 50);
						return;
					}
				}
				
				// Add CSS to hide Google Translate UI and exclude admin bar
				if (!document.querySelector('.wtranslator_css')) {
					var style = document.createElement('style');
					style.className = 'wtranslator_css';
					style.textContent = <?php echo wp_json_encode( $this->get_google_translate_hide_css() ); ?>;
					document.head.appendChild(style);
				}
				(function hideGoogleTranslateFrame() {
					var sel = 'iframe.skiptranslate,iframe.goog-te-banner-frame,.goog-te-banner-frame,.goog-te-balloon-frame,#goog-gt-tt,.VIpgJd-ZVi9od-ORHb-OEVmcd,.VIpgJd-ZVi9od-aZ2wEe-wOHMyf,.VIpgJd-ZVi9od-ORHb,.VIpgJd-ZVi9od-l4eHX-hSRGPd';
					function hide() {
						var nodes = document.querySelectorAll(sel);
						for (var i = 0; i < nodes.length; i++) {
							var el = nodes[i];
							if (el.id === 'google_translate_element2' || (el.closest && el.closest('#google_translate_element2'))) {
								continue;
							}
							el.style.setProperty('display', 'none', 'important');
							el.style.setProperty('visibility', 'hidden', 'important');
							el.style.setProperty('opacity', '0', 'important');
							el.style.setProperty('pointer-events', 'none', 'important');
						}
						if (document.body) {
							document.body.style.setProperty('top', '0px', 'important');
						}
					}
					hide();
					if (!window.__wtranslatorGtFrameWatch && typeof MutationObserver !== 'undefined' && document.documentElement) {
						window.__wtranslatorGtFrameWatch = true;
						new MutationObserver(hide).observe(document.documentElement, { childList: true, subtree: true });
					}
				})();
				
				// Load Google Translate script IMMEDIATELY (WTranslator style - very fast)
				// Load synchronously for faster translation
				if (!window.wtranslatorGtScript) {
					window.wtranslatorGtScript = document.createElement('script');
					window.wtranslatorGtScript.src = 'https://translate.google.com/translate_a/element.js?cb=wtranslatorInitEarly';
					window.wtranslatorGtScript.async = false;
					document.head.appendChild(window.wtranslatorGtScript);
				}

				// 2. Skip Translation using CSS
				<?php if ( ! empty( $settings['skipped_selectors'] ) ) : ?>
				(function() {
					var selectors = <?php echo wp_json_encode( array_map( 'trim', explode( ',', $settings['skipped_selectors'] ) ) ); ?>;
					selectors.forEach(function(selector) {
						if (!selector) return;
						try {
							var elements = document.querySelectorAll(selector);
							elements.forEach(function(el) {
								el.classList.add('notranslate');
								el.setAttribute('translate', 'no');
							});
						} catch (e) {
							console.warn('Translate SaaS: Invalid skip selector', selector);
						}
					});
				})();
				<?php endif; ?>

				// 3. Find & Replace Rules
				(function() {
					var fr_rules = <?php echo wp_json_encode( $fr_rules ); ?>;
					if (!fr_rules || fr_rules.length === 0) return;

					function applyFindReplace(type) {
						fr_rules.forEach(function(rule) {
							if (rule.type !== type || !rule.find) return;
							
							var find = rule.find;
							var replace = rule.replace || '';
							var caseSensitive = !!rule.case_sensitive;
							
							function walk(node) {
								if (node.nodeType === 3) {
									var text = node.nodeValue;
									var newText;
									if (caseSensitive) {
										newText = text.split(find).join(replace);
									} else {
										var regex = new RegExp(find.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'gi');
										newText = text.replace(regex, replace);
									}
									if (newText !== text) {
										node.nodeValue = newText;
									}
								} else if (node.nodeType === 1 && node.nodeName !== 'SCRIPT' && node.nodeName !== 'STYLE' && !node.classList.contains('notranslate')) {
									for (var i = 0; i < node.childNodes.length; i++) {
										walk(node.childNodes[i]);
									}
								}
							}
							if (document.body) walk(document.body);
						});
					}

					// Apply "original" replacements
					if (document.body) {
						applyFindReplace('original');
					} else {
						document.addEventListener('DOMContentLoaded', function() { applyFindReplace('original'); });
					}

					// MutationObserver for "translated" replacements
					var observer = new MutationObserver(function(mutations) {
						var shouldUpdate = false;
						for (var i = 0; i < mutations.length; i++) {
							if (mutations[i].type === 'characterData' || (mutations[i].addedNodes && mutations[i].addedNodes.length > 0)) {
								shouldUpdate = true;
								break;
							}
						}
						
						if (shouldUpdate) {
							observer.disconnect();
							applyFindReplace('translated');
							observer.observe(document.body, { childList: true, subtree: true, characterData: true });
						}
					});

					if (document.body) {
						observer.observe(document.body, { childList: true, subtree: true, characterData: true });
					} else {
						document.addEventListener('DOMContentLoaded', function() {
							observer.observe(document.body, { childList: true, subtree: true, characterData: true });
						});
					}
				})();
			}
			
			// Wait for body to be ready
			if (document.body) {
				initTranslation();
			} else {
				// Wait for DOMContentLoaded or body to be available
				if (document.readyState === 'loading') {
					document.addEventListener('DOMContentLoaded', initTranslation);
				} else {
					// DOM already loaded, wait a bit for body
					setTimeout(initTranslation, 10);
				}
			}
			
			// Early initialization function
			window.wtranslatorInitEarly = function() {
				try {
					// Ensure admin bar is marked as notranslate before Google Translate initializes
					var adminBar = document.getElementById('wpadminbar');
					if (adminBar) {
						adminBar.classList.add('notranslate');
						adminBar.setAttribute('translate', 'no');
						var allElements = adminBar.querySelectorAll('*');
						for (var i = 0; i < allElements.length; i++) {
							allElements[i].classList.add('notranslate');
							allElements[i].setAttribute('translate', 'no');
						}
					}
					
					new google.translate.TranslateElement({
						pageLanguage: default_lang,
						autoDisplay: false
					}, 'google_translate_element2');
					
					// Translate IMMEDIATELY - ensure translation happens
					function triggerTranslation() {
						var teCombo = document.querySelector('.goog-te-combo');
						if (teCombo && teCombo.length > 0) {
							// Normalize language code for Google Translate Element API
							// Google Translate API requires: zh-CN (not zh-cn), zh-TW (not zh-tw), etc.
							function normalizeLangCode(lang) {
								if (!lang) return lang;
								lang = lang.toLowerCase();
								if (lang.indexOf('zh') === 0) {
									if (lang.indexOf('cn') !== -1 || lang === 'zh') return 'zh-CN';
									if (lang.indexOf('tw') !== -1) return 'zh-TW';
								}
								if (lang.indexOf('-') !== -1 || lang.indexOf('_') !== -1) {
									var parts = lang.split(/[-_]/);
									if (parts.length === 2) {
										return parts[0].toLowerCase() + '-' + parts[1].toUpperCase();
									}
								}
								return lang.toLowerCase();
							}
							
							var normalized_current_lang = normalizeLangCode(current_lang);
							
							// Set language value
							teCombo.value = normalized_current_lang;
							
							// Fire change event multiple times to ensure translation triggers
							var evt = document.createEvent('HTMLEvents');
							evt.initEvent('change', true, true);
							teCombo.dispatchEvent(evt);
							teCombo.dispatchEvent(evt);
							
							// Also trigger via doWTranslate to ensure translation
							if (typeof window.wtranslatorDoTranslate === 'function') {
								setTimeout(function() {
									window.wtranslatorDoTranslate(default_lang + '|' + normalized_current_lang);
								}, 100);
							}
							
							// Verify translation started
							setTimeout(function() {
								var teComboCheck = document.querySelector('.goog-te-combo');
								if (teComboCheck && teComboCheck.value !== current_lang) {
									// Translation didn't start, retry
									teComboCheck.value = current_lang;
									var evt2 = document.createEvent('HTMLEvents');
									evt2.initEvent('change', true, true);
									teComboCheck.dispatchEvent(evt2);
								}
							}, 200);
						} else {
							// Retry if not ready
							setTimeout(triggerTranslation, 100);
						}
					}
					
					// Trigger translation after a short delay to ensure element is ready
					setTimeout(triggerTranslation, 100);
					
				} catch (e) {
					console.error('WTranslator Error:', e);
					if (typeof window.wtranslatorShowPage === 'function') {
						window.wtranslatorShowPage();
					}
				}
			};
		})();
		<?php
		$script = trim( ob_get_clean() );
		wp_register_script( 'wtranslator-translation-loader', false, array(), WTRANSLATOR_VERSION, false );
		wp_enqueue_script( 'wtranslator-translation-loader' );
		wp_add_inline_script( 'wtranslator-translation-loader', $script );
	}

	/**
	 * Start output buffering to inject hide script at the very beginning
	 */
	public function start_output_buffering() {
		// Don't apply to admin panel, login page, AJAX, REST API, or XML-RPC
		if ( ! $this->is_frontend_request() ) {
			return;
		}

		$current_lang = $this->get_current_language();
		$default_lang = $this->get_default_language();

		$settings = $this->get_settings();

		if ( $current_lang !== $default_lang ) {
			// Start output buffering to capture all HTML output
			// This handles hide script injection AND internal URL rewriting
			ob_start( array( $this, 'process_html_buffer' ) );
			$this->main_buffer_started = true;
		}
	}

	/**
	 * Ensure main output buffer is closed on shutdown if wp_footer did not run
	 */
	public function shutdown_cleanup_output_buffer() {
		if ( $this->main_buffer_started && ob_get_level() > 0 ) {
			ob_end_flush();
			$this->main_buffer_started = false;
		}
	}

	/**
	 * Ensure wp_head output buffer is closed on shutdown if end_intercept_meta_description_tags did not run.
	 */
	public function shutdown_cleanup_head_buffer() {
		if ( $this->head_buffer_started && ob_get_level() > 0 ) {
			ob_end_clean();
			$this->head_buffer_started = false;
		}
	}

	/**
	 * Check if current request is frontend (not admin panel or login page)
	 * Admin users will also see translated content on frontend
	 * Supports custom login URLs from plugins like WPS Hide Login, WPSecurity, etc.
	 */
	private function is_frontend_request() {
		// Don't apply translation logic to admin panel or login page
		if ( is_admin() ) {
			return false;
		}

		// Don't apply translation if admin bar is showing (user is logged in)
		if ( is_admin_bar_showing() ) {
			return false;
		}

		// Check if it's login page (wp-login.php)
		global $pagenow;
		if ( isset( $pagenow ) && $pagenow === 'wp-login.php' ) {
			return false;
		}

		// Check if it's login page by checking REQUEST_URI
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( $request_uri && strpos( $request_uri, 'wp-login.php' ) !== false ) {
			return false;
		}

		// Check for login-related POST parameters (works for both wp-login.php and custom login URLs)
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checking for login page, not processing form data
		if ( isset( $_POST['log'] ) || isset( $_POST['pwd'] ) || isset( $_POST['wp-submit'] ) || isset( $_POST['testcookie'] ) ) {
			return false;
		}

		// Check for login-related query parameters (action=login, action=lostpassword, etc.)
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checking for login page, not processing form data
		if ( isset( $_GET['action'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$action = sanitize_text_field( wp_unslash( $_GET['action'] ) );
			if ( in_array( $action, array( 'login', 'lostpassword', 'register', 'resetpass', 'rp', 'logout' ) ) ) {
				return false;
			}
		}

		// Check for custom login URLs from popular plugins
		$request_path = wp_parse_url( $request_uri, PHP_URL_PATH );

		// Check WPS Hide Login / WPSecurity / Admin Site Enhancements / WPManagify custom login slug
		// These plugins store custom login slug in options
		$custom_login_slug = false;

		// Check WPS Hide Login (wps-hide-login)
		if ( function_exists( 'whl_get_slug' ) ) {
			$custom_login_slug = whl_get_slug();
		} elseif ( get_option( 'whl_page' ) ) {
			$custom_login_slug = get_option( 'whl_page' );
		}

		// Check WPSecurity (aiowpsec)
		if ( ! $custom_login_slug && get_option( 'aiowps_login_page_slug' ) ) {
			$custom_login_slug = get_option( 'aiowps_login_page_slug' );
		}

		// Check Admin Site Enhancements / WPManagify
		if ( ! $custom_login_slug ) {
			// Check for WPMANAGIFY_SLUG_U option
			$wpmanagify_options = get_option( 'WPMANAGIFY_SLUG_U' );
			if ( $wpmanagify_options && isset( $wpmanagify_options['custom_login_slug'] ) && ! empty( $wpmanagify_options['custom_login_slug'] ) ) {
				$custom_login_slug = $wpmanagify_options['custom_login_slug'];
			}
		}

		// Check Admin Site Enhancements (asenha)
		if ( ! $custom_login_slug ) {
			$asenha_options = get_option( 'admin_site_enhancements' );
			if ( $asenha_options && isset( $asenha_options['custom_login_slug'] ) && ! empty( $asenha_options['custom_login_slug'] ) ) {
				$custom_login_slug = $asenha_options['custom_login_slug'];
			}
		}

		// If custom login slug found, check if current URL matches it
		if ( $custom_login_slug && $request_path ) {
			// Remove leading/trailing slashes for comparison
			$custom_login_slug = trim( $custom_login_slug, '/' );
			$request_path      = trim( $request_path, '/' );

			// Check if request path starts with custom login slug
			if ( $request_path === $custom_login_slug || strpos( $request_path, $custom_login_slug . '/' ) === 0 ) {
				return false;
			}
		}

		// Check if it's admin-ajax or admin-post (AJAX requests)
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}

		// Check if it's REST API request
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		// Check if it's XML-RPC request
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return false;
		}

		// Otherwise, it's a frontend request
		return true;
	}

	/**
 * Process HTML buffer (injection and URL rewriting)
 */
	public function process_html_buffer( $buffer ) {
		// Don't apply to admin panel, login page, AJAX, REST API, or XML-RPC
		if ( ! $this->is_frontend_request() || empty( $buffer ) ) {
			return $buffer;
		}

		// Detect if content is XML (Sitemap, RSS, etc.)
		// Also check for HTML sitemap pages (Rank Math shows HTML sitemap)
		$is_xml = ( stripos( $buffer, '<?xml' ) !== false || stripos( $buffer, '<urlset' ) !== false || stripos( $buffer, '<sitemapindex' ) !== false || stripos( $buffer, '<rss' ) !== false );

		$request_uri     = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$is_sitemap_page = ( stripos( $request_uri, 'sitemap' ) !== false ) ||
					   ( stripos( $buffer, 'Sitemap' ) !== false && stripos( $buffer, 'sitemap' ) !== false );

		// For sitemap/XML requests, extract language directly from address bar URL
		// Don't rely on plugin settings or cache - use actual URL
		$url_lang_from_address_bar = null;
		if ( $is_xml || $is_sitemap_page ) {
			if ( $request_uri ) {
				$path = wp_parse_url( $request_uri, PHP_URL_PATH );
				// Extract language code from path (e.g., /ar/sitemap_index.xml or /bn/post-sitemap.xml)
				// Simple pattern: match /lang/ followed by anything ending with .xml or containing sitemap
				// Pattern: /ar/sitemap_index.xml or /ar/post-sitemap.xml or /bn/category-sitemap.xml
				if ( preg_match( '#^/([a-z]{2}(?:-[a-z]{2,3})?)/#i', $path, $matches ) ) {
					// Check if path contains sitemap or ends with .xml
					if ( stripos( $path, 'sitemap' ) !== false || substr( $path, -4 ) === '.xml' ) {
						$url_lang_from_address_bar = $matches[1];
						// Normalize language code
						$lang_parts = explode( '-', $url_lang_from_address_bar );
						if ( count( $lang_parts ) === 2 ) {
							$url_lang_from_address_bar = strtolower( $lang_parts[0] ) . '-' . strtoupper( $lang_parts[1] );
						} else {
							$url_lang_from_address_bar = strtolower( $url_lang_from_address_bar );
						}
					}
				}
			}
		}

		$current_lang = $url_lang_from_address_bar ? $url_lang_from_address_bar : $this->get_current_language();
		$default_lang = $this->get_default_language();

		// Check if page is skipped
		if ( $this->is_page_skipped() ) {
			return '<!-- Translate SaaS: Buffer processing skipped -->' . $buffer;
		}

		// Keep <title> and SEO description in the original language (Pro-only SEO translation).
		if ( ! $is_xml && ! $is_sitemap_page ) {
			$buffer = $this->mark_seo_tags_notranslate( $buffer );
		}

		// Skip admin bar content from translation - add notranslate class to admin bar
		// This prevents Google Translate from translating admin bar while allowing page content to translate
		if ( preg_match( '/<div[^>]*id=["\']wpadminbar["\'][^>]*>/i', $buffer ) ) {
			// Add notranslate class to admin bar div (handle both with and without existing class attribute)
			if ( preg_match( '/<div[^>]*id=["\']wpadminbar["\'][^>]*class=["\'][^"\']*["\'][^>]*>/i', $buffer ) ) {
				// Admin bar already has class attribute - add notranslate to existing classes
				$buffer = preg_replace( '/(<div[^>]*id=["\']wpadminbar["\'][^>]*class=["\'])([^"\']*)(["\'][^>]*>)/i', '$1$2 notranslate$3', $buffer );
			} else {
				// Admin bar doesn't have class attribute - add it
				$buffer = preg_replace( '/(<div[^>]*id=["\']wpadminbar["\'][^>]*)(>)/i', '$1 class="notranslate"$2', $buffer );
			}
		}

		// 0. Apply "Replace in Original HTML" rules
		$buffer = $this->apply_find_replace( $buffer, 'original' );

		// Note: hide script for non-default languages is enqueued via output_early_hide_script()
		// (registered with wp_add_inline_script), so no direct injection needed here.

		// 2. Rewrite internal URLs in the buffer (WTranslator style)
		// For sitemap/XML requests, rewrite URLs ONLY if address bar URL has language code
		// If address bar has no language code, don't rewrite URLs
		$is_sitemap_request = $is_xml || $is_sitemap_page;

		if ( $is_sitemap_request ) {
			// Only rewrite URLs if address bar URL actually has language code
			if ( $url_lang_from_address_bar ) {
				// Address bar has language code - rewrite URLs to match
				// Pass the language code from address bar explicitly
				$buffer = $this->rewrite_html_buffer_urls( $buffer, $is_sitemap_request, $is_sitemap_page, $url_lang_from_address_bar );
			} else {
				// Address bar has NO language code - don't rewrite URLs
				// URLs should remain without language code
				// Do nothing - return buffer as is
			}
		}

		// 4. Skip Translation of Phrases
		if ( ! empty( $settings['skipped_phrases'] ) && is_array( $settings['skipped_phrases'] ) && ! $is_xml ) {
			foreach ( $settings['skipped_phrases'] as $phrase ) {
				$phrase = trim( $phrase );
				if ( $phrase === '' ) {
					continue;
				}

				// Escape phrase for regex
				$quoted_phrase = preg_quote( $phrase, '/' );

				// Regex to match tags, scripts, styles OR the phrase
				// m[1]: Matches Tags, Scripts, or Styles (return as is)
				// m[2]: Matches the phrase (wrap in notranslate)
				// Using \b for word boundaries if the phrase starts/ends with a word character
				$boundary = '';
				if ( preg_match( '/^\w/', $phrase ) && preg_match( '/\w$/', $phrase ) ) {
					// $boundary = '\b'; // Word boundary can be tricky with different languages, skipping for now
				}

				$buffer = preg_replace_callback(
					'/(<script[^>]*>.*?<\/script>|<style[^>]*>.*?<\/style>|<[^>]*>)|(' . $boundary . $quoted_phrase . $boundary . ')/is',
					function ( $matches ) {
						if ( ! empty( $matches[1] ) ) {
							return $matches[1];
						}
						return '<span class="notranslate">' . $matches[2] . '</span>';
					},
					$buffer
				);
			}
		}

		// 5. Apply "Replace in Translated HTML" rules (Server-side portion)
		$buffer = $this->apply_find_replace( $buffer, 'translated' );

		// 3. Allow other modules to filter the buffer
		return apply_filters( 'wtranslator_html_buffer', $buffer );
	}

	/**
	 * Mark <title> and SEO description tags so Google Translate Element skips them.
	 */
	private function mark_seo_tags_notranslate( $html ) {
		if ( empty( $html ) ) {
			return $html;
		}

		$html = preg_replace_callback(
			'/<title(\s[^>]*)?>/i',
			function ( $matches ) {
				return $this->add_notranslate_attributes_to_tag( '<title' . ( isset( $matches[1] ) ? $matches[1] : '' ) . '>' );
			},
			$html,
			1
		);

		$html = preg_replace_callback(
			'/<meta\b[^>]*>/i',
			function ( $matches ) {
				$tag = $matches[0];
				if ( ! preg_match( '/\s(?:name|property)\s*=\s*["\'](?:description|og:title|og:description|twitter:title|twitter:description)["\']/i', $tag ) ) {
					return $tag;
				}
				return $this->add_notranslate_attributes_to_tag( $tag );
			},
			$html
		);

		return $html;
	}

	/**
	 * Add class="notranslate" and translate="no" to an HTML start tag.
	 */
	private function add_notranslate_attributes_to_tag( $tag ) {
		if ( stripos( $tag, 'notranslate' ) === false ) {
			if ( preg_match( '/\sclass=(["\'])([^"\']*)\1/i', $tag ) ) {
				$tag = preg_replace( '/\sclass=(["\'])([^"\']*)\1/i', ' class=$1$2 notranslate$1', $tag, 1 );
			} else {
				$tag = preg_replace( '/\s*\/?>$/', ' class="notranslate"$0', $tag, 1 );
			}
		}
		if ( stripos( $tag, 'translate=' ) === false ) {
			$tag = preg_replace( '/\s*\/?>$/', ' translate="no"$0', $tag, 1 );
		}
		return $tag;
	}

	/**
	 * Rewrite a single URL for output (never adds ?lang=).
	 */
	private function rewrite_url_with_lang( $url, $lang_code, $is_sitemap = false ) {
		if ( empty( $url ) || empty( $lang_code ) ) {
			return $url;
		}
		$parts = wp_parse_url( $url );
		if ( ! isset( $parts['path'] ) && ! isset( $parts['host'] ) ) {
			return $url;
		}
		$query = array();
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
		}
		// Never add a language query parameter. Keep URLs clean for SEO.
		unset( $query['lang'] );
		$new_query = http_build_query( $query );
		$built     = ( isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '' ) . ( isset( $parts['host'] ) ? $parts['host'] : '' ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . ( isset( $parts['path'] ) ? $parts['path'] : '/' ) . ( $new_query !== '' ? '?' . $new_query : '' ) . ( isset( $parts['fragment'] ) && $parts['fragment'] !== '' ? '#' . $parts['fragment'] : '' );
		return $built;
	}

	/**
	 * Rewrite all internal URLs in an HTML buffer (comprehensive replacement)
	 * Supports both HTML attributes and XML <loc> tags for sitemaps
	 */
	public function rewrite_html_buffer_urls( $buffer, $is_xml = false, $is_sitemap_page = false, $force_lang = null ) {
		// Skip if admin bar is showing (user is logged in) - don't rewrite admin bar URLs
		if ( is_admin_bar_showing() && ! $is_xml ) {
			return $buffer;
		}
		// Use forced language (from address bar) if provided, otherwise get from settings
		$current_lang = $force_lang ? $force_lang : $this->get_current_language();
		$default_lang = $this->get_default_language();

		// For sitemap requests with forced language (from address bar), always rewrite
		// For XML sitemaps, always rewrite URLs even if current language is default
		// For HTML, skip if current language is default (unless forced)
		if ( ! $is_xml && ! $is_sitemap_page && $current_lang === $default_lang && ! $force_lang ) {
			return $buffer;
		}

		// If forced language is provided (from address bar), always rewrite
		if ( $force_lang ) {
			// Always rewrite when language comes from address bar
		}

		$home_url   = home_url();
		$home_parts = wp_parse_url( $home_url );
		$domain     = $home_parts['host'];

		// Regex to find href="..." or action="..." attributes
		// Covers: a, form, area, link
		$pattern = '/(href|action)=["\']((?:https?:\/\/' . $domain . '|(?!\w+:))[^"\']+)["\']/i';

		$buffer = preg_replace_callback(
			$pattern,
			function ( $matches ) use ( $current_lang, $is_xml ) {
				$attr = $matches[1];
				$url  = $matches[2];

				// Skip admin bar URLs - don't rewrite admin URLs
				if ( strpos( $url, '/wp-admin/' ) !== false || strpos( $url, 'admin-ajax.php' ) !== false || strpos( $url, 'admin-post.php' ) !== false ) {
					return $matches[0];
				}

				// Skip static files (optimization)
				if ( preg_match( '/\\.(css|js|jpg|jpeg|png|gif|svg|webp|ico|woff2?|ttf|otf|mp4|webm|wav|mp3|ogg|pdf|zip|gz|tar|json|txt)(\\?|$)/i', $url ) ) {
					return $matches[0];
				}

				// For non-XML, skip .xml files too (RSS feeds, etc. usually don't need rewriting in HTML)
				// BUT allow sitemap.xml files to be rewritten
				if ( ! $is_xml && preg_match( '/\\.xml(\\?|$)/i', $url ) && stripos( $url, 'sitemap' ) === false ) {
					return $matches[0];
				}

				$new_url = $this->rewrite_url_with_lang( $url, $current_lang, $is_xml );
				return $attr . '="' . $new_url . '"';
			},
			$buffer
		);

		// If it's XML or sitemap page, also rewrite <loc> tags and href links (Sitemaps)
		// BUT only if we have a language code (from address bar or forced)
		$is_sitemap_request = $is_xml || $is_sitemap_page;
		// Use forced language if provided (from address bar), otherwise use current_lang
		$lang_to_use = $force_lang ? $force_lang : $current_lang;
		if ( $is_sitemap_request && $lang_to_use ) {
			// For XML sitemaps, rewrite URLs with language code ONLY if we have language code
			// This ensures sitemap URLs match the address bar URL
			$loc_pattern = '/<loc>\s*((?:https?:\/\/' . $domain . '|(?!\\w+:))[^<]+)\s*<\/loc>/i';
			$buffer      = preg_replace_callback(
				$loc_pattern,
				function ( $matches ) use ( $lang_to_use ) {
					$url = trim( $matches[1] );

					// Skip if URL already has language code
					if ( preg_match( '#\/[a-z]{2}(?:-[a-z]{2,3})?\/#i', $url ) ) {
						return '<loc>' . $url . '</loc>';
					}

					// Rewrite URL with language code from address bar
					$new_url = $this->rewrite_url_with_lang( $url, $lang_to_use, true );
					return '<loc>' . $new_url . '</loc>';
				},
				$buffer
			);

			// Also rewrite <sitemap><loc> tags in sitemap index
			$sitemap_loc_pattern = '/<sitemap>\s*<loc>\s*((?:https?:\/\/' . $domain . '|(?!\\w+:))[^<]+)\s*<\/loc>/i';
			$buffer              = preg_replace_callback(
				$sitemap_loc_pattern,
				function ( $matches ) use ( $lang_to_use ) {
					$url = trim( $matches[1] );

					// Skip if URL already has language code
					if ( preg_match( '#\/[a-z]{2}(?:-[a-z]{2,3})?\/#i', $url ) ) {
						return '<sitemap><loc>' . $url . '</loc>';
					}

					$new_url = $this->rewrite_url_with_lang( $url, $lang_to_use, true );
					return '<sitemap><loc>' . $new_url . '</loc>';
				},
				$buffer
			);

			// For HTML sitemap pages (Rank Math), rewrite all sitemap URLs
			if ( $is_sitemap_page && ! $is_xml ) {
				// Match any sitemap XML URLs in the buffer (comprehensive pattern)
				// This catches URLs in href, plain text, table cells, etc.
				// Escape domain for regex
				$domain_escaped = preg_quote( $domain, '/' );

				// Pattern 1: Full URLs with http:// or https://
				$sitemap_url_pattern = '/(https?:\/\/' . $domain_escaped . '[^\s<>"\']*sitemap[^\s<>"\']*\.xml)/i';
				$buffer              = preg_replace_callback(
					$sitemap_url_pattern,
					function ( $matches ) use ( $lang_to_use ) {
						$url = trim( $matches[1] );

						// Skip if URL already has language code
						if ( preg_match( '#\/[a-z]{2}(?:-[a-z]{2,3})?\/.*sitemap#i', $url ) ) {
							return $url;
						}

						// Only rewrite if lang_to_use is set (from address bar)
						if ( $lang_to_use ) {
							$new_url = $this->rewrite_url_with_lang( $url, $lang_to_use, true );
							return $new_url;
						}

						// If no language code in address bar, return URL as is
						return $url;
					},
					$buffer
				);

				// Pattern 2: URLs without http:// (just domain and path) - for plain text in HTML
				$sitemap_url_pattern2 = '/([^"\'<>\/])(' . $domain_escaped . '[^\s<>"\']*sitemap[^\s<>"\']*\.xml)/i';
				$buffer               = preg_replace_callback(
					$sitemap_url_pattern2,
					function ( $matches ) use ( $lang_to_use, $domain ) {
							$before   = $matches[1];
							$url_part = $matches[2];
							$full_url = 'http://' . $url_part;

							// Skip if URL already has language code
						if ( preg_match( '#\/[a-z]{2}(?:-[a-z]{2,3})?\/.*sitemap#i', $full_url ) ) {
							return $matches[0];
						}

							// Only rewrite if lang_to_use is set (from address bar)
						if ( $lang_to_use ) {
							$new_url = $this->rewrite_url_with_lang( $full_url, $lang_to_use, true );
							// Remove http:// prefix if original didn't have it
							$new_url = str_replace( 'http://', '', $new_url );
							return $before . $new_url;
						}

							// If no language code in address bar, return URL as is
							return $matches[0];
					},
					$buffer
				);
			}
		}

		return $buffer;
	}

	/**
	 * End output buffering
	 */
	public function end_output_buffering() {
		// Don't apply to admin panel, login page, AJAX, REST API, or XML-RPC
		if ( ! $this->is_frontend_request() ) {
			return;
		}

		// Only flush if we started buffering
		$current_lang = $this->get_current_language();
		$default_lang = $this->get_default_language();

		if ( $current_lang !== $default_lang && ob_get_level() > 0 ) {
			ob_end_flush();
			$this->main_buffer_started = false;
		}
	}

	/**
	 * Output early hide script (before any HTML output - prevents flash completely)
	 * Hide page IMMEDIATELY before Google Translate script loads - feels like server translation
	 */
	public function output_early_hide_script() {
		// Don't apply to admin panel, login page, AJAX, REST API, or XML-RPC
		if ( ! $this->is_frontend_request() ) {
			return;
		}

		$current_lang = $this->get_current_language();
		$default_lang = $this->get_default_language();

		if ( $current_lang !== $default_lang ) {
			$script = "(function(){if(document.documentElement){document.documentElement.style.visibility='hidden';document.documentElement.style.opacity='0';}if(document.body){document.body.style.visibility='hidden';document.body.style.opacity='0';}})();";
			wp_register_script( 'wtranslator-early-hide', false, array(), WTRANSLATOR_VERSION, false );
			wp_enqueue_script( 'wtranslator-early-hide' );
			wp_add_inline_script( 'wtranslator-early-hide', $script );
		}
	}

	/**
	 * Output prevent flash CSS (highest priority - first in head)
	 * Hide page IMMEDIATELY before Google Translate script loads - feels like server translation
	 */
	public function output_prevent_flash_css() {
		if ( is_admin() ) {
			return;
		}
		$current_lang = $this->get_current_language();
		$default_lang = $this->get_default_language();
		if ( $current_lang === $default_lang ) {
			return;
		}
		$css = 'html { visibility: hidden !important; opacity: 0 !important; } html body { visibility: hidden !important; opacity: 0 !important; }';
		wp_register_style( 'wtranslator-prevent-flash', false, array(), WTRANSLATOR_VERSION );
		wp_enqueue_style( 'wtranslator-prevent-flash' );
		wp_add_inline_style( 'wtranslator-prevent-flash', $css );
	}

	/**
	 * Output script to show page immediately for default language
	 * Prevents white page when switching to default language
	 */
	public function output_default_language_show() {
		if ( is_admin() ) {
			return;
		}

		$current_lang = $this->get_current_language();
		$default_lang = $this->get_default_language();

		if ( $current_lang === $default_lang ) {
			$script = "(function(){var hideStyleEl=document.getElementById('wtranslator-prevent-flash');if(hideStyleEl)hideStyleEl.remove();if(document.documentElement){document.documentElement.style.visibility='visible';document.documentElement.style.opacity='1';}if(document.body){document.body.style.visibility='visible';document.body.style.opacity='1';}var showStyle=document.createElement('style');showStyle.id='wtranslator-show-page';showStyle.textContent='html, html body { visibility: visible !important; opacity: 1 !important; }';document.head.appendChild(showStyle);})();";
			wp_register_script( 'wtranslator-default-lang-show', false, array(), WTRANSLATOR_VERSION, false );
			wp_enqueue_script( 'wtranslator-default-lang-show' );
			wp_add_inline_script( 'wtranslator-default-lang-show', $script );
		}
	}

	/**
	 * Get widget code - WTranslator style
	 */
	public function get_widget_code( $atts = array() ) {
		$unique_id = wp_rand( 10000000, 88888888 );

		$settings = $this->get_settings();

		// Override settings from attributes
		if ( isset( $atts['widget_look'] ) ) {
			$settings['widget_look'] = $atts['widget_look'];
		}

		if ( isset( $atts['wrapper_selector'] ) ) {
			$settings['wrapper_selector'] = $atts['wrapper_selector'];
		}

		// Determine position
		if ( isset( $atts['position'] ) ) {
			$position = $atts['position'];
		} elseif ( ! empty( $settings['floating_language_selector'] ) && $settings['floating_language_selector'] !== 'no' ) {
				$position = $settings['floating_language_selector'];
		} else {
			$position = 'inline';
		}

		// Parse position
		if ( $position === 'inline' ) {
			$horizontal_position = 'inline';
			$vertical_position   = '';
		} else {
			$parts = explode( '_', $position );
			if ( count( $parts ) === 2 ) {
				$vertical_position   = $parts[0]; // top or bottom
				$horizontal_position = $parts[1]; // left or right
			} else {
				$horizontal_position = 'inline';
				$vertical_position   = '';
			}
		}

		// Generate widget wrapper
		$widget_code      = '';
		$wrapper_selector = ! empty( $settings['wrapper_selector'] ) ? $settings['wrapper_selector'] : '.wtranslator-wrapper';

		if ( $wrapper_selector === '.wtranslator-wrapper' || empty( trim( $wrapper_selector ) ) ) {
			$wrapper_selector = '#wtranslator-wrapper-' . $unique_id;
			$widget_code     .= '<div class="wtranslator-wrapper" id="wtranslator-wrapper-' . $unique_id . '"></div>';
		}

		// Render the switcher
		if ( ! class_exists( 'WTranslator_Language_Manager' ) ) {
			require_once WTRANSLATOR_PLUGIN_DIR . 'includes/class-language-manager.php';
		}

		$language_manager = new WTranslator_Language_Manager();

		// Update settings for rendering
		$render_settings = $settings;
		// Convert position to hyphen format for CSS class
		$render_settings['switcher_position'] = str_replace( '_', '-', $position );
		$render_settings['wrapper_selector']  = $wrapper_selector;
		$render_settings['unique_id']         = $unique_id;

		// Get switcher HTML
		ob_start();
		$language_manager->render_switcher( $render_settings );
		$switcher_html = ob_get_clean();

		// If inline, return just the switcher, otherwise wrap it
		if ( $position === 'inline' ) {
			return $widget_code . $switcher_html;
		} else {
			return $widget_code . $switcher_html;
		}
	}

	/**
	 * Render floating widget - WTranslator style
	 */
	public function render_floating_widget() {
		echo $this->get_widget_code(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Render wrapper widget - WTranslator style
	 */
	public function render_wrapper_widget() {
		echo $this->get_widget_code( array( 'position' => 'inline' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Add language switcher to menu
	 */
	public function add_language_switcher_to_menu( $items, $args ) {
		// Only run on frontend
		if ( is_admin() ) {
			return $items;
		}

		try {
			$settings     = $this->get_settings();
			$show_in_menu = ! empty( $settings['show_in_menu'] ) ? $settings['show_in_menu'] : '';

			// If no menu selected, return
			if ( empty( $show_in_menu ) ) {
				return $items;
			}

			// Check if this is the selected menu location
			$menu_location = '';

			// Method 1: Direct theme_location (most reliable)
			if ( isset( $args->theme_location ) && ! empty( $args->theme_location ) ) {
				$menu_location = $args->theme_location;
			}

			// Method 2: Get location from menu ID/object
			if ( empty( $menu_location ) && isset( $args->menu ) ) {
				$menu_id = is_object( $args->menu ) ? ( isset( $args->menu->term_id ) ? $args->menu->term_id : 0 ) : $args->menu;
				if ( $menu_id ) {
					$locations = get_nav_menu_locations();
					if ( is_array( $locations ) ) {
						foreach ( $locations as $location => $loc_menu_id ) {
							if ( $loc_menu_id == $menu_id ) {
								$menu_location = $location;
								break;
							}
						}
					}
				}
			}

			// Method 3: Check all registered menu locations and match by menu ID
			// This is important for footer menu which might not have theme_location set
			if ( empty( $menu_location ) && isset( $args->menu ) ) {
				$menu_id = is_object( $args->menu ) ? ( isset( $args->menu->term_id ) ? $args->menu->term_id : 0 ) : $args->menu;
				if ( $menu_id ) {
					$all_locations = get_nav_menu_locations();
					foreach ( $all_locations as $loc_name => $loc_menu_id ) {
						if ( $loc_menu_id == $menu_id ) {
							$menu_location = $loc_name;
							break;
						}
					}
				}
			}

			// WTranslator style: Direct comparison first (exact match)
			$location_matches = false;

			// Method 1: Direct theme_location comparison (WTranslator style)
			if ( isset( $args->theme_location ) && ! empty( $args->theme_location ) ) {
				if ( $args->theme_location == $show_in_menu ) {
					$location_matches = true;
				}
			}

			// Method 2: Menu location comparison
			if ( ! $location_matches && ! empty( $menu_location ) ) {
				if ( $menu_location == $show_in_menu ) {
					$location_matches = true;
				}
			}

			// Method 3: Normalized comparison (case-insensitive, handle spaces/underscores/hyphens)
			if ( ! $location_matches ) {
				$normalized_location     = strtolower( preg_replace( '/[^a-z0-9]/', '_', $menu_location ) );
				$normalized_show_in_menu = strtolower( preg_replace( '/[^a-z0-9]/', '_', $show_in_menu ) );

				if ( $normalized_location == $normalized_show_in_menu && ! empty( $normalized_location ) ) {
					$location_matches = true;
				}
			}

			// Method 4: Check if menu location contains the setting value (for partial matches)
			if ( ! $location_matches ) {
				$normalized_location     = strtolower( preg_replace( '/[^a-z0-9]/', '_', $menu_location ) );
				$normalized_show_in_menu = strtolower( preg_replace( '/[^a-z0-9]/', '_', $show_in_menu ) );

			}

			if ( ! $location_matches ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				}
				return $items;
			}

			// Location matches - proceed to add widget
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			}

			if ( ! class_exists( 'WTranslator_Language_Manager' ) ) {
				require_once WTRANSLATOR_PLUGIN_DIR . 'includes/class-language-manager.php';
			}

			$language_manager  = new WTranslator_Language_Manager();
			$enabled_languages = $language_manager->get_enabled_languages();
			// Use plugin instance method instead of language manager
			$current_lang = $this->get_current_language();

			if ( empty( $enabled_languages ) || count( $enabled_languages ) < 2 ) {
				return $items;
			}
		} catch ( Exception $e ) {
			// If any error occurs, just return items without breaking
			return $items;
		}

		// WTranslator style menu integration
		try {
			$widget_look = ! empty( $settings['widget_look'] ) ? $settings['widget_look'] : 'dropdown';

			if ( $widget_look === 'dropdown' || $widget_look === 'float' ) {
				$current_lang      = $language_manager->resolve_switcher_language( $enabled_languages, $current_lang );
				$current_lang_info = $enabled_languages[ $current_lang ];
				if ( ! $current_lang_info || ! is_array( $current_lang_info ) ) {
					return $items;
				}

				$native_language_names = ! empty( $settings['native_language_names'] );
				$open_direction        = ! empty( $settings['open_direction'] ) ? $settings['open_direction'] : 'down';
				$items                .= '<li class="menu-item menu-item-wtranslator wtranslator-menu-item notranslate">';
				$items                .= '<span class="wtranslator-menu-switcher notranslate">';
				$items                .= '<span class="wtranslator-menu-dropdown notranslate ' . esc_attr( $open_direction === 'down' ? 'open-down' : 'open-up' ) . '">';
				$items                .= '<span class="wtranslator-menu-toggle notranslate">';
				$items                .= '<span class="wtranslator-flag">' . esc_html( $current_lang_info['flag'] ) . '</span>';
				$items                .= '<span class="wtranslator-lang-name notranslate">' . esc_html( $native_language_names ? $current_lang_info['native'] : $current_lang_info['name'] ) . '</span>';
				$items                .= '<span class="wtranslator-arrow">▼</span>';
				$items                .= '</span>';
				$items                .= '<ul class="wtranslator-menu-dropdown-list notranslate">';
				foreach ( $enabled_languages as $code => $lang ) {
					$items .= '<li class="notranslate">';
					$items .= '<a href="' . esc_url( $language_manager->get_language_url( $code ) ) . '" ';
					$items .= 'class="wtranslator-lang-item notranslate ' . ( $code === $current_lang ? 'current' : '' ) . '" ';
					$items .= 'data-lang="' . esc_attr( $code ) . '">';
					$items .= '<span class="wtranslator-flag">' . esc_html( $lang['flag'] ) . '</span>';
					$items .= '<span class="wtranslator-lang-name notranslate">' . esc_html( $native_language_names ? $lang['native'] : $lang['name'] ) . '</span>';
					$items .= '</a>';
					$items .= '</li>';
				}
				$items .= '</ul>';
				$items .= '</span>';
				$items .= '</span>';
				$items .= '</li>';
			} else {
				// For flags, use direct menu item approach
				$native_language_names = ! empty( $settings['native_language_names'] );
				$items                .= '<li class="menu-item menu-item-wtranslator wtranslator-menu-item notranslate">';
				$items                .= '<span class="wtranslator-menu-switcher notranslate">';
				foreach ( $enabled_languages as $code => $lang ) {
					$is_active = $code === $current_lang;
					$items    .= '<a href="' . esc_url( $language_manager->get_language_url( $code ) ) . '" ';
					$items    .= 'class="wtranslator-flag-item wtranslator-menu-flag notranslate ' . ( $is_active ? 'active' : '' ) . '" ';
					$items    .= 'data-lang="' . esc_attr( $code ) . '" ';
					$items    .= 'title="' . esc_attr( $native_language_names ? $lang['native'] : $lang['name'] ) . '">';
					$items    .= esc_html( $lang['flag'] );
					$items    .= '</a>';
				}
				$items .= '</span>';
				$items .= '</li>';
			}

			return $items;
		} catch ( Exception $e ) {
			return $items;
		}
	}

	/**
	 * Add language switcher to menu using walker filter (alternative approach)
	 */
	public function add_language_switcher_to_menu_walker( $item_output, $item, $depth, $args ) {
		// Only run on frontend
		if ( is_admin() ) {
			return $item_output;
		}

		// Only process on last menu item to append switcher
		// We'll use a different approach - check if this is the last item
		static $menu_processed = array();

		try {
			$settings     = $this->get_settings();
			$show_in_menu = ! empty( $settings['show_in_menu'] ) ? $settings['show_in_menu'] : '';

			if ( empty( $show_in_menu ) ) {
				return $item_output;
			}

			// Get menu location (same resolution as add_language_switcher_to_menu for footer/secondary when theme_location is unset)
			$menu_location = '';
			if ( isset( $args->theme_location ) && ! empty( $args->theme_location ) ) {
				$menu_location = $args->theme_location;
			}
			if ( empty( $menu_location ) && isset( $args->menu ) ) {
				$menu_id = is_object( $args->menu ) ? ( isset( $args->menu->term_id ) ? $args->menu->term_id : 0 ) : $args->menu;
				if ( $menu_id ) {
					$locations = get_nav_menu_locations();
					if ( is_array( $locations ) ) {
						foreach ( $locations as $location => $loc_menu_id ) {
							if ( (int) $loc_menu_id === (int) $menu_id ) {
								$menu_location = $location;
								break;
							}
						}
					}
				}
			}

			$location_matches = false;
			if ( isset( $args->theme_location ) && ! empty( $args->theme_location ) && $args->theme_location === $show_in_menu ) {
				$location_matches = true;
			}
			if ( ! $location_matches && ! empty( $menu_location ) && $menu_location === $show_in_menu ) {
				$location_matches = true;
			}
			$normalized_location     = strtolower( preg_replace( '/[^a-z0-9]/', '_', $menu_location ) );
			$normalized_show_in_menu = strtolower( preg_replace( '/[^a-z0-9]/', '_', $show_in_menu ) );
			if ( ! $location_matches && ! empty( $normalized_location ) && $normalized_location === $normalized_show_in_menu ) {
				$location_matches = true;
			}
			if ( ! $location_matches ) {
				return $item_output;
			}

			// Only add once per menu
			$menu_key = $menu_location . '_' . ( isset( $args->menu->term_id ) ? $args->menu->term_id : 'default' );
			if ( isset( $menu_processed[ $menu_key ] ) ) {
				return $item_output;
			}

			// Mark as processed
			$menu_processed[ $menu_key ] = true;

			// Get language data
			if ( ! class_exists( 'WTranslator_Language_Manager' ) ) {
				require_once WTRANSLATOR_PLUGIN_DIR . 'includes/class-language-manager.php';
			}

			$language_manager  = new WTranslator_Language_Manager();
			$enabled_languages = $language_manager->get_enabled_languages();
			$current_lang      = $this->get_current_language();

			if ( empty( $enabled_languages ) || count( $enabled_languages ) < 2 ) {
				return $item_output;
			}

			// Append switcher after the last item
			$widget_look           = ! empty( $settings['widget_look'] ) ? $settings['widget_look'] : 'dropdown';
			$native_language_names = ! empty( $settings['native_language_names'] );

			$switcher_html = '';
			if ( $widget_look === 'dropdown' || $widget_look === 'float' ) {
				$current_lang      = $language_manager->resolve_switcher_language( $enabled_languages, $current_lang );
				$current_lang_info = $enabled_languages[ $current_lang ];
				if ( $current_lang_info && is_array( $current_lang_info ) ) {
					$switcher_html  = '<li class="menu-item menu-item-wtranslator wtranslator-menu-item notranslate">';
					$switcher_html .= '<span class="wtranslator-menu-switcher notranslate">';
					$switcher_html .= '<span class="wtranslator-menu-dropdown notranslate">';
					$switcher_html .= '<span class="wtranslator-menu-toggle notranslate">';
					$switcher_html .= '<span class="wtranslator-flag">' . esc_html( $current_lang_info['flag'] ) . '</span>';
					$switcher_html .= '<span class="wtranslator-lang-name notranslate">' . esc_html( $native_language_names ? $current_lang_info['native'] : $current_lang_info['name'] ) . '</span>';
					$switcher_html .= '<span class="wtranslator-arrow">▼</span>';
					$switcher_html .= '</span>';
					$switcher_html .= '<ul class="wtranslator-menu-dropdown-list notranslate">';
					foreach ( $enabled_languages as $code => $lang ) {
						$switcher_html .= '<li class="notranslate"><a href="' . esc_url( $language_manager->get_language_url( $code ) ) . '" class="notranslate ' . ( $code === $current_lang ? 'current' : '' ) . '" data-lang="' . esc_attr( $code ) . '">';
						$switcher_html .= '<span class="wtranslator-flag">' . esc_html( $lang['flag'] ) . '</span>';
						$switcher_html .= '<span class="wtranslator-lang-name notranslate">' . esc_html( $native_language_names ? $lang['native'] : $lang['name'] ) . '</span>';
						$switcher_html .= '</a></li>';
					}
					$switcher_html .= '</ul></span></span></li>';
				}
			} else {
				$switcher_html  = '<li class="menu-item menu-item-wtranslator wtranslator-menu-item notranslate">';
				$switcher_html .= '<span class="wtranslator-menu-switcher notranslate">';
				foreach ( $enabled_languages as $code => $lang ) {
					$is_active      = $code === $current_lang;
					$switcher_html .= '<a href="' . esc_url( $language_manager->get_language_url( $code ) ) . '" class="wtranslator-menu-flag notranslate ' . ( $is_active ? 'active' : '' ) . '" data-lang="' . esc_attr( $code ) . '" title="' . esc_attr( $native_language_names ? $lang['native'] : $lang['name'] ) . '">' . esc_html( $lang['flag'] ) . '</a>';
				}
				$switcher_html .= '</span></li>';
			}

			// Append to item output (this won't work well, so we'll use wp_nav_menu_items instead)
			return $item_output;
		} catch ( Exception $e ) {
			return $item_output;
		}
	}

	/**
	 * Enable server-side translation filters
	 * DISABLED - Using client-side Google Translate Element API instead
	 * This function is kept for backward compatibility but not used
	 */
	private function enable_server_side_translation() {
		// DISABLED - Using client-side Google Translate Element API
		// Server-side translation filters are not enabled
		// Google Translate Element API handles all translation automatically
		return;
	}

	/**
	 * Translate content (Server-side translation - happens before page load)
	 */
	public function translate_content( $content ) {
		$current_lang = $this->get_current_language();
		$default_lang = $this->get_default_language();

		// Skip if same language or empty content
		if ( $current_lang === $default_lang || empty( $content ) ) {
			return $content;
		}

		// Skip very short content (performance optimization)
		$trimmed_content = trim( wp_strip_all_tags( $content ) );
		if ( strlen( $trimmed_content ) < 3 ) {
			return $content;
		}

		// Use cache for faster page load
		$cache_manager = new WTranslator_Cache_Manager();
		$cache_key     = md5( $content . $current_lang . $default_lang );

		// Check cache first (instant return)
		if ( $cache_manager->has_cache( $cache_key ) ) {
			return $cache_manager->get_cache( $cache_key );
		}

		// Translate using Google Translate API (server-side)
		$google_translate = new WTranslator_Google_Translate();
		$http_host        = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$request_uri      = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$current_url      = ( isset( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http' ) . '://' . $http_host . $request_uri;

		$translated = $google_translate->translate( $content, $default_lang, $current_lang, $current_url );

		if ( $translated && $translated !== $content ) {
			// Cache the result for future requests (faster page load)
			$cache_manager->set_cache( $cache_key, $translated );
			return $translated;
		}

		// Return original if translation failed
		return $content;
	}

	/**
	 * Translate title parts
	 */
	public function translate_title_parts( $title_parts ) {
		// Free plugin: keep original <title> for SEO. Body copy is translated client-side.
		return $title_parts;
	}

	/**
	 * Translate menu items (before adding language switcher)
	 */
	public function translate_menu_items( $items, $args ) {
		$current_lang = $this->get_current_language();
		$default_lang = $this->get_default_language();

		if ( $current_lang === $default_lang ) {
			return $items;
		}

		// Translate menu item text using regex - preserve HTML structure
		$pattern = '/<a([^>]*)>([^<]+)<\/a>/i';
		$items   = preg_replace_callback(
			$pattern,
			function ( $matches ) {
				$attributes = $matches[1];
				$text       = trim( $matches[2] );
				if ( ! empty( $text ) ) {
					$translated = $this->translate_content( $text );
					return '<a' . $attributes . '>' . $translated . '</a>';
				}
				return $matches[0];
			},
			$items
		);

		return $items;
	}

	/**
	 * Translate menu item output (walker-based menus)
	 */
	public function translate_menu_item_output( $item_output, $item, $depth, $args ) {
		// Skip if this is our language switcher
		if ( isset( $item->classes ) && in_array( 'menu-item-wtranslator', $item->classes ) ) {
			return $item_output;
		}

		$current_lang = $this->get_current_language();
		$default_lang = $this->get_default_language();

		if ( $current_lang === $default_lang || empty( $item_output ) ) {
			return $item_output;
		}

		// Translate menu item title
		if ( ! empty( $item->title ) ) {
			$translated_title = $this->translate_content( $item->title );
			$item_output      = str_replace( $item->title, $translated_title, $item_output );
		}

		return $item_output;
	}

	/**
	 * Translate bloginfo
	 */
	public function translate_bloginfo( $output, $show ) {
		$current_lang = $this->get_current_language();
		$default_lang = $this->get_default_language();

		if ( $current_lang === $default_lang || empty( $output ) ) {
			return $output;
		}

		// Only translate text-based info, skip URLs and other data
		$text_fields = array( 'name', 'description', 'admin_email' );
		if ( in_array( $show, $text_fields ) && is_string( $output ) ) {
			return $this->translate_content( $output );
		}

		return $output;
	}

	/**
	 * Translate bloginfo output
	 */
	public function translate_bloginfo_output( $output, $show ) {
		return $this->translate_bloginfo( $output, $show );
	}

	/**
	 * Translate taxonomy terms
	 */
	public function translate_terms( $terms, $post_id, $taxonomy ) {
		$current_lang = $this->get_current_language();
		$default_lang = $this->get_default_language();

		if ( $current_lang === $default_lang || empty( $terms ) || is_wp_error( $terms ) ) {
			return $terms;
		}

		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				if ( isset( $term->name ) ) {
					$term->name = $this->translate_content( $term->name );
				}
				if ( isset( $term->description ) ) {
					$term->description = $this->translate_content( $term->description );
				}
			}
		}

		return $terms;
	}

	/**
	 * Translate post meta for known SEO keys (e.g. Yoast, Rank Math) using the same translation layer as the rest of the plugin.
	 */
	public function translate_post_meta( $value, $post_id, $key, $single ) {
		return $value;
	}

	/**
	 * Handle language switch
	 */
	public function handle_language_switch() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public language switching action
		if ( isset( $_GET['lang'] ) && ! empty( $_GET['lang'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$lang             = sanitize_text_field( wp_unslash( $_GET['lang'] ) );
			$language_manager = new WTranslator_Language_Manager();

			if ( $language_manager->is_valid_language( $lang ) ) {
				setcookie( 'wtranslator_lang', $lang, time() + ( 365 * 24 * 60 * 60 ), '/', '', is_ssl(), false );
				$_COOKIE['wtranslator_lang'] = $lang;
			}

			// Strip ?lang= from the URL so it cannot be used for SEO/indexing.
			$redirect_url = remove_query_arg( 'lang' );
			if ( ! empty( $redirect_url ) ) {
				wp_safe_redirect( $redirect_url, 302 );
				exit;
			}
		}
	}

	/**
	 * AJAX translate handler - supports both single and batch translation
	 */
	public function ajax_translate() {
		check_ajax_referer( 'wtranslator_nonce', 'nonce' );

		$source_lang  = isset( $_POST['source_lang'] ) ? sanitize_text_field( wp_unslash( $_POST['source_lang'] ) ) : 'en';
		$target_lang  = isset( $_POST['target_lang'] ) ? sanitize_text_field( wp_unslash( $_POST['target_lang'] ) ) : 'en';
		$http_referer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		$page_url     = isset( $_POST['page_url'] ) ? esc_url_raw( wp_unslash( $_POST['page_url'] ) ) : $http_referer;

		$google_translate = new WTranslator_Google_Translate();

		// Check if batch translation (multiple texts)
		if ( isset( $_POST['texts'] ) && is_array( $_POST['texts'] ) ) {
			$texts = array_map( 'wp_kses_post', wp_unslash( $_POST['texts'] ) );

			if ( empty( $texts ) ) {
				wp_send_json_error( array( 'message' => __( 'Texts are required', 'wtranslator' ) ) );
			}

			// Use batch translate if available
			$translations = $google_translate->translate_batch( $texts, $source_lang, $target_lang, $page_url );

			if ( ! empty( $translations ) ) {
				wp_send_json_success( array( 'translations' => $translations ) );
			} else {
				// Fallback: translate individually
				$translations = array();
				foreach ( $texts as $text ) {
					if ( ! empty( $text ) ) {
						$translated     = $google_translate->translate( $text, $source_lang, $target_lang, $page_url );
						$translations[] = $translated ? $translated : $text;
					} else {
						$translations[] = '';
					}
				}
				wp_send_json_success( array( 'translations' => $translations ) );
			}
		} else {
			// Single text translation (backward compatibility)
			$text = isset( $_POST['text'] ) ? wp_kses_post( wp_unslash( $_POST['text'] ) ) : '';

			if ( empty( $text ) ) {
				wp_send_json_error( array( 'message' => __( 'Text is required', 'wtranslator' ) ) );
			}

			$translated = $google_translate->translate( $text, $source_lang, $target_lang, $page_url );

			if ( $translated ) {
				wp_send_json_success( array( 'translated' => $translated ) );
			} else {
				wp_send_json_error( array( 'message' => __( 'Translation failed', 'wtranslator' ) ) );
			}
		}
	}


	/**
	 * Add settings link
	 */
	public function add_settings_link( $links ) {
		$settings_link = '<a href="' . admin_url( 'admin.php?page=wtranslator' ) . '">' . __( 'Settings', 'wtranslator' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}


	/**
	 * AJAX clear cache handler
	 */
	public function ajax_clear_cache() {
		check_ajax_referer( 'wtranslator_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'wtranslator' ) ) );
		}

		$cache_manager = new WTranslator_Cache_Manager();
		$result        = $cache_manager->clear_all_cache();

		if ( $result ) {
			wp_send_json_success( array( 'message' => __( 'Cache cleared successfully', 'wtranslator' ) ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to clear cache', 'wtranslator' ) ) );
		}
	}

	/**
	 * AJAX save settings handler - saves settings via AJAX and returns success message
	 */
	public function ajax_save_settings() {
		check_ajax_referer( 'wtranslator_settings_nonce', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'wtranslator' ) ) );
		}

		// Get settings from POST (unslashed)
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Array is unslashed, individual fields sanitized below
		$settings = isset( $_POST['wtranslator_settings'] ) ? wp_unslash( $_POST['wtranslator_settings'] ) : array();

		// Define fields that are checkboxes
		$checkbox_fields = array( 'native_language_names', 'auto_detect' );
		foreach ( $checkbox_fields as $field ) {
			if ( ! isset( $settings[ $field ] ) ) {
				$settings[ $field ] = 0;
			}
		}

		if ( ! isset( $settings['enabled_languages'] ) ) {
			$settings['enabled_languages'] = array();
		}

		// Sanitize settings
		$sanitized         = $this->sanitize_settings( $settings );
		$existing_settings = get_option( 'wtranslator_settings', array() );
		$sanitized         = array_merge( $existing_settings, $sanitized );

		// Save settings
		update_option( 'wtranslator_settings', $sanitized );
		$this->settings = $sanitized;

		// Flush rewrite rules if needed

		wp_send_json_success(
			array(
				'message' => __( 'Settings saved successfully!', 'wtranslator' ),
			)
		);
	}

	/**
	 * Normalize a language code to plugin keys (zh-CN, en, bn, …).
	 *
	 * @param string $code Raw code from cookie, URL, or Google Translate.
	 * @return string
	 */
	public function normalize_language_code( $code ) {
		$code = str_replace( '_', '-', trim( (string) $code ) );
		if ( $code === '' ) {
			return '';
		}

		$lower = strtolower( $code );
		if ( $lower === 'zh' || $lower === 'zh-cn' || $lower === 'zh-hans' || $lower === 'zh-sg' ) {
			return 'zh-CN';
		}
		if ( $lower === 'zh-tw' || $lower === 'zh-hant' || $lower === 'zh-hk' ) {
			return 'zh-TW';
		}

		$parts = explode( '-', $code );
		if ( count( $parts ) === 2 ) {
			return strtolower( $parts[0] ) . '-' . strtoupper( $parts[1] );
		}

		return strtolower( $code );
	}

	/**
	 * Get current language from URL or cookie
	 * Supports both query parameter and sub-directory URL structure
	 */
	public function get_current_language() {
		$default_lang = $this->get_default_language();
		$lang         = $default_lang;

		if ( isset( $_COOKIE['wtranslator_lang'] ) ) {
			$lang = $this->normalize_language_code( sanitize_text_field( wp_unslash( $_COOKIE['wtranslator_lang'] ) ) );
		}

		if ( ! class_exists( 'WTranslator_Language_Manager' ) ) {
			require_once WTRANSLATOR_PLUGIN_DIR . 'includes/class-language-manager.php';
		}
		$language_manager = new WTranslator_Language_Manager();
		$matched          = $language_manager->match_language_code( $lang );
		if ( $matched ) {
			return $matched;
		}

		return $default_lang;
	}

	/**
	 * Detect language from IP (supports Cloudflare)
	 */
	private function detect_language_by_ip( $manual_ip = null ) {
		$country_code = '';

		// Use manual IP (from client AJAX) if provided
		$visitor_ip = $manual_ip;

		if ( empty( $visitor_ip ) ) {
			// Determine visitor IP (respecting Cloudflare/Proxies)
			$visitor_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
			if ( isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) && ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
				$visitor_ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
			} elseif ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
				$x_forwarded_for = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
				$visitor_ip      = explode( ',', $x_forwarded_for )[0];
			}
		}

		// 1. Check Cloudflare's direct country header
		if ( isset( $_SERVER['HTTP_CF_IPCOUNTRY'] ) && ! empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) && empty( $manual_ip ) ) {
			$country_code = strtoupper( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) );
		}

		// No external IP/geo APIs: use only Cloudflare header when available.
		if ( empty( $country_code ) ) {
			return false;
		}

		$country_lang_map = array(
			'BD' => 'bn',
			'FR' => 'fr',
			'ES' => 'es',
			'DE' => 'de',
			'IT' => 'it',
			'CN' => 'zh-CN',
			'JP' => 'ja',
			'RU' => 'ru',
			'PT' => 'pt',
			'BR' => 'pt',
			'AR' => 'ar',
			'SA' => 'ar',
			'EG' => 'ar',
		);

		return isset( $country_lang_map[ $country_code ] ) ? $country_lang_map[ $country_code ] : false;
	}

	/**
	 * Detect language from browser
	 */
	private function detect_language() {
		if ( ! isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) {
			return false;
		}

		$accept_language = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) );
		$languages       = explode( ',', $accept_language );

		if ( ! empty( $languages ) ) {
			$primary_lang = trim( explode( ';', $languages[0] )[0] );
			$lang_code    = substr( $primary_lang, 0, 2 );

			// Check for specific codes like zh-CN
			if ( preg_match( '/^zh-(cn|tw|hk|sg)/i', $primary_lang, $zh_matches ) ) {
				$lang_code = 'zh-' . strtoupper( $zh_matches[1] );
				if ( $lang_code === 'zh-HK' || $lang_code === 'zh-SG' ) {
					$lang_code = 'zh-CN';
				}
			}

			if ( ! class_exists( 'WTranslator_Language_Manager' ) ) {
				require_once WTRANSLATOR_PLUGIN_DIR . 'includes/class-language-manager.php';
			}
			$language_manager  = new WTranslator_Language_Manager();
			$enabled_languages = $language_manager->get_enabled_languages();

			// Only return if it's a valid language
			if ( $language_manager->is_valid_language( $lang_code ) ) {
				return $lang_code;
			}
		}

		return false;
	}

	/**
	 * AJAX handler for language detection (IP when provided, otherwise browser Accept-Language).
	 * No external IP/geo APIs are used; Cloudflare header is used when available.
	 */
	public function ajax_detect_ip() {
		check_ajax_referer( 'wtranslator_nonce', '_ajax_nonce' );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above
		$ip = isset( $_POST['visitor_ip'] ) ? sanitize_text_field( wp_unslash( $_POST['visitor_ip'] ) ) : '';

		$detected = false;
		if ( ! empty( $ip ) && $ip !== '127.0.0.1' && $ip !== '::1' ) {
			$detected = $this->detect_language_by_ip( $ip );
		}
		if ( ! $detected ) {
			$detected = $this->detect_language();
		}

		if ( $detected ) {
			$language_manager  = new WTranslator_Language_Manager();
			$enabled_languages = $language_manager->get_enabled_languages();
			$default_lang      = $this->get_default_language();

			if ( $language_manager->is_valid_language( $detected ) && isset( $enabled_languages[ $detected ] ) ) {
				// Set cookie
				setcookie( 'wtranslator_lang', $detected, time() + ( 365 * 24 * 60 * 60 ), '/', '', is_ssl(), false );

				wp_send_json_success(
					array(
						'lang'       => $detected,
						'is_default' => ( $detected === $default_lang ),
					)
				);
			}
		}

		wp_send_json_error( 'Detection failed or language not enabled' );
	}

	/**
	 * Get default language
	 */
	public function get_default_language() {
		$settings = $this->get_settings();
		// "translate_from" is the source (default) language used across the plugin (e.g., URL logic).
		// Prefer it for translation decisions; fall back to legacy "default_language".
		if ( ! empty( $settings['translate_from'] ) ) {
			return $settings['translate_from'];
		}
		if ( ! empty( $settings['default_language'] ) ) {
			return $settings['default_language'];
		}
		return 'en';
	}


	/**
	 * Get settings
	 */
	public function get_settings() {
		$this->settings = get_option( 'wtranslator_settings', array() );

		// Migrate wrapper_selector from double hyphen to single hyphen
		if ( ! empty( $this->settings['wrapper_selector'] ) && strpos( $this->settings['wrapper_selector'], 'wtranslator--wrapper' ) !== false ) {
			$this->settings['wrapper_selector'] = str_replace( 'wtranslator--wrapper', 'wtranslator-wrapper', $this->settings['wrapper_selector'] );
			// Update database with migrated value
			update_option( 'wtranslator_settings', $this->settings );
		}

		return $this->settings;
	}

	/**
	 * Optional: redirect /lang/ URLs to base path. Not hooked by default.
	 */
	public function handle_subdirectory_redirect() {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( ! $request_uri ) {
			return;
		}
		$path = wp_parse_url( $request_uri, PHP_URL_PATH );
		if ( ! $path || ! preg_match( '#^/([a-z]{2}(?:-[a-z]{2,3})?)(?:/|$)#i', $path, $matches ) ) {
			return;
		}
		$lang_code  = $matches[1];
		$lang_parts = explode( '-', $lang_code );
		if ( count( $lang_parts ) === 2 ) {
			$lang_code = strtolower( $lang_parts[0] ) . '-' . strtoupper( $lang_parts[1] );
		} else {
			$lang_code = strtolower( $lang_code );
		}
		if ( ! class_exists( 'WTranslator_Language_Manager' ) ) {
			require_once WTRANSLATOR_PLUGIN_DIR . 'includes/class-language-manager.php';
		}
		$language_manager = new WTranslator_Language_Manager();
		if ( ! $language_manager->is_valid_language( $lang_code ) ) {
			return;
		}
		$new_path = preg_replace( '#^/([a-z]{2}(?:-[a-z]{2,3})?)(/|$)#i', '/', $path );
		$new_path = '/' . ltrim( $new_path, '/' );
		$query    = wp_parse_url( $request_uri, PHP_URL_QUERY );
		if ( $query ) {
			$new_path .= '?' . $query;
		}
		wp_safe_redirect( home_url( $new_path ), 301 );
		exit;
	}


	/**
	 * Output hreflang link tags for each enabled language (query-parameter URLs).
	 */
	public function output_seo_tags() {
		if ( is_admin() ) {
			return;
		}
		$settings  = get_option( 'wtranslator_settings', array() );
		$languages = isset( $settings['languages'] ) && is_array( $settings['languages'] ) ? $settings['languages'] : array();
		if ( empty( $languages ) ) {
			return;
		}
		if ( ! class_exists( 'WTranslator_Language_Manager' ) ) {
			require_once WTRANSLATOR_PLUGIN_DIR . 'includes/class-language-manager.php';
		}
		$language_manager = new WTranslator_Language_Manager();
		foreach ( $languages as $code ) {
			if ( empty( $code ) || ! $language_manager->is_valid_language( $code ) ) {
				continue;
			}
			$url      = $language_manager->get_language_url( $code );
			$hreflang = str_replace( '_', '-', strtolower( $code ) );
			echo '<link rel="alternate" hreflang="' . esc_attr( $hreflang ) . '" href="' . esc_url( $url ) . '" />' . "\n";
		}
	}

	/**
	 * Filter canonical URL for SEO plugins compatibility
	 */
	public function filter_canonical_url( $canonical ) {
		return $canonical;
	}

	/**
	 * Translate meta description using the plugin's translation layer (SEO plugin filters).
	 */
	public function translate_meta_description( $description ) {
		return $description;
	}

	/**
	 * Translate meta title using the plugin's translation layer (SEO plugin filters).
	 */
	public function translate_meta_title( $title ) {
		return $title;
	}

	/**
	 * Translate document title parts using the plugin's translation layer.
	 */
	public function translate_document_title( $title_parts ) {
		return $this->translate_title_parts( $title_parts );
	}

	/**
	 * Intercept wp_head output to translate meta description tags.
	 * This is a last-resort fallback for SEO plugins/themes that don't provide filters.
	 */
	public function intercept_meta_description_tags() {
		return;
	}

	/**
	 * End wp_head output buffer and translate any meta description-like tags.
	 */
	public function end_intercept_meta_description_tags() {
		return;
	}

	/**
	 * Output a default meta description when no SEO plugin is present.
	 * Free plugin never translates this tag.
	 */
	public function output_meta_description() {
		// Only on frontend
		if ( is_admin() ) {
			return;
		}

		// Skip if SEO plugin already outputs meta description
		// We don't want duplicate meta descriptions
		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' ) ) {
			// SEO plugin will handle it, but Google Translate will translate it
			return;
		}

		// Get meta description from WordPress default
		$meta_description = '';

		if ( is_singular() ) {
			global $post;
			if ( $post ) {
				$meta_description = get_the_excerpt( $post->ID );
				if ( empty( $meta_description ) ) {
					$meta_description = wp_trim_words( get_the_content( $post->ID ), 30 );
				}
			}
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$meta_description = term_description();
		} elseif ( is_home() || is_front_page() ) {
			$meta_description = get_bloginfo( 'description' );
		}

		// Output original meta description only (free plugin does not translate SEO tags).
		if ( ! empty( $meta_description ) ) {
			$meta_description = wp_strip_all_tags( $meta_description );

			echo '<meta name="description" class="notranslate" translate="no" content="' . esc_attr( $meta_description ) . '" />' . "\n";
		}
	}

	/**
	 * Bypass Rank Math sitemap cache for language-specific sitemaps
	 * This ensures URLs are rewritten based on current URL language code
	 */
	public function bypass_sitemap_cache_for_language( $mode ) {
		return $mode;
	}

	/**
	 * Add language code to cache directory for language-specific sitemaps
	 * This ensures each language has its own cached sitemap
	 */
	public function add_language_to_cache_directory( $directory ) {
		return $directory;
	}
}

// Initialize the plugin
function wtranslator_init() {
	return WTranslator::get_instance();
}

// Start the plugin
wtranslator_init();

