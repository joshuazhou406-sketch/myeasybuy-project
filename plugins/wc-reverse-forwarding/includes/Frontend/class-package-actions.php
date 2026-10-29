<?php
/**
 * Front-end package declaration and consolidation request handlers.
 *
 * @package ReverseForwarding
 */

namespace ReverseForwarding\Frontend;

use ReverseForwarding\Checkout\Cart_Integration;
use ReverseForwarding\Models\Package;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Listens for forwarding center package forms.
 */
class Package_Actions {

	public const ACTION_DECLARE       = 'wc_rf_declare_package';
	public const ACTION_CONSOLIDATE   = 'wc_rf_request_consolidation';
	public const ACTION_CANCEL        = 'wc_rf_cancel_package';
	public const NONCE_DECLARE        = 'wc_rf_declare_nonce';
	public const NONCE_CONSOLIDATE    = 'wc_rf_consolidation_nonce';
	public const NONCE_CANCEL         = 'wc_rf_cancel_nonce';

	/**
	 * Register form listeners.
	 */
	public static function init(): void {
		add_action( 'admin_post_' . self::ACTION_DECLARE, array( __CLASS__, 'handle_declare' ) );
		add_action( 'admin_post_' . self::ACTION_CONSOLIDATE, array( __CLASS__, 'handle_consolidation_request' ) );
		add_action( 'admin_post_' . self::ACTION_CANCEL, array( __CLASS__, 'handle_cancel' ) );
	}

	/**
	 * Domestic carriers shown on the declare form.
	 *
	 * @return array<string, string>
	 */
	public static function express_companies(): array {
		$companies = array(
			'顺丰速运' => __( '顺丰速运', 'wc-rf' ),
			'中通快递' => __( '中通快递', 'wc-rf' ),
			'圆通速递' => __( '圆通速递', 'wc-rf' ),
			'韵达快递' => __( '韵达快递', 'wc-rf' ),
			'申通快递' => __( '申通快递', 'wc-rf' ),
			'极兔速递' => __( '极兔速递', 'wc-rf' ),
			'京东物流' => __( '京东物流', 'wc-rf' ),
			'EMS'    => 'EMS',
			'other'  => __( '其他', 'wc-rf' ),
		);

		/**
		 * Filter declare-form carrier options.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, string> $companies Value => label.
		 */
		return apply_filters( 'wc_rf_express_companies', $companies );
	}

	/**
	 * Template args for the declare form.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_form_data(): array {
		return array(
			'companies'    => self::express_companies(),
			'action_url'   => admin_url( 'admin-post.php' ),
			'redirect_to'  => Forwarding_Center::get_url(),
			'action_name'  => self::ACTION_DECLARE,
			'nonce_action' => self::ACTION_DECLARE,
			'nonce_name'   => self::NONCE_DECLARE,
		);
	}

	/**
	 * Handle 快递预报.
	 */
	public static function handle_declare(): void {
		self::guard_request( self::ACTION_DECLARE, self::NONCE_DECLARE );

		$company = sanitize_text_field( wp_unslash( $_POST['express_company'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( 'other' === $company ) {
			$company = sanitize_text_field( wp_unslash( $_POST['express_company_other'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		$result = Package::create(
			array(
				'tracking_number' => wp_unslash( $_POST['tracking_number'] ?? '' ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'express_company' => $company,
				'declared_name'   => wp_unslash( $_POST['declared_name'] ?? '' ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			)
		);

		if ( is_wp_error( $result ) ) {
			self::notice_and_redirect( $result->get_error_message(), 'error' );
		}

		self::notice_and_redirect( __( '预报成功。包裹到达仓库并入库后，会出现在「已到仓待合箱」。', 'wc-rf' ), 'success' );
	}

	/**
	 * Handle 申请合箱打包: quote, draft job, exclusive cart, checkout.
	 */
	public static function handle_consolidation_request(): void {
		self::guard_request( self::ACTION_CONSOLIDATE, self::NONCE_CONSOLIDATE );

		$ids = isset( $_POST['package_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['package_ids'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$ids = array_values( array_filter( array_unique( $ids ) ) );

		$packages = self::selectable_owned_packages( $ids );
		if ( is_wp_error( $packages ) ) {
			self::notice_and_redirect( $packages->get_error_message(), 'error' );
		}

		$result = Cart_Integration::begin_checkout( $packages );
		if ( is_wp_error( $result ) ) {
			self::notice_and_redirect( $result->get_error_message(), 'error' );
		}

		/**
		 * Fires after a buyer submits a valid consolidation selection.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int, array<string, mixed>> $packages Selected inbound packages.
		 * @param int                              $user_id  Current user.
		 * @param int                              $result   Consolidation ID.
		 */
		do_action( 'wc_rf_consolidation_requested', $packages, get_current_user_id(), (int) $result );

		self::notice_and_redirect(
			__( '已生成集运费用，请确认收件地址并完成支付。', 'wc-rf' ),
			'success',
			wc_get_checkout_url()
		);
	}

	/**
	 * Handle 撤销预报.
	 */
	public static function handle_cancel(): void {
		self::guard_request( self::ACTION_CANCEL, self::NONCE_CANCEL );

		$id     = absint( $_POST['package_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$result = Package::delete( $id );

		if ( is_wp_error( $result ) ) {
			self::notice_and_redirect( $result->get_error_message(), 'error' );
		}

		self::notice_and_redirect( __( '已撤销该预报包裹。', 'wc-rf' ), 'success' );
	}

	/**
	 * Confirm every posted ID is an inbound package owned by the current user.
	 *
	 * @param array<int, int> $ids Package IDs.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	private static function selectable_owned_packages( array $ids ) {
		if ( array() === $ids ) {
			return new WP_Error( 'wc_rf_empty', __( '请至少勾选一个已到仓包裹。', 'wc-rf' ) );
		}

		$owned = array();
		foreach ( $ids as $id ) {
			$row = Package::get( $id );
			if ( null === $row ) {
				return new WP_Error( 'wc_rf_forbidden', __( '部分包裹不存在或无权操作。', 'wc-rf' ) );
			}
			if ( ! Package::is_selectable( $row ) ) {
				return new WP_Error( 'wc_rf_not_ready', __( '只能勾选已到仓、尚未合箱的包裹。', 'wc-rf' ) );
			}
			$owned[] = $row;
		}

		return $owned;
	}

	/**
	 * Login + capability + nonce.
	 */
	private static function guard_request( string $nonce_action, string $nonce_name ): void {
		if ( ! is_user_logged_in() || ! current_user_can( 'read' ) ) {
			wp_die( esc_html__( '请先登录。', 'wc-rf' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( $nonce_action, $nonce_name );
	}

	/**
	 * Flash a WooCommerce notice and return to the forwarding center.
	 */
	private static function notice_and_redirect( string $message, string $type, string $redirect = '' ): void {
		// admin-post.php skips WooCommerce's frontend session, so notices would otherwise be dropped.
		if ( null === WC()->session ) {
			wc_load_cart();
		}

		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( $message, $type );
		}

		if ( '' === $redirect ) {
			$redirect = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		if ( '' === $redirect ) {
			$redirect = Forwarding_Center::get_url();
		}

		nocache_headers();
		wp_safe_redirect( $redirect );
		exit;
	}
}
