<?php
/**
 * Standalone forwarding center document. Site header/footer attach via wp_body_open / wp_footer.
 *
 * This template can be overridden by copying it to:
 * yourtheme/wc-reverse-forwarding/forwarding-center/page.php
 *
 * @package ReverseForwarding
 */

defined( 'ABSPATH' ) || exit;
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<main id="content" class="wc-rf-fc">
	<div class="wc-rf-fc__container">
		<?php \ReverseForwarding\Frontend\Forwarding_Center::render_body(); ?>
	</div>
</main>

<?php wp_footer(); ?>
</body>
</html>
