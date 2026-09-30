( function () {
	function initSpotlight( root ) {
		if ( ! root || root.dataset.tr724Ready === "1" || typeof Swiper !== "function" ) {
			return;
		}

		var swiperEl = root.querySelector( ".news-swiper" );
		var pagerNumbers = root.querySelector( ".news-pager__numbers" );
		var pagerMore = root.querySelector( ".news-pager__more" );
		if ( ! swiperEl || ! pagerNumbers || ! pagerMore ) return;
		if ( ! swiperEl.querySelector( ".swiper-slide" ) ) return;

		root.dataset.tr724Ready = "1";

		var newsSwiper = new Swiper( swiperEl, {
			slidesPerView: 1,
			speed: 450,
			rewind: true,
			grabCursor: true,
			autoplay: {
				delay: 5000,
				disableOnInteraction: false,
				pauseOnMouseEnter: true,
			},
			navigation: {
				nextEl: root.querySelector( ".news-swiper__next" ),
				prevEl: root.querySelector( ".news-swiper__prev" ),
			},
			pagination: {
				el: pagerNumbers,
				clickable: true,
				renderBullet: function ( index, className ) {
					return (
						'<button type="button" class="' +
						className +
						'">' +
						( index + 1 ) +
						"</button>"
					);
				},
			},
			a11y: {
				prevSlideMessage: "Önceki haber",
				nextSlideMessage: "Sonraki haber",
				paginationBulletMessage: "{{index}}. habere git",
			},
		} );

		root.tr724Swiper = newsSwiper;

		pagerNumbers.addEventListener( "mouseover", function ( event ) {
			var bullet = event.target.closest( ".swiper-pagination-bullet" );
			if ( ! bullet || ! newsSwiper.pagination ) return;
			var index = Array.prototype.indexOf.call( newsSwiper.pagination.bullets, bullet );
			if ( index < 0 || index === newsSwiper.realIndex ) return;
			newsSwiper.slideTo( index );
		} );

		pagerMore.addEventListener( "click", function () {
			if ( newsSwiper.isEnd ) newsSwiper.slideTo( 0 );
			else newsSwiper.slideNext();
		} );
	}

	function destroySpotlight( root ) {
		if ( root && root.tr724Swiper && root.tr724Swiper.destroy ) {
			root.tr724Swiper.destroy( true, true );
			root.tr724Swiper = null;
		}
	}

	function boot( scope ) {
		if ( ! scope || scope.nodeType !== 1 ) return;
		if ( scope.matches( "[data-tr724-spotlight]" ) ) initSpotlight( scope );
		scope.querySelectorAll( "[data-tr724-spotlight]" ).forEach( initSpotlight );
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
					if ( node.matches && node.matches( "[data-tr724-spotlight]" ) ) {
						destroySpotlight( node );
					}
					if ( node.querySelectorAll ) {
						node.querySelectorAll( "[data-tr724-spotlight]" ).forEach( destroySpotlight );
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
