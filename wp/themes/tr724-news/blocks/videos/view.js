( function () {
	function initVideos( root ) {
		if ( ! root || root.dataset.tr724Ready === "1" || typeof Swiper !== "function" ) {
			return;
		}

		var swiperEl = root.querySelector( ".videos-swiper" );
		if ( ! swiperEl || ! swiperEl.querySelector( ".swiper-slide" ) ) {
			return;
		}

		root.dataset.tr724Ready = "1";

		var loop = root.dataset.loop === "1";
		root.tr724Swiper = new Swiper( swiperEl, {
			slidesPerView: "auto",
			centeredSlides: true,
			spaceBetween: 14,
			loop: loop,
			rewind: ! loop,
			speed: 450,
			grabCursor: true,
			navigation: {
				nextEl: root.querySelector( ".videos__next" ),
				prevEl: root.querySelector( ".videos__prev" ),
			},
			a11y: {
				prevSlideMessage: "Önceki videolar",
				nextSlideMessage: "Sonraki videolar",
			},
		} );
	}

	function destroyVideos( root ) {
		if ( root && root.tr724Swiper && root.tr724Swiper.destroy ) {
			root.tr724Swiper.destroy( true, true );
			root.tr724Swiper = null;
		}
	}

	function boot( scope ) {
		if ( ! scope || scope.nodeType !== 1 ) return;
		if ( scope.matches( "[data-tr724-videos]" ) ) initVideos( scope );
		scope.querySelectorAll( "[data-tr724-videos]" ).forEach( initVideos );
	}

	function start() {
		boot( document.body );
		if ( ! document.body ) return;

		var observer = new MutationObserver( function ( records ) {
			records.forEach( function ( record ) {
				record.addedNodes.forEach( function ( node ) {
					boot( node );
				} );
				record.removedNodes.forEach( function ( node ) {
					if ( ! node || node.nodeType !== 1 ) return;
					if ( node.matches && node.matches( "[data-tr724-videos]" ) ) {
						destroyVideos( node );
					}
					if ( node.querySelectorAll ) {
						node.querySelectorAll( "[data-tr724-videos]" ).forEach( destroyVideos );
					}
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
