(function () {
	'use strict';

	var cfg = (typeof window !== 'undefined' && window.mebOnlineService) || {};
	var kb = null;
	var root = null;

	function $(sel, ctx) { return (ctx || document).querySelector(sel); }

	function el(html) {
		var d = document.createElement('div');
		d.innerHTML = html.trim();
		return d.firstElementChild;
	}

	function escapeHtml(s) {
		return String(s == null ? '' : s)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}

	var STORAGE_KEY = 'myeasybuy_os_pos';
	var DRAG_THRESHOLD = 5;
	var VIEW_PAD = 8;
	var fabAnchor = null; /* {left, top} of the launcher, or null for default */
	var placement = { h: 'left', v: 'up' };
	var lastFabW = 0;
	var lastFabH = 0;
	var suppressClick = false;
	var openTimer = null;

	var MebOsPos = {
		STORAGE_KEY: STORAGE_KEY,
		THRESHOLD: DRAG_THRESHOLD,
		PAD: VIEW_PAD,
		clamp: function (left, top, w, h, vw, vh, pad) {
			pad = pad == null ? VIEW_PAD : pad;
			vw = vw || 0;
			vh = vh || 0;
			w = Math.min(Math.max(0, w), Math.max(0, vw - pad * 2));
			h = Math.min(Math.max(0, h), Math.max(0, vh - pad * 2));
			var maxL = Math.max(pad, vw - pad - w);
			var maxT = Math.max(pad, vh - pad - h);
			left = Math.max(pad, Math.min(left, maxL));
			top = Math.max(pad, Math.min(top, maxT));
			return { left: left, top: top, w: w, h: h };
		},
		parse: function (raw) {
			try {
				var o = typeof raw === 'string' ? JSON.parse(raw) : raw;
				if (!o || !isFinite(Number(o.left)) || !isFinite(Number(o.top))) return null;
				return { left: Number(o.left), top: Number(o.top) };
			} catch (err) {
				return null;
			}
		},
		isDrag: function (dx, dy) {
			return Math.sqrt(dx * dx + dy * dy) >= DRAG_THRESHOLD;
		},
		placePanel: function (fab, pw, ph, vw, vh, pad) {
			pad = pad == null ? VIEW_PAD : pad;
			var fl = fab.left;
			var ft = fab.top;
			var fw = fab.width != null ? fab.width : (fab.right - fab.left);
			var fh = fab.height != null ? fab.height : (fab.bottom - fab.top);
			var fr = fab.right != null ? fab.right : (fl + fw);
			var fb = fab.bottom != null ? fab.bottom : (ft + fh);
			var h, left;
			if (fr - pw >= pad) {
				left = fr - pw;
				h = 'left';
			} else if (fl + pw <= vw - pad) {
				left = fl;
				h = 'right';
			} else if ((vw - fr) >= fl) {
				left = Math.max(pad, Math.min(fl, vw - pad - pw));
				h = 'right';
			} else {
				left = Math.max(pad, fr - pw);
				h = 'left';
			}
			var v, top;
			if (fb - ph >= pad) {
				top = fb - ph;
				v = 'up';
			} else if (ft + ph <= vh - pad) {
				top = ft;
				v = 'down';
			} else if (ft >= (vh - fb)) {
				top = pad;
				v = 'up';
			} else {
				top = Math.max(pad, vh - pad - ph);
				v = 'down';
			}
			var c = MebOsPos.clamp(left, top, pw, ph, vw, vh, pad);
			c.hAlign = h;
			c.vAlign = v;
			return c;
		}
	};

	function viewport() {
		return { w: window.innerWidth, h: window.innerHeight };
	}

	function rememberFabSize() {
		if (!root) return;
		var fab = $('.meb-os__fab', root);
		if (fab && fab.offsetWidth) {
			lastFabW = fab.offsetWidth;
			lastFabH = fab.offsetHeight;
		}
	}

	function fabSize() {
		rememberFabSize();
		return { w: lastFabW || 120, h: lastFabH || 148 };
	}

	function setRootBox(left, top) {
		root.classList.add('is-placed');
		root.style.left = Math.round(left) + 'px';
		root.style.top = Math.round(top) + 'px';
		root.style.right = 'auto';
		root.style.bottom = 'auto';
	}

	function clearRootBox() {
		root.classList.remove('is-placed');
		root.style.left = '';
		root.style.top = '';
		root.style.right = '';
		root.style.bottom = '';
	}

	function saveAnchor() {
		try {
			if (!fabAnchor) localStorage.removeItem(STORAGE_KEY);
			else localStorage.setItem(STORAGE_KEY, JSON.stringify({ left: fabAnchor.left, top: fabAnchor.top }));
		} catch (err) { /* private mode */ }
	}

	function loadAnchor() {
		try {
			return MebOsPos.parse(localStorage.getItem(STORAGE_KEY));
		} catch (err) {
			return null;
		}
	}

	function applyFabAnchor() {
		if (!root) return;
		if (!fabAnchor) {
			clearRootBox();
			return;
		}
		var sz = fabSize();
		var vp = viewport();
		var c = MebOsPos.clamp(fabAnchor.left, fabAnchor.top, sz.w, sz.h, vp.w, vp.h, VIEW_PAD);
		fabAnchor = { left: c.left, top: c.top };
		setRootBox(c.left, c.top);
	}

	function anchorFromPanel() {
		var r = root.getBoundingClientRect();
		var sz = fabSize();
		var left = placement.h === 'left' ? r.right - sz.w : r.left;
		var top = placement.v === 'up' ? r.bottom - sz.h : r.top;
		var vp = viewport();
		var c = MebOsPos.clamp(left, top, sz.w, sz.h, vp.w, vp.h, VIEW_PAD);
		fabAnchor = { left: c.left, top: c.top };
		saveAnchor();
	}

	function resetPosition() {
		fabAnchor = null;
		saveAnchor();
		if (root) {
			root.classList.remove('is-open', 'is-messages');
			clearRootBox();
		}
	}

	function bindDrag(handle, opts) {
		opts = opts || {};
		handle.addEventListener('pointerdown', function (e) {
			if (e.pointerType === 'mouse' && e.button !== 0) return;
			if (opts.ignore && e.target.closest(opts.ignore)) return;
			var startX = e.clientX;
			var startY = e.clientY;
			var startLeft = 0;
			var startTop = 0;
			var dragging = false;
			var pid = e.pointerId;
			try { handle.setPointerCapture(pid); } catch (err) { /* ignore */ }

			function onMove(ev) {
				if (ev.pointerId !== pid) return;
				var dx = ev.clientX - startX;
				var dy = ev.clientY - startY;
				if (!dragging) {
					if (!MebOsPos.isDrag(dx, dy)) return;
					dragging = true;
					if (opts.undock) root.classList.add('is-placed');
					root.classList.add('is-dragging');
					var r = root.getBoundingClientRect();
					startLeft = r.left;
					startTop = r.top;
				}
				if (ev.cancelable) ev.preventDefault();
				var sz = { w: root.offsetWidth, h: root.offsetHeight };
				var vp = viewport();
				var c = MebOsPos.clamp(startLeft + ev.clientX - startX, startTop + ev.clientY - startY, sz.w, sz.h, vp.w, vp.h, VIEW_PAD);
				setRootBox(c.left, c.top);
			}

			function onUp(ev) {
				if (ev.pointerId !== pid) return;
				try { handle.releasePointerCapture(pid); } catch (err) { /* ignore */ }
				handle.removeEventListener('pointermove', onMove);
				handle.removeEventListener('pointerup', onUp);
				handle.removeEventListener('pointercancel', onUp);
				root.classList.remove('is-dragging');
				if (dragging) {
					suppressClick = true;
					setTimeout(function () { suppressClick = false; }, 0);
					if (ev.cancelable) ev.preventDefault();
					if (opts.onDragEnd) opts.onDragEnd();
				}
			}

			handle.addEventListener('pointermove', onMove);
			handle.addEventListener('pointerup', onUp);
			handle.addEventListener('pointercancel', onUp);
		});
	}

	function mascotUrl(size) {
		var base = (cfg.mascotBase || 'assets/mascot/beaver').replace(/\/$/, '');
		return {
			base: base,
			webp: base + '-' + size + '.webp',
			png: base + '-' + size + '.png',
			anim: cfg.mascotAnim || (base + '-anim.webp')
		};
	}

	function prefersReducedMotion() {
		return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
	}

	function mascotImg(cls, size, display) {
		var u = mascotUrl(size);
		return '<img class="' + cls + '" src="' + escapeHtml(u.webp) + '" width="' + display + '" height="' + display + '" alt="" ' +
			'data-static="' + escapeHtml(u.webp) + '" data-png="' + escapeHtml(u.png) + '" data-anim="' + escapeHtml(u.anim) + '" />';
	}

	/** Poster first; swap in the animated WebP after idle unless the user prefers reduced motion. */
	function lazyLoadAnim(img) {
		if (!img) return;
		img.addEventListener('error', function () {
			if (img.dataset.png && img.src !== img.dataset.png) img.src = img.dataset.png;
		}, { once: true });
		if (prefersReducedMotion()) return;
		var url = img.getAttribute('data-anim');
		if (!url) return;
		var kick = function () {
			var pre = new Image();
			pre.onload = function () {
				img.src = url;
				img.classList.add('is-anim');
			};
			pre.src = url;
		};
		if ('requestIdleCallback' in window) requestIdleCallback(kick, { timeout: 1200 });
		else setTimeout(kick, 200);
	}

	function ensureKb() {
		if (kb) return Promise.resolve(kb);
		if (cfg.kb) {
			kb = cfg.kb;
			return Promise.resolve(kb);
		}
		var url = cfg.kbUrl;
		if (!url) return Promise.reject(new Error('no kb'));
		return fetch(url, { credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (data) { kb = data; return kb; });
	}

	function openPanel(tab) {
		if (!root) return;
		rememberFabSize();
		var fab = $('.meb-os__fab', root);
		var fabRect = fab.getBoundingClientRect();
		root.classList.add('is-open');
		setTab(tab || 'home');
		if (fabAnchor) {
			var panel = $('.meb-os__panel', root);
			var vp = viewport();
			var box = MebOsPos.placePanel({
				left: fabRect.left,
				top: fabRect.top,
				width: fabRect.width,
				height: fabRect.height,
				right: fabRect.right,
				bottom: fabRect.bottom
			}, panel.offsetWidth, panel.offsetHeight, vp.w, vp.h, VIEW_PAD);
			placement = { h: box.hAlign, v: box.vAlign };
			setRootBox(box.left, box.top);
		}
		ensureKb().catch(function () {});
	}

	function closePanel() {
		if (!root) return;
		root.classList.remove('is-open', 'is-messages');
		if (fabAnchor) applyFabAnchor();
		else clearRootBox();
	}

	function setTab(tab) {
		root.classList.toggle('is-messages', tab === 'messages');
		root.querySelectorAll('.meb-os__tab').forEach(function (b) {
			b.classList.toggle('is-active', b.getAttribute('data-tab') === tab);
		});
		root.querySelectorAll('.meb-os__pane').forEach(function (p) {
			p.hidden = p.getAttribute('data-pane') !== tab;
		});
		if (tab === 'messages') {
			var input = $('.meb-os__composer input', root);
			if (input) input.focus();
		} else {
			var q = $('.meb-os__search input', root);
			if (q) q.focus();
		}
	}

	function renderHome() {
		var chips = '';
		((kb && kb.faqs) || []).forEach(function (f) {
			if (!f.chip) return;
			var q = f.q_en || '';
			chips += '<button type="button" class="meb-os__chip" data-query="' + escapeHtml(q) + '">' +
				escapeHtml(q) + '</button>';
		});
		var policies = '';
		((kb && kb.policies) || []).forEach(function (p) {
			policies += '<a class="meb-os__policy" href="' + escapeHtml(p.url) + '" target="_blank" rel="noopener noreferrer">' +
				escapeHtml(p.title_en) + '</a>';
		});
		return '<form class="meb-os__search" data-home-search>' +
			'<input type="search" placeholder="Ask a question" aria-label="Ask a question" />' +
			'<button type="submit">Ask</button></form>' +
			'<div class="meb-os__label">Quick questions</div>' +
			'<div class="meb-os__chips">' + chips + '</div>' +
			'<div class="meb-os__label">Policies</div>' +
			'<div class="meb-os__policies">' + policies + '</div>';
	}

	function ensureWelcome() {
		var thread = $('.meb-os__thread', root);
		if (thread && !thread.childElementCount) {
			thread.appendChild(el(
				'<div class="meb-os__msg is-bot">Hi, I can look up MyEasyBuy policy clauses for you. Ask a question, or pick a shortcut on the Home tab.</div>'
			));
		}
	}

	function excerpt(text, max) {
		var t = String(text || '').trim();
		if (t.length <= max) return t;
		var cut = t.slice(0, max);
		var nl = cut.lastIndexOf('\n');
		if (nl > max * 0.5) cut = cut.slice(0, nl);
		else cut = cut.replace(/\s+\S*$/, '');
		return cut + '…';
	}

	function bodyWithoutTables(text) {
		var t = String(text || '').trim();
		if (!t) return '';
		// Drop "Header — Header" lines and "cell — cell" table lines so we don't
		// repeat a flattened table when the structured table is rendered.
		return t.split(/\n+/).filter(function (line) {
			return line.indexOf(' — ') === -1;
		}).join('\n').trim();
	}

	function renderTable(tableEn, hitRows, tableIndex) {
		var headersEn = (tableEn && tableEn.headers) || [];
		var rowsEn = (tableEn && tableEn.rows) || [];
		var n = rowsEn.length;
		if (!n && !headersEn.length) return '';
		var hitSet = {};
		(hitRows || []).forEach(function (r) {
			if (r.table === tableIndex) hitSet[r.row] = true;
		});
		// Keep the original row order (verbatim). The FAQ lead already surfaces
		// the best row first; the matching row is highlighted in place.
		var html = '<div class="meb-os__tbl">';
		if (headersEn.length) {
			html += '<div class="meb-os__tbl-head">' + escapeHtml(headersEn.join(' — ')) + '</div>';
		}
		for (var ri = 0; ri < n; ri++) {
			var en = rowsEn[ri] ? rowsEn[ri].join(' — ') : '';
			html += '<div class="meb-os__tbl-row' + (hitSet[ri] ? ' is-hit' : '') + '">';
			if (en) html += '<p>' + escapeHtml(en) + '</p>';
			html += '</div>';
		}
		html += '</div>';
		return html;
	}

	function quoteBlock(hit) {
		var clause = hit.clause || hit;
		var html = '<article class="meb-os__clause">';
		if (clause.title_en) html += '<h4>' + escapeHtml(clause.title_en) + '</h4>';
		if (hit.lead_en && (hit.faqId || (hit.matchedRows && hit.matchedRows.length))) {
			html += '<p class="meb-os__lead">' + escapeHtml(hit.lead_en) + '</p>';
		}
		var tablesEn = clause.tables_en || [];
		if (tablesEn.length) {
			for (var t = 0; t < tablesEn.length; t++) {
				html += renderTable(tablesEn[t], hit.matchedRows || [], t);
			}
			var extraEn = bodyWithoutTables(clause.text_en);
			if (extraEn) html += '<p class="meb-os__quote">' + escapeHtml(excerpt(extraEn, 360)) + '</p>';
		} else if (clause.text_en) {
			html += '<p class="meb-os__quote">' + escapeHtml(excerpt(clause.text_en, 520)) + '</p>';
		}
		html += '<a class="meb-os__full" href="' + escapeHtml(clause.url) + '" target="_blank" rel="noopener noreferrer">View full clause</a>';
		html += '</article>';
		return html;
	}

	function fallbackHtml() {
		var email = (kb && kb.contact_email) || cfg.contactEmail || 'joshuazhou406@gmail.com';
		return 'Sorry, I could not find a matching policy clause. Please email <a href="mailto:' +
			escapeHtml(email) + '">' + escapeHtml(email) + '</a>.';
	}

	function ask(query) {
		query = String(query || '').trim();
		if (!query) return;
		setTab('messages');
		ensureWelcome();
		var thread = $('.meb-os__thread', root);
		thread.appendChild(el('<div class="meb-os__msg is-user"></div>')).textContent = query;
		ensureKb().then(function (data) {
			var hits = window.MebOsSearch.search(query, data, { limit: 3 });
			var box = el('<div class="meb-os__msg is-bot"></div>');
			if (!hits.length) {
				box.innerHTML = fallbackHtml();
			} else {
				box.innerHTML = (hits.length === 1
					? 'Here is the most relevant policy clause (quoted verbatim).'
					: 'Here are the most relevant policy clauses (quoted verbatim).');
				hits.forEach(function (h) { box.insertAdjacentHTML('beforeend', quoteBlock(h)); });
			}
			thread.appendChild(box);
			var body = $('.meb-os__body', root);
			var users = thread.querySelectorAll('.meb-os__msg.is-user');
			var user = users[users.length - 1];
			if (user && body) {
				var top = user.getBoundingClientRect().top - body.getBoundingClientRect().top + body.scrollTop;
				body.scrollTop = Math.max(0, top - 8);
			} else if (body) {
				body.scrollTop = body.scrollHeight;
			}
		}).catch(function () {
			var box = el('<div class="meb-os__msg is-bot"></div>');
			box.innerHTML = fallbackHtml();
			thread.appendChild(box);
		});
	}

	function mount() {
		if (document.getElementById('meb-online-service')) return;
		if (cfg.hide) return;
		root = el(
			'<div class="meb-os" id="meb-online-service">' +
			'<button type="button" class="meb-os__fab" aria-label="Online Service">' +
			'<span class="meb-os__mascot-wrap">' + mascotImg('meb-os__mascot', 200, 112) + '</span>' +
			'<span class="meb-os__pill">Online Service</span>' +
			'</button>' +
			'<section class="meb-os__panel" role="dialog" aria-label="Online Service">' +
			'<header class="meb-os__head">' +
			'<button type="button" class="meb-os__close" aria-label="Close">&times;</button>' +
			'<p class="meb-os__hello">Hi there, welcome, how can we help?</p>' +
			mascotImg('meb-os__head-mascot', 200, 84) +
			'</header>' +
			'<div class="meb-os__body"><div class="meb-os__pane" data-pane="home"></div>' +
			'<div class="meb-os__pane" data-pane="messages" hidden><div class="meb-os__thread"></div></div></div>' +
			'<footer class="meb-os__foot">' +
			'<form class="meb-os__composer"><input type="text" placeholder="Type a question" aria-label="Type a question" /><button type="submit">Send</button></form>' +
			'<div class="meb-os__tabs">' +
			'<button type="button" class="meb-os__tab is-active" data-tab="home">Home</button>' +
			'<button type="button" class="meb-os__tab" data-tab="messages">Messages</button>' +
			'</div></footer></section></div>' 
		);
		document.body.appendChild(root);
		lazyLoadAnim(root.querySelector('.meb-os__mascot'));
		rememberFabSize();

		fabAnchor = loadAnchor();
		if (fabAnchor) applyFabAnchor();

		var fab = root.querySelector('.meb-os__fab');
		fab.setAttribute('title', 'Drag to move. Double-click to reset to the bottom right.');
		var head = root.querySelector('.meb-os__head');
		head.setAttribute('title', 'Drag to move');

		bindDrag(fab, {
			onDragEnd: function () {
				var r = root.getBoundingClientRect();
				fabAnchor = { left: r.left, top: r.top };
				saveAnchor();
			}
		});
		bindDrag(head, {
			ignore: '.meb-os__close',
			undock: true,
			onDragEnd: function () { anchorFromPanel(); }
		});

		fab.addEventListener('click', function (e) {
			if (suppressClick) {
				e.preventDefault();
				e.stopPropagation();
				return;
			}
			// Keyboard Enter/Space: open immediately (detail is 0).
			if (e.detail === 0) {
				if (openTimer) { clearTimeout(openTimer); openTimer = null; }
				openPanel('home');
				return;
			}
			if (openTimer) {
				clearTimeout(openTimer);
				openTimer = null;
				resetPosition();
				return;
			}
			openTimer = setTimeout(function () {
				openTimer = null;
				openPanel('home');
			}, 280);
		});
		fab.addEventListener('dblclick', function (e) {
			e.preventDefault();
			if (openTimer) {
				clearTimeout(openTimer);
				openTimer = null;
			}
			resetPosition();
		});
		root.querySelector('.meb-os__close').addEventListener('click', function (e) {
			if (suppressClick) {
				e.preventDefault();
				e.stopPropagation();
				return;
			}
			closePanel();
		});

		window.addEventListener('resize', function () {
			if (!root) return;
			if (root.classList.contains('is-open')) {
				if (!root.classList.contains('is-placed') && !fabAnchor) return;
				var r = root.getBoundingClientRect();
				var vp = viewport();
				var c = MebOsPos.clamp(r.left, r.top, r.width, r.height, vp.w, vp.h, VIEW_PAD);
				setRootBox(c.left, c.top);
				if (fabAnchor) {
					var sz = fabSize();
					var a = MebOsPos.clamp(fabAnchor.left, fabAnchor.top, sz.w, sz.h, vp.w, vp.h, VIEW_PAD);
					fabAnchor = { left: a.left, top: a.top };
					saveAnchor();
				}
			} else if (fabAnchor) {
				applyFabAnchor();
				saveAnchor();
			}
		});
		root.querySelectorAll('.meb-os__tab').forEach(function (b) {
			b.addEventListener('click', function () {
				setTab(b.getAttribute('data-tab'));
				if (b.getAttribute('data-tab') === 'messages') ensureWelcome();
			});
		});
		root.addEventListener('click', function (e) {
			var chip = e.target.closest('.meb-os__chip');
			if (chip) ask(chip.getAttribute('data-query'));
		});
		root.addEventListener('submit', function (e) {
			var form = e.target;
			if (form.matches('[data-home-search]')) {
				e.preventDefault();
				ask(form.querySelector('input').value);
				form.querySelector('input').value = '';
			} else if (form.matches('.meb-os__composer')) {
				e.preventDefault();
				var input = form.querySelector('input');
				ask(input.value);
				input.value = '';
			}
		});

		ensureKb().then(function () {
			$('.meb-os__pane[data-pane="home"]', root).innerHTML = renderHome();
		}).catch(function () {
			$('.meb-os__pane[data-pane="home"]', root).innerHTML = '<p class="meb-os__msg is-bot">' + fallbackHtml() + '</p>';
		});
	}

	var apiRoot = typeof window !== 'undefined' ? window : globalThis;
	apiRoot.MebOsPos = MebOsPos;
	apiRoot.MebOsWidget = {
		resetPosition: resetPosition,
		getAnchor: function () { return fabAnchor ? { left: fabAnchor.left, top: fabAnchor.top } : null; }
	};

	if (typeof document !== 'undefined') {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', mount);
		} else {
			mount();
		}
	}
})();
