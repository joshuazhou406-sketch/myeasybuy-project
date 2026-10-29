<?php
/**
 * Buyer warehouse codes and the domestic warehouse address.
 *
 * @package ReverseForwarding
 */

namespace ReverseForwarding\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Warehouse code assignment and address card data (rendered by Forwarding_Center).
 */
class My_Account {

	public const META_WAREHOUSE_CODE = '_wc_rf_warehouse_code';

	public const WAREHOUSE_CODE_BASE = 8000;

	public const OPTION_WAREHOUSE = 'wc_rf_warehouse_address';

	/**
	 * Assign and return the buyer's unique warehouse code (CA-{8000 + user_id}).
	 */
	public static function ensure_warehouse_code( int $user_id = 0 ): string {
		$user_id = $user_id > 0 ? $user_id : get_current_user_id();
		if ( $user_id <= 0 ) {
			return '';
		}

		$stored = sanitize_text_field( (string) get_user_meta( $user_id, self::META_WAREHOUSE_CODE, true ) );
		if ( '' !== $stored ) {
			return $stored;
		}

		$code = self::generate_warehouse_code( $user_id );
		update_user_meta( $user_id, self::META_WAREHOUSE_CODE, $code );

		return $code;
	}

	/**
	 * Build the generated warehouse code. Does not persist.
	 */
	public static function generate_warehouse_code( int $user_id ): string {
		$code = sprintf( 'CA-%d', self::WAREHOUSE_CODE_BASE + $user_id );

		/**
		 * Filter a newly generated warehouse code before it is saved.
		 *
		 * @since 1.0.0
		 *
		 * @param string $code    Warehouse code.
		 * @param int    $user_id User ID.
		 */
		return sanitize_text_field( (string) apply_filters( 'wc_rf_warehouse_code', $code, $user_id ) );
	}

	/**
	 * Physical China warehouse address (same source as 代采采购仓).
	 *
	 * Reads `myeasybuy_procurement_warehouse` from 4PX 物流对接. Falls back to
	 * the legacy RF option, then Shenzhen defaults.
	 *
	 * @return array<string, string>
	 */
	public static function get_warehouse(): array {
		$defaults = array(
			'recipient_base' => '深圳集运仓',
			'company'        => 'MyEasyBuy Warehouse',
			'address_1'      => '宝安区福永街道怀德社区怀德南路',
			'address_2'      => '',
			'city'           => '深圳市',
			'state'          => '广东省',
			'postcode'       => '518103',
			'country'        => 'CN',
			'phone'          => '',
		);

		$procurement_key = class_exists( 'MyEasyBuy_Procurement' )
			? \MyEasyBuy_Procurement::OPTION_WAREHOUSE
			: 'myeasybuy_procurement_warehouse';

		$procurement = (array) get_option( $procurement_key, array() );
		$legacy      = (array) get_option( self::OPTION_WAREHOUSE, array() );
		$source      = self::has_address_fields( $procurement ) ? $procurement : $legacy;
		$saved       = wp_parse_args( $source, $defaults );

		if ( '' !== trim( (string) ( $saved['first_name'] ?? '' ) ) ) {
			$saved['recipient_base'] = (string) $saved['first_name'];
		}

		foreach ( $saved as $key => $value ) {
			$saved[ $key ] = sanitize_text_field( (string) $value );
		}

		/**
		 * Filter the domestic consolidation warehouse address.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, string> $saved Warehouse fields.
		 */
		return apply_filters( 'wc_rf_warehouse_address', $saved );
	}

	/**
	 * Whether an option row has a usable street or city.
	 *
	 * @param array<string, mixed> $fields Raw option.
	 */
	private static function has_address_fields( array $fields ): bool {
		$street = trim( (string) ( $fields['address_1'] ?? '' ) );
		$city   = trim( (string) ( $fields['city'] ?? '' ) );

		return '' !== $street || '' !== $city;
	}

	/**
	 * Template data for the warehouse receiving card.
	 *
	 * @return array<string, string>
	 */
	public static function get_address_card_data( int $user_id = 0 ): array {
		$user_id = $user_id > 0 ? $user_id : get_current_user_id();
		$code    = self::ensure_warehouse_code( $user_id );
		$wh      = self::get_warehouse();

		$recipient = trim( $wh['recipient_base'] . ' - ' . $code );
		$street    = trim(
			$wh['state'] . $wh['city'] . $wh['address_1'] . ( '' !== $wh['address_2'] ? ' ' . $wh['address_2'] : '' )
		);

		$copy_lines = array_filter(
			array(
				sprintf( /* translators: %s: recipient including warehouse code */ __( '收件人：%s', 'wc-rf' ), $recipient ),
				'' !== $wh['phone'] ? sprintf( /* translators: %s: phone */ __( '电话：%s', 'wc-rf' ), $wh['phone'] ) : '',
				sprintf( /* translators: %s: street address */ __( '地址：%s', 'wc-rf' ), $street ),
				sprintf( /* translators: %s: postcode */ __( '邮编：%s', 'wc-rf' ), $wh['postcode'] ),
			)
		);

		$data = array(
			'warehouse_code' => $code,
			'recipient'      => $recipient,
			'company'        => $wh['company'],
			'phone'          => $wh['phone'],
			'address'        => $street,
			'postcode'       => $wh['postcode'],
			'country'        => $wh['country'],
			'copy_all'       => implode( "\n", $copy_lines ),
		);

		/**
		 * Filter warehouse address card template arguments.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, string> $data    Template args.
		 * @param int                   $user_id User ID.
		 */
		return apply_filters( 'wc_rf_warehouse_address_card', $data, $user_id );
	}
}
