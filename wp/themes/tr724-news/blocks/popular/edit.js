( function ( blocks, element, blockEditor, components, serverSideRender, i18n ) {
	var el = element.createElement;
	var Fragment = element.Fragment;
	var useBlockProps = blockEditor.useBlockProps;
	var InspectorControls = blockEditor.InspectorControls;
	var MediaUpload = blockEditor.MediaUpload;
	var MediaUploadCheck = blockEditor.MediaUploadCheck;
	var PanelBody = components.PanelBody;
	var TextControl = components.TextControl;
	var Button = components.Button;
	var ServerSideRender = serverSideRender.default || serverSideRender;
	var __ = i18n.__;

	blocks.registerBlockType( "tr724/popular", {
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
						{ title: __( "Ad", "tr724-news" ), initialOpen: true },
						el( MediaUploadCheck, null, el( MediaUpload, {
							onSelect: function ( media ) {
								props.setAttributes( { adImageId: media && media.id ? media.id : 0 } );
							},
							allowedTypes: [ "image" ],
							value: attributes.adImageId || 0,
							render: function ( obj ) {
								return el(
									Button,
									{ variant: "secondary", onClick: obj.open },
									attributes.adImageId
										? __( "Replace image", "tr724-news" )
										: __( "Select image", "tr724-news" )
								);
							},
						} ) ),
						attributes.adImageId
							? el(
								Button,
								{
									variant: "link",
									isDestructive: true,
									onClick: function () {
										props.setAttributes( { adImageId: 0 } );
									},
								},
								__( "Remove image", "tr724-news" )
							)
							: null,
						el( TextControl, {
							label: __( "Link", "tr724-news" ),
							help: __( "Where the image ad goes. Leave empty to show the image without a link.", "tr724-news" ),
							value: attributes.adUrl || "",
							onChange: function ( value ) {
								props.setAttributes( { adUrl: value } );
							},
						} ),
						el( TextControl, {
							label: __( "Label", "tr724-news" ),
							value: attributes.adLabel || "",
							onChange: function ( value ) {
								props.setAttributes( { adLabel: value } );
							},
						} )
					)
				),
				el(
					"div",
					useBlockProps(),
					el( ServerSideRender, {
						block: "tr724/popular",
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
