( function () {
	function initHeadlines( root ) {
		if ( ! root || root.dataset.tr724Ready === "1" || typeof Swiper !== "function" ) {
			return;
		}

		var swiperEl = root.querySelector( ".headlines-swiper" );
		var currentEl = root.querySelector( "[data-headlines-current]" );
		var bar = root.querySelector( ".headlines__progress-bar" );
		if ( ! swiperEl || ! swiperEl.querySelector( ".swiper-slide" ) ) {
			return;
		}

		root.dataset.tr724Ready = "1";

		root.querySelectorAll( ".headlines-card__media img" ).forEach( function ( img ) {
			function dropBroken() {
				if ( img.naturalWidth === 0 ) {
					img.remove();
				}
			}
			if ( img.complete ) {
				dropBroken();
			} else {
				img.addEventListener( "error", dropBroken );
			}
		} );

		var reduce = window.matchMedia( "(prefers-reduced-motion: reduce)" ).matches;

		function syncLock( swiper ) {
			root.classList.toggle( "headlines--static", !! swiper.isLocked );
		}

		var headlinesSwiper = new Swiper( swiperEl, {
			slidesPerView: 1,
			slidesPerGroup: 1,
			spaceBetween: 14,
			speed: 450,
			rewind: true,
			grabCursor: true,
			watchOverflow: true,
			threshold: 8,
			breakpoints: {
				960: {
					slidesPerView: 2,
					spaceBetween: 16,
				},
			},
			autoplay: reduce
				? false
				: {
					delay: 5000,
					disableOnInteraction: false,
					pauseOnMouseEnter: true,
				},
			navigation: {
				nextEl: root.querySelector( ".headlines__next" ),
				prevEl: root.querySelector( ".headlines__prev" ),
			},
			keyboard: {
				enabled: true,
				onlyInViewport: true,
			},
			a11y: {
				prevSlideMessage: "Önceki manşet",
				nextSlideMessage: "Sonraki manşet",
				firstSlideMessage: "İlk manşet",
				lastSlideMessage: "Son manşet",
			},
			on: {
				init: syncLock,
				lock: syncLock,
				unlock: syncLock,
				resize: syncLock,
				breakpoint: syncLock,
				slideChange: function ( swiper ) {
					if ( currentEl ) {
						currentEl.textContent = String( swiper.realIndex + 1 );
					}
				},
				autoplayTimeLeft: function ( swiper, time, progress ) {
					if ( ! bar ) return;
					bar.style.transform = "scaleX(" + ( 1 - progress ) + ")";
				},
			},
		} );

		root.tr724Swiper = headlinesSwiper;
	}

	function destroyHeadlines( root ) {
		if ( root && root.tr724Swiper && root.tr724Swiper.destroy ) {
			root.tr724Swiper.destroy( true, true );
			root.tr724Swiper = null;
		}
	}

	function boot( scope ) {
		if ( ! scope || scope.nodeType !== 1 ) return;
		if ( scope.matches( "[data-tr724-headlines]" ) ) initHeadlines( scope );
		scope.querySelectorAll( "[data-tr724-headlines]" ).forEach( initHeadlines );
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
					if ( node.matches && node.matches( "[data-tr724-headlines]" ) ) {
						destroyHeadlines( node );
					}
					if ( node.querySelectorAll ) {
						node.querySelectorAll( "[data-tr724-headlines]" ).forEach( destroyHeadlines );
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
