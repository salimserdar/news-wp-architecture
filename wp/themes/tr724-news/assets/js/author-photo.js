(function () {
	var row = document.querySelector(".tr724-author-photo");
	if (!row || !window.wp || !wp.media) {
		return;
	}

	var input = row.querySelector(".tr724-author-photo-id");
	var preview = row.querySelector(".tr724-author-photo-preview");
	var selectButton = row.querySelector(".tr724-author-photo-select");
	var removeButton = row.querySelector(".tr724-author-photo-remove");
	if (!input || !preview || !selectButton || !removeButton) {
		return;
	}

	function setPhoto(id, url) {
		input.value = String(id || 0);
		preview.replaceChildren();
		if (url) {
			var img = document.createElement("img");
			img.alt = "";
			img.src = url;
			img.style.display = "block";
			img.style.maxWidth = "96px";
			img.style.height = "auto";
			preview.appendChild(img);
		}
		removeButton.hidden = !id;
		selectButton.textContent = id
			? selectButton.getAttribute("data-replace") || selectButton.textContent
			: selectButton.getAttribute("data-select") || selectButton.textContent;
	}

	selectButton.addEventListener("click", function (event) {
		event.preventDefault();
		var frame = wp.media({
			title: selectButton.getAttribute("data-select") || "Fotoğraf seç",
			button: {
				text: selectButton.getAttribute("data-replace") || "Fotoğrafı değiştir",
			},
			library: { type: "image" },
			multiple: false,
		});
		frame.on("select", function () {
			var attachment = frame.state().get("selection").first().toJSON();
			var thumb = attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url;
			setPhoto(attachment.id, thumb || attachment.url || "");
		});
		frame.open();
	});

	removeButton.addEventListener("click", function (event) {
		event.preventDefault();
		setPhoto(0, "");
	});
})();
