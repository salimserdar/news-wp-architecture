( function ( blocks, element, blockEditor, components, data, serverSideRender, i18n ) {
	var el = element.createElement;
	var Fragment = element.Fragment;
	var useBlockProps = blockEditor.useBlockProps;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var RangeControl = components.RangeControl;
	var SelectControl = components.SelectControl;
	var TextControl = components.TextControl;
	var useSelect = data.useSelect;
	var ServerSideRender = serverSideRender.default || serverSideRender;
	var __ = i18n.__;

	blocks.registerBlockType( "tr724/spotlight", {
		edit: function ( props ) {
			var attributes = props.attributes;
			var categories = useSelect( function ( select ) {
				return select( "core" ).getEntityRecords( "taxonomy", "category", {
					per_page: 100,
					orderby: "name",
					order: "asc",
					hide_empty: true,
					_fields: "id,name",
				} );
			}, [] );

			var options = [
				{ label: __( "All categories", "tr724-news" ), value: "0" },
			];
			( categories || [] ).forEach( function ( category ) {
				options.push( {
					label: category.name,
					value: String( category.id ),
				} );
			} );

			var adminUrl = window.tr724MansetAdmin || "";
			var ui = window.tr724MansetUi || {};
			var mansetId = parseInt( window.tr724MansetCategoryId, 10 ) || 0;
			var categoryId = parseInt( attributes.categoryId, 10 ) || 0;
			var usesQueue = categoryId === 0 || ( mansetId > 0 && categoryId === mansetId );

			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( "Spotlight", "tr724-news" ), initialOpen: true },
						usesQueue
							? el(
								"p",
								null,
								ui.help || __( "Order comes from Editorial → Headlines. This block cannot override it.", "tr724-news" )
							)
							: null,
						usesQueue && adminUrl
							? el(
								"p",
								null,
								el(
									"a",
									{
										href: adminUrl,
										target: "_blank",
										rel: "noopener noreferrer",
									},
									ui.edit || __( "Edit headline order", "tr724-news" )
								)
							)
							: null,
						el( SelectControl, {
							label: __( "Category", "tr724-news" ),
							value: String( attributes.categoryId || 0 ),
							options: options,
							onChange: function ( value ) {
								props.setAttributes( {
									categoryId: parseInt( value, 10 ) || 0,
								} );
							},
						} ),
						el( RangeControl, {
							label: __( "Posts", "tr724-news" ),
							value: attributes.postsToShow || 15,
							min: 1,
							max: 30,
							step: 1,
							withInputField: true,
							onChange: function ( value ) {
								var count = parseInt( value, 10 );
								if ( ! count || count < 1 ) count = 1;
								if ( count > 30 ) count = 30;
								props.setAttributes( { postsToShow: count } );
							},
						} ),
						el( TextControl, {
							label: __( "More link", "tr724-news" ),
							help: __( "Optional. Makes Devamı open this address.", "tr724-news" ),
							value: attributes.moreUrl || "",
							type: "url",
							onChange: function ( value ) {
								props.setAttributes( { moreUrl: value } );
							},
						} )
					)
				),
				el(
					"div",
					useBlockProps(),
					el( ServerSideRender, {
						block: "tr724/spotlight",
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
