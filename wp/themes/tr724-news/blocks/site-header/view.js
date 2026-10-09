( function () {
	function initHeader( root ) {
		var openSearch = root.querySelector( ".site-header__search" );
		var modal = root.querySelector( ".search-modal" );
		var menuButton = root.querySelector( ".site-header__menu" );
		var drawer = root.querySelector( ".drawer" );
		if ( ! openSearch || ! modal || ! menuButton || ! drawer ) return;

		var input = modal.querySelector( ".search-modal__input" );
		var sortField = modal.querySelector( ".search-modal__sort" );
		var sort = sortField ? sortField.querySelector( "select" ) : null;
		var list = modal.querySelector( ".search-modal__list" );
		var more = modal.querySelector( ".search-modal__more" );
		var endpoint = modal.getAttribute( "data-search-endpoint" );
		var searchPage = modal.getAttribute( "data-search-page" );
		var emptyText = modal.getAttribute( "data-search-empty" ) || "Sonuç bulunamadı";
		var errorText = modal.getAttribute( "data-search-error" ) || "Arama şu anda kullanılamıyor.";
		var loadingText = modal.getAttribute( "data-search-loading" ) || "Aranıyor…";
		var pageSize = 20;
		var page = 1;
		var total = 0;
		var shown = 0;
		var query = "";
		var timer = 0;
		var requestId = 0;
		var controller = null;
		var reduceMotion = window.matchMedia( "(prefers-reduced-motion: reduce)" ).matches;
		var drawerOpen = false;

		function currentSort() {
			return sort && sort.value === "date:asc" ? "date:asc" : "date:desc";
		}

		function searchPageUrl() {
			if ( ! searchPage || ! query ) return "";
			var url = new URL( searchPage, window.location.href );
			url.searchParams.set( "s", query );
			url.searchParams.set( "sort", currentSort() );
			url.searchParams.set( "paged", "2" );
			return url.toString();
		}

		function setSortVisible( visible ) {
			if ( sortField ) sortField.hidden = ! visible;
		}

		function setStatus( message ) {
			if ( ! list ) return;
			list.replaceChildren();
			var note = document.createElement( "p" );
			note.className = "search-modal__empty";
			note.textContent = message;
			list.append( note );
			if ( more ) more.hidden = true;
		}

		function sameSiteStoryUrl( value ) {
			if ( typeof value !== "string" ) return "";
			var raw = value.trim();
			if ( ! raw || raw.indexOf( "\\" ) !== -1 || raw.indexOf( ".." ) !== -1 ) return "";
			var path = raw;
			if ( raw.indexOf( "://" ) !== -1 || raw.slice( 0, 2 ) === "//" ) {
				try {
					path = new URL( raw, window.location.origin ).pathname || "";
				} catch ( error ) {
					return "";
				}
			}
			try {
				path = decodeURIComponent( path );
			} catch ( error ) {
				return "";
			}
			if ( path.indexOf( "://" ) !== -1 || path.indexOf( "//" ) !== -1 || path.indexOf( "\\" ) !== -1 || path.indexOf( ".." ) !== -1 ) {
				return "";
			}
			if ( path.charAt( 0 ) !== "/" ) path = "/" + path;
			if ( path.charAt( path.length - 1 ) !== "/" ) path += "/";
			if ( ! /^\/(?:[a-z0-9]+(?:-[a-z0-9]+)*\/)+$/.test( path ) ) return "";
			return window.location.origin + path;
		}

		function relativeTime( value ) {
			var then = Date.parse( value );
			if ( isNaN( then ) ) return { time: "", datetime: "" };
			var seconds = Math.max( 0, Math.round( ( Date.now() - then ) / 1000 ) );
			var steps = [
				[ 31536000, "yıl" ],
				[ 2592000, "ay" ],
				[ 604800, "hafta" ],
				[ 86400, "gün" ],
				[ 3600, "saat" ],
				[ 60, "dakika" ],
			];
			var label = "1 dakika";
			for ( var i = 0; i < steps.length; i++ ) {
				if ( seconds >= steps[i][0] ) {
					var count = Math.round( seconds / steps[i][0] );
					label = ( count > 0 ? count : 1 ) + " " + steps[i][1];
					break;
				}
			}
			return { time: label + " önce", datetime: new Date( then ).toISOString() };
		}

		function normalizeStory( story ) {
			if ( ! story || typeof story !== "object" ) return null;
			var title = typeof story.title === "string" ? story.title.trim() : "";
			title = title.replace( /\s+-\s+TR724\s*$/u, "" ).trim();
			var source = typeof story.link === "string" && story.link !== "" ? story.link : story.url;
			var url = sameSiteStoryUrl( source );
			if ( ! title || ! url ) return null;
			var kicker = typeof story.kicker === "string" ? story.kicker.trim() : "";
			if ( ! kicker && story.categories && story.categories[0] && typeof story.categories[0].name === "string" ) {
				kicker = story.categories[0].name.trim().toLocaleUpperCase( "tr-TR" );
			}
			var author = "";
			if ( typeof story.author === "string" ) author = story.author.trim();
			else if ( story.author && typeof story.author.name === "string" ) author = story.author.name.trim();
			var time = typeof story.time === "string" ? story.time : "";
			var datetime = typeof story.datetime === "string" ? story.datetime : "";
			if ( ! time && typeof story.date === "string" && story.date ) {
				var relative = relativeTime( story.date );
				time = relative.time;
				datetime = relative.datetime;
			}
			return {
				url: url,
				title: title,
				kicker: kicker,
				author: author,
				time: time,
				datetime: datetime,
			};
		}

		function renderHits( items, append ) {
			if ( ! list ) return;
			if ( ! append ) list.replaceChildren();
			var firstNew = null;
			items.forEach( function ( story ) {
				if ( ! story || typeof story.url !== "string" || typeof story.title !== "string" || story.url === "" ) {
					return;
				}
				var link = document.createElement( "a" );
				link.className = "search-hit";
				link.href = story.url;
				var kickerText = typeof story.kicker === "string" ? story.kicker.trim() : "";
				if ( kickerText && kickerText.toLocaleUpperCase( "tr-TR" ) !== "MANŞET" ) {
					var kicker = document.createElement( "span" );
					kicker.className = "search-hit__kicker";
					kicker.textContent = kickerText;
					link.append( kicker );
				}
				var title = document.createElement( "span" );
				title.className = "search-hit__title";
				title.textContent = story.title;
				link.append( title );
				var authorText = typeof story.author === "string" ? story.author.trim() : "";
				var showAuthor = authorText !== "" && authorText.toLocaleUpperCase( "tr-TR" ).indexOf( "TR724" ) === -1;
				if ( showAuthor || story.time ) {
					var meta = document.createElement( "span" );
					meta.className = "search-hit__meta";
					if ( showAuthor ) {
						var author = document.createElement( "span" );
						author.className = "search-hit__author";
						author.textContent = authorText;
						meta.append( author );
					}
					if ( story.time ) {
						var time = document.createElement( "time" );
						time.className = "search-hit__time";
						time.textContent = story.time;
						if ( story.datetime ) time.dateTime = story.datetime;
						meta.append( time );
					}
					link.append( meta );
				}
				if ( ! firstNew ) firstNew = link;
				list.append( link );
			} );
			if ( append && firstNew && typeof firstNew.scrollIntoView === "function" ) {
				firstNew.scrollIntoView( { block: "nearest" } );
			}
		}

		function resetSearch() {
			window.clearTimeout( timer );
			if ( controller ) controller.abort();
			requestId += 1;
			query = "";
			page = 1;
			total = 0;
			shown = 0;
			if ( input ) input.value = "";
			if ( list ) list.replaceChildren();
			setSortVisible( false );
			if ( more ) {
				more.hidden = true;
				more.removeAttribute( "href" );
			}
		}

		function search( nextPage, append ) {
			if ( ! input || ! endpoint ) return;
			var current = input.value.trim();
			if ( current.length < 2 ) {
				resetSearch();
				if ( input ) input.value = current;
				return;
			}

			query = current;
			page = nextPage;
			if ( controller ) controller.abort();
			controller = typeof AbortController === "function" ? new AbortController() : null;
			var id = ++requestId;
			if ( ! append ) setStatus( loadingText );

			var url = new URL( endpoint, window.location.href );
			url.searchParams.set( "q", query );
			url.searchParams.set( "page", String( page ) );
			url.searchParams.set( "limit", String( pageSize ) );
			url.searchParams.set( "status", "publish" );
			url.searchParams.set( "sort", currentSort() );

			var options = {
				headers: { Accept: "application/json" },
			};
			if ( controller ) options.signal = controller.signal;

			window.fetch( url.toString(), options )
				.then( function ( response ) {
					if ( ! response.ok ) throw new Error( "search" );
					return response.json();
				} )
				.then( function ( data ) {
					if ( id !== requestId ) return;
					var items = data && Array.isArray( data.results ) ? data.results.map( normalizeStory ).filter( Boolean ) : [];
					total = data && typeof data.total === "number" ? data.total : items.length;
					if ( ! append && ! items.length ) {
						setStatus( emptyText );
						setSortVisible( false );
						shown = 0;
						return;
					}
					renderHits( items, append );
					setSortVisible( true );
					shown = append ? shown + items.length : items.length;
					if ( more ) {
						var href = searchPageUrl();
						if ( href ) more.href = href;
						more.hidden = ! href || shown >= total || items.length === 0;
					}
				} )
				.catch( function ( error ) {
					if ( error && error.name === "AbortError" ) return;
					if ( id !== requestId ) return;
					if ( ! append ) {
						setStatus( errorText );
						setSortVisible( false );
					}
				} );
		}

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
			resetSearch();
			if ( input ) input.focus();
		}

		function closeModal() {
			modal.hidden = true;
			document.body.style.overflow = "";
			resetSearch();
			openSearch.focus();
		}

		openSearch.addEventListener( "click", openModal );
		modal.querySelectorAll( "[data-search-close]" ).forEach( function ( control ) {
			control.addEventListener( "click", closeModal );
		} );

		if ( input ) {
			input.addEventListener( "input", function () {
				window.clearTimeout( timer );
				timer = window.setTimeout( function () {
					search( 1, false );
				}, 300 );
			} );
			input.addEventListener( "keydown", function ( event ) {
				if ( event.key !== "Enter" ) return;
				event.preventDefault();
				window.clearTimeout( timer );
				search( 1, false );
			} );
		}

		if ( sort ) {
			sort.addEventListener( "change", function () {
				window.clearTimeout( timer );
				if ( ! input || input.value.trim().length < 2 ) return;
				search( 1, false );
			} );
		}

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
