(function () {
	var boxes = document.querySelectorAll(".meb-msg__box");
	var all = document.querySelector("[data-meb-msg-all]");
	var read = document.querySelector("[data-meb-msg-read]");
	var remove = document.querySelector("[data-meb-msg-delete]");

	function selected() {
		return Array.prototype.filter.call(boxes, function (box) {
			return box.checked;
		});
	}

	function sync() {
		var count = selected().length;
		if (read) {
			read.disabled = count === 0;
		}
		if (remove) {
			remove.disabled = count === 0;
		}
		if (all) {
			all.checked = boxes.length > 0 && count === boxes.length;
			all.indeterminate = count > 0 && count < boxes.length;
		}
	}

	if (all) {
		all.addEventListener("change", function () {
			boxes.forEach(function (box) {
				box.checked = all.checked;
			});
			sync();
		});
	}

	boxes.forEach(function (box) {
		box.addEventListener("change", sync);
	});

	if (remove) {
		remove.addEventListener("click", function (event) {
			if (remove.disabled) {
				event.preventDefault();
				return;
			}
			if (!window.confirm("确定删除选中的消息吗？")) {
				event.preventDefault();
			}
		});
	}

	var jump = document.querySelector("[data-meb-msg-jump]");
	if (jump) {
		jump.addEventListener("submit", function (event) {
			event.preventDefault();
			var input = jump.querySelector("input");
			var page = input ? parseInt(input.value, 10) : 0;
			var pages = parseInt(jump.getAttribute("data-meb-msg-pages") || "1", 10);
			if (!page || page < 1) {
				page = 1;
			}
			if (page > pages) {
				page = pages;
			}
			var url = jump.getAttribute("data-meb-msg-jump") || "";
			if (page <= 1) {
				url = url.replace(/\/9999\/?/, "/");
			} else {
				url = url.replace("9999", String(page));
			}
			window.location.href = url;
		});
	}

	sync();
})();
