<?php
/**
 * Policy consent, parcel-protection choice, and first-order disclaimer.
 *
 * @package MyEasyBuy_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registration, checkout, shipping, and disclaimer consent.
 */
class MyEasyBuy_Policy_Consent {

	public const REG_VERSION         = '1.0';
	public const PURCHASE_VERSION    = '1.0';
	public const SHIPPING_VERSION    = '1.0';
	public const DISCLAIMER_VERSION  = '1.0';
	public const PROTECTION_VERSION  = '1.0';

	public const USER_META_CONSENT         = '_myeasybuy_policy_consent';
	public const USER_META_CONSENT_PENDING = '_myeasybuy_consent_pending';
	public const USER_META_DISCLAIMER      = '_myeasybuy_disclaimer_consent';

	public const ORDER_META_PURCHASE   = '_myeasybuy_purchase_consent';
	public const ORDER_META_SHIPPING   = '_myeasybuy_shipping_consent';
	public const ORDER_META_PROTECTION = '_myeasybuy_protection';

	public const COOKIE_REG_CONSENT = 'meb_reg_consent';
	public const NONCE_REG          = 'meb_policy_reg_consent';
	public const NONCE_DISCLAIMER   = 'meb_disclaimer_consent';
	public const NONCE_INTERSTITIAL = 'meb_save_registration_consent';

	public const FIELD_PURCHASE   = 'myeasybuy/purchase-consent';
	public const FIELD_SHIPPING   = 'myeasybuy/shipping-consent';
	public const FIELD_PROTECTION = 'myeasybuy/parcel-protection';

