( function () {
	var cfg = window.tr724Timeline || {};
	var i18n = cfg.i18n || {};
	var max = parseInt( cfg.max, 10 );
	if ( ! max || max < 1 ) {
		max = 40;
	}

	var form = document.getElementById( "tr724-tl-form" );
	var list = document.getElementById( "tr724-tl-list" );
	var empty = document.getElementById( "tr724-tl-empty" );
	var addButton = document.getElementById( "tr724-tl-add" );
	var template = document.getElementById( "tr724-tl-template" );

	if ( ! form || ! list || ! addButton || ! template ) {
		return;
	}

	function syncHidden( item ) {
		var box = item.querySelector( 'input[data-name="hidden"]' );
		item.classList.toggle( "is-hidden", !!( box && box.checked ) );
	}

	function reindex() {
		var items = list.querySelectorAll( ".tr724-tl-item" );
		items.forEach( function ( item, index ) {
			item.querySelectorAll( "[data-name]" ).forEach( function ( input ) {
				var field = input.getAttribute( "data-name" );
				if ( ! field ) {
					return;
				}
				input.name = "tr724_timeline_programs[" + index + "][" + field + "]";
			} );
			syncHidden( item );
		} );
		if ( empty ) {
			empty.hidden = items.length > 0;
		}
		addButton.disabled = items.length >= max;
	}

	addButton.addEventListener( "click", function () {
		if ( list.querySelectorAll( ".tr724-tl-item" ).length >= max ) {
			window.alert( i18n.limit || "" );
			addButton.disabled = true;
			return;
		}
		var row = template.content.querySelector( ".tr724-tl-item" );
		if ( ! row ) {
			return;
		}
		var node = row.cloneNode( true );
		list.appendChild( node );
		reindex();
		var time = node.querySelector( 'input[type="time"]' );
		if ( time ) {
			time.focus();
		}
	} );

	list.addEventListener( "change", function ( event ) {
		var input = event.target;
		if ( ! input || input.getAttribute( "data-name" ) !== "hidden" ) {
			return;
		}
		var item = input.closest( ".tr724-tl-item" );
		if ( item ) {
			syncHidden( item );
		}
	} );

	list.addEventListener( "click", function ( event ) {
		var button = event.target.closest( ".tr724-tl-remove" );
		if ( ! button || ! list.contains( button ) ) {
			return;
		}
		var item = button.closest( ".tr724-tl-item" );
		if ( ! item ) {
			return;
		}
		item.remove();
		reindex();
	} );

	form.addEventListener( "submit", function () {
		reindex();
	} );

	reindex();
} )();
