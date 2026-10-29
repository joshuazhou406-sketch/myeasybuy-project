(function () {
	var dates = document.querySelectorAll("[data-meb-date]");
	dates.forEach(function (input) {
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

		input.addEventListener("change", function () {
			if (input.form) {
				input.form.requestSubmit();
			}
		});

		if (input.value) {
			input.type = "date";
		}
	});

	var filter = document.querySelector("[data-meb-wallet-filter]");
	if (filter) {
		filter.addEventListener("change", function () {
			filter.classList.toggle("has-value", filter.value !== "");
			if (filter.form) {
				filter.form.requestSubmit();
			}
		});
	}

	function dialogByName(name) {
		return document.querySelector('[data-meb-wallet-dialog="' + name + '"]');
	}

	function openDialog(name) {
		var dialog = dialogByName(name);
		if (!dialog) {
			return;
		}
		dialog.hidden = false;
		dialog.classList.add("is-open");
		var field = dialog.querySelector("input[name='amount']");
		if (field) {
			field.focus();
		}
	}

	function closeDialog(dialog) {
		dialog.classList.remove("is-open");
		dialog.hidden = true;
	}

	document.querySelectorAll("[data-meb-wallet-open]").forEach(function (button) {
		button.addEventListener("click", function () {
			openDialog(button.getAttribute("data-meb-wallet-open"));
		});
	});

	document.querySelectorAll("[data-meb-wallet-close]").forEach(function (button) {
		button.addEventListener("click", function () {
			var dialog = button.closest("[data-meb-wallet-dialog]");
			if (dialog) {
				closeDialog(dialog);
			}
		});
	});

	document.querySelectorAll("[data-meb-wallet-dialog]").forEach(function (dialog) {
		dialog.addEventListener("click", function (event) {
			if (event.target === dialog) {
				closeDialog(dialog);
			}
		});

		var form = dialog.querySelector("form");
		if (form) {
			form.addEventListener("submit", function () {
				var submit = form.querySelector("[type='submit']");
				if (submit) {
					submit.disabled = true;
				}
			});
		}
	});

	document.addEventListener("keydown", function (event) {
		if (event.key !== "Escape") {
			return;
		}
		document.querySelectorAll("[data-meb-wallet-dialog].is-open").forEach(closeDialog);
	});

	document.querySelectorAll("[data-meb-wallet-dialog].is-open input[name='amount']").forEach(function (field) {
		field.focus();
	});
})();
