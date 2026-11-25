<?php
/**
 * Cloudflare Turnstile and disposable-email protection for registration.
 *
 * @package MyEasyBuy_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Protects WooCommerce registration and checkout account creation.
 * Turnstile is off when site key or secret is empty, so missing keys cannot lock forms.
 */
class MyEasyBuy_Registration_Guard {

	public const OPTION_SITE_KEY   = 'myeasybuy_turnstile_site_key';
	public const OPTION_SECRET_KEY = 'myeasybuy_turnstile_secret_key';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 25 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 30 );
		add_action( 'woocommerce_register_form', array( __CLASS__, 'render_register_widget' ), 30 );
		add_action( 'woocommerce_after_checkout_registration_form', array( __CLASS__, 'render_checkout_widget' ), 10, 1 );
		add_filter( 'woocommerce_process_registration_errors', array( __CLASS__, 'validate_registration' ), 20, 4 );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_checkout' ), 20, 2 );
	}

	/**
	 * Register options (secrets stored in options; wp-config constants take precedence).
	 */
	public static function register_settings(): void {
		register_setting(
			'myeasybuy_registration_guard',
			self::OPTION_SITE_KEY,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
		register_setting(
			'myeasybuy_registration_guard',
			self::OPTION_SECRET_KEY,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
	}

	/**
	 * Settings submenu under the existing WooCommerce menu.
	 */
	public static function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Registration security', 'myeasybuy-core' ),
			__( 'Registration security', 'myeasybuy-core' ),
			'manage_woocommerce',
			'myeasybuy-registration-security',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	/**
	 * Settings page (does not echo key material to logs).
	 */
	public static function render_settings_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$const_site   = defined( 'MYEASYBUY_TURNSTILE_SITE_KEY' );
		$const_secret = defined( 'MYEASYBUY_TURNSTILE_SECRET_KEY' );
		$enabled      = self::is_turnstile_enabled();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Registration security', 'myeasybuy-core' ); ?></h1>
			<p><?php esc_html_e( 'Cloudflare Turnstile is used only on account registration and on checkout when the customer chooses to create an account. Guest checkout is not challenged. If either key is empty, Turnstile stays off so registration cannot be locked out.', 'myeasybuy-core' ); ?></p>
			<p>
				<?php
				echo $enabled
					? esc_html__( 'Turnstile is currently enabled.', 'myeasybuy-core' )
					: esc_html__( 'Turnstile is currently disabled (missing site key or secret).', 'myeasybuy-core' );
				?>
			</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'myeasybuy_registration_guard' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="myeasybuy_turnstile_site_key"><?php esc_html_e( 'Turnstile site key', 'myeasybuy-core' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="myeasybuy_turnstile_site_key" name="<?php echo esc_attr( self::OPTION_SITE_KEY ); ?>" value="<?php echo esc_attr( (string) get_option( self::OPTION_SITE_KEY, '' ) ); ?>" autocomplete="off" <?php disabled( $const_site ); ?> />
							<?php if ( $const_site ) : ?>
								<p class="description"><?php esc_html_e( 'Overridden by the MYEASYBUY_TURNSTILE_SITE_KEY constant in wp-config.php.', 'myeasybuy-core' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="myeasybuy_turnstile_secret_key"><?php esc_html_e( 'Turnstile secret key', 'myeasybuy-core' ); ?></label></th>
						<td>
							<input type="password" class="regular-text" id="myeasybuy_turnstile_secret_key" name="<?php echo esc_attr( self::OPTION_SECRET_KEY ); ?>" value="<?php echo esc_attr( (string) get_option( self::OPTION_SECRET_KEY, '' ) ); ?>" autocomplete="new-password" <?php disabled( $const_secret ); ?> />
							<?php if ( $const_secret ) : ?>
								<p class="description"><?php esc_html_e( 'Overridden by the MYEASYBUY_TURNSTILE_SECRET_KEY constant in wp-config.php.', 'myeasybuy-core' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Public site key (safe to expose in HTML).
	 */
	public static function site_key(): string {
		if ( defined( 'MYEASYBUY_TURNSTILE_SITE_KEY' ) && is_string( MYEASYBUY_TURNSTILE_SITE_KEY ) ) {
			return trim( MYEASYBUY_TURNSTILE_SITE_KEY );
		}
		return trim( (string) get_option( self::OPTION_SITE_KEY, '' ) );
	}

	/**
	 * Secret key (never print).
	 */
	private static function secret_key(): string {
		if ( defined( 'MYEASYBUY_TURNSTILE_SECRET_KEY' ) && is_string( MYEASYBUY_TURNSTILE_SECRET_KEY ) ) {
			return trim( MYEASYBUY_TURNSTILE_SECRET_KEY );
		}
		return trim( (string) get_option( self::OPTION_SECRET_KEY, '' ) );
	}

	/**
	 * Whether Turnstile should run.
	 */
	public static function is_turnstile_enabled(): bool {
		return ( '' !== self::site_key() && '' !== self::secret_key() );
	}

	/**
	 * Enqueue widget script and checkout helper on relevant pages.
	 */
	public static function enqueue_assets(): void {
		if ( is_admin() ) {
			return;
		}

		$need_list = self::is_turnstile_enabled() && self::should_load_on_current_page();
		$need_css  = self::should_load_on_current_page() || self::is_checkout_page();

		if ( $need_css ) {
			wp_enqueue_style(
				'myeasybuy-registration-guard',
				MYEASYBUY_CORE_URL . 'assets/css/registration-guard.css',
				array(),
				(string) filemtime( MYEASYBUY_CORE_DIR . 'assets/css/registration-guard.css' )
			);
		}

		if ( ! $need_list ) {
			return;
		}

		wp_enqueue_script(
			'myeasybuy-turnstile-api',
			'https://challenges.cloudflare.com/turnstile/v0/api.js',
			array(),
			null,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);

		wp_enqueue_script(
			'myeasybuy-registration-guard',
			MYEASYBUY_CORE_URL . 'assets/js/registration-guard.js',
			array( 'myeasybuy-turnstile-api' ),
			(string) filemtime( MYEASYBUY_CORE_DIR . 'assets/js/registration-guard.js' ),
			true
		);

		wp_localize_script(
			'myeasybuy-registration-guard',
			'myeasybuyRegistrationGuard',
			array(
				'siteKey' => self::site_key(),
			)
		);
	}

	/**
	 * Widget on the WooCommerce / dedicated registration form.
	 */
	public static function render_register_widget(): void {
		self::render_widget( 'register' );
	}

	/**
	 * Widget after the classic checkout "Create an account" checkbox.
	 *
	 * @param WC_Checkout|null $checkout Checkout object.
	 */
	public static function render_checkout_widget( $checkout = null ): void {
		$required = ( 'yes' !== get_option( 'woocommerce_enable_guest_checkout' ) );
		self::render_widget( 'checkout', $required );
	}

	/**
	 * Output a Turnstile container (no-op when keys are empty).
	 *
	 * @param string $context  register|checkout.
	 * @param bool   $required Always visible (registration required at checkout).
	 */
	private static function render_widget( string $context, bool $required = false ): void {
		if ( ! self::is_turnstile_enabled() ) {
			return;
		}

		$hidden = ( 'checkout' === $context && ! $required );
		echo '<div class="myeasybuy-turnstile myeasybuy-turnstile--' . esc_attr( $context ) . '" data-meb-turnstile-context="' . esc_attr( $context ) . '" data-meb-turnstile-required="' . ( $required ? '1' : '0' ) . '"' . ( $hidden ? ' hidden' : '' ) . '>';
		echo '<div class="cf-turnstile" data-sitekey="' . esc_attr( self::site_key() ) . '" data-theme="light"></div>';
		echo '</div>';
	}

	/**
	 * Validate Turnstile and disposable emails on the registration form.
	 *
	 * @param WP_Error $errors   Errors.
	 * @param string   $username Username.
	 * @param string   $password Password.
	 * @param string   $email    Email.
	 * @return WP_Error
	 */
	public static function validate_registration( $errors, $username, $password, $email ) {
		unset( $username, $password );

		if ( ! $errors instanceof WP_Error ) {
			$errors = new WP_Error();
		}

		self::add_disposable_email_error( $errors, (string) $email );

		if ( self::is_turnstile_enabled() ) {
			self::add_turnstile_error( $errors );
		}

		return $errors;
	}

	/**
	 * Validate checkout account creation only (guest checkout is skipped).
	 *
	 * @param array    $data   Posted checkout data.
	 * @param WP_Error $errors Errors.
	 */
	public static function validate_checkout( $data, $errors ): void {
		if ( is_user_logged_in() || ! self::is_creating_account() ) {
			return;
		}

		if ( ! $errors instanceof WP_Error ) {
			return;
		}

		$email = '';
		if ( isset( $data['billing_email'] ) ) {
			$email = (string) $data['billing_email'];
		} elseif ( isset( $_POST['billing_email'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$email = (string) wp_unslash( $_POST['billing_email'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		self::add_disposable_email_error( $errors, $email );

		if ( self::is_turnstile_enabled() ) {
			self::add_turnstile_error( $errors );
		}
	}

	/**
	 * Whether this checkout request will create an account.
	 */
	private static function is_creating_account(): bool {
		if ( ! empty( $_POST['createaccount'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return true;
		}

		// Guest checkout must not be challenged, even if a checkout object was built earlier.
		if ( 'yes' === get_option( 'woocommerce_enable_guest_checkout' ) ) {
			return false;
		}

		if ( function_exists( 'WC' ) && WC()->checkout() && method_exists( WC()->checkout(), 'is_registration_required' ) ) {
			return (bool) WC()->checkout()->is_registration_required();
		}

		return true;
	}

	/**
	 * Add a disposable-email error when the domain is listed.
	 *
	 * @param WP_Error $errors Errors.
	 * @param string   $email  Email address.
	 */
	private static function add_disposable_email_error( WP_Error $errors, string $email ): void {
		if ( ! self::is_disposable_email( $email ) ) {
			return;
		}

		$errors->add(
			'myeasybuy_disposable_email',
			__( 'Please use a permanent email address. Temporary or disposable email addresses are not allowed.', 'myeasybuy-core' )
		);
	}

	/**
	 * Add a Turnstile failure error.
	 *
	 * @param WP_Error $errors Errors.
	 */
	private static function add_turnstile_error( WP_Error $errors ): void {
		$token = '';
		if ( isset( $_POST['cf-turnstile-response'] ) && is_string( $_POST['cf-turnstile-response'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$token = trim( (string) wp_unslash( $_POST['cf-turnstile-response'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		if ( '' === $token ) {
			$errors->add(
				'myeasybuy_turnstile',
				__( 'Please complete the verification check.', 'myeasybuy-core' )
			);
			return;
		}

		if ( ! self::verify_turnstile_token( $token ) ) {
			$errors->add(
				'myeasybuy_turnstile',
				__( 'Verification failed. Please try again.', 'myeasybuy-core' )
			);
		}
	}

	/**
	 * Verify a Turnstile token with Cloudflare. Never logs the secret.
	 *
	 * @param string $token Browser token.
	 */
	private static function verify_turnstile_token( string $token ): bool {
		$secret = self::secret_key();
		if ( '' === $secret || '' === $token ) {
			return false;
		}

		$remote_ip = '';
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$remote_ip = sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) );
		}

		$response = wp_remote_post(
			'https://challenges.cloudflare.com/turnstile/v0/siteverify',
			array(
				'timeout' => 10,
				'body'    => array(
					'secret'   => $secret,
					'response' => $token,
					'remoteip' => $remote_ip,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return is_array( $body ) && ! empty( $body['success'] );
	}

	/**
	 * Whether an email uses a disposable domain from the bundled list.
	 *
	 * @param string $email Email address.
	 */
	public static function is_disposable_email( string $email ): bool {
		$email = strtolower( trim( $email ) );
		$pos   = strrpos( $email, '@' );
		if ( false === $pos ) {
			return false;
		}
		$domain = substr( $email, $pos + 1 );
		if ( '' === $domain ) {
			return false;
		}

		$list = self::disposable_domains();
		if ( isset( $list[ $domain ] ) ) {
			return true;
		}

		// Match parent domains (e.g. mail.euploading.com).
		$parts = explode( '.', $domain );
		while ( count( $parts ) > 2 ) {
			array_shift( $parts );
			$parent = implode( '.', $parts );
			if ( isset( $list[ $parent ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Loaded disposable domain map.
	 *
	 * @return array<string, bool>
	 */
	private static function disposable_domains(): array {
		static $map = null;
		if ( is_array( $map ) ) {
			return $map;
		}

		$map  = array();
		$file = MYEASYBUY_CORE_DIR . 'includes/data/disposable-email-domains.txt';
		if ( ! is_readable( $file ) ) {
			return $map;
		}

		$lines = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		if ( ! is_array( $lines ) ) {
			return $map;
		}

		foreach ( $lines as $line ) {
			$domain = strtolower( trim( $line ) );
			if ( '' === $domain || '#' === $domain[0] || false === strpos( $domain, '.' ) ) {
				continue;
			}
			$map[ $domain ] = true;
		}

		return $map;
	}

	/**
	 * Whether the current request is the dedicated register form or checkout.
	 */
	private static function should_load_on_current_page(): bool {
		if ( function_exists( 'is_checkout' ) && is_checkout() && ! is_user_logged_in() ) {
			return true;
		}
		if ( function_exists( 'is_account_page' ) && is_account_page() && ! is_user_logged_in() ) {
			return true;
		}
		$register_page_id = (int) get_option( 'myeasybuy_register_page_id', 0 );
		if ( $register_page_id && is_page( $register_page_id ) ) {
			return true;
		}
		global $post;
		return ( $post instanceof WP_Post && has_shortcode( (string) $post->post_content, 'myeasybuy_register' ) );
	}

	/**
	 * Checkout page (for CSS even if keys are later added).
	 */
	private static function is_checkout_page(): bool {
		return function_exists( 'is_checkout' ) && is_checkout();
	}
}
