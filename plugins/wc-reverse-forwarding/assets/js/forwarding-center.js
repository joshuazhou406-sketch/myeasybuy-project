(function () {
	'use strict';

	var labels = window.wcRfForwardingCenter || {};

	function fallbackCopy(text) {
		var area = document.createElement('textarea');
		area.value = text;
		area.setAttribute('readonly', 'readonly');
		area.style.position = 'fixed';
		area.style.left = '-9999px';
		document.body.appendChild(area);
		area.select();

		var ok = false;
		try {
			ok = document.execCommand('copy');
		} catch (e) {
			ok = false;
		}

		document.body.removeChild(area);
		return ok;
	}

	function copyText(text) {
		if (navigator.clipboard && window.isSecureContext) {
			return navigator.clipboard.writeText(text).then(function () {
				return true;
			}).catch(function () {
				return fallbackCopy(text);
			});
		}

		return Promise.resolve(fallbackCopy(text));
	}

	function flashButton(button, ok) {
		var target = button.querySelector('[data-wc-rf-copy-label]') || button;
		var original = target.getAttribute('data-wc-rf-original') || target.textContent;
		target.setAttribute('data-wc-rf-original', original);
		target.textContent = ok ? (labels.copied || '已复制') : (labels.failed || '复制失败，请手动选择文本');
		button.classList.toggle('is-copied', ok);
		button.classList.toggle('is-failed', !ok);

		window.clearTimeout(button.wcRfTimer);
		button.wcRfTimer = window.setTimeout(function () {
			target.textContent = original;
			button.classList.remove('is-copied', 'is-failed');
		}, 1800);
	}

	document.addEventListener('click', function (event) {
		var button = event.target.closest('[data-wc-rf-copy]');
		if (!button) {
			return;
		}

		event.preventDefault();
		var text = button.getAttribute('data-wc-rf-copy') || '';
		if (!text) {
			return;
		}

		copyText(text).then(function (ok) {
			flashButton(button, !!ok);
		});
	});

	function selectedChecks() {
		return Array.prototype.slice.call(
			document.querySelectorAll('.wc-rf-package-check[data-selectable="1"]:checked')
		);
	}

	function updateSelectionBar() {
		var checks = selectedChecks();
		var weight = 0;
		checks.forEach(function (input) {
			weight += parseFloat(input.getAttribute('data-weight') || '0') || 0;
		});

		var countEl = document.querySelector('[data-wc-rf-selected-count]');
		var weightEl = document.querySelector('[data-wc-rf-selected-weight]');
		var submit = document.getElementById('wc-rf-consolidate-submit');

		if (countEl) {
			countEl.textContent = String(checks.length);
		}
		if (weightEl) {
			weightEl.textContent = weight.toFixed(3);
		}
		if (submit) {
			submit.disabled = checks.length === 0;
		}
	}

	function activateTab(tab) {
		document.querySelectorAll('[data-wc-rf-tab]').forEach(function (button) {
			var on = button.getAttribute('data-wc-rf-tab') === tab;
			button.classList.toggle('is-active', on);
			button.setAttribute('aria-selected', on ? 'true' : 'false');
		});
		document.querySelectorAll('[data-wc-rf-panel]').forEach(function (panel) {
			panel.classList.toggle('is-active', panel.getAttribute('data-wc-rf-panel') === tab);
		});
	}

	document.addEventListener('click', function (event) {
		var tabButton = event.target.closest('[data-wc-rf-tab]');
		if (!tabButton) {
			return;
		}
		event.preventDefault();
		activateTab(tabButton.getAttribute('data-wc-rf-tab') || 'pending');
	});

	document.addEventListener('change', function (event) {
		var target = event.target;
		if (target.classList && target.classList.contains('wc-rf-select-all')) {
			var tab = target.getAttribute('data-wc-rf-select-all');
			var panel = document.querySelector('[data-wc-rf-panel="' + tab + '"]');
			if (panel) {
				panel.querySelectorAll('.wc-rf-package-check[data-selectable="1"]').forEach(function (input) {
					input.checked = target.checked;
				});
			}
		}
		updateSelectionBar();
	});

	var form = document.getElementById('wc-rf-packages-form');
	if (form) {
		form.addEventListener('submit', function (event) {
			if (selectedChecks().length === 0) {
				event.preventDefault();
				window.alert(labels.needSelect || '请至少勾选一个已到仓包裹。');
			}
		});
	}

	var company = document.getElementById('wc-rf-express-company');
	var otherRow = document.getElementById('wc-rf-express-other-row');
	var otherInput = document.getElementById('wc-rf-express-other');

	function toggleOtherCompany() {
		if (!company || !otherRow) {
			return;
		}
		var show = company.value === 'other';
		otherRow.classList.toggle('is-hidden', !show);
		if (otherInput) {
			otherInput.required = show;
		}
	}

	if (company) {
		company.addEventListener('change', toggleOtherCompany);
		toggleOtherCompany();
	}

	updateSelectionBar();
})();
