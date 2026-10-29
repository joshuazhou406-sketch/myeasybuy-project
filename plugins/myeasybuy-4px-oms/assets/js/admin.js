(function ($) {
	'use strict';

	$(function () {
		$('.meb-oms-actions form').on('submit', function () {
			return window.confirm('确认向 4PX OMS 提交该指令？');
		});
	});
})(jQuery);
