( function ( blocks, element, blockEditor, components, i18n ) {
	var el = element.createElement;
	var InnerBlocks = blockEditor.InnerBlocks;
	var useBlockProps = blockEditor.useBlockProps;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var TextControl = components.TextControl;
	var __ = i18n.__;

	var links = [
		"SON DAKİKA",
		"YAZARLAR",
		"GÜNDEM",
		"EKONOMİ",
		"DÜNYA",
		"GÜNÜN İÇİNDEN",
		"SPOR",
		"HAYAT",
		"MAGAZİN",
		"FİNANS",
		"EĞİTİM",
		"RESMİ İLANLAR",
	].map( function ( label ) {
		return [
			"core/navigation-link",
			{ label: label, url: "#", kind: "custom", type: "custom" },
		];
	} );

	var blockLock = { remove: true, move: true };
	var template = [
		[
			"core/site-logo",
			{ width: 180, shouldSyncIcon: false, isLink: true, lock: blockLock },
		],
		[
			"core/navigation",
			{ overlayMenu: "never", ariaLabel: "Bölümler", lock: blockLock },
			links,
		],
		[
			"core/search",
			{
				label: "Haber ara",
				showLabel: false,
				placeholder: "Haber ara",
				buttonText: "Ara",
				lock: blockLock,
			},
		],
	];

	blocks.registerBlockType( "tr724/site-header", {
		edit: function ( props ) {
			var attributes = props.attributes;
			return el(
				"div",
				useBlockProps(),
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( "Header links", "tr724-news" ), initialOpen: true },
						el( TextControl, {
							label: __( "TR724 Tv label", "tr724-news" ),
							value: attributes.tvLabel,
							onChange: function ( value ) {
								props.setAttributes( { tvLabel: value } );
							},
						} ),
						el( TextControl, {
							label: __( "TR724 Tv URL", "tr724-news" ),
							value: attributes.tvUrl,
							onChange: function ( value ) {
								props.setAttributes( { tvUrl: value } );
							},
						} ),
						el( TextControl, {
							label: __( "Son Dakika label", "tr724-news" ),
							value: attributes.flashLabel,
							onChange: function ( value ) {
								props.setAttributes( { flashLabel: value } );
							},
						} ),
						el( TextControl, {
							label: __( "Son Dakika URL", "tr724-news" ),
							value: attributes.flashUrl,
							onChange: function ( value ) {
								props.setAttributes( { flashUrl: value } );
							},
						} )
					)
				),
				el( InnerBlocks, {
					template: template,
					allowedBlocks: [ "core/site-logo", "core/navigation", "core/search" ],
				} )
			);
		},
		save: function () {
			return el( InnerBlocks.Content );
		},
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n );
