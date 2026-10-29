(function () {
	'use strict';

	function pad(n) {
		return n < 10 ? '0' + n : String(n);
	}

	function initCountdown() {
		var root = document.querySelector('[data-meb-offer-countdown]');
		if (!root) {
			return;
		}
		var parts = root.querySelectorAll('b');
		if (parts.length < 3) {
			return;
		}

		function tick() {
			var now = Date.now();
			var cn = now + 8 * 3600000;
			var left = Math.floor((86400000 - (cn % 86400000)) / 1000);
			parts[0].textContent = pad(Math.floor(left / 3600));
			parts[1].textContent = pad(Math.floor((left % 3600) / 60));
			parts[2].textContent = pad(left % 60);
		}

		tick();
		window.setInterval(tick, 1000);
	}

	function initPasswordToggles() {
		document.querySelectorAll('[data-meb-toggle-pass]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var input = document.getElementById(btn.getAttribute('data-meb-toggle-pass') || '');
				if (!input) {
					return;
				}
				var hidden = input.type === 'password';
				input.type = hidden ? 'text' : 'password';
				btn.setAttribute('aria-label', hidden ? 'Hide password' : 'Show password');
			});
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		initCountdown();
		initPasswordToggles();
	});
})();
