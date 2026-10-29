<?php
/**
 * Signed HTTP client for 4PX / Cainiao OMS standard order APIs.
 *
 * @package MyEasyBuy_OMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Calls /api/standard/order/* or the open-platform router.
 */
class MyEasyBuy_OMS_Client {

	public const PATH_CREATE        = '/api/standard/order/create';
	public const PATH_UPDATE        = '/api/standard/order/update';
	public const PATH_CANCEL        = '/api/standard/order/cancel';
	public const PATH_SHELF_RETURN  = '/api/standard/order/shelfReturn';

	/**
	 * POST create.
	 *
	 * @param array<string, mixed> $body Business body.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create_order( array $body ) {
		return $this->request( 'create', $body );
	}

	/**
	 * POST update.
	 *
	 * @param array<string, mixed> $body Business body.
	 * @return array<string, mixed>|WP_Error
	 */
	public function update_order( array $body ) {
		return $this->request( 'update', $body );
	}

	/**
	 * POST cancel.
	 *
	 * @param array<string, mixed> $body Business body.
	 * @return array<string, mixed>|WP_Error
	 */
	public function cancel_order( array $body ) {
		return $this->request( 'cancel', $body );
	}

	/**
	 * POST shelf return.
	 *
	 * @param array<string, mixed> $body Business body.
	 * @return array<string, mixed>|WP_Error
	 */
	public function shelf_return( array $body ) {
		return $this->request( 'shelf_return', $body );
	}

