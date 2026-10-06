(function () {
	var Attachments = window.wp && wp.media && wp.media.model && wp.media.model.Attachments;
	if (!Attachments || typeof Attachments.comparator !== 'function') {
		return;
	}

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
})();
