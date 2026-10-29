<?php
/**
 * Plugin activation and custom table schema.
 *
 * @package ReverseForwarding
 */

namespace ReverseForwarding;

defined( 'ABSPATH' ) || exit;

/**
 * Creates dedicated forwarding tables on activation / upgrade.
 */
class Activator {

	public const DB_VERSION        = '1.0.0';
	public const DB_VERSION_OPTION = 'wc_rf_db_version';

	/**
	 * Run on plugin activation.
	 */
	public static function activate(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			deactivate_plugins( plugin_basename( WC_RF_PLUGIN_FILE ) );
			wp_die(
				esc_html__( 'WooCommerce Reverse Forwarding requires WooCommerce to be installed and active.', 'wc-rf' ),
				esc_html__( 'Plugin Activation Error', 'wc-rf' ),
				array( 'back_link' => true )
			);
		}

		self::create_tables();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		Checkout\Cart_Integration::ensure_product_id();

		Frontend\Forwarding_Center::add_rewrite_rules();
		flush_rewrite_rules( false );
		update_option( Frontend\Forwarding_Center::REWRITE_OPTION, Frontend\Forwarding_Center::REWRITE_VERSION, false );
	}

	/**
	 * Flush rewrite rules on deactivation. Tables and user meta are kept.
	 */
	public static function deactivate(): void {
		flush_rewrite_rules( false );
	}

	/**
	 * Apply schema updates when the stored DB version is stale.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		self::create_tables();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Packages table with WordPress prefix (wp_rf_packages by default).
	 */
	public static function packages_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'rf_packages';
	}

	/**
	 * Consolidations table with WordPress prefix (wp_rf_consolidations by default).
	 */
	public static function consolidations_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'rf_consolidations';
	}

	/**
	 * Create or upgrade both custom tables via dbDelta().
	 */
	private static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		dbDelta( self::packages_schema( $charset_collate ) );
		dbDelta( self::consolidations_schema( $charset_collate ) );
	}

	/**
	 * Schema for inbound domestic parcels.
	 */
	private static function packages_schema( string $charset_collate ): string {
		$table = self::packages_table();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			tracking_number varchar(100) NOT NULL,
			express_company varchar(100) NOT NULL DEFAULT '',
			declared_name varchar(255) NOT NULL DEFAULT '',
			status varchar(32) NOT NULL DEFAULT 'pending',
			weight decimal(10,3) NOT NULL DEFAULT 0,
			length decimal(10,2) NOT NULL DEFAULT 0,
			width decimal(10,2) NOT NULL DEFAULT 0,
			height decimal(10,2) NOT NULL DEFAULT 0,
			photos longtext NULL,
			shelf_location varchar(100) NOT NULL DEFAULT '',
			consolidation_id bigint(20) unsigned NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY tracking_number (tracking_number),
			KEY user_id (user_id),
			KEY status (status),
			KEY consolidation_id (consolidation_id)
		) {$charset_collate};";
	}

	/**
	 * Schema for outbound consolidation orders.
	 */
	private static function consolidations_schema( string $charset_collate ): string {
		$table = self::consolidations_table();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL,
			carrier_code varchar(50) NOT NULL DEFAULT '',
			chargeable_weight decimal(10,3) NOT NULL DEFAULT 0,
			shipping_fee decimal(10,2) NOT NULL DEFAULT 0,
			service_fee decimal(10,2) NOT NULL DEFAULT 0,
			tracking_number varchar(100) NOT NULL DEFAULT '',
			status varchar(32) NOT NULL DEFAULT 'pending',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY order_id (order_id),
			KEY user_id (user_id),
			KEY status (status),
			KEY tracking_number (tracking_number)
		) {$charset_collate};";
	}
}
