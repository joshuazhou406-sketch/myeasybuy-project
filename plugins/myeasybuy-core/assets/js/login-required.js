/**
 * Guests: send add-to-cart / cart / checkout actions to the login screen.
 * Loaded only for logged-out visitors; the server enforces the same rule.
 */
( function () {
	'use strict';

	var config = window.myeasybuyLoginRequired;
	if ( ! config || ! config.loginUrl ) {
		return;
	}

	var ACTION_SELECTOR = [
		'.add_to_cart_button',
		'.ajax_add_to_cart',
		'.single_add_to_cart_button',
		'.meb-pdp__btn--cart',
		'.meb-pdp__btn--buy',
		'a[href*="add-to-cart="]',
		'.wc-block-components-product-button__button',
		'.ppw-jd-detail-btn--cart',
		'.checkout-button',
		'.wc-block-cart__submit-button',
	].join( ',' );

	function loginUrlFor( returnUrl ) {
		var url = new URL( config.loginUrl, window.location.href );
		url.searchParams.set( config.redirectArg, returnUrl );
		return url.toString();
	}

	function goToLogin( event, returnUrl ) {
		event.preventDefault();
		event.stopImmediatePropagation();
		window.location.href = loginUrlFor( returnUrl || window.location.href );
	}

	window.addEventListener(
		'click',
		function ( event ) {
			var target = event.target instanceof Element ? event.target.closest( ACTION_SELECTOR ) : null;
			if ( ! target ) {
				return;
			}

			var href = target.tagName === 'A' ? target.getAttribute( 'href' ) : '';
			var returnUrl = href && href.indexOf( 'add-to-cart=' ) !== -1
				? new URL( href, window.location.href ).toString()
				: window.location.href;

			goToLogin( event, returnUrl );
		},
		true
	);

	window.addEventListener(
		'submit',
		function ( event ) {
			if ( event.target instanceof Element && event.target.matches( 'form.cart' ) ) {
				goToLogin( event );
			}
		},
		true
	);
} )();
