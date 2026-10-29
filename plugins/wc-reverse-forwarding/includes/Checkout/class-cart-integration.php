<?php
/**
 * Push a consolidation quote into WooCommerce checkout.
 *
 * @package ReverseForwarding
 */

namespace ReverseForwarding\Checkout;

use ReverseForwarding\Models\Consolidation;
use ReverseForwarding\Models\Package;
use ReverseForwarding\Shipping\Calculator;
use WC_Order;
use WC_Product;
use WC_Product_Simple;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Draft consolidation → exclusive cart line → paid order sync.
 *
 * Data flow:
 * 1) Buyer confirms inbound packages.
 * 2) Calculator quotes chargeable weight + VAS using the WC customer address.
 * 3) A wp_rf_consolidations draft is stored and packages are locked (applied).
 * 4) Cart is emptied and a hidden virtual product is added at quote total.
 * 5) Checkout uses standard WC shipping address (no extra WC shipping rate).
 * 6) Address changes re-quote the draft; order creation binds order_id.
 * 7) woocommerce_order_status_processing marks the job paid and packages consolidating.
 */
class Cart_Integration {

	public const PRODUCT_OPTION   = 'wc_rf_product_id';
	public const PRODUCT_SKU      = 'wc-rf-consolidation';
	public const CART_KEY         = 'wc_rf_consolidation_id';
	public const ORDER_META       = '_wc_rf_consolidation_id';
	public const ITEM_QUOTE_META  = '_wc_rf_quote_total';

