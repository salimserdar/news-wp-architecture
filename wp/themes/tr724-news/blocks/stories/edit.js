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

	function selectedTermIds( attributes, listKey, legacyKey ) {
		var ids = [];
		var seen = {};
		var list = attributes[ listKey ];
		if ( Array.isArray( list ) ) {
			list.forEach( function ( value ) {
				var id = parseInt( value, 10 ) || 0;
				if ( id > 0 && ! seen[ id ] ) {
					seen[ id ] = true;
					ids.push( id );
				}
			} );
		}
		var legacy = parseInt( attributes[ legacyKey ], 10 ) || 0;
		if ( legacy > 0 && ! seen[ legacy ] ) {
			ids.push( legacy );
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

	function useTaxonomyTerms( taxonomy, selectedKey ) {
		return useSelect(
			function ( select ) {
				var core = select( "core" );
				var records = core.getEntityRecords( "taxonomy", taxonomy, termQuery );
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
				var extra = core.getEntityRecords( "taxonomy", taxonomy, {
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
			[ taxonomy, selectedKey ]
		);
	}

	blocks.registerBlockType( "tr724/stories", {
		edit: function ( props ) {
			var attributes = props.attributes;
			var categoryIds = selectedTermIds( attributes, "categoryIds", "categoryId" );
			var tagIds = selectedTermIds( attributes, "tagIds", "tagId" );
			var categories = useTaxonomyTerms( "category", categoryIds.join( "," ) );
			var tags = useTaxonomyTerms( "post_tag", tagIds.join( "," ) );

			var showMore = attributes.showMore !== false;

			function taxonomyControl( config ) {
				var terms = config.terms || [];
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
				return el( FormTokenField, {
					label: config.label,
					help: config.help,
					placeholder: config.placeholder,
					value: config.terms ? tokensForIds( config.ids, labels ) : [],
					suggestions: suggestions,
					maxSuggestions: 20,
					tokenizeOnSpace: false,
					__experimentalExpandOnFocus: true,
					__experimentalAutoSelectFirstMatch: true,
					__experimentalShowHowTo: false,
					onChange: function ( tokens ) {
						if ( ! config.terms ) {
							return;
						}
						var nextIds = idsForTokens( tokens, config.terms );
						var legacy = parseInt( attributes[ config.legacyAttribute ], 10 ) || 0;
						if ( sameIds( nextIds, config.ids ) && ! legacy ) {
							return;
						}
						var next = {};
						next[ config.idsAttribute ] = nextIds;
						next[ config.legacyAttribute ] = 0;
						props.setAttributes( next );
					},
				} );
			}

			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( "Stories", "tr724-news" ), initialOpen: true },
						taxonomyControl( {
							label: __( "Categories", "tr724-news" ),
							help: __( "Show posts in any of these categories. Leave empty for all categories.", "tr724-news" ),
							placeholder: __( "Search categories", "tr724-news" ),
							terms: categories,
							ids: categoryIds,
							idsAttribute: "categoryIds",
							legacyAttribute: "categoryId",
						} ),
						taxonomyControl( {
							label: __( "Tags", "tr724-news" ),
							help: __( "Show posts with any of these tags. When categories are also set, a post must match both.", "tr724-news" ),
							placeholder: __( "Search tags", "tr724-news" ),
							terms: tags,
							ids: tagIds,
							idsAttribute: "tagIds",
							legacyAttribute: "tagId",
						} ),
						el( RangeControl, {
							label: __( "Posts", "tr724-news" ),
							help: __( "How many cards to show.", "tr724-news" ),
							value: attributes.postsToShow || 6,
							min: 1,
							max: 24,
							step: 1,
							withInputField: true,
							onChange: function ( value ) {
								props.setAttributes( {
									postsToShow: clamp( value, 1, 24, 6 ),
								} );
							},
						} ),
						el( RangeControl, {
							label: __( "Skip first", "tr724-news" ),
							help: __( "Leave out this many posts in the filtered list. Skip 15 to start after the 15th post — for example, 6 posts from a category beginning at post 16.", "tr724-news" ),
							value: attributes.offset || 0,
							min: 0,
							max: 200,
							step: 1,
							withInputField: true,
							onChange: function ( value ) {
								props.setAttributes( {
									offset: clamp( value, 0, 200, 0 ),
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
								help: __( "Optional. Defaults to the archive when one category or one tag is selected.", "tr724-news" ),
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
						block: "tr724/stories",
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
