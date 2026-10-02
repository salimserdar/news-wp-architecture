( function () {
	function initPopular( root ) {
		if ( ! root || root.dataset.tr724Ready === "1" ) {
			return;
		}

		var tabs = root.querySelectorAll( ".popular__tab" );
		var panels = root.querySelectorAll( ".popular__list" );
		if ( ! tabs.length || ! panels.length ) {
			return;
		}

		root.dataset.tr724Ready = "1";

		tabs.forEach( function ( tab ) {
			tab.addEventListener( "click", function () {
				var id = tab.getAttribute( "aria-controls" );
				tabs.forEach( function ( item ) {
					var selected = item === tab;
					item.setAttribute( "aria-selected", selected ? "true" : "false" );
					item.tabIndex = selected ? 0 : -1;
				} );
				panels.forEach( function ( panel ) {
					panel.hidden = panel.id !== id;
				} );
			} );
		} );
	}

	function boot( scope ) {
		if ( ! scope || scope.nodeType !== 1 ) return;
		if ( scope.matches( "[data-tr724-popular]" ) ) initPopular( scope );
		scope.querySelectorAll( "[data-tr724-popular]" ).forEach( initPopular );
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
