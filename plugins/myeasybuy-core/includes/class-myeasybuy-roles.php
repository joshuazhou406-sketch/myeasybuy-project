<?php
/**
 * RBAC roles and capabilities for buyer / warehouse staff portals.
 *
 * @package MyEasyBuy_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers WordPress roles for domestic buyers and warehouse operators.
 */
class MyEasyBuy_Roles {

	public const ROLE_BUYER     = 'myeasybuy_buyer';
	public const ROLE_WAREHOUSE = 'myeasybuy_warehouse';

	/** Capability: access domestic buyer portal menus. */
	public const CAP_BUYER = 'myeasybuy_manage_buyer';

	/** Capability: access warehouse / WMS portal menus. */
	public const CAP_WAREHOUSE = 'myeasybuy_manage_warehouse';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		self::maybe_install();
		add_action( 'admin_init', array( __CLASS__, 'maybe_redirect_staff_home' ) );
		add_action( 'admin_menu', array( __CLASS__, 'prune_admin_menus_for_staff' ), 999 );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 20, 3 );
		add_filter( 'woocommerce_login_redirect', array( __CLASS__, 'woocommerce_login_redirect' ), 20, 2 );
		add_filter( 'woocommerce_prevent_admin_access', array( __CLASS__, 'allow_staff_admin_access' ) );
		add_filter( 'woocommerce_disable_admin_bar', array( __CLASS__, 'allow_staff_admin_bar' ) );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_staff_from_storefront' ), 5 );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar_portal_link' ), 80 );
		add_filter( 'show_admin_bar', array( __CLASS__, 'show_admin_bar_for_staff' ), 20 );
		add_action( 'show_user_profile', array( __CLASS__, 'render_role_hint' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_role_hint' ) );
	}

	/**
	 * Ensure roles exist after plugin update without reactivation.
	 */
	public static function maybe_install(): void {
		if ( get_option( 'myeasybuy_roles_version' ) === '1.6.2' ) {
			return;
		}
		self::activate();
		update_option( 'myeasybuy_roles_version', '1.6.2' );
	}

	/**
	 * Create / refresh custom roles and grant caps to WooCommerce managers.
	 */
	public static function activate(): void {
		self::register_roles();
		self::grant_caps_to_managers();
	}

	/**
	 * Register buyer and warehouse roles.
	 */
	public static function register_roles(): void {
		$buyer_caps = array(
			'read'                       => true,
			'view_admin_dashboard'       => true,
			self::CAP_BUYER              => true,
			'upload_files'               => true,
			'edit_shop_orders'           => true,
			'read_shop_order'            => true,
			'edit_others_shop_orders'    => true,
		);

		$warehouse_caps = array(
			'read'                       => true,
			'view_admin_dashboard'       => true,
			self::CAP_WAREHOUSE          => true,
			'upload_files'               => true,
			'edit_shop_orders'           => true,
			'read_shop_order'            => true,
			'edit_others_shop_orders'    => true,
		);

		/**
		 * Filter domestic buyer role capabilities.
		 *
		 * @since 1.6.0
		 *
		 * @param array<string, bool> $buyer_caps Caps.
		 */
		$buyer_caps = apply_filters( 'myeasybuy_buyer_role_caps', $buyer_caps );

		/**
		 * Filter warehouse role capabilities.
		 *
		 * @since 1.6.0
		 *
		 * @param array<string, bool> $warehouse_caps Caps.
		 */
		$warehouse_caps = apply_filters( 'myeasybuy_warehouse_role_caps', $warehouse_caps );

		self::upsert_role(
			self::ROLE_BUYER,
			__( '国内买手', 'myeasybuy-core' ),
			$buyer_caps
		);

		self::upsert_role(
			self::ROLE_WAREHOUSE,
			__( '集运仓储', 'myeasybuy-core' ),
			$warehouse_caps
		);
	}

	/**
	 * Add or refresh a role definition.
	 *
	 * @param string              $role         Role slug.
	 * @param string              $display_name Label.
	 * @param array<string, bool> $caps         Capabilities.
	 */
	private static function upsert_role( string $role, string $display_name, array $caps ): void {
		$existing = get_role( $role );
		if ( ! $existing ) {
			add_role( $role, $display_name, $caps );
			return;
		}

		foreach ( $caps as $cap => $grant ) {
			if ( $grant ) {
				$existing->add_cap( $cap );
			} else {
				$existing->remove_cap( $cap );
			}
		}
	}

	/**
	 * Shop managers / administrators see both portals.
	 */
	public static function grant_caps_to_managers(): void {
		foreach ( array( 'administrator', 'shop_manager' ) as $role_slug ) {
			$role = get_role( $role_slug );
			if ( ! $role ) {
				continue;
			}
			$role->add_cap( self::CAP_BUYER );
			$role->add_cap( self::CAP_WAREHOUSE );
		}
	}

	/**
	 * Whether current user can use buyer portal.
	 */
	public static function current_user_can_buyer(): bool {
		return (bool) apply_filters(
			'myeasybuy_admin_can_buyer',
			current_user_can( self::CAP_BUYER ) || current_user_can( 'manage_woocommerce' )
		);
	}

	/**
	 * Whether current user can use warehouse portal.
	 */
	public static function current_user_can_warehouse(): bool {
		return (bool) apply_filters(
			'myeasybuy_admin_can_warehouse',
			current_user_can( self::CAP_WAREHOUSE ) || current_user_can( 'manage_woocommerce' )
		);
	}

	/**
	 * Whether a user has buyer and/or warehouse staff role.
	 *
	 * @param WP_User|null $user User.
	 */
	public static function user_has_staff_role( $user = null ): bool {
		if ( ! $user instanceof WP_User ) {
			if ( ! is_user_logged_in() ) {
				return false;
			}
			$user = wp_get_current_user();
		}

		$roles = (array) $user->roles;
		return in_array( self::ROLE_BUYER, $roles, true )
			|| in_array( self::ROLE_WAREHOUSE, $roles, true );
	}

	/**
	 * Staff who should only see their portal (not full WP admin).
	 */
	public static function is_portal_only_staff(): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		if ( current_user_can( 'manage_options' ) || current_user_can( 'manage_woocommerce' ) ) {
			return false;
		}

		return self::user_has_staff_role();
	}

	/**
	 * Default landing page for a user.
	 *
	 * @param WP_User|null $user User.
	 */
	public static function get_home_url( $user = null ): string {
		if ( ! $user instanceof WP_User ) {
			$user = wp_get_current_user();
		}

		$roles     = (array) $user->roles;
		$is_buyer  = in_array( self::ROLE_BUYER, $roles, true ) || user_can( $user, self::CAP_BUYER );
		$is_wh     = in_array( self::ROLE_WAREHOUSE, $roles, true ) || user_can( $user, self::CAP_WAREHOUSE );

		if ( $is_buyer && ! $is_wh ) {
			return admin_url( 'admin.php?page=' . MyEasyBuy_Admin_Workflow::MENU_BUYER );
		}

		if ( $is_wh && ! $is_buyer ) {
			return admin_url( 'admin.php?page=' . MyEasyBuy_Admin_Workflow::MENU_WAREHOUSE );
		}

		if ( $is_buyer ) {
			return admin_url( 'admin.php?page=' . MyEasyBuy_Admin_Workflow::MENU_BUYER );
		}

		if ( $is_wh ) {
			return admin_url( 'admin.php?page=' . MyEasyBuy_Admin_Workflow::MENU_WAREHOUSE );
		}

		return admin_url();
	}

	/**
	 * Redirect portal-only staff away from wp-admin index to their portal.
	 */
	public static function maybe_redirect_staff_home(): void {
		if ( ! self::is_portal_only_staff() ) {
			return;
		}

		global $pagenow;
		if ( 'index.php' !== $pagenow ) {
			return;
		}

		wp_safe_redirect( self::get_home_url() );
		exit;
	}

	/**
	 * Allow buyer/warehouse roles into wp-admin (WooCommerce blocks non-editors by default).
	 *
	 * @param bool $prevent Whether access is prevented.
	 */
	public static function allow_staff_admin_access( $prevent ) {
		if ( self::user_has_staff_role() ) {
			return false;
		}
		return $prevent;
	}

	/**
	 * Keep WooCommerce from stripping the admin bar for staff roles.
	 *
	 * @param bool $disable Whether to disable admin bar for "customers".
	 */
	public static function allow_staff_admin_bar( $disable ) {
		if ( self::user_has_staff_role() ) {
			return false;
		}
		return $disable;
	}

	/**
	 * After storefront / WooCommerce login, send staff into admin portal.
	 *
	 * @param string  $redirect Redirect URL.
	 * @param WP_User $user     User.
	 */
	public static function woocommerce_login_redirect( $redirect, $user ) {
		if ( ! $user instanceof WP_User ) {
			return $redirect;
		}

		if ( user_can( $user, 'manage_options' ) || user_can( $user, 'manage_woocommerce' ) ) {
			return $redirect;
		}

		if ( self::user_has_staff_role( $user ) ) {
			return self::get_home_url( $user );
		}

		return $redirect;
	}

	/**
	 * Staff browsing my-account should land in their workbench.
	 * Homepage redirect is intentionally limited to avoid redirect loops with WooCommerce.
	 */
	public static function redirect_staff_from_storefront(): void {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		if ( ! self::is_portal_only_staff() ) {
			return;
		}

		// Only force redirect from customer account area.
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			wp_safe_redirect( self::get_home_url() );
			exit;
		}
	}

	/**
	 * Keep admin bar visible so staff can open the workbench from the storefront.
	 *
	 * @param bool $show Whether to show.
	 */
	public static function show_admin_bar_for_staff( $show ) {
		if ( self::is_portal_only_staff() ) {
			return true;
		}
		return $show;
	}

	/**
	 * Admin bar shortcut into the workbench.
	 *
	 * @param WP_Admin_Bar $bar Admin bar.
	 */
	public static function admin_bar_portal_link( $bar ): void {
		if ( ! is_user_logged_in() || ! self::user_has_staff_role() ) {
			return;
		}

		if ( current_user_can( 'manage_options' ) || current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$bar->add_node(
			array(
				'id'    => 'myeasybuy-portal',
				'title' => __( '进入工作台', 'myeasybuy-core' ),
				'href'  => self::get_home_url(),
				'meta'  => array( 'class' => 'myeasybuy-portal-abar' ),
			)
		);
	}

	/**
	 * Login redirect into the correct portal.
	 *
	 * @param string           $redirect_to Default redirect.
	 * @param string           $requested   Requested redirect.
	 * @param WP_User|WP_Error $user        User.
	 */
	public static function login_redirect( $redirect_to, $requested, $user ) {
		unset( $requested );

		if ( ! $user instanceof WP_User ) {
			return $redirect_to;
		}

		if ( user_can( $user, 'manage_options' ) || user_can( $user, 'manage_woocommerce' ) ) {
			return $redirect_to;
		}

		if ( self::user_has_staff_role( $user ) ) {
			return self::get_home_url( $user );
		}

		return $redirect_to;
	}

	/**
	 * Strip clutter menus for portal-only staff.
	 */
	public static function prune_admin_menus_for_staff(): void {
		if ( ! self::is_portal_only_staff() ) {
			return;
		}

		$remove = array(
			'index.php',
			'edit.php',
			'upload.php',
			'edit.php?post_type=page',
			'edit-comments.php',
			'themes.php',
			'plugins.php',
			'users.php',
			'tools.php',
			'options-general.php',
			'woocommerce',
			'wc-admin',
			'separator1',
			'separator2',
			'separator-last',
		);

		/**
		 * Filter menus removed for portal-only staff.
		 *
		 * @since 1.6.0
		 *
		 * @param array<int, string> $remove Menu slugs.
		 */
		$remove = apply_filters( 'myeasybuy_portal_hidden_menus', $remove );

		foreach ( $remove as $slug ) {
			remove_menu_page( $slug );
		}

		// Hide order monitor from portal-only staff (ops overview for managers).
		remove_menu_page( MyEasyBuy_Admin_Monitor::PAGE_SLUG );
	}

	/**
	 * Show assignment hint on user profile.
	 *
	 * @param WP_User $user User.
	 */
	public static function render_role_hint( $user ): void {
		if ( ! current_user_can( 'edit_users' ) ) {
			return;
		}

		unset( $user );
		?>
		<h2><?php esc_html_e( 'MyEasyBuy 工作台角色', 'myeasybuy-core' ); ?></h2>
		<p class="description">
			<?php
			esc_html_e(
				'将用户角色设为「国内买手」或「集运仓储」后，登录会进入后台工作台（不是前台会员中心）。也可直接打开 /wp-admin/。',
				'myeasybuy-core'
			);
			?>
		</p>
		<?php
	}
}
