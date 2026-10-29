(function ($) {
	'use strict';

	function dismissAll() {
		var $modal = $('#myeasybuy-inbound-modal');
		if (!$modal.length) {
			return;
		}
		$modal.addClass('is-hidden');

		var cfg = window.myeasybuyInbound || {};
		$.post(cfg.ajaxUrl, {
			action: 'myeasybuy_dismiss_inbound_alert',
			nonce: cfg.nonce,
			order_id: 0
		});
	}

	$(document).on('click', '#myeasybuy-inbound-modal [data-dismiss-all]', function (e) {
		e.preventDefault();
		dismissAll();
	});

	$(document).on('keydown', function (e) {
		if (e.key === 'Escape') {
			dismissAll();
		}
	});
})(jQuery);
