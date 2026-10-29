<?php
/**
 * Admin workflow panels for domestic buyers and warehouse staff.
 *
 * @package MyEasyBuy_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders and handles MyEasyBuy admin workflow screens.
 */
class MyEasyBuy_Admin_Workflow {

	public const PAGE_MANUAL_ENTRY      = 'myeasybuy-manual-entry';
	public const PAGE_PURCHASING        = 'myeasybuy-purchasing';
	public const PAGE_PROCUREMENT_JOBS  = 'myeasybuy-procurement-jobs';
	public const PAGE_INBOUND           = 'myeasybuy-inbound';
	public const PAGE_INSPECTION        = 'myeasybuy-inspection';
	public const PAGE_CONSOLIDATION     = 'myeasybuy-consolidation';
	public const PAGE_INT_SHIPPING      = 'myeasybuy-int-shipping';

	public const MENU_BUYER     = 'myeasybuy-buyer-portal';
	public const MENU_WAREHOUSE = 'myeasybuy-warehouse-portal';

	/** Order item meta keys. */
	public const META_SOURCE_URL = '_myeasybuy_source_url';
	public const META_ITEM_TITLE = '_myeasybuy_item_title';
	public const META_IMAGE_URL  = '_myeasybuy_image_url';
	public const META_PRICE_RMB  = '_myeasybuy_price_rmb';
	public const META_SKU_SPEC   = '_myeasybuy_sku_spec';

