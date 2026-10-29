(function () {
	var root = document.querySelector(".meb-rh");
	if (!root) {
		return;
	}

	root.querySelectorAll("[data-meb-date]").forEach(function (input) {
		input.addEventListener("focus", function () {
			input.type = "date";
			if (typeof input.showPicker === "function") {
				try {
					input.showPicker();
				} catch (error) {
					/* Some browsers block showPicker outside a direct gesture. */
				}
			}
		});

		input.addEventListener("blur", function () {
			if (!input.value) {
				input.type = "text";
			}
		});

		if (input.value) {
			input.type = "date";
		}
	});

	var guideBtn = root.querySelector("[data-meb-guide]");
	var guide = root.querySelector("[data-meb-guide-panel]");
	if (guideBtn && guide) {
		guideBtn.addEventListener("click", function () {
			var open = guide.hasAttribute("hidden");
			if (open) {
				guide.removeAttribute("hidden");
			} else {
				guide.setAttribute("hidden", "");
			}
			guideBtn.setAttribute("aria-expanded", open ? "true" : "false");
		});
	}

	root.querySelectorAll("[data-meb-cancel]").forEach(function (form) {
		form.addEventListener("submit", function (event) {
			if (!window.confirm("确定取消这份包裹预演吗？")) {
				event.preventDefault();
			}
		});
	});
})();
