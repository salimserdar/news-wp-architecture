(function () {
	function closestWidget(node) {
		return node && node.closest ? node.closest(".tr724-ads-widget") : null;
	}

	function sync(widget) {
		if (!widget) {
			return;
		}
		var select = widget.querySelector(".tr724-ads-mode");
		var mode = select ? select.value || "none" : "none";
		widget.querySelectorAll("[data-tr724-ad-mode]").forEach(function (panel) {
			panel.hidden = panel.getAttribute("data-tr724-ad-mode") !== mode;
		});
		var label = widget.querySelector("[data-tr724-ad-label]");
		if (label) {
			label.hidden = mode === "none";
		}
		var selectButton = widget.querySelector(".tr724-ads-select");
		var imageId = widget.querySelector(".tr724-ads-image-id");
		if (selectButton && imageId) {
			var hasImage = parseInt(imageId.value, 10) > 0;
			selectButton.textContent = hasImage
				? selectButton.getAttribute("data-replace") || selectButton.textContent
				: selectButton.getAttribute("data-select") || selectButton.textContent;
		}
	}

	function setImage(widget, id, url) {
		var input = widget.querySelector(".tr724-ads-image-id");
		if (input) {
			input.value = String(id || 0);
			input.dispatchEvent(new Event("change", { bubbles: true }));
		}
		var preview = widget.querySelector(".tr724-ads-preview");
		if (preview) {
			preview.replaceChildren();
			if (url) {
				var img = document.createElement("img");
				img.alt = "";
				img.src = url;
				img.style.display = "block";
				img.style.maxWidth = "100%";
				img.style.height = "auto";
				preview.appendChild(img);
			}
		}
		var remove = widget.querySelector(".tr724-ads-remove");
		if (remove) {
			remove.hidden = !id;
		}
		sync(widget);
	}

	document.addEventListener("change", function (event) {
		var select = event.target.closest && event.target.closest(".tr724-ads-mode");
		if (!select) {
			return;
		}
		sync(closestWidget(select));
	});

	document.addEventListener("click", function (event) {
		var selectButton = event.target.closest && event.target.closest(".tr724-ads-select");
		if (selectButton) {
			event.preventDefault();
			var widget = closestWidget(selectButton);
			if (!widget || !window.wp || !wp.media) {
				return;
			}
			var frame = wp.media({
				title: widget.getAttribute("data-media-title") || "Select image",
				button: {
					text: widget.getAttribute("data-media-button") || "Use image",
				},
				library: { type: "image" },
				multiple: false,
			});
			frame.on("select", function () {
				var attachment = frame.state().get("selection").first().toJSON();
				var url = attachment.url || "";
				if (attachment.sizes && attachment.sizes.medium) {
					url = attachment.sizes.medium.url;
				}
				setImage(widget, attachment.id || 0, url);
			});
			frame.open();
			return;
		}

		var removeButton = event.target.closest && event.target.closest(".tr724-ads-remove");
		if (!removeButton) {
			return;
		}
		event.preventDefault();
		var widget = closestWidget(removeButton);
		if (!widget) {
			return;
		}
		setImage(widget, 0, "");
	});
})();
