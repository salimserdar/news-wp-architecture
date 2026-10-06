( function ( blocks, element, blockEditor, components, data, serverSideRender, i18n ) {
	var el = element.createElement;
	var Fragment = element.Fragment;
	var useBlockProps = blockEditor.useBlockProps;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var SelectControl = components.SelectControl;
	var TextControl = components.TextControl;
	var useSelect = data.useSelect;
	var ServerSideRender = serverSideRender.default || serverSideRender;
	var __ = i18n.__;

	blocks.registerBlockType( "tr724/exchange", {
		edit: function ( props ) {
			var attributes = props.attributes;
			var categories = useSelect( function ( select ) {
				return select( "core" ).getEntityRecords( "taxonomy", "category", {
					per_page: 100,
					orderby: "name",
					order: "asc",
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

			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( "Markets", "tr724-news" ), initialOpen: true },
						el( SelectControl, {
							label: __( "Category", "tr724-news" ),
							help: __( "Shown only on this category archive. All categories keeps it on every sidebar that includes the block.", "tr724-news" ),
							value: String( attributes.categoryId || 0 ),
							options: options,
							onChange: function ( value ) {
								props.setAttributes( {
									categoryId: parseInt( value, 10 ) || 0,
								} );
							},
						} ),
						el( TextControl, {
							label: __( "Title", "tr724-news" ),
							value: attributes.title || "",
							onChange: function ( value ) {
								props.setAttributes( { title: value } );
							},
						} )
					)
				),
				el(
					"div",
					useBlockProps(),
					el( ServerSideRender, {
						block: "tr724/exchange",
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
