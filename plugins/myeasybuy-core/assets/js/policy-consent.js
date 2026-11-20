(function () {
	'use strict';

	var cfg = window.mebPolicyConsent || {};

	function ready(fn) {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', fn);
		} else {
			fn();
		}
	}

	function setConsentCookie() {
		if (!cfg.regNonce || !cfg.cookieName) {
			return;
		}
		document.cookie = cfg.cookieName + '=' + encodeURIComponent(cfg.regNonce) + '; path=/; max-age=1200; SameSite=Lax';
	}

	function clearConsentCookie() {
		if (!cfg.cookieName) {
			return;
		}
		document.cookie = cfg.cookieName + '=; path=/; max-age=0; SameSite=Lax';
	}

	function appendNonceToGoogle(link) {
		if (!link || !cfg.regNonce) {
			return;
		}
		try {
			var url = new URL(link.href, window.location.origin);
			url.searchParams.set('meb_consent_nonce', cfg.regNonce);
			link.href = url.toString();
		} catch (e) {
			if (link.href.indexOf('meb_consent_nonce=') === -1) {
				link.href += (link.href.indexOf('?') === -1 ? '?' : '&') + 'meb_consent_nonce=' + encodeURIComponent(cfg.regNonce);
			}
		}
	}

	function initRegisterGoogleGate() {
		var box = document.getElementById('meb_terms');
		var google = document.querySelector('.meb-reg__google');
		if (!box || !google || google.tagName !== 'A') {
			return;
		}

		function sync() {
			var on = !!box.checked;
			google.classList.toggle('is-disabled', !on);
			google.setAttribute('aria-disabled', on ? 'false' : 'true');
			if (on) {
				setConsentCookie();
				appendNonceToGoogle(google);
			} else {
				clearConsentCookie();
			}
		}

		google.addEventListener('click', function (e) {
			if (!box.checked) {
				e.preventDefault();
				box.focus();
			}
		});

		box.addEventListener('change', sync);
		sync();
	}

	function isShippingCart() {
		return cfg.cartIsShipping === true || cfg.cartIsShipping === 1 || cfg.cartIsShipping === '1';
	}

	function fieldRoot(fieldId) {
		if (!fieldId) {
			return null;
		}
		var slug = fieldId.replace(/\//g, '-');
		var input = document.getElementById('order-' + slug) || document.getElementById(slug) || document.querySelector('[name="order_' + fieldId + '"]');
		if (input) {
			return input.closest('[class*="address-form__' + slug + '"]')
				|| input.closest('.wc-block-components-checkbox')
				|| input.closest('.wc-blocks-components-select')
				|| input.closest('.wc-block-components-select')
				|| input.parentElement;
		}
		return document.querySelector('.wc-block-components-address-form__' + slug);
	}

	function toggleCheckoutFields() {
		var shipping = isShippingCart();
		if (document.body) {
			document.body.classList.toggle('meb-cart-shipping', shipping);
			document.body.classList.toggle('meb-cart-purchase', !shipping);
		}
		var purchase = fieldRoot(cfg.fieldPurchase);
		var ship = fieldRoot(cfg.fieldShipping);
		var prot = fieldRoot(cfg.fieldProtection);
		function hide(el, on) {
			if (!el) {
				return;
			}
			el.style.display = on ? 'none' : '';
			el.hidden = !!on;
		}
		hide(purchase, shipping);
		hide(ship, !shipping);
		hide(prot, !shipping);
	}

	function linkify(html, urls) {
		if (!html || html.indexOf('<a ') !== -1) {
			return html;
		}
		var pairs = [
			['Shipping & Prohibited Items Policy', urls.shipping],
			['Storage & Inspection Policy', urls.storage],
			['Compensation & Parcel Protection Policy', urls.compensation],
			['Compensation & Parcel Protection', urls.compensation],
			['Return & Refund Policy', urls.returns],
			['Terms of Service', urls.terms],
			['运输与禁运政策', urls.shipping],
			['仓储与验货政策', urls.storage],
			['赔偿与包裹保障', urls.compensation],
			['退货退款政策', urls.returns],
			['服务条款', urls.terms]
		];
		pairs.forEach(function (p) {
			if (!p[1]) return;
			var re = new RegExp(p[0].replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g');
			html = html.replace(re, '<a href="' + p[1] + '" target="_blank" rel="noopener noreferrer">' + p[0] + '</a>');
		});
		return html;
	}

	function enhanceCheckoutLabels() {
		var urls = cfg.policyUrls || {};
		var roots = document.querySelectorAll('.wc-block-components-checkbox__label, .wc-block-components-select label, .wc-block-components-checkout-additional-fields label, .wc-block-components-checkbox span');
		roots.forEach(function (el) {
			if (el.getAttribute('data-meb-linked') === '1') return;
			var src = el.innerHTML;
			if (!src) return;
			if (src.indexOf('Terms of Service') === -1 && src.indexOf('服务条款') === -1 && src.indexOf('Shipping') === -1 && src.indexOf('运输') === -1 && src.indexOf('Parcel protection') === -1 && src.indexOf('包裹保障') === -1) {
				return;
			}
			var next = linkify(src, urls).replace(/\s*\((?:optional|可选)\)/gi, '');
			if (next !== src) {
				el.innerHTML = next;
				el.setAttribute('data-meb-linked', '1');
			}
		});
	}

	function initCheckoutFields() {
		if (!document.body || !document.body.classList.contains('woocommerce-checkout')) {
			return;
		}
		toggleCheckoutFields();
		enhanceCheckoutLabels();
		var obs = new MutationObserver(function () {
			toggleCheckoutFields();
			enhanceCheckoutLabels();
		});
		obs.observe(document.body, { childList: true, subtree: true });
		window.setTimeout(toggleCheckoutFields, 600);
		window.setTimeout(toggleCheckoutFields, 1600);
	}

	function buildDisclaimer() {
		var data = cfg.disclaimer || {};
		var steps = data.steps || [];
		if (!steps.length) {
			return null;
		}

		var wrap = document.createElement('div');
		wrap.className = 'meb-disc';
		wrap.setAttribute('role', 'dialog');
		wrap.setAttribute('aria-modal', 'true');
		wrap.setAttribute('aria-labelledby', 'meb-disc-title');

		wrap.innerHTML =
			'<div class="meb-disc__backdrop" data-meb-disc-ignore="1"></div>' +
			'<div class="meb-disc__panel">' +
			'<div class="meb-disc__head">' +
			'<button type="button" class="meb-disc__back" data-meb-disc-back hidden>&lsaquo; <span data-meb-disc-back-label></span></button>' +
			'<span class="meb-disc__eyebrow">' + escapeHtml(data.eyebrowEn || '') + ' <span lang="zh">' + escapeHtml(data.eyebrowZh || '') + '</span></span>' +
			'<span class="meb-disc__badge">' + escapeHtml(data.badgeEn || '') + ' / ' + escapeHtml(data.badgeZh || '') + '</span>' +
			'</div>' +
			'<div class="meb-disc__progress" data-meb-disc-progress></div>' +
			'<div data-meb-disc-body></div>' +
			'<div class="meb-disc__foot">' +
			'<label class="meb-disc__accept" data-meb-disc-accept hidden>' +
			'<input type="checkbox" data-meb-disc-check />' +
			'<span>' + escapeHtml(data.acceptEn || '') + '<br />' + escapeHtml(data.acceptZh || '') + '</span>' +
			'</label>' +
			'<button type="button" class="meb-disc__btn" data-meb-disc-next></button>' +
			'</div>' +
			'</div>';

		var progress = wrap.querySelector('[data-meb-disc-progress]');
		steps.forEach(function (step, i) {
			var d = document.createElement('div');
			d.className = 'meb-disc__step-dot';
			d.innerHTML = '<span class="meb-disc__num">' + (i + 1) + '</span><span class="meb-disc__step-label">' + escapeHtml(step.label_en || '') + '<br />' + escapeHtml(step.label_zh || '') + '</span>';
			progress.appendChild(d);
		});

		document.body.appendChild(wrap);
		return wrap;
	}

	function escapeHtml(s) {
		return String(s == null ? '' : s)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function renderStep(wrap, index) {
		var data = cfg.disclaimer || {};
		var steps = data.steps || [];
		var step = steps[index];
		if (!step) {
			return;
		}
		var dots = wrap.querySelectorAll('.meb-disc__step-dot');
		dots.forEach(function (dot, i) {
			dot.classList.toggle('is-current', i === index);
			dot.classList.toggle('is-done', i < index);
		});
		var body = wrap.querySelector('[data-meb-disc-body]');
		var html = '<h2 class="meb-disc__title" id="meb-disc-title">' + escapeHtml((index + 1).toString().padStart(2, '0') + '. ' + (step.title_en || '')) + '</h2>';
		html += '<p class="meb-disc__title meb-disc__title-zh" lang="zh">' + escapeHtml(step.title_zh || '') + '</p>';
		if (step.body_en) {
			html += '<p class="meb-disc__body">' + escapeHtml(step.body_en) + '</p>';
		}
		if (step.body_zh) {
			html += '<p class="meb-disc__body" lang="zh">' + escapeHtml(step.body_zh) + '</p>';
		}
		(step.substeps || []).forEach(function (sub) {
			html += '<div class="meb-disc__sub"><div class="meb-disc__sub-label">' + escapeHtml(sub.label_en || '') + ' / ' + escapeHtml(sub.label_zh || '') + '</div>';
			html += '<p class="meb-disc__body">' + escapeHtml(sub.en || '') + '</p>';
			html += '<p class="meb-disc__body" lang="zh">' + escapeHtml(sub.zh || '') + '</p></div>';
		});
		body.innerHTML = html;

		var last = index === steps.length - 1;
		var back = wrap.querySelector('[data-meb-disc-back]');
		var accept = wrap.querySelector('[data-meb-disc-accept]');
		var next = wrap.querySelector('[data-meb-disc-next]');
		var backLabel = wrap.querySelector('[data-meb-disc-back-label]');
		back.hidden = index === 0;
		if (backLabel) {
			backLabel.textContent = (data.backEn || 'Back') + ' / ' + (data.backZh || '返回');
		}
		accept.hidden = !last;
		next.disabled = last && !wrap.querySelector('[data-meb-disc-check]').checked;
		next.textContent = last
			? (data.continueEn || 'Continue') + ' / ' + (data.continueZh || '继续')
			: (data.nextEn || 'Confirm, Next') + ' / ' + (data.nextZh || '确认，下一步');
	}

	function initDisclaimer() {
		if (!cfg.showDisclaimer) {
			return;
		}
		var wrap = buildDisclaimer();
		if (!wrap) {
			return;
		}
		var index = 0;
		var steps = (cfg.disclaimer && cfg.disclaimer.steps) || [];
		document.body.classList.add('meb-disc-open');
		renderStep(wrap, index);

		wrap.querySelector('[data-meb-disc-back]').addEventListener('click', function () {
			if (index > 0) {
				index -= 1;
				renderStep(wrap, index);
			}
		});
		wrap.querySelector('[data-meb-disc-check]').addEventListener('change', function () {
			renderStep(wrap, index);
		});
		wrap.querySelector('[data-meb-disc-next]').addEventListener('click', function () {
			if (index < steps.length - 1) {
				index += 1;
				renderStep(wrap, index);
				wrap.querySelector('.meb-disc__panel').scrollTop = 0;
				return;
			}
			var box = wrap.querySelector('[data-meb-disc-check]');
			if (!box.checked) {
				box.focus();
				return;
			}
			submitDisclaimer(wrap);
		});
	}

	function submitDisclaimer(wrap) {
		var btn = wrap.querySelector('[data-meb-disc-next]');
		btn.disabled = true;
		var body = new FormData();
		body.append('action', 'meb_save_disclaimer');
		body.append('nonce', cfg.disclaimerNonce || '');
		body.append('accepted', '1');
		fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (res) {
				return res.json();
			})
			.then(function (json) {
				if (json && json.success) {
					wrap.hidden = true;
					document.body.classList.remove('meb-disc-open');
					cfg.showDisclaimer = false;
					return;
				}
				btn.disabled = false;
				window.alert('Please try again / 请重试');
			})
			.catch(function () {
				btn.disabled = false;
				window.alert('Please try again / 请重试');
			});
	}

	ready(function () {
		initRegisterGoogleGate();
		initCheckoutFields();
		initDisclaimer();
	});
})();
