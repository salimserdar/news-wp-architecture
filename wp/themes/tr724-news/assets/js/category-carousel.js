( function () {
	function initCarousel( root ) {
		if ( ! root || root.dataset.tr724Ready === "1" || typeof Swiper !== "function" ) {
			return;
		}

		var swiperEl = root.querySelector( ".category-carousel__swiper" );
		var pager = root.querySelector( ".category-carousel__pager" );
		if ( ! swiperEl || ! pager ) return;
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
				nextEl: root.querySelector( ".category-carousel__next" ),
				prevEl: root.querySelector( ".category-carousel__prev" ),
			},
			pagination: {
				el: pager,
				clickable: true,
				renderBullet: function ( index, className ) {
					return '<button type="button" class="' + className + '"></button>';
				},
			},
			a11y: {
				prevSlideMessage: "Önceki haber",
				nextSlideMessage: "Sonraki haber",
				paginationBulletMessage: "{{index}}. habere git",
			},
		} );

		root.tr724Swiper = newsSwiper;

		pager.addEventListener( "mouseover", function ( event ) {
			var bullet = event.target.closest( ".swiper-pagination-bullet" );
			if ( ! bullet || ! newsSwiper.pagination ) return;
			var index = Array.prototype.indexOf.call( newsSwiper.pagination.bullets, bullet );
			if ( index < 0 || index === newsSwiper.realIndex ) return;
			newsSwiper.slideTo( index );
		} );
	}

	function boot( scope ) {
		if ( ! scope || scope.nodeType !== 1 ) return;
		if ( scope.matches( "[data-tr724-category-carousel]" ) ) initCarousel( scope );
		scope.querySelectorAll( "[data-tr724-category-carousel]" ).forEach( initCarousel );
	}

	if ( document.readyState === "loading" ) {
		document.addEventListener( "DOMContentLoaded", function () {
			boot( document.body );
		} );
	} else {
		boot( document.body );
	}
} )();
