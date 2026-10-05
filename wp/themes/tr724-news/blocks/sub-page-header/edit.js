( function ( blocks, element, blockEditor, components, serverSideRender, i18n ) {
	var el = element.createElement;
	var Fragment = element.Fragment;
	var useBlockProps = blockEditor.useBlockProps;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var TextControl = components.TextControl;
	var ServerSideRender = serverSideRender.default || serverSideRender;
	var __ = i18n.__;

	blocks.registerBlockType( "tr724/sub-page-header", {
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
						{ title: __( "Sub Page Header", "tr724-news" ), initialOpen: true },
						el( TextControl, {
							label: __( "Başlık", "tr724-news" ),
							help: __( "Boş bırakılırsa sayfa başlığı kullanılır.", "tr724-news" ),
							value: attributes.title || "",
							onChange: function ( value ) {
								props.setAttributes( { title: value } );
							},
						} ),
						el( TextControl, {
							label: __( "Filigran", "tr724-news" ),
							help: __( "Boş bırakılırsa başlıkla aynı metin kullanılır.", "tr724-news" ),
							value: attributes.watermark || "",
							onChange: function ( value ) {
								props.setAttributes( { watermark: value } );
							},
						} )
					)
				),
				el(
					"div",
					useBlockProps(),
					el( ServerSideRender, {
						block: "tr724/sub-page-header",
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
