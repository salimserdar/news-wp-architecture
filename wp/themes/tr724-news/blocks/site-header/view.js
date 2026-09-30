( function () {
	function initHeader( root ) {
		var openSearch = root.querySelector( ".site-header__search" );
		var modal = root.querySelector( ".search-modal" );
		var menuButton = root.querySelector( ".site-header__menu" );
		var drawer = root.querySelector( ".drawer" );
		if ( ! openSearch || ! modal || ! menuButton || ! drawer ) return;

		var input = modal.querySelector( ".wp-block-search__input" );
		var reduceMotion = window.matchMedia( "(prefers-reduced-motion: reduce)" ).matches;
		var drawerOpen = false;

		function setDrawer( open ) {
			drawerOpen = open;
			if ( open && ! modal.hidden ) {
				modal.hidden = true;
			}
			menuButton.setAttribute( "aria-expanded", open ? "true" : "false" );
			menuButton.setAttribute( "aria-label", open ? "Menüyü kapat" : "Menü" );
			document.body.style.overflow = open ? "hidden" : "";

			if ( open ) {
				drawer.hidden = false;
				if ( reduceMotion ) {
					drawer.classList.add( "is-open" );
					return;
				}
				window.requestAnimationFrame( function () {
					window.requestAnimationFrame( function () {
						drawer.classList.add( "is-open" );
					} );
				} );
				return;
			}

			drawer.classList.remove( "is-open" );
			if ( reduceMotion ) drawer.hidden = true;
		}

		drawer.addEventListener( "transitionend", function ( event ) {
			if ( event.propertyName !== "transform" ) return;
			if ( ! drawer.classList.contains( "is-open" ) ) drawer.hidden = true;
		} );

		menuButton.addEventListener( "click", function () {
			setDrawer( ! drawerOpen );
		} );

		drawer.querySelectorAll( "[data-drawer-close]" ).forEach( function ( control ) {
			control.addEventListener( "click", function () {
				setDrawer( false );
				menuButton.focus();
			} );
		} );

		function openModal() {
			if ( drawerOpen ) {
				drawer.classList.remove( "is-open" );
				drawer.hidden = true;
				drawerOpen = false;
				menuButton.setAttribute( "aria-expanded", "false" );
				menuButton.setAttribute( "aria-label", "Menü" );
			}
			modal.hidden = false;
			document.body.style.overflow = "hidden";
			if ( input ) {
				input.value = "";
				input.focus();
			}
		}

		function closeModal() {
			modal.hidden = true;
			document.body.style.overflow = "";
			openSearch.focus();
		}

		openSearch.addEventListener( "click", openModal );
		modal.querySelectorAll( "[data-search-close]" ).forEach( function ( control ) {
			control.addEventListener( "click", closeModal );
		} );

		document.addEventListener( "keydown", function ( event ) {
			if ( event.key !== "Escape" ) return;
			if ( ! modal.hidden ) {
				closeModal();
				return;
			}
			if ( ! drawer.hidden ) setDrawer( false );
		} );
	}

	function boot() {
		document.querySelectorAll( "[data-tr724-header]" ).forEach( initHeader );
	}

	if ( document.readyState === "loading" ) {
		document.addEventListener( "DOMContentLoaded", boot );
	} else {
		boot();
	}
} )();
