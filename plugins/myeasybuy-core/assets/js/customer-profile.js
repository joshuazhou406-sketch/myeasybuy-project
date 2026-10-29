(function () {
	var input = document.querySelector("[data-meb-date]");
	if (input) {
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
	}

	document.querySelectorAll("[data-meb-confirm]").forEach(function (button) {
		button.addEventListener("click", function (event) {
			if (!window.confirm(button.getAttribute("data-meb-confirm") || "")) {
				event.preventDefault();
			}
		});
	});

	document.querySelectorAll(".meb-acct select").forEach(function (select) {
		select.addEventListener("change", function () {
			select.classList.toggle("has-value", select.value !== "");
		});
	});
})();
