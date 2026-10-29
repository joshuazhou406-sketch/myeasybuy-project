(function ($) {
	'use strict';

	function refreshPage() {
		var $btn = $('#myeasybuy-monitor-refresh');
		if ($btn.length) {
			$btn.prop('disabled', true).text(
				(window.myeasybuyMonitor && myeasybuyMonitor.refreshing) || 'Refreshing…'
			);
		}
		window.location.reload();
	}

	$(document).on('click', '#myeasybuy-monitor-refresh', function (e) {
		e.preventDefault();
		refreshPage();
	});

	// Optional auto-refresh while the monitor tab stays open.
	if (
		window.myeasybuyMonitor &&
		myeasybuyMonitor.autoRefresh &&
		myeasybuyMonitor.intervalMs
	) {
		window.setInterval(function () {
			// Only auto-refresh when the tab is visible.
			if (!document.hidden) {
				refreshPage();
			}
		}, parseInt(myeasybuyMonitor.intervalMs, 10) || 60000);
	}
})(jQuery);
