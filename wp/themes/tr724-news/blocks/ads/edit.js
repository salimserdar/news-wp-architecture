( function ( blocks, element, blockEditor, components, serverSideRender, i18n ) {
	var el = element.createElement;
	var Fragment = element.Fragment;
	var useBlockProps = blockEditor.useBlockProps;
	var InspectorControls = blockEditor.InspectorControls;
	var MediaUpload = blockEditor.MediaUpload;
	var MediaUploadCheck = blockEditor.MediaUploadCheck;
	var PanelBody = components.PanelBody;
	var SelectControl = components.SelectControl;
	var TextControl = components.TextControl;
	var TextareaControl = components.TextareaControl;
	var Button = components.Button;
	var ServerSideRender = serverSideRender.default || serverSideRender;
	var __ = i18n.__;

	blocks.registerBlockType( "tr724/ads", {
		edit: function ( props ) {
			var attributes = props.attributes;
			var adMode = attributes.adMode || "none";
			var adFields = null;

			if ( "image" === adMode ) {
				adFields = el(
					Fragment,
					null,
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
					} )
				);
			} else if ( "google" === adMode ) {
				adFields = el(
					Fragment,
					null,
					el( TextControl, {
						label: __( "Publisher ID", "tr724-news" ),
						help: __( "AdSense client id, such as ca-pub-1234567890123456.", "tr724-news" ),
						value: attributes.adClient || "",
						onChange: function ( value ) {
							props.setAttributes( { adClient: value } );
						},
					} ),
					el( TextControl, {
						label: __( "Ad slot", "tr724-news" ),
						help: __( "The data-ad-slot number from the AdSense unit.", "tr724-news" ),
						value: attributes.adSlot || "",
						onChange: function ( value ) {
							props.setAttributes( { adSlot: value } );
						},
					} ),
					el( SelectControl, {
						label: __( "Format", "tr724-news" ),
						value: attributes.adFormat || "auto",
						options: [
							{ label: __( "Auto", "tr724-news" ), value: "auto" },
							{ label: __( "Vertical", "tr724-news" ), value: "vertical" },
							{ label: __( "Rectangle", "tr724-news" ), value: "rectangle" },
							{ label: __( "Horizontal", "tr724-news" ), value: "horizontal" },
						],
						onChange: function ( value ) {
							props.setAttributes( { adFormat: value } );
						},
					} )
				);
			} else if ( "html" === adMode ) {
				adFields = el( TextareaControl, {
					label: __( "Ad code", "tr724-news" ),
					help: __( "Paste a Google ad snippet or any other ad markup. Saving script tags requires a user who can post unfiltered HTML.", "tr724-news" ),
					value: attributes.adHtml || "",
					rows: 8,
					onChange: function ( value ) {
						props.setAttributes( { adHtml: value } );
					},
				} );
			}

			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( "Advertisement", "tr724-news" ), initialOpen: true },
						el( SelectControl, {
							label: __( "Ad", "tr724-news" ),
							value: adMode,
							options: [
								{ label: __( "None", "tr724-news" ), value: "none" },
								{ label: __( "Image", "tr724-news" ), value: "image" },
								{ label: __( "Google AdSense", "tr724-news" ), value: "google" },
								{ label: __( "Custom code", "tr724-news" ), value: "html" },
							],
							onChange: function ( value ) {
								props.setAttributes( { adMode: value } );
							},
						} ),
						adFields,
						"none" !== adMode
							? el( TextControl, {
								label: __( "Label", "tr724-news" ),
								help: __( "Small label under the ad. Leave empty to hide it.", "tr724-news" ),
								value: attributes.adLabel || "",
								onChange: function ( value ) {
									props.setAttributes( { adLabel: value } );
								},
							} )
							: null
					)
				),
				el(
					"div",
					useBlockProps(),
					el( ServerSideRender, {
						block: "tr724/ads",
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
