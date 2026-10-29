<?php
/**
 * Dedicated login page — same split layout as registration.
 *
 * @package MyEasyBuy_Home
 */

defined( 'ABSPATH' ) || exit;

$meb_account_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/' );
$meb_return_url  = class_exists( 'MyEasyBuy_Login_Required' ) ? MyEasyBuy_Login_Required::get_requested_return_url() : '';

if ( is_user_logged_in() ) {
	wp_safe_redirect( $meb_return_url ? $meb_return_url : $meb_account_url );
	exit;
}

$meb_u           = meb_urls();
$meb_brand       = get_bloginfo( 'name' );
$meb_signup_url  = meb_with_return_url( $meb_u['register'] );
$meb_lost_url    = function_exists( 'wc_lostpassword_url' ) ? wc_lostpassword_url() : wp_lostpassword_url();
$meb_google_url  = (string) apply_filters( 'myeasybuy_google_login_url', apply_filters( 'myeasybuy_google_signup_url', '' ) );
$meb_redirect_to = $meb_return_url ? $meb_return_url : $meb_account_url;
$meb_username    = class_exists( 'MyEasyBuy_Registration' ) ? MyEasyBuy_Registration::posted_value_public( 'username' ) : '';
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<main class="meb-reg" id="content">
	<?php include MYEASYBUY_HOME_DIR . 'parts/auth-promo.php'; ?>

	<section class="meb-reg__panel">
		<div class="meb-reg__form-wrap">
			<a class="meb-reg__brand" href="<?php echo esc_url( home_url( '/' ) ); ?>">
				<span class="meb-reg__brand-mark"><?php echo meb_icon( 'bag' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<?php echo esc_html( $meb_brand ); ?>
			</a>

			<h1 class="meb-reg__title">Welcome back</h1>

			<?php
			if ( function_exists( 'woocommerce_output_all_notices' ) ) {
				woocommerce_output_all_notices();
			} elseif ( function_exists( 'wc_print_notices' ) ) {
				wc_print_notices();
			}

			do_action( 'woocommerce_before_customer_login_form' );
			?>

			<form class="woocommerce-form woocommerce-form-login login meb-reg__form" method="post">
				<?php do_action( 'woocommerce_login_form_start' ); ?>

				<p class="form-row form-row-wide">
					<label class="screen-reader-text" for="username">E-mail</label>
					<input type="text" class="input-text" name="username" id="username" autocomplete="username" placeholder="E-mail" value="<?php echo esc_attr( $meb_username ); ?>" required>
				</p>

				<p class="form-row form-row-wide meb-reg__pass">
					<label class="screen-reader-text" for="password">Password</label>
					<input type="password" class="input-text" name="password" id="password" autocomplete="current-password" placeholder="Password" required>
					<button type="button" class="meb-reg__eye" data-meb-toggle-pass="password" aria-label="Show password">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
					</button>
				</p>

				<?php do_action( 'woocommerce_login_form' ); ?>

				<?php wp_nonce_field( 'woocommerce-login', 'woocommerce-login-nonce' ); ?>
				<?php do_action( 'woocommerce_login_form_end' ); ?>
				<input type="hidden" name="redirect" value="<?php echo esc_url( $meb_redirect_to ); ?>">

				<button type="submit" class="meb-reg__submit" name="login" value="Log in">Log in</button>
			</form>

			<p class="meb-reg__links">
				<a href="<?php echo esc_url( $meb_lost_url ); ?>">Forgot your password?</a>
				<a href="<?php echo esc_url( $meb_signup_url ); ?>">Sign up</a>
			</p>

			<div class="meb-reg__or"><span>or</span></div>
			<?php if ( $meb_google_url ) : ?>
				<a class="meb-reg__google" href="<?php echo esc_url( $meb_google_url ); ?>">
			<?php else : ?>
				<span class="meb-reg__google" role="note">
			<?php endif; ?>
					<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
						<path fill="#4285F4" d="M23.5 12.3c0-.8-.1-1.6-.2-2.3H12v4.4h6.5c-.3 1.5-1.2 2.8-2.5 3.6v3h4c2.4-2.2 3.7-5.4 3.7-8.7z"/>
						<path fill="#34A853" d="M12 24c3.2 0 6-1.1 8-2.9l-4-3c-1.1.8-2.5 1.2-4 1.2-3.1 0-5.7-2.1-6.6-4.9H1.3v3.1C3.3 21.3 7.4 24 12 24z"/>
						<path fill="#FBBC05" d="M5.4 14.4c-.2-.7-.4-1.4-.4-2.4s.1-1.7.4-2.4V6.5H1.3C.5 8.2 0 10.1 0 12s.5 3.8 1.3 5.5l4.1-3.1z"/>
						<path fill="#EA4335" d="M12 4.8c1.8 0 3.4.6 4.6 1.8l3.5-3.5C18 1.1 15.2 0 12 0 7.4 0 3.3 2.7 1.3 6.5l4.1 3.1C6.3 6.8 8.9 4.8 12 4.8z"/>
					</svg>
					Continue with Google
			<?php if ( $meb_google_url ) : ?>
				</a>
			<?php else : ?>
				</span>
			<?php endif; ?>
		</div>
	</section>
</main>

<?php do_action( 'woocommerce_after_customer_login_form' ); ?>
<?php wp_footer(); ?>
</body>
</html>
