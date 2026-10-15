(function () {
	document.addEventListener('drop', noteImageUse, true);
	document.addEventListener('paste', noteImageUse, true);
	document.addEventListener('change', noteFileInput, true);

	if (window.wp && wp.Uploader && wp.Uploader.prototype) {
		var originalInit = wp.Uploader.prototype.init;
		wp.Uploader.prototype.init = function () {
			if (typeof originalInit === 'function') {
				originalInit.apply(this, arguments);
			}
			if (!this.uploader || this.uploader._newsImageUseBound) {
				return;
			}
			this.uploader._newsImageUseBound = true;
			this.uploader.bind('BeforeUpload', function (up) {
				var params = up.settings.multipart_params || {};
				params.news_image_use = imageUse();
				up.settings.multipart_params = params;
			});
		};
	}

	if (window.wp && wp.apiFetch && typeof wp.apiFetch.use === 'function') {
		wp.apiFetch.use(function (options, next) {
			if (isMediaCreate(options) && !options.body.has('news_image_use')) {
				options.body.append('news_image_use', imageUse());
			}
			return next(options);
		});
	}

	function noteFileInput(event) {
		var input = event.target;
		if (!input || input.type !== 'file') {
			return;
		}
		noteImageUse(event);
	}

	function noteImageUse(event) {
		var target = event.target;
		var featured = target && target.closest && target.closest('.editor-post-featured-image');
		window.newsNextImageUse = featured ? 'featured' : 'content';
	}

	function imageUse() {
		var stateId = openFrameStateId();
		if (stateId === 'featured-image') {
			return 'featured';
		}
		if (stateId) {
			return 'content';
		}
		return window.newsNextImageUse === 'featured' ? 'featured' : 'content';
	}

	function openFrameStateId() {
		var frame = window.wp && wp.media && wp.media.frame;
		if (!frame || typeof frame.state !== 'function' || !frame.modal || !frame.modal.el) {
			return '';
		}
		var el = frame.modal.el;
		if (!el.isConnected) {
			return '';
		}
		var style = window.getComputedStyle(el);
		if (style.display === 'none' || style.visibility === 'hidden') {
			return '';
		}
		var state = frame.state();
		return state && state.id ? String(state.id) : '';
	}

	function isMediaCreate(options) {
		if (!options || String(options.method || 'GET').toUpperCase() !== 'POST') {
			return false;
		}
		if (!window.FormData || !(options.body instanceof window.FormData)) {
			return false;
		}
		var path = String(options.path || options.url || '');
		return path.indexOf('/wp/v2/media') !== -1 || path.indexOf('wp/v2/media') === 0;
	}
})();
