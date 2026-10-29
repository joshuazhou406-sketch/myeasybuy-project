<?php
/**
 * Frontend customer registration for MyEasyBuy.
 *
 * @package MyEasyBuy_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Enables WooCommerce registration and provides register/login shortcodes.
 */
class MyEasyBuy_Registration {

	public const OPTION_REGISTER_PAGE_ID = 'myeasybuy_register_page_id';

	public const OPTION_LOGIN_PAGE_ID = 'myeasybuy_login_page_id';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'maybe_create_register_page' ), 5 );
		add_shortcode( 'myeasybuy_register', array( __CLASS__, 'render_register_shortcode' ) );
		add_shortcode( 'myeasybuy_login', array( __CLASS__, 'render_login_shortcode' ) );
		add_filter( 'woocommerce_registration_redirect', array( __CLASS__, 'registration_redirect' ) );
		add_action( 'woocommerce_register_form', array( __CLASS__, 'render_extra_register_fields' ) );
		add_action( 'woocommerce_created_customer', array( __CLASS__, 'save_extra_register_fields' ) );
		add_filter( 'woocommerce_process_registration_errors', array( __CLASS__, 'validate_extra_register_fields' ), 10, 4 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'woocommerce_before_customer_login_form', array( __CLASS__, 'render_myaccount_register_prompt' ) );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_guest_account_to_register' ), 5 );
	}

	/**
	 * Send guests from My Account to the dedicated register page.
	 * The old ?meb_login=1 screen redirects to the dedicated login page.
	 */
	public static function redirect_guest_account_to_register(): void {
		if ( is_user_logged_in() || is_admin() || ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}

		if ( ! empty( $_GET['meb_login'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_safe_redirect( self::legacy_login_redirect_url() );
			exit;
		}

		if ( ! empty( $_POST['login'] ) || ! empty( $_POST['woocommerce-login-nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		if ( function_exists( 'is_wc_endpoint_url' ) && ( is_wc_endpoint_url( 'lost-password' ) || is_wc_endpoint_url( 'lost_password' ) ) ) {
			return;
		}

		$url = self::get_register_url();
		if ( class_exists( 'MyEasyBuy_Login_Required' ) ) {
			$return_url = MyEasyBuy_Login_Required::get_requested_return_url();
			if ( '' === $return_url ) {
				$return_url = MyEasyBuy_Login_Required::current_url();
			}
			if ( '' !== $return_url ) {
				$url = add_query_arg( MyEasyBuy_Login_Required::REDIRECT_ARG, rawurlencode( $return_url ), $url );
			}
		}

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Run on plugin activation.
	 */
	public static function activate(): void {
		self::enable_woocommerce_registration();
		self::ensure_register_page();
		self::ensure_login_page();
	}

	/**
	 * Enable WooCommerce account registration options.
	 */
	public static function enable_woocommerce_registration(): void {
		update_option( 'woocommerce_enable_myaccount_registration', 'yes' );
		update_option( 'woocommerce_registration_generate_username', 'yes' );
		update_option( 'woocommerce_registration_generate_password', 'no' );
		update_option( 'woocommerce_enable_signup_and_login_from_checkout', 'yes' );
	}

	/**
	 * Create register page if missing (existing installs without re-activation).
	 */
	public static function maybe_create_register_page(): void {
		if ( 'yes' !== get_option( 'woocommerce_enable_myaccount_registration', 'no' ) ) {
			self::enable_woocommerce_registration();
		}

		if ( ! get_option( self::OPTION_REGISTER_PAGE_ID ) ) {
			self::ensure_register_page();
		}

		if ( ! get_option( self::OPTION_LOGIN_PAGE_ID ) ) {
			self::ensure_login_page();
		}
	}

	/**
	 * Create or locate the public register page.
	 *
	 * @return int Page ID.
	 */
	public static function ensure_register_page(): int {
		$page_id = (int) get_option( self::OPTION_REGISTER_PAGE_ID, 0 );

		if ( $page_id && 'trash' !== get_post_status( $page_id ) ) {
			return $page_id;
		}

		$existing = get_page_by_path( 'register' );
		if ( $existing instanceof WP_Post ) {
			update_option( self::OPTION_REGISTER_PAGE_ID, $existing->ID );
			return (int) $existing->ID;
		}

		$page_id = wp_insert_post(
			array(
				'post_title'   => __( 'Register', 'myeasybuy-core' ),
				'post_name'    => 'register',
				'post_content' => '[myeasybuy_register]',
				'post_status'  => 'publish',
				'post_type'    => 'page',
			),
			true
		);

		if ( is_wp_error( $page_id ) ) {
			return 0;
		}

		update_option( self::OPTION_REGISTER_PAGE_ID, $page_id );

		return (int) $page_id;
	}

	/**
	 * Register page URL.
	 */
	public static function get_register_url(): string {
		$page_id = (int) get_option( self::OPTION_REGISTER_PAGE_ID, 0 );
		return $page_id ? (string) get_permalink( $page_id ) : wc_get_page_permalink( 'myaccount' );
	}

	/**
	 * Create or locate the public login page.
	 *
	 * @return int Page ID.
	 */
	public static function ensure_login_page(): int {
		$page_id = (int) get_option( self::OPTION_LOGIN_PAGE_ID, 0 );

		if ( $page_id && 'trash' !== get_post_status( $page_id ) ) {
			return $page_id;
		}

		$existing = get_page_by_path( 'login' );
		if ( $existing instanceof WP_Post ) {
			update_option( self::OPTION_LOGIN_PAGE_ID, $existing->ID );
			return (int) $existing->ID;
		}

		$page_id = wp_insert_post(
			array(
				'post_title'   => __( 'Log in', 'myeasybuy-core' ),
				'post_name'    => 'login',
				'post_content' => '[myeasybuy_login]',
				'post_status'  => 'publish',
				'post_type'    => 'page',
			),
			true
		);

		if ( is_wp_error( $page_id ) ) {
			return 0;
		}

		update_option( self::OPTION_LOGIN_PAGE_ID, $page_id );

		return (int) $page_id;
	}

	/**
	 * Login page URL.
	 *
	 * Replaces the retired My Account screen at ?meb_login=1.
	 */
	public static function get_login_url(): string {
		$page_id = (int) get_option( self::OPTION_LOGIN_PAGE_ID, 0 );
		if ( $page_id && 'trash' !== get_post_status( $page_id ) ) {
			return (string) get_permalink( $page_id );
		}

		$existing = get_page_by_path( 'login' );
		if ( $existing instanceof WP_Post ) {
			return (string) get_permalink( $existing );
		}

		return home_url( '/login/' );
	}

	/**
	 * Old /my-account/?meb_login=1 links, keeping the return URL.
	 */
	private static function legacy_login_redirect_url(): string {
		$url  = self::get_login_url();
		$args = array();

		foreach ( $_GET as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 'meb_login' === $key || ! is_scalar( $value ) ) {
				continue;
			}
			$args[ sanitize_key( (string) $key ) ] = wp_unslash( (string) $value );
		}

		return $args ? add_query_arg( $args, $url ) : $url;
	}

	/**
	 * Shortcode: standalone registration form.
	 *
	 * @return string
	 */
	public static function render_register_shortcode(): string {
		if ( is_user_logged_in() ) {
			return self::render_logged_in_notice();
		}

		if ( ! self::is_registration_enabled() ) {
			return '<p class="myeasybuy-auth-notice">' . esc_html__( 'Registration is currently disabled.', 'myeasybuy-core' ) . '</p>';
		}

		ob_start();
		self::render_auth_shell(
			__( 'Create your account', 'myeasybuy-core' ),
			__( 'Register to track orders, pay shipping fees, and manage your MyEasyBuy purchases.', 'myeasybuy-core' ),
			'register'
		);
		return (string) ob_get_clean();
	}

	/**
	 * Shortcode: standalone login form.
	 *
	 * @return string
	 */
	public static function render_login_shortcode(): string {
		if ( is_user_logged_in() ) {
			return self::render_logged_in_notice();
		}

		ob_start();
		self::render_auth_shell(
			__( 'Sign in', 'myeasybuy-core' ),
			__( 'Welcome back. Sign in to view your orders and account details.', 'myeasybuy-core' ),
			'login'
		);
		return (string) ob_get_clean();
	}

	/**
	 * Render auth card wrapper and form template.
	 *
	 * @param string $title       Card title.
	 * @param string $description Card description.
	 * @param string $mode        login|register.
	 */
	private static function render_auth_shell( string $title, string $description, string $mode ): void {
		$alternate_url   = self::with_return_url( 'register' === $mode ? self::get_login_url() : self::get_register_url() );
		$alternate_label = 'register' === $mode
			? __( 'Already have an account? Sign in', 'myeasybuy-core' )
			: __( 'Need an account? Register', 'myeasybuy-core' );

		echo '<div class="myeasybuy-auth myeasybuy-auth--' . esc_attr( $mode ) . '">';
		echo '<div class="myeasybuy-auth__card">';
		echo '<h2 class="myeasybuy-auth__title">' . esc_html( $title ) . '</h2>';
		echo '<p class="myeasybuy-auth__desc">' . esc_html( $description ) . '</p>';

		if ( 'register' === $mode ) {
			wc_get_template(
				'myaccount/form-register.php',
				array(),
				'',
				MYEASYBUY_CORE_DIR . 'templates/woocommerce/'
			);
		} else {
			wc_get_template(
				'myaccount/form-login-only.php',
				array(),
				'',
				MYEASYBUY_CORE_DIR . 'templates/woocommerce/'
			);
		}

		printf(
			'<p class="myeasybuy-auth__switch"><a href="%1$s">%2$s</a></p>',
			esc_url( $alternate_url ),
			esc_html( $alternate_label )
		);

		echo '</div></div>';
	}

	/**
	 * Notice for logged-in users hitting auth shortcodes.
	 */
	private static function render_logged_in_notice(): string {
		$account_url = wc_get_page_permalink( 'myaccount' );

		return sprintf(
			'<div class="myeasybuy-auth myeasybuy-auth--logged-in"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html__( 'You are already signed in.', 'myeasybuy-core' ),
			esc_url( $account_url ),
			esc_html__( 'Go to My Account', 'myeasybuy-core' )
		);
	}

	/**
	 * Prompt on WooCommerce My Account login screen.
	 */
	public static function render_myaccount_register_prompt(): void {
		if ( ! self::is_registration_enabled() || is_user_logged_in() ) {
			return;
		}

		// Avoid showing "Create an account" on the dedicated auth pages.
		$register_page_id = (int) get_option( self::OPTION_REGISTER_PAGE_ID, 0 );
		$login_page_id    = (int) get_option( self::OPTION_LOGIN_PAGE_ID, 0 );
		if ( ( $register_page_id && is_page( $register_page_id ) ) || ( $login_page_id && is_page( $login_page_id ) ) ) {
			return;
		}

		printf(
			'<div class="myeasybuy-auth-banner"><p>%1$s <a class="button" href="%2$s">%3$s</a></p></div>',
			esc_html__( 'New to MyEasyBuy?', 'myeasybuy-core' ),
			esc_url( self::with_return_url( self::get_register_url() ) ),
			esc_html__( 'Create an account', 'myeasybuy-core' )
		);
	}

	/**
	 * Keep the login-required return URL when switching between login and register.
	 *
	 * @param string $url Login or register URL.
	 */
	private static function with_return_url( string $url ): string {
		if ( ! class_exists( 'MyEasyBuy_Login_Required' ) ) {
			return $url;
		}

		$return_url = MyEasyBuy_Login_Required::get_requested_return_url();

		return '' === $return_url ? $url : add_query_arg( MyEasyBuy_Login_Required::REDIRECT_ARG, rawurlencode( $return_url ), $url );
	}

	/**
	 * Extra registration fields (first / last name).
	 * Hidden on the dedicated split register page, which uses email + password only.
	 */
	public static function render_extra_register_fields(): void {
		if ( function_exists( 'meb_is_register_page' ) && meb_is_register_page() ) {
			return;
		}
		?>
		<p class="woocommerce-form-row woocommerce-form-row--first form-row form-row-first">
			<label for="reg_first_name"><?php esc_html_e( 'First name', 'myeasybuy-core' ); ?>&nbsp;<span class="required">*</span></label>
			<input type="text" class="input-text" name="first_name" id="reg_first_name" autocomplete="given-name" value="<?php echo esc_attr( self::posted_value( 'first_name' ) ); ?>" required />
		</p>
		<p class="woocommerce-form-row woocommerce-form-row--last form-row form-row-last">
			<label for="reg_last_name"><?php esc_html_e( 'Last name', 'myeasybuy-core' ); ?>&nbsp;<span class="required">*</span></label>
			<input type="text" class="input-text" name="last_name" id="reg_last_name" autocomplete="family-name" value="<?php echo esc_attr( self::posted_value( 'last_name' ) ); ?>" required />
		</p>
		<?php
	}

	/**
	 * Validate extra registration fields.
	 *
	 * @param WP_Error $errors           Validation errors.
	 * @param string   $username         Username.
	 * @param string   $email            Email.
	 * @param string   $password         Password.
	 * @return WP_Error
	 */
	public static function validate_extra_register_fields( $errors, $username, $email, $password ) {
		unset( $username, $email );

		if ( isset( $_POST['password2'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$confirm = (string) wp_unslash( $_POST['password2'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( $confirm !== (string) $password ) {
				$errors->add( 'password_mismatch', __( 'Passwords do not match.', 'myeasybuy-core' ) );
			}
		}

		if ( isset( $_POST['meb_terms'] ) && empty( $_POST['meb_terms'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$errors->add( 'terms_required', __( 'Please accept the registration agreement.', 'myeasybuy-core' ) );
		}

		if ( isset( $_POST['register'] ) && ! isset( $_POST['meb_terms'] ) && isset( $_POST['password2'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$errors->add( 'terms_required', __( 'Please accept the registration agreement.', 'myeasybuy-core' ) );
		}

		// Checkout account creation does not include our custom fields.
		if ( ! isset( $_POST['first_name'] ) && ! isset( $_POST['last_name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return $errors;
		}

		if ( empty( $_POST['first_name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$errors->add( 'first_name_required', __( 'Please enter your first name.', 'myeasybuy-core' ) );
		}

		if ( empty( $_POST['last_name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$errors->add( 'last_name_required', __( 'Please enter your last name.', 'myeasybuy-core' ) );
		}

		return $errors;
	}

	/**
	 * Save extra registration fields to user meta.
	 *
	 * @param int $customer_id Customer user ID.
	 */
	public static function save_extra_register_fields( int $customer_id ): void {
		$first_name = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['first_name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$last_name  = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['last_name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( '' !== $first_name || '' !== $last_name ) {
			update_user_meta( $customer_id, 'first_name', $first_name );
			update_user_meta( $customer_id, 'last_name', $last_name );
			update_user_meta( $customer_id, 'billing_first_name', $first_name );
			update_user_meta( $customer_id, 'billing_last_name', $last_name );
		}

		if ( ! empty( $_POST['invitation_code'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			update_user_meta(
				$customer_id,
				'_myeasybuy_invitation_code',
				sanitize_text_field( wp_unslash( (string) $_POST['invitation_code'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			);
		}
	}

	/**
	 * Redirect to My Account after successful registration.
	 *
	 * @param string $redirect Default redirect URL.
	 */
	public static function registration_redirect( string $redirect ): string {
		// Only honour an explicit return URL; WC's referer fallback is the register page itself.
		if ( ! empty( $_POST['redirect'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return $redirect;
		}

		return wc_get_page_permalink( 'myaccount' );
	}

	/**
	 * Enqueue registration styles.
	 */
	public static function enqueue_assets(): void {
		if ( ! self::should_enqueue_assets() ) {
			return;
		}

		wp_enqueue_style(
			'myeasybuy-registration',
			MYEASYBUY_CORE_URL . 'assets/css/registration.css',
			array(),
			MYEASYBUY_CORE_VERSION
		);
	}

	/**
	 * Whether registration styles should load on current request.
	 */
	private static function should_enqueue_assets(): bool {
		if ( is_account_page() && ! is_user_logged_in() ) {
			return true;
		}

		$register_page_id = (int) get_option( self::OPTION_REGISTER_PAGE_ID, 0 );
		if ( $register_page_id && is_page( $register_page_id ) ) {
			return true;
		}

		$login_page_id = (int) get_option( self::OPTION_LOGIN_PAGE_ID, 0 );
		if ( $login_page_id && is_page( $login_page_id ) ) {
			return true;
		}

		global $post;
		if ( $post instanceof WP_Post && has_shortcode( $post->post_content, 'myeasybuy_register' ) ) {
			return true;
		}

		if ( $post instanceof WP_Post && has_shortcode( $post->post_content, 'myeasybuy_login' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Whether WooCommerce registration is enabled.
	 */
	private static function is_registration_enabled(): bool {
		return 'yes' === get_option( 'woocommerce_enable_myaccount_registration', 'no' );
	}

	/**
	 * Get sanitized POST value for form repopulation.
	 *
	 * @param string $key Field key.
	 */
	private static function posted_value( string $key ): string {
		if ( empty( $_POST[ $key ] ) || ! is_string( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return '';
		}

		return sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
	}

	/**
	 * Public wrapper for form repopulation on the dedicated register template.
	 *
	 * @param string $key Field key.
	 */
	public static function posted_value_public( string $key ): string {
		return self::posted_value( $key );
	}
}
