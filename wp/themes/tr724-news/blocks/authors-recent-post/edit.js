( function ( blocks, element, blockEditor, components, serverSideRender, i18n ) {
	var el = element.createElement;
	var Fragment = element.Fragment;
	var useBlockProps = blockEditor.useBlockProps;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var RangeControl = components.RangeControl;
	var TextControl = components.TextControl;
	var ServerSideRender = serverSideRender.default || serverSideRender;
	var __ = i18n.__;

	blocks.registerBlockType( "tr724/authors-recent-post", {
		edit: function ( props ) {
			var attributes = props.attributes;

			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( "Authors Recent Post", "tr724-news" ), initialOpen: true },
						el( TextControl, {
							label: __( "Heading", "tr724-news" ),
							value: attributes.heading || "",
							onChange: function ( value ) {
								props.setAttributes( { heading: value } );
							},
						} ),
						el( RangeControl, {
							label: __( "Authors", "tr724-news" ),
							value: attributes.postsToShow || 8,
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
						} )
					)
				),
				el(
					"div",
					useBlockProps(),
					el( ServerSideRender, {
						block: "tr724/authors-recent-post",
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
