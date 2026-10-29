<?php
/**
 * Current-user scoped CRUD for wp_rf_packages.
 *
 * @package ReverseForwarding
 */

namespace ReverseForwarding\Models;

use ReverseForwarding\Activator;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Package repository. Every read/write is locked to the logged-in owner.
 */
class Package {

	public const STATUS_PENDING       = 'pending';
	public const STATUS_INBOUND       = 'inbound';
	public const STATUS_STORED        = 'stored';
	public const STATUS_APPLIED       = 'applied';
	public const STATUS_CONSOLIDATING = 'consolidating';
	public const STATUS_IN_TRANSIT    = 'in_transit';

	/**
	 * Fields a buyer may write on create / pending update.
	 */
	private const USER_FIELDS = array( 'tracking_number', 'express_company', 'declared_name' );

	/**
	 * Full table name.
	 */
	public static function table(): string {
		return Activator::packages_table();
	}

	/**
	 * Current actor. Returns 0 when logged out.
	 */
	public static function current_user_id(): int {
		if ( ! is_user_logged_in() || ! current_user_can( 'read' ) ) {
			return 0;
		}

		return get_current_user_id();
	}

	/**
	 * Insert a declared inbound parcel for the current user.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return int|WP_Error New ID or error.
	 */
	public static function create( array $input ) {
		global $wpdb;

		$user_id = self::current_user_id();
		if ( $user_id <= 0 ) {
			return new WP_Error( 'wc_rf_auth', __( '请先登录后再预报包裹。', 'wc-rf' ) );
		}

		$clean = self::sanitize_user_fields( $input );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		if ( self::tracking_exists( $clean['tracking_number'] ) ) {
			return new WP_Error( 'wc_rf_tracking_dup', __( '该快递单号已预报，请勿重复提交。', 'wc-rf' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			self::table(),
			array(
				'user_id'          => $user_id,
				'tracking_number'  => $clean['tracking_number'],
				'express_company'  => $clean['express_company'],
				'declared_name'    => $clean['declared_name'],
				'status'           => self::STATUS_PENDING,
				'weight'           => 0,
				'length'           => 0,
				'width'            => 0,
				'height'           => 0,
				'shelf_location'   => '',
			),
			array( '%d', '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%f', '%s' )
		);

		if ( false === $inserted ) {
			if ( self::is_duplicate_error( (string) $wpdb->last_error ) ) {
				return new WP_Error( 'wc_rf_tracking_dup', __( '该快递单号已预报，请勿重复提交。', 'wc-rf' ) );
			}

			return new WP_Error( 'wc_rf_db', __( '包裹预报失败，请稍后重试。', 'wc-rf' ) );
		}

		$package_id = (int) $wpdb->insert_id;

		/**
		 * Fires after a buyer declares a domestic inbound parcel.
		 *
		 * @since 1.0.1
		 *
		 * @param int                  $package_id New package ID.
		 * @param int                  $user_id    Owner user ID.
		 * @param array<string, mixed> $clean      Sanitized fields.
		 */
		do_action( 'wc_rf_package_declared', $package_id, $user_id, $clean );

		return $package_id;
	}

	/**
	 * Fetch one package owned by the current user.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get( int $id ): ?array {
		$items = self::query(
			array(
				'id'    => $id,
				'limit' => 1,
			)
		);

		return $items[0] ?? null;
	}

	/**
	 * Update a pending package owned by the current user.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return true|WP_Error
	 */
	public static function update( int $id, array $input ) {
		global $wpdb;

		$row = self::get( $id );
		if ( null === $row ) {
			return new WP_Error( 'wc_rf_not_found', __( '找不到该包裹，或您无权操作。', 'wc-rf' ) );
		}

		if ( self::STATUS_PENDING !== $row['status'] ) {
			return new WP_Error( 'wc_rf_locked', __( '仅待入库的预报包裹可以修改。', 'wc-rf' ) );
		}

		$clean = self::sanitize_user_fields( array_merge( $row, $input ) );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		if ( self::tracking_exists( $clean['tracking_number'], $id ) ) {
			return new WP_Error( 'wc_rf_tracking_dup', __( '该快递单号已预报，请勿重复提交。', 'wc-rf' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			self::table(),
			$clean,
			array(
				'id'      => $id,
				'user_id' => self::current_user_id(),
			),
			array( '%s', '%s', '%s' ),
			array( '%d', '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'wc_rf_db', __( '包裹更新失败，请稍后重试。', 'wc-rf' ) );
		}

		return true;
	}

	/**
	 * Delete a pending package owned by the current user.
	 *
	 * @return true|WP_Error
	 */
	public static function delete( int $id ) {
		global $wpdb;

		$row = self::get( $id );
		if ( null === $row ) {
			return new WP_Error( 'wc_rf_not_found', __( '找不到该包裹，或您无权操作。', 'wc-rf' ) );
		}

		if ( self::STATUS_PENDING !== $row['status'] ) {
			return new WP_Error( 'wc_rf_locked', __( '仅待入库的预报包裹可以撤销。', 'wc-rf' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete(
			self::table(),
			array(
				'id'      => $id,
				'user_id' => self::current_user_id(),
			),
			array( '%d', '%d' )
		);

		if ( false === $deleted || 0 === (int) $deleted ) {
			return new WP_Error( 'wc_rf_db', __( '撤销预报失败，请稍后重试。', 'wc-rf' ) );
		}

		/**
		 * Fires after a pending inbound declaration is cancelled.
		 *
		 * @since 1.0.1
		 *
		 * @param int                  $id  Package ID.
		 * @param array<string, mixed> $row Deleted row.
		 */
		do_action( 'wc_rf_package_cancelled', $id, $row );

		return true;
	}

	/**
	 * List packages for the current user only.
	 *
	 * @param array<string, mixed> $args {
	 *     @type int    $id
	 *     @type string $status
	 *     @type int    $limit
	 * }
	 * @return array<int, array<string, mixed>>
	 */
	public static function query( array $args = array() ): array {
		global $wpdb;

		$user_id = self::current_user_id();
		if ( $user_id <= 0 ) {
			return array();
		}

		$table  = self::table();
		$where  = array( 'user_id = %d' );
		$params = array( $user_id );

		if ( ! empty( $args['id'] ) ) {
			$where[]  = 'id = %d';
			$params[] = absint( $args['id'] );
		}

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = sanitize_text_field( (string) $args['status'] );
		}

		$limit = isset( $args['limit'] ) ? absint( $args['limit'] ) : 200;
		$limit = min( max( $limit, 1 ), 500 );

		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY created_at DESC, id DESC LIMIT %d';
		$params[] = $limit;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( array( __CLASS__, 'hydrate' ), $rows );
	}

	/**
	 * Group the current user's packages into list tabs.
	 *
	 * @return array{pending: array<int, array<string, mixed>>, inbound: array<int, array<string, mixed>>, transit: array<int, array<string, mixed>>}
	 */
	public static function grouped_for_current_user(): array {
		$grouped = array(
			'pending' => array(),
			'inbound' => array(),
			'transit' => array(),
		);

		foreach ( self::query() as $row ) {
			$grouped[ self::tab_of( $row ) ][] = $row;
		}

		return $grouped;
	}

	/**
	 * Whether this inbound package can be selected for consolidation.
	 *
	 * @param array<string, mixed> $row Package.
	 */
	public static function is_selectable( array $row ): bool {
		return 'inbound' === self::tab_of( $row );
	}

	/**
	 * Tab key for a package row.
	 *
	 * @param array<string, mixed> $row Package.
	 */
	public static function tab_of( array $row ): string {
		$status = (string) ( $row['status'] ?? '' );
		$cid    = (int) ( $row['consolidation_id'] ?? 0 );

		if ( $cid > 0 || in_array( $status, array( self::STATUS_APPLIED, self::STATUS_CONSOLIDATING, self::STATUS_IN_TRANSIT ), true ) ) {
			return 'transit';
		}

		if ( in_array( $status, array( self::STATUS_INBOUND, self::STATUS_STORED ), true ) ) {
			return 'inbound';
		}

		return 'pending';
	}

	/**
	 * Volume in cm³. Zero if any side is missing.
	 *
	 * @param array<string, mixed> $row Package.
	 */
	public static function volume_cm3( array $row ): float {
		$length = (float) ( $row['length'] ?? 0 );
		$width  = (float) ( $row['width'] ?? 0 );
		$height = (float) ( $row['height'] ?? 0 );

		if ( $length <= 0 || $width <= 0 || $height <= 0 ) {
			return 0.0;
		}

		return round( $length * $width * $height, 2 );
	}

	/**
	 * Human-readable dimensions + volume.
	 *
	 * @param array<string, mixed> $row Package.
	 */
	public static function volume_label( array $row ): string {
		$cm3 = self::volume_cm3( $row );
		if ( $cm3 <= 0 ) {
			return '';
		}

		return sprintf(
			'%s × %s × %s cm（%s cm³）',
			self::format_number( (float) $row['length'] ),
			self::format_number( (float) $row['width'] ),
			self::format_number( (float) $row['height'] ),
			self::format_number( $cm3 )
		);
	}

	/**
	 * Attachment IDs stored in the photos JSON column.
	 *
	 * @param array<string, mixed> $row Package.
	 * @return array<int, int>
	 */
	public static function photo_ids( array $row ): array {
		$raw = $row['photos'] ?? '';
		if ( is_array( $raw ) ) {
			$decoded = $raw;
		} else {
			$decoded = json_decode( (string) $raw, true );
		}

		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$ids = array();
		foreach ( $decoded as $item ) {
			if ( is_numeric( $item ) ) {
				$ids[] = absint( $item );
				continue;
			}
			if ( is_array( $item ) && ! empty( $item['id'] ) ) {
				$ids[] = absint( $item['id'] );
			}
		}

		return array_values( array_filter( $ids ) );
	}

	/**
	 * Lock inbound packages to a draft consolidation. Always scoped to $user_id.
	 *
	 * @param array<int, int> $ids Package IDs.
	 * @return true|WP_Error
	 */
	public static function attach_to_consolidation( array $ids, int $consolidation_id, int $user_id ) {
		global $wpdb;

		$ids    = array_values( array_filter( array_map( 'absint', $ids ) ) );
		$user_id = absint( $user_id );
		if ( array() === $ids || $user_id <= 0 || $consolidation_id <= 0 ) {
			return new WP_Error( 'wc_rf_attach', __( '无法锁定包裹。', 'wc-rf' ) );
		}

		$table  = self::table();
		$in     = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$params = array_merge(
			array( $consolidation_id, self::STATUS_APPLIED ),
			$ids,
			array( $user_id, self::STATUS_INBOUND, self::STATUS_STORED )
		);

		$sql = "UPDATE {$table}
			SET consolidation_id = %d, status = %s
			WHERE id IN ({$in})
			AND user_id = %d
			AND status IN (%s, %s)
			AND (consolidation_id IS NULL OR consolidation_id = 0)";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$updated = $wpdb->query( $wpdb->prepare( $sql, $params ) );

		if ( (int) $updated !== count( $ids ) ) {
			return new WP_Error( 'wc_rf_attach', __( '部分包裹已不在待合箱状态，请刷新后重试。', 'wc-rf' ) );
		}

		return true;
	}

	/**
	 * Unlock packages from a cancelled draft.
	 */
	public static function detach_from_consolidation( int $consolidation_id, int $user_id ): void {
		global $wpdb;

		if ( $consolidation_id <= 0 || $user_id <= 0 ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			self::table(),
			array(
				'consolidation_id' => 0,
				'status'           => self::STATUS_STORED,
			),
			array(
				'consolidation_id' => $consolidation_id,
				'user_id'          => $user_id,
				'status'           => self::STATUS_APPLIED,
			),
			array( '%d', '%s' ),
			array( '%d', '%d', '%s' )
		);
	}

	/**
	 * After payment: applied → consolidating for this job + owner.
	 */
	public static function mark_consolidating( int $consolidation_id, int $user_id ): int {
		global $wpdb;

		if ( $consolidation_id <= 0 || $user_id <= 0 ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			self::table(),
			array( 'status' => self::STATUS_CONSOLIDATING ),
			array(
				'consolidation_id' => $consolidation_id,
				'user_id'          => $user_id,
			),
			array( '%s' ),
			array( '%d', '%d' )
		);

		return max( 0, (int) $updated );
	}

	/**
	 * Packages attached to a consolidation for a specific owner (payment-safe).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_by_consolidation( int $consolidation_id, int $user_id ): array {
		global $wpdb;

		if ( $consolidation_id <= 0 || $user_id <= 0 ) {
			return array();
		}

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE consolidation_id = %d AND user_id = %d ORDER BY id ASC",
				$consolidation_id,
				$user_id
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( array( __CLASS__, 'hydrate' ), $rows );
	}

	/**
	 * All parcels on a consolidation (warehouse / 4PX outbound).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_for_consolidation( int $consolidation_id ): array {
		global $wpdb;

		if ( $consolidation_id <= 0 ) {
			return array();
		}

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE consolidation_id = %d ORDER BY id ASC",
				$consolidation_id
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( array( __CLASS__, 'hydrate' ), $rows );
	}

	/**
	 * After 4PX / international dispatch: consolidating → in_transit.
	 */
	public static function mark_in_transit( int $consolidation_id ): int {
		global $wpdb;

		if ( $consolidation_id <= 0 ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			self::table(),
			array( 'status' => self::STATUS_IN_TRANSIT ),
			array( 'consolidation_id' => $consolidation_id ),
			array( '%s' ),
			array( '%d' )
		);

		return max( 0, (int) $updated );
	}

	/**
	 * Warehouse staff may look up any tracking number.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function find_by_tracking( string $tracking ): ?array {
		global $wpdb;

		if ( ! self::warehouse_can() ) {
			return null;
		}

		$tracking = self::normalize_tracking( $tracking );
		if ( '' === $tracking ) {
			return null;
		}

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE tracking_number = %s LIMIT 1", $tracking ),
			ARRAY_A
		);

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Recent stored parcels for the WMS side panel.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function recent_stored( int $limit = 10 ): array {
		global $wpdb;

		if ( ! self::warehouse_can() ) {
			return array();
		}

		$limit = min( max( $limit, 1 ), 50 );
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s ORDER BY id DESC LIMIT %d",
				self::STATUS_STORED,
				$limit
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( array( __CLASS__, 'hydrate' ), $rows );
	}

	/**
	 * Scan-gun inbound: write measurements and mark stored.
	 *
	 * @param array<string, mixed> $input Measures + optional unclaimed flag.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function receive_from_warehouse( array $input ) {
		global $wpdb;

		if ( ! self::warehouse_can() ) {
			return new WP_Error( 'wc_rf_cap', __( '无权操作入库台。', 'wc-rf' ) );
		}

		$tracking = self::normalize_tracking( (string) ( $input['tracking_number'] ?? '' ) );
		if ( '' === $tracking ) {
			return new WP_Error( 'wc_rf_tracking', __( '请扫描或填写快递单号。', 'wc-rf' ) );
		}

		$weight = (float) ( $input['weight'] ?? 0 );
		$length = (float) ( $input['length'] ?? 0 );
		$width  = (float) ( $input['width'] ?? 0 );
		$height = (float) ( $input['height'] ?? 0 );
		$shelf  = sanitize_text_field( (string) ( $input['shelf_location'] ?? '' ) );
		$photos = isset( $input['photo_ids'] ) && is_array( $input['photo_ids'] )
			? array_values( array_filter( array_map( 'absint', $input['photo_ids'] ) ) )
			: array();

		if ( $weight <= 0 ) {
			return new WP_Error( 'wc_rf_weight', __( '请填写实测重量（kg）。', 'wc-rf' ) );
		}

		$existing = self::find_by_tracking( $tracking );
		if ( $existing ) {
			if ( in_array( (string) $existing['status'], array( self::STATUS_APPLIED, self::STATUS_CONSOLIDATING, self::STATUS_IN_TRANSIT ), true ) ) {
				return new WP_Error( 'wc_rf_locked', __( '该包裹已申请合箱或转运中，不能再入库改重。', 'wc-rf' ) );
			}

			$merged_photos = $photos ? $photos : (array) $existing['photo_ids'];
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$updated       = $wpdb->update(
				self::table(),
				array(
					'status'         => self::STATUS_STORED,
					'weight'         => $weight,
					'length'         => $length,
					'width'          => $width,
					'height'         => $height,
					'shelf_location' => substr( $shelf, 0, 100 ),
					'photos'         => $merged_photos ? wp_json_encode( $merged_photos ) : $existing['photos'],
				),
				array( 'id' => (int) $existing['id'] ),
				array( '%s', '%f', '%f', '%f', '%f', '%s', '%s' ),
				array( '%d' )
			);

			if ( false === $updated ) {
				return new WP_Error( 'wc_rf_db', __( '入库保存失败。', 'wc-rf' ) );
			}

			$fresh = self::find_by_tracking( $tracking );
			return $fresh ? $fresh : $existing;
		}

		if ( empty( $input['allow_unclaimed'] ) ) {
			return new WP_Error( 'wc_rf_not_found', __( '未找到预报记录。可登记为无主认领件。', 'wc-rf' ), array( 'unclaimed' => true ) );
		}

		$name    = sanitize_text_field( (string) ( $input['declared_name'] ?? '' ) );
		$company = sanitize_text_field( (string) ( $input['express_company'] ?? '' ) );
		if ( '' === $name ) {
			$name = __( '无主认领件', 'wc-rf' );
		}
		if ( '' === $company ) {
			$company = __( '未知', 'wc-rf' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			self::table(),
			array(
				'user_id'         => 0,
				'tracking_number' => $tracking,
				'express_company' => substr( $company, 0, 100 ),
				'declared_name'   => substr( $name, 0, 255 ),
				'status'          => self::STATUS_STORED,
				'weight'          => $weight,
				'length'          => $length,
				'width'           => $width,
				'height'          => $height,
				'shelf_location'  => substr( $shelf, 0, 100 ),
				'photos'          => $photos ? wp_json_encode( $photos ) : null,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%f', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'wc_rf_db', __( '无主件登记失败。', 'wc-rf' ) );
		}

		$created = self::find_by_tracking( $tracking );
		return $created ? $created : new WP_Error( 'wc_rf_db', __( '无主件登记失败。', 'wc-rf' ) );
	}

	/**
	 * Whether the current user may operate the WMS workbench.
	 */
	public static function warehouse_can(): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$allowed = current_user_can( 'manage_woocommerce' ) || current_user_can( 'myeasybuy_manage_warehouse' );

		/**
		 * Filter warehouse WMS permission.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $allowed Current decision.
		 */
		return (bool) apply_filters( 'wc_rf_can_manage_warehouse', $allowed );
	}

	/**
	 * Whether this tracking number already exists (any owner, due to UNIQUE KEY).
	 */
	public static function tracking_exists( string $tracking, int $exclude_id = 0 ): bool {
		global $wpdb;

		$tracking = self::normalize_tracking( $tracking );
		if ( '' === $tracking ) {
			return false;
		}

		$table = self::table();

		if ( $exclude_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$found = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE tracking_number = %s AND id <> %d LIMIT 1",
					$tracking,
					$exclude_id
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$found = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE tracking_number = %s LIMIT 1",
					$tracking
				)
			);
		}

		return ! empty( $found );
	}

	/**
	 * Normalize a carrier tracking number.
	 */
	public static function normalize_tracking( string $tracking ): string {
		$tracking = strtoupper( sanitize_text_field( $tracking ) );
		$tracking = (string) preg_replace( '/\s+/', '', $tracking );
		$tracking = (string) preg_replace( '/[^A-Z0-9\-]/', '', $tracking );

		return substr( $tracking, 0, 100 );
	}

	/**
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, string>|WP_Error
	 */
	private static function sanitize_user_fields( array $input ) {
		$tracking = self::normalize_tracking( (string) ( $input['tracking_number'] ?? '' ) );
		$company  = sanitize_text_field( (string) ( $input['express_company'] ?? '' ) );
		$name     = sanitize_text_field( (string) ( $input['declared_name'] ?? '' ) );

		if ( '' === $tracking ) {
			return new WP_Error( 'wc_rf_tracking', __( '请填写有效的快递单号。', 'wc-rf' ) );
		}

		if ( '' === $company ) {
			return new WP_Error( 'wc_rf_company', __( '请选择或填写快递公司。', 'wc-rf' ) );
		}

		if ( '' === $name ) {
			return new WP_Error( 'wc_rf_name', __( '请填写申报品名。', 'wc-rf' ) );
		}

		return array(
			'tracking_number' => $tracking,
			'express_company' => substr( $company, 0, 100 ),
			'declared_name'   => substr( $name, 0, 255 ),
		);
	}

	/**
	 * @param array<string, mixed> $row Raw DB row.
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		$row['id']               = (int) $row['id'];
		$row['user_id']          = (int) $row['user_id'];
		$row['consolidation_id'] = isset( $row['consolidation_id'] ) ? (int) $row['consolidation_id'] : 0;
		$row['weight']           = (float) $row['weight'];
		$row['length']           = (float) $row['length'];
		$row['width']            = (float) $row['width'];
		$row['height']           = (float) $row['height'];
		$row['tab']              = self::tab_of( $row );
		$row['selectable']       = self::is_selectable( $row );
		$row['volume_cm3']       = self::volume_cm3( $row );
		$row['volume_label']     = self::volume_label( $row );
		$row['photo_ids']        = self::photo_ids( $row );

		return $row;
	}

	private static function is_duplicate_error( string $error ): bool {
		return false !== stripos( $error, 'duplicate' );
	}

	private static function format_number( float $value ): string {
		$formatted = number_format( $value, 2, '.', '' );
		return rtrim( rtrim( $formatted, '0' ), '.' );
	}
}
