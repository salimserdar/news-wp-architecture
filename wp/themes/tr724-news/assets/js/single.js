(function () {
	document.querySelectorAll("[data-post-print]").forEach(function (button) {
		button.addEventListener("click", function () {
			window.print();
		});
	});

	var dialog = document.getElementById("lightbox");
	if (!dialog) return;

	var dataEl = document.getElementById("post-gallery-data");
	if (!dataEl) return;

	var shots = [];
	try {
		shots = JSON.parse(dataEl.textContent || "[]");
	} catch (error) {
		return;
	}
	if (!shots.length) return;

	var image = dialog.querySelector(".lightbox__image");
	var caption = dialog.querySelector(".lightbox__caption");
	var index = 0;

	function show(next) {
		index = (next + shots.length) % shots.length;
		var shot = shots[index] || {};
		image.src = shot.src || "";
		image.alt = shot.alt || "";
		caption.textContent =
			index + 1 + " / " + shots.length + " — " + (shot.alt || "");
	}

	function openAt(next) {
		show(next);
		if (typeof dialog.showModal === "function") dialog.showModal();
		else dialog.setAttribute("open", "");
	}

	document.querySelectorAll("[data-gallery]").forEach(function (button) {
		button.addEventListener("click", function () {
			openAt(Number(button.getAttribute("data-gallery")));
		});
	});

	dialog.querySelectorAll("[data-lightbox-step]").forEach(function (button) {
		button.addEventListener("click", function () {
			show(index + Number(button.getAttribute("data-lightbox-step")));
		});
	});

	var closeButton = dialog.querySelector("[data-lightbox-close]");
	if (closeButton) {
		closeButton.addEventListener("click", function () {
			if (typeof dialog.close === "function") dialog.close();
			else dialog.removeAttribute("open");
		});
	}
})();
