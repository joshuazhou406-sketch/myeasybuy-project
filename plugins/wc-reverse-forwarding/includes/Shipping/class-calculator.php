<?php
/**
 * International forwarding rate engine.
 *
 * @package ReverseForwarding
 */

namespace ReverseForwarding\Shipping;

defined( 'ABSPATH' ) || exit;

/**
 * Chargeable weight = max(actual, L*W*H/6000), then 1kg first + 0.5kg additional.
 */
class Calculator {

	public const OPTION_KEY = 'wc_rf_shipping_settings';

	public const DIVISOR = 6000;

	/**
	 * Filterable rate card (CAD-style defaults, store currency at checkout).
	 *
	 * @return array<string, mixed>
	 */
	public static function settings(): array {
		$defaults = array(
			'volumetric_divisor'    => self::DIVISOR,
			'first_weight_kg'       => 1.0,
			'additional_weight_kg'  => 0.5,
			'first_price'           => 28.0,
			'additional_price'      => 9.0,
			'service_fee'           => 6.0,
			'service_fee_per_pkg'   => 2.0,
			'carrier_code'          => '4px',
			'default_country'       => 'CA',
			'default_state'         => 'ON',
			'zones'                 => self::default_zones(),
		);

		$saved = wp_parse_args( (array) get_option( self::OPTION_KEY, array() ), $defaults );

		/**
		 * Filter forwarding rate settings.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $saved Settings.
		 */
		return apply_filters( 'wc_rf_shipping_settings', $saved );
	}

	/**
	 * Province / territory overrides.
	 *
	 * @return array<string, array{first_price: float, additional_price: float}>
	 */
	public static function default_zones(): array {
		return array(
			'ON' => array( 'first_price' => 28.0, 'additional_price' => 9.0 ),
			'QC' => array( 'first_price' => 29.0, 'additional_price' => 9.0 ),
			'BC' => array( 'first_price' => 32.0, 'additional_price' => 10.0 ),
			'AB' => array( 'first_price' => 31.0, 'additional_price' => 10.0 ),
			'MB' => array( 'first_price' => 33.0, 'additional_price' => 10.5 ),
			'SK' => array( 'first_price' => 33.0, 'additional_price' => 10.5 ),
			'NS' => array( 'first_price' => 34.0, 'additional_price' => 11.0 ),
			'NB' => array( 'first_price' => 34.0, 'additional_price' => 11.0 ),
			'NL' => array( 'first_price' => 36.0, 'additional_price' => 12.0 ),
			'PE' => array( 'first_price' => 36.0, 'additional_price' => 12.0 ),
			'YT' => array( 'first_price' => 48.0, 'additional_price' => 16.0 ),
			'NT' => array( 'first_price' => 48.0, 'additional_price' => 16.0 ),
			'NU' => array( 'first_price' => 52.0, 'additional_price' => 18.0 ),
		);
	}

	/**
	 * Build a quote for one or more inbound parcels.
	 *
	 * @param array<int, array<string, mixed>> $packages    Packages with weight/length/width/height.
	 * @param array<string, string>            $destination country, state, postcode.
	 * @return array<string, mixed>
	 */
	public static function quote( array $packages, array $destination = array() ): array {
		$settings    = self::settings();
		$destination = self::normalize_destination( $destination );
		$zone        = self::zone_rates( $destination['state'], $settings );

		$divisor   = max( 1.0, (float) $settings['volumetric_divisor'] );
		$first_kg  = max( 0.001, (float) $settings['first_weight_kg'] );
		$add_kg    = max( 0.001, (float) $settings['additional_weight_kg'] );
		$first_fee = (float) $zone['first_price'];
		$add_fee   = (float) $zone['additional_price'];

		$lines          = array();
		$chargeable_sum = 0.0;

		foreach ( $packages as $package ) {
			$line            = self::package_line( $package, $divisor );
			$lines[]         = $line;
			$chargeable_sum += $line['chargeable_kg'];
		}

		$shipping   = self::tiered_fee( $chargeable_sum, $first_kg, $first_fee, $add_kg, $add_fee );
		$service    = self::service_fee( $packages, $settings );
		$decimals   = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		$shipping   = round( $shipping, $decimals );
		$service    = round( $service, $decimals );
		$extra_steps = $chargeable_sum > $first_kg
			? (int) ceil( ( $chargeable_sum - $first_kg ) / $add_kg )
			: 0;

		$quote = array(
			'destination'       => $destination,
			'carrier_code'      => sanitize_text_field( (string) $settings['carrier_code'] ),
			'packages'          => $lines,
			'package_count'     => count( $lines ),
			'chargeable_kg'     => round( $chargeable_sum, 3 ),
			'shipping_fee'      => $shipping,
			'service_fee'       => $service,
			'total'             => round( $shipping + $service, $decimals ),
			'tier'              => array(
				'first_weight_kg'      => $first_kg,
				'additional_weight_kg' => $add_kg,
				'first_price'          => $first_fee,
				'additional_price'     => $add_fee,
				'additional_steps'     => $extra_steps,
			),
		);

		/**
		 * Filter a calculated forwarding quote.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed>             $quote       Quote.
		 * @param array<int, array<string, mixed>> $packages    Source packages.
		 * @param array<string, string>            $destination Destination.
		 */
		return apply_filters( 'wc_rf_calculator_quote', $quote, $packages, $destination );
	}

