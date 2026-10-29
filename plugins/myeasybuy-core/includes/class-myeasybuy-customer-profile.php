<?php
/**
 * Customer My Account — profile (账户信息).
 *
 * @package MyEasyBuy_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Profile, shipping addresses, account security, and billing address.
 */
class MyEasyBuy_Customer_Profile {

	public const ENDPOINT = 'profile';

	private const META_BIRTHDAY = '_myeasybuy_birthday';

	private const META_COUNTRY = '_myeasybuy_country';

	private const META_GENDER = '_myeasybuy_gender';

	private const META_ADDRESSES = '_myeasybuy_shipping_addresses';

	private const META_TRADE_PASS = '_myeasybuy_trade_password';

	private const META_AUTO_BALANCE = '_myeasybuy_auto_balance';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'add_endpoint' ) );
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'add_query_var' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'account_menu_items' ), 140 );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'render_endpoint' ) );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_filter( 'document_title_parts', array( __CLASS__, 'document_title' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 120 );
		add_action( 'admin_post_myeasybuy_save_profile', array( __CLASS__, 'handle_profile' ) );
		add_action( 'admin_post_myeasybuy_save_address', array( __CLASS__, 'handle_address' ) );
		add_action( 'admin_post_myeasybuy_delete_address', array( __CLASS__, 'handle_delete_address' ) );
		add_action( 'admin_post_myeasybuy_save_billing', array( __CLASS__, 'handle_billing' ) );
		add_action( 'admin_post_myeasybuy_change_password', array( __CLASS__, 'handle_password' ) );
		add_action( 'admin_post_myeasybuy_set_trade_password', array( __CLASS__, 'handle_trade_password' ) );
		add_action( 'admin_post_myeasybuy_toggle_balance', array( __CLASS__, 'handle_balance' ) );

		if ( get_option( 'myeasybuy_profile_endpoint_flushed' ) !== '1.17.0' ) {
			add_action( 'init', array( __CLASS__, 'maybe_flush_rewrite' ), 99 );
		}
	}

	/**
	 * Whether the current request is the profile screen.
	 */
	public static function is_page(): bool {
		return function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( self::ENDPOINT );
	}

	/**
	 * Account profile URL.
	 */
	public static function get_endpoint_url(): string {
		return wc_get_account_endpoint_url( self::ENDPOINT );
	}

	/**
	 * Register the My Account endpoint. EP_PAGES only, so it does not steal a root URL.
	 */
	public static function add_endpoint(): void {
		add_rewrite_endpoint( self::ENDPOINT, EP_PAGES );
	}

	/**
	 * @param array<string, string> $vars Query vars.
	 * @return array<string, string>
	 */
	public static function add_query_var( array $vars ): array {
		$vars[ self::ENDPOINT ] = self::ENDPOINT;
		return $vars;
	}

	/**
	 * Flush rewrite rules once after the endpoint is added.
	 */
	public static function maybe_flush_rewrite(): void {
		self::add_endpoint();
		flush_rewrite_rules( false );
		update_option( 'myeasybuy_profile_endpoint_flushed', '1.17.0', false );
	}

	/**
	 * Place 账户信息 after 我的消息.
	 *
	 * @param array<string, string> $items Menu items.
	 * @return array<string, string>
	 */
	public static function account_menu_items( array $items ): array {
		$label  = __( '账户信息', 'myeasybuy-core' );
		$anchor = isset( $items['messages'] ) ? 'messages' : 'rehearsal';
		unset( $items[ self::ENDPOINT ] );

		$new = array();
		foreach ( $items as $key => $item_label ) {
			$new[ $key ] = $item_label;
			if ( $anchor === $key ) {
				$new[ self::ENDPOINT ] = $label;
			}
		}

		if ( ! isset( $new[ self::ENDPOINT ] ) ) {
			$new[ self::ENDPOINT ] = $label;
		}

		return $new;
	}

	/**
	 * @param array<int, string> $classes Body classes.
	 * @return array<int, string>
	 */
	public static function body_class( array $classes ): array {
		if ( self::is_page() ) {
			$classes[] = 'meb-account-profile';
		}

		return $classes;
	}

	/**
	 * @param array<string, string> $parts Title parts.
	 * @return array<string, string>
	 */
	public static function document_title( array $parts ): array {
		if ( self::is_page() ) {
			$tabs             = self::tabs();
			$parts['title']   = $tabs[ self::current_tab() ] ?? __( '账户信息', 'myeasybuy-core' );
		}

		return $parts;
	}

	/**
	 * Assets for the profile screen.
	 */
	public static function enqueue_assets(): void {
		if ( ! self::is_page() ) {
			return;
		}

		wp_enqueue_style(
			'myeasybuy-customer-profile',
			MYEASYBUY_CORE_URL . 'assets/css/customer-profile.css',
			array( 'myeasybuy-storefront-palette' ),
			MYEASYBUY_CORE_VERSION
		);

		wp_enqueue_script(
			'myeasybuy-customer-profile',
			MYEASYBUY_CORE_URL . 'assets/js/customer-profile.js',
			array(),
			MYEASYBUY_CORE_VERSION,
			true
		);
	}

	/**
	 * Render the profile screen.
	 */
	public static function render_endpoint(): void {
		if ( ! is_user_logged_in() ) {
			echo '<p>' . esc_html__( '请先登录。', 'myeasybuy-core' ) . '</p>';
			return;
		}

		$user_id  = get_current_user_id();
		$tab      = self::current_tab();
		$tabs     = self::tabs();
		$profile  = self::profile( $user_id );
		$addresses = self::addresses( $user_id );
		$billing  = self::billing( $user_id );
		$panel    = self::query_key( 'meb_panel' );
		$adding   = isset( $_GET['meb_add'] ) && '1' === (string) wp_unslash( $_GET['meb_add'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$edit_id  = isset( $_GET['meb_edit'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['meb_edit'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$editing  = self::find_address( $addresses, $edit_id );
		$countries = self::countries();

		include MYEASYBUY_CORE_DIR . 'templates/account/profile.php';
	}

	/**
	 * @return array<string, string>
	 */
	public static function tabs(): array {
		return array(
			'profile'  => __( '个人资料', 'myeasybuy-core' ),
			'address'  => __( '收件地址', 'myeasybuy-core' ),
			'security' => __( '账户安全', 'myeasybuy-core' ),
			'billing'  => __( '信用卡账单地址', 'myeasybuy-core' ),
		);
	}

	/**
	 * @return array{name: string, phone: string, birthday: string, country: string, gender: string, email: string, letter: string, public_id: string}
	 */
	public static function profile( int $user_id ): array {
		$user     = get_userdata( $user_id );
		$customer = new WC_Customer( $user_id );
		$name     = $user instanceof WP_User ? $user->display_name : '';
		$letter   = $name;
		if ( function_exists( 'mb_substr' ) ) {
			$letter = mb_substr( $name, 0, 1 );
		} else {
			$letter = substr( $name, 0, 1 );
		}
		if ( '' === $letter && $user instanceof WP_User ) {
			$letter = substr( $user->user_login, 0, 1 );
		}

		$gender = (string) get_user_meta( $user_id, self::META_GENDER, true );
		if ( ! in_array( $gender, array( 'male', 'female' ), true ) ) {
			$gender = 'male';
		}

		return array(
			'name'      => $name,
			'phone'     => $customer->get_billing_phone(),
			'birthday'  => (string) get_user_meta( $user_id, self::META_BIRTHDAY, true ),
			'country'   => (string) get_user_meta( $user_id, self::META_COUNTRY, true ),
			'gender'    => $gender,
			'email'     => $user instanceof WP_User ? $user->user_email : '',
			'letter'    => function_exists( 'mb_strtolower' ) ? mb_strtolower( $letter ) : strtolower( $letter ),
			'public_id' => self::public_id( $user_id ),
		);
	}

	/**
	 * Stable short customer code, like 5EALDnX.
	 */
	public static function public_id( int $user_id ): string {
		$chars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
		$raw   = hash( 'sha256', 'myeasybuy-user-' . $user_id );
		$id    = '';
		$span  = strlen( $chars );
		for ( $i = 0; $i < 7; $i++ ) {
			$id .= $chars[ hexdec( substr( $raw, $i * 2, 2 ) ) % $span ];
		}

		return $id;
	}

	public static function has_trade_password( int $user_id ): bool {
		return '' !== (string) get_user_meta( $user_id, self::META_TRADE_PASS, true );
	}

	/**
	 * Check a trade password that has already been set.
	 */
	public static function verify_trade_password( int $user_id, string $password ): bool {
		$hash = (string) get_user_meta( $user_id, self::META_TRADE_PASS, true );
		if ( '' === $hash ) {
			return false;
		}

		return wp_check_password( $password, $hash, $user_id );
	}

	public static function auto_balance_enabled( int $user_id ): bool {
		return 'yes' === (string) get_user_meta( $user_id, self::META_AUTO_BALANCE, true );
	}

	/**
	 * @return array<int, array<string, string>>
	 */
	public static function addresses( int $user_id ): array {
		$stored = get_user_meta( $user_id, self::META_ADDRESSES, true );
		if ( is_array( $stored ) ) {
			return $stored;
		}

		$imported = self::import_wc_shipping( $user_id );
		update_user_meta( $user_id, self::META_ADDRESSES, $imported );

		return $imported;
	}

	/**
	 * @return array{name: string, phone: string, country: string, state: string, city: string, address: string, postcode: string, empty: bool}
	 */
	public static function billing( int $user_id ): array {
		$customer = new WC_Customer( $user_id );
		$name     = trim( $customer->get_billing_first_name() . ' ' . $customer->get_billing_last_name() );
		$row      = array(
			'name'     => $name,
			'phone'    => $customer->get_billing_phone(),
			'country'  => $customer->get_billing_country(),
			'state'    => $customer->get_billing_state(),
			'city'     => $customer->get_billing_city(),
			'address'  => $customer->get_billing_address_1(),
			'postcode' => $customer->get_billing_postcode(),
			'empty'    => '' === $customer->get_billing_address_1() && '' === $customer->get_billing_city(),
		);

		return $row;
	}

	/**
	 * @return array<string, string>
	 */
	public static function countries(): array {
		if ( function_exists( 'WC' ) && WC()->countries ) {
			$countries = WC()->countries->get_countries();
			if ( is_array( $countries ) ) {
				return $countries;
			}
		}

		return array();
	}

	/**
	 * Profile URL, keeping the requested tab and panel.
	 *
	 * @param array{tab?: string, add?: string, edit?: string, panel?: string} $overrides Query overrides.
	 */
	public static function page_url( array $overrides = array() ): string {
		$tab = $overrides['tab'] ?? self::current_tab();
		if ( ! isset( self::tabs()[ $tab ] ) ) {
			$tab = 'profile';
		}

		$args = array();
		if ( 'profile' !== $tab ) {
			$args['meb_tab'] = $tab;
		}
		if ( ! empty( $overrides['add'] ) ) {
			$args['meb_add'] = '1';
		}
		if ( ! empty( $overrides['edit'] ) ) {
			$args['meb_edit'] = (string) $overrides['edit'];
		}
		if ( ! empty( $overrides['panel'] ) ) {
			$args['meb_panel'] = sanitize_key( (string) $overrides['panel'] );
		}

		$url = self::get_endpoint_url();

		return $args ? add_query_arg( $args, $url ) : $url;
	}

	/**
	 * Save personal details.
	 */
	public static function handle_profile(): void {
		self::guard( 'myeasybuy_save_profile' );
		$user_id = get_current_user_id();
		$name    = isset( $_POST['display_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['display_name'] ) ) : '';
		$phone   = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['phone'] ) ) : '';
		$birth   = isset( $_POST['birthday'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['birthday'] ) ) : '';
		$country = isset( $_POST['country'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['country'] ) ) : '';
		$gender  = isset( $_POST['gender'] ) ? sanitize_key( wp_unslash( (string) $_POST['gender'] ) ) : 'male';

		if ( '' === $name ) {
			self::fail( 'profile', __( '请填写用户名。', 'myeasybuy-core' ) );
		}
		if ( '' !== $birth ) {
			$date = DateTime::createFromFormat( '!Y-m-d', $birth );
			if ( ! $date || $date->format( 'Y-m-d' ) !== $birth ) {
				self::fail( 'profile', __( '生日格式不正确。', 'myeasybuy-core' ) );
			}
		}
		if ( '' !== $country && ! isset( self::countries()[ $country ] ) ) {
			$country = '';
		}
		if ( ! in_array( $gender, array( 'male', 'female' ), true ) ) {
			$gender = 'male';
		}

		wp_update_user(
			array(
				'ID'           => $user_id,
				'display_name' => $name,
			)
		);

		$customer = new WC_Customer( $user_id );
		$customer->set_billing_phone( $phone );
		$customer->save();

		update_user_meta( $user_id, self::META_BIRTHDAY, $birth );
		update_user_meta( $user_id, self::META_COUNTRY, $country );
		update_user_meta( $user_id, self::META_GENDER, $gender );

		self::done( 'profile', __( '个人资料已保存。', 'myeasybuy-core' ) );
	}

	/**
	 * Add or update a shipping address.
	 */
	public static function handle_address(): void {
		self::guard( 'myeasybuy_save_address' );
		$user_id = get_current_user_id();
		$row     = self::read_address_post();
		if ( '' === $row['name'] || '' === $row['phone'] || '' === $row['address'] || '' === $row['country'] ) {
			self::fail( 'address', __( '请填写收件人、电话、国家和详细地址。', 'myeasybuy-core' ) );
		}

		$stored = self::addresses( $user_id );
		$found  = false;
		foreach ( $stored as $index => $address ) {
			if ( (string) $address['id'] === $row['id'] ) {
				$row['is_default']   = (string) ( $address['is_default'] ?? '' );
				$stored[ $index ]    = $row;
				$found               = true;
				break;
			}
		}
		if ( ! $found ) {
			if ( array() === $stored ) {
				$row['is_default'] = 'yes';
			}
			$stored[] = $row;
		}
		if ( 'yes' === $row['is_default'] || 1 === count( $stored ) ) {
			foreach ( $stored as $index => $address ) {
				$stored[ $index ]['is_default'] = ( (string) $address['id'] === $row['id'] ) ? 'yes' : '';
			}
		}

		self::save_addresses( $user_id, $stored );
		self::done( 'address', __( '收件地址已保存。', 'myeasybuy-core' ) );
	}

	/**
	 * Remove a shipping address.
	 */
	public static function handle_delete_address(): void {
		self::guard( 'myeasybuy_delete_address' );
		$user_id = get_current_user_id();
		$id      = isset( $_POST['address_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['address_id'] ) ) : '';
		$stored  = array_values(
			array_filter(
				self::addresses( $user_id ),
				static function ( $address ) use ( $id ): bool {
					return (string) ( $address['id'] ?? '' ) !== $id;
				}
			)
		);
		if ( $stored && ! self::has_default( $stored ) ) {
			$stored[0]['is_default'] = 'yes';
		}
		self::save_addresses( $user_id, $stored );
		self::done( 'address', __( '收件地址已删除。', 'myeasybuy-core' ) );
	}

	/**
	 * Save the credit-card billing address onto the WooCommerce customer.
	 */
	public static function handle_billing(): void {
		self::guard( 'myeasybuy_save_billing' );
		$user_id = get_current_user_id();
		$row     = self::read_address_post();
		if ( '' === $row['name'] || '' === $row['address'] || '' === $row['country'] ) {
			self::fail( 'billing', __( '请填写姓名、国家和详细地址。', 'myeasybuy-core' ), array( 'add' => '1' ) );
		}

		$customer = new WC_Customer( $user_id );
		$customer->set_billing_first_name( $row['name'] );
		$customer->set_billing_last_name( '' );
		$customer->set_billing_phone( $row['phone'] );
		$customer->set_billing_country( $row['country'] );
		$customer->set_billing_state( $row['state'] );
		$customer->set_billing_city( $row['city'] );
		$customer->set_billing_address_1( $row['address'] );
		$customer->set_billing_postcode( $row['postcode'] );
		$customer->save();

		self::done( 'billing', __( '账单地址已保存。', 'myeasybuy-core' ) );
	}

	/**
	 * Change the login password.
	 */
	public static function handle_password(): void {
		self::guard( 'myeasybuy_change_password' );
		$user = wp_get_current_user();
		$now  = isset( $_POST['current_password'] ) ? (string) wp_unslash( $_POST['current_password'] ) : '';
		$next = isset( $_POST['new_password'] ) ? (string) wp_unslash( $_POST['new_password'] ) : '';
		$again = isset( $_POST['confirm_password'] ) ? (string) wp_unslash( $_POST['confirm_password'] ) : '';

		if ( ! wp_check_password( $now, $user->user_pass, $user->ID ) ) {
			self::fail( 'security', __( '当前密码不正确。', 'myeasybuy-core' ), array( 'panel' => 'password' ) );
		}
		if ( $next !== $again ) {
			self::fail( 'security', __( '两次输入的新密码不一致。', 'myeasybuy-core' ), array( 'panel' => 'password' ) );
		}
		if ( ! self::strong_password( $next ) ) {
			self::fail( 'security', __( '密码需由数字和字母组成，长度大于6位。', 'myeasybuy-core' ), array( 'panel' => 'password' ) );
		}

		wp_set_password( $next, $user->ID );
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true );
		self::done( 'security', __( '登录密码已更新。', 'myeasybuy-core' ) );
	}

	/**
	 * Set or replace the trade password.
	 */
	public static function handle_trade_password(): void {
		self::guard( 'myeasybuy_set_trade_password' );
		$user_id = get_current_user_id();
		$next    = isset( $_POST['trade_password'] ) ? (string) wp_unslash( $_POST['trade_password'] ) : '';
		$again   = isset( $_POST['trade_confirm'] ) ? (string) wp_unslash( $_POST['trade_confirm'] ) : '';
		$current = isset( $_POST['trade_current'] ) ? (string) wp_unslash( $_POST['trade_current'] ) : '';
		$hash    = (string) get_user_meta( $user_id, self::META_TRADE_PASS, true );

		if ( '' !== $hash && ! wp_check_password( $current, $hash, $user_id ) ) {
			self::fail( 'security', __( '当前交易密码不正确。', 'myeasybuy-core' ), array( 'panel' => 'trade' ) );
		}
		if ( $next !== $again ) {
			self::fail( 'security', __( '两次输入的交易密码不一致。', 'myeasybuy-core' ), array( 'panel' => 'trade' ) );
		}
		if ( ! self::strong_password( $next ) ) {
			self::fail( 'security', __( '交易密码需由数字和字母组成，长度大于6位。', 'myeasybuy-core' ), array( 'panel' => 'trade' ) );
		}

		update_user_meta( $user_id, self::META_TRADE_PASS, wp_hash_password( $next ) );
		self::done( 'security', __( '交易密码已保存。', 'myeasybuy-core' ) );
	}

	/**
	 * Remember the auto-balance preference. Orders are not charged from the wallet automatically.
	 */
	public static function handle_balance(): void {
		self::guard( 'myeasybuy_toggle_balance' );
		$user_id = get_current_user_id();
		$next    = self::auto_balance_enabled( $user_id ) ? 'no' : 'yes';
		update_user_meta( $user_id, self::META_AUTO_BALANCE, $next );
		self::done(
			'security',
			'yes' === $next
				? __( '已开启自动余额补款。账户余额功能上线后才会按此设置扣款。', 'myeasybuy-core' )
				: __( '已关闭自动余额补款。', 'myeasybuy-core' )
		);
	}

	/**
	 * @param array<string, string> $extra Extra query args.
	 */
	private static function fail( string $tab, string $message, array $extra = array() ): void {
		self::load_notices();
		wc_add_notice( $message, 'error' );
		$extra['tab'] = $tab;
		wp_safe_redirect( self::page_url( $extra ) );
		exit;
	}

	private static function done( string $tab, string $message ): void {
		self::load_notices();
		wc_add_notice( $message, 'success' );
		wp_safe_redirect( self::page_url( array( 'tab' => $tab ) ) );
		exit;
	}

	private static function guard( string $action ): void {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
			exit;
		}
		check_admin_referer( $action );
	}

	private static function load_notices(): void {
		if ( function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}
		if ( ! function_exists( 'wc_add_notice' ) && defined( 'WC_ABSPATH' ) ) {
			include_once WC_ABSPATH . 'includes/wc-notice-functions.php';
		}
	}

	private static function strong_password( string $password ): bool {
		return strlen( $password ) > 6 && preg_match( '/[A-Za-z]/', $password ) && preg_match( '/\d/', $password );
	}

	private static function current_tab(): string {
		$tab = self::query_key( 'meb_tab' );
		return isset( self::tabs()[ $tab ] ) ? $tab : 'profile';
	}

	private static function query_key( string $key ): string {
		if ( empty( $_GET[ $key ] ) || ! is_string( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return '';
		}

		return sanitize_key( wp_unslash( $_GET[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * @return array{id: string, name: string, phone: string, country: string, state: string, city: string, address: string, postcode: string, is_default: string}
	 */
	private static function read_address_post(): array {
		$id = isset( $_POST['address_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['address_id'] ) ) : '';
		if ( '' === $id ) {
			$id = 'AD' . wp_date( 'ymdHis' );
		}

		$country = isset( $_POST['country'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['country'] ) ) : '';
		if ( '' !== $country && ! isset( self::countries()[ $country ] ) ) {
			$country = '';
		}

		return array(
			'id'         => $id,
			'name'       => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['name'] ) ) : '',
			'phone'      => isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['phone'] ) ) : '',
			'country'    => $country,
			'state'      => isset( $_POST['state'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['state'] ) ) : '',
			'city'       => isset( $_POST['city'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['city'] ) ) : '',
			'address'    => isset( $_POST['address'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['address'] ) ) : '',
			'postcode'   => isset( $_POST['postcode'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['postcode'] ) ) : '',
			'is_default' => '',
		);
	}

	/**
	 * @param array<int, array<string, string>> $addresses Addresses.
	 * @return array<string, string>|null
	 */
	private static function find_address( array $addresses, string $id ): ?array {
		if ( '' === $id ) {
			return null;
		}
		foreach ( $addresses as $address ) {
			if ( (string) ( $address['id'] ?? '' ) === $id ) {
				return $address;
			}
		}

		return null;
	}

	/**
	 * @return array<int, array<string, string>>
	 */
	private static function import_wc_shipping( int $user_id ): array {
		$customer = new WC_Customer( $user_id );
		if ( '' === $customer->get_shipping_address_1() && '' === $customer->get_shipping_city() ) {
			return array();
		}

		$name = trim( $customer->get_shipping_first_name() . ' ' . $customer->get_shipping_last_name() );

		return array(
			array(
				'id'         => 'AD' . wp_date( 'ymdHis' ),
				'name'       => $name,
				'phone'      => method_exists( $customer, 'get_shipping_phone' ) ? $customer->get_shipping_phone() : '',
				'country'    => $customer->get_shipping_country(),
				'state'      => $customer->get_shipping_state(),
				'city'       => $customer->get_shipping_city(),
				'address'    => $customer->get_shipping_address_1(),
				'postcode'   => $customer->get_shipping_postcode(),
				'is_default' => 'yes',
			),
		);
	}

	/**
	 * @param array<int, array<string, string>> $addresses Addresses.
	 */
	private static function save_addresses( int $user_id, array $addresses ): void {
		update_user_meta( $user_id, self::META_ADDRESSES, $addresses );

		$default = null;
		foreach ( $addresses as $address ) {
			if ( 'yes' === (string) ( $address['is_default'] ?? '' ) ) {
				$default = $address;
				break;
			}
		}
		if ( null === $default && $addresses ) {
			$default = $addresses[0];
		}
		if ( null === $default ) {
			return;
		}

		$customer = new WC_Customer( $user_id );
		$customer->set_shipping_first_name( (string) $default['name'] );
		$customer->set_shipping_last_name( '' );
		if ( method_exists( $customer, 'set_shipping_phone' ) ) {
			$customer->set_shipping_phone( (string) $default['phone'] );
		}
		$customer->set_shipping_country( (string) $default['country'] );
		$customer->set_shipping_state( (string) $default['state'] );
		$customer->set_shipping_city( (string) $default['city'] );
		$customer->set_shipping_address_1( (string) $default['address'] );
		$customer->set_shipping_postcode( (string) $default['postcode'] );
		$customer->save();
	}

	/**
	 * @param array<int, array<string, string>> $addresses Addresses.
	 */
	private static function has_default( array $addresses ): bool {
		foreach ( $addresses as $address ) {
			if ( 'yes' === (string) ( $address['is_default'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}
}
