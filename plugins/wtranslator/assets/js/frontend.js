/**
 * Frontend JavaScript - Language Switcher & Full Page Translation (WTranslator Style)
 *
 * @package WTranslator
 */

// phpcs:disable Squiz.Commenting.InlineComment.InvalidEndChar,Squiz.PHP.DisallowSizeFunctionsInLoops.Found

(function ($) {
	'use strict';

	// WTranslator-style full page translation using Google Translate Element API
	(function () {
		var default_language = (typeof wtranslatorData !== 'undefined' && wtranslatorData.defaultLang) ? wtranslatorData.defaultLang : 'en';
		var current_lang     = default_language;

		// RTL languages list (WTranslator list)
		var rtl_languages = ['ar', 'he', 'fa', 'fa-AF', 'ur', 'yi', 'iw', 'ps', 'sd', 'ckb', 'pa-Arab', 'ms-Arab', 'ug', 'dv', 'bal'];

		// Function to check if language is RTL (WTranslator style)
		function isRTL(lang) {
			return rtl_languages.indexOf( lang ) !== -1;
		}

		// Function to get current language from googtrans cookie (WTranslator style)
		function get_googtrans_lang() {
			var keyValue = document.cookie.match( '(^|;) ?googtrans=([^;]*)(;|$)' );
			return keyValue ? keyValue[2].split( '/' )[2] : null;
		}

		// Function to clear googtrans cookie robustly (WTranslator style)
		function clearGoogtransCookie() {
			var domain       = window.location.hostname;
			var domain_parts = domain.split( '.' );
			var main_domain  = domain_parts.length > 1 ? domain_parts.slice( -2 ).join( '.' ) : domain;

			var domains = [domain, '.' + domain, main_domain, '.' + main_domain];
			var paths   = ['/', '/wp-admin', '/wp-content'];

			// Add current path and all its parent directories to paths list
			var current_path = window.location.pathname;
			if (current_path && current_path !== '/') {
				var path_parts       = current_path.split( '/' );
				var accumulated_path = '';
				for (var i = 0; i < path_parts.length; i++) {
					if (path_parts[i]) {
						accumulated_path += '/' + path_parts[i];
						paths.push( accumulated_path );
						paths.push( accumulated_path + '/' );
					}
				}
			}

			// Remove duplicates from paths
			paths = paths.filter(
				function (item, pos) {
					return paths.indexOf( item ) == pos;
				}
			);

			domains.forEach(
				function (d) {
					paths.forEach(
						function (p) {
							document.cookie = 'googtrans=; expires=Thu, 01 Jan 1970 00:00:01 GMT; path=' + p + ';';
							document.cookie = 'googtrans=; expires=Thu, 01 Jan 1970 00:00:01 GMT; path=' + p + '; domain=' + d + ';';
						}
					);
				}
			);

			// Final safeguard for root path
			document.cookie = 'googtrans=; expires=Thu, 01 Jan 1970 00:00:01 GMT; path=/';
		}

		// Function to apply RTL to page (WTranslator logic) - Enhanced
		function applyRTL() {
			// Get language from googtrans cookie (WTranslator style)
			var googtrans_lang = get_googtrans_lang();
			var lang_to_check  = googtrans_lang || current_lang;

			if (isRTL( lang_to_check )) {
				// Apply RTL (WTranslator style) - Comprehensive
				document.documentElement.setAttribute( 'dir', 'rtl' );
				document.documentElement.setAttribute( 'lang', lang_to_check );
				document.documentElement.style.direction = 'rtl';

				if (document.body) {
					document.body.setAttribute( 'dir', 'rtl' );
					document.body.style.direction = 'rtl';
					document.body.style.textAlign = 'right';
				}

				// Add RTL class to body and html (WTranslator style)
				document.documentElement.classList.add( 'rtl' );
				document.documentElement.classList.remove( 'ltr' );
				if (document.body) {
					document.body.classList.add( 'rtl' );
					document.body.classList.remove( 'ltr' );
				}

				// Apply RTL to main content areas
				var mainElements = document.querySelectorAll( 'main, article, section, .content, .main-content, #content, #main' );
				for (var i = 0; i < mainElements.length; i++) {
					mainElements[i].setAttribute( 'dir', 'rtl' );
					mainElements[i].style.direction = 'rtl';
					mainElements[i].style.textAlign = 'right';
				}

				// Apply RTL to navigation menus
				var navElements = document.querySelectorAll( 'nav, .menu, ul.menu, #menu, .et-menu-nav, #et-top-navigation' );
				for (var j = 0; j < navElements.length; j++) {
					navElements[j].setAttribute( 'dir', 'rtl' );
					navElements[j].style.direction = 'rtl';
					navElements[j].style.textAlign = 'right';
				}
			} else {
				// Apply LTR (WTranslator style) - Comprehensive
				document.documentElement.setAttribute( 'dir', 'ltr' );
				document.documentElement.setAttribute( 'lang', lang_to_check || default_language );
				document.documentElement.style.direction = 'ltr';

				if (document.body) {
					document.body.setAttribute( 'dir', 'ltr' );
					document.body.style.direction = 'ltr';
					document.body.style.textAlign = 'left';
				}

				// Add LTR class to body and html (WTranslator style)
				document.documentElement.classList.add( 'ltr' );
				document.documentElement.classList.remove( 'rtl' );
				if (document.body) {
					document.body.classList.add( 'ltr' );
					document.body.classList.remove( 'rtl' );
				}

				// Apply LTR to main content areas
				var mainElements = document.querySelectorAll( 'main, article, section, .content, .main-content, #content, #main' );
				for (var i = 0; i < mainElements.length; i++) {
					mainElements[i].setAttribute( 'dir', 'ltr' );
					mainElements[i].style.direction = 'ltr';
					mainElements[i].style.textAlign = 'left';
				}

				// Apply LTR to navigation menus
				var navElements = document.querySelectorAll( 'nav, .menu, ul.menu, #menu, .et-menu-nav, #et-top-navigation' );
				for (var j = 0; j < navElements.length; j++) {
					navElements[j].setAttribute( 'dir', 'ltr' );
					navElements[j].style.direction = 'ltr';
					navElements[j].style.textAlign = 'left';
				}
			}
		}

		// Watch for googtrans cookie changes (WTranslator style)
		function languageCodesMatch(a, b) {
			if ( ! a || ! b) {
				return false;
			}
			return normalize_language_code( a ) === normalize_language_code( b );
		}

		function resolveUiLanguage() {
			var gt = get_googtrans_lang();
			if (gt) {
				return normalize_language_code( gt );
			}
			return normalize_language_code( current_lang || default_language );
		}

		function syncLanguageSwitcher(lang) {
			lang = normalize_language_code( lang || resolveUiLanguage() );
			if ( ! lang || typeof window.jQuery === 'undefined') {
				return;
			}
			var $ = window.jQuery;
			var $root = $( '.wtranslator-switcher, .wtranslator-menu-switcher' );
			if ( ! $root.length) {
				return;
			}

			var $items = $root.find( '.wtranslator-lang-item, .wtranslator-flag-item, .wtranslator-flag-name-item' );
			$items.removeClass( 'active current' );

			var $match = $items.filter(
				function () {
					return languageCodesMatch( $( this ).attr( 'data-lang' ), lang );
				}
			);
			if ( ! $match.length) {
				return;
			}

			$match.addClass( 'active current' );
			$match.each(
				function () {
					var $item = $( this );
					var $scope = $item.closest( '.wtranslator-dropdown, .wtranslator-menu-dropdown, .wtranslator-flags, .wtranslator-flags-name, .wtranslator-menu-switcher, .wtranslator-switcher' );
					var flag = $item.find( '.wtranslator-flag' ).html();
					var name = $item.find( '.wtranslator-lang-name' ).html();
					if (flag) {
						$scope.find( '.wtranslator-dropdown-toggle .wtranslator-flag, .wtranslator-menu-toggle .wtranslator-flag' ).html( flag );
					}
					if (name) {
						$scope.find( '.wtranslator-dropdown-toggle .wtranslator-lang-name, .wtranslator-menu-toggle .wtranslator-lang-name' ).html( name );
					}
				}
			);
		}

		function watchGoogtransCookie() {
			var last_googtrans = get_googtrans_lang();

			setInterval(
				function () {
					var current_googtrans = get_googtrans_lang();
					if (current_googtrans !== last_googtrans) {
						last_googtrans = current_googtrans;
						applyRTL(); // Re-apply RTL when cookie changes
						syncLanguageSwitcher( current_googtrans || current_lang );
					}
				},
				100
			);
		}

		// Start watching googtrans cookie (WTranslator style)
		watchGoogtransCookie();
		if (typeof window.jQuery !== 'undefined') {
			window.jQuery( function () {
				syncLanguageSwitcher();
			} );
		} else if (document.readyState === 'loading') {
			document.addEventListener( 'DOMContentLoaded', function () {
				syncLanguageSwitcher();
			} );
		} else {
			syncLanguageSwitcher();
		}

		// Language is stored in cookie only (no ?lang= in URL).

		// Free plugin: never let Google Translate Element rewrite <title> or SEO description.
		var originalDocumentTitle = (typeof document !== 'undefined' && document.title) ? document.title : '';
		var originalMetaContents = [];
		var restoringSeoTags = false;

		function seoMetaNodes() {
			if (typeof document === 'undefined') {
				return [];
			}
			return document.querySelectorAll(
				'meta[name="description"], meta[property="og:title"], meta[property="og:description"], meta[name="twitter:title"], meta[name="twitter:description"]'
			);
		}

		function snapshotSeoTags() {
			if (typeof document === 'undefined') {
				return;
			}
			if (document.title) {
				originalDocumentTitle = document.title;
			}
			originalMetaContents = [];
			var metas = seoMetaNodes();
			for (var i = 0; i < metas.length; i++) {
				originalMetaContents.push({
					el: metas[i],
					content: metas[i].getAttribute( 'content' ) || ''
				});
			}
		}

		function markSeoTagsNoTranslate() {
			if (typeof document === 'undefined') {
				return;
			}
			var titleEl = document.querySelector( 'title' );
			if (titleEl) {
				titleEl.classList.add( 'notranslate' );
				titleEl.setAttribute( 'translate', 'no' );
			}
			var metas = seoMetaNodes();
			for (var i = 0; i < metas.length; i++) {
				metas[i].classList.add( 'notranslate' );
				metas[i].setAttribute( 'translate', 'no' );
			}
		}

		function restoreSeoTags() {
			if (typeof document === 'undefined' || restoringSeoTags) {
				return;
			}
			restoringSeoTags = true;
			if (originalDocumentTitle && document.title !== originalDocumentTitle) {
				document.title = originalDocumentTitle;
			}
			var titleEl = document.querySelector( 'title' );
			if (titleEl && originalDocumentTitle && titleEl.textContent !== originalDocumentTitle) {
				titleEl.textContent = originalDocumentTitle;
			}
			for (var i = 0; i < originalMetaContents.length; i++) {
				var item = originalMetaContents[i];
				if (item.el && item.el.getAttribute( 'content' ) !== item.content) {
					item.el.setAttribute( 'content', item.content );
				}
			}
			restoringSeoTags = false;
		}

		function protectSeoTags() {
			snapshotSeoTags();
			markSeoTagsNoTranslate();
			restoreSeoTags();
		}

		// Normalize language code for Google Translate Element API
		// Google Translate API requires: zh-CN (not zh-cn), zh-TW (not zh-tw), etc.
		function normalize_language_code(lang_code) {
			if ( ! lang_code) {
				return lang_code;
			}

			// Convert to lowercase first
			lang_code = lang_code.toLowerCase();

			// Handle Chinese language codes specifically
			// Google Translate Element API uses: zh-CN (Simplified) and zh-TW (Traditional)
			if (lang_code.indexOf( 'zh' ) === 0) {
				// Handle variations: zh-cn, zh_cn, zhcn → zh-CN
				if (lang_code.indexOf( 'cn' ) !== -1 || lang_code === 'zh') {
					return 'zh-CN'; // Simplified Chinese
				}
				// Handle variations: zh-tw, zh_tw, zhtw → zh-TW
				if (lang_code.indexOf( 'tw' ) !== -1) {
					return 'zh-TW'; // Traditional Chinese
				}
			}

			// For other language codes, capitalize region code if present
			// Format: en-US, es-ES, etc.
			if (lang_code.indexOf( '-' ) !== -1 || lang_code.indexOf( '_' ) !== -1) {
				var parts = lang_code.split( /[-_]/ );
				if (parts.length === 2) {
					var rest = parts[1];
					if (rest.length <= 3) {
						return parts[0].toLowerCase() + '-' + rest.toUpperCase();
					}
					return parts[0].toLowerCase() + '-' + rest.charAt( 0 ).toUpperCase() + rest.slice( 1 ).toLowerCase();
				}
			}

			// Return lowercase for simple language codes (en, es, fr, etc.)
			return lang_code.toLowerCase();
		}

		// Get current language from cookie (wtranslator_lang cookie, then googtrans)
		if (typeof document !== 'undefined') {
			var cookies     = document.cookie.split( ';' );
			var cookie_lang = null;
			for (var i = 0; i < cookies.length; i++) {
				var cookie = cookies[i].trim();
				if (cookie.indexOf( 'wtranslator_lang=' ) === 0) {
					cookie_lang  = normalize_language_code( cookie.substring( 'wtranslator_lang='.length ) );
					current_lang = cookie_lang;
					break;
				}
			}

			// If our cookie is default language or not set, check googtrans cookie
			// But only if user hasn't explicitly set wtranslator_lang to default
			if (current_lang === default_language) {
				var googtrans_matches = document.cookie.match( '(^|;) ?googtrans=([^;]*)(;|$)' );
				var googtrans_lang    = googtrans_matches && googtrans_matches[2].split( '/' )[2];

				// If googtrans cookie exists but user wants default language, clear it
				if (googtrans_lang && googtrans_lang !== default_language) {
					// Clear googtrans cookie robustly to prevent auto-translation
					clearGoogtransCookie();
				}
			}
			// Auto-detect browser language if enabled and no selection made
			var auto_detect       = (typeof wtranslatorData !== 'undefined' && wtranslatorData.autoDetect) ? wtranslatorData.autoDetect : false;
			var enabled_languages = (typeof wtranslatorData !== 'undefined' && wtranslatorData.enabledLanguages) ? wtranslatorData.enabledLanguages : [];

			if (auto_detect && current_lang === default_language && ! cookie_lang) {
				// Use server-side detection (Cloudflare header when available, else browser Accept-Language)
				jQuery.post(
					wtranslatorData.ajaxUrl,
					{
						action: 'wtranslator_detect_ip',
						visitor_ip: '',
						_ajax_nonce: wtranslatorData.nonce
					},
					function (response) {
						if (response.success && response.data.lang) {
							var detected_lang = response.data.lang;
							var is_default    = response.data.is_default;

							if ( ! is_default && enabled_languages.indexOf( detected_lang ) !== -1) {
								// Store language in cookie only (no ?lang= in URL).
								document.cookie = 'wtranslator_lang=' + detected_lang + '; path=/; max-age=' + (365 * 24 * 60 * 60);
								window.location.reload();
							}
						}
					}
				);
			}
		}

		// Create hidden translate element (WTranslator style)
		if (typeof document !== 'undefined' && ! document.getElementById( 'google_translate_element2' )) {
			var widget_code    = '<div id="google_translate_element2"></div>';
			var add_code       = document.createElement( 'div' );
			add_code.innerHTML = widget_code;
			document.body.appendChild( add_code );
		}

		// Add CSS to hide Google Translate UI. Do not make <head> visible —
		// that is what caused Google Translate to rewrite <title> / meta description.
		if (typeof document !== 'undefined' && ! document.querySelector( '.wtranslator_css' )) {
			var widget_css =
				'#google_translate_element2, div.skiptranslate, iframe.skiptranslate, iframe.goog-te-banner-frame, .goog-te-banner-frame, .goog-te-balloon-frame, #goog-gt-tt, .goog-tooltip, .VIpgJd-ZVi9od-ORHb-OEVmcd, .VIpgJd-ZVi9od-aZ2wEe-wOHMyf, .VIpgJd-ZVi9od-ORHb, .VIpgJd-ZVi9od-l4eHX-hSRGPd { display: none !important; visibility: hidden !important; opacity: 0 !important; pointer-events: none !important; height: 0 !important; max-height: 0 !important; width: 0 !important; border: none !important; } ' +
				'body { top: 0 !important; } ' +
				'font font { background-color: transparent !important; box-shadow: none !important; position: initial !important; }';

			var add_css = document.createElement( 'style' );
			add_css.classList.add( 'wtranslator_css' );
			add_css.textContent = widget_css;
			document.head.appendChild( add_css );
		}

		function hideGoogleTranslateFrame() {
			var nodes = document.querySelectorAll( 'iframe.skiptranslate, iframe.goog-te-banner-frame, .goog-te-banner-frame, .goog-te-balloon-frame, #goog-gt-tt, .VIpgJd-ZVi9od-ORHb-OEVmcd, .VIpgJd-ZVi9od-aZ2wEe-wOHMyf, .VIpgJd-ZVi9od-ORHb, .VIpgJd-ZVi9od-l4eHX-hSRGPd' );
			for ( var i = 0; i < nodes.length; i++ ) {
				var el = nodes[i];
				if ( el.id === 'google_translate_element2' || ( el.closest && el.closest( '#google_translate_element2' ) ) ) {
					continue;
				}
				el.style.setProperty( 'display', 'none', 'important' );
				el.style.setProperty( 'visibility', 'hidden', 'important' );
				el.style.setProperty( 'opacity', '0', 'important' );
				el.style.setProperty( 'pointer-events', 'none', 'important' );
			}
			if ( document.body ) {
				document.body.style.setProperty( 'top', '0px', 'important' );
			}
		}

		function watchGoogleTranslateFrame() {
			hideGoogleTranslateFrame();
			if ( window.__wtranslatorGtFrameWatch || typeof MutationObserver === 'undefined' || ! document.documentElement ) {
				return;
			}
			window.__wtranslatorGtFrameWatch = true;
			new MutationObserver( hideGoogleTranslateFrame ).observe( document.documentElement, { childList: true, subtree: true } );
		}

		watchGoogleTranslateFrame();

		if (typeof document !== 'undefined') {
			protectSeoTags();
			if (window.MutationObserver && document.head) {
				new MutationObserver( function () {
					markSeoTagsNoTranslate();
					restoreSeoTags();
				} ).observe( document.head, {
					subtree: true,
					childList: true,
					characterData: true,
					attributes: true,
					attributeFilter: [ 'content' ]
				} );
			}
			var seoRestoreTicks = 0;
			var seoRestoreTimer = setInterval( function () {
				restoreSeoTags();
				seoRestoreTicks += 1;
				if (seoRestoreTicks > 40) {
					clearInterval( seoRestoreTimer );
				}
			}, 500 );
		}

		// WTranslator functions (exact copy)
		function get_current_lang() {
			var keyValue = document.cookie.match( '(^|;) ?googtrans=([^;]*)(;|$)' );
			return keyValue ? keyValue[2].split( '/' )[2] : null;
		}

		function fire_event(element, event) {
			try {
				if (document.createEventObject) {
					var evt = document.createEventObject();
					element.fireEvent( 'on' + event, evt );
				} else {
					var evt = document.createEvent( 'HTMLEvents' );
					evt.initEvent( event, true, true );
					element.dispatchEvent( evt );
				}
			} catch (e) {
			}
		}

		// WTranslator load_tlib function - optimized for fast loading
		function load_tlib() {
			if ( ! window.wtranslatorGtScript) {
				window.wtranslatorGtScript       = document.createElement( 'script' );
				window.wtranslatorGtScript.src   = 'https://translate.google.com/translate_a/element.js?cb=wtranslatorInit';
				window.wtranslatorGtScript.async = false; // Load immediately for faster translation (WTranslator style)
				// Use head for faster loading
				if (document.head) {
					document.head.appendChild( window.wtranslatorGtScript );
				} else {
					document.body.appendChild( window.wtranslatorGtScript );
				}
			} else if (window.wtranslatorGtScript && ! window.wtranslatorGtScript.onload && window.google && window.google.translate) {
				// Script already loaded, initialize if not done
				if ( ! document.getElementById( 'google_translate_element2' ).innerHTML) {
					window.wtranslatorInit();
				}
			}
		}

		// Track last translation to prevent duplicate calls
		var lastTranslation = null;
		var isTranslating   = false;

		// WTranslator wtranslatorDoTranslate function
		window.wtranslatorDoTranslate = function (lang_pair) {
			if (lang_pair.value) {
				lang_pair = lang_pair.value;
			}
			if (lang_pair == '') {
				return;
			}

			// Prevent duplicate translation calls
			if (lastTranslation === lang_pair && isTranslating) {
				return; // Already translating this language
			}

			var lang = lang_pair.split( '|' )[1];
			if (get_current_lang() == null && lang == lang_pair.split( '|' )[0]) {
				return;
			}

			var teCombo;
			var sel = document.getElementsByTagName( 'select' );
			for (var i = 0; i < sel.length; i++) {
				if (sel[i].className.indexOf( 'goog-te-combo' ) != -1) {
					teCombo = sel[i];
					break;
				}
			}
			// WTranslator logic: retry if not ready, otherwise translate immediately
			// Very fast retry for smooth translation (WTranslator style)
			if (document.getElementById( 'google_translate_element2' ) == null ||
				document.getElementById( 'google_translate_element2' ).innerHTML.length == 0 ||
				! teCombo ||
				teCombo.length == 0 ||
				teCombo.innerHTML.length == 0) {
				setTimeout(
					function () {
						wtranslatorDoTranslate( lang_pair ); },
					50
				); // Very fast retry - WTranslator style
			} else {
				// Mark as translating
				isTranslating   = true;
				lastTranslation = lang_pair;

				// Normalize language code for Google Translate Element API
				var normalized_lang = normalize_language_code( lang );

				// Only translate if language is different
				if (teCombo.value !== normalized_lang) {
					teCombo.value = normalized_lang;
					fire_event( teCombo, 'change' );
					fire_event( teCombo, 'change' );
					hideGoogleTranslateFrame();

					// Apply RTL after translation (WTranslator style)
					setTimeout(
						function () {
							applyRTL();
							restoreSeoTags();
						},
						200
					);

					// Don't show page here - wait for translation to complete
					// Page will be shown by showPageWhenTranslated() function
					// This ensures page is only shown AFTER translation is complete
				}

				// Reset flag after translation
				setTimeout(
					function () {
						isTranslating = false;
					},
					1000
				);
			}
		};

		// WTranslator googleTranslateElementInit2 function
		window.wtranslatorInit = function () {
			// Initialize Google Translate with auto-display disabled (WTranslator style)
			new google.translate.TranslateElement(
				{
					pageLanguage: default_language,
					autoDisplay: false
				},
				'google_translate_element2'
			);

			// Apply RTL after Google Translate initializes (WTranslator style)
			setTimeout(
				function () {
					applyRTL();
				},
				100
			);

			// If current language is not default, trigger translation immediately (WTranslator style)
			if (current_lang && current_lang !== default_language) {
				setTimeout(
					function () {
						// Normalize language code before passing to Google Translate Element API
						var normalized_lang = normalize_language_code( current_lang );
						// Trigger translation after Google Translate initializes
						wtranslatorDoTranslate( default_language + '|' + normalized_lang );
					},
					200
				);
			}

			// Watch for Google Translate language changes and apply RTL (WTranslator style)
			var teCombo = document.querySelector( '.goog-te-combo' );
			if (teCombo) {
				teCombo.addEventListener(
					'change',
					function () {
						setTimeout(
							function () {
								applyRTL();
								restoreSeoTags();
								// Don't show page here - wait for translation to complete
								// Page will be shown by showPageWhenTranslated() function
							},
							100
						);
					}
				);
			}

			setTimeout(
				function () {
					restoreSeoTags();
				},
				500
			);

			// Don't show page immediately after Google Translate initializes
			// Wait for translation to complete - page will be shown by showPageWhenTranslated()

			// Additional safeguard: Prevent any automatic translation for default language
			if ( ! userSelectedLanguage && current_lang === default_language) {
				setTimeout(
					function () {
						var teCombo = document.querySelector( '.goog-te-combo' );
						if (teCombo && teCombo.value !== default_language) {
							// Auto-translation detected, reset to default
							teCombo.value = default_language;
							fire_event( teCombo, 'change' );
							hideGoogleTranslateFrame();
							// Clear any translation cookies
							document.cookie = 'googtrans=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT';
							document.cookie = 'googtrans=; path=/; domain=' + window.location.hostname + '; expires=Thu, 01 Jan 1970 00:00:00 GMT';
							applyRTL(); // Re-apply direction
						}
					},
					1000
				);
			}
		};

		// Prevent browser language auto-switch if our auto-detect is disabled (WTranslator style)
		if ( ! auto_detect && typeof localStorage !== 'undefined') {
			// Set flag to prevent auto-switch (WTranslator style)
			localStorage.setItem( 'gt_autoswitch', '1' );
		}

		// Prevent any automatic language switching
		// Only translate when user explicitly selects a language
		var userSelectedLanguage = false;

		// WTranslator Style: Load script immediately if language is not default
		// This ensures smooth and fast translation
		if (current_lang && current_lang != default_language) {
			// User has selected a language - load Google Translate immediately
			userSelectedLanguage = true;

			// Apply RTL if needed (WTranslator style)
			applyRTL();

			// Load Google Translate library first
			load_tlib();

			// Wait for Google Translate to initialize, then trigger translation
			// WTranslator style: Wait for element to be ready
			function triggerTranslation() {
				var teCombo          = document.querySelector( '.goog-te-combo' );
				var translateElement = document.getElementById( 'google_translate_element2' );

				// Check if Google Translate is ready
				if (translateElement && translateElement.innerHTML.length > 0 && teCombo && teCombo.length > 0) {
					// Normalize language code before passing to Google Translate Element API
					var normalized_lang = normalize_language_code( current_lang );
					// Google Translate is ready - trigger translation
					wtranslatorDoTranslate( default_language + '|' + normalized_lang );
				} else {
					// Not ready yet, retry after short delay (WTranslator style)
					setTimeout( triggerTranslation, 100 );
				}
			}

			// Start translation trigger after Google Translate loads
			setTimeout( triggerTranslation, 200 );

			// Additional retry mechanism (WTranslator style - ensure translation happens)
			setTimeout(
				function () {
					var teCombo = document.querySelector( '.goog-te-combo' );
					if ( ! teCombo || teCombo.value !== current_lang) {
						// Translation didn't start, retry
						triggerTranslation();
					}
				},
				500
			);

			// Show page function - called after translation completes
			function showPageNow() {
				// Remove hide style if exists
				var hideStyleEl = document.getElementById( 'wtranslator-prevent-flash' );
				if (hideStyleEl) {
					hideStyleEl.remove();
				}
				// Show page - multiple methods to ensure it works
				if (document.documentElement) {
					document.documentElement.style.visibility = 'visible';
					document.documentElement.style.opacity    = '1';
				}
				if (document.body) {
					document.body.style.visibility = 'visible';
					document.body.style.opacity    = '1';
				}
				// Force show with CSS override
				var showStyle = document.getElementById( 'wtranslator-show-page' );
				if ( ! showStyle) {
					showStyle             = document.createElement( 'style' );
					showStyle.id          = 'wtranslator-show-page';
					showStyle.textContent = 'html, html body { visibility: visible !important; opacity: 1 !important; }';
					document.head.appendChild( showStyle );
				}
				// Also call global function if exists
				if (typeof window.wtranslatorShowPage === 'function') {
					window.wtranslatorShowPage();
				}
			}

			// Track if page has been shown
			var pageShown          = false;
			var translationStarted = false;

			// Check if default language - show page immediately
			if (current_lang === default_language) {
				// Default language - show page immediately, no translation needed
				pageShown = true;
				showPageNow();
			} else {
				// Show page after translation completes - ensure translation happens
				function showPageWhenTranslated() {
					if (pageShown) {
						return; // Already shown
					}

					var teCombo = document.querySelector( '.goog-te-combo' );

					// Check if Google Translate has started translation
					if (teCombo && teCombo.value === current_lang && teCombo.value !== default_language) {
						if ( ! translationStarted) {
							translationStarted = true;
							// Translation started, wait for it to complete
							setTimeout(
								function () {
									if ( ! pageShown) {
										// Verify translation is actually happening
										var teComboCheck = document.querySelector( '.goog-te-combo' );
										if (teComboCheck && teComboCheck.value === current_lang) {
											// Translation confirmed, wait for it to complete
											setTimeout(
												function () {
													if ( ! pageShown) {
														pageShown = true;
														// Show page after translation completes
														showPageNow();
													}
												},
												800
											); // Wait 800ms for translation to complete
										} else {
											// Translation not confirmed, check again
											translationStarted = false;
											setTimeout( showPageWhenTranslated, 200 );
										}
									}
								},
								400
							); // Wait 400ms after translation starts
						}
					} else {
						var checkCount = arguments[0] || 0;
						if (checkCount < 40) { // Increased to ensure translation happens
							setTimeout(
								function () {
									showPageWhenTranslated( checkCount + 1 );
								},
								100
							); // Check every 100ms
						} else {
							if ( ! pageShown) {
								pageShown = true;
								showPageNow();
							}
						}
					}
				}

				// Start checking for translation completion
				setTimeout( showPageWhenTranslated, 500 ); // Wait for translation to start

				// Fallback: Show after 2.5 seconds max (ensure translation happens)
				setTimeout(
					function () {
						if ( ! pageShown) {
							pageShown = true;
							showPageNow();
						}
					},
					2500
				);
			} // Increased to 2.5 seconds to ensure translation completes

			// Page will load normally - no hiding
			// Translation will happen in background and apply on reload
			// No need to hide/show page - let it load normally
		} else {
			// Apply default direction
			applyRTL();
			// Default language - do NOT auto-translate
			// Clear any existing googtrans cookie robustly
			clearGoogtransCookie();

			// Only load script on hover/click of language switcher
			$( document ).ready(
				function () {
					$( '.wtranslator-switcher, .wtranslator-menu-switcher' ).on(
						'pointerenter',
						function () {
							load_tlib();
						}
					);
				}
			);
		}

		// Handle language switcher clicks (WTranslator style - exact copy)
		$( document ).ready(
			function () {
				// Use event delegation for dynamically added elements
				$( document ).on(
					'click',
					'.wtranslator-lang-item, .wtranslator-flag-item, .wtranslator-flag-name-item',
					function (e) {
						var $link = $( this );
						var lang  = $link.data( 'lang' );

						if ( ! lang) {
							return;
						}

						// Prevent default
						e.preventDefault();
						e.stopPropagation();

						// Mark that user has selected a language
						userSelectedLanguage = true;

						// Update current language
						current_lang = lang;
						syncLanguageSwitcher( lang );

						// Apply RTL if needed (WTranslator style) - apply immediately
						applyRTL();

						// Load translate library first (WTranslator style)
						load_tlib();

						// Set cookie
						document.cookie = 'wtranslator_lang=' + lang + '; path=/; max-age=' + (365 * 24 * 60 * 60);

						if (lang === default_language) {
							// Also clear googtrans cookie robustly
							clearGoogtransCookie();
						}
						// Reload without ever adding ?lang= (prevents SEO/indexing of language-query URLs).
						window.location.reload();
						return; // Exit early - page will reload
					}
				);
			}
		);
	})();

	// Language switcher UI functionality
	$( document ).ready(
		function () {

			// Dropdown toggle for floating widget
			$( '.wtranslator-dropdown-toggle' ).on(
				'click',
				function (e) {
					e.preventDefault();
					e.stopPropagation();

					var $dropdown = $( this ).closest( '.wtranslator-dropdown' );
					var isActive  = $dropdown.hasClass( 'active' );

					// Close all dropdowns
					$( '.wtranslator-dropdown' ).removeClass( 'active' );

					// Toggle current dropdown
					if ( ! isActive) {
						$dropdown.addClass( 'active' );
					}
				}
			);

			// Menu dropdown toggle (same functionality as floating widget)
			$( '.wtranslator-menu-toggle' ).on(
				'click',
				function (e) {
					e.preventDefault();
					e.stopPropagation();

					var $dropdown = $( this ).closest( '.wtranslator-menu-dropdown' );
					var isActive  = $dropdown.hasClass( 'active' );

					// Close all menu dropdowns
					$( '.wtranslator-menu-dropdown' ).removeClass( 'active' );

					// Toggle current dropdown
					if ( ! isActive) {
						$dropdown.addClass( 'active' );
					}
				}
			);

			// Close dropdown when clicking outside
			$( document ).on(
				'click',
				function (e) {
					if ( ! $( e.target ).closest( '.wtranslator-switcher' ).length &&
					! $( e.target ).closest( '.wtranslator-menu-dropdown' ).length) {
						$( '.wtranslator-dropdown' ).removeClass( 'active' );
						$( '.wtranslator-menu-dropdown' ).removeClass( 'active' );
					}
				}
			);

			// Do not append ?lang= to forms/AJAX. Language is cookie-driven to keep URLs clean for SEO.
		}
	);

	// Handle keyboard navigation for accessibility
	$( document ).on(
		'keydown',
		function (e) {
			// ESC to close dropdown
			if (e.key === 'Escape') {
				$( '.wtranslator-dropdown' ).removeClass( 'active' );
				$( '.wtranslator-menu-dropdown' ).removeClass( 'active' );
			}
		}
	);

})( jQuery );
