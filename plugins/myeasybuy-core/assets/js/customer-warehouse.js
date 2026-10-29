(function () {
	var root = document.querySelector(".meb-wh");
	if (!root) {
		return;
	}

	var inputs = root.querySelectorAll("[data-meb-date]");
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

	var helpBtn = root.querySelector("[data-meb-help]");
	var help = root.querySelector("[data-meb-help-panel]");
	if (helpBtn && help) {
		helpBtn.addEventListener("click", function () {
			var open = help.hasAttribute("hidden");
			if (open) {
				help.removeAttribute("hidden");
			} else {
				help.setAttribute("hidden", "");
			}
			helpBtn.setAttribute("aria-expanded", open ? "true" : "false");
		});
	}

	var all = root.querySelector("[data-meb-all]");
	var hint = root.querySelector("[data-meb-hint]");
	var blockHint = root.getAttribute("data-block-hint") || "请先选择商品";

	function boxes() {
		return Array.prototype.slice.call(root.querySelectorAll(".meb-wh__check"));
	}

	function selected() {
		return boxes().filter(function (box) {
			return box.checked;
		});
	}

	function syncAll() {
		if (!all || all.disabled) {
			return;
		}
		var list = boxes();
		var count = selected().length;
		all.checked = list.length > 0 && count === list.length;
		all.indeterminate = count > 0 && count < list.length;
	}

	if (all && !all.disabled) {
		all.addEventListener("change", function () {
			boxes().forEach(function (box) {
				box.checked = all.checked;
			});
			syncAll();
			showHint("");
		});
	}

	root.addEventListener("change", function (event) {
		if (event.target.classList && event.target.classList.contains("meb-wh__check")) {
			syncAll();
			showHint("");
		}
	});

	function showHint(text) {
		if (!hint) {
			return;
		}
		hint.textContent = text;
		if (text) {
			hint.removeAttribute("hidden");
		} else {
			hint.setAttribute("hidden", "");
		}
	}

	var createDialog = root.querySelector("[data-meb-create-dialog]");

	function fillCreateIds(chosen) {
		if (!createDialog) {
			return;
		}
		var slot = createDialog.querySelector("[data-meb-create-ids]");
		var count = createDialog.querySelector("[data-meb-create-count]");
		if (slot) {
			slot.textContent = "";
			chosen.forEach(function (box) {
				var input = document.createElement("input");
				input.type = "hidden";
				input.name = "order_ids[]";
				input.value = box.value;
				slot.appendChild(input);
			});
		}
		if (count) {
			count.textContent = String(chosen.length);
		}
	}

	function openCreate() {
		var chosen = selected();
		if (!chosen.length) {
			showHint(blockHint);
			return;
		}
		if (!createDialog) {
			showHint(blockHint);
			return;
		}
		showHint("");
		var preview = root.querySelector("[data-meb-preview]");
		if (preview) {
			preview.hidden = true;
		}
		fillCreateIds(chosen);
		createDialog.hidden = false;
	}

	function closeCreate() {
		if (createDialog) {
			createDialog.hidden = true;
		}
	}

	root.querySelectorAll("[data-meb-create]").forEach(function (button) {
		button.addEventListener("click", openCreate);
	});

	if (createDialog) {
		createDialog.querySelectorAll("[data-meb-create-close]").forEach(function (button) {
			button.addEventListener("click", closeCreate);
		});
		createDialog.addEventListener("click", function (event) {
			if (event.target === createDialog) {
				closeCreate();
			}
		});
		var createForm = createDialog.querySelector("[data-meb-create-form]");
		if (createForm) {
			createForm.addEventListener("submit", function (event) {
				var chosen = selected();
				if (!chosen.length) {
					event.preventDefault();
					closeCreate();
					showHint(blockHint);
					return;
				}
				fillCreateIds(chosen);
				var submit = createForm.querySelector("[type='submit']");
				if (submit) {
					submit.disabled = true;
				}
			});
		}
	}

	if (root.getAttribute("data-open-create") === "1") {
		openCreate();
	}

	var rehearsalForm = root.querySelector("[data-meb-rehearsal-form]");
	if (rehearsalForm) {
		rehearsalForm.addEventListener("submit", function (event) {
			var chosen = selected();
			if (!chosen.length) {
				event.preventDefault();
				showHint(blockHint);
				return;
			}
			var slot = rehearsalForm.querySelector("[data-meb-rehearsal-ids]");
			slot.textContent = "";
			chosen.forEach(function (box) {
				var input = document.createElement("input");
				input.type = "hidden";
				input.name = "order_ids[]";
				input.value = box.value;
				slot.appendChild(input);
			});
		});
	}

	var preview = root.querySelector("[data-meb-preview]");
	var openPreview = root.querySelector("[data-meb-preview-open]");
	if (openPreview && preview) {
		openPreview.addEventListener("click", function () {
			var chosen = selected();
			if (!chosen.length) {
				showHint(blockHint);
				return;
			}
			showHint("");
			var qty = 0;
			var weight = 0;
			var missing = false;
			chosen.forEach(function (box) {
				qty += parseInt(box.getAttribute("data-qty") || "0", 10) || 0;
				var raw = box.getAttribute("data-weight") || "";
				if (!raw) {
					missing = true;
				} else {
					weight += parseInt(raw, 10) || 0;
				}
			});
			preview.querySelector("[data-meb-preview-count]").textContent = String(chosen.length);
			preview.querySelector("[data-meb-preview-qty]").textContent = String(qty);
			var weightText = weight > 0 ? weight + " g" : "待称重";
			if (weight > 0 && missing) {
				weightText += "（部分商品未称重）";
			}
			preview.querySelector("[data-meb-preview-weight]").textContent = weightText;
			preview.hidden = false;
		});

		preview.querySelectorAll("[data-meb-preview-close]").forEach(function (button) {
			button.addEventListener("click", function () {
				preview.hidden = true;
			});
		});

		preview.addEventListener("click", function (event) {
			if (event.target === preview) {
				preview.hidden = true;
			}
		});

		document.addEventListener("keydown", function (event) {
			if (event.key !== "Escape") {
				return;
			}
			if (!preview.hidden) {
				preview.hidden = true;
			}
			closeCreate();
		});
	}

	var per = root.querySelector("[data-meb-per]");
	if (per) {
		per.addEventListener("change", function () {
			var url = new URL(per.getAttribute("data-base"), window.location.href);
			if (per.value === "50") {
				url.searchParams.delete("meb_per");
			} else {
				url.searchParams.set("meb_per", per.value);
			}
			window.location.href = url.toString();
		});
	}
})();
