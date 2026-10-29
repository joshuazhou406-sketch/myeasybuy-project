<?php
/**
 * Customer My Account — international logistics tracking timeline.
 *
 * @package MyEasyBuy_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Surfaces 4PX (and compatible) tracking on the customer order page after
 * warehouse staff confirm international shipment.
 */
class MyEasyBuy_Customer_Tracking {

	public const STALE_SECONDS = 900;

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'render_order_tracking_panel' ), 8 );
		add_filter( 'woocommerce_my_account_my_orders_actions', array( __CLASS__, 'add_orders_action' ), 20, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 125 );
		add_action( 'wp_ajax_myeasybuy_refresh_tracking', array( __CLASS__, 'ajax_refresh_tracking' ) );
	}

	/**
	 * Enqueue assets on My Account / order received.
	 */
	public static function enqueue_assets(): void {
		$on_account  = function_exists( 'is_account_page' ) && is_account_page();
		$on_received = function_exists( 'is_order_received_page' ) && is_order_received_page();
		if ( ! $on_account && ! $on_received ) {
			return;
		}

		wp_enqueue_style(
			'myeasybuy-customer-tracking',
			MYEASYBUY_CORE_URL . 'assets/css/customer-tracking.css',
			array( 'myeasybuy-storefront-palette' ),
			MYEASYBUY_CORE_VERSION
		);

		wp_enqueue_script(
			'myeasybuy-customer-tracking',
			MYEASYBUY_CORE_URL . 'assets/js/customer-tracking.js',
			array( 'jquery' ),
			MYEASYBUY_CORE_VERSION,
			true
		);

		wp_localize_script(
			'myeasybuy-customer-tracking',
			'myeasybuyTracking',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'myeasybuy_refresh_tracking' ),
				'i18n'         => array(
					'refreshing' => __( '正在查询 4PX 轨迹…', 'myeasybuy-core' ),
					'copied'     => __( '已复制单号', 'myeasybuy-core' ),
					'copyFail'   => __( '复制失败，请手动选择单号。', 'myeasybuy-core' ),
					'empty'      => __( '暂无轨迹节点。包裹交运后通常会稍后出现国内与国际全程记录。', 'myeasybuy-core' ),
				),
			)
		);
	}

	/**
	 * Add “物流状态” on the My Account orders list after international dispatch.
	 *
	 * @param array<string, array<string, string>> $actions Actions.
	 * @param WC_Order                             $order   Order.
	 * @return array<string, array<string, string>>
	 */
	public static function add_orders_action( array $actions, $order ): array {
		if ( ! $order instanceof WC_Order ) {
			return $actions;
		}

		if ( '' === self::get_tracking_number( $order ) ) {
			return $actions;
		}

		$actions['myeasybuy-tracking'] = array(
			'url'  => $order->get_view_order_url() . '#myeasybuy-int-tracking',
			'name' => __( '物流状态', 'myeasybuy-core' ),
		);

		return $actions;
	}

	/**
	 * Order view: international tracking timeline.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function render_order_tracking_panel( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		if ( ! self::current_user_can_view_order( $order ) ) {
			return;
		}

		$tracking = self::get_tracking_number( $order );
		if ( '' === $tracking ) {
			return;
		}

		$service = MyEasyBuy_4PX_Service::instance();
		$payload = $service->get_order_tracking_display( $order, false );
		$needs   = self::needs_refresh( $payload );

		self::render_panel_markup( $order, $payload, $needs );
	}

	/**
	 * AJAX: refresh tracking from 4PX for the order owner.
	 */
	public static function ajax_refresh_tracking(): void {
		check_ajax_referer( 'myeasybuy_refresh_tracking', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( '请先登录。', 'myeasybuy-core' ) ), 401 );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order    = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			wp_send_json_error( array( 'message' => __( '订单不存在。', 'myeasybuy-core' ) ), 404 );
		}

		if ( ! self::current_user_can_view_order( $order ) ) {
			wp_send_json_error( array( 'message' => __( '无权查看该订单。', 'myeasybuy-core' ) ), 403 );
		}

		if ( '' === self::get_tracking_number( $order ) ) {
			wp_send_json_error( array( 'message' => __( '该订单尚未录入国际追踪单号。', 'myeasybuy-core' ) ), 400 );
		}

		$service = MyEasyBuy_4PX_Service::instance();
		$payload = $service->get_order_tracking_display( $order, true );

		wp_send_json_success(
			array(
				'payload'      => self::public_payload( $payload ),
				'timelineHtml' => self::get_timeline_html( $payload['tracks'] ),
				'latestHtml'   => self::get_latest_html( $payload ),
				'fetchedLabel' => self::format_fetched_at( (string) $payload['fetched_at'] ),
			)
		);
	}

	/**
	 * Tracking number stored after warehouse confirms international shipping.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function get_tracking_number( WC_Order $order ): string {
		$tracking = (string) $order->get_meta( '_myeasybuy_int_tracking_number', true );
		if ( '' === $tracking ) {
			$tracking = (string) $order->get_meta( MyEasyBuy_4PX_Service::META_4PX_ORDER_NO, true );
		}

		return sanitize_text_field( $tracking );
	}

	/**
	 * @param WC_Order $order Order.
	 */
	private static function current_user_can_view_order( WC_Order $order ): bool {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		$user_id = get_current_user_id();
		if ( $user_id > 0 && (int) $order->get_customer_id() === $user_id ) {
			return true;
		}

		// Guest thank-you / order-pay key.
		$order_key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $order_key && hash_equals( (string) $order->get_order_key(), $order_key ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Whether cached tracks should be refreshed from 4PX in the background.
	 *
	 * @param array<string, mixed> $payload Display payload.
	 */
	private static function needs_refresh( array $payload ): bool {
		if ( empty( $payload['uses_4px'] ) || empty( $payload['is_configured'] ) ) {
			return false;
		}

		if ( ! empty( $payload['is_delivered'] ) ) {
			return false;
		}

		$fetched = (string) ( $payload['fetched_at'] ?? '' );
		if ( '' === $fetched ) {
			return true;
		}

		$ts = strtotime( $fetched );
		if ( false === $ts ) {
			return true;
		}

		return ( time() - $ts ) > self::STALE_SECONDS;
	}

	/**
	 * JSON-safe subset for the front end.
	 *
	 * @param array<string, mixed> $payload Display payload.
	 * @return array<string, mixed>
	 */
	private static function public_payload( array $payload ): array {
		$latest = is_array( $payload['latest'] ?? null ) ? $payload['latest'] : array();
		$tracks = is_array( $payload['tracks'] ?? null ) ? $payload['tracks'] : array();

		return array(
			'tracking_number' => (string) ( $payload['tracking_number'] ?? '' ),
			'carrier'         => (string) ( $payload['carrier'] ?? '' ),
			'latest'          => $latest,
			'tracks'          => $tracks,
			'fetched_at'      => (string) ( $payload['fetched_at'] ?? '' ),
			'public_url'      => (string) ( $payload['public_url'] ?? '' ),
			'error'           => (string) ( $payload['error'] ?? '' ),
			'is_delivered'    => ! empty( $payload['is_delivered'] ),
		);
	}

	/**
	 * @param WC_Order             $order   Order.
	 * @param array<string, mixed> $payload Display payload.
	 * @param bool                 $needs   Auto-refresh on load.
	 */
	private static function render_panel_markup( WC_Order $order, array $payload, bool $needs ): void {
		$tracking = (string) ( $payload['tracking_number'] ?? '' );
		$carrier  = (string) ( $payload['carrier'] ?? '4PX' );
		$public   = (string) ( $payload['public_url'] ?? '' );
		$tracks   = is_array( $payload['tracks'] ?? null ) ? $payload['tracks'] : array();
		$error    = (string) ( $payload['error'] ?? '' );

		echo '<section id="myeasybuy-int-tracking" class="myeasybuy-tracking-panel woocommerce-order-details" data-order-id="' . esc_attr( (string) $order->get_id() ) . '" data-needs-refresh="' . ( $needs ? '1' : '0' ) . '">';
		echo '<h2 class="woocommerce-order-details__title">' . esc_html__( '国际物流轨迹', 'myeasybuy-core' ) . '</h2>';
		echo '<p class="myeasybuy-tracking-panel__intro">' . esc_html__( '仓储确认国际发货后，可在此查看 4PX 国内与国际全程轨迹。', 'myeasybuy-core' ) . '</p>';

		echo '<table class="woocommerce-table shop_table myeasybuy-tracking-panel__table"><tbody>';
		echo '<tr><th>' . esc_html__( '物流商', 'myeasybuy-core' ) . '</th><td>' . esc_html( $carrier ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( '追踪单号', 'myeasybuy-core' ) . '</th><td>';
		echo '<code class="myeasybuy-tracking-panel__number">' . esc_html( $tracking ) . '</code> ';
		echo '<button type="button" class="button myeasybuy-tracking-copy" data-copy="' . esc_attr( $tracking ) . '">' . esc_html__( '复制', 'myeasybuy-core' ) . '</button> ';
		if ( $public ) {
			printf(
				'<a class="myeasybuy-tracking-panel__official" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
				esc_url( $public ),
				esc_html__( '在 4PX 官网查询', 'myeasybuy-core' )
			);
		}
		echo '</td></tr>';
		echo '<tr><th>' . esc_html__( '最新状态', 'myeasybuy-core' ) . '</th><td class="myeasybuy-tracking-latest">';
		echo self::get_latest_html( $payload ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper.
		echo '</td></tr>';
		echo '</tbody></table>';

		echo '<div class="myeasybuy-tracking-toolbar">';
		if ( ! empty( $payload['uses_4px'] ) && ! empty( $payload['is_configured'] ) ) {
			echo '<button type="button" class="button myeasybuy-tracking-refresh">' . esc_html__( '刷新轨迹', 'myeasybuy-core' ) . '</button>';
		}
		echo '<small class="myeasybuy-tracking-fetched">' . esc_html( self::format_fetched_at( (string) ( $payload['fetched_at'] ?? '' ) ) ) . '</small>';
		echo '</div>';

		if ( $error ) {
			echo '<p class="myeasybuy-tracking-error">' . esc_html( $error ) . '</p>';
		}

		echo '<ol class="myeasybuy-tracking-timeline">';
		echo self::get_timeline_html( $tracks ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper.
		echo '</ol>';

		echo '</section>';
	}

	/**
	 * @param array<string, mixed> $payload Display payload.
	 */
	private static function get_latest_html( array $payload ): string {
		$latest = is_array( $payload['latest'] ?? null ) ? $payload['latest'] : array();
		$desc   = (string) ( $latest['description'] ?? '' );
		$loc    = (string) ( $latest['location'] ?? '' );
		$when   = self::format_occurred_at( (string) ( $latest['occurred_at'] ?? '' ) );

		if ( '' === $desc && empty( $payload['tracks'] ) ) {
			return esc_html__( '运单已生成，轨迹同步中。', 'myeasybuy-core' );
		}

		$parts = array_filter( array( $desc, $loc, $when ) );
		return esc_html( implode( ' · ', $parts ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $tracks Normalized tracks (newest first).
	 */
	private static function get_timeline_html( array $tracks ): string {
		if ( empty( $tracks ) ) {
			return '<li class="myeasybuy-tracking-timeline__empty">' . esc_html__( '暂无轨迹节点。包裹交运后通常会稍后出现国内与国际全程记录。', 'myeasybuy-core' ) . '</li>';
		}

		$html = '';
		foreach ( $tracks as $index => $track ) {
			if ( ! is_array( $track ) ) {
				continue;
			}

			$desc = (string) ( $track['description'] ?? '' );
			$loc  = (string) ( $track['location'] ?? '' );
			$when = self::format_occurred_at( (string) ( $track['occurred_at'] ?? '' ) );
			$code = (string) ( $track['track_code'] ?? '' );

			$current = 0 === (int) $index ? ' is-current' : '';
			$html   .= '<li class="myeasybuy-tracking-timeline__item' . esc_attr( $current ) . '">';
			if ( $when ) {
				$html .= '<time class="myeasybuy-tracking-timeline__time">' . esc_html( $when ) . '</time>';
			}
			if ( $loc ) {
				$html .= '<span class="myeasybuy-tracking-timeline__loc">' . esc_html( $loc ) . '</span>';
			}
			if ( $desc ) {
				$html .= '<p class="myeasybuy-tracking-timeline__desc">' . esc_html( $desc ) . '</p>';
			}
			if ( $code ) {
				$html .= '<span class="myeasybuy-tracking-timeline__code">' . esc_html( $code ) . '</span>';
			}
			$html .= '</li>';
		}

		return $html;
	}

	/**
	 * @param string $value ISO8601 or 4PX datetime.
	 */
	public static function format_occurred_at( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		// 4PX occurDatetime is usually already a local wall clock (CN). Keep it.
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})/', $value, $m ) ) {
			return $m[1] . ' ' . $m[2];
		}

		$ts = strtotime( $value );
		if ( false === $ts ) {
			return $value;
		}

		return (string) wp_date( 'Y-m-d H:i', $ts );
	}

	/**
	 * @param string $fetched_at ISO8601.
	 */
	private static function format_fetched_at( string $fetched_at ): string {
		if ( '' === $fetched_at ) {
			return __( '尚未从 4PX 同步轨迹', 'myeasybuy-core' );
		}

		$ts = strtotime( $fetched_at );
		if ( false === $ts ) {
			return __( '尚未从 4PX 同步轨迹', 'myeasybuy-core' );
		}

		return sprintf(
			/* translators: %s: local datetime */
			__( '轨迹更新于 %s', 'myeasybuy-core' ),
			(string) wp_date( 'Y-m-d H:i', $ts )
		);
	}
}
