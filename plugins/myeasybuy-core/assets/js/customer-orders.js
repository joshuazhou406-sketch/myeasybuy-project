(function () {
	var inputs = document.querySelectorAll("[data-meb-date]");
	if (!inputs.length) {
		return;
	}

	inputs.forEach(function (input) {
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
})();