	/**
	 * Single-box chargeable weight: max(actual, L*W*H / 6000).
	 *
	 * @param array<string, mixed> $package Package row.
	 * @return array<string, mixed>
	 */
	public static function package_line( array $package, float $divisor = self::DIVISOR ): array {
		$actual     = max( 0.0, (float) ( $package['weight'] ?? 0 ) );
		$length     = max( 0.0, (float) ( $package['length'] ?? 0 ) );
		$width      = max( 0.0, (float) ( $package['width'] ?? 0 ) );
		$height     = max( 0.0, (float) ( $package['height'] ?? 0 ) );
		$volume_cm3 = ( $length > 0 && $width > 0 && $height > 0 ) ? ( $length * $width * $height ) : 0.0;
		$volumetric = $volume_cm3 > 0 ? ( $volume_cm3 / max( 1.0, $divisor ) ) : 0.0;
		$chargeable = max( $actual, $volumetric );

		return array(
			'id'            => isset( $package['id'] ) ? (int) $package['id'] : 0,
			'actual_kg'     => round( $actual, 3 ),
			'volumetric_kg' => round( $volumetric, 3 ),
			'chargeable_kg' => round( $chargeable, 3 ),
			'volume_cm3'    => round( $volume_cm3, 2 ),
		);
	}

	/**
	 * First-weight + additional-step ladder.
	 */
	public static function tiered_fee(
		float $chargeable_kg,
		float $first_kg,
		float $first_price,
		float $additional_kg,
		float $additional_price
	): float {
		if ( $chargeable_kg <= 0 ) {
			return 0.0;
		}

		$fee = $first_price;
		if ( $chargeable_kg > $first_kg ) {
			$steps = (int) ceil( ( $chargeable_kg - $first_kg ) / $additional_kg );
			$fee  += $steps * $additional_price;
		}

		return max( 0.0, $fee );
	}

	/**
	 * Sanitize a Canada-oriented destination.
	 *
	 * @param array<string, string> $destination Destination.
	 * @return array{country: string, state: string, postcode: string}
	 */
	public static function normalize_destination( array $destination ): array {
		$settings = self::settings();
		$country  = strtoupper( sanitize_text_field( (string) ( $destination['country'] ?? $settings['default_country'] ) ) );
		$state    = strtoupper( sanitize_text_field( (string) ( $destination['state'] ?? $settings['default_state'] ) ) );
		$postcode = strtoupper( sanitize_text_field( (string) ( $destination['postcode'] ?? '' ) ) );
		$postcode = (string) preg_replace( '/\s+/', '', $postcode );

		if ( '' === $country ) {
			$country = 'CA';
		}
		if ( '' === $state ) {
			$state = (string) $settings['default_state'];
		}

		return array(
			'country'  => substr( $country, 0, 2 ),
			'state'    => substr( $state, 0, 8 ),
			'postcode' => substr( $postcode, 0, 12 ),
		);
	}

	/**
	 * @param array<string, mixed> $settings Settings.
	 * @return array{first_price: float, additional_price: float}
	 */
	private static function zone_rates( string $state, array $settings ): array {
		$zones   = isset( $settings['zones'] ) && is_array( $settings['zones'] ) ? $settings['zones'] : array();
		$default = array(
			'first_price'      => (float) $settings['first_price'],
			'additional_price' => (float) $settings['additional_price'],
		);

		if ( isset( $zones[ $state ] ) && is_array( $zones[ $state ] ) ) {
			return wp_parse_args( $zones[ $state ], $default );
		}

		return $default;
	}

	/**
	 * Flat packing fee plus per-package value-added charge.
	 *
	 * @param array<int, array<string, mixed>> $packages Packages.
	 * @param array<string, mixed>             $settings Settings.
	 */
	private static function service_fee( array $packages, array $settings ): float {
		$base = (float) $settings['service_fee'] + ( count( $packages ) * (float) $settings['service_fee_per_pkg'] );

		/**
		 * Filter value-added service fees.
		 *
		 * @since 1.0.0
		 *
		 * @param float                            $base     Calculated service fee.
		 * @param array<int, array<string, mixed>> $packages Packages.
		 * @param array<string, mixed>             $settings Settings.
		 */
		return max( 0.0, (float) apply_filters( 'wc_rf_service_fee', $base, $packages, $settings ) );
	}
}
