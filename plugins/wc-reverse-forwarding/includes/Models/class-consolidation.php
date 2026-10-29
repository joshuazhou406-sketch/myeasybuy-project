<?php
/**
 * Current-user / order scoped CRUD for wp_rf_consolidations.
 *
 * @package ReverseForwarding
 */

namespace ReverseForwarding\Models;

use ReverseForwarding\Activator;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Consolidation (outbound) jobs.
 */
class Consolidation {

	public const STATUS_DRAFT     = 'draft';
	public const STATUS_PAID      = 'paid';
	public const STATUS_SHIPPED   = 'shipped';
	public const STATUS_CANCELLED = 'cancelled';

	/**
	 * Full table name.
	 */
	public static function table(): string {
		return Activator::consolidations_table();
	}

	/**
	 * Insert a draft job.
	 *
	 * @param array<string, mixed> $data Row data.
	 * @return int|WP_Error
	 */
	public static function create_draft( array $data ) {
		global $wpdb;

		$user_id = absint( $data['user_id'] ?? 0 );
		if ( $user_id <= 0 ) {
			return new WP_Error( 'wc_rf_auth', __( '无法创建合箱任务：用户无效。', 'wc-rf' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			self::table(),
			array(
				'order_id'           => 0,
				'user_id'            => $user_id,
				'carrier_code'       => substr( sanitize_text_field( (string) ( $data['carrier_code'] ?? '4px' ) ), 0, 50 ),
				'chargeable_weight'  => (float) ( $data['chargeable_weight'] ?? 0 ),
				'shipping_fee'       => (float) ( $data['shipping_fee'] ?? 0 ),
				'service_fee'        => (float) ( $data['service_fee'] ?? 0 ),
				'tracking_number'    => '',
				'status'             => self::STATUS_DRAFT,
			),
			array( '%d', '%d', '%s', '%f', '%f', '%f', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'wc_rf_db', __( '创建合箱任务失败。', 'wc-rf' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;

		if ( $id <= 0 ) {
			return null;
		}

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ),
			ARRAY_A
		);

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Fetch if it belongs to the given user.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get_for_user( int $id, int $user_id ): ?array {
		$row = self::get( $id );
		if ( null === $row || (int) $row['user_id'] !== $user_id ) {
			return null;
		}

		return $row;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get_by_order( int $order_id ): ?array {
		global $wpdb;

		if ( $order_id <= 0 ) {
			return null;
		}

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d ORDER BY id DESC LIMIT 1", $order_id ),
			ARRAY_A
		);

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Update quoted totals on a draft.
	 *
	 * @param array<string, mixed> $quote Calculator quote.
	 */
	public static function update_quote( int $id, array $quote ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			self::table(),
			array(
				'carrier_code'      => substr( sanitize_text_field( (string) ( $quote['carrier_code'] ?? '4px' ) ), 0, 50 ),
				'chargeable_weight' => (float) ( $quote['chargeable_kg'] ?? 0 ),
				'shipping_fee'      => (float) ( $quote['shipping_fee'] ?? 0 ),
				'service_fee'       => (float) ( $quote['service_fee'] ?? 0 ),
			),
			array(
				'id'     => $id,
				'status' => self::STATUS_DRAFT,
			),
			array( '%s', '%f', '%f', '%f' ),
			array( '%d', '%s' )
		);

		return false !== $updated;
	}

	/**
	 * Bind a WooCommerce order to this draft.
	 */
	public static function set_order_id( int $id, int $order_id, int $user_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			self::table(),
			array( 'order_id' => $order_id ),
			array(
				'id'      => $id,
				'user_id' => $user_id,
			),
			array( '%d' ),
			array( '%d', '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Mark paid after WooCommerce settlement.
	 */
	public static function mark_paid( int $id, int $order_id, int $user_id ): bool {
		global $wpdb;

		$row = self::get_for_user( $id, $user_id );
		if ( null === $row ) {
			return false;
		}

		if ( self::STATUS_PAID === $row['status'] ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			self::table(),
			array(
				'order_id' => $order_id,
				'status'   => self::STATUS_PAID,
			),
			array(
				'id'      => $id,
				'user_id' => $user_id,
			),
			array( '%d', '%s' ),
			array( '%d', '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Record 4PX / international tracking after warehouse outbound.
	 */
	public static function mark_shipped( int $id, string $tracking_number, string $carrier_code = '4px' ): bool {
		global $wpdb;

		$row = self::get( $id );
		if ( null === $row ) {
			return false;
		}

		$tracking_number = sanitize_text_field( $tracking_number );
		$carrier_code    = substr( sanitize_text_field( $carrier_code ), 0, 50 );
		if ( '' === $tracking_number ) {
			return false;
		}

		if ( self::STATUS_SHIPPED === $row['status'] && $tracking_number === (string) $row['tracking_number'] ) {
			return true;
		}

		if ( ! in_array( $row['status'], array( self::STATUS_PAID, self::STATUS_SHIPPED ), true ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			self::table(),
			array(
				'tracking_number' => substr( $tracking_number, 0, 100 ),
				'carrier_code'    => '' !== $carrier_code ? $carrier_code : '4px',
				'status'          => self::STATUS_SHIPPED,
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Cancel leftover drafts for a buyer and unlock their packages.
	 */
	public static function abandon_user_drafts( int $user_id ): void {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return;
		}

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE user_id = %d AND status = %s",
				$user_id,
				self::STATUS_DRAFT
			)
		);

		if ( ! is_array( $ids ) ) {
			return;
		}

		foreach ( $ids as $id ) {
			Package::detach_from_consolidation( (int) $id, $user_id );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array( 'status' => self::STATUS_CANCELLED ),
				array(
					'id'     => (int) $id,
					'status' => self::STATUS_DRAFT,
				),
				array( '%s' ),
				array( '%d', '%s' )
			);
		}
	}

	/**
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		$row['id']                = (int) $row['id'];
		$row['order_id']          = (int) $row['order_id'];
		$row['user_id']           = (int) $row['user_id'];
		$row['chargeable_weight'] = (float) $row['chargeable_weight'];
		$row['shipping_fee']      = (float) $row['shipping_fee'];
		$row['service_fee']       = (float) $row['service_fee'];

		return $row;
	}
}
