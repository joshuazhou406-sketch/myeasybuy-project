<?php
/**
 * Customer-facing OMS timeline.
 *
 * @package MyEasyBuy_OMS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shows 一段 / 二段 warehouse nodes on My Account.
 */
class MyEasyBuy_OMS_Frontend {

	/**
	 * Register storefront hooks.
	 */
	public static function init(): void {
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'render_order_timeline' ), 16 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 130 );
	}

	/**
	 * CSS.
	 */
	public static function enqueue_assets(): void {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}

		wp_enqueue_style(
			'meb-oms-frontend',
			MEB_OMS_URL . 'assets/css/frontend.css',
			array(),
			MEB_OMS_VERSION
		);
	}

	/**
	 * Timeline on order details.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function render_order_timeline( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$leg1 = MyEasyBuy_OMS_Repository::get_by_source( MyEasyBuy_OMS_Service::SOURCE_WC, (int) $order->get_id(), 1 );
		$leg2 = MyEasyBuy_OMS_Repository::get_by_source( MyEasyBuy_OMS_Service::SOURCE_WC, (int) $order->get_id(), 2 );
		if ( ! $leg1 && ! $leg2 ) {
			return;
		}

		$labels = MyEasyBuy_OMS_Service::status_labels();
		$nodes  = array( 'P', 'V', 'N', 'H', 'S', 'X', 'C' );
		$rank   = array_flip( $nodes );
		$current = $leg2['oms_status'] ?? $leg1['oms_status'] ?? 'P';
		if ( ! isset( $rank[ $current ] ) ) {
			$current = $leg1['oms_status'] ?? 'P';
		}
		$current_rank = $rank[ $current ] ?? 0;
		?>
		<section class="meb-oms-timeline woocommerce-order-oms">
			<h2><?php esc_html_e( '集运仓进度', 'meb-oms' ); ?></h2>
			<ol>
				<?php foreach ( $nodes as $index => $code ) : ?>
					<li class="<?php echo $index <= $current_rank ? 'is-done' : ''; ?>">
						<span><?php echo esc_html( $labels[ $code ] ?? $code ); ?></span>
					</li>
				<?php endforeach; ?>
			</ol>
			<dl>
				<?php if ( $leg1 ) : ?>
					<div>
						<dt><?php esc_html_e( '一段预报号', 'meb-oms' ); ?></dt>
						<dd><?php echo esc_html( (string) $leg1['customer_hawb_code'] ); ?></dd>
					</div>
					<?php if ( ! empty( $leg1['fpx_track_no'] ) ) : ?>
						<div>
							<dt><?php esc_html_e( '仓库单号', 'meb-oms' ); ?></dt>
							<dd><?php echo esc_html( (string) $leg1['fpx_track_no'] ); ?></dd>
						</div>
					<?php endif; ?>
					<?php if ( (int) $leg1['weight_g'] > 0 ) : ?>
						<div>
							<dt><?php esc_html_e( '入库实重', 'meb-oms' ); ?></dt>
							<dd><?php echo esc_html( number_format_i18n( (int) $leg1['weight_g'] / 1000, 3 ) ); ?> kg</dd>
						</div>
					<?php endif; ?>
				<?php endif; ?>
				<?php if ( $leg2 && ! empty( $leg2['fpx_track_no'] ) ) : ?>
					<div>
						<dt><?php esc_html_e( '二段出库单', 'meb-oms' ); ?></dt>
						<dd><?php echo esc_html( (string) $leg2['fpx_track_no'] ); ?></dd>
					</div>
				<?php endif; ?>
			</dl>
			<?php
			$photos = is_array( $leg1['photos'] ?? null ) ? $leg1['photos'] : array();
			if ( ! empty( $photos ) ) :
				?>
				<div class="meb-oms-photos">
					<?php foreach ( $photos as $url ) : ?>
						<a href="<?php echo esc_url( (string) $url ); ?>" target="_blank" rel="noopener noreferrer">
							<img src="<?php echo esc_url( (string) $url ); ?>" alt="" />
						</a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}
}
