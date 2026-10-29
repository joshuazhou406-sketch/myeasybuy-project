<?php
/**
 * Admin order lifecycle monitor for reverse proxy shopping.
 *
 * @package MyEasyBuy_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders a dashboard to monitor all orders across the MyEasyBuy pipeline.
 */
class MyEasyBuy_Admin_Monitor {

	public const PAGE_SLUG = 'myeasybuy-order-monitor';

	/**
	 * Pipeline stages in business order (slug without wc- prefix => label).
	 *
	 * @return array<string, string>
	 */
	public static function get_pipeline_stages(): array {
		$stages = array(
			'pending'                 => __( '待付一价', 'myeasybuy-core' ),
			'pending-manual'          => __( '待人工补录', 'myeasybuy-core' ),
			'processing'              => __( '处理中', 'myeasybuy-core' ),
			'purchasing'              => __( '平台代采中', 'myeasybuy-core' ),
			'cn-shipped'              => __( '国内已发货', 'myeasybuy-core' ),
			'in-warehouse'            => __( '已入库验货', 'myeasybuy-core' ),
			'consolidating'           => __( '申请合箱中', 'myeasybuy-core' ),
			'awaiting-shipping-fee'   => __( '待付国际运费', 'myeasybuy-core' ),
			'paid-shipping'           => __( '已付二价/待出库', 'myeasybuy-core' ),
			'int-shipped'             => __( '国际已发货', 'myeasybuy-core' ),
			'completed'               => __( '已完结/签收', 'myeasybuy-core' ),
			'cancelled'               => __( '已取消', 'myeasybuy-core' ),
			'failed'                  => __( '失败', 'myeasybuy-core' ),
			'refunded'                => __( '已退款', 'myeasybuy-core' ),
		);

		/**
		 * Filter monitor pipeline stages.
		 *
		 * @since 1.4.0
		 *
		 * @param array<string, string> $stages Stage slug => label.
		 */
		return apply_filters( 'myeasybuy_monitor_pipeline_stages', $stages );
	}

