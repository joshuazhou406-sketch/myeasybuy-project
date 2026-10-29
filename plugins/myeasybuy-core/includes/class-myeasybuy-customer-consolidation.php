<?php
/**
 * Customer My Account — consolidation request (合箱申请).
 *
 * @package MyEasyBuy_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lets customers multi-select inbound packages and choose a shipping channel.
 */
class MyEasyBuy_Customer_Consolidation {

	public const ENDPOINT = 'consolidation';

	public const META_GROUP_ID          = '_myeasybuy_consolidation_group_id';
	public const META_REQUESTED_CHANNEL = '_myeasybuy_requested_shipping_channel';
	public const META_REQUESTED_AT      = '_myeasybuy_consolidation_requested_at';
	public const META_REQUESTED_BY      = '_myeasybuy_consolidation_requested_by';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'add_endpoint' ) );
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'add_query_var' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'account_menu_items' ), 200 );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'render_endpoint' ) );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_retired_pages' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 110 );
		add_action( 'admin_post_myeasybuy_request_consolidation', array( __CLASS__, 'handle_request' ) );
		add_action( 'woocommerce_account_dashboard', array( __CLASS__, 'render_dashboard_prompt' ), 12 );
		add_action( 'woocommerce_before_account_orders', array( __CLASS__, 'render_orders_prompt' ), 5 );

		if ( get_option( 'myeasybuy_consolidation_endpoint_flushed' ) !== '1.7.0' ) {
			add_action( 'init', array( __CLASS__, 'maybe_flush_rewrite' ), 99 );
		}
	}

	/**
	 * Register WooCommerce My Account endpoint.
	 */
	public static function add_endpoint(): void {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	/**
	 * Expose endpoint to WooCommerce query vars.
	 *
	 * @param array<string, string> $vars Query vars.
	 * @return array<string, string>
	 */
	public static function add_query_var( array $vars ): array {
		$vars[ self::ENDPOINT ] = self::ENDPOINT;
		return $vars;
	}

	/**
	 * Flush rewrite rules once after adding endpoint.
	 */
	public static function maybe_flush_rewrite(): void {
		self::add_endpoint();
		flush_rewrite_rules( false );
		update_option( 'myeasybuy_consolidation_endpoint_flushed', '1.7.0', false );
	}

	/**
	 * Drop retired account screens. 合箱申请 now lives on 我的仓库.
	 *
	 * @param array<string, string> $items Menu items.
	 * @return array<string, string>
	 */
	public static function account_menu_items( array $items ): array {
		unset(
			$items[ self::ENDPOINT ],
			$items['downloads'],
			$items['edit-address'],
			$items['payment-methods'],
			$items['edit-account']
		);

		return $items;
	}

	/**
	 * Send retired account URLs to the screen that replaced them.
	 */
	public static function redirect_retired_pages(): void {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() || ! is_user_logged_in() ) {
			return;
		}

		$account = wc_get_page_permalink( 'myaccount' );
		$profile = class_exists( 'MyEasyBuy_Customer_Profile' ) ? MyEasyBuy_Customer_Profile::get_endpoint_url() : $account;

		if ( is_wc_endpoint_url( self::ENDPOINT ) ) {
			$target = class_exists( 'MyEasyBuy_Customer_Warehouse' ) ? MyEasyBuy_Customer_Warehouse::get_endpoint_url() : $account;
			if ( ! empty( $_GET['meb_ids'] ) && is_string( $_GET['meb_ids'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$target = add_query_arg(
					'meb_ids',
					sanitize_text_field( wp_unslash( $_GET['meb_ids'] ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$target
				);
			}
			wp_safe_redirect( $target );
			exit;
		}

		if ( is_wc_endpoint_url( 'edit-address' ) && class_exists( 'MyEasyBuy_Customer_Profile' ) ) {
			wp_safe_redirect( MyEasyBuy_Customer_Profile::page_url( array( 'tab' => 'address' ) ) );
			exit;
		}

		if ( is_wc_endpoint_url( 'edit-account' ) ) {
			wp_safe_redirect( $profile );
			exit;
		}

		if ( is_wc_endpoint_url( 'downloads' ) || is_wc_endpoint_url( 'payment-methods' ) ) {
			wp_safe_redirect( $account );
			exit;
		}
	}

	/**
	 * Endpoint URL.
	 */
	public static function get_endpoint_url(): string {
		return wc_get_account_endpoint_url( self::ENDPOINT );
	}

	/**
	 * Enqueue front-end assets on account pages.
	 */
	public static function enqueue_assets(): void {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}

		wp_enqueue_style(
			'myeasybuy-customer-consolidation',
			MYEASYBUY_CORE_URL . 'assets/css/customer-consolidation.css',
			array( 'myeasybuy-storefront-palette' ),
			MYEASYBUY_CORE_VERSION
		);

		wp_enqueue_script(
			'myeasybuy-customer-consolidation',
			MYEASYBUY_CORE_URL . 'assets/js/customer-consolidation.js',
			array( 'jquery' ),
			MYEASYBUY_CORE_VERSION,
			true
		);

		wp_localize_script(
			'myeasybuy-customer-consolidation',
			'myeasybuyConsolidation',
			array(
				'needSelect'  => __( '请至少勾选一个已入库包裹。', 'myeasybuy-core' ),
				'needChannel' => __( '请选择物流渠道。', 'myeasybuy-core' ),
				'confirmTpl'  => __( '将提交 %d 个包裹合箱，并选择该物流渠道，确认吗？', 'myeasybuy-core' ),
			)
		);
	}

	/**
	 * Dashboard shortcut when inbound packages exist.
	 */
	public static function render_dashboard_prompt(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$orders = self::get_eligible_orders( get_current_user_id() );
		if ( empty( $orders ) ) {
			return;
		}

		$warehouse = class_exists( 'MyEasyBuy_Customer_Warehouse' ) ? MyEasyBuy_Customer_Warehouse::get_endpoint_url() : self::get_endpoint_url();
		printf(
			'<div class="woocommerce-info myeasybuy-consolidation-prompt"><p>%1$s <a class="button" href="%2$s">%3$s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: %d: package count */
					__( '您有 %d 个已入库包裹，可在「我的仓库」创建包裹。', 'myeasybuy-core' ),
					count( $orders )
				)
			),
			esc_url( $warehouse ),
			esc_html__( '去我的仓库', 'myeasybuy-core' )
		);
	}

	/**
	 * Prompt above orders list.
	 *
	 * @param bool $has_orders Whether customer has orders.
	 */
	public static function render_orders_prompt( $has_orders ): void {
		unset( $has_orders );
		self::render_dashboard_prompt();
	}

	/**
	 * Eligible inbound packages for the customer.
	 *
	 * @param int $customer_id User ID.
	 * @return WC_Order[]
	 */
	public static function get_eligible_orders( int $customer_id ): array {
		if ( $customer_id <= 0 ) {
			return array();
		}

		$orders = wc_get_orders(
			array(
				'customer_id' => $customer_id,
				'status'      => array( str_replace( 'wc-', '', MyEasyBuy_Order_Statuses::STATUS_IN_WAREHOUSE ) ),
				'limit'       => 100,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);

		$result = array();
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			// Skip fee-only second-payment children.
			if ( MyEasyBuy_Consolidation::PAYMENT_TYPE_SECOND === $order->get_meta( MyEasyBuy_Consolidation::META_PAYMENT_TYPE, true ) ) {
				continue;
			}
			$result[] = $order;
		}

		return $result;
	}

	/**
	 * Render My Account consolidation page.
	 */
	public static function render_endpoint(): void {
		if ( ! is_user_logged_in() ) {
			echo '<p>' . esc_html__( '请先登录。', 'myeasybuy-core' ) . '</p>';
			return;
		}

		self::render_notices();

		$orders      = self::get_eligible_orders( get_current_user_id() );
		$channels    = MyEasyBuy_Consolidation::get_channels();
		$pending     = self::get_pending_requests( get_current_user_id() );
		$preselected = self::preselected_order_ids();

		echo '<div class="myeasybuy-consolidation">';
		echo '<h2>' . esc_html__( '合箱申请', 'myeasybuy-core' ) . '</h2>';
		echo '<p class="myeasybuy-consolidation__intro">' . esc_html__( '勾选已入库验货的包裹，选择国际物流渠道后提交。仓库合箱称重后会生成国际运费（二价）账单。', 'myeasybuy-core' ) . '</p>';

		if ( ! empty( $pending ) ) {
			echo '<div class="myeasybuy-consolidation__pending">';
			echo '<h3>' . esc_html__( '处理中的合箱申请', 'myeasybuy-core' ) . '</h3>';
			echo '<ul>';
			foreach ( $pending as $row ) {
				$channel_label = MyEasyBuy_Consolidation::get_channels()[ $row['channel'] ] ?? $row['channel'];
				printf(
					'<li>%1$s · %2$s · %3$s</li>',
					esc_html( sprintf( /* translators: %s: group id */ __( '批次 %s', 'myeasybuy-core' ), $row['group_id'] ) ),
					esc_html( implode( ', ', array_map( static function ( $id ) {
						return '#' . $id;
					}, $row['order_ids'] ) ) ),
					esc_html( $channel_label )
				);
			}
			echo '</ul></div>';
		}

		if ( empty( $orders ) ) {
			echo '<p class="myeasybuy-consolidation__empty">' . esc_html__( '暂无已入库包裹。商品到达深圳仓并完成验货后，会出现在此列表。', 'myeasybuy-core' ) . '</p>';
			echo '</div>';
			return;
		}

		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="myeasybuy-consolidation__form" id="myeasybuy-consolidation-form">
			<?php wp_nonce_field( 'myeasybuy_request_consolidation', 'myeasybuy_consolidation_nonce' ); ?>
			<input type="hidden" name="action" value="myeasybuy_request_consolidation" />
			<input type="hidden" name="redirect_to" value="<?php echo esc_url( self::get_endpoint_url() ); ?>" />

			<div class="myeasybuy-consolidation__toolbar">
				<label class="myeasybuy-consolidation__select-all">
					<input type="checkbox" id="myeasybuy-select-all" />
					<?php esc_html_e( '全选', 'myeasybuy-core' ); ?>
				</label>
				<span class="myeasybuy-consolidation__count" id="myeasybuy-selected-count">0</span>
				<?php esc_html_e( '个包裹已选', 'myeasybuy-core' ); ?>
			</div>

			<table class="shop_table shop_table_responsive myeasybuy-consolidation__table">
				<thead>
					<tr>
						<th class="check-column"><?php esc_html_e( '选择', 'myeasybuy-core' ); ?></th>
						<th><?php esc_html_e( '订单', 'myeasybuy-core' ); ?></th>
						<th><?php esc_html_e( '商品', 'myeasybuy-core' ); ?></th>
						<th><?php esc_html_e( '仓储信息', 'myeasybuy-core' ); ?></th>
						<th><?php esc_html_e( '免费保管', 'myeasybuy-core' ); ?></th>
						<th><?php esc_html_e( '入库时间', 'myeasybuy-core' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $orders as $order ) : ?>
					<?php
					$oid     = (int) $order->get_id();
					$shelf   = (string) $order->get_meta( '_myeasybuy_shelf_no', true );
					$weight  = (string) $order->get_meta( '_myeasybuy_actual_weight', true );
					$dims    = (string) $order->get_meta( '_myeasybuy_dimensions', true );
					$summary = class_exists( 'MyEasyBuy_Customer_Inbound' )
						? MyEasyBuy_Customer_Inbound::get_package_summary( $order )
						: array();
					$items   = array();
					foreach ( $order->get_items( 'line_item' ) as $item ) {
						$items[] = $item->get_name() . ' ×' . $item->get_quantity();
					}
					$date = ! empty( $summary['inbound_at_label'] ) && '—' !== $summary['inbound_at_label']
						? $summary['inbound_at_label']
						: ( $order->get_date_modified() ? $order->get_date_modified()->date_i18n( 'Y-m-d H:i' ) : '' );
					?>
					<tr>
						<td class="check-column">
							<input type="checkbox" class="myeasybuy-package-check" name="order_ids[]" value="<?php echo esc_attr( (string) $oid ); ?>" <?php checked( in_array( $oid, $preselected, true ) ); ?> />
						</td>
						<td data-title="<?php esc_attr_e( '订单', 'myeasybuy-core' ); ?>">
							<a href="<?php echo esc_url( $order->get_view_order_url() ); ?>">#<?php echo esc_html( (string) $oid ); ?></a>
						</td>
						<td data-title="<?php esc_attr_e( '商品', 'myeasybuy-core' ); ?>">
							<?php echo esc_html( implode( '；', $items ) ); ?>
						</td>
						<td data-title="<?php esc_attr_e( '仓储信息', 'myeasybuy-core' ); ?>">
							<?php
							$parts = array();
							if ( $shelf ) {
								$parts[] = sprintf( /* translators: %s: shelf */ __( '货架 %s', 'myeasybuy-core' ), $shelf );
							}
							if ( $weight ) {
								$parts[] = $weight . 'g';
							}
							if ( $dims ) {
								$parts[] = $dims . ' cm';
							}
							echo esc_html( $parts ? implode( ' · ', $parts ) : '—' );
							?>
						</td>
						<td data-title="<?php esc_attr_e( '免费保管', 'myeasybuy-core' ); ?>">
							<?php echo esc_html( $summary['storage_remaining_label'] ?? '—' ); ?>
						</td>
						<td data-title="<?php esc_attr_e( '入库时间', 'myeasybuy-core' ); ?>">
							<?php echo esc_html( $date ?: '—' ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<fieldset class="myeasybuy-consolidation__channels">
				<legend><?php esc_html_e( '物流渠道', 'myeasybuy-core' ); ?></legend>
				<p class="description"><?php esc_html_e( '请选择发往加拿大的运输方式。', 'myeasybuy-core' ); ?></p>
				<?php foreach ( $channels as $slug => $label ) : ?>
					<label class="myeasybuy-consolidation__channel">
						<input type="radio" name="channel" value="<?php echo esc_attr( $slug ); ?>" required />
						<span class="myeasybuy-consolidation__channel-label"><?php echo esc_html( $label ); ?></span>
						<?php if ( MyEasyBuy_Consolidation::CHANNEL_AIR === $slug ) : ?>
							<span class="myeasybuy-consolidation__channel-hint"><?php esc_html_e( '时效较快，按实重与体积重取大计费。', 'myeasybuy-core' ); ?></span>
						<?php else : ?>
							<span class="myeasybuy-consolidation__channel-hint"><?php esc_html_e( '更经济，适合大件/不急件，按实重计费。', 'myeasybuy-core' ); ?></span>
						<?php endif; ?>
					</label>
				<?php endforeach; ?>
			</fieldset>

			<p class="myeasybuy-consolidation__note">
				<?php esc_html_e( '提交后订单进入「申请合箱中」。仓库完成合箱称重后，您将收到国际运费二价支付通知。', 'myeasybuy-core' ); ?>
			</p>

			<p>
				<button type="submit" class="button button-primary myeasybuy-consolidation__submit" id="myeasybuy-consolidation-submit">
					<?php esc_html_e( '提交合箱申请', 'myeasybuy-core' ); ?>
				</button>
			</p>
		</form>
		</div>
		<?php
	}

	/**
	 * Pending consolidating groups for customer.
	 *
	 * @param int $customer_id User ID.
	 * @return array<int, array{group_id: string, channel: string, order_ids: int[]}>
	 */
	public static function get_pending_requests( int $customer_id ): array {
		$orders = wc_get_orders(
			array(
				'customer_id' => $customer_id,
				'status'      => array( str_replace( 'wc-', '', MyEasyBuy_Order_Statuses::STATUS_CONSOLIDATING ) ),
				'limit'       => 50,
			)
		);

		$groups = array();
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			$group = (string) $order->get_meta( self::META_GROUP_ID, true );
			if ( '' === $group ) {
				$group = 'order-' . $order->get_id();
			}
			if ( ! isset( $groups[ $group ] ) ) {
				$groups[ $group ] = array(
					'group_id'  => $group,
					'channel'   => (string) ( $order->get_meta( self::META_REQUESTED_CHANNEL, true ) ?: $order->get_meta( MyEasyBuy_Consolidation::META_SHIPPING_CHANNEL, true ) ),
					'order_ids' => array(),
				);
			}
			$groups[ $group ]['order_ids'][] = (int) $order->get_id();
		}

		return array_values( $groups );
	}

	/**
	 * Handle consolidation request form.
	 */
	public static function handle_request(): void {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( '请先登录。', 'myeasybuy-core' ) );
		}

		check_admin_referer( 'myeasybuy_request_consolidation', 'myeasybuy_consolidation_nonce' );

		$user_id   = get_current_user_id();
		$order_ids = isset( $_POST['order_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['order_ids'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$channel   = isset( $_POST['channel'] ) ? sanitize_key( wp_unslash( (string) $_POST['channel'] ) ) : '';
		$redirect  = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( (string) $_POST['redirect_to'] ) ) : self::get_endpoint_url();

		if ( function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		$result = MyEasyBuy_Consolidation::request_customer_consolidation( $order_ids, $channel, $user_id );

		if ( is_wp_error( $result ) ) {
			wc_add_notice( $result->get_error_message(), 'error' );
		} else {
			wc_add_notice(
				sprintf(
					/* translators: 1: package count, 2: channel label */
					__( '已提交 %1$d 个包裹的合箱申请（%2$s）。仓库处理后将通知您支付国际运费。', 'myeasybuy-core' ),
					(int) ( $result['count'] ?? 0 ),
					MyEasyBuy_Consolidation::get_channels()[ $channel ] ?? $channel
				),
				'success'
			);
		}

		wp_safe_redirect( $redirect ? $redirect : self::get_endpoint_url() );
		exit;
	}

	/**
	 * Flash WC notices on endpoint (account pages already print them).
	 */
	private static function render_notices(): void {
		// WooCommerce account templates print notices automatically.
	}

	/**
	 * Order IDs carried over from 我的仓库.
	 *
	 * @return int[]
	 */
	private static function preselected_order_ids(): array {
		if ( empty( $_GET['meb_ids'] ) || ! is_string( $_GET['meb_ids'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return array();
		}

		$raw = sanitize_text_field( wp_unslash( $_GET['meb_ids'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return array_values( array_filter( array_map( 'absint', explode( ',', $raw ) ) ) );
	}
}