	/** Order meta keys. */
	public const META_CN_CARRIER  = '_myeasybuy_cn_carrier';
	public const META_CN_TRACKING = '_myeasybuy_cn_tracking';
	public const META_WEIGHT      = '_myeasybuy_actual_weight';
	public const META_DIMENSIONS  = '_myeasybuy_dimensions';
	public const META_PHOTOS      = '_myeasybuy_inspection_photos';
	public const META_SHELF_NO     = '_myeasybuy_shelf_no';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menus' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_post_myeasybuy_save_manual_entry', array( __CLASS__, 'handle_save_manual_entry' ) );
		add_action( 'admin_post_myeasybuy_save_cn_shipping', array( __CLASS__, 'handle_save_cn_shipping' ) );
		add_action( 'admin_post_myeasybuy_save_inbound', array( __CLASS__, 'handle_save_inbound' ) );
		add_action( 'admin_post_myeasybuy_generate_second_payment', array( __CLASS__, 'handle_generate_second_payment' ) );
		add_action( 'admin_post_myeasybuy_save_int_shipping', array( __CLASS__, 'handle_save_int_shipping' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_order_metabox' ) );
	}

	/**
	 * Register portal menus (buyer / warehouse) and manager-only WC shortcuts.
	 */
	public static function register_menus(): void {
		$can_buyer     = MyEasyBuy_Roles::current_user_can_buyer();
		$can_warehouse = MyEasyBuy_Roles::current_user_can_warehouse();

		if ( $can_buyer ) {
			add_menu_page(
				__( '国内买手工作台', 'myeasybuy-core' ),
				__( '国内买手', 'myeasybuy-core' ),
				MyEasyBuy_Roles::CAP_BUYER,
				self::MENU_BUYER,
				array( __CLASS__, 'render_buyer_portal_home' ),
				'dashicons-cart',
				56
			);

			add_submenu_page(
				self::MENU_BUYER,
				__( '工作台概览', 'myeasybuy-core' ),
				__( '工作台概览', 'myeasybuy-core' ),
				MyEasyBuy_Roles::CAP_BUYER,
				self::MENU_BUYER,
				array( __CLASS__, 'render_buyer_portal_home' )
			);

			add_submenu_page(
				self::MENU_BUYER,
				__( '待补录商品', 'myeasybuy-core' ),
				__( '待补录商品', 'myeasybuy-core' ),
				MyEasyBuy_Roles::CAP_BUYER,
				self::PAGE_MANUAL_ENTRY,
				array( __CLASS__, 'render_manual_entry_page' )
			);

			add_submenu_page(
				self::MENU_BUYER,
				__( '待采购列表', 'myeasybuy-core' ),
				__( '待采购列表', 'myeasybuy-core' ),
				MyEasyBuy_Roles::CAP_BUYER,
				self::PAGE_PURCHASING,
				array( __CLASS__, 'render_purchasing_page' )
			);

			add_submenu_page(
				self::MENU_BUYER,
				__( '代采工单', 'myeasybuy-core' ),
				__( '代采工单', 'myeasybuy-core' ),
				MyEasyBuy_Roles::CAP_BUYER,
				self::PAGE_PROCUREMENT_JOBS,
				array( __CLASS__, 'render_procurement_jobs_page' )
			);
		}

		if ( $can_warehouse ) {
			add_menu_page(
				__( '集运仓储工作台', 'myeasybuy-core' ),
				__( '集运仓储', 'myeasybuy-core' ),
				MyEasyBuy_Roles::CAP_WAREHOUSE,
				self::MENU_WAREHOUSE,
				array( __CLASS__, 'render_warehouse_portal_home' ),
				'dashicons-archive',
				57
			);

			add_submenu_page(
				self::MENU_WAREHOUSE,
				__( '工作台概览', 'myeasybuy-core' ),
				__( '工作台概览', 'myeasybuy-core' ),
				MyEasyBuy_Roles::CAP_WAREHOUSE,
				self::MENU_WAREHOUSE,
				array( __CLASS__, 'render_warehouse_portal_home' )
			);

			add_submenu_page(
				self::MENU_WAREHOUSE,
				__( '扫码入库', 'myeasybuy-core' ),
				__( '扫码入库', 'myeasybuy-core' ),
				MyEasyBuy_Roles::CAP_WAREHOUSE,
				self::PAGE_INBOUND,
				array( __CLASS__, 'render_inbound_page' )
			);

			add_submenu_page(
				self::MENU_WAREHOUSE,
				__( '拍照/称重上传', 'myeasybuy-core' ),
				__( '拍照/称重上传', 'myeasybuy-core' ),
				MyEasyBuy_Roles::CAP_WAREHOUSE,
				self::PAGE_INSPECTION,
				array( __CLASS__, 'render_inspection_page' )
			);

			add_submenu_page(
				self::MENU_WAREHOUSE,
				__( '合箱打包工单', 'myeasybuy-core' ),
				__( '合箱打包工单', 'myeasybuy-core' ),
				MyEasyBuy_Roles::CAP_WAREHOUSE,
				self::PAGE_CONSOLIDATION,
				array( __CLASS__, 'render_consolidation_page' )
			);

			add_submenu_page(
				self::MENU_WAREHOUSE,
				__( '国际发货', 'myeasybuy-core' ),
				__( '国际发货', 'myeasybuy-core' ),
				MyEasyBuy_Roles::CAP_WAREHOUSE,
				self::PAGE_INT_SHIPPING,
				array( __CLASS__, 'render_int_shipping_page' )
			);
		}
	}

	/**
	 * Enqueue admin assets on workflow pages and order edit screen.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_workflow_page = isset( $_GET['page'] ) && in_array( // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			sanitize_key( wp_unslash( (string) $_GET['page'] ) ),
			array(
				self::MENU_BUYER,
				self::MENU_WAREHOUSE,
				self::PAGE_MANUAL_ENTRY,
				self::PAGE_PURCHASING,
				self::PAGE_PROCUREMENT_JOBS,
				self::PAGE_INBOUND,
				self::PAGE_INSPECTION,
				self::PAGE_CONSOLIDATION,
				self::PAGE_INT_SHIPPING,
			),
			true
		);

		$is_order_screen = $screen && in_array( $screen->id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true );

		if ( ! $is_workflow_page && ! $is_order_screen ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_style(
			'myeasybuy-admin-workflow',
			MYEASYBUY_CORE_URL . 'assets/css/admin-workflow.css',
			array(),
			MYEASYBUY_CORE_VERSION
		);
		wp_enqueue_script(
			'myeasybuy-admin-workflow',
			MYEASYBUY_CORE_URL . 'assets/js/admin-workflow.js',
			array( 'jquery', 'media-upload', 'media-views' ),
			MYEASYBUY_CORE_VERSION,
			true
		);
		wp_localize_script(
			'myeasybuy-admin-workflow',
			'myeasybuyAdmin',
			array(
				'selectPhotos'  => __( '选择验货图片', 'myeasybuy-core' ),
				'usePhotos'     => __( '使用所选图片', 'myeasybuy-core' ),
				'removePhoto'   => __( '移除', 'myeasybuy-core' ),
				'noPhotos'      => __( '尚未选择图片', 'myeasybuy-core' ),
			)
		);
	}

	/**
	 * Domestic buyer portal home.
	 */
	public static function render_buyer_portal_home(): void {
		if ( ! self::current_user_can_buyer() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'myeasybuy-core' ) );
		}

		$manual_count = count( self::get_orders_by_status( MyEasyBuy_Order_Statuses::STATUS_PENDING_MANUAL ) );
		$buy_count    = count( self::get_purchasing_queue_orders() );
		$jobs_count   = class_exists( 'MyEasyBuy_Procurement' )
			? count( MyEasyBuy_Procurement::list_jobs( array( MyEasyBuy_Procurement::STATUS_PENDING, MyEasyBuy_Procurement::STATUS_PROCESSING ), 200 ) )
			: 0;

		self::render_admin_header(
			__( '国内买手工作台', 'myeasybuy-core' ),
			__( '仅显示补录、待采购与代采相关工单。', 'myeasybuy-core' )
		);
		self::render_admin_notices();
		self::render_portal_cards(
			array(
				array(
					'title' => __( '待补录商品', 'myeasybuy-core' ),
					'desc'  => __( '反爬拦截 / 信息不全商品补录', 'myeasybuy-core' ),
					'count' => $manual_count,
					'url'   => self::page_url( self::PAGE_MANUAL_ENTRY ),
				),
				array(
					'title' => __( '待采购列表', 'myeasybuy-core' ),
					'desc'  => __( '已付一价、待国内代采发货', 'myeasybuy-core' ),
					'count' => $buy_count,
					'url'   => self::page_url( self::PAGE_PURCHASING ),
				),
				array(
					'title' => __( '代采工单', 'myeasybuy-core' ),
					'desc'  => __( '自动采购队列 / RPA 任务状态', 'myeasybuy-core' ),
					'count' => $jobs_count,
					'url'   => self::page_url( self::PAGE_PROCUREMENT_JOBS ),
				),
			)
		);
		self::render_admin_footer();
	}

	/**
	 * Warehouse portal home.
	 */
	public static function render_warehouse_portal_home(): void {
		if ( ! self::current_user_can_warehouse() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'myeasybuy-core' ) );
		}

		$cn_shipped = count( self::get_orders_by_status( MyEasyBuy_Order_Statuses::STATUS_CN_SHIPPED ) );
		$pack_count = count(
			array_merge(
				self::get_orders_by_status( MyEasyBuy_Order_Statuses::STATUS_CONSOLIDATING ),
				self::get_orders_by_status( MyEasyBuy_Order_Statuses::STATUS_IN_WAREHOUSE )
			)
		);
		$int_count = count( self::get_orders_by_status( MyEasyBuy_Order_Statuses::STATUS_PAID_SHIPPING ) );

		self::render_admin_header(
			__( '集运仓储工作台', 'myeasybuy-core' ),
			__( 'WMS 轻量版：扫码入库 → 验货称重 → 合箱打包 → 国际发货。', 'myeasybuy-core' )
		);
		self::render_admin_notices();
		self::render_portal_cards(
			array(
				array(
					'title' => __( '扫码入库', 'myeasybuy-core' ),
					'desc'  => __( '扫描国内快递单号定位订单', 'myeasybuy-core' ),
					'count' => $cn_shipped,
					'url'   => self::page_url( self::PAGE_INBOUND ),
				),
				array(
					'title' => __( '拍照/称重上传', 'myeasybuy-core' ),
					'desc'  => __( '待验货包裹的称重、尺寸与照片', 'myeasybuy-core' ),
					'count' => $cn_shipped,
					'url'   => self::page_url( self::PAGE_INSPECTION ),
				),
				array(
					'title' => __( '合箱打包工单', 'myeasybuy-core' ),
					'desc'  => __( '合箱称重并生成国际运费二价', 'myeasybuy-core' ),
					'count' => $pack_count,
					'url'   => self::page_url( self::PAGE_CONSOLIDATION ),
				),
				array(
					'title' => __( '国际发货', 'myeasybuy-core' ),
					'desc'  => __( '合箱下一程：已付二价订单录入国际单号', 'myeasybuy-core' ),
					'count' => $int_count,
					'url'   => self::page_url( self::PAGE_INT_SHIPPING ),
				),
			)
		);

		if ( class_exists( 'MyEasyBuy_OMS_Admin' ) ) {
			self::render_portal_cards(
				array(
					array(
						'title' => __( '4PX 集运 OMS', 'myeasybuy-core' ),
						'desc'  => __( '一段预报、二段合包、仓内回传', 'myeasybuy-core' ),
						'count' => class_exists( 'MyEasyBuy_OMS_Repository' )
							? array_sum( MyEasyBuy_OMS_Repository::status_counts() )
							: 0,
						'url'   => admin_url( 'admin.php?page=' . MyEasyBuy_OMS_Admin::PAGE_CONSOLE ),
					),
				)
			);
		}
		self::render_admin_footer();
	}

	/**
	 * Render portal shortcut cards.
	 *
	 * @param array<int, array<string, mixed>> $cards Cards.
	 */
	private static function render_portal_cards( array $cards ): void {
		echo '<div class="myeasybuy-portal-cards">';
		foreach ( $cards as $card ) {
			printf(
				'<a class="myeasybuy-portal-card" href="%1$s"><span class="myeasybuy-portal-card__count">%2$d</span><span class="myeasybuy-portal-card__title">%3$s</span><span class="myeasybuy-portal-card__desc">%4$s</span></a>',
				esc_url( (string) $card['url'] ),
				(int) $card['count'],
				esc_html( (string) $card['title'] ),
				esc_html( (string) $card['desc'] )
			);
		}
		echo '</div>';
	}

	/**
	 * Render manual entry queue page.
	 */
	public static function render_manual_entry_page(): void {
		if ( ! self::current_user_can_buyer() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'myeasybuy-core' ) );
		}

		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		self::render_admin_header(
			__( '待补录商品', 'myeasybuy-core' ),
			__( '国内买手：补录京东反爬拦截的商品信息，完成后订单将进入「待用户支付一价」。', 'myeasybuy-core' )
		);

		self::render_admin_notices();

		if ( $order_id ) {
			self::render_manual_entry_form( $order_id );
			self::render_admin_footer();
			return;
		}

		$orders = self::get_orders_by_status( MyEasyBuy_Order_Statuses::STATUS_PENDING_MANUAL );

		if ( empty( $orders ) ) {
			echo '<p class="myeasybuy-empty">' . esc_html__( '暂无待补录订单。', 'myeasybuy-core' ) . '</p>';
			self::render_admin_footer();
			return;
		}

		self::render_order_queue_table(
			$orders,
			self::PAGE_MANUAL_ENTRY,
			__( '补录商品', 'myeasybuy-core' )
		);
		self::render_admin_footer();
	}

	/**
	 * Render purchasing queue page.
	 */
	public static function render_purchasing_page(): void {
		if ( ! self::current_user_can_buyer() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'myeasybuy-core' ) );
		}

		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		self::render_admin_header(
			__( '待采购列表', 'myeasybuy-core' ),
			__( '国内买手：已付一价订单，代采完成后录入国内快递信息。', 'myeasybuy-core' )
		);

		self::render_admin_notices();

		if ( $order_id ) {
			self::render_cn_shipping_form( $order_id );
			self::render_admin_footer();
			return;
		}

		$orders = self::get_purchasing_queue_orders();

		if ( empty( $orders ) ) {
			echo '<p class="myeasybuy-empty">' . esc_html__( '暂无待采购订单。', 'myeasybuy-core' ) . '</p>';
			self::render_admin_footer();
			return;
		}

		self::render_order_queue_table(
			$orders,
			self::PAGE_PURCHASING,
			__( '录入发货', 'myeasybuy-core' )
		);
		self::render_admin_footer();
	}

	/**
	 * Orders awaiting domestic buyer procurement.
	 *
	 * Includes 「平台代采中」 plus paid first-payment orders still in Woo 「processing」.
	 *
	 * @return WC_Order[]
	 */
	private static function get_purchasing_queue_orders(): array {
		$orders = self::get_orders_by_status( MyEasyBuy_Order_Statuses::STATUS_PURCHASING );
		$seen   = array();

		foreach ( $orders as $order ) {
			$seen[ $order->get_id() ] = true;
		}

		$processing = wc_get_orders(
			array(
				'status'  => array( 'processing' ),
				'limit'   => 100,
				'orderby' => 'date',
				'order'   => 'ASC',
			)
		);

		foreach ( $processing as $order ) {
			if ( ! $order instanceof WC_Order || isset( $seen[ $order->get_id() ] ) ) {
				continue;
			}
			if ( ! $order->is_paid() ) {
				continue;
			}
			if ( class_exists( 'MyEasyBuy_Consolidation' )
				&& MyEasyBuy_Consolidation::PAYMENT_TYPE_SECOND === $order->get_meta( MyEasyBuy_Consolidation::META_PAYMENT_TYPE, true ) ) {
				continue;
			}
			$orders[]                   = $order;
			$seen[ $order->get_id() ] = true;
		}

		return $orders;
	}

	/**
	 * Render auto-procurement jobs board (代采工单).
	 */
	public static function render_procurement_jobs_page(): void {
		if ( ! self::current_user_can_buyer() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'myeasybuy-core' ) );
		}

		self::render_admin_header(
			__( '代采工单', 'myeasybuy-core' ),
			__( '淘宝 / 京东代采队列：查看 pending / processing 任务，并跳转对应订单录入国内快递。', 'myeasybuy-core' )
		);
		self::render_admin_notices();

		$jobs = class_exists( 'MyEasyBuy_Procurement' )
			? MyEasyBuy_Procurement::list_jobs(
				array(
					MyEasyBuy_Procurement::STATUS_PENDING,
					MyEasyBuy_Procurement::STATUS_PROCESSING,
					MyEasyBuy_Procurement::STATUS_FAILED,
				),
				100
			)
			: array();

		$active_jobs = array();
		foreach ( $jobs as $job ) {
			$job_order_id = (int) ( $job['order_id'] ?? 0 );
			$order        = $job_order_id > 0 ? wc_get_order( $job_order_id ) : null;
			if ( $order instanceof WC_Order && class_exists( 'MyEasyBuy_Procurement' ) ) {
				MyEasyBuy_Procurement::maybe_repair_second_payment_rollback( $order );
				$order = wc_get_order( $job_order_id );
			}
			if ( $order instanceof WC_Order && MyEasyBuy_Procurement::is_order_past_buyer_procurement( $order ) ) {
				MyEasyBuy_Procurement::complete_open_jobs_for_order(
					$job_order_id,
					__( 'Auto-closed: order already past domestic procurement.', 'myeasybuy-core' )
				);
				continue;
			}
			$active_jobs[] = $job;
		}
		$jobs = $active_jobs;

		if ( empty( $jobs ) ) {
			echo '<p class="myeasybuy-empty">' . esc_html__( '暂无代采工单。支付一价后，关联淘宝/京东商品的订单将自动入队；无平台商品 ID 的订单仍会出现在「待采购列表」。', 'myeasybuy-core' ) . '</p>';
			self::render_admin_footer();
			return;
		}

		echo '<table class="widefat striped myeasybuy-queue-table"><thead><tr>';
		echo '<th>' . esc_html__( '工单ID', 'myeasybuy-core' ) . '</th>';
		echo '<th>' . esc_html__( '订单', 'myeasybuy-core' ) . '</th>';
		echo '<th>' . esc_html__( '平台', 'myeasybuy-core' ) . '</th>';
		echo '<th>' . esc_html__( '商品 ID', 'myeasybuy-core' ) . '</th>';
		echo '<th>' . esc_html__( '数量', 'myeasybuy-core' ) . '</th>';
		echo '<th>' . esc_html__( '状态', 'myeasybuy-core' ) . '</th>';
		echo '<th>' . esc_html__( '重试', 'myeasybuy-core' ) . '</th>';
		echo '<th>' . esc_html__( '操作', 'myeasybuy-core' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $jobs as $job ) {
			$job_order_id = (int) ( $job['order_id'] ?? 0 );
			$status       = (string) ( $job['status'] ?? '' );
			$platform     = (string) ( $job['platform'] ?? 'jd' );
			$url          = self::page_url( self::PAGE_PURCHASING, array( 'order_id' => $job_order_id ) );
			$jd_url       = (string) ( $job['jd_product_url'] ?? '' );
			$item_id      = (string) ( $job['jd_sku_id'] ?? '' );
			if ( '' === $jd_url && '' !== $item_id ) {
				$jd_url = 'taobao' === $platform
					? 'https://item.taobao.com/item.htm?id=' . preg_replace( '/\D/', '', $item_id )
					: 'https://item.jd.com/' . preg_replace( '/\D/', '', $item_id ) . '.html';
			}
			$platform_label = 'taobao' === $platform
				? __( '淘宝', 'myeasybuy-core' )
				: __( '京东', 'myeasybuy-core' );

			$can_ship = MyEasyBuy_Procurement::is_order_eligible_for_cn_shipping( $job_order_id );

			echo '<tr>';
			echo '<td>#' . esc_html( (string) ( $job['id'] ?? '' ) ) . '</td>';
			echo '<td><a href="' . esc_url( $url ) . '">#' . esc_html( (string) $job_order_id ) . '</a></td>';
			echo '<td>' . esc_html( $platform_label ) . '</td>';
			echo '<td>';
			if ( $jd_url ) {
				echo '<a href="' . esc_url( $jd_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $item_id ) . '</a>';
			} else {
				echo esc_html( $item_id );
			}
			echo '</td>';
			echo '<td>' . esc_html( (string) ( $job['quantity'] ?? 1 ) ) . '</td>';
			echo '<td><span class="myeasybuy-job-status status-' . esc_attr( $status ) . '">' . esc_html( $status ) . '</span></td>';
			echo '<td>' . esc_html( (string) ( $job['retry_count'] ?? 0 ) ) . '</td>';
			echo '<td>';
			if ( $can_ship ) {
				echo '<a class="button button-small" href="' . esc_url( $url ) . '">' . esc_html__( '录入发货', 'myeasybuy-core' ) . '</a>';
			} else {
				echo '<span class="myeasybuy-muted">' . esc_html__( '已过代采阶段', 'myeasybuy-core' ) . '</span>';
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		self::render_admin_footer();
	}

	/**
	 * Render warehouse inbound page (扫码入库).
	 */
	public static function render_inbound_page(): void {
		if ( ! self::current_user_can_warehouse() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'myeasybuy-core' ) );
		}

		$search   = isset( $_GET['tracking'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['tracking'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order    = null;

		if ( $order_id > 0 ) {
			$order = wc_get_order( $order_id );
		} elseif ( '' !== $search ) {
			$order = self::find_order_by_cn_tracking( $search );
		}

		self::render_admin_header(
			__( '扫码入库', 'myeasybuy-core' ),
			__( '集运仓管：扫描或搜索国内快递单号，定位后进入验货称重。', 'myeasybuy-core' )
		);

		self::render_admin_notices();
		self::render_inbound_search_form( $search );

		if ( '' !== $search && ! $order && ! $order_id ) {
			echo '<p class="myeasybuy-notice myeasybuy-notice--error">' . esc_html__( '未找到匹配的订单，请核对国内快递单号。', 'myeasybuy-core' ) . '</p>';
			self::render_admin_footer();
			return;
		}

		if ( $order_id && ! $order ) {
			echo '<p class="myeasybuy-notice myeasybuy-notice--error">' . esc_html__( '订单不存在。', 'myeasybuy-core' ) . '</p>';
			self::render_admin_footer();
			return;
		}

		if ( $order ) {
			self::render_inbound_form( $order );
		}

		self::render_admin_footer();
	}

	/**
	 * Render inspection queue (拍照/称重上传).
	 */
	public static function render_inspection_page(): void {
		if ( ! self::current_user_can_warehouse() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'myeasybuy-core' ) );
		}

		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		self::render_admin_header(
			__( '拍照/称重上传', 'myeasybuy-core' ),
			__( '国内已发货包裹：录入实测重量、尺寸与验货照片后确认入库。', 'myeasybuy-core' )
		);
		self::render_admin_notices();

		if ( $order_id > 0 ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				echo '<p class="myeasybuy-notice myeasybuy-notice--error">' . esc_html__( '订单不存在。', 'myeasybuy-core' ) . '</p>';
			} else {
				self::render_inbound_form( $order );
			}
			self::render_admin_footer();
			return;
		}

		$orders = self::get_orders_by_status( MyEasyBuy_Order_Statuses::STATUS_CN_SHIPPED );
		if ( empty( $orders ) ) {
			echo '<p class="myeasybuy-empty">' . esc_html__( '暂无待验货包裹。', 'myeasybuy-core' ) . '</p>';
			self::render_admin_footer();
			return;
		}

		self::render_order_queue_table(
			$orders,
			self::PAGE_INSPECTION,
			__( '称重拍照', 'myeasybuy-core' )
		);
		self::render_admin_footer();
	}

	/**
	 * Register order edit metabox shortcut (classic orders + HPOS).
	 */
	public static function register_order_metabox(): void {
		$screens = array( 'shop_order' );

		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}

		foreach ( array_unique( $screens ) as $screen ) {
			add_meta_box(
				'myeasybuy-workflow-shortcut',
				__( 'MyEasyBuy 工单', 'myeasybuy-core' ),
				array( __CLASS__, 'render_order_metabox' ),
				$screen,
				'side',
				'high'
			);
		}
	}

	/**
	 * Render order metabox with links to relevant workflow screens.
	 *
	 * @param WP_Post|WC_Order $post_or_order Post or order.
	 */
	public static function render_order_metabox( $post_or_order ): void {
		$order = ( $post_or_order instanceof WC_Order ) ? $post_or_order : wc_get_order( $post_or_order );
		if ( ! $order ) {
			return;
		}

		$status = MyEasyBuy_Order_Statuses::normalize_status( $order->get_status() );
		$links  = array();

		if ( MyEasyBuy_Order_Statuses::STATUS_PENDING_MANUAL === $status ) {
			$links[] = array(
				'label' => __( '打开待补录商品', 'myeasybuy-core' ),
				'url'   => self::page_url( self::PAGE_MANUAL_ENTRY, array( 'order_id' => $order->get_id() ) ),
			);
		}

		if ( MyEasyBuy_Order_Statuses::STATUS_PURCHASING === $status ) {
			$links[] = array(
				'label' => __( '打开待采购列表', 'myeasybuy-core' ),
				'url'   => self::page_url( self::PAGE_PURCHASING, array( 'order_id' => $order->get_id() ) ),
			);
		}

		if ( MyEasyBuy_Order_Statuses::STATUS_CN_SHIPPED === $status ) {
			$links[] = array(
				'label' => __( '打开拍照/称重', 'myeasybuy-core' ),
				'url'   => self::page_url( self::PAGE_INSPECTION, array( 'order_id' => $order->get_id() ) ),
			);
			$tracking = (string) $order->get_meta( self::META_CN_TRACKING, true );
			$links[]  = array(
				'label' => __( '打开扫码入库', 'myeasybuy-core' ),
				'url'   => self::page_url(
					self::PAGE_INBOUND,
					$tracking ? array( 'tracking' => $tracking ) : array( 'order_id' => $order->get_id() )
				),
			);
		}

		if ( in_array( $status, array( MyEasyBuy_Order_Statuses::STATUS_IN_WAREHOUSE, MyEasyBuy_Order_Statuses::STATUS_CONSOLIDATING ), true ) ) {
			$links[] = array(
				'label' => __( '打开合箱打包工单', 'myeasybuy-core' ),
				'url'   => self::page_url( self::PAGE_CONSOLIDATION, array( 'order_id' => $order->get_id() ) ),
			);
		}

		if ( MyEasyBuy_Order_Statuses::STATUS_PAID_SHIPPING === $status ) {
			$links[] = array(
				'label' => __( '打开国际发货', 'myeasybuy-core' ),
				'url'   => self::page_url( self::PAGE_INT_SHIPPING, array( 'order_id' => $order->get_id() ) ),
			);
		}

		if ( empty( $links ) ) {
			echo '<p>' . esc_html__( '当前订单状态无需工单操作。', 'myeasybuy-core' ) . '</p>';
			return;
		}

		echo '<ul class="myeasybuy-metabox-links">';
		foreach ( $links as $link ) {
			printf(
				'<li><a class="button button-secondary" href="%1$s">%2$s</a></li>',
				esc_url( $link['url'] ),
				esc_html( $link['label'] )
			);
		}
		echo '</ul>';
	}

	/**
	 * Handle manual entry form submission.
	 */
	public static function handle_save_manual_entry(): void {
		self::verify_post_request( 'myeasybuy_save_manual_entry', 'buyer' );

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			self::redirect_with_message( self::PAGE_MANUAL_ENTRY, 'error', __( '订单不存在。', 'myeasybuy-core' ) );
		}

		if ( MyEasyBuy_Order_Statuses::STATUS_PENDING_MANUAL !== MyEasyBuy_Order_Statuses::normalize_status( $order->get_status() ) ) {
			self::redirect_with_message(
				self::page_url( self::PAGE_MANUAL_ENTRY, array( 'order_id' => $order_id ) ),
				'error',
				__( '该订单不在待补录状态。', 'myeasybuy-core' )
			);
		}

		$items_data = isset( $_POST['items'] ) && is_array( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : array();

		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( ! isset( $items_data[ $item_id ] ) || ! is_array( $items_data[ $item_id ] ) ) {
				continue;
			}

			$row = $items_data[ $item_id ];
			self::save_line_item_meta(
				$item,
				array(
					self::META_SOURCE_URL => esc_url_raw( (string) ( $row['source_url'] ?? '' ) ),
					self::META_ITEM_TITLE => sanitize_text_field( (string) ( $row['item_title'] ?? '' ) ),
					self::META_IMAGE_URL  => esc_url_raw( (string) ( $row['image_url'] ?? '' ) ),
					self::META_PRICE_RMB  => (string) max( 0, (float) ( $row['price_rmb'] ?? 0 ) ),
					self::META_SKU_SPEC   => sanitize_text_field( (string) ( $row['sku_spec'] ?? '' ) ),
				)
			);
		}

		$order->update_status(
			'pending',
			__( '人工补录完成，等待用户支付一价。', 'myeasybuy-core' )
		);

		MyEasyBuy_Hooks::dispatch_manual_entry_completed( $order_id );

		self::redirect_with_message(
			self::PAGE_MANUAL_ENTRY,
			'success',
			sprintf(
				/* translators: %d: order ID */
				__( '订单 #%d 补录完成，已移至待支付一价。', 'myeasybuy-core' ),
				$order_id
			)
		);
	}

	/**
	 * Handle domestic shipping form submission.
	 */
	public static function handle_save_cn_shipping(): void {
		self::verify_post_request( 'myeasybuy_save_cn_shipping', 'buyer' );

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			self::redirect_with_message( self::PAGE_PURCHASING, 'error', __( '订单不存在。', 'myeasybuy-core' ) );
		}

		if ( class_exists( 'MyEasyBuy_Procurement' ) ) {
			MyEasyBuy_Procurement::maybe_repair_second_payment_rollback( $order );
			$order = wc_get_order( $order_id );
		}

		if ( ! $order || ! MyEasyBuy_Procurement::is_order_eligible_for_cn_shipping( $order ) ) {
			self::redirect_with_message(
				self::PAGE_PURCHASING,
				'error',
				sprintf(
					/* translators: %d: order ID */
					__( '订单 #%d 已过国内代采阶段（或已付二价），不能再录入国内发货回退状态。', 'myeasybuy-core' ),
					$order_id
				)
			);
		}

		$carrier  = isset( $_POST['cn_carrier'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['cn_carrier'] ) ) : '';
		$tracking = isset( $_POST['cn_tracking'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['cn_tracking'] ) ) : '';

		if ( '' === $carrier || '' === $tracking ) {
			self::redirect_with_message(
				self::page_url( self::PAGE_PURCHASING, array( 'order_id' => $order_id ) ),
				'error',
				__( '请填写国内快递公司与快递单号。', 'myeasybuy-core' )
			);
		}

		$order->update_meta_data( self::META_CN_CARRIER, $carrier );
		$order->update_meta_data( self::META_CN_TRACKING, $tracking );
		$order->save();

		MyEasyBuy_Hooks::update_order_status(
			$order_id,
			MyEasyBuy_Order_Statuses::STATUS_CN_SHIPPED,
			sprintf(
				/* translators: 1: carrier, 2: tracking number */
				__( '国内发货：%1$s / %2$s', 'myeasybuy-core' ),
				$carrier,
				$tracking
			)
		);

		if ( class_exists( 'MyEasyBuy_Procurement' ) ) {
			MyEasyBuy_Procurement::complete_open_jobs_for_order(
				$order_id,
				__( 'Closed after domestic express was recorded by buyer.', 'myeasybuy-core' )
			);
		}

		self::redirect_with_message(
			self::PAGE_PURCHASING,
			'success',
			sprintf(
				/* translators: %d: order ID */
				__( '订单 #%d 国内发货信息已保存。', 'myeasybuy-core' ),
				$order_id
			)
		);
	}

	/**
	 * Handle inbound inspection form submission.
	 */
	public static function handle_save_inbound(): void {
		self::verify_post_request( 'myeasybuy_save_inbound', 'warehouse' );

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			self::redirect_with_message( self::PAGE_INBOUND, 'error', __( '订单不存在。', 'myeasybuy-core' ) );
		}

		$weight_g = isset( $_POST['actual_weight'] ) ? absint( $_POST['actual_weight'] ) : 0;
		$length   = isset( $_POST['length_cm'] ) ? (float) $_POST['length_cm'] : 0;
		$width    = isset( $_POST['width_cm'] ) ? (float) $_POST['width_cm'] : 0;
		$height   = isset( $_POST['height_cm'] ) ? (float) $_POST['height_cm'] : 0;
		$shelf_no = isset( $_POST['shelf_no'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['shelf_no'] ) ) : '';

		$photo_ids = isset( $_POST['inspection_photo_ids'] ) ? wp_unslash( $_POST['inspection_photo_ids'] ) : array();
		if ( ! is_array( $photo_ids ) ) {
			$photo_ids = array_filter( array_map( 'absint', explode( ',', (string) $photo_ids ) ) );
		} else {
			$photo_ids = array_filter( array_map( 'absint', $photo_ids ) );
		}

		if ( $weight_g <= 0 || $length <= 0 || $width <= 0 || $height <= 0 || '' === $shelf_no ) {
			self::redirect_with_message(
				self::page_url( self::PAGE_INBOUND, array( 'tracking' => $order->get_meta( self::META_CN_TRACKING, true ) ) ),
				'error',
				__( '请完整填写毛重、尺寸与货架编号。', 'myeasybuy-core' )
			);
		}

		$dimensions = sprintf( '%sx%sx%s', $length, $width, $height );
		$photo_urls = array();

		foreach ( $photo_ids as $attachment_id ) {
			$url = wp_get_attachment_url( $attachment_id );
			if ( $url ) {
				$photo_urls[] = $url;
			}
		}

		$order->update_meta_data( self::META_WEIGHT, $weight_g );
		$order->update_meta_data( self::META_DIMENSIONS, $dimensions );
		$order->update_meta_data( self::META_PHOTOS, $photo_ids );
		$order->update_meta_data( self::META_SHELF_NO, $shelf_no );
		$order->save();

		$inbound_data = array(
			'weight_grams'      => $weight_g,
			'weight_kg'         => round( $weight_g / 1000, 3 ),
			'length_cm'         => $length,
			'width_cm'          => $width,
			'height_cm'         => $height,
			'dimensions'        => $dimensions,
			'photos'            => $photo_urls,
			'photo_ids'         => $photo_ids,
			'shelf_code'        => $shelf_no,
			'domestic_tracking' => $order->get_meta( self::META_CN_TRACKING, true ),
			'inspected_by'      => get_current_user_id(),
			'inspected_at'      => gmdate( 'c' ),
		);

		MyEasyBuy_Hooks::dispatch_item_inbound_completed( $order_id, $inbound_data );

		MyEasyBuy_Hooks::update_order_status(
			$order_id,
			MyEasyBuy_Order_Statuses::STATUS_IN_WAREHOUSE,
			__( '入库验货完成。', 'myeasybuy-core' )
		);

		self::redirect_with_message(
			self::PAGE_INBOUND,
			'success',
			sprintf(
				/* translators: %d: order ID */
				__( '订单 #%d 已确认入库。', 'myeasybuy-core' ),
				$order_id
			)
		);
	}

	/**
	 * Render consolidation / second-payment page.
	 */
	public static function render_consolidation_page(): void {
		if ( ! self::current_user_can_warehouse() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'myeasybuy-core' ) );
		}

		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		self::render_admin_header(
			__( '合箱打包工单', 'myeasybuy-core' ),
			__( '录入合箱后重量、体积与物流渠道，生成国际运费二价账单。客户付清二价后进入「国际发货」。', 'myeasybuy-core' )
		);

		self::render_admin_notices();

		if ( $order_id ) {
			self::render_consolidation_form( $order_id );
			self::render_admin_footer();
			return;
		}

		$orders = array_merge(
			self::get_orders_by_status( MyEasyBuy_Order_Statuses::STATUS_CONSOLIDATING ),
			self::get_orders_by_status( MyEasyBuy_Order_Statuses::STATUS_IN_WAREHOUSE )
		);

		$queue = array();
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			// Same-batch package already billed → sync status and keep off this queue.
			if ( MyEasyBuy_Consolidation::reconcile_group_billing_from_siblings( $order ) ) {
				continue;
			}
			if ( (int) $order->get_meta( MyEasyBuy_Consolidation::META_SECOND_PAYMENT_ORDER, true ) > 0 ) {
				continue;
			}
			$queue[] = $order;
		}

		if ( empty( $queue ) ) {
			echo '<p class="myeasybuy-empty">' . esc_html__( '暂无待合箱订单。', 'myeasybuy-core' ) . '</p>';
			self::render_admin_footer();
			return;
		}

		self::render_order_queue_table(
			$queue,
			self::PAGE_CONSOLIDATION,
			__( '生成二价', 'myeasybuy-core' )
		);
		self::render_admin_footer();
	}

	/**
	 * Render international shipping page.
	 */
	public static function render_int_shipping_page(): void {
		if ( ! self::current_user_can_warehouse() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'myeasybuy-core' ) );
		}

		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		self::render_admin_header(
			__( '国际发货', 'myeasybuy-core' ),
			__( '合箱打包的下一流程：客户已付二价后，录入国际物流商与追踪单号并出库。', 'myeasybuy-core' )
		);

		self::render_admin_notices();

		if ( $order_id ) {
			self::render_int_shipping_form( $order_id );
			self::render_admin_footer();
			return;
		}

		$orders = self::get_orders_by_status( MyEasyBuy_Order_Statuses::STATUS_PAID_SHIPPING );

		if ( empty( $orders ) ) {
			echo '<p class="myeasybuy-empty">' . esc_html__( '暂无待国际发货订单。', 'myeasybuy-core' ) . '</p>';
			self::render_admin_footer();
			return;
		}

		self::render_order_queue_table(
			$orders,
			self::PAGE_INT_SHIPPING,
			__( '录入国际发货', 'myeasybuy-core' )
		);
		self::render_admin_footer();
	}

	/**
	 * Handle second-payment generation form.
	 */
	public static function handle_generate_second_payment(): void {
		self::verify_post_request( 'myeasybuy_generate_second_payment', 'warehouse' );

		$order_id   = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$weight_kg  = isset( $_POST['weight_kg'] ) ? (float) $_POST['weight_kg'] : 0;
		$volume_cm3 = isset( $_POST['volume_cm3'] ) ? (float) $_POST['volume_cm3'] : 0;
		$channel    = isset( $_POST['channel'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['channel'] ) ) : '';

		if ( $volume_cm3 <= 0 ) {
			$length = isset( $_POST['length_cm'] ) ? (float) $_POST['length_cm'] : 0;
			$width  = isset( $_POST['width_cm'] ) ? (float) $_POST['width_cm'] : 0;
			$height = isset( $_POST['height_cm'] ) ? (float) $_POST['height_cm'] : 0;
			if ( $length > 0 && $width > 0 && $height > 0 ) {
				$volume_cm3 = $length * $width * $height;
			}
		}

		$result = MyEasyBuy_Consolidation::generate_second_payment( $order_id, $weight_kg, $volume_cm3, $channel );

		if ( is_wp_error( $result ) ) {
			self::redirect_with_message(
				self::page_url( self::PAGE_CONSOLIDATION, array( 'order_id' => $order_id ) ),
				'error',
				$result->get_error_message()
			);
		}

		$parent = wc_get_order( $order_id );
		$group_note = '';
		if ( $parent instanceof WC_Order ) {
			$sibling_ids = array();
			foreach ( MyEasyBuy_Consolidation::get_group_sibling_orders( $parent ) as $sibling ) {
				$sibling_ids[] = '#' . (int) $sibling->get_id();
			}
			if ( $sibling_ids ) {
				$group_note = ' ' . sprintf(
					/* translators: %s: sibling order numbers */
					__( '同批次订单 %s 已一并移出合箱工单。', 'myeasybuy-core' ),
					implode( ', ', $sibling_ids )
				);
			}
		}

		self::redirect_with_message(
			self::PAGE_CONSOLIDATION,
			'success',
			sprintf(
				/* translators: 1: parent order ID, 2: payment order ID */
				__( '订单 #%1$d 二价已生成，支付订单 #%2$d。', 'myeasybuy-core' ),
				$order_id,
				(int) $result
			) . $group_note
		);
	}

	/**
	 * Handle international shipping form submission.
	 */
	public static function handle_save_int_shipping(): void {
		self::verify_post_request( 'myeasybuy_save_int_shipping', 'warehouse' );

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$carrier  = isset( $_POST['int_carrier'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['int_carrier'] ) ) : '';
		$tracking = isset( $_POST['int_tracking'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['int_tracking'] ) ) : '';

		$result = MyEasyBuy_Consolidation::record_international_shipment( $order_id, $carrier, $tracking );

		if ( is_wp_error( $result ) ) {
			self::redirect_with_message(
				self::page_url( self::PAGE_INT_SHIPPING, array( 'order_id' => $order_id ) ),
				'error',
				$result->get_error_message()
			);
		}

		self::redirect_with_message(
			self::PAGE_INT_SHIPPING,
			'success',
			sprintf(
				/* translators: %d: order ID */
				__( '订单 #%d 国际发货信息已保存。', 'myeasybuy-core' ),
				$order_id
			)
		);
	}

	/**
	 * Render manual entry form for a single order.
	 *
	 * @param int $order_id Order ID.
	 */
	private static function render_manual_entry_form( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			echo '<p class="myeasybuy-notice myeasybuy-notice--error">' . esc_html__( '订单不存在。', 'myeasybuy-core' ) . '</p>';
			return;
		}

		printf(
			'<p><a href="%1$s" class="button">&larr; %2$s</a></p>',
			esc_url( self::page_url( self::PAGE_MANUAL_ENTRY ) ),
			esc_html__( '返回列表', 'myeasybuy-core' )
		);

		printf(
			'<h2>%1$s <a href="%2$s" target="_blank">#%3$d</a></h2>',
			esc_html__( '补录订单', 'myeasybuy-core' ),
			esc_url( $order->get_edit_order_url() ),
			(int) $order->get_id()
		);

		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="myeasybuy-form">
			<?php wp_nonce_field( 'myeasybuy_save_manual_entry', 'myeasybuy_nonce' ); ?>
			<input type="hidden" name="action" value="myeasybuy_save_manual_entry" />
			<input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $order_id ); ?>" />

			<table class="widefat striped myeasybuy-items-table">
				<thead>
					<tr>
						<th><?php esc_html_e( '商品', 'myeasybuy-core' ); ?></th>
						<th><?php esc_html_e( '原始链接', 'myeasybuy-core' ); ?></th>
						<th><?php esc_html_e( '商品标题', 'myeasybuy-core' ); ?></th>
						<th><?php esc_html_e( '主图 URL', 'myeasybuy-core' ); ?></th>
						<th><?php esc_html_e( 'RMB 价格', 'myeasybuy-core' ); ?></th>
						<th><?php esc_html_e( 'SKU 规格', 'myeasybuy-core' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php
				foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
					self::render_line_item_fields( $item_id, $item, 'manual' );
				}
				?>
				</tbody>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary button-hero">
					<?php esc_html_e( '保存补录并提交至待支付一价', 'myeasybuy-core' ); ?>
				</button>
			</p>
		</form>
		<?php
	}

	/**
	 * Render domestic shipping form for a single order.
	 *
	 * @param int $order_id Order ID.
	 */
	private static function render_cn_shipping_form( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			echo '<p class="myeasybuy-notice myeasybuy-notice--error">' . esc_html__( '订单不存在。', 'myeasybuy-core' ) . '</p>';
			return;
		}

		if ( class_exists( 'MyEasyBuy_Procurement' ) ) {
			MyEasyBuy_Procurement::maybe_repair_second_payment_rollback( $order );
			$order = wc_get_order( $order_id ) ?: $order;
		}

		printf(
			'<p><a href="%1$s" class="button">&larr; %2$s</a></p>',
			esc_url( self::page_url( self::PAGE_PURCHASING ) ),
			esc_html__( '返回列表', 'myeasybuy-core' )
		);

		printf(
			'<h2>%1$s <a href="%2$s" target="_blank">#%3$d</a></h2>',
			esc_html__( '国内代采发货', 'myeasybuy-core' ),
			esc_url( $order->get_edit_order_url() ),
			(int) $order->get_id()
		);

		$status_label = wc_get_order_status_name( $order->get_status() );

		if ( ! MyEasyBuy_Procurement::is_order_eligible_for_cn_shipping( $order ) ) {
			printf(
				'<div class="myeasybuy-notice myeasybuy-notice--error"><p>%1$s</p><p>%2$s <strong>%3$s</strong></p></div>',
				esc_html__( '该订单已过国内代采阶段，不能再录入国内发货，以免回退流程。', 'myeasybuy-core' ),
				esc_html__( '当前状态：', 'myeasybuy-core' ),
				esc_html( $status_label )
			);

			$carrier  = (string) $order->get_meta( self::META_CN_CARRIER, true );
			$tracking = (string) $order->get_meta( self::META_CN_TRACKING, true );
			if ( $carrier || $tracking ) {
				printf(
					'<div class="myeasybuy-card"><p><strong>%1$s</strong> %2$s<br /><strong>%3$s</strong> %4$s</p></div>',
					esc_html__( '国内快递公司', 'myeasybuy-core' ),
					esc_html( $carrier ?: '—' ),
					esc_html__( '国内快递单号', 'myeasybuy-core' ),
					esc_html( $tracking ?: '—' )
				);
			}
			return;
		}

		?>
		<div class="myeasybuy-card">
			<h3><?php esc_html_e( '订单商品与京东链接', 'myeasybuy-core' ); ?></h3>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( '商品', 'myeasybuy-core' ); ?></th>
						<th><?php esc_html_e( 'RMB', 'myeasybuy-core' ); ?></th>
						<th><?php esc_html_e( '规格', 'myeasybuy-core' ); ?></th>
						<th><?php esc_html_e( '操作', 'myeasybuy-core' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $order->get_items( 'line_item' ) as $item ) : ?>
					<?php
					$source_url = $item->get_meta( self::META_SOURCE_URL, true );
					$price_rmb  = $item->get_meta( self::META_PRICE_RMB, true );
					$sku_spec   = $item->get_meta( self::META_SKU_SPEC, true );
					?>
					<tr>
						<td><?php echo esc_html( $item->get_name() ); ?></td>
						<td><?php echo esc_html( (string) $price_rmb ); ?></td>
						<td><?php echo esc_html( (string) $sku_spec ); ?></td>
						<td>
							<?php if ( $source_url ) : ?>
								<a class="button button-primary" href="<?php echo esc_url( $source_url ); ?>" target="_blank" rel="noopener noreferrer">
									<?php esc_html_e( '打开京东链接', 'myeasybuy-core' ); ?>
								</a>
							<?php else : ?>
								<span class="myeasybuy-muted"><?php esc_html_e( '无链接', 'myeasybuy-core' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="myeasybuy-form myeasybuy-card">
			<?php wp_nonce_field( 'myeasybuy_save_cn_shipping', 'myeasybuy_nonce' ); ?>
			<input type="hidden" name="action" value="myeasybuy_save_cn_shipping" />
			<input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $order_id ); ?>" />

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="cn_carrier"><?php esc_html_e( '国内快递公司', 'myeasybuy-core' ); ?></label></th>
					<td><input type="text" class="regular-text" id="cn_carrier" name="cn_carrier" value="<?php echo esc_attr( $order->get_meta( self::META_CN_CARRIER, true ) ); ?>" required /></td>
				</tr>
				<tr>
					<th scope="row"><label for="cn_tracking"><?php esc_html_e( '国内快递单号', 'myeasybuy-core' ); ?></label></th>
					<td><input type="text" class="regular-text" id="cn_tracking" name="cn_tracking" value="<?php echo esc_attr( $order->get_meta( self::META_CN_TRACKING, true ) ); ?>" required /></td>
				</tr>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary button-hero">
					<?php esc_html_e( '保存并标记为国内已发货', 'myeasybuy-core' ); ?>
				</button>
			</p>
		</form>
		<?php
	}

	/**
	 * Render inbound search form.
	 *
	 * @param string $search Current tracking search value.
	 */
	private static function render_inbound_search_form( string $search ): void {
		?>
		<form method="get" class="myeasybuy-search-form myeasybuy-card">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_INBOUND ); ?>" />
			<label for="myeasybuy-tracking-search" class="screen-reader-text"><?php esc_html_e( '国内快递单号', 'myeasybuy-core' ); ?></label>
			<input
				type="search"
				id="myeasybuy-tracking-search"
				name="tracking"
				class="regular-text myeasybuy-tracking-input"
				value="<?php echo esc_attr( $search ); ?>"
				placeholder="<?php esc_attr_e( '扫描或输入国内快递单号…', 'myeasybuy-core' ); ?>"
				autofocus
			/>
			<button type="submit" class="button button-primary"><?php esc_html_e( '定位订单', 'myeasybuy-core' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Render inbound inspection form.
	 *
	 * @param WC_Order $order Matched order.
	 */
	private static function render_inbound_form( WC_Order $order ): void {
		$existing_photos = self::get_photo_ids_from_meta( $order->get_meta( self::META_PHOTOS, true ) );
		$dimensions      = self::parse_dimensions( $order->get_meta( self::META_DIMENSIONS, true ) );

		printf(
			'<div class="myeasybuy-card"><h3>%1$s <a href="%2$s" target="_blank">#%3$d</a></h3><p>%4$s: <strong>%5$s</strong> / %6$s</p></div>',
			esc_html__( '匹配订单', 'myeasybuy-core' ),
			esc_url( $order->get_edit_order_url() ),
			(int) $order->get_id(),
			esc_html__( '国内快递', 'myeasybuy-core' ),
			esc_html( (string) $order->get_meta( self::META_CN_CARRIER, true ) ),
			esc_html( (string) $order->get_meta( self::META_CN_TRACKING, true ) )
		);

		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="myeasybuy-form myeasybuy-card">
			<?php wp_nonce_field( 'myeasybuy_save_inbound', 'myeasybuy_nonce' ); ?>
			<input type="hidden" name="action" value="myeasybuy_save_inbound" />
			<input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $order->get_id() ); ?>" />

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="actual_weight"><?php esc_html_e( '实测毛重 (g)', 'myeasybuy-core' ); ?></label></th>
					<td><input type="number" min="1" step="1" class="small-text" id="actual_weight" name="actual_weight" value="<?php echo esc_attr( (string) $order->get_meta( self::META_WEIGHT, true ) ); ?>" required /> g</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( '体积尺寸 (cm)', 'myeasybuy-core' ); ?></th>
					<td class="myeasybuy-dimensions">
						<input type="number" min="0.1" step="0.1" name="length_cm" value="<?php echo esc_attr( (string) $dimensions['length'] ); ?>" placeholder="<?php esc_attr_e( '长', 'myeasybuy-core' ); ?>" required />
						<span>&times;</span>
						<input type="number" min="0.1" step="0.1" name="width_cm" value="<?php echo esc_attr( (string) $dimensions['width'] ); ?>" placeholder="<?php esc_attr_e( '宽', 'myeasybuy-core' ); ?>" required />
						<span>&times;</span>
						<input type="number" min="0.1" step="0.1" name="height_cm" value="<?php echo esc_attr( (string) $dimensions['height'] ); ?>" placeholder="<?php esc_attr_e( '高', 'myeasybuy-core' ); ?>" required />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="shelf_no"><?php esc_html_e( '货架编号', 'myeasybuy-core' ); ?></label></th>
					<td><input type="text" class="regular-text" id="shelf_no" name="shelf_no" value="<?php echo esc_attr( (string) $order->get_meta( self::META_SHELF_NO, true ) ); ?>" required /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( '验货图片', 'myeasybuy-core' ); ?></th>
					<td>
						<div id="myeasybuy-photo-preview" class="myeasybuy-photo-preview">
							<?php foreach ( $existing_photos as $photo_id ) : ?>
								<?php self::render_photo_thumb( $photo_id ); ?>
							<?php endforeach; ?>
						</div>
						<p>
							<button type="button" class="button" id="myeasybuy-add-photos"><?php esc_html_e( '上传 / 选择图片', 'myeasybuy-core' ); ?></button>
						</p>
						<div id="myeasybuy-photo-inputs">
							<?php foreach ( $existing_photos as $photo_id ) : ?>
								<input type="hidden" name="inspection_photo_ids[]" value="<?php echo esc_attr( (string) $photo_id ); ?>" />
							<?php endforeach; ?>
						</div>
					</td>
				</tr>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary button-hero">
					<?php esc_html_e( '确认入库', 'myeasybuy-core' ); ?>
				</button>
			</p>
		</form>
		<?php
	}

	/**
	 * Render consolidation / second-payment form.
	 *
	 * @param int $order_id Order ID.
	 */
	private static function render_consolidation_form( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			echo '<p class="myeasybuy-notice myeasybuy-notice--error">' . esc_html__( '订单不存在。', 'myeasybuy-core' ) . '</p>';
			return;
		}

		$dimensions   = self::parse_dimensions( $order->get_meta( self::META_DIMENSIONS, true ) );
		$weight_g     = (int) $order->get_meta( self::META_WEIGHT, true );
		$weight_kg    = (float) $order->get_meta( MyEasyBuy_Consolidation::META_CONSOLIDATED_WEIGHT, true );
		$volume_cm3   = (float) $order->get_meta( MyEasyBuy_Consolidation::META_CONSOLIDATED_VOLUME, true );
		$channel      = (string) $order->get_meta( MyEasyBuy_Consolidation::META_SHIPPING_CHANNEL, true );
		$requested    = (string) $order->get_meta( MyEasyBuy_Customer_Consolidation::META_REQUESTED_CHANNEL, true );
		$group_id     = (string) $order->get_meta( MyEasyBuy_Customer_Consolidation::META_GROUP_ID, true );
		$child        = MyEasyBuy_Consolidation::get_second_payment_order( $order_id );

		if ( '' === $channel && '' !== $requested ) {
			$channel = $requested;
		}

		if ( $weight_kg <= 0 && $weight_g > 0 ) {
			$weight_kg = round( $weight_g / 1000, 3 );
		}

		if ( $volume_cm3 <= 0 && $dimensions['length'] && $dimensions['width'] && $dimensions['height'] ) {
			$volume_cm3 = (float) $dimensions['length'] * (float) $dimensions['width'] * (float) $dimensions['height'];
		}

		printf(
			'<p><a href="%1$s" class="button">&larr; %2$s</a></p>',
			esc_url( self::page_url( self::PAGE_CONSOLIDATION ) ),
			esc_html__( '返回列表', 'myeasybuy-core' )
		);

		printf(
			'<h2>%1$s <a href="%2$s" target="_blank">#%3$d</a></h2>',
			esc_html__( '合箱二价', 'myeasybuy-core' ),
			esc_url( $order->get_edit_order_url() ),
			(int) $order->get_id()
		);

		if ( $group_id || $requested ) {
			$channel_labels = MyEasyBuy_Consolidation::get_channels();
			$req_label      = $channel_labels[ $requested ] ?? $requested;
			$sibling_note   = '';
			if ( $group_id ) {
				$siblings = wc_get_orders(
					array(
						'limit'      => 50,
						'meta_key'   => MyEasyBuy_Customer_Consolidation::META_GROUP_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
						'meta_value' => $group_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
						'return'     => 'ids',
					)
				);
				$sibling_ids = array_map( 'intval', (array) $siblings );
				$sibling_note = $sibling_ids
					? implode( ', ', array_map( static function ( $id ) {
						return '#' . $id;
					}, $sibling_ids ) )
					: '';
			}
			printf(
				'<div class="myeasybuy-card"><p><strong>%1$s</strong> %2$s<br /><strong>%3$s</strong> %4$s%5$s</p></div>',
				esc_html__( '客户申请渠道', 'myeasybuy-core' ),
				esc_html( $req_label ?: '—' ),
				esc_html__( '合箱批次', 'myeasybuy-core' ),
				esc_html( $group_id ?: '—' ),
				$sibling_note
					? '<br /><strong>' . esc_html__( '同批次订单', 'myeasybuy-core' ) . '</strong> ' . esc_html( $sibling_note )
					: ''
			);
		}

		if ( $child ) {
			printf(
				'<div class="myeasybuy-card"><p><strong>%1$s</strong> #%2$d — %3$s<br /><a href="%4$s" target="_blank">%5$s</a></p></div>',
				esc_html__( '二价支付订单', 'myeasybuy-core' ),
				(int) $child->get_id(),
				wp_kses_post( wc_price( (float) $child->get_total(), array( 'currency' => $child->get_currency() ) ) ),
				esc_url( $child->get_edit_order_url() ),
				esc_html__( '查看支付订单', 'myeasybuy-core' )
			);
		}

		$status = MyEasyBuy_Order_Statuses::normalize_status( $order->get_status() );
		if ( MyEasyBuy_Order_Statuses::STATUS_PAID_SHIPPING === $status ) {
			printf(
				'<div class="myeasybuy-card"><p>%1$s</p><p><a class="button button-primary" href="%2$s">%3$s</a></p></div>',
				esc_html__( '客户已付二价，可进入下一流程「国际发货」。', 'myeasybuy-core' ),
				esc_url( self::page_url( self::PAGE_INT_SHIPPING, array( 'order_id' => $order_id ) ) ),
				esc_html__( '去国际发货', 'myeasybuy-core' )
			);
		}

		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="myeasybuy-form myeasybuy-card">
			<?php wp_nonce_field( 'myeasybuy_generate_second_payment', 'myeasybuy_nonce' ); ?>
			<input type="hidden" name="action" value="myeasybuy_generate_second_payment" />
			<input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $order_id ); ?>" />

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="weight_kg"><?php esc_html_e( '合箱总重量 (kg)', 'myeasybuy-core' ); ?></label></th>
					<td><input type="number" min="0.001" step="0.001" class="small-text" id="weight_kg" name="weight_kg" value="<?php echo esc_attr( (string) $weight_kg ); ?>" required /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( '合箱尺寸 (cm)', 'myeasybuy-core' ); ?></th>
					<td class="myeasybuy-dimensions">
						<input type="number" min="0.1" step="0.1" name="length_cm" value="<?php echo esc_attr( (string) $dimensions['length'] ); ?>" placeholder="<?php esc_attr_e( '长', 'myeasybuy-core' ); ?>" />
						<span>&times;</span>
						<input type="number" min="0.1" step="0.1" name="width_cm" value="<?php echo esc_attr( (string) $dimensions['width'] ); ?>" placeholder="<?php esc_attr_e( '宽', 'myeasybuy-core' ); ?>" />
						<span>&times;</span>
						<input type="number" min="0.1" step="0.1" name="height_cm" value="<?php echo esc_attr( (string) $dimensions['height'] ); ?>" placeholder="<?php esc_attr_e( '高', 'myeasybuy-core' ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="volume_cm3"><?php esc_html_e( '总体积 (cm³)', 'myeasybuy-core' ); ?></label></th>
					<td>
						<input type="number" min="1" step="1" class="regular-text" id="volume_cm3" name="volume_cm3" value="<?php echo esc_attr( $volume_cm3 > 0 ? (string) $volume_cm3 : '' ); ?>" />
						<p class="description"><?php esc_html_e( '可留空，系统将根据长宽高自动计算；空运渠道会按体积重 (cm³/6000) 与实重取大值计费。', 'myeasybuy-core' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="channel"><?php esc_html_e( '物流渠道', 'myeasybuy-core' ); ?></label></th>
					<td>
						<select name="channel" id="channel" required>
							<option value=""><?php esc_html_e( '请选择', 'myeasybuy-core' ); ?></option>
							<?php foreach ( MyEasyBuy_Consolidation::get_channels() as $slug => $label ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $channel, $slug ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary button-hero">
					<?php esc_html_e( '计算并生成二价账单', 'myeasybuy-core' ); ?>
				</button>
			</p>
		</form>
		<?php
	}

	/**
	 * Render international shipping form.
	 *
	 * @param int $order_id Order ID.
	 */
	private static function render_int_shipping_form( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			echo '<p class="myeasybuy-notice myeasybuy-notice--error">' . esc_html__( '订单不存在。', 'myeasybuy-core' ) . '</p>';
			return;
		}

		printf(
			'<p><a href="%1$s" class="button">&larr; %2$s</a></p>',
			esc_url( self::page_url( self::PAGE_INT_SHIPPING ) ),
			esc_html__( '返回列表', 'myeasybuy-core' )
		);

		printf(
			'<h2>%1$s <a href="%2$s" target="_blank">#%3$d</a></h2>',
			esc_html__( '国际发货操作', 'myeasybuy-core' ),
			esc_url( $order->get_edit_order_url() ),
			(int) $order->get_id()
		);

		$carriers     = array( '4PX', 'Canada Post', 'YunExpress' );
		$channel      = (string) $order->get_meta( MyEasyBuy_Consolidation::META_SHIPPING_CHANNEL, true );
		$channel_lbl  = MyEasyBuy_Consolidation::get_channels()[ $channel ] ?? $channel;
		$fpx_settings = MyEasyBuy_4PX_Service::instance()->get_settings();
		$default_code = MyEasyBuy_Consolidation::CHANNEL_SEA === $channel
			? (string) $fpx_settings['4px_product_sea']
			: (string) $fpx_settings['4px_product_air'];
		$fpx_ref      = (string) $order->get_meta( MyEasyBuy_4PX_Service::META_4PX_REF_NO, true );
		$fpx_no       = (string) $order->get_meta( MyEasyBuy_4PX_Service::META_4PX_ORDER_NO, true );
		$fpx_label    = (string) $order->get_meta( MyEasyBuy_4PX_Service::META_4PX_LABEL_URL, true );
		$fpx_track    = (string) $order->get_meta( '_myeasybuy_int_tracking_number', true );
		$latest_track = $order->get_meta( MyEasyBuy_4PX_Service::META_LATEST_TRACK, true );
		$track_hist   = $order->get_meta( MyEasyBuy_4PX_Service::META_TRACK_HISTORY, true );
		$track_hist   = is_array( $track_hist ) ? MyEasyBuy_4PX_Service::instance()->normalize_tracks( $track_hist ) : array();

		if ( $channel_lbl ) {
			printf(
				'<p class="description">%1$s <strong>%2$s</strong></p>',
				esc_html__( '客户/合箱所选渠道：', 'myeasybuy-core' ),
				esc_html( $channel_lbl )
			);
		}

		?>
		<div class="myeasybuy-card">
			<h3><?php esc_html_e( '4PX 一键预报', 'myeasybuy-core' ); ?></h3>
			<?php if ( $fpx_ref || $fpx_no ) : ?>
				<p>
					<?php esc_html_e( '参考号', 'myeasybuy-core' ); ?>: <code><?php echo esc_html( $fpx_ref ?: '—' ); ?></code><br />
					<?php esc_html_e( '4PX/渠道单号', 'myeasybuy-core' ); ?>: <code><?php echo esc_html( $fpx_no ?: $fpx_track ?: '—' ); ?></code>
				</p>
				<?php if ( $fpx_label ) : ?>
					<p><a class="button" href="<?php echo esc_url( $fpx_label ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( '打开面单', 'myeasybuy-core' ); ?></a></p>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px;">
					<?php wp_nonce_field( 'myeasybuy_4px_refresh_label', 'myeasybuy_nonce' ); ?>
					<input type="hidden" name="action" value="myeasybuy_4px_refresh_label" />
					<input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $order_id ); ?>" />
					<button type="submit" class="button"><?php esc_html_e( '刷新面单', 'myeasybuy-core' ); ?></button>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
					<?php wp_nonce_field( 'myeasybuy_4px_sync_tracking', 'myeasybuy_nonce' ); ?>
					<input type="hidden" name="action" value="myeasybuy_4px_sync_tracking" />
					<input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $order_id ); ?>" />
					<button type="submit" class="button"><?php esc_html_e( '同步轨迹', 'myeasybuy-core' ); ?></button>
				</form>
				<?php if ( is_array( $latest_track ) && ! empty( $latest_track['description'] ) ) : ?>
					<p class="description">
						<?php esc_html_e( '最新轨迹：', 'myeasybuy-core' ); ?>
						<?php echo esc_html( (string) $latest_track['description'] ); ?>
						<?php if ( ! empty( $latest_track['occurred_at'] ) ) : ?>
							(<?php echo esc_html( (string) $latest_track['occurred_at'] ); ?>)
						<?php endif; ?>
					</p>
				<?php endif; ?>
				<?php if ( $track_hist ) : ?>
					<ol class="myeasybuy-admin-track-history" style="max-height:240px;overflow:auto;padding-left:1.25rem;">
						<?php foreach ( array_slice( $track_hist, 0, 20 ) as $hist_item ) : ?>
							<li>
								<?php if ( ! empty( $hist_item['occurred_at'] ) ) : ?>
									<code><?php echo esc_html( (string) $hist_item['occurred_at'] ); ?></code>
								<?php endif; ?>
								<?php if ( ! empty( $hist_item['location'] ) ) : ?>
									<strong><?php echo esc_html( (string) $hist_item['location'] ); ?></strong>
								<?php endif; ?>
								<?php echo esc_html( (string) ( $hist_item['description'] ?? '' ) ); ?>
							</li>
						<?php endforeach; ?>
					</ol>
					<p class="description"><?php esc_html_e( '客户订单页会同步显示以上 4PX 全程轨迹。', 'myeasybuy-core' ); ?></p>
				<?php endif; ?>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="myeasybuy-form">
					<?php wp_nonce_field( 'myeasybuy_4px_create_shipment', 'myeasybuy_nonce' ); ?>
					<input type="hidden" name="action" value="myeasybuy_4px_create_shipment" />
					<input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $order_id ); ?>" />
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="4px_product_code"><?php esc_html_e( '4PX 物流产品代码', 'myeasybuy-core' ); ?></label></th>
							<td>
								<input type="text" class="regular-text" id="4px_product_code" name="4px_product_code" value="<?php echo esc_attr( $default_code ); ?>" required />
								<p class="description">
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=myeasybuy-4px-settings' ) ); ?>">
										<?php esc_html_e( '配置默认空运/海运产品代码', 'myeasybuy-core' ); ?>
									</a>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( '预报后动作', 'myeasybuy-core' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="auto_mark_shipped" value="1" checked />
									<?php esc_html_e( '成功后自动标记为「国际已发货」', 'myeasybuy-core' ); ?>
								</label>
							</td>
						</tr>
					</table>
					<p class="submit">
						<button type="submit" class="button button-primary button-hero">
							<?php esc_html_e( '创建 4PX 预报单', 'myeasybuy-core' ); ?>
						</button>
					</p>
				</form>
			<?php endif; ?>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="myeasybuy-form myeasybuy-card">
			<?php wp_nonce_field( 'myeasybuy_save_int_shipping', 'myeasybuy_nonce' ); ?>
			<input type="hidden" name="action" value="myeasybuy_save_int_shipping" />
			<input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $order_id ); ?>" />

			<h3><?php esc_html_e( '手动录入发货（非 4PX 或补录）', 'myeasybuy-core' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="int_carrier"><?php esc_html_e( '国际物流商', 'myeasybuy-core' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" list="myeasybuy-int-carriers" id="int_carrier" name="int_carrier" value="<?php echo esc_attr( (string) ( $order->get_meta( '_myeasybuy_int_carrier', true ) ?: '4PX' ) ); ?>" required />
						<datalist id="myeasybuy-int-carriers">
							<?php foreach ( $carriers as $carrier_name ) : ?>
								<option value="<?php echo esc_attr( $carrier_name ); ?>"></option>
							<?php endforeach; ?>
						</datalist>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="int_tracking"><?php esc_html_e( '国际追踪单号', 'myeasybuy-core' ); ?></label></th>
					<td><input type="text" class="regular-text" id="int_tracking" name="int_tracking" value="<?php echo esc_attr( $fpx_track ); ?>" required /></td>
				</tr>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary button-hero">
					<?php esc_html_e( '确认国际发货', 'myeasybuy-core' ); ?>
				</button>
			</p>
		</form>
		<?php
	}

	/**
	 * Render editable line item fields row.
	 *
	 * @param int                 $item_id Line item ID.
	 * @param WC_Order_Item_Product $item  Line item.
	 * @param string              $mode    Render mode.
	 */
	private static function render_line_item_fields( int $item_id, $item, string $mode ): void {
		unset( $mode );

		$source_url = $item->get_meta( self::META_SOURCE_URL, true );
		$item_title = $item->get_meta( self::META_ITEM_TITLE, true );
		$image_url  = $item->get_meta( self::META_IMAGE_URL, true );
		$price_rmb  = $item->get_meta( self::META_PRICE_RMB, true );
		$sku_spec   = $item->get_meta( self::META_SKU_SPEC, true );

		if ( '' === $item_title ) {
			$item_title = $item->get_name();
		}

		?>
		<tr>
			<td>
				<strong>#<?php echo esc_html( (string) $item_id ); ?></strong><br />
				<?php echo esc_html( $item->get_name() ); ?>
			</td>
			<td><input type="url" class="large-text" name="items[<?php echo esc_attr( (string) $item_id ); ?>][source_url]" value="<?php echo esc_attr( (string) $source_url ); ?>" placeholder="https://item.jd.com/..." /></td>
			<td><input type="text" class="large-text" name="items[<?php echo esc_attr( (string) $item_id ); ?>][item_title]" value="<?php echo esc_attr( (string) $item_title ); ?>" /></td>
			<td><input type="url" class="large-text" name="items[<?php echo esc_attr( (string) $item_id ); ?>][image_url]" value="<?php echo esc_attr( (string) $image_url ); ?>" /></td>
			<td><input type="number" min="0" step="0.01" class="small-text" name="items[<?php echo esc_attr( (string) $item_id ); ?>][price_rmb]" value="<?php echo esc_attr( (string) $price_rmb ); ?>" /></td>
			<td><input type="text" class="regular-text" name="items[<?php echo esc_attr( (string) $item_id ); ?>][sku_spec]" value="<?php echo esc_attr( (string) $sku_spec ); ?>" /></td>
		</tr>
		<?php
	}

	/**
	 * Render order queue table.
	 *
	 * @param WC_Order[] $orders      Orders.
	 * @param string     $page_slug   Admin page slug.
	 * @param string     $action_label Action button label.
	 */
	private static function render_order_queue_table( array $orders, string $page_slug, string $action_label ): void {
		?>
		<table class="widefat striped myeasybuy-queue-table">
			<thead>
				<tr>
					<th><?php esc_html_e( '订单号', 'myeasybuy-core' ); ?></th>
					<th><?php esc_html_e( '客户', 'myeasybuy-core' ); ?></th>
					<th><?php esc_html_e( '日期', 'myeasybuy-core' ); ?></th>
					<th><?php esc_html_e( '商品数', 'myeasybuy-core' ); ?></th>
					<th><?php esc_html_e( '操作', 'myeasybuy-core' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $orders as $order ) : ?>
				<tr>
					<td><a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">#<?php echo esc_html( (string) $order->get_id() ); ?></a></td>
					<td><?php echo esc_html( trim( $order->get_formatted_billing_full_name() ) ?: __( 'Guest', 'myeasybuy-core' ) ); ?></td>
					<td><?php echo esc_html( $order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y-m-d H:i' ) : '' ); ?></td>
					<td><?php echo esc_html( (string) count( $order->get_items( 'line_item' ) ) ); ?></td>
					<td>
						<a class="button button-primary" href="<?php echo esc_url( self::page_url( $page_slug, array( 'order_id' => $order->get_id() ) ) ); ?>">
							<?php echo esc_html( $action_label ); ?>
						</a>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render photo thumbnail for media picker.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private static function render_photo_thumb( int $attachment_id ): void {
		$thumb = wp_get_attachment_image( $attachment_id, 'thumbnail' );
		if ( ! $thumb ) {
			return;
		}

		printf(
			'<div class="myeasybuy-photo-item" data-id="%1$d"><button type="button" class="myeasybuy-photo-remove" aria-label="%2$s">&times;</button>%3$s</div>',
			$attachment_id,
			esc_attr__( 'Remove photo', 'myeasybuy-core' ),
			$thumb // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}

	/**
	 * Render page header.
	 *
	 * @param string $title Page title.
	 * @param string $desc  Description.
	 */
	private static function render_admin_header( string $title, string $desc ): void {
		echo '<div class="wrap myeasybuy-admin-wrap">';
		printf( '<h1>%s</h1>', esc_html( $title ) );
		printf( '<p class="description">%s</p>', esc_html( $desc ) );
	}

	/**
	 * Close admin wrap container.
	 */
	private static function render_admin_footer(): void {
		echo '</div><!-- .myeasybuy-admin-wrap -->';
	}

	/**
	 * Render admin notices from query args.
	 */
	private static function render_admin_notices(): void {
		$type    = isset( $_GET['myeasybuy_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['myeasybuy_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$message = isset( $_GET['myeasybuy_message'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['myeasybuy_message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' === $message || ! in_array( $type, array( 'success', 'error' ), true ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible myeasybuy-admin-notice"><p>%2$s</p></div>',
			'success' === $type ? 'success' : 'error',
			esc_html( $message )
		);
	}

	/**
	 * Get orders by wc-prefixed status slug.
	 *
	 * @param string $status Status slug with wc- prefix.
	 * @return WC_Order[]
	 */
	private static function get_orders_by_status( string $status ): array {
		$slug = str_replace( 'wc-', '', $status );

		return wc_get_orders(
			array(
				'status'  => $slug,
				'limit'   => 100,
				'orderby' => 'date',
				'order'   => 'ASC',
			)
		);
	}

	/**
	 * Find order by domestic tracking number meta.
	 *
	 * @param string $tracking Tracking number.
	 * @return WC_Order|null
	 */
	private static function find_order_by_cn_tracking( string $tracking ): ?WC_Order {
		$tracking = trim( $tracking );
		if ( '' === $tracking ) {
			return null;
		}

		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'meta_key'   => self::META_CN_TRACKING,
				'meta_value' => $tracking,
			)
		);

		return ! empty( $orders ) ? $orders[0] : null;
	}

	/**
	 * Save line item meta map.
	 *
	 * @param WC_Order_Item_Product $item Line item.
	 * @param array<string, string> $meta Meta map.
	 */
	private static function save_line_item_meta( $item, array $meta ): void {
		foreach ( $meta as $key => $value ) {
			$item->update_meta_data( $key, $value );
		}
		$item->save();
	}

	/**
	 * Parse dimensions string LxWxH.
	 *
	 * @param mixed $raw Raw meta value.
	 * @return array{length: string, width: string, height: string}
	 */
	private static function parse_dimensions( $raw ): array {
		$defaults = array(
			'length' => '',
			'width'  => '',
			'height' => '',
		);

		if ( ! is_string( $raw ) || ! preg_match( '/^([\d.]+)x([\d.]+)x([\d.]+)$/', $raw, $matches ) ) {
			return $defaults;
		}

		return array(
			'length' => $matches[1],
			'width'  => $matches[2],
			'height' => $matches[3],
		);
	}

	/**
	 * Normalize photo meta to attachment ID list.
	 *
	 * @param mixed $raw Raw meta.
	 * @return int[]
	 */
	private static function get_photo_ids_from_meta( $raw ): array {
		if ( is_array( $raw ) ) {
			return array_values( array_filter( array_map( 'absint', $raw ) ) );
		}

		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				return array_values( array_filter( array_map( 'absint', $decoded ) ) );
			}
		}

		return array();
	}

	/**
	 * Build admin page URL.
	 *
	 * @param string               $page_slug Page slug.
	 * @param array<string, mixed> $args      Query args.
	 */
	public static function page_url( string $page_slug, array $args = array() ): string {
		$args = array_merge( array( 'page' => $page_slug ), $args );
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Verify admin POST request nonce and capability.
	 *
	 * @param string $nonce_action Nonce action name.
	 * @param string $role         Required role: buyer|warehouse.
	 */
	private static function verify_post_request( string $nonce_action, string $role = 'buyer' ): void {
		$allowed = ( 'warehouse' === $role )
			? self::current_user_can_warehouse()
			: self::current_user_can_buyer();

		if ( ! $allowed ) {
			wp_die( esc_html__( 'Permission denied.', 'myeasybuy-core' ) );
		}

		check_admin_referer( $nonce_action, 'myeasybuy_nonce' );
	}

	/**
	 * Redirect with admin notice query args.
	 *
	 * @param string $redirect_url Redirect target (page slug or full URL).
	 * @param string $type         Notice type.
	 * @param string $message      Notice message.
	 */
	private static function redirect_with_message( string $redirect_url, string $type, string $message ): void {
		self::redirect_with_message_public( $redirect_url, $type, $message );
	}

	/**
	 * Public redirect helper for sibling modules (e.g. 4PX handlers).
	 *
	 * @param string $redirect_url Redirect target (page slug or full URL).
	 * @param string $type         Notice type.
	 * @param string $message      Notice message.
	 */
	public static function redirect_with_message_public( string $redirect_url, string $type, string $message ): void {
		if ( 0 === strpos( $redirect_url, 'myeasybuy-' ) ) {
			$redirect_url = self::page_url( $redirect_url );
		}

		$url = add_query_arg(
			array(
				'myeasybuy_notice'  => $type,
				'myeasybuy_message' => $message,
			),
			$redirect_url
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Buyer workflow capability.
	 */
	private static function current_user_can_buyer(): bool {
		return MyEasyBuy_Roles::current_user_can_buyer();
	}

	/**
	 * Warehouse workflow capability.
	 */
	private static function current_user_can_warehouse(): bool {
		return MyEasyBuy_Roles::current_user_can_warehouse();
	}
}
