<?php
/**
 * Shared address fields for shipping and billing forms.
 *
 * @package MyEasyBuy_Core
 *
 * @var array{name?: string, phone?: string, country?: string, state?: string, city?: string, address?: string, postcode?: string} $values
 * @var array<string, string> $countries
 */

defined( 'ABSPATH' ) || exit;

$values = isset( $values ) && is_array( $values ) ? $values : array();
?>
<label class="meb-acct__field">
	<span><?php esc_html_e( '收件人', 'myeasybuy-core' ); ?></span>
	<input type="text" name="name" value="<?php echo esc_attr( (string) ( $values['name'] ?? '' ) ); ?>" autocomplete="name" required>
</label>
<label class="meb-acct__field">
	<span><?php esc_html_e( '电话', 'myeasybuy-core' ); ?></span>
	<input type="tel" name="phone" value="<?php echo esc_attr( (string) ( $values['phone'] ?? '' ) ); ?>" autocomplete="tel"<?php echo ! empty( $require_phone ) ? ' required' : ''; ?>>
</label>
<label class="meb-acct__field">
	<span><?php esc_html_e( '国家', 'myeasybuy-core' ); ?></span>
	<select name="country" class="<?php echo '' !== (string) ( $values['country'] ?? '' ) ? 'has-value' : ''; ?>" required>
		<option value=""><?php esc_html_e( '选择国家', 'myeasybuy-core' ); ?></option>
		<?php foreach ( $countries as $code => $country_name ) : ?>
			<option value="<?php echo esc_attr( $code ); ?>"<?php selected( (string) ( $values['country'] ?? '' ), $code ); ?>><?php echo esc_html( $country_name ); ?></option>
		<?php endforeach; ?>
	</select>
</label>
<label class="meb-acct__field">
	<span><?php esc_html_e( '省/州', 'myeasybuy-core' ); ?></span>
	<input type="text" name="state" value="<?php echo esc_attr( (string) ( $values['state'] ?? '' ) ); ?>" autocomplete="address-level1">
</label>
<label class="meb-acct__field">
	<span><?php esc_html_e( '城市', 'myeasybuy-core' ); ?></span>
	<input type="text" name="city" value="<?php echo esc_attr( (string) ( $values['city'] ?? '' ) ); ?>" autocomplete="address-level2">
</label>
<label class="meb-acct__field">
	<span><?php esc_html_e( '详细地址', 'myeasybuy-core' ); ?></span>
	<input type="text" name="address" value="<?php echo esc_attr( (string) ( $values['address'] ?? '' ) ); ?>" autocomplete="street-address" required>
</label>
<label class="meb-acct__field">
	<span><?php esc_html_e( '邮编', 'myeasybuy-core' ); ?></span>
	<input type="text" name="postcode" value="<?php echo esc_attr( (string) ( $values['postcode'] ?? '' ) ); ?>" autocomplete="postal-code">
</label>
