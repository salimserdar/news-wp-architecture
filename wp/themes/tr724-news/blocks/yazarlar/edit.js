( function ( blocks, element, blockEditor, components, serverSideRender, i18n ) {
	var el = element.createElement;
	var Fragment = element.Fragment;
	var useBlockProps = blockEditor.useBlockProps;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var TextControl = components.TextControl;
	var RangeControl = components.RangeControl;
	var SelectControl = components.SelectControl;
	var ToggleControl = components.ToggleControl;
	var ServerSideRender = serverSideRender.default || serverSideRender;
	var __ = i18n.__;

	blocks.registerBlockType( "tr724/yazarlar", {
		edit: function ( props ) {
			var attributes = props.attributes;
			var role = attributes.role || "author";
			var collapse = !! attributes.collapseInitially;
			var roleOptions = ( window.tr724YazarlarRoles || [
				{ value: "author", label: __( "Author", "tr724-news" ) },
			] ).slice();
			if (
				! roleOptions.some( function ( option ) {
					return option.value === role;
				} )
			) {
				roleOptions.unshift( { value: role, label: role } );
			}

			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( "Yazarlar", "tr724-news" ), initialOpen: true },
						el( TextControl, {
							label: __( "Section title", "tr724-news" ),
							value: attributes.sectionTitle || "",
							onChange: function ( value ) {
								props.setAttributes( { sectionTitle: value } );
							},
						} ),
						el( SelectControl, {
							label: __( "Role", "tr724-news" ),
							value: role,
							options: roleOptions,
							onChange: function ( value ) {
								props.setAttributes( { role: value || "author" } );
							},
						} ),
						el( RangeControl, {
							label: __( "Authors per row", "tr724-news" ),
							value: attributes.columns || 4,
							min: 1,
							max: 6,
							step: 1,
							withInputField: true,
							onChange: function ( value ) {
								var count = parseInt( value, 10 );
								if ( ! count || count < 1 ) count = 1;
								if ( count > 6 ) count = 6;
								props.setAttributes( { columns: count } );
							},
						} ),
						el( ToggleControl, {
							label: __( "Start collapsed", "tr724-news" ),
							help: __( "Hide every author until a visitor opens the list. The button states how many authors are in the archive. Off shows every author.", "tr724-news" ),
							checked: collapse,
							onChange: function ( value ) {
								props.setAttributes( { collapseInitially: !! value } );
							},
						} )
					)
				),
				el(
					"div",
					useBlockProps(),
					el( ServerSideRender, {
						block: "tr724/yazarlar",
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
	window.wp.serverSideRender,
	window.wp.i18n
);