	/**
	 * Stages shown in the compact progress bar (core reverse-shopping flow).
	 *
	 * @return array<int, string>
	 */
	public static function get_progress_stage_slugs(): array {
		return array(
			'pending-manual',
			'pending',
			'purchasing',
			'cn-shipped',
			'in-warehouse',
			'consolidating',
			'awaiting-shipping-fee',
			'paid-shipping',
			'int-shipped',
			'completed',
		);
	}

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 9 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Register top-level / WooCommerce monitor menu.
	 */
	public static function register_menu(): void {
		add_menu_page(
			__( '订单全流程监控', 'myeasybuy-core' ),
			__( '海淘订单监控', 'myeasybuy-core' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' ),
			'dashicons-visibility',
			56
		);

		add_submenu_page(
			'woocommerce',
			__( '订单全流程监控', 'myeasybuy-core' ),
			__( '订单全流程监控', 'myeasybuy-core' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueue monitor assets.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::PAGE_SLUG !== $page && false === strpos( $hook_suffix, self::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'myeasybuy-admin-monitor',
			MYEASYBUY_CORE_URL . 'assets/css/admin-monitor.css',
			array(),
			MYEASYBUY_CORE_VERSION
		);

		wp_enqueue_script(
			'myeasybuy-admin-monitor',
			MYEASYBUY_CORE_URL . 'assets/js/admin-monitor.js',
			array( 'jquery' ),
			MYEASYBUY_CORE_VERSION,
			true
		);

		wp_localize_script(
			'myeasybuy-admin-monitor',
			'myeasybuyMonitor',
			array(
				/**
				 * Auto-refresh every 2 minutes while the monitor tab is visible.
				 * Set autoRefresh false via filter if undesired.
				 */
				'autoRefresh' => (bool) apply_filters( 'myeasybuy_monitor_auto_refresh', true ),
				'intervalMs'  => (int) apply_filters( 'myeasybuy_monitor_refresh_interval_ms', 120000 ),
				'refreshing'  => __( '正在刷新…', 'myeasybuy-core' ),
			)
		);
	}

	/**
	 * Render monitor page.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'myeasybuy-core' ) );
		}

		$filters = self::get_request_filters();
		$counts  = self::get_status_counts();
		$result  = self::query_orders( $filters );

		echo '<div class="wrap myeasybuy-monitor-wrap">';
		echo '<h1>' . esc_html__( '反向海淘 · 订单全流程监控', 'myeasybuy-core' ) . '</h1>';
		echo '<p class="description">' . esc_html__( '实时查看每笔订单在补录 → 代采 → 入库 → 合箱二价 → 国际发货 → 签收全链路中的状态。', 'myeasybuy-core' ) . '</p>';

		self::render_summary_cards( $counts, $filters['status'] );
		self::render_filters( $filters, (int) $result['total'] );
		self::render_orders_table( $result['orders'], $filters );
		self::render_pagination( (int) $result['total'], (int) $filters['paged'], (int) $filters['per_page'] );

		echo '</div>';
	}

	/**
	 * Parse list filters from request.
	 *
	 * @return array<string, mixed>
	 */
	private static function get_request_filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$status   = isset( $_GET['status'] ) ? sanitize_title( wp_unslash( (string) $_GET['status'] ) ) : '';
		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '';
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$per_page = isset( $_GET['per_page'] ) ? absint( $_GET['per_page'] ) : 20;
		// phpcs:enable

		if ( $per_page < 10 ) {
			$per_page = 20;
		}
		if ( $per_page > 100 ) {
			$per_page = 100;
		}

		return array(
			'status'   => $status,
			's'        => $search,
			'paged'    => $paged,
			'per_page' => $per_page,
		);
	}

	/**
	 * Count orders per pipeline status.
	 *
	 * @return array<string, int>
	 */
	private static function get_status_counts(): array {
		$counts = array();

		foreach ( array_keys( self::get_pipeline_stages() ) as $slug ) {
			$result = wc_get_orders(
				array(
					'status'   => array( $slug ),
					'limit'    => 1,
					'paginate' => true,
					'return'   => 'ids',
				)
			);

			$counts[ $slug ] = ( is_object( $result ) && isset( $result->total ) )
				? (int) $result->total
				: 0;
		}

		/*
		 * Paid first-payment orders may briefly sit in WooCommerce 「processing」
		 * before being moved to 「purchasing」. Progress UI maps processing→代采中,
		 * so fold those into the purchasing summary card for consistency.
		 */
		$processing_paid = self::count_paid_processing_first_payment_orders();
		if ( $processing_paid > 0 ) {
			$counts['purchasing'] = (int) ( $counts['purchasing'] ?? 0 ) + $processing_paid;
		}

		return $counts;
	}

	/**
	 * Count paid first-payment orders still in core 「processing」 status.
	 */
	private static function count_paid_processing_first_payment_orders(): int {
		$orders = wc_get_orders(
			array(
				'status' => array( 'processing' ),
				'limit'  => 100,
				'return' => 'objects',
			)
		);

		$count = 0;
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order || ! $order->is_paid() ) {
				continue;
			}
			if ( class_exists( 'MyEasyBuy_Consolidation' )
				&& MyEasyBuy_Consolidation::PAYMENT_TYPE_SECOND === $order->get_meta( MyEasyBuy_Consolidation::META_PAYMENT_TYPE, true ) ) {
				continue;
			}
			++$count;
		}

		return $count;
	}

