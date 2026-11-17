(function () {
	'use strict';

	function initClock() {
		var dateEl = document.querySelector('[data-meb-clock-date]');
		var timeEl = document.querySelector('[data-meb-clock-time]');
		if (!dateEl || !timeEl) {
			return;
		}

		function pad(n) {
			return n < 10 ? '0' + n : String(n);
		}

		function tick() {
			var now = new Date();
			var cn = new Date(now.getTime() + now.getTimezoneOffset() * 60000 + 8 * 3600000);
			dateEl.textContent = pad(cn.getMonth() + 1) + '/' + pad(cn.getDate());
			timeEl.textContent = pad(cn.getHours()) + ':' + pad(cn.getMinutes()) + ':' + pad(cn.getSeconds());
		}

		tick();
		window.setInterval(tick, 1000);
	}

	function initSearchFocus() {
		var input = document.querySelector('.meb-search__input');
		if (!input) {
			return;
		}

		document.querySelectorAll('[data-meb-focus-search]').forEach(function (link) {
			link.addEventListener('click', function (e) {
				e.preventDefault();
				window.scrollTo({ top: 0, behavior: 'smooth' });
				input.focus();
			});
		});
	}

	// Off the homepage the badges only pick the platform passed to the homepage search.
	function initPlatformPicker() {
		var field = document.querySelector('[data-meb-platform-input]');
		if (!field) {
			return;
		}

		var badges = document.querySelectorAll('[data-meb-platform]');
		badges.forEach(function (badge) {
			badge.addEventListener('click', function () {
				var platform = badge.getAttribute('data-meb-platform');
				field.value = platform;
				badges.forEach(function (b) {
					var active = b === badge;
					b.classList.toggle('is-active', active);
					b.setAttribute('aria-selected', active ? 'true' : 'false');
				});
			});
		});
	}

	function initBackToTop() {
		var btn = document.querySelector('[data-meb-top]');
		if (btn) {
			btn.addEventListener('click', function () {
				window.scrollTo({ top: 0, behavior: 'smooth' });
			});
		}
	}

	function initSignup() {
		var bar = document.querySelector('[data-meb-signup]');
		if (!bar) {
			return;
		}

		var key = 'mebSignupDismissed';
		try {
			if (window.localStorage.getItem(key)) {
				return;
			}
		} catch (err) {}

		bar.hidden = false;
		document.body.style.paddingBottom = bar.offsetHeight + 'px';

		bar.querySelector('[data-meb-signup-close]').addEventListener('click', function () {
			bar.hidden = true;
			document.body.style.paddingBottom = '';
			try {
				window.localStorage.setItem(key, '1');
			} catch (err) {}
		});
	}

	function initAccountMenu() {
		var menu = document.querySelector('[data-meb-account-menu]');
		if (!menu) {
			return;
		}

		var button = menu.querySelector('[data-meb-account-toggle]');
		if (!button) {
			return;
		}

		function setOpen(open) {
			menu.classList.toggle('is-open', open);
			button.setAttribute('aria-expanded', open ? 'true' : 'false');
		}

		menu.addEventListener('mouseenter', function () {
			setOpen(true);
		});
		menu.addEventListener('mouseleave', function () {
			setOpen(false);
		});
		button.addEventListener('click', function (event) {
			var canHover = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
			if (canHover || button.getAttribute('aria-expanded') === 'true') {
				return;
			}
			event.preventDefault();
			setOpen(true);
		});
		document.addEventListener('click', function (event) {
			if (!menu.contains(event.target)) {
				setOpen(false);
			}
		});
		document.addEventListener('keydown', function (event) {
			if (event.key === 'Escape') {
				setOpen(false);
			}
		});
	}

	function placeLanguageSwitcher() {
		var actions = document.querySelector('.meb-header__actions');
		var switcher = document.getElementById('wtranslator-switcher');
		if (!actions || !switcher || switcher.parentElement === actions) {
			return !!switcher;
		}

		var account = actions.querySelector(':scope > a.meb-btn, :scope > .meb-account-menu');
		if (account) {
			actions.insertBefore(switcher, account);
		} else {
			actions.appendChild(switcher);
		}

		switcher.classList.remove(
			'wtranslator-top-right',
			'wtranslator-top-left',
			'wtranslator-bottom-right',
			'wtranslator-bottom-left'
		);

		var dropdown = switcher.querySelector('.wtranslator-dropdown');
		if (dropdown) {
			dropdown.classList.remove('open-up');
			dropdown.classList.add('open-down');
		}

		return true;
	}

	document.addEventListener('DOMContentLoaded', function () {
		initClock();
		initSearchFocus();
		initPlatformPicker();
		initBackToTop();
		initSignup();
		initAccountMenu();
		if (!placeLanguageSwitcher()) {
			var observer = new MutationObserver(function () {
				if (placeLanguageSwitcher()) {
					observer.disconnect();
				}
			});
			observer.observe(document.body, { childList: true, subtree: true });
		}
	});
})();