	/**
	 * Disclaimer copy. Edit this (or filter `myeasybuy_disclaimer_steps`) when the wording changes,
	 * and bump DISCLAIMER_VERSION so customers see it again.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function disclaimer_steps(): array {
		$steps = array(
			array(
				'key'      => 'service',
				'label_en' => 'Service scope',
				'label_zh' => '服务范围',
				'title_en' => 'We purchase items for you from third-party Chinese platforms, acting as your purchasing agent.',
				'title_zh' => '我们作为您的代购代理人，从中国第三方平台为您购买商品。',
				'body_en'  => 'MyEasyBuy provides a purchasing-agent service. Product quality, intellectual property, and seller-related issues are the responsibility of the original seller and the third-party platform. To protect your interests, we inspect goods at the warehouse, keep you informed, and help with after-sales communication. If you ask us to buy from a platform we cannot verify, an extra service fee may apply, the item may not be eligible for return or exchange, and purchase or shipping may fail. Please refer to our Terms of Service, Return & Refund Policy, and staff guidance for details.',
				'body_zh'  => 'MyEasyBuy 提供代购服务。商品质量、知识产权以及与卖家有关的问题，由原卖家和第三方平台负责。为保障您的权益，我们会在仓库验货、及时与您沟通，并协助售后联系。如果您要求从我们无法核验的平台购买，可能需要额外服务费，商品可能无法退换，也存在买不到或运输风险。详情请见《服务条款》《退货退款政策》以及客服说明。',
			),
			array(
				'key'      => 'process',
				'label_en' => 'Shopping process',
				'label_zh' => '购物流程',
				'title_en' => 'Two payments: first for the goods and China domestic shipping, then for international shipping.',
				'title_zh' => '分两步付款：先支付货款和中国国内运费，再支付国际运费。',
				'body_en'  => '',
				'body_zh'  => '',
				'substeps' => array(
					array(
						'label_en' => 'Step 1',
						'label_zh' => '第一步',
						'en'       => 'After you pay for the goods and China domestic shipping, we purchase the item and have it sent to our warehouse. Items can be stored free of charge for 90 days. You may consolidate multiple items into one parcel to save on shipping.',
						'zh'       => '您支付货款和中国国内运费后，我们代为采购并寄至仓库。代购商品可免费仓储 90 天。您可以将多件商品合箱，以节省运费。',
					),
					array(
						'label_en' => 'Step 2',
						'label_zh' => '第二步',
						'en'       => 'When you are ready, submit a consolidation request, pay the international shipping fee, and we will ship the parcel to you. Please estimate international shipping before placing your first order.',
						'zh'       => '准备发货时，请提交合箱申请并支付国际运费，我们再将包裹寄出。下单前请先估算国际运费。',
					),
				),
			),
			array(
				'key'      => 'transport',
				'label_en' => 'Transportation risk',
				'label_zh' => '运输风险',
				'title_en' => 'International delivery is handled by third-party carriers. Customs, delays, and in-transit loss or damage are subject to the rules below.',
				'title_zh' => '国际运输由第三方物流公司承运。海关、延误以及运输途中的丢失或损坏，按以下规则处理。',
				'body_en'  => 'Customs seizures, import restrictions, and duties or taxes charged by the destination customs authority, or that arise because an item is prohibited, are the customer\'s responsibility. Please review our Shipping & Prohibited Items Policy before ordering.' . "\n\n" . 'Loss, damage, or delay in transit is handled under our Compensation & Parcel Protection Policy. If you do not purchase optional parcel protection, compensation for uninsured loss is limited to the lower of three times the international shipping cost or CAD 100. You will be asked to choose whether to buy parcel protection when you pay for international shipping.',
				'body_zh'  => '海关查扣、目的地海关的进口限制以及关税税费，或因禁运商品引起的问题，由客户自行承担。下单前请阅读《运输与禁运政策》。' . "\n\n" . '运输途中的丢失、损坏或延误，按照《赔偿与包裹保障》处理。如未购买可选的包裹保障，未投保损失的赔偿上限为国际运费的 3 倍与 CAD 100 中的较低者。支付国际运费时，您需要明确选择购买或放弃包裹保障。',
			),
		);

		/**
		 * Filter the Three Things You Should Know steps.
		 * If you change customer-facing meaning, bump DISCLAIMER_VERSION.
		 *
		 * @param array $steps Disclaimer steps.
		 */
		return apply_filters( 'myeasybuy_disclaimer_steps', $steps );
	}

	/**
	 * Hook everything.
	 */
	public static function init(): void {
		add_action( 'woocommerce_created_customer', array( __CLASS__, 'on_email_registration' ), 20, 1 );
		add_action( 'nsl_register_new_user', array( __CLASS__, 'on_google_registration' ), 20, 1 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_show_interstitial' ), 8 );
		add_action( 'admin_post_meb_save_registration_consent', array( __CLASS__, 'handle_interstitial' ) );

		// WC 11+ requires additional fields to be registered on woocommerce_init or later.
		add_action( 'woocommerce_init', array( __CLASS__, 'register_checkout_fields' ), 20 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'validate_and_save_blocks_checkout' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'save_blocks_checkout_meta' ) );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'validate_and_save_classic_checkout' ), 20, 2 );

		add_action( 'woocommerce_pay_order_before_submit', array( __CLASS__, 'render_order_pay_consent' ) );
		add_action( 'woocommerce_before_pay_action', array( __CLASS__, 'validate_order_pay_consent' ) );
		add_filter( 'woocommerce_checkout_show_terms', array( __CLASS__, 'maybe_hide_default_terms' ) );

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 30 );
		add_action( 'wp_ajax_meb_save_disclaimer', array( __CLASS__, 'handle_disclaimer_ajax' ) );
	}

	/**
	 * Policy page URL by slug (works on local and production; IDs differ).
	 *
	 * @param string $slug Page slug.
	 */
	public static function policy_url( string $slug ): string {
		if ( function_exists( 'meb_policy_page_url' ) ) {
			$url = meb_policy_page_url( $slug );
			if ( $url ) {
				return $url;
			}
		}
		$page = get_page_by_path( $slug );
		if ( $page instanceof WP_Post && 'publish' === $page->post_status ) {
			$url = get_permalink( $page );
			return $url ? (string) $url : home_url( '/' . $slug . '/' );
		}
		return home_url( '/' . $slug . '/' );
	}

	/**
	 * Policy URLs used in forms.
	 *
	 * @return array<string, string>
	 */
	public static function policy_urls(): array {
		return array(
			'terms'         => self::policy_url( 'terms-of-service' ),
			'privacy'       => self::policy_url( 'privacy-policy' ),
			'returns'       => self::policy_url( 'return-refund-policy' ),
			'shipping'      => self::policy_url( 'shipping-policy' ),
			'storage'       => self::policy_url( 'storage-inspection-policy' ),
			'compensation'  => self::policy_url( 'compensation-policy' ),
		);
	}

	/**
	 * Linked policy name, English, Chinese, or bilingual.
	 *
	 * @param string $slug Page slug.
	 * @param string $en   English label.
	 * @param string $zh   Chinese label.
	 * @param string $lang en|zh|both.
	 */
	public static function policy_anchor( string $slug, string $en, string $zh, string $lang = 'both' ): string {
		if ( 'zh' === $lang ) {
			$text = $zh;
		} elseif ( 'en' === $lang ) {
			$text = $en;
		} else {
			$text = $en . ' / ' . $zh;
		}
		$url = self::policy_url( $slug );
		if ( $url ) {
			return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $text ) . '</a>';
		}
		return esc_html( $text );
	}

	/**
	 * Registration checkbox markup (email sign-up form).
	 */
	public static function render_registration_checkbox(): void {
		$terms   = self::policy_url( 'terms-of-service' );
		$privacy = self::policy_url( 'privacy-policy' );
		$terms_l = $terms
			? '<a href="' . esc_url( $terms ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Terms of Service', 'myeasybuy-core' ) . ' / ' . esc_html__( '服务条款', 'myeasybuy-core' ) . '</a>'
			: esc_html__( 'Terms of Service', 'myeasybuy-core' ) . ' / ' . esc_html__( '服务条款', 'myeasybuy-core' );
		$privacy_l = $privacy
			? '<a href="' . esc_url( $privacy ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Privacy Policy', 'myeasybuy-core' ) . ' / ' . esc_html__( '隐私政策', 'myeasybuy-core' ) . '</a>'
			: esc_html__( 'Privacy Policy', 'myeasybuy-core' ) . ' / ' . esc_html__( '隐私政策', 'myeasybuy-core' );
		?>
		<p class="form-row meb-reg__terms">
			<label>
				<input type="checkbox" name="meb_terms" id="meb_terms" value="1" required>
				<span class="meb-reg__terms-copy">
					<span class="meb-reg__terms-en">I have read and agree to the <?php echo $terms_l; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> and <?php echo $privacy_l; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>.</span>
					<span class="meb-reg__terms-zh">我已阅读并同意《<?php echo $terms ? '<a href="' . esc_url( $terms ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( '服务条款', 'myeasybuy-core' ) . '</a>' : esc_html__( '服务条款', 'myeasybuy-core' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>》和《<?php echo $privacy ? '<a href="' . esc_url( $privacy ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( '隐私政策', 'myeasybuy-core' ) . '</a>' : esc_html__( '隐私政策', 'myeasybuy-core' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>》。</span>
				</span>
			</label>
		</p>
		<?php
	}

	/**
	 * Google button footnote (register + login).
	 */
	public static function render_google_note(): void {
		$terms   = self::policy_url( 'terms-of-service' );
		$privacy = self::policy_url( 'privacy-policy' );
		$terms_l = $terms
			? '<a href="' . esc_url( $terms ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Terms of Service', 'myeasybuy-core' ) . ' / ' . esc_html__( '服务条款', 'myeasybuy-core' ) . '</a>'
			: esc_html__( 'Terms of Service / 服务条款', 'myeasybuy-core' );
		$privacy_l = $privacy
			? '<a href="' . esc_url( $privacy ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Privacy Policy', 'myeasybuy-core' ) . ' / ' . esc_html__( '隐私政策', 'myeasybuy-core' ) . '</a>'
			: esc_html__( 'Privacy Policy / 隐私政策', 'myeasybuy-core' );
		echo '<p class="meb-reg__google-note">';
		echo 'By continuing with Google, you agree to the ' . $terms_l . ' and ' . $privacy_l . '.'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<br />';
		echo '继续使用 Google，即表示你同意《';
		echo $terms ? '<a href="' . esc_url( $terms ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( '服务条款', 'myeasybuy-core' ) . '</a>' : esc_html__( '服务条款', 'myeasybuy-core' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '》和《';
		echo $privacy ? '<a href="' . esc_url( $privacy ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( '隐私政策', 'myeasybuy-core' ) . '</a>' : esc_html__( '隐私政策', 'myeasybuy-core' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '》。';
		echo '</p>';
	}

	/**
	 * Informal notice on the forwarding pre-alert form (no checkbox).
	 */
	public static function render_forwarding_notice(): void {
		$en = self::policy_anchor( 'shipping-policy', 'Shipping & Prohibited Items Policy', '运输与禁运政策', 'en' );
		$zh = self::policy_anchor( 'shipping-policy', 'Shipping & Prohibited Items Policy', '运输与禁运政策', 'zh' );
		echo '<p class="meb-consent-forward-note">';
		echo esc_html__( 'Please confirm your parcel does not contain prohibited items. See the', 'myeasybuy-core' ) . ' ' . $en . '.'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<br />';
		echo esc_html__( '请确认包裹不含禁运物品。详见', 'myeasybuy-core' ) . '《' . $zh . '》。'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</p>';
	}

	/**
	 * Save registration consent to user meta.
	 *
	 * @param int    $user_id User ID.
	 * @param string $source  email_register|google|interstitial.
	 */
	public static function save_registration_consent( int $user_id, string $source ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		$payload = array(
			'version'     => self::REG_VERSION,
			'accepted_at' => current_time( 'mysql' ),
			'accepted_ts' => time(),
			'source'      => $source,
			'policies'    => array( 'terms-of-service', 'privacy-policy' ),
		);
		update_user_meta( $user_id, self::USER_META_CONSENT, $payload );
		delete_user_meta( $user_id, self::USER_META_CONSENT_PENDING );
	}

	/**
	 * Whether the user has accepted the current registration consent version.
	 *
	 * @param int $user_id User ID.
	 */
	public static function user_has_registration_consent( int $user_id ): bool {
		$data = get_user_meta( $user_id, self::USER_META_CONSENT, true );
		if ( ! is_array( $data ) || empty( $data['version'] ) ) {
			return false;
		}
		return (string) $data['version'] === self::REG_VERSION;
	}

	/**
	 * Email registration: persist consent when the checkbox was posted.
	 *
	 * @param int $customer_id New customer ID.
	 */
	public static function on_email_registration( int $customer_id ): void {
		if ( empty( $_POST['meb_terms'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		self::save_registration_consent( $customer_id, 'email_register' );
	}

	/**
	 * New Google account: save consent if the pre-click cookie is valid, else mark pending.
	 *
	 * @param int $user_id New user ID.
	 */
	public static function on_google_registration( $user_id ): void {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return;
		}
		if ( self::request_has_valid_reg_consent() ) {
			self::save_registration_consent( $user_id, 'google' );
			return;
		}
		update_user_meta( $user_id, self::USER_META_CONSENT_PENDING, '1' );
	}

	/**
	 * Cookie or request nonce from the register-page Google click.
	 */
	public static function request_has_valid_reg_consent(): bool {
		$token = '';
		if ( ! empty( $_COOKIE[ self::COOKIE_REG_CONSENT ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$token = sanitize_text_field( wp_unslash( (string) $_COOKIE[ self::COOKIE_REG_CONSENT ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		if ( ! $token && ! empty( $_GET['meb_consent_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$token = sanitize_text_field( wp_unslash( (string) $_GET['meb_consent_nonce'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		return $token && wp_verify_nonce( $token, self::NONCE_REG );
	}

	/**
	 * Interstitial for new Google users who have not yet accepted the policies.
	 */
	public static function maybe_show_interstitial(): void {
		if ( ! is_user_logged_in() || is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		if ( is_user_member_of_blog() && user_can( get_current_user_id(), 'manage_options' ) && is_admin() ) {
			return;
		}

		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( in_array( $action, array( 'logout', 'meb_save_registration_consent' ), true ) ) {
			return;
		}
		if ( isset( $_GET['preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$user_id = get_current_user_id();
		$pending = (string) get_user_meta( $user_id, self::USER_META_CONSENT_PENDING, true );
		if ( '1' !== $pending ) {
			return;
		}
		if ( self::user_has_registration_consent( $user_id ) ) {
			delete_user_meta( $user_id, self::USER_META_CONSENT_PENDING );
			return;
		}

		self::render_interstitial();
		exit;
	}

	/**
	 * Full-page consent gate (new Google accounts only).
	 */
	public static function render_interstitial(): void {
		$return = class_exists( 'MyEasyBuy_Login_Required' ) ? MyEasyBuy_Login_Required::get_requested_return_url() : '';
		if ( ! $return && ! empty( $_GET['redirect_to'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$return = wp_validate_redirect( esc_url_raw( wp_unslash( (string) $_GET['redirect_to'] ) ), '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( ! $return ) {
			$return = home_url( '/' );
		}
		status_header( 200 );
		nocache_headers();
		?><!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php echo esc_html__( 'Please accept the Terms of Service and Privacy Policy', 'myeasybuy-core' ); ?></title>
			<?php wp_head(); ?>
		</head>
		<body class="meb-consent-gate">
		<?php wp_body_open(); ?>
		<main class="meb-consent-gate__box">
			<h1><?php echo esc_html__( 'One more step / 还差一步', 'myeasybuy-core' ); ?></h1>
			<p><?php echo esc_html__( 'Please accept the Terms of Service and Privacy Policy to continue.', 'myeasybuy-core' ); ?></p>
			<p><?php echo esc_html__( '请先同意《服务条款》和《隐私政策》，然后继续使用网站。', 'myeasybuy-core' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="meb_save_registration_consent" />
				<input type="hidden" name="redirect_to" value="<?php echo esc_url( $return ); ?>" />
				<?php wp_nonce_field( self::NONCE_INTERSTITIAL ); ?>
				<?php self::render_registration_checkbox(); ?>
				<button type="submit" class="meb-consent-gate__submit"><?php echo esc_html__( 'Continue / 继续', 'myeasybuy-core' ); ?></button>
			</form>
		</main>
		<?php wp_footer(); ?>
		</body>
		</html>
		<?php
	}

	/**
	 * Save interstitial consent and continue to the original destination.
	 */
	public static function handle_interstitial(): void {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
		check_admin_referer( self::NONCE_INTERSTITIAL );
		if ( empty( $_POST['meb_terms'] ) ) {
			wp_safe_redirect( add_query_arg( 'meb_consent_required', '1', wp_get_referer() ? wp_get_referer() : home_url( '/' ) ) );
			exit;
		}
		self::save_registration_consent( get_current_user_id(), 'interstitial' );
		$return = home_url( '/' );
		if ( ! empty( $_POST['redirect_to'] ) ) {
			$candidate = wp_validate_redirect( esc_url_raw( wp_unslash( (string) $_POST['redirect_to'] ) ), '' );
			if ( $candidate ) {
				$return = $candidate;
			}
		}
		wp_safe_redirect( $return );
		exit;
	}

	/**
	 * Cart contains a reverse-forwarding consolidation (intl shipping) item.
	 */
	public static function cart_is_shipping(): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}
		$key = class_exists( '\ReverseForwarding\Checkout\Cart_Integration' )
			? \ReverseForwarding\Checkout\Cart_Integration::CART_KEY
			: 'wc_rf_consolidation_id';
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( ! empty( $item[ $key ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Order is an international-shipping / second-payment / consolidation invoice.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function order_is_shipping( WC_Order $order ): bool {
		if ( class_exists( 'MyEasyBuy_Consolidation' )
			&& MyEasyBuy_Consolidation::PAYMENT_TYPE_SECOND === $order->get_meta( MyEasyBuy_Consolidation::META_PAYMENT_TYPE, true ) ) {
			return true;
		}
		if ( absint( $order->get_meta( '_wc_rf_consolidation_id' ) ) > 0 ) {
			return true;
		}
		if ( absint( $order->get_meta( '_myeasybuy_second_payment_order_id' ) ) === (int) $order->get_id() ) {
			return true;
		}
		return false;
	}

	/**
	 * Register WooCommerce Blocks additional checkout fields.
	 */
	public static function register_checkout_fields(): void {
		static $registered = false;
		if ( $registered || ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}
		$registered = true;

		$purchase_label = 'I have read and agree to the Terms of Service, Return & Refund Policy, and Shipping & Prohibited Items Policy. / 我已阅读并同意《服务条款》《退货退款政策》和《运输与禁运政策》。';
		woocommerce_register_additional_checkout_field(
			array(
				'id'            => self::FIELD_PURCHASE,
				'label'         => $purchase_label,
				'optionalLabel' => $purchase_label, // keep required=false for per-cart display; do not show "(optional)"
				'location'      => 'order',
				'type'          => 'checkbox',
				'required'      => false,
			)
		);

		$shipping_label = 'I have read and agree to the Shipping & Prohibited Items Policy, Storage & Inspection Policy, and Compensation & Parcel Protection Policy. / 我已阅读并同意《运输与禁运政策》《仓储与验货政策》和《赔偿与包裹保障》。';
		woocommerce_register_additional_checkout_field(
			array(
				'id'            => self::FIELD_SHIPPING,
				'label'         => $shipping_label,
				'optionalLabel' => $shipping_label,
				'location'      => 'order',
				'type'          => 'checkbox',
				'required'      => false,
			)
		);

		$protection_label = 'Parcel protection / 包裹保障';
		woocommerce_register_additional_checkout_field(
			array(
				'id'            => self::FIELD_PROTECTION,
				'label'         => $protection_label,
				'optionalLabel' => $protection_label,
				'location'      => 'order',
				'type'          => 'select',
				'required'      => false,
				'options'  => array(
					array(
						'value' => '',
						'label' => 'Please choose / 请选择',
					),
					array(
						'value' => 'buy',
						'label' => 'I want Parcel Protection / 我要购买包裹保障',
					),
					array(
						'value' => 'decline',
						'label' => 'I decline Parcel Protection. Uninsured loss compensation is limited to the lower of 3× international shipping or CAD 100. / 我放弃包裹保障。未投保损失的赔偿上限为国际运费的 3 倍与 CAD 100 中的较低者。',
					),
				),
			)
		);
	}

	/**
	 * Hide the default WooCommerce terms checkbox when we render our own (order-pay / classic).
	 *
	 * @param bool $show Whether to show default terms.
	 */
	public static function maybe_hide_default_terms( $show ) {
		if ( is_checkout() && is_wc_endpoint_url( 'order-pay' ) ) {
			return false;
		}
		return $show;
	}

	/**
	 * Additional-fields values from a Store API request.
	 *
	 * @param WP_REST_Request|array $request Request.
	 * @return array<string, mixed>
	 */
	private static function additional_fields_from_request( $request ): array {
		$params = array();
		if ( is_object( $request ) && method_exists( $request, 'get_json_params' ) ) {
			$params = $request->get_json_params();
		} elseif ( is_object( $request ) && method_exists( $request, 'get_params' ) ) {
			$params = $request->get_params();
		} elseif ( is_array( $request ) ) {
			$params = $request;
		}
		$fields = array();
		if ( isset( $params['additional_fields'] ) && is_array( $params['additional_fields'] ) ) {
			$fields = $params['additional_fields'];
		}
		return $fields;
	}

	/**
	 * Validate Blocks checkout and attach meta on the in-progress order.
	 *
	 * @param WC_Order        $order   Order.
	 * @param WP_REST_Request $request Request.
	 */
	public static function validate_and_save_blocks_checkout( $order, $request ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$fields  = self::additional_fields_from_request( $request );
		$shipping = self::cart_is_shipping() || self::order_is_shipping( $order );

		if ( $shipping ) {
			if ( empty( $fields[ self::FIELD_SHIPPING ] ) ) {
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
					'meb_shipping_consent_required',
					__( 'Please agree to the Shipping & Prohibited Items, Storage & Inspection, and Compensation & Parcel Protection policies. / 请先同意《运输与禁运政策》《仓储与验货政策》和《赔偿与包裹保障》。', 'myeasybuy-core' ),
					400
				);
			}
			$choice = isset( $fields[ self::FIELD_PROTECTION ] ) ? sanitize_key( (string) $fields[ self::FIELD_PROTECTION ] ) : '';
			if ( ! in_array( $choice, array( 'buy', 'decline' ), true ) ) {
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
					'meb_protection_choice_required',
					__( 'Please choose to buy or decline parcel protection. / 请选择购买或放弃包裹保障。', 'myeasybuy-core' ),
					400
				);
			}
			self::write_shipping_order_meta( $order, $choice );
			return;
		}

		if ( empty( $fields[ self::FIELD_PURCHASE ] ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
				'meb_purchase_consent_required',
				__( 'Please agree to the Terms of Service, Return & Refund Policy, and Shipping & Prohibited Items Policy. / 请先同意《服务条款》《退货退款政策》和《运输与禁运政策》。', 'myeasybuy-core' ),
				400
			);
		}

		if ( ! self::user_has_disclaimer_consent( get_current_user_id() ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
				'meb_disclaimer_required',
				__( 'Please review and accept “Three Things You Should Know” before placing your first purchase order. / 首次代购下单前，请先阅读并同意「三件事须知」。', 'myeasybuy-core' ),
				400
			);
		}

		self::write_purchase_order_meta( $order );
	}

	/**
	 * Persist checkout meta after the Blocks order is stored.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function save_blocks_checkout_meta( $order ): void {
		if ( $order instanceof WC_Order ) {
			$order->save();
		}
	}

	/**
	 * Classic checkout (rare; most traffic is Blocks or order-pay).
	 *
	 * @param WC_Order $order Order.
	 * @param array    $data  Posted data.
	 */
	public static function validate_and_save_classic_checkout( $order, $data ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		if ( self::cart_is_shipping() ) {
			if ( empty( $_POST['meb_shipping_consent'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				wc_add_notice( __( 'Please agree to the shipping, storage, and compensation policies. / 请先同意运输、仓储与赔偿政策。', 'myeasybuy-core' ), 'error' );
				return;
			}
			$choice = isset( $_POST['meb_parcel_protection'] ) ? sanitize_key( wp_unslash( (string) $_POST['meb_parcel_protection'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! in_array( $choice, array( 'buy', 'decline' ), true ) ) {
				wc_add_notice( __( 'Please choose to buy or decline parcel protection. / 请选择购买或放弃包裹保障。', 'myeasybuy-core' ), 'error' );
				return;
			}
			self::write_shipping_order_meta( $order, $choice );
			return;
		}
		if ( empty( $_POST['meb_purchase_consent'] ) && empty( $_POST[ str_replace( '/', '_', self::FIELD_PURCHASE ) ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			// Blocks additional fields may also post under their ids; skip if already handled.
			return;
		}
		if ( ! empty( $_POST['meb_purchase_consent'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! self::user_has_disclaimer_consent( get_current_user_id() ) ) {
				wc_add_notice( __( 'Please review and accept “Three Things You Should Know” first. / 请先阅读并同意「三件事须知」。', 'myeasybuy-core' ), 'error' );
				return;
			}
			self::write_purchase_order_meta( $order );
		}
	}

	/**
	 * @param WC_Order $order Order.
	 */
	private static function write_purchase_order_meta( WC_Order $order ): void {
		$order->update_meta_data(
			self::ORDER_META_PURCHASE,
			array(
				'version'     => self::PURCHASE_VERSION,
				'accepted_at' => current_time( 'mysql' ),
				'accepted_ts' => time(),
				'policies'    => array( 'terms-of-service', 'return-refund-policy', 'shipping-policy' ),
			)
		);
	}

	/**
	 * @param WC_Order $order  Order.
	 * @param string   $choice buy|decline.
	 */
	private static function write_shipping_order_meta( WC_Order $order, string $choice ): void {
		$order->update_meta_data(
			self::ORDER_META_SHIPPING,
			array(
				'version'     => self::SHIPPING_VERSION,
				'accepted_at' => current_time( 'mysql' ),
				'accepted_ts' => time(),
				'policies'    => array( 'shipping-policy', 'storage-inspection-policy', 'compensation-policy' ),
				'protection'  => $choice,
			)
		);
		$order->update_meta_data( self::ORDER_META_PROTECTION, 'buy' === $choice ? 'yes' : 'no' );
	}

	/**
	 * Consent UI on the classic order-pay screen (代购二价 bills).
	 */
	public static function render_order_pay_consent(): void {
		$order_id = absint( get_query_var( 'order-pay' ) );
		$order    = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$shipping = self::order_is_shipping( $order );
		$urls     = self::policy_urls();
		echo '<div class="meb-pay-consent">';
		if ( $shipping ) {
			$s_en = self::policy_anchor( 'shipping-policy', 'Shipping & Prohibited Items Policy', '运输与禁运政策', 'en' );
			$t_en = self::policy_anchor( 'storage-inspection-policy', 'Storage & Inspection Policy', '仓储与验货政策', 'en' );
			$c_en = self::policy_anchor( 'compensation-policy', 'Compensation & Parcel Protection', '赔偿与包裹保障', 'en' );
			$s_zh = self::policy_anchor( 'shipping-policy', 'Shipping & Prohibited Items Policy', '运输与禁运政策', 'zh' );
			$t_zh = self::policy_anchor( 'storage-inspection-policy', 'Storage & Inspection Policy', '仓储与验货政策', 'zh' );
			$c_zh = self::policy_anchor( 'compensation-policy', 'Compensation & Parcel Protection', '赔偿与包裹保障', 'zh' );
			echo '<p class="form-row validate-required meb-pay-consent__check">';
			echo '<label><input type="checkbox" name="meb_shipping_consent" value="1" required /> ';
			echo '<span>I have read and agree to the ' . $s_en . ', ' . $t_en . ', and ' . $c_en . '.<br />我已阅读并同意《' . $s_zh . '》《' . $t_zh . '》和《' . $c_zh . '》。</span></label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</p>';
			echo '<fieldset class="meb-pay-consent__protection">';
			echo '<legend>' . esc_html__( 'Parcel protection / 包裹保障', 'myeasybuy-core' ) . '</legend>';
			echo '<label><input type="radio" name="meb_parcel_protection" value="buy" required /> ' . esc_html__( 'I want Parcel Protection / 我要购买包裹保障', 'myeasybuy-core' ) . '</label>';
			echo '<label><input type="radio" name="meb_parcel_protection" value="decline" required /> ' . esc_html__( 'I decline Parcel Protection. I understand that uninsured loss compensation is limited to the lower of 3× the international shipping cost or CAD 100. / 我放弃购买包裹保障，并理解未投保损失的赔偿上限为国际运费的 3 倍与 CAD 100 中的较低者。', 'myeasybuy-core' ) . '</label>';
			echo '</fieldset>';
		} else {
			$a_en = self::policy_anchor( 'terms-of-service', 'Terms of Service', '服务条款', 'en' );
			$b_en = self::policy_anchor( 'return-refund-policy', 'Return & Refund Policy', '退货退款政策', 'en' );
			$d_en = self::policy_anchor( 'shipping-policy', 'Shipping & Prohibited Items Policy', '运输与禁运政策', 'en' );
			$a_zh = self::policy_anchor( 'terms-of-service', 'Terms of Service', '服务条款', 'zh' );
			$b_zh = self::policy_anchor( 'return-refund-policy', 'Return & Refund Policy', '退货退款政策', 'zh' );
			$d_zh = self::policy_anchor( 'shipping-policy', 'Shipping & Prohibited Items Policy', '运输与禁运政策', 'zh' );
			echo '<p class="form-row validate-required meb-pay-consent__check">';
			echo '<label><input type="checkbox" name="meb_purchase_consent" value="1" required /> ';
			echo '<span>I have read and agree to the ' . $a_en . ', ' . $b_en . ', and ' . $d_en . '.<br />我已阅读并同意《' . $a_zh . '》《' . $b_zh . '》和《' . $d_zh . '》。</span></label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</p>';
		}
		echo '</div>';
	}

	/**
	 * Validate and save consent when paying an existing order.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function validate_order_pay_consent( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		if ( self::order_is_shipping( $order ) ) {
			if ( empty( $_POST['meb_shipping_consent'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				wc_add_notice( __( 'Please agree to the Shipping & Prohibited Items, Storage & Inspection, and Compensation & Parcel Protection policies. / 请先同意《运输与禁运政策》《仓储与验货政策》和《赔偿与包裹保障》。', 'myeasybuy-core' ), 'error' );
				return;
			}
			$choice = isset( $_POST['meb_parcel_protection'] ) ? sanitize_key( wp_unslash( (string) $_POST['meb_parcel_protection'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! in_array( $choice, array( 'buy', 'decline' ), true ) ) {
				wc_add_notice( __( 'Please choose to buy or decline parcel protection. / 请选择购买或放弃包裹保障。', 'myeasybuy-core' ), 'error' );
				return;
			}
			self::write_shipping_order_meta( $order, $choice );
			$order->save();
			return;
		}
		if ( empty( $_POST['meb_purchase_consent'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			wc_add_notice( __( 'Please agree to the Terms of Service, Return & Refund Policy, and Shipping & Prohibited Items Policy. / 请先同意《服务条款》《退货退款政策》和《运输与禁运政策》。', 'myeasybuy-core' ), 'error' );
			return;
		}
		self::write_purchase_order_meta( $order );
		$order->save();
	}

	/**
	 * Current disclaimer accepted?
	 *
	 * @param int $user_id User ID.
	 */
	public static function user_has_disclaimer_consent( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		$data = get_user_meta( $user_id, self::USER_META_DISCLAIMER, true );
		if ( ! is_array( $data ) || empty( $data['version'] ) ) {
			return false;
		}
		return (string) $data['version'] === self::DISCLAIMER_VERSION;
	}

	/**
	 * Record first-order disclaimer acceptance.
	 *
	 * @param int $user_id User ID.
	 */
	public static function save_disclaimer_consent( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		update_user_meta(
			$user_id,
			self::USER_META_DISCLAIMER,
			array(
				'version'     => self::DISCLAIMER_VERSION,
				'accepted_at' => current_time( 'mysql' ),
				'accepted_ts' => time(),
			)
		);
	}

	/**
	 * AJAX: save first-order disclaimer.
	 */
	public static function handle_disclaimer_ajax(): void {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => 'login' ), 403 );
		}
		check_ajax_referer( self::NONCE_DISCLAIMER, 'nonce' );
		if ( empty( $_POST['accepted'] ) ) {
			wp_send_json_error( array( 'message' => 'required' ), 400 );
		}
		self::save_disclaimer_consent( get_current_user_id() );
		wp_send_json_success(
			array(
				'version' => self::DISCLAIMER_VERSION,
			)
		);
	}

	/**
	 * Front-end assets.
	 */
	public static function enqueue_assets(): void {
		$ver = defined( 'MYEASYBUY_CORE_VERSION' ) ? MYEASYBUY_CORE_VERSION : '1.20.0';
		$css = MYEASYBUY_CORE_URL . 'assets/css/policy-consent.css';
		$js  = MYEASYBUY_CORE_URL . 'assets/js/policy-consent.js';

		$is_auth = function_exists( 'meb_is_auth_page' ) ? meb_is_auth_page() : ( function_exists( 'meb_is_register_page' ) && ( meb_is_register_page() || ( function_exists( 'meb_is_login_page' ) && meb_is_login_page() ) ) );
		$need    = $is_auth || is_checkout() || is_cart() || is_account_page();
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) {
			$need = true;
		}
		if ( is_user_logged_in() && '1' === (string) get_user_meta( get_current_user_id(), self::USER_META_CONSENT_PENDING, true ) ) {
			$need = true;
		}
		if ( get_query_var( 'wc_rf_forwarding_center' ) ) {
			$need = true;
		}
		if ( ! $need ) {
			return;
		}

		wp_enqueue_style( 'myeasybuy-policy-consent', $css, array(), $ver );
		wp_enqueue_script( 'myeasybuy-policy-consent', $js, array(), $ver, true );

		$show_disclaimer = false;
		if ( is_user_logged_in() && is_checkout() && ! is_wc_endpoint_url( 'order-pay' ) && ! is_wc_endpoint_url( 'order-received' ) && ! self::cart_is_shipping() ) {
			$show_disclaimer = ! self::user_has_disclaimer_consent( get_current_user_id() );
		}

		wp_localize_script(
			'myeasybuy-policy-consent',
			'mebPolicyConsent',
			array(
				'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
				'disclaimerNonce'    => wp_create_nonce( self::NONCE_DISCLAIMER ),
				'regNonce'           => wp_create_nonce( self::NONCE_REG ),
				'cookieName'         => self::COOKIE_REG_CONSENT,
				'cartIsShipping'     => self::cart_is_shipping(),
				'showDisclaimer'     => $show_disclaimer,
				'disclaimerVersion'  => self::DISCLAIMER_VERSION,
				'fieldPurchase'      => self::FIELD_PURCHASE,
				'fieldShipping'      => self::FIELD_SHIPPING,
				'fieldProtection'    => self::FIELD_PROTECTION,
				'policyUrls'         => self::policy_urls(),
				'disclaimer'         => array(
					'eyebrowEn' => 'MyEasyBuy cares about you!',
					'eyebrowZh' => 'MyEasyBuy 想让你先了解这些',
					'badgeEn'   => 'Three Things You Should Know',
					'badgeZh'   => '三件事须知',
					'nextEn'    => 'Confirm, Next',
					'nextZh'    => '确认，下一步',
					'backEn'    => 'Back',
					'backZh'    => '返回',
					'acceptEn'  => 'I understand and accept',
					'acceptZh'  => '我已了解并同意',
					'continueEn'=> 'Continue',
					'continueZh'=> '继续',
					'steps'     => self::disclaimer_steps(),
				),
			)
		);
	}
}
