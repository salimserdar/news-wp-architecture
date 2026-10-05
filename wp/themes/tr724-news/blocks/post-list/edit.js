( function ( blocks, element, blockEditor, components, data, serverSideRender, i18n ) {
	var el = element.createElement;
	var Fragment = element.Fragment;
	var useBlockProps = blockEditor.useBlockProps;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var RangeControl = components.RangeControl;
	var FormTokenField = components.FormTokenField;
	var TextControl = components.TextControl;
	var ToggleControl = components.ToggleControl;
	var useSelect = data.useSelect;
	var ServerSideRender = serverSideRender.default || serverSideRender;
	var __ = i18n.__;

	var termQuery = {
		per_page: 100,
		orderby: "name",
		order: "asc",
		hide_empty: true,
		_fields: "id,name,slug",
	};

	function clamp( value, min, max, fallback ) {
		var number = parseInt( value, 10 );
		if ( isNaN( number ) ) {
			return fallback;
		}
		if ( number < min ) {
			return min;
		}
		if ( number > max ) {
			return max;
		}
		return number;
	}

	function selectedTermIds( attributes ) {
		var ids = [];
		var seen = {};
		var list = attributes.categoryIds;
		if ( Array.isArray( list ) ) {
			list.forEach( function ( value ) {
				var id = parseInt( value, 10 ) || 0;
				if ( id > 0 && ! seen[ id ] ) {
					seen[ id ] = true;
					ids.push( id );
				}
			} );
		}
		return ids;
	}

	function termLabels( terms ) {
		var nameCount = {};
		( terms || [] ).forEach( function ( term ) {
			nameCount[ term.name ] = ( nameCount[ term.name ] || 0 ) + 1;
		} );
		var byId = {};
		( terms || [] ).forEach( function ( term ) {
			byId[ term.id ] = nameCount[ term.name ] > 1
				? term.name + " (" + term.slug + ")"
				: term.name;
		} );
		return byId;
	}

	function tokensForIds( ids, labels ) {
		return ids.map( function ( id ) {
			return labels[ id ] || ( "#" + id );
		} );
	}

	function idsForTokens( tokens, terms ) {
		var labels = termLabels( terms );
		var idByLabel = {};
		Object.keys( labels ).forEach( function ( id ) {
			idByLabel[ labels[ id ] ] = parseInt( id, 10 );
		} );
		var ids = [];
		var seen = {};
		( tokens || [] ).forEach( function ( token ) {
			var label = "";
			if ( "string" === typeof token ) {
				label = token;
			} else if ( token && "string" === typeof token.value ) {
				label = token.value;
			}
			var id = idByLabel[ label ] || 0;
			if ( ! id ) {
				var hashed = /^#(\d+)$/.exec( label );
				id = hashed ? ( parseInt( hashed[ 1 ], 10 ) || 0 ) : 0;
			}
			if ( id > 0 && ! seen[ id ] ) {
				seen[ id ] = true;
				ids.push( id );
			}
		} );
		return ids;
	}

	function sameIds( left, right ) {
		if ( left.length !== right.length ) {
			return false;
		}
		for ( var index = 0; index < left.length; index++ ) {
			if ( left[ index ] !== right[ index ] ) {
				return false;
			}
		}
		return true;
	}

	function useCategoryTerms( selectedKey ) {
		return useSelect(
			function ( select ) {
				var core = select( "core" );
				var records = core.getEntityRecords( "taxonomy", "category", termQuery );
				if ( ! records ) {
					return null;
				}
				var selectedIds = selectedKey
					? selectedKey.split( "," ).map( function ( part ) {
						return parseInt( part, 10 ) || 0;
					} ).filter( function ( id ) {
						return id > 0;
					} )
					: [];
				var known = {};
				records.forEach( function ( term ) {
					known[ term.id ] = true;
				} );
				var missing = selectedIds.filter( function ( id ) {
					return ! known[ id ];
				} );
				if ( ! missing.length ) {
					return records;
				}
				var extra = core.getEntityRecords( "taxonomy", "category", {
					include: missing.join( "," ),
					per_page: missing.length,
					hide_empty: false,
					_fields: "id,name,slug",
				} );
				if ( ! extra ) {
					return records;
				}
				return records.concat( extra );
			},
			[ selectedKey ]
		);
	}

	blocks.registerBlockType( "tr724/post-list", {
		edit: function ( props ) {
			var attributes = props.attributes;
			var categoryIds = selectedTermIds( attributes );
			var categories = useCategoryTerms( categoryIds.join( "," ) );
			var showMore = attributes.showMore !== false;
			var terms = categories || [];
			var labels = termLabels( terms );
			var suggestions = [];
			var seen = {};

			terms.forEach( function ( term ) {
				var label = labels[ term.id ];
				if ( label && ! seen[ label ] ) {
					seen[ label ] = true;
					suggestions.push( label );
				}
			} );
			suggestions.sort( function ( a, b ) {
				return a.localeCompare( b, "tr" );
			} );

			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( "Post list", "tr724-news" ), initialOpen: true },
						el( TextControl, {
							label: __( "Heading", "tr724-news" ),
							help: __( "Leave empty to use the category name when one category is selected.", "tr724-news" ),
							value: attributes.heading || "",
							onChange: function ( value ) {
								props.setAttributes( { heading: value } );
							},
						} ),
						el( FormTokenField, {
							label: __( "Categories", "tr724-news" ),
							help: __( "Show posts in any of these categories. Leave empty for all categories.", "tr724-news" ),
							placeholder: __( "Search categories", "tr724-news" ),
							value: categories ? tokensForIds( categoryIds, labels ) : [],
							suggestions: suggestions,
							maxSuggestions: 20,
							tokenizeOnSpace: false,
							__experimentalExpandOnFocus: true,
							__experimentalAutoSelectFirstMatch: true,
							__experimentalShowHowTo: false,
							onChange: function ( tokens ) {
								if ( ! categories ) {
									return;
								}
								var nextIds = idsForTokens( tokens, categories );
								if ( sameIds( nextIds, categoryIds ) ) {
									return;
								}
								props.setAttributes( { categoryIds: nextIds } );
							},
						} ),
						el( RangeControl, {
							label: __( "Posts", "tr724-news" ),
							help: __( "How many posts to show.", "tr724-news" ),
							value: attributes.postsToShow || 8,
							min: 1,
							max: 24,
							step: 1,
							withInputField: true,
							onChange: function ( value ) {
								props.setAttributes( {
									postsToShow: clamp( value, 1, 24, 8 ),
								} );
							},
						} ),
						el( ToggleControl, {
							label: __( "Show more link", "tr724-news" ),
							checked: showMore,
							onChange: function ( value ) {
								props.setAttributes( { showMore: !! value } );
							},
						} ),
						showMore
							? el( TextControl, {
								label: __( "More label", "tr724-news" ),
								value: attributes.moreLabel || "",
								onChange: function ( value ) {
									props.setAttributes( { moreLabel: value } );
								},
							} )
							: null,
						showMore
							? el( TextControl, {
								label: __( "More link", "tr724-news" ),
								help: __( "Optional. Defaults to the archive when one category is selected.", "tr724-news" ),
								value: attributes.moreUrl || "",
								onChange: function ( value ) {
									props.setAttributes( { moreUrl: value } );
								},
							} )
							: null
					)
				),
				el(
					"div",
					useBlockProps(),
					el( ServerSideRender, {
						block: "tr724/post-list",
						attributes: attributes,
					} )
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.data,
	window.wp.serverSideRender,
	window.wp.i18n
);
