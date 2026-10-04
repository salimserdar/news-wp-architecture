( function ( blocks, element, blockEditor, components, data, serverSideRender, apiFetch, i18n ) {
	var el = element.createElement;
	var Fragment = element.Fragment;
	var useState = element.useState;
	var useEffect = element.useEffect;
	var useRef = element.useRef;
	var useBlockProps = blockEditor.useBlockProps;
	var BlockControls = blockEditor.BlockControls;
	var TextControl = components.TextControl;
	var Modal = components.Modal;
	var SelectControl = components.SelectControl;
	var Button = components.Button;
	var ToolbarGroup = components.ToolbarGroup;
	var ToolbarButton = components.ToolbarButton;
	var useSelect = data.useSelect;
	var ServerSideRender = serverSideRender.default || serverSideRender;
	var __ = i18n.__;

	var MAX_POSTS = 8;
	var LIST_HEADING = "İlgili Haberler";
	var SINGLE_HEADING = "İlgili Haber";

	var termQuery = {
		per_page: 100,
		orderby: "name",
		order: "asc",
		hide_empty: true,
		_fields: "id,name",
	};

	function normalizeIds( value ) {
		var ids = [];
		( value || [] ).forEach( function ( id ) {
			var number = parseInt( id, 10 );
			if ( number > 0 && ids.indexOf( number ) === -1 ) {
				ids.push( number );
			}
		} );
		return ids.slice( 0, MAX_POSTS );
	}

	function isDirectQuery( value ) {
		return /^https?:\/\//i.test( value ) || /^(?:#|id\s*:\s*)\d+$/i.test( value ) || /^\d{5,}$/.test( value );
	}

	function escapeRegExp( value ) {
		return value.replace( /[.*+?^${}()|[\]\\]/g, "\\$&" );
	}

	function highlightedTitle( title, query ) {
		var needle = ( query || "" ).trim();
		if ( needle.length < 2 || isDirectQuery( needle ) ) {
			return title;
		}
		var match = String( title || "" ).match( new RegExp( escapeRegExp( needle ), "i" ) );
		if ( ! match || match.index === undefined ) {
			return title;
		}
		return el(
			Fragment,
			null,
			title.slice( 0, match.index ),
			el( "mark", null, match[0] ),
			title.slice( match.index + match[0].length )
		);
	}

	function metaLine( post ) {
		var parts = [];
		if ( post.category ) {
			parts.push( post.category );
		}
		if ( post.date ) {
			parts.push( post.date );
		}
		return parts.join( " · " );
	}

	function thumb( post ) {
		if ( post && post.thumb ) {
			return el(
				"span",
				{ className: "related-news-editor__thumb" },
				el( "img", { src: post.thumb, alt: "" } )
			);
		}
		return el( "span", { className: "related-news-editor__thumb", "aria-hidden": "true" } );
	}

	function RelatedNewsEdit( props ) {
		var attributes = props.attributes;
		var postIds = normalizeIds( attributes.postIds );
		var idsKey = postIds.join( "," );
		var layout = attributes.layout === "single" ? "single" : "list";
		var currentPostId = props.context && props.context.postId ? parseInt( props.context.postId, 10 ) : 0;

		var openState = useState( false );
		var isOpen = openState[0];
		var setIsOpen = openState[1];
		var openedOnce = useRef( false );

		var queryState = useState( "" );
		var query = queryState[0];
		var setQuery = queryState[1];

		var categoryState = useState( 0 );
		var categoryId = categoryState[0];
		var setCategoryId = categoryState[1];

		var resultsState = useState( [] );
		var results = resultsState[0];
		var setResults = resultsState[1];

		var searchState = useState( "loading" );
		var searchStatus = searchState[0];
		var setSearchStatus = searchState[1];

		var selectedState = useState( {} );
		var selected = selectedState[0];
		var setSelected = selectedState[1];

		var pickedState = useState( "ready" );
		var pickedStatus = pickedState[0];
		var setPickedStatus = pickedState[1];

		var searchRequest = useRef( 0 );
		var selectedRequest = useRef( 0 );

		var categories = useSelect( function ( select ) {
			return select( "core" ).getEntityRecords( "taxonomy", "category", termQuery );
		}, [] );

		var trimmed = query.trim();
		var textSearch = trimmed.length >= 2 || isDirectQuery( trimmed );
		var requestSearch = textSearch ? trimmed : "";
		var requestKey = requestSearch + "\n" + String( categoryId ) + "\n" + String( currentPostId );

		useEffect( function () {
			if ( ! requestSearch ) {
				setResults( [] );
				setSearchStatus( "ready" );
				return undefined;
			}

			var requestId = searchRequest.current + 1;
			searchRequest.current = requestId;

			var handle = window.setTimeout( function () {
				setSearchStatus( "loading" );
				var path = "/tr724/v1/related-news?search=" + encodeURIComponent( requestSearch );
				if ( categoryId ) {
					path += "&category=" + encodeURIComponent( String( categoryId ) );
				}
				if ( currentPostId ) {
					path += "&exclude=" + encodeURIComponent( String( currentPostId ) );
				}
				apiFetch( { path: path } )
					.then( function ( data ) {
						if ( requestId !== searchRequest.current ) {
							return;
						}
						setResults( ( data && data.posts ) || [] );
						setSearchStatus( "ready" );
					} )
					.catch( function () {
						if ( requestId !== searchRequest.current ) {
							return;
						}
						setResults( [] );
						setSearchStatus( "error" );
					} );
			}, requestSearch ? 280 : 0 );

			return function () {
				window.clearTimeout( handle );
			};
		}, [ requestKey ] );

		useEffect( function () {
			if ( ! idsKey ) {
				setSelected( {} );
				setPickedStatus( "ready" );
				return undefined;
			}
			var requestId = selectedRequest.current + 1;
			selectedRequest.current = requestId;
			setPickedStatus( "loading" );
			apiFetch( { path: "/tr724/v1/related-news?include=" + idsKey } )
				.then( function ( data ) {
					if ( requestId !== selectedRequest.current ) {
						return;
					}
					var map = {};
					( ( data && data.posts ) || [] ).forEach( function ( post ) {
						map[ post.id ] = post;
					} );
					setSelected( map );
					setPickedStatus( "ready" );
				} )
				.catch( function () {
					if ( requestId !== selectedRequest.current ) {
						return;
					}
					setPickedStatus( "error" );
				} );
			return undefined;
		}, [ idsKey ] );

		function setLayout( next ) {
			var patch = { layout: next };
			var current = attributes.heading || "";
			if ( "single" === next && ( "" === current || current === LIST_HEADING ) ) {
				patch.heading = SINGLE_HEADING;
			}
			if ( "list" === next && ( "" === current || current === SINGLE_HEADING ) ) {
				patch.heading = LIST_HEADING;
			}
			props.setAttributes( patch );
		}

		function addPost( id ) {
			var number = parseInt( id, 10 );
			if ( ! number || number === currentPostId || postIds.indexOf( number ) !== -1 || postIds.length >= MAX_POSTS ) {
				return;
			}
			props.setAttributes( { postIds: postIds.concat( [ number ] ) } );
		}

		function removePost( id ) {
			props.setAttributes( {
				postIds: postIds.filter( function ( item ) {
					return item !== id;
				} ),
			} );
		}

		function movePost( index, delta ) {
			var target = index + delta;
			if ( target < 0 || target >= postIds.length ) {
				return;
			}
			var next = postIds.slice();
			var moved = next[ index ];
			next.splice( index, 1 );
			next.splice( target, 0, moved );
			props.setAttributes( { postIds: next } );
		}

		var categoryOptions = [ { label: __( "All categories", "tr724-news" ), value: "0" } ];
		( categories || [] ).forEach( function ( term ) {
			categoryOptions.push( {
				label: term.name,
				value: String( term.id ),
			} );
		} );

		var statusText = "";
		if ( "error" === searchStatus ) {
			statusText = __( "Search failed. Try again.", "tr724-news" );
		} else if ( "loading" === searchStatus && ! results.length ) {
			statusText = __( "Searching…", "tr724-news" );
		} else if ( ! textSearch ) {
			statusText = __( "Type a title, paste a link, or enter an ID.", "tr724-news" );
		} else if ( ! results.length ) {
			statusText = __( "No stories matched.", "tr724-news" );
		}

		var hint = "single" === layout
			? __( "Readers see only the first story. Reorder the list to choose it.", "tr724-news" )
			: __( "Readers see every story, in this order.", "tr724-news" );

		useEffect( function () {
			if ( openedOnce.current || ! props.isSelected || postIds.length ) {
				return undefined;
			}
			openedOnce.current = true;
			setIsOpen( true );
			return undefined;
		}, [ props.isSelected, postIds.length ] );

		return el(
			Fragment,
			null,
			el(
				BlockControls,
				null,
				el(
					ToolbarGroup,
					null,
					el( ToolbarButton, {
						icon: "search",
						text: __( "Select stories", "tr724-news" ),
						label: __( "Select stories", "tr724-news" ),
						onClick: function () {
							setIsOpen( true );
						},
					} )
				)
			),
			isOpen
				? el(
					Modal,
					{
						title: __( "Related news", "tr724-news" ),
						className: "related-news-editor__modal",
						onRequestClose: function () {
							setIsOpen( false );
						},
					},
					el( "div", { className: "related-news-editor" },
					el(
						"div",
						{ className: "related-news-editor__layout", role: "group", "aria-label": __( "Layout", "tr724-news" ) },
						el(
							Button,
							{
								variant: "list" === layout ? "primary" : "secondary",
								onClick: function () {
									setLayout( "list" );
								},
							},
							__( "List", "tr724-news" )
						),
						el(
							Button,
							{
								variant: "single" === layout ? "primary" : "secondary",
								onClick: function () {
									setLayout( "single" );
								},
							},
							__( "Single story", "tr724-news" )
						)
					),
					el( "p", { className: "related-news-editor__hint" }, hint ),
					el( TextControl, {
						className: "related-news-editor__heading",
						label: __( "Heading", "tr724-news" ),
						value: attributes.heading || "",
						onChange: function ( value ) {
							props.setAttributes( { heading: value } );
						},
						help: __( "Shown above the stories. Leave empty to hide it.", "tr724-news" ),
					} ),
					el(
						"div",
						{ className: "related-news-editor__columns" },
						el(
							"div",
							{ className: "related-news-editor__find" },
							el(
								"div",
								{ className: "related-news-editor__filters" },
						el(
							"label",
							{ className: "related-news-editor__field" },
							el( "span", { className: "related-news-editor__field-label" }, __( "Search", "tr724-news" ) ),
							el( "input", {
								className: "related-news-editor__search",
								type: "search",
								value: query,
								placeholder: __( "Title, link, or ID", "tr724-news" ),
								autoComplete: "off",
								autoFocus: true,
								onChange: function ( event ) {
									setQuery( event.target.value );
								},
								onKeyDown: function ( event ) {
									if ( "Enter" !== event.key ) {
										return;
									}
									event.preventDefault();
									var first = results.filter( function ( post ) {
										return postIds.indexOf( post.id ) === -1;
									} )[0];
									if ( first ) {
										addPost( first.id );
									}
								},
							} )
						),
						el( SelectControl, {
							label: __( "Category", "tr724-news" ),
							value: String( categoryId || 0 ),
							options: categoryOptions,
							onChange: function ( value ) {
								setCategoryId( parseInt( value, 10 ) || 0 );
							},
						} )
					),
					el(
						"p",
						{
							className: "related-news-editor__status" + ( "error" === searchStatus ? " related-news-editor__status--error" : "" ),
							"aria-live": "polite",
						},
						statusText
					),
					results.length
						? el(
							"ul",
							{ className: "related-news-editor__results" },
							results.map( function ( post ) {
								var added = postIds.indexOf( post.id ) !== -1;
								var full = postIds.length >= MAX_POSTS && ! added;
								return el(
									"li",
									{ key: post.id },
									el(
										"button",
										{
											type: "button",
											className: "related-news-editor__result",
											disabled: added || full,
											onClick: function () {
												addPost( post.id );
											},
										},
										thumb( post ),
										el(
											"span",
											{ className: "related-news-editor__copy" },
											metaLine( post )
												? el( "span", { className: "related-news-editor__meta" }, metaLine( post ) )
												: null,
											el( "span", { className: "related-news-editor__name" }, highlightedTitle( post.title, query ) )
										),
										el(
											"span",
											{ className: added ? "related-news-editor__badge" : "related-news-editor__add" },
											added
												? __( "Added", "tr724-news" )
												: __( "Add", "tr724-news" )
										)
									)
								);
							} )
						)
						: null
					),
					el(
						"div",
						{ className: "related-news-editor__manage" },
						el(
							"div",
							{ className: "related-news-editor__selected-label" },
						el( "span", null, __( "Selected", "tr724-news" ) ),
						el( "span", { className: "related-news-editor__count" }, postIds.length + " / " + MAX_POSTS )
					),
					postIds.length
						? el(
							"ul",
							{ className: "related-news-editor__selected" },
							postIds.map( function ( id, index ) {
								var post = selected[ id ];
								return el(
									"li",
									{ key: id, className: "related-news-editor__picked" },
									thumb( post ),
									el(
										"span",
										{ className: "related-news-editor__copy" },
										post
											? el(
												Fragment,
												null,
												metaLine( post )
													? el( "span", { className: "related-news-editor__meta" }, metaLine( post ) )
													: null,
												el( "span", { className: "related-news-editor__name" }, post.title ),
												"single" === layout && 0 === index
													? el( "span", { className: "related-news-editor__shown" }, __( "Shown to readers", "tr724-news" ) )
													: null
											)
											: el(
												"span",
												{
													className: "loading" === pickedStatus
														? "related-news-editor__meta"
														: "related-news-editor__missing",
												},
												"loading" === pickedStatus
													? __( "Loading…", "tr724-news" )
													: __( "Story unavailable", "tr724-news" )
											)
									),
									el(
										"span",
										{ className: "related-news-editor__actions" },
										el( Button, {
											icon: "arrow-up-alt2",
											label: __( "Move up", "tr724-news" ),
											disabled: 0 === index,
											onClick: function () {
												movePost( index, -1 );
											},
										} ),
										el( Button, {
											icon: "arrow-down-alt2",
											label: __( "Move down", "tr724-news" ),
											disabled: index === postIds.length - 1,
											onClick: function () {
												movePost( index, 1 );
											},
										} ),
										el( Button, {
											icon: "no-alt",
											label: __( "Remove", "tr724-news" ),
											onClick: function () {
												removePost( id );
											},
										} )
									)
								);
							} )
						)
						: el(
							"p",
							{ className: "related-news-editor__hint" },
							__( "Nothing selected yet.", "tr724-news" )
						)
					)
				),
				el(
					"div",
					{ className: "related-news-editor__footer" },
					el(
						Button,
						{
							variant: "primary",
							onClick: function () {
								setIsOpen( false );
							},
						},
						__( "Done", "tr724-news" )
					)
				)
			)
		) : null,
			el(
				"div",
				useBlockProps(),
				el( ServerSideRender, {
					block: "tr724/related-news",
					attributes: {
						postIds: postIds,
						layout: layout,
						heading: attributes.heading || "",
					},
					urlQueryArgs: currentPostId ? { post_id: currentPostId } : undefined,
				} )
			)
		);
	}

	blocks.registerBlockType( "tr724/related-news", {
		usesContext: [ "postId" ],
		edit: RelatedNewsEdit,
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
	window.wp.apiFetch,
	window.wp.i18n
);
