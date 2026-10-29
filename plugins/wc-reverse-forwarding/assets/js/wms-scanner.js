(function () {
	'use strict';

	var cfg = window.wcRfWms || {};
	var i18n = cfg.i18n || {};
	var form = document.getElementById('wc-rf-wms-form');
	if (!form) {
		return;
	}

	var tracking = document.getElementById('wc-rf-wms-tracking');
	var nameInput = document.getElementById('wc-rf-wms-name');
	var companyInput = document.getElementById('wc-rf-wms-company');
	var weight = document.getElementById('wc-rf-wms-weight');
	var shelf = document.getElementById('wc-rf-wms-shelf');
	var length = document.getElementById('wc-rf-wms-length');
	var width = document.getElementById('wc-rf-wms-width');
	var height = document.getElementById('wc-rf-wms-height');
	var photos = document.getElementById('wc-rf-wms-photos');
	var unclaimedRow = document.getElementById('wc-rf-wms-unclaimed-row');
	var unclaimed = document.getElementById('wc-rf-wms-unclaimed');
	var owner = document.getElementById('wc-rf-wms-owner');
	var toast = document.getElementById('wc-rf-wms-toast');
	var recent = document.getElementById('wc-rf-wms-recent');
	var submit = document.getElementById('wc-rf-wms-submit');
	var looking = false;
	var locked = false;

	function focusTracking() {
		window.setTimeout(function () {
			if (tracking) {
				tracking.focus();
				tracking.select();
			}
		}, 30);
	}

	function beep(ok) {
		try {
			var ctx = new (window.AudioContext || window.webkitAudioContext)();
			var osc = ctx.createOscillator();
			var gain = ctx.createGain();
			osc.type = 'sine';
			osc.frequency.value = ok ? 880 : 220;
			gain.gain.value = 0.08;
			osc.connect(gain);
			gain.connect(ctx.destination);
			osc.start();
			window.setTimeout(function () {
				osc.stop();
				ctx.close();
			}, ok ? 140 : 280);
		} catch (e) {
			// Ignore autoplay / missing Web Audio.
		}
	}

	function showToast(message, type) {
		if (!toast) {
			return;
		}
		toast.hidden = false;
		toast.className = 'wc-rf-wms-toast is-' + (type || 'info');
		toast.textContent = message;
		window.setTimeout(function () {
			toast.hidden = true;
		}, 3200);
	}

	function setOwner(payload) {
		var body = owner.querySelector('.wc-rf-wms-owner__body');
		var hint = owner.querySelector('.wc-rf-wms-owner__hint');
		if (!payload || !payload.found) {
			owner.className = 'wc-rf-wms-owner is-unclaimed';
			if (hint) {
				hint.hidden = false;
				hint.textContent = (payload && payload.message) || i18n.unclaimed;
			}
			if (body) {
				body.hidden = true;
			}
			if (unclaimedRow) {
				unclaimedRow.hidden = false;
			}
			if (unclaimed) {
				unclaimed.checked = true;
			}
			locked = false;
			return;
		}

		var pkg = payload.package || {};
		owner.className = payload.locked ? 'wc-rf-wms-owner is-locked' : 'wc-rf-wms-owner is-found';
		if (hint) {
			hint.hidden = true;
		}
		if (body) {
			body.hidden = false;
			body.querySelector('[data-wms-code]').textContent = pkg.warehouse_code || '';
			body.querySelector('[data-wms-user]').textContent = pkg.user_label || '';
			body.querySelector('[data-wms-email]').textContent = pkg.user_email || '';
			body.querySelector('[data-wms-name]').textContent = pkg.declared_name || '';
			body.querySelector('[data-wms-company]').textContent = pkg.express_company || '';
		}
		nameInput.value = pkg.declared_name || '';
		companyInput.value = pkg.express_company || '';
		if (pkg.weight) {
			weight.value = pkg.weight;
		}
		if (pkg.shelf_location) {
			shelf.value = pkg.shelf_location;
		}
		if (pkg.length) {
			length.value = pkg.length;
		}
		if (pkg.width) {
			width.value = pkg.width;
		}
		if (pkg.height) {
			height.value = pkg.height;
		}
		if (unclaimedRow) {
			unclaimedRow.hidden = true;
		}
		if (unclaimed) {
			unclaimed.checked = false;
		}
		locked = !!payload.locked;
		if (locked) {
			showToast(i18n.locked, 'error');
			beep(false);
		}
	}

	function resetForm(keepToast) {
		form.reset();
		locked = false;
		if (unclaimedRow) {
			unclaimedRow.hidden = true;
		}
		owner.className = 'wc-rf-wms-owner is-empty';
		var body = owner.querySelector('.wc-rf-wms-owner__body');
		var hint = owner.querySelector('.wc-rf-wms-owner__hint');
		if (body) {
			body.hidden = true;
		}
		if (hint) {
			hint.hidden = false;
			hint.textContent = '扫描后显示归属会员与申报品名。';
		}
		if (!keepToast) {
			focusTracking();
		}
	}

	function prependRecent(pkg) {
		if (!recent || !pkg) {
			return;
		}
		var empty = recent.querySelector('.wc-rf-wms-recent__empty');
		if (empty) {
			empty.remove();
		}
		var li = document.createElement('li');
		var code = pkg.warehouse_code || (pkg.unclaimed ? '无主' : '');
		li.innerHTML = '<strong></strong><span></span><span></span>';
		li.querySelector('strong').textContent = pkg.tracking_number || '';
		li.querySelectorAll('span')[0].textContent = (code ? code + ' · ' : '') + (pkg.declared_name || '');
		li.querySelectorAll('span')[1].textContent = (pkg.weight || 0) + ' kg · ' + (pkg.shelf_location || '');
		recent.insertBefore(li, recent.firstChild);
	}

	function request(url, body, isForm) {
		if (cfg.restLookup && cfg.restNonce && url.indexOf('/wp-json/') !== -1) {
			return fetch(url, {
				method: 'POST',
				credentials: 'same-origin',
				headers: isForm ? { 'X-WP-Nonce': cfg.restNonce } : {
					'X-WP-Nonce': cfg.restNonce,
					'Content-Type': 'application/json'
				},
				body: isForm ? body : JSON.stringify(body)
			}).then(function (res) {
				return res.json().then(function (json) {
					if (!res.ok) {
						throw new Error((json && json.message) || i18n.receiveFail);
					}
					return json;
				});
			});
		}

		var data = isForm ? body : new URLSearchParams(body);
		if (isForm) {
			data.append('action', body._ajaxAction || cfg.ajaxReceive);
			data.append('nonce', cfg.ajaxNonce);
		} else {
			data.set('action', body.action);
			data.set('nonce', cfg.ajaxNonce);
		}

		return fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: data
		}).then(function (res) {
			return res.json();
		});
	}

	function lookup() {
		var value = (tracking.value || '').trim();
		if (!value || looking) {
			return;
		}
		looking = true;
		request(cfg.restLookup, { tracking_number: value }, false)
			.catch(function () {
				return request(cfg.ajaxUrl, {
					action: 'wc_rf_wms_lookup',
					tracking_number: value
				}, false);
			})
			.then(function (payload) {
				looking = false;
				if (payload && payload.data && payload.success === false) {
					showToast(payload.data.message || i18n.lookupFail, 'error');
					beep(false);
					focusTracking();
					return;
				}
				setOwner(payload);
				if (payload && payload.found && !payload.locked) {
					showToast(i18n.found, 'success');
					weight.focus();
					weight.select();
					return;
				}
				if (payload && payload.unclaimed) {
					showToast(i18n.unclaimed, 'info');
					nameInput.focus();
				}
			})
			.catch(function () {
				looking = false;
				showToast(i18n.lookupFail, 'error');
				beep(false);
				focusTracking();
			});
	}

	function receive(event) {
		event.preventDefault();
		if (locked) {
			showToast(i18n.locked, 'error');
			focusTracking();
			return;
		}
		if (!(tracking.value || '').trim()) {
			showToast(i18n.needTracking, 'error');
			focusTracking();
			return;
		}
		if (!weight.value || parseFloat(weight.value) <= 0) {
			showToast(i18n.needWeight, 'error');
			weight.focus();
			return;
		}

		submit.disabled = true;
		var data = new FormData(form);
		if (unclaimed && unclaimed.checked) {
			data.set('allow_unclaimed', '1');
		}

		var restBody = data;
		fetch(cfg.restReceive, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.restNonce },
			body: restBody
		})
			.then(function (res) {
				return res.json().then(function (json) {
					return { ok: res.ok, json: json };
				});
			})
			.then(function (result) {
				if (result.ok && result.json && result.json.package) {
					return result.json;
				}
				data.append('action', 'wc_rf_wms_receive');
				data.append('nonce', cfg.ajaxNonce);
				return fetch(cfg.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					body: data
				}).then(function (res) {
					return res.json();
				}).then(function (json) {
					if (json && json.success && json.data && json.data.package) {
						return { package: json.data.package };
					}
					throw new Error((json && json.data && json.data.message) || (json && json.message) || i18n.receiveFail);
				});
			})
			.then(function (json) {
				submit.disabled = false;
				var pkg = json.package || (json.data && json.data.package);
				showToast(i18n.stored, 'success');
				beep(true);
				prependRecent(pkg);
				resetForm(true);
				focusTracking();
			})
			.catch(function (err) {
				submit.disabled = false;
				showToast(err.message || i18n.receiveFail, 'error');
				beep(false);
				focusTracking();
			});
	}

	tracking.addEventListener('keydown', function (event) {
		if (event.key === 'Enter') {
			event.preventDefault();
			lookup();
		}
	});

	tracking.addEventListener('blur', function () {
		if ((tracking.value || '').trim()) {
			lookup();
		}
	});

	form.addEventListener('submit', receive);

	window.addEventListener('load', focusTracking);
	focusTracking();
})();
