(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		var root = document.querySelector('[data-meb-pdp]');
		if (!root) {
			return;
		}

		var mainImg = root.querySelector('[data-meb-main-img]');
		var priceEl = root.querySelector('[data-meb-price]');
		var varInput = root.querySelector('[data-meb-variation-id]');
		var form = root.querySelector('[data-meb-cart-form]');
		var variations = [];

		try {
			variations = JSON.parse(root.getAttribute('data-variations') || '[]') || [];
		} catch (err) {
			variations = [];
		}

		// Gallery thumbnails
		root.addEventListener('click', function (e) {
			var thumb = e.target.closest('[data-meb-thumb]');
			if (thumb && mainImg) {
				mainImg.src = thumb.getAttribute('data-meb-thumb');
				root.querySelectorAll('[data-meb-thumb]').forEach(function (t) {
					t.classList.toggle('is-active', t === thumb);
				});
				return;
			}

			var moreBtn = e.target.closest('[data-meb-more-thumbs]');
			if (moreBtn) {
				root.querySelectorAll('.meb-pdp__thumb.is-extra').forEach(function (t) {
					t.hidden = false;
				});
				moreBtn.hidden = true;
			}
		});

		// Variation resolution
		function selectedAttributes() {
			var attrs = {};
			root.querySelectorAll('[data-meb-attr-input]').forEach(function (input) {
				attrs[input.getAttribute('data-meb-attr-input')] = input.value;
			});
			return attrs;
		}

		function matchVariation() {
			if (!variations.length) {
				return null;
			}
			var picked = selectedAttributes();
			var keys = Object.keys(picked);
			for (var k = 0; k < keys.length; k++) {
				if (!picked[keys[k]]) {
					return null;
				}
			}

			for (var i = 0; i < variations.length; i++) {
				var v = variations[i];
				var vAttrs = v.attributes || {};
				var ok = true;
				for (var j = 0; j < keys.length; j++) {
					var key = keys[j];
					var expected = vAttrs[key];
					if (expected && expected !== picked[key]) {
						ok = false;
						break;
					}
				}
				if (ok) {
					return v;
				}
			}
			return null;
		}

		function pickSwatch(btn) {
			var groupKey = btn.getAttribute('data-meb-swatch');
			var value = btn.getAttribute('data-value') || '';
			var group = root.querySelector('[data-meb-attr-group="' + groupKey + '"]');
			if (!group) {
				return;
			}

			group.classList.remove('is-missing');
			group.querySelectorAll('[data-meb-swatch]').forEach(function (b) {
				b.classList.toggle('is-active', b === btn);
			});

			var input = group.querySelector('[data-meb-attr-input]');
			if (input) {
				input.value = value;
			}
			var pickedEl = group.querySelector('[data-meb-attr-picked]');
			if (pickedEl) {
				pickedEl.textContent = value;
			}

			var swImg = btn.getAttribute('data-image');
			if (swImg && mainImg) {
				mainImg.src = swImg;
			}

			var matched = matchVariation();
			if (varInput) {
				varInput.value = matched ? String(matched.variation_id) : '0';
			}
			if (matched && matched.price_html && priceEl) {
				priceEl.textContent = matched.price_html;
			}
			if (matched && matched.image && mainImg) {
				mainImg.src = matched.image;
			}
		}

		root.addEventListener('click', function (e) {
			var sw = e.target.closest('[data-meb-swatch]');
			if (sw) {
				pickSwatch(sw);
			}
		});

		// Pre-select the first option in each attribute group so "Add to cart" / "Buy now" works immediately.
		root.querySelectorAll('[data-meb-attr-group]').forEach(function (group) {
			var first = group.querySelector('[data-meb-swatch]');
			if (first) {
				pickSwatch(first);
			}
		});

		// Quantity stepper
		var qtyInput = root.querySelector('[data-meb-qty-input]');
		root.querySelectorAll('[data-meb-qty]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var delta = parseInt(btn.getAttribute('data-meb-qty'), 10) || 0;
				var cur = Math.max(1, parseInt(qtyInput.value, 10) || 1);
				qtyInput.value = String(Math.max(1, cur + delta));
			});
		});

		// Validate variation selection before submit
		if (form && varInput) {
			form.addEventListener('submit', function (e) {
				if (parseInt(varInput.value, 10) > 0) {
					return;
				}
				e.preventDefault();
				root.querySelectorAll('[data-meb-attr-group]').forEach(function (group) {
					var input = group.querySelector('[data-meb-attr-input]');
					if (input && !input.value) {
						group.classList.add('is-missing');
					}
				});
				var firstMissing = root.querySelector('.meb-pdp__attr.is-missing');
				if (firstMissing) {
					firstMissing.scrollIntoView({ behavior: 'smooth', block: 'center' });
				}
			});
		}

		// Favorite toggle (persisted in localStorage)
		var favBtn = root.querySelector('[data-meb-fav]');
		var pid = root.getAttribute('data-product-id') || '';
		var favKey = 'mebFav_' + pid;
		if (favBtn && pid) {
			try {
				if (window.localStorage.getItem(favKey) === '1') {
					favBtn.classList.add('is-active');
					favBtn.setAttribute('aria-pressed', 'true');
				}
			} catch (err) {}

			favBtn.addEventListener('click', function () {
				var active = favBtn.classList.toggle('is-active');
				favBtn.setAttribute('aria-pressed', active ? 'true' : 'false');
				try {
					if (active) {
						window.localStorage.setItem(favKey, '1');
					} else {
						window.localStorage.removeItem(favKey);
					}
				} catch (err) {}
			});
		}

		// Share & Earn copies product URL
		var shareBtn = root.querySelector('[data-meb-share]');
		if (shareBtn) {
			shareBtn.addEventListener('click', function () {
				var label = shareBtn.querySelector('span:last-child');
				var orig = label.textContent;
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(window.location.href);
				}
				label.textContent = 'Link Copied!';
				window.setTimeout(function () {
					label.textContent = orig;
				}, 1800);
			});
		}

		// Refresh re-runs item_get sync if available, otherwise reloads
		var refreshBtn = root.querySelector('[data-meb-refresh]');
		if (refreshBtn) {
			refreshBtn.addEventListener('click', function () {
				refreshBtn.classList.add('is-spinning');
				window.location.reload();
			});
		}

		// Keep the floating CTA aligned with the right-hand summary column.
		var ctaCard = root.querySelector('.meb-pdp__cta-card');
		var summary = root.querySelector('.meb-pdp__summary');
		function pinFloatingCta() {
			if (!ctaCard || !summary) {
				return;
			}
			if (window.matchMedia('(max-width: 560px)').matches) {
				ctaCard.style.left = '';
				ctaCard.style.right = '';
				ctaCard.style.width = '';
				return;
			}
			var box = summary.getBoundingClientRect();
			ctaCard.style.left = Math.round(box.left) + 'px';
			ctaCard.style.width = Math.round(box.width) + 'px';
			ctaCard.style.right = 'auto';
		}
		pinFloatingCta();
		window.addEventListener('resize', pinFloatingCta);
		if (window.ResizeObserver) {
			new ResizeObserver(pinFloatingCta).observe(summary);
		}
	});
})();
