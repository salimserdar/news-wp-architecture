( function () {
	function headerClearance() {
		var header = document.querySelector( ".site-header" );
		if ( ! header ) return 8;
		return Math.max( 8, header.getBoundingClientRect().bottom + 8 );
	}

	function initYazarlar( root ) {
		if ( ! root || root.dataset.tr724Ready === "1" ) return;
		if ( ! root.classList.contains( "yazarlar--collapse" ) ) return;

		var title = root.querySelector( ".writers__title" );
		var grid = root.querySelector( ".writers__grid" );
		if ( ! title || ! grid ) return;

		root.dataset.tr724Ready = "1";
		title.tabIndex = 0;
		title.setAttribute( "role", "button" );
		if ( grid.id ) title.setAttribute( "aria-controls", grid.id );

		var collapsed = root.classList.contains( "is-collapsed" );

		function apply( scroll ) {
			if ( ! root.isConnected ) return;

			root.classList.toggle( "is-collapsed", collapsed );
			title.setAttribute( "aria-expanded", collapsed ? "false" : "true" );

			if ( scroll && collapsed ) {
				var clearance = headerClearance();
				var top = root.getBoundingClientRect().top;
				if ( top < clearance ) {
					var reduce = window.matchMedia( "(prefers-reduced-motion: reduce)" ).matches;
					window.scrollBy( {
						top: top - clearance,
						behavior: reduce ? "auto" : "smooth",
					} );
				}
			}
		}

		function toggle( event ) {
			event.preventDefault();
			collapsed = ! collapsed;
			apply( true );
		}

		title.addEventListener( "click", toggle );
		title.addEventListener( "keydown", function ( event ) {
			if ( event.key !== "Enter" && event.key !== " " ) return;
			toggle( event );
		} );

		apply( false );
	}

	function boot( scope ) {
		if ( ! scope || scope.nodeType !== 1 ) return;
		if ( scope.classList.contains( "yazarlar--collapse" ) ) initYazarlar( scope );
		scope.querySelectorAll( ".yazarlar--collapse" ).forEach( initYazarlar );
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