	/**
	 * Signed request.
	 *
	 * @param string               $action create|update|cancel|shelf_return
	 * @param array<string, mixed> $body   JSON body.
	 * @return array<string, mixed>|WP_Error
	 */
	public function request( string $action, array $body ) {
		$settings = MyEasyBuy_OMS_Service::instance()->get_settings();
		$creds    = $this->credentials( $settings );

		if ( is_wp_error( $creds ) ) {
			return $creds;
		}

		$body_json = wp_json_encode( $body, JSON_UNESCAPED_UNICODE );
		if ( false === $body_json ) {
			return new WP_Error( 'meb_oms_json', __( 'OMS 请求体编码失败。', 'meb-oms' ) );
		}

		$paths = array(
			'create'       => self::PATH_CREATE,
			'update'       => self::PATH_UPDATE,
			'cancel'       => self::PATH_CANCEL,
			'shelf_return' => self::PATH_SHELF_RETURN,
		);
		$path = $paths[ $action ] ?? self::PATH_CREATE;

		$methods = array(
			'create'       => (string) $settings['method_create'],
			'update'       => (string) $settings['method_update'],
			'cancel'       => (string) $settings['method_cancel'],
			'shelf_return' => (string) $settings['method_shelf_return'],
		);

		$common = array(
			'app_key'   => $creds['app_key'],
			'v'         => (string) $settings['api_version'],
			'timestamp' => (string) (int) round( microtime( true ) * 1000 ),
			'format'    => 'json',
			'language'  => 'cn',
		);

		if ( 'router' === $settings['transport'] ) {
			$common['method'] = $methods[ $action ] ?: ltrim( $path, '/' );
			$url              = rtrim( $this->base_url( $settings ), '/' ) . '/router/api/service';
		} else {
			$url = rtrim( $this->base_url( $settings ), '/' ) . $path;
		}

		if ( '' !== $creds['access_token'] ) {
			$common['access_token'] = $creds['access_token'];
		}

		$common['sign'] = $this->sign( $common, $body_json, $creds['app_secret'] );
		$url            = add_query_arg( $common, $url );

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 45,
				'headers' => array(
					'Content-Type' => 'application/json; charset=utf-8',
					'Accept'       => 'application/json',
				),
				'body'    => $body_json,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code >= 400 ) {
			return new WP_Error(
				'meb_oms_http',
				sprintf(
					/* translators: %d: HTTP status */
					__( 'OMS HTTP 错误：%d', 'meb-oms' ),
					$code
				),
				array( 'body' => $raw )
			);
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'meb_oms_invalid_json', __( 'OMS 返回了无效 JSON。', 'meb-oms' ), array( 'body' => $raw ) );
		}

		if ( ! $this->is_success( $data ) ) {
			$message = (string) ( $data['msg'] ?? $data['message'] ?? $data['error'] ?? __( 'OMS 返回失败。', 'meb-oms' ) );
			$err_code = (string) ( $data['code'] ?? $data['errorCode'] ?? '' );
			if ( '' !== $err_code ) {
				$message = $err_code . ' ' . $message;
			}

			return new WP_Error( 'meb_oms_api', $message, array( 'response' => $data ) );
		}

		return $data;
	}

	/**
	 * Open-test vs production host.
	 *
	 * @param array<string, mixed> $settings Settings.
	 */
	public function base_url( array $settings ): string {
		$custom = trim( (string) ( $settings['api_base_url'] ?? '' ) );
		if ( '' !== $custom ) {
			return untrailingslashit( $custom );
		}

		return ! empty( $settings['sandbox_mode'] )
			? 'https://open-test.4px.com'
			: 'https://open.4px.com';
	}

	/**
	 * Shared or dedicated credentials.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return array{app_key:string,app_secret:string,access_token:string}|WP_Error
	 */
	public function credentials( array $settings ) {
		$app_key    = trim( (string) ( $settings['app_key'] ?? '' ) );
		$app_secret = trim( (string) ( $settings['app_secret'] ?? '' ) );
		$token      = trim( (string) ( $settings['access_token'] ?? '' ) );

		if ( ! empty( $settings['use_shared_credentials'] ) && class_exists( 'MyEasyBuy_4PX_Service' ) ) {
			$shared     = MyEasyBuy_4PX_Service::instance()->get_settings();
			$app_key    = $app_key ?: trim( (string) ( $shared['4px_app_key'] ?? '' ) );
			$app_secret = $app_secret ?: trim( (string) ( $shared['4px_app_secret'] ?? '' ) );
			$token      = $token ?: trim( (string) ( $shared['4px_access_token'] ?? '' ) );
		}

		if ( '' === $app_key || '' === $app_secret ) {
			return new WP_Error( 'meb_oms_creds', __( '请先配置 4PX App Key / Secret。', 'meb-oms' ) );
		}

		return array(
			'app_key'      => $app_key,
			'app_secret'   => $app_secret,
			'access_token' => $token,
		);
	}

	/**
	 * Accept both documented success codes 0 and 1.
	 *
	 * @param array<string, mixed> $data Response.
	 */
	public function is_success( array $data ): bool {
		$candidates = array(
			$data['code'] ?? null,
			$data['result'] ?? null,
			$data['success'] ?? null,
		);

		foreach ( $candidates as $flag ) {
			if ( null === $flag ) {
				continue;
			}
			$value = strtolower( (string) $flag );
			if ( in_array( $value, array( '0', '1', 'true', 'success', '2' ), true ) ) {
				return true;
			}
		}

		return isset( $data['data'] ) && ! isset( $data['error'] );
	}

	/**
	 * 4PX MD5 sign: sorted key+value + body + secret.
	 *
	 * @param array<string, mixed> $params Query params.
	 */
	public function sign( array $params, string $body, string $secret ): string {
		unset( $params['sign'], $params['access_token'], $params['language'] );
		ksort( $params, SORT_STRING );

		$concat = '';
		foreach ( $params as $key => $value ) {
			if ( null === $value || '' === (string) $value ) {
				continue;
			}
			$concat .= (string) $key . (string) $value;
		}

		$concat .= $body . $secret;

		/**
		 * Filter OMS sign string.
		 *
		 * @since 1.0.0
		 *
		 * @param string               $concat Raw string.
		 * @param array<string, mixed> $params Params.
		 * @param string               $body   JSON body.
		 */
		$concat = (string) apply_filters( 'myeasybuy_oms_sign_string', $concat, $params, $body );

		return md5( $concat );
	}
}
