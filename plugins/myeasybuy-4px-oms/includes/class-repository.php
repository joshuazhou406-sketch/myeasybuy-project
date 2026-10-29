<?php
/**
 * Persistence for OMS orders and callbacks.
 *
 * @package MyEasyBuy_OMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Database helpers.
 */
class MyEasyBuy_OMS_Repository {

	/**
	 * Insert or update by customer hawb.
	 *
	 * @param array<string, mixed> $data Row.
	 */
	public static function upsert_order( array $data ): int {
		global $wpdb;

		$hawb = sanitize_text_field( (string) ( $data['customer_hawb_code'] ?? '' ) );
		if ( '' === $hawb ) {
			return 0;
		}

		$existing = self::get_by_hawb( $hawb );
		$row      = self::normalize_row( $data );

		if ( $existing ) {
			unset( $row['customer_hawb_code'], $row['created_at'] );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				MyEasyBuy_OMS_Activator::orders_table(),
				$row,
				array( 'id' => (int) $existing['id'] )
			);

			return (int) $existing['id'];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert( MyEasyBuy_OMS_Activator::orders_table(), $row );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update by primary key.
	 *
	 * @param array<string, mixed> $data Fields.
	 */
	public static function update_order( int $id, array $data ): bool {
		global $wpdb;

		if ( $id <= 0 ) {
			return false;
		}

		$row               = self::normalize_row( $data, false );
		$row['updated_at'] = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->update(
			MyEasyBuy_OMS_Activator::orders_table(),
			$row,
			array( 'id' => $id )
		);
	}

	/**
	 * Get one order.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;

		if ( $id <= 0 ) {
			return null;
		}

		$table = MyEasyBuy_OMS_Activator::orders_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * By customer hawb.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get_by_hawb( string $hawb ): ?array {
		return self::get_by_code( 'customer_hawb_code', $hawb );
	}

	/**
	 * By 4PX track no.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get_by_fpx( string $fpx ): ?array {
		return self::get_by_code( 'fpx_track_no', $fpx );
	}

	/**
	 * By service hawb.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get_by_service_hawb( string $code ): ?array {
		return self::get_by_code( 'service_hawb_code', $code );
	}

	/**
	 * By source object.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get_by_source( string $type, int $source_id, int $leg = 1 ): ?array {
		global $wpdb;

		if ( $source_id <= 0 ) {
			return null;
		}

		$table = MyEasyBuy_OMS_Activator::orders_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE source_type = %s AND source_id = %d AND leg = %d ORDER BY id DESC LIMIT 1",
				$type,
				$source_id,
				$leg
			),
			ARRAY_A
		);

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Find 一段 by any known barcode.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function find_leg1_by_any_code( string $code ): ?array {
		$code = trim( $code );
		if ( '' === $code ) {
			return null;
		}

		foreach ( array( 'customer_hawb_code', 'fpx_track_no', 'service_hawb_code' ) as $column ) {
			$row = self::get_by_code( $column, $code );
			if ( $row && 1 === (int) $row['leg'] ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * List OMS orders.
	 *
	 * @param array<string, mixed> $args Filters.
	 * @return array<int, array<string, mixed>>
	 */
	public static function query_orders( array $args = array() ): array {
		global $wpdb;

		$table  = MyEasyBuy_OMS_Activator::orders_table();
		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['leg'] ) ) {
			$where[]  = 'leg = %d';
			$params[] = (int) $args['leg'];
		}
		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'oms_status = %s';
			$params[] = sanitize_text_field( (string) $args['status'] );
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[]  = '(customer_hawb_code LIKE %s OR fpx_track_no LIKE %s OR service_hawb_code LIKE %s OR group_id LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$limit  = isset( $args['limit'] ) ? max( 1, min( 200, (int) $args['limit'] ) ) : 50;
		$sql    = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d';
		$params[] = $limit;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		return array_map( array( __CLASS__, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Leg-1 rows for a consolidation group.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_leg1_by_group( string $group_id ): array {
		global $wpdb;

		$group_id = sanitize_text_field( $group_id );
		if ( '' === $group_id ) {
			return array();
		}

		$table = MyEasyBuy_OMS_Activator::orders_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE group_id = %s AND leg = 1 ORDER BY id ASC",
				$group_id
			),
			ARRAY_A
		);

		return array_map( array( __CLASS__, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Store a callback.
	 *
	 * @param array<string, mixed> $data Log row.
	 */
	public static function log_callback( array $data ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			MyEasyBuy_OMS_Activator::callbacks_table(),
			array(
				'order_code'           => substr( sanitize_text_field( (string) ( $data['order_code'] ?? '' ) ), 0, 80 ),
				'action_code'          => substr( sanitize_text_field( (string) ( $data['action_code'] ?? '' ) ), 0, 80 ),
				'msg_type'             => substr( sanitize_text_field( (string) ( $data['msg_type'] ?? '' ) ), 0, 120 ),
				'operation_time'       => substr( sanitize_text_field( (string) ( $data['operation_time'] ?? '' ) ), 0, 32 ),
				'wh_code'              => substr( sanitize_text_field( (string) ( $data['wh_code'] ?? '' ) ), 0, 40 ),
				'operate_result_code'  => substr( sanitize_text_field( (string) ( $data['operate_result_code'] ?? '' ) ), 0, 16 ),
				'payload'              => wp_json_encode( $data['payload'] ?? array(), JSON_UNESCAPED_UNICODE ),
				'processed'            => empty( $data['processed'] ) ? 0 : 1,
				'created_at'           => current_time( 'mysql' ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Recent callbacks.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function query_callbacks( int $limit = 40 ): array {
		global $wpdb;

		$table = MyEasyBuy_OMS_Activator::callbacks_table();
		$limit = max( 1, min( 200, $limit ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Counts by status.
	 *
	 * @return array<string, int>
	 */
	public static function status_counts( int $leg = 0 ): array {
		global $wpdb;

		$table = MyEasyBuy_OMS_Activator::orders_table();
		$sql   = "SELECT oms_status, COUNT(*) AS total FROM {$table}";
		if ( $leg > 0 ) {
			$sql .= $wpdb->prepare( ' WHERE leg = %d', $leg );
		}
		$sql .= ' GROUP BY oms_status';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['oms_status'] ] = (int) $row['total'];
		}

		return $out;
	}

	/**
	 * Lookup helper.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function get_by_code( string $column, string $value ): ?array {
		global $wpdb;

		$value = sanitize_text_field( $value );
		if ( '' === $value ) {
			return null;
		}

		$allowed = array( 'customer_hawb_code', 'fpx_track_no', 'service_hawb_code' );
		if ( ! in_array( $column, $allowed, true ) ) {
			return null;
		}

		$table = MyEasyBuy_OMS_Activator::orders_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE {$column} = %s ORDER BY id DESC LIMIT 1", $value ),
			ARRAY_A
		);

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Decode JSON columns.
	 *
	 * @param array<string, mixed> $row Raw.
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		foreach ( array( 'photos', 'one_service_hawb_list', 'piece_list', 'request_payload', 'response_payload' ) as $key ) {
			if ( ! empty( $row[ $key ] ) && is_string( $row[ $key ] ) ) {
				$decoded = json_decode( $row[ $key ], true );
				if ( is_array( $decoded ) ) {
					$row[ $key ] = $decoded;
				}
			}
		}

		return $row;
	}

	/**
	 * Prepare a writable row.
	 *
	 * @param array<string, mixed> $data Input.
	 * @return array<string, mixed>
	 */
	private static function normalize_row( array $data, bool $for_insert = true ): array {
		$now = current_time( 'mysql' );
		$row = array();

		$map = array(
			'leg'                  => 'int',
			'source_type'          => 'text',
			'source_id'            => 'int',
			'group_id'             => 'text',
			'customer_hawb_code'   => 'text',
			'fpx_track_no'         => 'text',
			'service_hawb_code'    => 'text',
			'oms_status'           => 'text',
			'shipping_mode'        => 'text',
			'country_code'         => 'text',
			'weight_g'             => 'int',
			'length_mm'            => 'int',
			'width_mm'             => 'int',
			'height_mm'            => 'int',
			'storage_district'     => 'text',
			'sale_pk_code'         => 'text',
			'last_action_code'     => 'text',
			'last_error'           => 'text',
		);

		foreach ( $map as $key => $type ) {
			if ( ! array_key_exists( $key, $data ) ) {
				continue;
			}
			$row[ $key ] = 'int' === $type ? (int) $data[ $key ] : sanitize_text_field( (string) $data[ $key ] );
		}

		foreach ( array( 'photos', 'one_service_hawb_list', 'piece_list', 'request_payload', 'response_payload' ) as $json_key ) {
			if ( ! array_key_exists( $json_key, $data ) ) {
				continue;
			}
			$value = $data[ $json_key ];
			$row[ $json_key ] = is_string( $value ) ? $value : wp_json_encode( $value, JSON_UNESCAPED_UNICODE );
		}

		if ( $for_insert ) {
			$row['created_at'] = $data['created_at'] ?? $now;
			if ( empty( $row['customer_hawb_code'] ) && ! empty( $data['customer_hawb_code'] ) ) {
				$row['customer_hawb_code'] = sanitize_text_field( (string) $data['customer_hawb_code'] );
			}
		}

		$row['updated_at'] = $now;

		return $row;
	}
}