	/**
	 * Register WooCommerce hooks.
	 */
	public static function init(): void {
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'sync_cart_prices' ), 20 );
		add_filter( 'woocommerce_cart_needs_shipping', array( __CLASS__, 'cart_needs_shipping' ) );
		add_filter( 'woocommerce_cart_needs_shipping_address', array( __CLASS__, 'cart_needs_shipping_address' ) );
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_add_to_cart' ), 10, 2 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'cart_item_data' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'attach_order_meta' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'attach_line_item_meta' ), 10, 4 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'bind_processed_order' ), 10, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'bind_block_order' ) );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_order_processing' ), 20, 2 );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'on_payment_complete' ) );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'require_login_for_cart' ) );
	}

	/**
	 * Consolidation checkout is always tied to the logged-in warehouse account.
	 */
	public static function require_login_for_cart(): void {
		if ( self::cart_has_consolidation() && ! is_user_logged_in() ) {
			wc_add_notice( __( '请登录后再支付集运费用。', 'wc-rf' ), 'error' );
		}
	}

	/**
	 * Create draft + exclusive cart item, then the caller redirects to checkout.
	 *
	 * @param array<int, array<string, mixed>> $packages Owned inbound packages.
	 * @return int|WP_Error Consolidation ID.
	 */
	public static function begin_checkout( array $packages ) {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 || ! current_user_can( 'read' ) ) {
			return new WP_Error( 'wc_rf_auth', __( '请先登录后再申请合箱。', 'wc-rf' ) );
		}

		if ( array() === $packages ) {
			return new WP_Error( 'wc_rf_empty', __( '请至少勾选一个已到仓包裹。', 'wc-rf' ) );
		}

		self::ensure_cart();

		$destination = self::destination_from_customer();
		$quote       = Calculator::quote( $packages, $destination );

		if ( (float) $quote['total'] <= 0 ) {
			return new WP_Error( 'wc_rf_zero', __( '无法计费：包裹尚未称重或尺寸未录入。', 'wc-rf' ) );
		}

		Consolidation::abandon_user_drafts( $user_id );

		$consolidation_id = Consolidation::create_draft(
			array(
				'user_id'           => $user_id,
				'carrier_code'      => $quote['carrier_code'],
				'chargeable_weight' => $quote['chargeable_kg'],
				'shipping_fee'      => $quote['shipping_fee'],
				'service_fee'       => $quote['service_fee'],
			)
		);

		if ( is_wp_error( $consolidation_id ) ) {
			return $consolidation_id;
		}

		$ids    = array_map( static fn( array $row ): int => (int) $row['id'], $packages );
		$locked = Package::attach_to_consolidation( $ids, (int) $consolidation_id, $user_id );
		if ( is_wp_error( $locked ) ) {
			Consolidation::abandon_user_drafts( $user_id );
			return $locked;
		}

		$added = self::replace_cart_with_quote( (int) $consolidation_id, $quote );
		if ( is_wp_error( $added ) ) {
			Consolidation::abandon_user_drafts( $user_id );
			return $added;
		}

		/**
		 * Fires after a draft consolidation is in the WooCommerce cart.
		 *
		 * @since 1.0.0
		 *
		 * @param int                              $consolidation_id Draft ID.
		 * @param array<string, mixed>             $quote            Quote.
		 * @param array<int, array<string, mixed>> $packages         Packages.
		 */
		do_action( 'wc_rf_checkout_started', (int) $consolidation_id, $quote, $packages );

		return (int) $consolidation_id;
	}

	/**
	 * Recalculate exclusive cart item from the latest checkout address.
	 *
	 * @param \WC_Cart $cart Cart.
	 */
	public static function sync_cart_prices( $cart ): void {
		if ( ! $cart || is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		foreach ( $cart->get_cart() as $item ) {
			if ( empty( $item[ self::CART_KEY ] ) || ! isset( $item['data'] ) || ! $item['data'] instanceof WC_Product ) {
				continue;
			}

			$consolidation = Consolidation::get_for_user( (int) $item[ self::CART_KEY ], get_current_user_id() );
			if ( null === $consolidation || Consolidation::STATUS_DRAFT !== $consolidation['status'] ) {
				continue;
			}

			$packages = Package::list_by_consolidation( (int) $consolidation['id'], (int) $consolidation['user_id'] );
			if ( array() === $packages ) {
				continue;
			}

			$quote = Calculator::quote( $packages, self::destination_from_customer() );
			if ( (float) $quote['total'] <= 0 ) {
				continue;
			}

			Consolidation::update_quote( (int) $consolidation['id'], $quote );

			$item['data']->set_price( (float) $quote['total'] );
			$item['data']->set_regular_price( (float) $quote['total'] );
			$item['data']->set_sold_individually( true );
		}
	}

	/**
	 * Consolidation fee is the product price — do not add WC shipping rates.
	 */
	public static function cart_needs_shipping( bool $needs ): bool {
		return self::cart_has_consolidation() ? false : $needs;
	}

	/**
	 * Still collect a standard WooCommerce shipping address.
	 */
	public static function cart_needs_shipping_address( bool $needs ): bool {
		return self::cart_has_consolidation() ? true : $needs;
	}

	/**
	 * Keep the cart exclusive to the consolidation line.
	 *
	 * @param bool $passed     Current validation.
	 * @param int  $product_id Product being added.
	 */
	public static function validate_add_to_cart( bool $passed, $product_id ): bool {
		if ( ! $passed || ! self::cart_has_consolidation() ) {
			return $passed;
		}

		if ( (int) $product_id === self::ensure_product_id() ) {
			return $passed;
		}

		wc_add_notice( __( '购物车中已有集运费用，请先完成结账或清空购物车后再购买其他商品。', 'wc-rf' ), 'error' );
		return false;
	}

	/**
	 * Visible cart meta (escaped by WooCommerce later via wc_clean / esc).
	 *
	 * @param array<int, array<string, string>> $data Item data.
	 * @param array<string, mixed>              $item Cart item.
	 * @return array<int, array<string, string>>
	 */
	public static function cart_item_data( array $data, array $item ): array {
		if ( empty( $item[ self::CART_KEY ] ) ) {
			return $data;
		}

		$job = Consolidation::get( (int) $item[ self::CART_KEY ] );
		if ( null === $job ) {
			return $data;
		}

		$destination = self::destination_from_customer();

		$data[] = array(
			'key'   => __( '合箱单', 'wc-rf' ),
			'value' => '#' . (int) $job['id'],
		);
		$data[] = array(
			'key'   => __( '计费重', 'wc-rf' ),
			'value' => wc_format_decimal( (float) $job['chargeable_weight'], 3 ) . ' kg',
		);
		$data[] = array(
			'key'   => __( '国际运费', 'wc-rf' ),
			'value' => wp_strip_all_tags( wc_price( (float) $job['shipping_fee'] ) ),
		);
		$data[] = array(
			'key'   => __( '增值服务', 'wc-rf' ),
			'value' => wp_strip_all_tags( wc_price( (float) $job['service_fee'] ) ),
		);
		if ( '' !== $destination['state'] || '' !== $destination['postcode'] ) {
			$data[] = array(
				'key'   => __( '目的地', 'wc-rf' ),
				'value' => trim( $destination['state'] . ' ' . $destination['postcode'] . ' ' . $destination['country'] ),
			);
		}

		return $data;
	}

	/**
	 * Persist consolidation id on the WC order.
	 *
	 * @param WC_Order             $order Order being created.
	 * @param array<string, mixed> $data  Posted checkout data.
	 */
	public static function attach_order_meta( $order, $data ): void {
		unset( $data );
		$id = self::cart_consolidation_id();
		if ( $id > 0 ) {
			$order->update_meta_data( self::ORDER_META, $id );
		}
	}

	/**
	 * Persist fee breakdown on the line item.
	 *
	 * @param \WC_Order_Item_Product $item      Line item.
	 * @param string                 $cart_key  Cart key.
	 * @param array<string, mixed>   $values    Cart values.
	 * @param WC_Order               $order     Order.
	 */
	public static function attach_line_item_meta( $item, $cart_key, $values, $order ): void {
		unset( $cart_key, $order );
		if ( empty( $values[ self::CART_KEY ] ) ) {
			return;
		}

		$id  = (int) $values[ self::CART_KEY ];
		$job = Consolidation::get( $id );
		if ( null === $job ) {
			return;
		}

		$item->update_meta_data( self::ORDER_META, $id );
		$item->update_meta_data( self::ITEM_QUOTE_META, (float) $job['shipping_fee'] + (float) $job['service_fee'] );
		$item->update_meta_data( '_wc_rf_shipping_fee', (float) $job['shipping_fee'] );
		$item->update_meta_data( '_wc_rf_service_fee', (float) $job['service_fee'] );
		$item->update_meta_data( '_wc_rf_chargeable_kg', (float) $job['chargeable_weight'] );
		$item->set_name(
			sprintf(
				/* translators: %d: consolidation id */
				__( '集运国际运费（合箱 #%d）', 'wc-rf' ),
				$id
			)
		);
	}

	/**
	 * Classic checkout: bind order_id onto the draft.
	 *
	 * @param int                  $order_id Order ID.
	 * @param array<string, mixed> $data     Posted data.
	 * @param WC_Order             $order    Order.
	 */
	public static function bind_processed_order( $order_id, $data, $order ): void {
		unset( $data );
		self::bind_order( (int) $order_id, $order instanceof WC_Order ? $order : null );
	}

	/**
	 * Block / Store API checkout.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function bind_block_order( $order ): void {
		if ( $order instanceof WC_Order ) {
			self::bind_order( $order->get_id(), $order );
		}
	}

	/**
	 * Required hook: payment reached processing.
	 *
	 * @param int           $order_id Order ID.
	 * @param WC_Order|null $order    Order.
	 */
	public static function on_order_processing( $order_id, $order = null ): void {
		self::mark_settled( (int) $order_id, $order instanceof WC_Order ? $order : null );
	}

	/**
	 * Some gateways skip processing and only fire payment_complete.
	 */
	public static function on_payment_complete( $order_id ): void {
		self::mark_settled( (int) $order_id, null );
	}

	/**
	 * Whether the cart currently holds a forwarding line.
	 */
	public static function cart_has_consolidation(): bool {
		return self::cart_consolidation_id() > 0;
	}

	/**
	 * Hidden virtual product used as the dynamic-price vehicle.
	 */
	public static function ensure_product_id(): int {
		$stored = absint( get_option( self::PRODUCT_OPTION, 0 ) );
		if ( $stored && 'trash' !== get_post_status( $stored ) ) {
			$product = wc_get_product( $stored );
			if ( $product instanceof WC_Product ) {
				return $stored;
			}
		}

		$existing = wc_get_product_id_by_sku( self::PRODUCT_SKU );
		if ( $existing ) {
			update_option( self::PRODUCT_OPTION, $existing, false );
			return $existing;
		}

		$product = new WC_Product_Simple();
		$product->set_name( __( '集运国际运费', 'wc-rf' ) );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_virtual( true );
		$product->set_sold_individually( true );
		$product->set_regular_price( '0' );
		$product->set_price( '0' );
		$product->set_sku( self::PRODUCT_SKU );
		$product->set_tax_status( 'none' );
		$product->set_reviews_allowed( false );
		$product->update_meta_data( '_wc_rf_is_consolidation', 'yes' );
		$product_id = (int) $product->save();

		if ( $product_id > 0 ) {
			update_option( self::PRODUCT_OPTION, $product_id, false );
		}

		return $product_id;
	}

	/**
	 * Destination from the live WooCommerce customer (checkout address).
	 *
	 * @return array{country: string, state: string, postcode: string}
	 */
	public static function destination_from_customer(): array {
		$country  = '';
		$state    = '';
		$postcode = '';

		if ( function_exists( 'WC' ) && WC()->customer ) {
			$country  = (string) WC()->customer->get_shipping_country();
			$state    = (string) WC()->customer->get_shipping_state();
			$postcode = (string) WC()->customer->get_shipping_postcode();

			if ( '' === $country ) {
				$country = (string) WC()->customer->get_billing_country();
			}
			if ( '' === $state ) {
				$state = (string) WC()->customer->get_billing_state();
			}
			if ( '' === $postcode ) {
				$postcode = (string) WC()->customer->get_billing_postcode();
			}
		}

		return Calculator::normalize_destination(
			array(
				'country'  => $country,
				'state'    => $state,
				'postcode' => $postcode,
			)
		);
	}

	/**
	 * Empty the cart and add the quoted consolidation product.
	 *
	 * @param array<string, mixed> $quote Quote.
	 * @return true|WP_Error
	 */
	private static function replace_cart_with_quote( int $consolidation_id, array $quote ) {
		$product_id = self::ensure_product_id();
		if ( $product_id <= 0 ) {
			return new WP_Error( 'wc_rf_product', __( '无法创建集运结算商品。', 'wc-rf' ) );
		}

		self::ensure_cart();
		WC()->cart->empty_cart();

		$key = WC()->cart->add_to_cart(
			$product_id,
			1,
			0,
			array(),
			array(
				self::CART_KEY => $consolidation_id,
				'wc_rf_total'  => (float) $quote['total'],
			)
		);

		if ( ! $key ) {
			return new WP_Error( 'wc_rf_cart', __( '无法将集运费用加入购物车。', 'wc-rf' ) );
		}

		return true;
	}

	private static function ensure_cart(): void {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		if ( null === WC()->cart ) {
			wc_load_cart();
		}
	}

	private static function cart_consolidation_id(): int {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return 0;
		}

		foreach ( WC()->cart->get_cart() as $item ) {
			if ( ! empty( $item[ self::CART_KEY ] ) ) {
				return (int) $item[ self::CART_KEY ];
			}
		}

		return 0;
	}

	/**
	 * Write order_id onto the draft after checkout creates the order.
	 */
	private static function bind_order( int $order_id, ?WC_Order $order ): void {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$id = absint( $order->get_meta( self::ORDER_META ) );
		if ( $id <= 0 ) {
			$id = self::consolidation_id_from_items( $order );
		}
		if ( $id <= 0 ) {
			return;
		}

		Consolidation::set_order_id( $id, $order_id, (int) $order->get_user_id() );
	}

	/**
	 * Payment success: draft → paid, packages → consolidating.
	 */
	private static function mark_settled( int $order_id, ?WC_Order $order ): void {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$id = absint( $order->get_meta( self::ORDER_META ) );
		if ( $id <= 0 ) {
			$id = self::consolidation_id_from_items( $order );
		}
		if ( $id <= 0 ) {
			$by_order = Consolidation::get_by_order( $order_id );
			$id       = $by_order['id'] ?? 0;
		}
		if ( $id <= 0 ) {
			return;
		}

		$user_id = (int) $order->get_user_id();
		if ( ! Consolidation::mark_paid( $id, $order_id, $user_id ) ) {
			return;
		}

		Package::mark_consolidating( $id, $user_id );

		/**
		 * Fires after a consolidation is marked paid.
		 *
		 * @since 1.0.0
		 *
		 * @param int      $id    Consolidation ID.
		 * @param WC_Order $order Order.
		 */
		do_action( 'wc_rf_consolidation_paid', $id, $order );
	}

	private static function consolidation_id_from_items( WC_Order $order ): int {
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$id = absint( $item->get_meta( self::ORDER_META ) );
			if ( $id > 0 ) {
				return $id;
			}
		}

		return 0;
	}
}
