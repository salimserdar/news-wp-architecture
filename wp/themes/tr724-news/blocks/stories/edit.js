( function ( blocks, element, blockEditor, components, data, serverSideRender, i18n ) {
	var el = element.createElement;
	var Fragment = element.Fragment;
	var useBlockProps = blockEditor.useBlockProps;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var RangeControl = components.RangeControl;
	var SelectControl = components.SelectControl;
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
		_fields: "id,name",
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

	function termOptions( terms, allLabel ) {
		var options = [ { label: allLabel, value: "0" } ];
		( terms || [] ).forEach( function ( term ) {
			options.push( {
				label: term.name,
				value: String( term.id ),
			} );
		} );
		return options;
	}

	blocks.registerBlockType( "tr724/stories", {
		edit: function ( props ) {
			var attributes = props.attributes;
			var categories = useSelect( function ( select ) {
				return select( "core" ).getEntityRecords( "taxonomy", "category", termQuery );
			}, [] );
			var tags = useSelect( function ( select ) {
				return select( "core" ).getEntityRecords( "taxonomy", "post_tag", termQuery );
			}, [] );

			var showMore = attributes.showMore !== false;

			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( "Stories", "tr724-news" ), initialOpen: true },
						el( SelectControl, {
							label: __( "Category", "tr724-news" ),
							value: String( attributes.categoryId || 0 ),
							options: termOptions( categories, __( "All categories", "tr724-news" ) ),
							onChange: function ( value ) {
								props.setAttributes( {
									categoryId: parseInt( value, 10 ) || 0,
								} );
							},
						} ),
						el( SelectControl, {
							label: __( "Tag", "tr724-news" ),
							value: String( attributes.tagId || 0 ),
							options: termOptions( tags, __( "All tags", "tr724-news" ) ),
							onChange: function ( value ) {
								props.setAttributes( {
									tagId: parseInt( value, 10 ) || 0,
								} );
							},
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
								help: __( "Optional. Defaults to the category archive, then the tag archive.", "tr724-news" ),
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
