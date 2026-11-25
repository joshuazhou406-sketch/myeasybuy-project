(function () {
	'use strict';

	function byId(id) {
		return document.getElementById(id);
	}

	function getCheckoutWrap() {
		return document.querySelector('.myeasybuy-turnstile--checkout');
	}

	function renderWidgets(scope) {
		if (!window.turnstile || typeof window.turnstile.render !== 'function') {
			return;
		}
		var root = scope || document;
		root.querySelectorAll('.myeasybuy-turnstile:not([hidden]) .cf-turnstile').forEach(function (el) {
			if (el.getAttribute('data-meb-rendered') === '1') {
				return;
			}
			try {
				window.turnstile.render(el);
				el.setAttribute('data-meb-rendered', '1');
			} catch (e) {
				// Widget may already have been auto-rendered by api.js.
			}
		});
	}

	function resetCheckoutWidget() {
		var wrap = getCheckoutWrap();
		if (!wrap || !window.turnstile || typeof window.turnstile.reset !== 'function') {
			return;
		}
		var el = wrap.querySelector('.cf-turnstile');
		if (el) {
			try {
				window.turnstile.reset(el);
			} catch (e) {
				// Ignore reset errors on a fresh widget.
			}
		}
	}

	function syncCheckoutWidget() {
		var wrap = getCheckoutWrap();
		if (!wrap) {
			return;
		}
		if (wrap.getAttribute('data-meb-turnstile-required') === '1') {
			wrap.hidden = false;
			renderWidgets(wrap);
			return;
		}
		var box = byId('createaccount');
		var show = !!(box && box.checked);
		wrap.hidden = !show;
		if (show) {
			renderWidgets(wrap);
		} else {
			resetCheckoutWidget();
		}
	}

	document.addEventListener('change', function (event) {
		if (event.target && event.target.id === 'createaccount') {
			syncCheckoutWidget();
		}
	});

	document.addEventListener('DOMContentLoaded', function () {
		syncCheckoutWidget();
		renderWidgets(document);
	});

	window.addEventListener('load', function () {
		syncCheckoutWidget();
		renderWidgets(document);
	});
})();