	/**
	 * Query orders for the monitor table.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return array{orders: WC_Order[], total: int}
	 */
	private static function query_orders( array $filters ): array {
		$args = array(
			'limit'    => (int) $filters['per_page'],
			'page'     => (int) $filters['paged'],
			'paginate' => true,
			'orderby'  => 'date',
			'order'    => 'DESC',
		);

		if ( '' !== $filters['status'] ) {
			$args['status'] = array( $filters['status'] );
		} else {
			$args['status'] = array_keys( self::get_pipeline_stages() );
		}

		if ( '' !== $filters['s'] ) {
			$search = $filters['s'];
			if ( ctype_digit( $search ) ) {
				$args['include'] = array( (int) $search );
				unset( $args['status'] );
			} else {
				// Search by billing email / name / tracking meta via custom query later.
				$args['search'] = '*' . $search . '*';
				$args['search_filter'] = 'all';
			}
		}

		$result = wc_get_orders( $args );

		$orders = array();
		$total  = 0;

		if ( is_object( $result ) && isset( $result->orders ) ) {
			$orders = $result->orders;
			$total  = (int) $result->total;
		} elseif ( is_array( $result ) ) {
			$orders = $result;
			$total  = count( $result );
		}

		if ( class_exists( 'MyEasyBuy_Procurement' ) ) {
			foreach ( $orders as $order ) {
				if ( $order instanceof WC_Order ) {
					MyEasyBuy_Procurement::maybe_repair_second_payment_rollback( $order );
				}
			}
		}

		// Meta tracking search fallback when WC search misses tracking numbers.
		if ( '' !== $filters['s'] && ! ctype_digit( $filters['s'] ) && empty( $orders ) ) {
			$meta_orders = wc_get_orders(
				array(
					'limit'      => (int) $filters['per_page'],
					'page'       => (int) $filters['paged'],
					'paginate'   => true,
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						'relation' => 'OR',
						array(
							'key'     => '_myeasybuy_cn_tracking',
							'value'   => $filters['s'],
							'compare' => 'LIKE',
						),
						array(
							'key'     => '_myeasybuy_int_tracking_number',
							'value'   => $filters['s'],
							'compare' => 'LIKE',
						),
						array(
							'key'     => '_myeasybuy_shelf_no',
							'value'   => $filters['s'],
							'compare' => 'LIKE',
						),
					),
				)
			);

			if ( is_object( $meta_orders ) && isset( $meta_orders->orders ) ) {
				$orders = $meta_orders->orders;
				$total  = (int) $meta_orders->total;
			}
		}

