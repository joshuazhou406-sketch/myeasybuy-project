(function () {
	var root = document.querySelector(".meb-cart");
	if (!root) {
		return;
	}

	function clamp(input, value) {
		var min = parseInt(input.getAttribute("min"), 10);
		var maxAttr = input.getAttribute("max");
		var max = maxAttr ? parseInt(maxAttr, 10) : Infinity;
		if (isNaN(min)) {
			min = 1;
		}
		if (value < min) {
			value = min;
		}
		if (value > max) {
			value = max;
		}
		return value;
	}

	function submitQty(input) {
		var form = input.form;
		if (!form || form.dataset.mebSubmitting === "1") {
			return;
		}
		form.dataset.mebSubmitting = "1";
		form.submit();
	}

	root.addEventListener("click", function (event) {
		var btn = event.target.closest("[data-meb-qty]");
		if (!btn) {
			return;
		}
		var wrap = btn.closest(".meb-cart__qty");
		var input = wrap && wrap.querySelector("input");
		if (!input) {
			return;
		}
		var dir = parseInt(btn.getAttribute("data-meb-qty"), 10) || 0;
		var next = clamp(input, (parseInt(input.value, 10) || 1) + dir);
		if (String(next) === input.value) {
			return;
		}
		input.value = String(next);
		submitQty(input);
	});

	root.addEventListener("change", function (event) {
		var input = event.target;
		if (!input.matches || !input.matches(".meb-cart__qty input")) {
			return;
		}
		input.value = String(clamp(input, parseInt(input.value, 10) || 1));
		submitQty(input);
	});
})();
