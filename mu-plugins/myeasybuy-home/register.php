<?php
/**
 * Dedicated registration page — split promo + sign-up form.
 *
 * @package MyEasyBuy_Home
 */

defined( 'ABSPATH' ) || exit;

if ( is_user_logged_in() ) {
	wp_safe_redirect( function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/' ) );
	exit;
}

$meb_u            = meb_urls();
$meb_privacy_url  = get_privacy_policy_url();
$meb_terms_url    = apply_filters( 'myeasybuy_registration_agreement_url', $meb_privacy_url );
$meb_statement_url = apply_filters( 'myeasybuy_registration_statement_url', $meb_privacy_url );
$meb_google_url   = (string) apply_filters( 'myeasybuy_google_signup_url', '' );
$meb_brand     = get_bloginfo( 'name' );
$meb_login_url = meb_with_return_url( $meb_u['login'] );
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

			<h1 class="meb-reg__title">Sign up</h1>

			<?php
			if ( function_exists( 'woocommerce_output_all_notices' ) ) {
				woocommerce_output_all_notices();
			} elseif ( function_exists( 'wc_print_notices' ) ) {
				wc_print_notices();
			}
			?>

			<form method="post" class="woocommerce-form woocommerce-form-register register meb-reg__form" <?php do_action( 'woocommerce_register_form_tag' ); ?>>
				<?php do_action( 'woocommerce_register_form_start' ); ?>

				<?php if ( 'no' === get_option( 'woocommerce_registration_generate_username' ) ) : ?>
					<p class="form-row form-row-wide">
						<label class="screen-reader-text" for="reg_username"><?php esc_html_e( 'Username', 'woocommerce' ); ?></label>
						<input type="text" class="input-text" name="username" id="reg_username" autocomplete="username" placeholder="<?php esc_attr_e( 'Username', 'woocommerce' ); ?>" value="<?php echo esc_attr( class_exists( 'MyEasyBuy_Registration' ) ? MyEasyBuy_Registration::posted_value_public( 'username' ) : '' ); ?>" required>
					</p>
				<?php endif; ?>

				<p class="form-row form-row-wide">
					<label class="screen-reader-text" for="reg_email">E-mail</label>
					<input type="email" class="input-text" name="email" id="reg_email" autocomplete="email" placeholder="E-mail" value="<?php echo esc_attr( class_exists( 'MyEasyBuy_Registration' ) ? MyEasyBuy_Registration::posted_value_public( 'email' ) : '' ); ?>" required>
				</p>

				<p class="form-row form-row-wide meb-reg__pass">
					<label class="screen-reader-text" for="reg_password">Password</label>
					<input type="password" class="input-text" name="password" id="reg_password" autocomplete="new-password" placeholder="Password" required>
					<button type="button" class="meb-reg__eye" data-meb-toggle-pass="reg_password" aria-label="Show password">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
					</button>
				</p>

				<p class="form-row form-row-wide meb-reg__pass">
					<label class="screen-reader-text" for="reg_password2">Confirm Password</label>
					<input type="password" class="input-text" name="password2" id="reg_password2" autocomplete="new-password" placeholder="Confirm Password" required>
					<button type="button" class="meb-reg__eye" data-meb-toggle-pass="reg_password2" aria-label="Show password">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
					</button>
				</p>

				<p class="form-row form-row-wide">
					<label class="screen-reader-text" for="reg_invitation_code">Invitation code</label>
					<input type="text" class="input-text" name="invitation_code" id="reg_invitation_code" placeholder="Enter the invitation code: (optional)" value="<?php echo esc_attr( class_exists( 'MyEasyBuy_Registration' ) ? MyEasyBuy_Registration::posted_value_public( 'invitation_code' ) : '' ); ?>">
				</p>

				<p class="form-row meb-reg__terms">
					<label>
						<input type="checkbox" name="meb_terms" value="1" required>
						<span>
							I have already read and accept
							<?php if ( $meb_terms_url ) : ?>
								<a href="<?php echo esc_url( $meb_terms_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $meb_brand ); ?> User Registration Agreement</a>
							<?php else : ?>
								<?php echo esc_html( $meb_brand ); ?> User Registration Agreement
							<?php endif; ?>
							and
							<?php if ( $meb_statement_url ) : ?>
								<a href="<?php echo esc_url( $meb_statement_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $meb_brand ); ?> Statement</a>
							<?php else : ?>
								<?php echo esc_html( $meb_brand ); ?> Statement
							<?php endif; ?>
						</span>
					</label>
				</p>

				<?php wp_nonce_field( 'woocommerce-register', 'woocommerce-register-nonce' ); ?>
				<?php
				$meb_return = class_exists( 'MyEasyBuy_Login_Required' ) ? MyEasyBuy_Login_Required::get_requested_return_url() : '';
				if ( $meb_return ) {
					printf( '<input type="hidden" name="redirect" value="%s" />', esc_url( $meb_return ) );
				}
				?>

				<button type="submit" class="meb-reg__submit" name="register" value="Sign up">Sign up</button>

				<p class="meb-reg__login">
					Already have an account?
					<a href="<?php echo esc_url( $meb_login_url ); ?>">Log in</a>
				</p>

				<?php do_action( 'woocommerce_register_form' ); ?>
				<?php do_action( 'woocommerce_register_form_end' ); ?>
			</form>

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

<?php wp_footer(); ?>
</body>
</html>
