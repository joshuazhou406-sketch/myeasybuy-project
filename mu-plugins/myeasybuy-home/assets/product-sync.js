/**
 * Shared product-sync overlay: search cards and pasted marketplace links
 * go through the same listing → overlay → purchase page flow as Shop.
 */
(function () {
	'use strict';

	var SYNC_STEPS = [
		'正在连接源平台获取商品信息…',
		'正在解析商品规格、款式与实时库存…',
		'正在换算价格并同步高清商品图…',
		'即将为您打开商品采购页…'
	];

	var overlay = null;
	var timer = null;
	var active = null;

	function fallbackMessage() {
		return (window.ppwSearch && ppwSearch.detailError) || '加载商品详情失败。';
	}

	function ensureOverlay() {
		if (overlay) {
			return overlay;
		}

		overlay = document.createElement('div');
		overlay.className = 'meb-sync-modal';
		overlay.hidden = true;
		overlay.setAttribute('role', 'status');
		overlay.setAttribute('aria-live', 'polite');
		overlay.innerHTML =
			'<div class="meb-sync-modal__card">' +
				'<div class="meb-sync-modal__visual">' +
					'<span class="meb-sync-modal__ring" aria-hidden="true"></span>' +
					'<span class="meb-sync-modal__pulse" aria-hidden="true"></span>' +
					'<img class="meb-sync-modal__thumb" alt="" />' +
				'</div>' +
				'<p class="meb-sync-modal__headline">正在为您准备商品采购页</p>' +
				'<p class="meb-sync-modal__product" data-meb-sync-title></p>' +
				'<div class="meb-sync-modal__bar" aria-hidden="true"><span data-meb-sync-bar></span></div>' +
				'<ul class="meb-sync-modal__steps" data-meb-sync-steps>' +
					SYNC_STEPS.map(function (t) {
						return '<li><i></i><span>' + t + '</span></li>';
					}).join('') +
				'</ul>' +
				'<button type="button" class="meb-sync-modal__cancel" data-meb-sync-cancel>取消等待</button>' +
			'</div>';
		document.body.appendChild(overlay);

		overlay.querySelector('[data-meb-sync-cancel]').addEventListener('click', abort);

		return overlay;
	}

	function setStep(index, doneAll) {
		if (!overlay) {
			return;
		}
		overlay.querySelectorAll('[data-meb-sync-steps] li').forEach(function (li, i) {
			li.classList.toggle('is-done', doneAll || i < index);
			li.classList.toggle('is-active', !doneAll && i === index);
		});
		overlay.querySelector('[data-meb-sync-bar]').style.width = (doneAll ? 100 : Math.min(92, 22 + index * 23)) + '%';
	}

	function show(meta) {
		var el = ensureOverlay();
		var img = el.querySelector('.meb-sync-modal__thumb');
		var src = (meta && meta.image) || '';
		img.src = src;
		img.hidden = !src;
		el.querySelector('[data-meb-sync-title]').textContent = (meta && meta.title) || '';

		var step = 0;
		setStep(0, false);
		window.clearInterval(timer);
		timer = window.setInterval(function () {
			if (step < SYNC_STEPS.length - 1) {
				step += 1;
				setStep(step, false);
			}
		}, 1100);

		el.hidden = false;
	}

	function hide() {
		window.clearInterval(timer);
		if (overlay) {
			overlay.hidden = true;
		}
		document.querySelectorAll('.ppw-jd-card.is-loading').forEach(function (card) {
			card.classList.remove('is-loading');
		});
	}

	function abort() {
		if (active) {
			active.abort();
			active = null;
		}
		hide();
	}

	function post(fields) {
		if (typeof ppwSearch === 'undefined') {
			return Promise.reject(new Error(fallbackMessage()));
		}

		if (active) {
			active.abort();
		}
		active = new AbortController();

		var body = new URLSearchParams(fields);
		body.set('nonce', ppwSearch.nonce);

		return fetch(ppwSearch.ajaxUrl, {
			method: 'POST',
			body: body,
			credentials: 'same-origin',
			signal: active.signal
		})
			.then(function (res) {
				return res.json();
			})
			.then(function (json) {
				var url = json && json.success && json.data && json.data.product_url;
				if (!url) {
					throw new Error((json && json.data && json.data.message) || fallbackMessage());
				}
				setStep(SYNC_STEPS.length, true);
				window.location.href = url;
			})
			.catch(function (err) {
				if (err && err.name === 'AbortError') {
					return;
				}
				hide();
				window.alert((err && err.message) || fallbackMessage());
			});
	}

	function openFromCard(btn) {
		if (!btn || typeof ppwSearch === 'undefined') {
			return;
		}

		var numIid = btn.getAttribute('data-item-id') || '';
		if (!numIid || numIid.indexOf('demo-') === 0) {
			window.alert(fallbackMessage());
			return;
		}

		document.querySelectorAll('.ppw-jd-card.is-loading').forEach(function (card) {
			card.classList.remove('is-loading');
		});
		var card = btn.closest('.ppw-jd-card');
		if (card) {
			card.classList.add('is-loading');
		}

		show({
			title: btn.getAttribute('data-title') || '',
			image: btn.getAttribute('data-image') || ''
		});

		post({
			action: 'ppw_jd_product_detail',
			num_iid: numIid,
			platform: btn.getAttribute('data-platform') || ppwSearch.platform || 'taobao',
			title: btn.getAttribute('data-title') || '',
			price_rmb: btn.getAttribute('data-price-rmb') || '',
			image: btn.getAttribute('data-image') || '',
			url: btn.getAttribute('data-jd-url') || '',
			shop: btn.getAttribute('data-shop') || ''
		});
	}

	function openFromLink(query, extras) {
		query = (query || '').trim();
		if (!query || typeof ppwSearch === 'undefined') {
			return;
		}

		extras = extras || {};
		show({
			title: extras.title || query,
			image: extras.image || ''
		});

		post({
			action: 'ppw_resolve_product_link',
			query: query
		});
	}

	window.mebProductSync = {
		openFromCard: openFromCard,
		openFromLink: openFromLink,
		abort: abort
	};

	window.addEventListener(
		'click',
		function (e) {
			var btn = e.target.closest('.ppw-jd-card-link');
			if (!btn) {
				return;
			}
			e.preventDefault();
			e.stopImmediatePropagation();
			openFromCard(btn);
		},
		true
	);
})();
