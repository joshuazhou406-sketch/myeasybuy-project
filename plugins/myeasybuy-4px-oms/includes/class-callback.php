<?php
/**
 * HTTPS callback gateway for Cainiao / OMS warehouse events.
 *
 * @package MyEasyBuy_OMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * REST: POST /wp-json/myeasybuy-oms/v1/callback
 */
class MyEasyBuy_OMS_Callback {

	public const REST_NS = 'myeasybuy-oms/v1';

	/**
	 * Register routes.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Routes.
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::REST_NS,
			'/callback',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'handle' ),
					'permission_callback' => array( __CLASS__, 'permission' ),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'health' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * Optional token check.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function permission( WP_REST_Request $request ): bool {
		$settings = MyEasyBuy_OMS_Service::instance()->get_settings();
		$token    = trim( (string) ( $settings['callback_token'] ?? '' ) );
		if ( '' === $token ) {
			return true;
		}

		$given = (string) $request->get_param( 'token' );
		if ( '' === $given ) {
			$given = (string) $request->get_header( 'x-oms-token' );
		}

		return hash_equals( $token, $given );
	}

	/**
	 * Health check for OMS URL validation.
	 *
	 * @return WP_REST_Response
	 */
	public static function health() {
		return new WP_REST_Response(
			array(
				'code'    => '1',
				'message' => 'success',
				'data'    => array(
					'plugin'  => 'myeasybuy-4px-oms',
					'version' => MEB_OMS_VERSION,
				),
			),
			200
		);
	}

	/**
	 * Ingest warehouse callback.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle( WP_REST_Request $request ) {
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) || empty( $payload ) ) {
			$payload = $request->get_params();
		}
		if ( isset( $payload['data'] ) && is_string( $payload['data'] ) ) {
			$decoded = json_decode( $payload['data'], true );
			if ( is_array( $decoded ) ) {
				$payload = array_merge( $payload, $decoded );
			}
		}

		/**
		 * Filter inbound OMS callback payload.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $payload Payload.
		 * @param WP_REST_Request      $request Request.
		 */
		$payload = apply_filters( 'myeasybuy_oms_callback_payload', is_array( $payload ) ? $payload : array(), $request );

		$processed = MyEasyBuy_OMS_Service::instance()->apply_callback( $payload );

		MyEasyBuy_OMS_Repository::log_callback(
			array(
				'order_code'          => (string) ( $payload['orderCode'] ?? $payload['fpxTrackNo'] ?? '' ),
				'action_code'         => (string) ( $payload['operationActionCode'] ?? $payload['actionCode'] ?? '' ),
				'msg_type'            => (string) ( $payload['msgType'] ?? '' ),
				'operation_time'      => (string) ( $payload['operationTime'] ?? '' ),
				'wh_code'             => (string) ( $payload['whCode'] ?? '' ),
				'operate_result_code' => (string) ( $payload['operateResultCode'] ?? '' ),
				'payload'             => $payload,
				'processed'           => ! empty( $processed['found'] ),
			)
		);

		/**
		 * Fires after an OMS callback is stored.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $payload   Raw payload.
		 * @param array<string, mixed> $processed Apply result.
		 */
		do_action( 'myeasybuy_oms_callback_received', $payload, $processed );

		return new WP_REST_Response(
			array(
				'code'    => '1',
				'message' => 'success',
				'data'    => array(),
			),
			200
		);
	}
}
