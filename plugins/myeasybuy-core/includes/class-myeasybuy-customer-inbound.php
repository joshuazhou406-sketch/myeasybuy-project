<?php
/**
 * Customer inbound inspection notices — email, My Account popup, order details.
 *
 * @package MyEasyBuy_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Surfaces warehouse inbound results (photos, weight, free storage countdown) to buyers.
 */
class MyEasyBuy_Customer_Inbound {

	public const META_INBOUND_AT       = '_myeasybuy_inbound_at';
	public const META_NOTIFY_SENT      = '_myeasybuy_inbound_notify_sent';
	public const USER_META_ALERTS      = '_myeasybuy_inbound_alerts';
	public const OPTION_FREE_DAYS      = 'myeasybuy_free_storage_days';
	public const DEFAULT_FREE_DAYS     = 90;

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( MyEasyBuy_Hooks::ACTION_ITEM_INBOUND_COMPLETED, array( __CLASS__, 'on_inbound_completed' ), 10, 2 );
		add_action( 'woocommerce_account_dashboard', array( __CLASS__, 'render_dashboard_alerts' ), 8 );
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'render_order_inbound_panel' ), 12 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 120 );
		add_action( 'wp_ajax_myeasybuy_dismiss_inbound_alert', array( __CLASS__, 'ajax_dismiss_alert' ) );
	}

	/**
	 * Configured free storage days.
	 */
	public static function get_free_storage_days(): int {
		$days = (int) get_option( self::OPTION_FREE_DAYS, self::DEFAULT_FREE_DAYS );
		$days = $days > 0 ? $days : self::DEFAULT_FREE_DAYS;

		/**
		 * Filter free warehouse storage days after inbound inspection.
		 *
		 * @since 1.8.0
		 *
		 * @param int $days Free days.
		 */
		return (int) apply_filters( 'myeasybuy_free_storage_days', $days );
	}

	/**
	 * Handle inbound completion: persist timestamp, alert, email.
	 *
	 * @param int                  $order_id     Order ID.
	 * @param array<string, mixed> $inbound_data Payload.
	 */
	public static function on_inbound_completed( int $order_id, array $inbound_data ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$inbound_at = isset( $inbound_data['inspected_at'] ) ? (string) $inbound_data['inspected_at'] : gmdate( 'c' );
		$order->update_meta_data( self::META_INBOUND_AT, $inbound_at );

		// Prefer attachment IDs when provided; keep URL list for emails/API.
		if ( ! empty( $inbound_data['photo_ids'] ) && is_array( $inbound_data['photo_ids'] ) ) {
			$order->update_meta_data( '_myeasybuy_inspection_photos', array_map( 'absint', $inbound_data['photo_ids'] ) );
		} elseif ( ! empty( $inbound_data['photos'] ) && is_array( $inbound_data['photos'] ) ) {
			$order->update_meta_data( '_myeasybuy_inspection_photos', array_values( $inbound_data['photos'] ) );
		}

		if ( isset( $inbound_data['weight_grams'] ) ) {
			$order->update_meta_data( '_myeasybuy_actual_weight', (int) $inbound_data['weight_grams'] );
		}
		if ( ! empty( $inbound_data['dimensions'] ) ) {
			$order->update_meta_data( '_myeasybuy_dimensions', (string) $inbound_data['dimensions'] );
		} elseif ( isset( $inbound_data['length_cm'], $inbound_data['width_cm'], $inbound_data['height_cm'] ) ) {
			$order->update_meta_data(
				'_myeasybuy_dimensions',
				sprintf( '%sx%sx%s', $inbound_data['length_cm'], $inbound_data['width_cm'], $inbound_data['height_cm'] )
			);
		}

		$order->save();

		self::queue_customer_alert( $order );
		self::send_inbound_email( $order, $inbound_data );
	}

	/**
	 * Queue a one-time My Account popup/banner for this package.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function queue_customer_alert( WC_Order $order ): void {
		$user_id = (int) $order->get_customer_id();
		if ( $user_id <= 0 ) {
			return;
		}

		$alerts   = get_user_meta( $user_id, self::USER_META_ALERTS, true );
		$alerts   = is_array( $alerts ) ? $alerts : array();
		$order_id = (int) $order->get_id();
		if ( ! in_array( $order_id, $alerts, true ) ) {
			$alerts[] = $order_id;
			update_user_meta( $user_id, self::USER_META_ALERTS, array_values( array_unique( array_map( 'absint', $alerts ) ) ) );
		}
	}

	/**
	 * Send inbound inspection email to the customer.
	 *
	 * @param WC_Order             $order        Order.
	 * @param array<string, mixed> $inbound_data Payload.
	 */
	public static function send_inbound_email( WC_Order $order, array $inbound_data = array() ): void {
		if ( 'yes' === $order->get_meta( self::META_NOTIFY_SENT, true ) ) {
			return;
		}

		$email = $order->get_billing_email();
		if ( ! is_email( $email ) ) {
			return;
		}

		$summary = self::get_package_summary( $order );
		$photos  = $summary['photos'];
		$subject = sprintf(
			/* translators: %s: order number */
			__( '[MyEasyBuy] 包裹已入库验货 — 订单 #%s', 'myeasybuy-core' ),
			$order->get_order_number()
		);

		$photo_html = '';
		if ( $photos ) {
			$photo_html .= '<p><strong>' . esc_html__( '验货实拍', 'myeasybuy-core' ) . '</strong></p><p>';
			foreach ( array_slice( $photos, 0, 6 ) as $url ) {
				$photo_html .= sprintf(
					'<a href="%1$s" target="_blank" rel="noopener noreferrer"><img src="%1$s" alt="" width="120" style="margin:4px;border:1px solid #ddd;" /></a>',
					esc_url( $url )
				);
			}
			$photo_html .= '</p>';
		}

		$view_url = $order->get_view_order_url();
		$consol   = class_exists( 'MyEasyBuy_Customer_Warehouse' )
			? MyEasyBuy_Customer_Warehouse::get_endpoint_url()
			: wc_get_page_permalink( 'myaccount' );

		$body  = '<div style="font-family:Arial,sans-serif;color:#222;line-height:1.6;">';
		$body .= '<p>' . esc_html__( '您好，您的包裹已到达深圳集运仓并完成验货称重。', 'myeasybuy-core' ) . '</p>';
		$body .= '<ul>';
		$body .= '<li><strong>' . esc_html__( '订单', 'myeasybuy-core' ) . ':</strong> #' . esc_html( $order->get_order_number() ) . '</li>';
		$body .= '<li><strong>' . esc_html__( '实重', 'myeasybuy-core' ) . ':</strong> ' . esc_html( $summary['weight_label'] ) . '</li>';
		$body .= '<li><strong>' . esc_html__( '尺寸', 'myeasybuy-core' ) . ':</strong> ' . esc_html( $summary['dimensions'] ?: '—' ) . '</li>';
		$body .= '<li><strong>' . esc_html__( '免费保管至', 'myeasybuy-core' ) . ':</strong> ' . esc_html( $summary['storage_deadline_label'] ) . '</li>';
		$body .= '<li><strong>' . esc_html__( '剩余免费保管', 'myeasybuy-core' ) . ':</strong> ' . esc_html( $summary['storage_remaining_label'] ) . '</li>';
		$body .= '</ul>';
		$body .= $photo_html;
		$body .= '<p>' . esc_html__( '请尽快打开「我的仓库」，勾选包裹并选择物流渠道。仓库合箱后将生成国际运费二价账单。', 'myeasybuy-core' ) . '</p>';
		$body .= '<p><a href="' . esc_url( $view_url ) . '">' . esc_html__( '查看订单详情', 'myeasybuy-core' ) . '</a>';
		$body .= ' &nbsp;|&nbsp; <a href="' . esc_url( $consol ) . '">' . esc_html__( '去我的仓库', 'myeasybuy-core' ) . '</a></p>';
		$body .= '</div>';

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		/**
		 * Filter whether to send the inbound inspection email.
		 *
		 * @since 1.8.0
		 *
		 * @param bool     $send         Whether to send.
		 * @param WC_Order $order        Order.
		 * @param array    $inbound_data Payload.
		 */
		$should_send = (bool) apply_filters( 'myeasybuy_send_inbound_email', true, $order, $inbound_data );

		$sent = false;
		if ( $should_send ) {
			$sent = wp_mail( $email, $subject, $body, $headers );
		}

		if ( $sent || ! $should_send ) {
			$order->update_meta_data( self::META_NOTIFY_SENT, 'yes' );
			$order->save();
		}

		/**
		 * Fires after attempting to notify the customer of inbound inspection.
		 *
		 * @since 1.8.0
		 *
		 * @param int  $order_id Order ID.
		 * @param bool $sent     Whether email was sent.
		 */
		do_action( 'myeasybuy_inbound_customer_notified', (int) $order->get_id(), (bool) $sent );
	}

	/**
	 * Inbound timestamp (Unix) for an order.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function get_inbound_timestamp( WC_Order $order ): int {
		$raw = (string) $order->get_meta( self::META_INBOUND_AT, true );
		if ( '' !== $raw ) {
			$ts = strtotime( $raw );
			if ( $ts ) {
				return (int) $ts;
			}
		}

		// Fallback for legacy orders that entered warehouse without meta.
		$status = MyEasyBuy_Order_Statuses::normalize_status( $order->get_status() );
		$past_inbound = array(
			MyEasyBuy_Order_Statuses::STATUS_IN_WAREHOUSE,
			MyEasyBuy_Order_Statuses::STATUS_CONSOLIDATING,
			MyEasyBuy_Order_Statuses::STATUS_AWAITING_SHIPPING_FEE,
			MyEasyBuy_Order_Statuses::STATUS_PAID_SHIPPING,
			MyEasyBuy_Order_Statuses::STATUS_INT_SHIPPED,
		);
		if ( in_array( $status, $past_inbound, true ) && $order->get_date_modified() ) {
			return (int) $order->get_date_modified()->getTimestamp();
		}

		return 0;
	}

	/**
	 * Package display summary for UI / email / API.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string, mixed>
	 */
	public static function get_package_summary( WC_Order $order ): array {
		$weight_g   = (int) $order->get_meta( '_myeasybuy_actual_weight', true );
		$dimensions = (string) $order->get_meta( '_myeasybuy_dimensions', true );
		$photos     = self::normalize_photos( $order->get_meta( '_myeasybuy_inspection_photos', true ) );
		$inbound_ts = self::get_inbound_timestamp( $order );
		$free_days  = self::get_free_storage_days();
		$deadline   = $inbound_ts > 0 ? $inbound_ts + ( $free_days * DAY_IN_SECONDS ) : 0;
		$now        = time();
		$remaining  = $deadline > 0 ? max( 0, $deadline - $now ) : 0;
		$remaining_days = (int) ceil( $remaining / DAY_IN_SECONDS );

		$weight_label = $weight_g > 0
			? sprintf(
				/* translators: 1: grams, 2: kilograms */
				__( '%1$d g（约 %2$s kg）', 'myeasybuy-core' ),
				$weight_g,
				number_format_i18n( $weight_g / 1000, 3 )
			)
			: '—';

		$deadline_label = $deadline
			? wp_date( 'Y-m-d H:i', $deadline )
			: '—';

		if ( $inbound_ts <= 0 ) {
			$remaining_label = '—';
		} elseif ( $remaining_days <= 0 ) {
			$remaining_label = __( '已到期（可能产生仓租）', 'myeasybuy-core' );
		} else {
			$remaining_label = sprintf(
				/* translators: %d: days remaining */
				_n( '剩余 %d 天', '剩余 %d 天', $remaining_days, 'myeasybuy-core' ),
				$remaining_days
			);
		}

		return array(
			'weight_grams'             => $weight_g,
			'weight_label'             => $weight_label,
			'dimensions'               => $dimensions,
			'photos'                   => $photos,
			'shelf_no'                 => (string) $order->get_meta( '_myeasybuy_shelf_no', true ),
			'inbound_at'               => $inbound_ts ? gmdate( 'c', $inbound_ts ) : '',
			'inbound_at_label'         => $inbound_ts ? wp_date( 'Y-m-d H:i', $inbound_ts ) : '—',
			'free_storage_days'        => $free_days,
			'storage_deadline'         => $deadline ? gmdate( 'c', $deadline ) : '',
			'storage_deadline_label'   => $deadline_label,
			'storage_remaining_seconds'=> $remaining,
			'storage_remaining_days'   => $remaining_days,
			'storage_remaining_label'  => $remaining_label,
			'has_inbound_data'         => ( $weight_g > 0 || $dimensions || $photos || $inbound_ts > 0 ),
		);
	}

	/**
	 * Normalize photo meta to URL list.
	 *
	 * @param mixed $raw Meta.
	 * @return array<int, string>
	 */
	public static function normalize_photos( $raw ): array {
		if ( is_string( $raw ) && '' !== $raw ) {
			if ( filter_var( $raw, FILTER_VALIDATE_URL ) ) {
				return array( esc_url_raw( $raw ) );
			}
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$raw = $decoded;
			}
		}

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$urls = array();
		foreach ( $raw as $entry ) {
			if ( is_numeric( $entry ) ) {
				$url = wp_get_attachment_url( (int) $entry );
				if ( $url ) {
					$urls[] = esc_url_raw( $url );
				}
				continue;
			}
			if ( is_string( $entry ) && '' !== $entry ) {
				$urls[] = esc_url_raw( $entry );
			}
		}

		return array_values( array_filter( $urls ) );
	}

	/**
	 * Enqueue customer assets on account pages.
	 */
	public static function enqueue_assets(): void {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}

		wp_enqueue_style(
			'myeasybuy-customer-inbound',
			MYEASYBUY_CORE_URL . 'assets/css/customer-inbound.css',
			array( 'myeasybuy-storefront-palette' ),
			MYEASYBUY_CORE_VERSION
		);

		wp_enqueue_script(
			'myeasybuy-customer-inbound',
			MYEASYBUY_CORE_URL . 'assets/js/customer-inbound.js',
			array( 'jquery' ),
			MYEASYBUY_CORE_VERSION,
			true
		);

		wp_localize_script(
			'myeasybuy-customer-inbound',
			'myeasybuyInbound',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'myeasybuy_inbound_alert' ),
			)
		);
	}

	/**
	 * Dashboard popup / banners for unread inbound alerts.
	 */
	public static function render_dashboard_alerts(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user_id = get_current_user_id();
		$alerts  = get_user_meta( $user_id, self::USER_META_ALERTS, true );
		$alerts  = is_array( $alerts ) ? array_map( 'absint', $alerts ) : array();
		$alerts  = array_values( array_filter( $alerts ) );

		// Also surface recent in-warehouse packages that never got an alert queued (legacy).
		if ( empty( $alerts ) ) {
			$legacy = wc_get_orders(
				array(
					'customer_id' => $user_id,
					'status'      => array(
						str_replace( 'wc-', '', MyEasyBuy_Order_Statuses::STATUS_IN_WAREHOUSE ),
						str_replace( 'wc-', '', MyEasyBuy_Order_Statuses::STATUS_CONSOLIDATING ),
						str_replace( 'wc-', '', MyEasyBuy_Order_Statuses::STATUS_AWAITING_SHIPPING_FEE ),
						str_replace( 'wc-', '', MyEasyBuy_Order_Statuses::STATUS_PAID_SHIPPING ),
					),
					'limit'       => 5,
					'orderby'     => 'date',
					'order'       => 'DESC',
				)
			);
			foreach ( $legacy as $order ) {
				if ( ! $order instanceof WC_Order ) {
					continue;
				}
				$summary = self::get_package_summary( $order );
				if ( $summary['has_inbound_data'] && 'yes' !== $order->get_meta( '_myeasybuy_inbound_alert_seen', true ) ) {
					$alerts[] = (int) $order->get_id();
				}
			}
			$alerts = array_values( array_unique( $alerts ) );
		}

		if ( empty( $alerts ) ) {
			return;
		}

		$cards = array();
		foreach ( $alerts as $oid ) {
			$order = wc_get_order( $oid );
			if ( ! $order instanceof WC_Order || (int) $order->get_customer_id() !== $user_id ) {
				continue;
			}
			$cards[] = array(
				'order'   => $order,
				'summary' => self::get_package_summary( $order ),
			);
		}

		if ( empty( $cards ) ) {
			return;
		}

		echo '<div class="myeasybuy-inbound-modal" id="myeasybuy-inbound-modal" role="dialog" aria-modal="true" aria-labelledby="myeasybuy-inbound-modal-title">';
		echo '<div class="myeasybuy-inbound-modal__backdrop" data-dismiss-all="1"></div>';
		echo '<div class="myeasybuy-inbound-modal__dialog">';
		echo '<button type="button" class="myeasybuy-inbound-modal__close" data-dismiss-all="1" aria-label="' . esc_attr__( '关闭', 'myeasybuy-core' ) . '">&times;</button>';
		echo '<h2 id="myeasybuy-inbound-modal-title">' . esc_html__( '包裹已入库验货', 'myeasybuy-core' ) . '</h2>';
		echo '<p class="myeasybuy-inbound-modal__intro">' . esc_html__( '以下包裹已完成实拍与称重，可查看验货信息与免费保管倒计时。', 'myeasybuy-core' ) . '</p>';

		foreach ( $cards as $card ) {
			/** @var WC_Order $order */
			$order   = $card['order'];
			$summary = $card['summary'];
			$oid     = (int) $order->get_id();

			echo '<article class="myeasybuy-inbound-card" data-order-id="' . esc_attr( (string) $oid ) . '">';
			echo '<header><strong>#' . esc_html( $order->get_order_number() ) . '</strong>';
			echo ' <span class="myeasybuy-inbound-card__status">' . esc_html( wc_get_order_status_name( $order->get_status() ) ) . '</span></header>';
			echo '<ul class="myeasybuy-inbound-card__meta">';
			echo '<li><span>' . esc_html__( '实重', 'myeasybuy-core' ) . '</span> ' . esc_html( $summary['weight_label'] ) . '</li>';
			echo '<li><span>' . esc_html__( '尺寸', 'myeasybuy-core' ) . '</span> ' . esc_html( $summary['dimensions'] ?: '—' ) . '</li>';
			echo '<li><span>' . esc_html__( '免费保管倒计时', 'myeasybuy-core' ) . '</span> <em class="myeasybuy-inbound-countdown">' . esc_html( $summary['storage_remaining_label'] ) . '</em>';
			echo ' <small>(' . esc_html__( '截止', 'myeasybuy-core' ) . ' ' . esc_html( $summary['storage_deadline_label'] ) . ')</small></li>';
			echo '</ul>';

			if ( ! empty( $summary['photos'] ) ) {
				echo '<div class="myeasybuy-inbound-photos">';
				foreach ( array_slice( $summary['photos'], 0, 4 ) as $url ) {
					printf(
						'<a href="%1$s" target="_blank" rel="noopener noreferrer"><img src="%1$s" alt="%2$s" /></a>',
						esc_url( $url ),
						esc_attr__( '验货实拍', 'myeasybuy-core' )
					);
				}
				echo '</div>';
			}

			echo '<p class="myeasybuy-inbound-card__actions">';
			echo '<a class="button" href="' . esc_url( $order->get_view_order_url() ) . '">' . esc_html__( '查看详情', 'myeasybuy-core' ) . '</a> ';
			if ( class_exists( 'MyEasyBuy_Customer_Warehouse' ) ) {
				echo '<a class="button button-primary" href="' . esc_url( MyEasyBuy_Customer_Warehouse::get_endpoint_url() ) . '">' . esc_html__( '去我的仓库', 'myeasybuy-core' ) . '</a>';
			}
			echo '</p></article>';
		}

		echo '<p class="myeasybuy-inbound-modal__footer"><button type="button" class="button" data-dismiss-all="1">' . esc_html__( '我知道了', 'myeasybuy-core' ) . '</button></p>';
		echo '</div></div>';
	}

	/**
	 * AJAX: dismiss inbound alerts.
	 */
	public static function ajax_dismiss_alert(): void {
		check_ajax_referer( 'myeasybuy_inbound_alert', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( null, 403 );
		}

		$user_id = get_current_user_id();
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;

		$alerts = get_user_meta( $user_id, self::USER_META_ALERTS, true );
		$alerts = is_array( $alerts ) ? array_map( 'absint', $alerts ) : array();

		if ( $order_id > 0 ) {
			$alerts = array_values( array_diff( $alerts, array( $order_id ) ) );
			$order  = wc_get_order( $order_id );
			if ( $order && (int) $order->get_customer_id() === $user_id ) {
				$order->update_meta_data( '_myeasybuy_inbound_alert_seen', 'yes' );
				$order->save();
			}
		} else {
			foreach ( $alerts as $oid ) {
				$order = wc_get_order( $oid );
				if ( $order && (int) $order->get_customer_id() === $user_id ) {
					$order->update_meta_data( '_myeasybuy_inbound_alert_seen', 'yes' );
					$order->save();
				}
			}
			$alerts = array();
		}

		update_user_meta( $user_id, self::USER_META_ALERTS, $alerts );
		wp_send_json_success();
	}

	/**
	 * Order view: inspection photos, weight, storage countdown.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function render_order_inbound_panel( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$summary = self::get_package_summary( $order );
		if ( ! $summary['has_inbound_data'] ) {
			return;
		}

		echo '<section class="myeasybuy-inbound-panel woocommerce-order-details">';
		echo '<h2 class="woocommerce-order-details__title">' . esc_html__( '入库验货信息', 'myeasybuy-core' ) . '</h2>';
		echo '<table class="woocommerce-table shop_table myeasybuy-inbound-panel__table"><tbody>';
		echo '<tr><th>' . esc_html__( '入库时间', 'myeasybuy-core' ) . '</th><td>' . esc_html( $summary['inbound_at_label'] ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( '实重', 'myeasybuy-core' ) . '</th><td>' . esc_html( $summary['weight_label'] ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( '尺寸 (cm)', 'myeasybuy-core' ) . '</th><td>' . esc_html( $summary['dimensions'] ?: '—' ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( '免费保管倒计时', 'myeasybuy-core' ) . '</th><td><strong class="myeasybuy-inbound-countdown">' . esc_html( $summary['storage_remaining_label'] ) . '</strong>';
		echo '<br /><small>' . esc_html__( '免费保管截止', 'myeasybuy-core' ) . ': ' . esc_html( $summary['storage_deadline_label'] );
		echo '（' . esc_html( (string) $summary['free_storage_days'] ) . esc_html__( ' 天免费', 'myeasybuy-core' ) . '）</small></td></tr>';
		if ( $summary['shelf_no'] ) {
			echo '<tr><th>' . esc_html__( '货架号', 'myeasybuy-core' ) . '</th><td>' . esc_html( $summary['shelf_no'] ) . '</td></tr>';
		}
		echo '</tbody></table>';

		if ( ! empty( $summary['photos'] ) ) {
			echo '<h3>' . esc_html__( '验货实拍照片', 'myeasybuy-core' ) . '</h3>';
			echo '<div class="myeasybuy-inbound-photos myeasybuy-inbound-photos--order">';
			foreach ( $summary['photos'] as $url ) {
				printf(
					'<a href="%1$s" target="_blank" rel="noopener noreferrer"><img src="%1$s" alt="%2$s" loading="lazy" /></a>',
					esc_url( $url ),
					esc_attr__( '验货实拍', 'myeasybuy-core' )
				);
			}
			echo '</div>';
		}

		echo '</section>';
	}
}
