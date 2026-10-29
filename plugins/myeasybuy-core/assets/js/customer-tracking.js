(function ($) {
	'use strict';

	var cfg = window.myeasybuyTracking || {};

	function $panel() {
		return $('#myeasybuy-int-tracking');
	}

	function setBusy($btn, busy) {
		if (!$btn.length) {
			return;
		}
		$btn.prop('disabled', busy).toggleClass('is-busy', busy);
	}

	function applyResult($root, data) {
		if (!data) {
			return;
		}

		if (data.latestHtml) {
			$root.find('.myeasybuy-tracking-latest').html(data.latestHtml);
		}
		if (data.timelineHtml) {
			$root.find('.myeasybuy-tracking-timeline').html(data.timelineHtml);
		}
		if (data.fetchedLabel) {
			$root.find('.myeasybuy-tracking-fetched').text(data.fetchedLabel);
		}

		var payload = data.payload || {};
		var $err = $root.find('.myeasybuy-tracking-error');
		if (payload.error) {
			if (!$err.length) {
				$err = $('<p class="myeasybuy-tracking-error"></p>');
				$root.find('.myeasybuy-tracking-toolbar').after($err);
			}
			$err.text(payload.error).show();
		} else {
			$err.remove();
		}

		$root.attr('data-needs-refresh', '0');
	}

	function refresh($root, $btn) {
		var orderId = $root.data('order-id');
		if (!orderId || !cfg.ajaxUrl) {
			return;
		}

		setBusy($btn, true);
		$root.find('.myeasybuy-tracking-fetched').text(cfg.i18n && cfg.i18n.refreshing ? cfg.i18n.refreshing : '');

		$.post(cfg.ajaxUrl, {
			action: 'myeasybuy_refresh_tracking',
			nonce: cfg.nonce,
			order_id: orderId
		})
			.done(function (res) {
				if (res && res.success) {
					applyResult($root, res.data);
					return;
				}
				var message = res && res.data && res.data.message ? res.data.message : '';
				window.alert(message);
			})
			.fail(function (xhr) {
				var message = '';
				if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
					message = xhr.responseJSON.data.message;
				}
				if (message) {
					window.alert(message);
				}
			})
			.always(function () {
				setBusy($btn, false);
			});
	}

	$(document).on('click', '.myeasybuy-tracking-refresh', function (e) {
		e.preventDefault();
		var $root = $(this).closest('#myeasybuy-int-tracking');
		refresh($root, $(this));
	});

	$(document).on('click', '.myeasybuy-tracking-copy', function (e) {
		e.preventDefault();
		var $btn = $(this);
		var value = String($btn.data('copy') || '');
		if (!value) {
			return;
		}

		var done = function () {
			var original = $btn.text();
			$btn.addClass('is-copied').text(cfg.i18n && cfg.i18n.copied ? cfg.i18n.copied : original);
			window.setTimeout(function () {
				$btn.removeClass('is-copied').text(original);
			}, 1600);
		};

		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(value).then(done).catch(function () {
				window.alert(cfg.i18n && cfg.i18n.copyFail ? cfg.i18n.copyFail : '');
			});
			return;
		}

		window.prompt('', value);
	});

	$(function () {
		var $root = $panel();
		if ($root.length && String($root.attr('data-needs-refresh')) === '1') {
			refresh($root, $root.find('.myeasybuy-tracking-refresh'));
		}
	});
})(jQuery);
