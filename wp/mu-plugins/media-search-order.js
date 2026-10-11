(function () {
	var Attachments = window.wp && wp.media && wp.media.model && wp.media.model.Attachments;
	if (Attachments && typeof Attachments.comparator === 'function') {
		var original = Attachments.comparator;

		Attachments.comparator = function (a, b, options) {
			if (this.args && this.args.s) {
				return 0;
			}

			if (this.props && this.props.get('search') && this.mirroring) {
				var left = this.mirroring.indexOf(a);
				var right = this.mirroring.indexOf(b);
				if (left !== -1 && right !== -1) {
					return left - right;
				}
			}

			return original.call(this, a, b, options);
		};
	}

	var Attachment = window.wp && wp.media && wp.media.view && wp.media.view.Attachment;
	if (!Attachment || typeof Attachment.prototype.imageSize !== 'function') {
		return;
	}

	var originalSize = Attachment.prototype.imageSize;

	Attachment.prototype.imageSize = function (size) {
		if (libraryIsSearching(this)) {
			var full = originalFile(this.model);
			if (full) {
				return full;
			}
		}
		return originalSize.call(this, size);
	};

	function libraryIsSearching(view) {
		var search = searchOf(view.collection);
		if (search) {
			return true;
		}
		if (!view.controller || typeof view.controller.state !== 'function') {
			return false;
		}
		var state = view.controller.state();
		if (!state || typeof state.get !== 'function') {
			return false;
		}
		return '' !== searchOf(state.get('library'));
	}

	function searchOf(collection) {
		if (!collection || !collection.props || typeof collection.props.get !== 'function') {
			return '';
		}
		var search = collection.props.get('search');
		return 'string' === typeof search ? search.trim() : '';
	}

	function originalFile(model) {
		if (!model || typeof model.get !== 'function') {
			return null;
		}
		var sizes = model.get('sizes');
		var full = sizes && sizes.full;
		if (full && full.url) {
			return {
				url: full.url,
				width: full.width,
				height: full.height,
				orientation: full.orientation
			};
		}
		var url = model.get('url');
		if (!url) {
			return null;
		}
		return {
			url: url,
			width: model.get('width'),
			height: model.get('height'),
			orientation: model.get('orientation')
		};
	}
})();
