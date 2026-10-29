/* global ppwSearch */
(function () {
	'use strict';

	var SKELETONS = 10;

	var root;
	var grid;
	var statusEl;
	var moreBtn;
	var state = {
		cat: '',
		keyword: '',
		platform: 'taobao',
		page: 1,
		token: 0
	};

	function looksLikeUrl(value) {
		return /^https?:\/\//i.test(value) || /(taobao\.com|tmall\.(com|hk)|tb\.cn|jd\.(com|hk))/i.test(value);
	}

	function setStatus(text, isError) {
		statusEl.hidden = !text;
		statusEl.textContent = text || '';
		statusEl.classList.toggle('is-error', !!isError);
	}

	function skeletons() {
		var html = '';
		for (var i = 0; i < SKELETONS; i++) {
			html += '<li class="meb-skel" aria-hidden="true"><span></span><i></i><i></i></li>';
		}
		return html;
	}

	function updateUrl() {
		if (!window.history || !window.history.replaceState) {
			return;
		}
		var url = new URL(window.location.href);
		url.searchParams.delete('cat');
		url.searchParams.delete('q');
		if (state.cat) {
			url.searchParams.set('cat', state.cat);
		} else if (state.keyword) {
			url.searchParams.set('q', state.keyword);
		}
		window.history.replaceState({}, '', url.toString());
	}

	function load(append) {
		if (typeof ppwSearch === 'undefined' || state.keyword.length < 2) {
			return;
		}

		var token = ++state.token;
		state.page = append ? state.page + 1 : 1;

		if (append) {
			moreBtn.disabled = true;
			moreBtn.textContent = ppwSearch.loadingMore || '正在加载…';
		} else {
			grid.innerHTML = skeletons();
			moreBtn.hidden = true;
			setStatus('');
		}

		var body = new URLSearchParams({
			action: 'ppw_jd_search',
			nonce: ppwSearch.nonce,
			keyword: state.keyword,
			page: String(state.page),
			platform: state.platform
		});

		fetch(ppwSearch.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
			.then(function (res) {
				return res.json();
			})
			.then(function (json) {
				if (token !== state.token) {
					return;
				}
				var data = json && json.data ? json.data : {};
				if (!json || !json.success) {
					throw new Error(data.message || ppwSearch.noResults);
				}

				if (append) {
					grid.insertAdjacentHTML('beforeend', data.cards_html || '');
				} else {
					grid.innerHTML = data.cards_html || '';
				}

				if (!grid.children.length) {
					setStatus(ppwSearch.noResults);
				}
				moreBtn.hidden = !data.has_more;
				moreBtn.disabled = false;
				moreBtn.textContent = '查看更多商品';
			})
			.catch(function (err) {
				if (token !== state.token) {
					return;
				}
				if (append) {
					moreBtn.disabled = false;
					moreBtn.textContent = '查看更多商品';
					state.page -= 1;
				} else {
					grid.innerHTML = '';
				}
				setStatus(err && err.message ? err.message : ppwSearch.error, true);
			});
	}

	function setActive(selector, attr, value) {
		document.querySelectorAll(selector).forEach(function (el) {
			el.classList.toggle('is-active', el.getAttribute(attr) === value);
		});
	}

	function selectSub(tab) {
		var list = tab.closest('[data-meb-subs]');
		list.querySelectorAll('.meb-subs__tab').forEach(function (t) {
			t.classList.toggle('is-active', t === tab);
		});
		state.keyword = tab.getAttribute('data-keyword') || '';
		load(false);
	}

	function selectCat(cat, sub) {
		var list = document.querySelector('[data-meb-subs="' + cat + '"]');
		if (!list) {
			return;
		}

		state.cat = cat;
		setActive('[data-meb-cat]', 'data-meb-cat', cat);
		document.querySelectorAll('[data-meb-subs]').forEach(function (l) {
			l.hidden = l !== list;
		});
		document.querySelector('[data-meb-search-label]').hidden = true;

		var tab = (sub && list.querySelector('[data-meb-sub="' + sub + '"]')) || list.querySelector('.meb-subs__tab');
		selectSub(tab);
		updateUrl();

		var activeCard = document.querySelector('.meb-cat.is-active');
		if (activeCard) {
			activeCard.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'smooth' });
		}
	}

	function runSearch(keyword, scroll) {
		keyword = keyword.trim();
		if (keyword.length < 2) {
			return;
		}

		if (looksLikeUrl(keyword)) {
			if (window.mebProductSync) {
				window.mebProductSync.openFromLink(keyword, { title: keyword });
				return;
			}
			var url = new URL(window.location.origin + '/');
			url.searchParams.set('ppw_q', keyword);
			url.searchParams.set('ppw_platform', state.platform);
			window.location.href = url.toString();
			return;
		}

		state.cat = '';
		state.keyword = keyword;
		setActive('[data-meb-cat]', 'data-meb-cat', '');
		document.querySelectorAll('[data-meb-subs]').forEach(function (l) {
			l.hidden = true;
		});
		document.querySelector('[data-meb-search-label]').hidden = false;
		document.querySelector('[data-meb-search-term]').textContent = keyword;
		updateUrl();
		load(false);
		if (scroll) {
			window.scrollTo({ top: root.getBoundingClientRect().top + window.pageYOffset - 80, behavior: 'smooth' });
		}
	}

	function initAllCats() {
		var panel = document.getElementById('meb-allcats');
		var toggles = document.querySelectorAll('[data-meb-allcats]');

		function setOpen(open) {
			panel.hidden = !open;
			toggles.forEach(function (t) {
				t.setAttribute('aria-expanded', open ? 'true' : 'false');
			});
		}

		toggles.forEach(function (t) {
			t.addEventListener('click', function (e) {
				e.stopPropagation();
				setOpen(panel.hidden);
				if (!panel.hidden) {
					window.scrollTo({ top: 0, behavior: 'smooth' });
				}
			});
		});

		document.addEventListener('click', function (e) {
			if (!panel.hidden && !panel.contains(e.target)) {
				setOpen(false);
			}
		});

		panel.addEventListener('click', function (e) {
			if (e.target.closest('[data-meb-cat]')) {
				setOpen(false);
			}
		});
	}

	function initScrollers() {
		document.querySelectorAll('[data-meb-scroll-btn]').forEach(function (btn) {
			var scope = btn.closest('.meb-cats, .meb-catbar__inner');
			var track = scope && scope.querySelector('[data-meb-scroll]');
			if (!track) {
				return;
			}
			btn.addEventListener('click', function () {
				var dir = parseInt(btn.getAttribute('data-meb-scroll-btn'), 10) || 1;
				track.scrollBy({ left: dir * track.clientWidth * 0.8, behavior: 'smooth' });
			});
		});
	}

	function initCountdown() {
		var el = document.querySelector('[data-meb-countdown]');
		if (!el) {
			return;
		}

		function pad(n) {
			return n < 10 ? '0' + n : String(n);
		}

		// Counts down to midnight in China (UTC+8).
		function tick() {
			var now = Date.now();
			var cn = now + 8 * 3600000;
			var left = Math.floor((86400000 - (cn % 86400000)) / 1000);
			el.textContent = pad(Math.floor(left / 3600)) + ':' + pad(Math.floor((left % 3600) / 60)) + ':' + pad(left % 60);
		}

		tick();
		window.setInterval(tick, 1000);
	}

	function initWhy() {
		var btn = document.querySelector('[data-meb-why-toggle]');
		var details = document.querySelector('.meb-why__details');
		if (!btn || !details) {
			return;
		}
		btn.addEventListener('click', function () {
			details.hidden = !details.hidden;
			btn.setAttribute('aria-expanded', details.hidden ? 'false' : 'true');
			btn.classList.toggle('is-open', !details.hidden);
		});
	}

	function initPlatform() {
		var badges = document.querySelectorAll('[data-meb-shop-platform]');
		badges.forEach(function (badge) {
			badge.addEventListener('click', function () {
				var platform = badge.getAttribute('data-meb-shop-platform');
				if (platform === state.platform) {
					return;
				}
				state.platform = platform;
				badges.forEach(function (b) {
					var active = b === badge;
					b.classList.toggle('is-active', active);
					b.setAttribute('aria-selected', active ? 'true' : 'false');
				});
				load(false);
			});
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		root = document.querySelector('[data-meb-shop]');
		if (!root) {
			return;
		}
		grid = root.querySelector('[data-meb-grid]');
		statusEl = root.querySelector('[data-meb-status]');
		moreBtn = root.querySelector('[data-meb-more]');
		state.platform = root.getAttribute('data-platform') || 'taobao';

		initAllCats();
		initScrollers();
		initCountdown();
		initWhy();
		initPlatform();

		document.addEventListener('click', function (e) {
			var catBtn = e.target.closest('[data-meb-cat]');
			if (catBtn) {
				selectCat(catBtn.getAttribute('data-meb-cat'), catBtn.getAttribute('data-meb-sub'));
				return;
			}
			var subTab = e.target.closest('.meb-subs__tab');
			if (subTab && !subTab.classList.contains('is-active')) {
				selectSub(subTab);
				updateUrl();
			}
		});

		moreBtn.addEventListener('click', function () {
			load(true);
		});

		var form = document.querySelector('[data-meb-shop-search]');
		var input = form.querySelector('input');
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			runSearch(input.value, true);
		});

		document.querySelector('[data-meb-search-clear]').addEventListener('click', function () {
			input.value = '';
			selectCat(document.querySelector('[data-meb-subs]').getAttribute('data-meb-subs'));
		});

		var params = new URL(window.location.href).searchParams;
		var q = params.get('q');
		if (q) {
			input.value = q;
			runSearch(q);
			return;
		}
		var active = document.querySelector('.meb-catbar__link.is-active');
		selectCat(active ? active.getAttribute('data-meb-cat') : '');
	});
})();
