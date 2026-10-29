<?php
/**
 * Shared left-hand offer panel for login and registration.
 *
 * @package MyEasyBuy_Home
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="meb-reg__promo" aria-label="New user offer">
	<a class="meb-reg__nav" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="Back">
		<?php echo meb_icon( 'left' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</a>

	<p class="meb-reg__kicker">Pick 1 <span>Free Gift</span></p>
	<p class="meb-reg__exclusive">EXCLUSIVE FOR NEW USERS</p>

	<div class="meb-reg__gift" aria-hidden="true">
		<span class="meb-reg__badge">LIMITED TIME OFFER</span>
		<svg class="meb-reg__gift-art" viewBox="0 0 320 300" fill="none" xmlns="http://www.w3.org/2000/svg">
			<ellipse cx="160" cy="268" rx="78" ry="12" fill="rgba(240,106,10,0.16)"/>
			<path d="M64 128h192c10 0 18 8 18 18v96c0 22-28 40-114 40S46 264 46 242v-96c0-10 8-18 18-18z" fill="#ff6b81"/>
			<path d="M64 128h192c10 0 18 8 18 18v22H46v-22c0-10 8-18 18-18z" fill="#ff8fa0"/>
			<path d="M146 128h28v154c-20 4-28 4-28 0V128z" fill="#ffd166"/>
			<path d="M38 104h244c12 0 20 8 18 18l-8 22H46l-10-22c-3-10 4-18 16-18z" fill="#ff8aa0"/>
			<path d="M146 96h28v48h-28z" fill="#ffd166"/>
			<path d="M160 58c-28 6-48 32-36 48 14-8 24-8 36-4 12-4 22-4 36 4 12-16-8-42-36-48z" fill="#ffcc4d"/>
			<path d="M124 88c-10 18 6 32 22 28-8-8-10-18-4-30-8 0-14 0-18 2z" fill="#ffe08a"/>
			<path d="M196 88c10 18-6 32-22 28 8-8 10-18 4-30 8 0 14 0 18 2z" fill="#ffbf3c"/>
			<circle cx="160" cy="108" r="16" fill="#ffdd66"/>
			<circle cx="154" cy="102" r="5" fill="#fff4c8"/>
			<path d="M92 168c18 10 28 10 46 0" stroke="#fff" stroke-width="3" stroke-linecap="round" opacity=".35"/>
		</svg>
	</div>

	<p class="meb-reg__ends">
		<span class="meb-reg__ends-label">OFFER ENDS IN</span>
		<span class="meb-reg__timer" data-meb-offer-countdown>
			<b>00</b><i>:</i><b>00</b><i>:</i><b>00</b>
		</span>
	</p>
</section>
