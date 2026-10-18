( function ( $ ) {
	var cfg = window.tr724Manset || {};
	var i18n = cfg.i18n || {};
	var form = document.getElementById( "tr724-ms-form" );
	var list = document.getElementById( "tr724-ms-list" );
	var orderInput = document.getElementById( "tr724-ms-order" );
	var searchInput = document.getElementById( "tr724-ms-search" );
	var results = document.getElementById( "tr724-ms-results" );
	var status = document.getElementById( "tr724-ms-status" );
	var empty = document.getElementById( "tr724-ms-empty" );
	var template = document.getElementById( "tr724-ms-template" );
	var searchWrap = searchInput ? searchInput.closest( ".tr724-ms-add" ) : null;
	var timer = 0;
	var controller = null;

	if ( ! form || ! list || ! orderInput || ! searchInput || ! results || ! template ) {
		return;
	}

	function setStatus( message ) {
		if ( status ) {
			status.textContent = message || "";
		}
	}

	function decodeTitle( html ) {
		var tmp = document.createElement( "span" );
		tmp.innerHTML = html || "";
		return tmp.textContent || "";
	}

	function formatDate( value ) {
		if ( ! value ) {
			return "";
		}
		return String( value ).replace( "T", " " ).slice( 0, 16 );
	}

	function selectedIds() {
		var ids = [];
		list.querySelectorAll( ".tr724-ms-item" ).forEach( function ( item ) {
			var id = parseInt( item.getAttribute( "data-post-id" ), 10 );
			if ( id > 0 ) {
				ids.push( id );
			}
		} );
		return ids;
	}

	function isSelected( id ) {
		return selectedIds().indexOf( id ) !== -1;
	}

	function refreshSortable() {
		if ( $( list ).data( "ui-sortable" ) ) {
			$( list ).sortable( "refresh" );
		}
	}

	function syncOrder() {
		orderInput.value = selectedIds().join( "," );
		var items = list.querySelectorAll( ".tr724-ms-item" );
		items.forEach( function ( item, index ) {
			var up = item.querySelector( ".tr724-ms-up" );
			var down = item.querySelector( ".tr724-ms-down" );
			var number = item.querySelector( ".tr724-ms-index" );
			if ( up ) {
				up.disabled = index === 0;
			}
			if ( down ) {
				down.disabled = index === items.length - 1;
			}
			if ( number ) {
				number.textContent = String( index + 1 );
			}
		} );
		if ( empty ) {
			empty.hidden = items.length > 0;
		}
	}

	function hideResults() {
		results.hidden = true;
		results.textContent = "";
	}

	function addPost( id, title, date ) {
		id = parseInt( id, 10 );
		if ( ! id || isSelected( id ) ) {
			return;
		}
		if ( selectedIds().length >= ( cfg.max || 30 ) ) {
			setStatus( i18n.limit || "" );
			return;
		}

		var node = template.content.firstElementChild.cloneNode( true );
		node.setAttribute( "data-post-id", String( id ) );
		node.querySelector( ".tr724-ms-post-title" ).textContent = title;
		node.querySelector( ".tr724-ms-post-date" ).textContent = date || "";

		var marker = node.querySelector( 'input[type="hidden"]' );
		if ( marker ) {
			marker.name = "tr724_manset_posts[" + id + "]";
			marker.value = "1";
		}

		list.insertBefore( node, list.firstChild );
		syncOrder();
		refreshSortable();
		searchInput.value = "";
		hideResults();
		setStatus( "" );
	}

	function renderResults( posts ) {
		results.textContent = "";
		if ( ! posts.length ) {
			hideResults();
			setStatus( i18n.none || "" );
			return;
		}

		posts.forEach( function ( post ) {
			var id = parseInt( post.id, 10 );
			if ( ! id ) {
				return;
			}
			var title = decodeTitle( post.title && post.title.rendered ? post.title.rendered : "" );
			var item = document.createElement( "li" );
			var name = document.createElement( "span" );
			var button = document.createElement( "button" );
			var selected = isSelected( id );

			name.textContent = title;
			button.type = "button";
			button.className = "button button-small";
			button.textContent = selected ? ( i18n.added || "Added" ) : ( i18n.add || "Add" );
			button.disabled = selected;
			button.addEventListener( "click", function () {
				addPost( id, title, formatDate( post.date ) );
				button.disabled = true;
				button.textContent = i18n.added || "Added";
			} );

			item.appendChild( name );
			item.appendChild( button );
			results.appendChild( item );
		} );

		results.hidden = false;
		setStatus( "" );
	}

	function search( query ) {
		if ( ! cfg.restPosts ) {
			setStatus( i18n.error || "" );
			return;
		}
		if ( controller ) {
			controller.abort();
		}
		controller = new AbortController();
		setStatus( i18n.searching || "" );

		var url = new URL( cfg.restPosts, window.location.href );
		url.searchParams.set( "search", query );
		url.searchParams.set( "per_page", "20" );
		url.searchParams.set( "status", "publish" );
		url.searchParams.set( "_fields", "id,title,date" );

		fetch( url.toString(), {
			credentials: "same-origin",
			signal: controller.signal,
			headers: {
				Accept: "application/json",
				"X-WP-Nonce": cfg.nonce || "",
			},
		} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( "search failed" );
				}
				return response.json();
			} )
			.then( function ( posts ) {
				renderResults( Array.isArray( posts ) ? posts : [] );
			} )
			.catch( function ( error ) {
				if ( error && error.name === "AbortError" ) {
					return;
				}
				hideResults();
				setStatus( i18n.error || "" );
			} );
	}

	$( list ).sortable( {
		handle: ".tr724-ms-handle",
		axis: "y",
		tolerance: "pointer",
		placeholder: "tr724-ms-placeholder",
		forcePlaceholderSize: true,
		update: syncOrder,
	} );

	list.addEventListener( "click", function ( event ) {
		var item = event.target.closest( ".tr724-ms-item" );
		if ( ! item || ! list.contains( item ) ) {
			return;
		}
		if ( event.target.closest( ".tr724-ms-remove" ) ) {
			item.remove();
			syncOrder();
			refreshSortable();
			return;
		}
		if ( event.target.closest( ".tr724-ms-up" ) ) {
			var previous = item.previousElementSibling;
			if ( previous ) {
				list.insertBefore( item, previous );
				syncOrder();
				refreshSortable();
			}
			return;
		}
		if ( event.target.closest( ".tr724-ms-down" ) ) {
			var next = item.nextElementSibling;
			if ( next ) {
				list.insertBefore( next, item );
				syncOrder();
				refreshSortable();
			}
		}
	} );

	searchInput.addEventListener( "input", function () {
		window.clearTimeout( timer );
		var query = searchInput.value.trim();
		if ( query.length < 1 ) {
			if ( controller ) {
				controller.abort();
			}
			hideResults();
			setStatus( "" );
			return;
		}
		timer = window.setTimeout( function () {
			search( query );
		}, 250 );
	} );

	searchInput.addEventListener( "keydown", function ( event ) {
		if ( event.key === "Enter" ) {
			event.preventDefault();
			var button = results.querySelector( "button:not(:disabled)" );
			if ( button ) {
				button.click();
			}
		}
		if ( event.key === "Escape" ) {
			hideResults();
			setStatus( "" );
		}
	} );

	document.addEventListener( "click", function ( event ) {
		if ( searchWrap && ! searchWrap.contains( event.target ) ) {
			hideResults();
		}
	} );

	form.addEventListener( "submit", syncOrder );
	syncOrder();
} )( jQuery );
