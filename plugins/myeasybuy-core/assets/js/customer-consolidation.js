(function ($) {
	'use strict';

	function updateCount() {
		var n = $('.myeasybuy-package-check:checked').length;
		$('#myeasybuy-selected-count').text(n);
		$('#myeasybuy-select-all').prop(
			'checked',
			n > 0 && n === $('.myeasybuy-package-check').length
		);
	}

	$(document).on('change', '.myeasybuy-package-check', updateCount);

	$(document).on('change', '#myeasybuy-select-all', function () {
		$('.myeasybuy-package-check').prop('checked', this.checked);
		updateCount();
	});

	$(document).on('submit', '#myeasybuy-consolidation-form', function (e) {
		var selected = $('.myeasybuy-package-check:checked').length;
		var channel = $('input[name="channel"]:checked').val();
		var i18n = window.myeasybuyConsolidation || {};

		if (!selected) {
			e.preventDefault();
			window.alert(i18n.needSelect || 'Please select packages.');
			return;
		}

		if (!channel) {
			e.preventDefault();
			window.alert(i18n.needChannel || 'Please select a channel.');
			return;
		}

		var tpl = i18n.confirmTpl || 'Submit %d packages?';
		if (!window.confirm(tpl.replace('%d', String(selected)))) {
			e.preventDefault();
		}
	});

	updateCount();
})(jQuery);
