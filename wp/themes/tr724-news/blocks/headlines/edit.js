( function ( blocks, element, blockEditor, components, serverSideRender, i18n ) {
	var el = element.createElement;
	var Fragment = element.Fragment;
	var useBlockProps = blockEditor.useBlockProps;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var RangeControl = components.RangeControl;
	var ServerSideRender = serverSideRender.default || serverSideRender;
	var __ = i18n.__;

	blocks.registerBlockType( "tr724/headlines", {
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
						{ title: __( "Günün Manşetleri", "tr724-news" ), initialOpen: true },
						el( RangeControl, {
							label: __( "Posts", "tr724-news" ),
							value: attributes.postsToShow || 15,
							min: 4,
							max: 20,
							step: 1,
							withInputField: true,
							onChange: function ( value ) {
								var count = parseInt( value, 10 );
								if ( ! count || count < 4 ) count = 4;
								if ( count > 20 ) count = 20;
								props.setAttributes( { postsToShow: count } );
							},
						} )
					)
				),
				el(
					"div",
					useBlockProps(),
					el( ServerSideRender, {
						block: "tr724/headlines",
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
