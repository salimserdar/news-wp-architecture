( function ( $ ) {
	var cfg = window.tr724Manset || {};
	var i18n = cfg.i18n || {};
	var form = document.getElementById( "tr724-ms-form" );
	var list = document.getElementById( "tr724-ms-list" );
	var orderInput = document.getElementById( "tr724-ms-order" );
	var refInput = document.getElementById( "tr724-ms-ref" );
	var lookupButton = document.getElementById( "tr724-ms-lookup" );
	var status = document.getElementById( "tr724-ms-status" );
	var empty = document.getElementById( "tr724-ms-empty" );
	var template = document.getElementById( "tr724-ms-template" );
	var controller = null;

	if ( ! form || ! list || ! orderInput || ! refInput || ! lookupButton || ! template ) {
		return;
	}

	function setStatus( message ) {
		if ( status ) {
			status.textContent = message || "";
		}
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

	function addPost( id, title, date ) {
		id = parseInt( id, 10 );
		if ( ! id ) {
			return false;
		}
		if ( isSelected( id ) ) {
			setStatus( i18n.already || "" );
			return false;
		}
		if ( selectedIds().length >= ( cfg.max || 30 ) ) {
			setStatus( i18n.limit || "" );
			return false;
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
		refInput.value = "";
		setStatus( "" );
		refInput.focus();
		return true;
	}

	function lookup() {
		var raw = refInput.value.trim();
		if ( ! raw ) {
			setStatus( "" );
			return;
		}
		if ( ! cfg.ajax ) {
			setStatus( i18n.error || "" );
			return;
		}
		if ( controller ) {
			controller.abort();
		}
		controller = new AbortController();
		lookupButton.disabled = true;
		setStatus( i18n.looking || "" );

		var body = new URLSearchParams();
		body.set( "action", "tr724_manset_lookup" );
		body.set( "nonce", cfg.nonce || "" );
		body.set( "ref", raw );

		fetch( cfg.ajax, {
			method: "POST",
			credentials: "same-origin",
			signal: controller.signal,
			headers: {
				"Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
				Accept: "application/json",
			},
			body: body.toString(),
		} )
			.then( function ( response ) {
				return response.json().then( function ( payload ) {
					return { ok: response.ok, payload: payload };
				} );
			} )
			.then( function ( result ) {
				var payload = result.payload || {};
				if ( ! result.ok || ! payload.success || ! payload.data ) {
					var message = payload.data && payload.data.message ? payload.data.message : ( i18n.none || "" );
					setStatus( message );
					return;
				}
				addPost( payload.data.id, payload.data.title || "", payload.data.date || "" );
			} )
			.catch( function ( error ) {
				if ( error && error.name === "AbortError" ) {
					return;
				}
				setStatus( i18n.error || "" );
			} )
			.then( function () {
				lookupButton.disabled = false;
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

	lookupButton.addEventListener( "click", function ( event ) {
		event.preventDefault();
		lookup();
	} );

	refInput.addEventListener( "keydown", function ( event ) {
		if ( event.key === "Enter" ) {
			event.preventDefault();
			lookup();
		}
	} );

	form.addEventListener( "submit", syncOrder );
	syncOrder();
} )( jQuery );
