( function () {
	function initAuthors( root ) {
		if ( ! root || root.dataset.tr724Ready === "1" ) return;

		var viewport = root.querySelector( ".authors__viewport" );
		var down = root.querySelector( ".authors__down" );
		var up = root.querySelector( ".authors__up" );
		if ( ! viewport || ! down || ! up ) return;

		root.dataset.tr724Ready = "1";

		function step() {
			var items = viewport.querySelectorAll( ".authors__list > li" );
			if ( items.length < 2 ) return 72;
			return items[1].offsetTop - items[0].offsetTop;
		}

		down.addEventListener( "click", function () {
			viewport.scrollBy( { top: step(), behavior: "smooth" } );
		} );

		up.addEventListener( "click", function () {
			viewport.scrollBy( { top: -step(), behavior: "smooth" } );
		} );
	}

	function boot( scope ) {
		if ( ! scope || scope.nodeType !== 1 ) return;
		if ( scope.matches( "[data-tr724-authors]" ) ) initAuthors( scope );
		scope.querySelectorAll( "[data-tr724-authors]" ).forEach( initAuthors );
	}

	function start() {
		boot( document.body );
		if ( ! document.body ) return;

		var observer = new MutationObserver( function ( records ) {
			records.forEach( function ( record ) {
				record.addedNodes.forEach( function ( node ) {
					boot( node );
				} );
			} );
		} );

		observer.observe( document.body, { childList: true, subtree: true } );
	}

	if ( document.readyState === "loading" ) {
		document.addEventListener( "DOMContentLoaded", start );
	} else {
		start();
	}
} )();
