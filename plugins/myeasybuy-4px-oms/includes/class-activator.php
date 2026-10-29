<?php
/**
 * Schema and default options.
 *
 * @package MyEasyBuy_OMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Activation / upgrade.
 */
class MyEasyBuy_OMS_Activator {

	public const DB_VERSION        = '1.0.0';
	public const DB_VERSION_OPTION = 'meb_oms_db_version';

	/**
	 * Activate.
	 */
	public static function activate(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			deactivate_plugins( MEB_OMS_BASENAME );
			wp_die(
				esc_html__( 'MyEasyBuy 4PX 集运 OMS 需要 WooCommerce。', 'meb-oms' ),
				esc_html__( '插件激活失败', 'meb-oms' ),
				array( 'back_link' => true )
			);
		}

		self::create_tables();
		self::ensure_default_options();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		flush_rewrite_rules( false );
	}

	/**
	 * Deactivate.
	 */
	public static function deactivate(): void {
		flush_rewrite_rules( false );
	}

	/**
	 * Upgrade schema when version drifts.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			self::ensure_default_options();
			return;
		}

		self::create_tables();
		self::ensure_default_options();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Orders table.
	 */
	public static function orders_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'meb_oms_orders';
	}

	/**
	 * Callback log table.
	 */
	public static function callbacks_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'meb_oms_callbacks';
	}

	/**
	 * Seed options.
	 */
	public static function ensure_default_options(): void {
		$current = get_option( MyEasyBuy_OMS_Service::OPTION_KEY, false );
		$merged  = wp_parse_args(
			false === $current ? array() : (array) $current,
			MyEasyBuy_OMS_Service::default_settings()
		);

		if ( empty( $merged['callback_token'] ) ) {
			$merged['callback_token'] = wp_generate_password( 24, false, false );
		}

		if ( false === $current ) {
			add_option( MyEasyBuy_OMS_Service::OPTION_KEY, $merged );
			return;
		}

		if ( $merged !== (array) $current ) {
			update_option( MyEasyBuy_OMS_Service::OPTION_KEY, $merged );
		}
	}

	/**
	 * Create tables.
	 */
	private static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		dbDelta( self::orders_schema( $charset ) );
		dbDelta( self::callbacks_schema( $charset ) );
	}

	/**
	 * 一段 / 二段 orders.
	 */
	private static function orders_schema( string $charset ): string {
		$table = self::orders_table();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			leg tinyint(1) unsigned NOT NULL DEFAULT 1,
			source_type varchar(20) NOT NULL DEFAULT 'wc_order',
			source_id bigint(20) unsigned NOT NULL DEFAULT 0,
			group_id varchar(80) NOT NULL DEFAULT '',
			customer_hawb_code varchar(80) NOT NULL,
			fpx_track_no varchar(80) NOT NULL DEFAULT '',
			service_hawb_code varchar(80) NOT NULL DEFAULT '',
			oms_status varchar(16) NOT NULL DEFAULT 'P',
			shipping_mode varchar(32) NOT NULL DEFAULT 'CONSOLIDATED_1',
			country_code varchar(8) NOT NULL DEFAULT 'CA',
			weight_g int(11) NOT NULL DEFAULT 0,
			length_mm int(11) NOT NULL DEFAULT 0,
			width_mm int(11) NOT NULL DEFAULT 0,
			height_mm int(11) NOT NULL DEFAULT 0,
			photos longtext NULL,
			storage_district varchar(80) NOT NULL DEFAULT '',
			sale_pk_code varchar(40) NOT NULL DEFAULT '',
			one_service_hawb_list longtext NULL,
			piece_list longtext NULL,
			last_action_code varchar(80) NOT NULL DEFAULT '',
			last_error text NULL,
			request_payload longtext NULL,
			response_payload longtext NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY customer_hawb_code (customer_hawb_code),
			KEY fpx_track_no (fpx_track_no),
			KEY service_hawb_code (service_hawb_code),
			KEY source_lookup (source_type, source_id),
			KEY group_id (group_id),
			KEY oms_status (oms_status),
			KEY leg (leg)
		) {$charset};";
	}

	/**
	 * Raw callback log.
	 */
	private static function callbacks_schema( string $charset ): string {
		$table = self::callbacks_table();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_code varchar(80) NOT NULL DEFAULT '',
			action_code varchar(80) NOT NULL DEFAULT '',
			msg_type varchar(120) NOT NULL DEFAULT '',
			operation_time varchar(32) NOT NULL DEFAULT '',
			wh_code varchar(40) NOT NULL DEFAULT '',
			operate_result_code varchar(16) NOT NULL DEFAULT '',
			payload longtext NULL,
			processed tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY order_code (order_code),
			KEY action_code (action_code),
			KEY created_at (created_at)
		) {$charset};";
	}
}