		return array(
			'orders' => $orders,
			'total'  => $total,
		);
	}

	/**
	 * Render status summary cards.
	 *
	 * @param array<string, int> $counts        Status counts.
	 * @param string             $active_status Active filter.
	 */
	private static function render_summary_cards( array $counts, string $active_status ): void {
		$highlight = array(
			'pending-manual',
			'purchasing',
			'cn-shipped',
			'in-warehouse',
			'consolidating',
			'awaiting-shipping-fee',
			'paid-shipping',
			'int-shipped',
			'completed',
		);

		$stages = self::get_pipeline_stages();

		echo '<div class="myeasybuy-monitor-cards">';
		foreach ( $highlight as $slug ) {
			if ( ! isset( $stages[ $slug ] ) ) {
				continue;
			}
			$url   = self::page_url( array( 'status' => $slug ) );
			$class = 'myeasybuy-monitor-card';
			if ( $active_status === $slug ) {
				$class .= ' is-active';
			}
			printf(
				'<a class="%1$s" href="%2$s"><span class="myeasybuy-monitor-card__count">%3$d</span><span class="myeasybuy-monitor-card__label">%4$s</span></a>',
				esc_attr( $class ),
				esc_url( $url ),
				(int) ( $counts[ $slug ] ?? 0 ),
				esc_html( $stages[ $slug ] )
			);
		}
		echo '</div>';
	}

	/**
	 * Render filter form.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @param int                  $total   Total matched.
	 */
	private static function render_filters( array $filters, int $total ): void {
		$stages = self::get_pipeline_stages();
		?>
		<form method="get" class="myeasybuy-monitor-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
			<label>
				<span class="screen-reader-text"><?php esc_html_e( '状态', 'myeasybuy-core' ); ?></span>
				<select name="status">
					<option value=""><?php esc_html_e( '全部状态', 'myeasybuy-core' ); ?></option>
					<?php foreach ( $stages as $slug => $label ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $filters['status'], $slug ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>
			<label>
				<span class="screen-reader-text"><?php esc_html_e( '搜索', 'myeasybuy-core' ); ?></span>
				<input type="search" name="s" value="<?php echo esc_attr( (string) $filters['s'] ); ?>" placeholder="<?php esc_attr_e( '订单号 / 客户 / 快递单号 / 货架号', 'myeasybuy-core' ); ?>" class="regular-text" />
			</label>
			<button type="submit" class="button button-primary"><?php esc_html_e( '筛选', 'myeasybuy-core' ); ?></button>
			<a class="button" href="<?php echo esc_url( self::page_url() ); ?>"><?php esc_html_e( '重置', 'myeasybuy-core' ); ?></a>
			<button type="button" class="button" id="myeasybuy-monitor-refresh"><?php esc_html_e( '刷新', 'myeasybuy-core' ); ?></button>
			<span class="myeasybuy-monitor-total">
				<?php
				printf(
					/* translators: %d: order count */
					esc_html__( '共 %d 笔订单', 'myeasybuy-core' ),
					$total
				);
				?>
			</span>
			<span class="myeasybuy-monitor-updated" id="myeasybuy-monitor-updated">
				<?php
				printf(
					/* translators: %s: current time */
					esc_html__( '更新于 %s', 'myeasybuy-core' ),
					esc_html( wp_date( 'Y-m-d H:i:s' ) )
				);
				?>
			</span>
		</form>
		<?php
	}

	/**
	 * Render orders table.
	 *
	 * @param WC_Order[]           $orders  Orders.
	 * @param array<string, mixed> $filters Filters.
	 */
	private static function render_orders_table( array $orders, array $filters ): void {
		unset( $filters );

		if ( empty( $orders ) ) {
			echo '<p class="myeasybuy-empty">' . esc_html__( '没有符合条件的订单。', 'myeasybuy-core' ) . '</p>';
			return;
		}

		?>
		<table class="widefat striped myeasybuy-monitor-table">
			<thead>
				<tr>
					<th><?php esc_html_e( '订单', 'myeasybuy-core' ); ?></th>
					<th><?php esc_html_e( '客户', 'myeasybuy-core' ); ?></th>
					<th><?php esc_html_e( '当前状态', 'myeasybuy-core' ); ?></th>
					<th><?php esc_html_e( '流程进度', 'myeasybuy-core' ); ?></th>
					<th><?php esc_html_e( '国内物流', 'myeasybuy-core' ); ?></th>
					<th><?php esc_html_e( '仓储', 'myeasybuy-core' ); ?></th>
					<th><?php esc_html_e( '二价/国际', 'myeasybuy-core' ); ?></th>
					<th><?php esc_html_e( '操作', 'myeasybuy-core' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $orders as $order ) : ?>
				<?php self::render_order_row( $order ); ?>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render a single order row.
	 *
	 * @param WC_Order $order Order.
	 */
	private static function render_order_row( WC_Order $order ): void {
		$status_slug  = $order->get_status();
		$status_label = self::get_status_label( $status_slug );
		$customer     = trim( $order->get_formatted_billing_full_name() );
		if ( '' === $customer ) {
			$customer = $order->get_billing_email() ?: __( 'Guest', 'myeasybuy-core' );
		}

		$cn_carrier  = (string) $order->get_meta( '_myeasybuy_cn_carrier', true );
		$cn_tracking = (string) $order->get_meta( '_myeasybuy_cn_tracking', true );
		$shelf       = (string) $order->get_meta( '_myeasybuy_shelf_no', true );
		$weight      = (string) $order->get_meta( '_myeasybuy_actual_weight', true );
		$dims        = (string) $order->get_meta( '_myeasybuy_dimensions', true );
		$int_carrier = (string) $order->get_meta( '_myeasybuy_int_carrier', true );
		$int_track   = (string) $order->get_meta( '_myeasybuy_int_tracking_number', true );
		$second_fee  = $order->get_meta( '_myeasybuy_second_payment_fee', true );
		$second_oid  = (int) $order->get_meta( '_myeasybuy_second_payment_order_id', true );
		$latest      = $order->get_meta( '_myeasybuy_latest_track', true );
		$latest_desc = '';
		if ( is_array( $latest ) ) {
			$latest_desc = (string) ( $latest['description'] ?? $latest['track_code'] ?? '' );
		}

		$date = $order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y-m-d H:i' ) : '';
		?>
		<tr>
			<td>
				<strong><a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">#<?php echo esc_html( (string) $order->get_id() ); ?></a></strong>
				<div class="myeasybuy-monitor-muted"><?php echo esc_html( $date ); ?></div>
				<div class="myeasybuy-monitor-muted"><?php echo esc_html( wp_strip_all_tags( $order->get_formatted_order_total() ) ); ?></div>
			</td>
			<td>
				<?php echo esc_html( $customer ); ?>
				<?php if ( $order->get_billing_email() ) : ?>
					<div class="myeasybuy-monitor-muted"><?php echo esc_html( $order->get_billing_email() ); ?></div>
				<?php endif; ?>
			</td>
			<td>
				<span class="myeasybuy-monitor-status status-<?php echo esc_attr( $status_slug ); ?>">
					<?php echo esc_html( $status_label ); ?>
				</span>
			</td>
			<td class="myeasybuy-monitor-progress-cell">
				<?php self::render_progress_bar( $status_slug ); ?>
			</td>
			<td>
				<?php if ( $cn_tracking || $cn_carrier ) : ?>
					<div><?php echo esc_html( $cn_carrier ?: '—' ); ?></div>
					<code><?php echo esc_html( $cn_tracking ?: '—' ); ?></code>
				<?php else : ?>
					<span class="myeasybuy-monitor-muted">—</span>
				<?php endif; ?>
			</td>
			<td>
				<?php if ( $shelf || $weight || $dims ) : ?>
					<div><?php echo esc_html( $shelf ? sprintf( /* translators: %s: shelf */ __( '货架 %s', 'myeasybuy-core' ), $shelf ) : '—' ); ?></div>
					<div class="myeasybuy-monitor-muted">
						<?php
						$parts = array();
						if ( $weight ) {
							$parts[] = $weight . 'g';
						}
						if ( $dims ) {
							$parts[] = $dims . ' cm';
						}
						echo esc_html( $parts ? implode( ' · ', $parts ) : '—' );
						?>
					</div>
				<?php else : ?>
					<span class="myeasybuy-monitor-muted">—</span>
				<?php endif; ?>
			</td>
			<td>
				<?php if ( '' !== (string) $second_fee || $second_oid ) : ?>
					<div>
						<?php
						echo wp_kses_post(
							wc_price(
								(float) $second_fee,
								array( 'currency' => $order->get_currency() )
							)
						);
						?>
						<?php
						if ( $second_oid ) :
							$second_order = wc_get_order( $second_oid );
							$second_url   = $second_order ? $second_order->get_edit_order_url() : admin_url( 'post.php?post=' . $second_oid . '&action=edit' );
							?>
							<a href="<?php echo esc_url( $second_url ); ?>">#<?php echo esc_html( (string) $second_oid ); ?></a>
						<?php endif; ?>
					</div>
				<?php endif; ?>
				<?php if ( $int_track || $int_carrier ) : ?>
					<div><?php echo esc_html( $int_carrier ?: '—' ); ?></div>
					<code><?php echo esc_html( $int_track ?: '—' ); ?></code>
				<?php elseif ( '' === (string) $second_fee && ! $second_oid ) : ?>
					<span class="myeasybuy-monitor-muted">—</span>
				<?php endif; ?>
				<?php if ( $latest_desc ) : ?>
					<div class="myeasybuy-monitor-track" title="<?php echo esc_attr( $latest_desc ); ?>">
						<?php echo esc_html( wp_html_excerpt( $latest_desc, 42, '…' ) ); ?>
					</div>
				<?php endif; ?>
			</td>
			<td class="myeasybuy-monitor-actions">
				<?php foreach ( self::get_action_links( $order ) as $link ) : ?>
					<a class="button button-small" href="<?php echo esc_url( $link['url'] ); ?>"><?php echo esc_html( $link['label'] ); ?></a>
				<?php endforeach; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Visual pipeline progress for an order status.
	 *
	 * @param string $current_status Current status slug (no wc-).
	 */
	private static function render_progress_bar( string $current_status ): void {
		$stages  = self::get_progress_stage_slugs();
		$labels  = self::get_pipeline_stages();
		$current = str_replace( 'wc-', '', $current_status );
		$index   = array_search( $current, $stages, true );

		// Map nearby WC core statuses into the pipeline.
		if ( false === $index ) {
			$map = array(
				'pending'    => 'pending',
				'on-hold'    => 'pending',
				'processing' => 'purchasing',
				'completed'  => 'completed',
			);
			if ( isset( $map[ $current ] ) ) {
				$index = array_search( $map[ $current ], $stages, true );
				$current = $map[ $current ];
			}
		}

		echo '<ol class="myeasybuy-progress">';
		foreach ( $stages as $i => $slug ) {
			$class = 'myeasybuy-progress__step';
			if ( false !== $index && $i < $index ) {
				$class .= ' is-done';
			} elseif ( false !== $index && $i === $index ) {
				$class .= ' is-current';
			}
			printf(
				'<li class="%1$s" title="%2$s"><span>%3$d</span></li>',
				esc_attr( $class ),
				esc_attr( $labels[ $slug ] ?? $slug ),
				$i + 1
			);
		}
		echo '</ol>';
		if ( isset( $labels[ $current ] ) ) {
			echo '<div class="myeasybuy-progress__caption">' . esc_html( $labels[ $current ] ) . '</div>';
		}
	}

	/**
	 * Contextual workflow shortcuts for an order.
	 *
	 * @param WC_Order $order Order.
	 * @return array<int, array{label: string, url: string}>
	 */
	private static function get_action_links( WC_Order $order ): array {
		$status = MyEasyBuy_Order_Statuses::normalize_status( $order->get_status() );
		$links  = array(
			array(
				'label' => __( '订单详情', 'myeasybuy-core' ),
				'url'   => $order->get_edit_order_url(),
			),
		);

		$map = array(
			MyEasyBuy_Order_Statuses::STATUS_PENDING_MANUAL        => array(
				'label' => __( '补录', 'myeasybuy-core' ),
				'page'  => MyEasyBuy_Admin_Workflow::PAGE_MANUAL_ENTRY,
			),
			MyEasyBuy_Order_Statuses::STATUS_PURCHASING            => array(
				'label' => __( '代采发货', 'myeasybuy-core' ),
				'page'  => MyEasyBuy_Admin_Workflow::PAGE_PURCHASING,
			),
			'wc-processing'                                       => array(
				'label' => __( '代采发货', 'myeasybuy-core' ),
				'page'  => MyEasyBuy_Admin_Workflow::PAGE_PURCHASING,
			),
			MyEasyBuy_Order_Statuses::STATUS_CN_SHIPPED            => array(
				'label' => __( '入库', 'myeasybuy-core' ),
				'page'  => MyEasyBuy_Admin_Workflow::PAGE_INBOUND,
			),
			MyEasyBuy_Order_Statuses::STATUS_IN_WAREHOUSE          => array(
				'label' => __( '合箱二价', 'myeasybuy-core' ),
				'page'  => MyEasyBuy_Admin_Workflow::PAGE_CONSOLIDATION,
			),
			MyEasyBuy_Order_Statuses::STATUS_CONSOLIDATING         => array(
				'label' => __( '合箱二价', 'myeasybuy-core' ),
				'page'  => MyEasyBuy_Admin_Workflow::PAGE_CONSOLIDATION,
			),
			MyEasyBuy_Order_Statuses::STATUS_PAID_SHIPPING         => array(
				'label' => __( '国际发货', 'myeasybuy-core' ),
				'page'  => MyEasyBuy_Admin_Workflow::PAGE_INT_SHIPPING,
			),
		);

		if ( isset( $map[ $status ] ) ) {
			$query = array( 'page' => $map[ $status ]['page'] );
			if ( MyEasyBuy_Admin_Workflow::PAGE_INBOUND === $map[ $status ]['page'] ) {
				$query['tracking'] = $order->get_meta( '_myeasybuy_cn_tracking', true );
			} else {
				$query['order_id'] = $order->get_id();
			}
			$links[] = array(
				'label' => $map[ $status ]['label'],
				'url'   => add_query_arg( $query, admin_url( 'admin.php' ) ),
			);
		}

		return $links;
	}

	/**
	 * Pagination controls.
	 *
	 * @param int $total    Total items.
	 * @param int $paged    Current page.
	 * @param int $per_page Per page.
	 */
	private static function render_pagination( int $total, int $paged, int $per_page ): void {
		$pages = (int) ceil( $total / max( 1, $per_page ) );
		if ( $pages <= 1 ) {
			return;
		}

		echo '<div class="tablenav"><div class="tablenav-pages">';
		echo wp_kses_post(
			paginate_links(
				array(
					'base'      => add_query_arg( 'paged', '%#%' ),
					'format'    => '',
					'prev_text' => '&laquo;',
					'next_text' => '&raquo;',
					'total'     => $pages,
					'current'   => $paged,
				)
			)
		);
		echo '</div></div>';
	}

	/**
	 * Human label for a status slug.
	 *
	 * @param string $status Status without wc- prefix.
	 */
	private static function get_status_label( string $status ): string {
		$status = str_replace( 'wc-', '', $status );
		$stages = self::get_pipeline_stages();
		if ( isset( $stages[ $status ] ) ) {
			return $stages[ $status ];
		}

		$wc = wc_get_order_statuses();
		$key = 'wc-' . $status;
		return $wc[ $key ] ?? $status;
	}

	/**
	 * Build monitor page URL.
	 *
	 * @param array<string, mixed> $args Query args.
	 */
	private static function page_url( array $args = array() ): string {
		$args = array_merge( array( 'page' => self::PAGE_SLUG ), $args );
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}
}
