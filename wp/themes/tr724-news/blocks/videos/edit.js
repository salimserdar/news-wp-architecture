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

	blocks.registerBlockType( "tr724/videos", {
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
						{ title: __( "Videos", "tr724-news" ), initialOpen: true },
						el( RangeControl, {
							label: __( "Videos to show", "tr724-news" ),
							value: attributes.videosToShow || 10,
							min: 1,
							max: 20,
							step: 1,
							withInputField: true,
							onChange: function ( value ) {
								props.setAttributes( {
									videosToShow: clamp( value, 1, 20, 10 ),
								} );
							},
						} ),
						el( TextControl, {
							label: __( "All videos label", "tr724-news" ),
							value: attributes.allLabel || "",
							onChange: function ( value ) {
								props.setAttributes( { allLabel: value } );
							},
						} ),
						el( TextControl, {
							label: __( "All videos link", "tr724-news" ),
							help: __( "Optional. Defaults to the YouTube channel videos page.", "tr724-news" ),
							value: attributes.allUrl || "",
							onChange: function ( value ) {
								props.setAttributes( { allUrl: value } );
							},
						} )
					)
				),
				el(
					"div",
					useBlockProps(),
					el( ServerSideRender, {
						block: "tr724/videos",
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
