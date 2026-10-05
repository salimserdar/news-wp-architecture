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

	blocks.registerBlockType( "tr724/authors-recent-wide", {
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
						{ title: __( "Wide Author Recent Posts", "tr724-news" ), initialOpen: true },
						el( TextControl, {
							label: __( "Başlık", "tr724-news" ),
							value: attributes.heading || "",
							onChange: function ( value ) {
								props.setAttributes( { heading: value } );
							},
						} ),
						el( TextControl, {
							label: __( "Başlık bağlantısı", "tr724-news" ),
							value: attributes.headingUrl || "",
							onChange: function ( value ) {
								props.setAttributes( { headingUrl: value } );
							},
						} ),
						el( RangeControl, {
							label: __( "Yazar sayısı", "tr724-news" ),
							value: attributes.postsToShow || 6,
							min: 1,
							max: 18,
							step: 1,
							withInputField: true,
							onChange: function ( value ) {
								var count = parseInt( value, 10 );
								if ( ! count || count < 1 ) count = 1;
								if ( count > 18 ) count = 18;
								props.setAttributes( { postsToShow: count } );
							},
						} )
					)
				),
				el(
					"div",
					useBlockProps(),
					el( ServerSideRender, {
						block: "tr724/authors-recent-wide",
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
