( function ( $ ) {
	var cfg = window.tr724FeaturedTopics || {};
	var i18n = cfg.i18n || {};
	var form = document.getElementById( "tr724-ft-form" );
	var list = document.getElementById( "tr724-ft-list" );
	var orderInput = document.getElementById( "tr724-ft-order" );
	var searchInput = document.getElementById( "tr724-ft-search" );
	var results = document.getElementById( "tr724-ft-results" );
	var status = document.getElementById( "tr724-ft-status" );
	var empty = document.getElementById( "tr724-ft-empty" );
	var template = document.getElementById( "tr724-ft-template" );
	var searchWrap = searchInput ? searchInput.closest( ".tr724-ft-add" ) : null;
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

	function selectedIds() {
		var ids = [];
		list.querySelectorAll( ".tr724-ft-item" ).forEach( function ( item ) {
			var id = parseInt( item.getAttribute( "data-tag-id" ), 10 );
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
		var items = list.querySelectorAll( ".tr724-ft-item" );
		items.forEach( function ( item, index ) {
			var up = item.querySelector( ".tr724-ft-up" );
			var down = item.querySelector( ".tr724-ft-down" );
			if ( up ) {
				up.disabled = index === 0;
			}
			if ( down ) {
				down.disabled = index === items.length - 1;
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

	function addTopic( id, name ) {
		id = parseInt( id, 10 );
		if ( ! id || isSelected( id ) ) {
			return;
		}
		if ( selectedIds().length >= ( cfg.max || 40 ) ) {
			setStatus( i18n.limit || "" );
			return;
		}

		var node = template.content.firstElementChild.cloneNode( true );
		node.setAttribute( "data-tag-id", String( id ) );
		node.querySelector( ".tr724-ft-tag-name" ).textContent = name;

		var marker = node.querySelector( 'input[type="hidden"]' );
		if ( marker ) {
			marker.name = "tr724_featured_topics[" + id + "]";
			marker.value = "1";
		}

		list.appendChild( node );
		syncOrder();
		refreshSortable();
		searchInput.value = "";
		hideResults();
		setStatus( "" );
	}

	function renderResults( tags ) {
		results.textContent = "";
		if ( ! tags.length ) {
			hideResults();
			setStatus( i18n.none || "" );
			return;
		}

		tags.forEach( function ( tag ) {
			var id = parseInt( tag.id, 10 );
			if ( ! id ) {
				return;
			}
			var item = document.createElement( "li" );
			var name = document.createElement( "span" );
			var button = document.createElement( "button" );
			var selected = isSelected( id );

			name.textContent = tag.name || "";
			button.type = "button";
			button.className = "button button-small";
			button.textContent = selected ? ( i18n.added || "Added" ) : ( i18n.add || "Add" );
			button.disabled = selected;
			button.addEventListener( "click", function () {
				addTopic( id, tag.name || "" );
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
		if ( ! cfg.restTags ) {
			setStatus( i18n.error || "" );
			return;
		}
		if ( controller ) {
			controller.abort();
		}
		controller = new AbortController();
		setStatus( i18n.searching || "" );

		var url = new URL( cfg.restTags, window.location.href );
		url.searchParams.set( "search", query );
		url.searchParams.set( "per_page", "20" );
		url.searchParams.set( "_fields", "id,name" );
		url.searchParams.set( "hide_empty", "false" );

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
			.then( function ( tags ) {
				renderResults( Array.isArray( tags ) ? tags : [] );
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
		handle: ".tr724-ft-handle",
		axis: "y",
		tolerance: "pointer",
		placeholder: "tr724-ft-placeholder",
		forcePlaceholderSize: true,
		update: syncOrder,
	} );

	list.addEventListener( "click", function ( event ) {
		var item = event.target.closest( ".tr724-ft-item" );
		if ( ! item || ! list.contains( item ) ) {
			return;
		}
		if ( event.target.closest( ".tr724-ft-remove" ) ) {
			item.remove();
			syncOrder();
			refreshSortable();
			return;
		}
		if ( event.target.closest( ".tr724-ft-up" ) ) {
			var previous = item.previousElementSibling;
			if ( previous ) {
				list.insertBefore( item, previous );
				syncOrder();
				refreshSortable();
			}
			return;
		}
		if ( event.target.closest( ".tr724-ft-down" ) ) {
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
