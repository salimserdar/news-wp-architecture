( function ( blocks, element, blockEditor, components, i18n ) {
	var el = element.createElement;
	var InnerBlocks = blockEditor.InnerBlocks;
	var useBlockProps = blockEditor.useBlockProps;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var TextControl = components.TextControl;
	var __ = i18n.__;

	var blockLock = { remove: true, move: true };

	function links( labels ) {
		return labels.map( function ( label ) {
			return [
				"core/navigation-link",
				{ label: label, url: "#", kind: "custom", type: "custom" },
			];
		} );
	}

	function column( ariaLabel, labels ) {
		return [
			"core/navigation",
			{
				overlayMenu: "never",
				ariaLabel: ariaLabel,
				lock: blockLock,
			},
			links( labels ),
		];
	}

	var template = [
		column( "Haber", [
			"Türkiye",
			"Dünya",
			"Güncel",
			"Ekonomi",
			"Spor",
			"Özel Haber",
			"Yaşam",
			"Medya",
			"Amerika Günlüğü",
		] ),
		column( "Yazarlar", [
			"Günün Yazarları",
			"Yorum",
			"Tüm Yazarlar",
			"Okur Görüşü",
		] ),
		column( "YouTube", [
			"Son Videolar",
			"Programlar",
			"Röportajlar",
			"Haber",
			"Portreler",
			"Yol Hikâyeleri",
			"Sesli Köşeler",
		] ),
		[
			"core/navigation",
			{
				overlayMenu: "never",
				ariaLabel: "Biz Kimiz",
				lock: blockLock,
			},
			[],
		],
	];

	function field( props, label, key ) {
		return el( TextControl, {
			label: label,
			value: props.attributes[ key ] || "",
			onChange: function ( value ) {
				var next = {};
				next[ key ] = value;
				props.setAttributes( next );
			},
		} );
	}

	blocks.registerBlockType( "tr724/site-footer", {
		edit: function ( props ) {
			return el(
				"div",
				useBlockProps(),
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( "WhatsApp", "tr724-news" ), initialOpen: true },
						field( props, __( "Label", "tr724-news" ), "whatsappLabel" ),
						field( props, __( "Phone", "tr724-news" ), "whatsappPhone" ),
						field( props, __( "URL", "tr724-news" ), "whatsappUrl" )
					),
					el(
						PanelBody,
						{ title: __( "Social", "tr724-news" ), initialOpen: false },
						field( props, __( "Facebook URL", "tr724-news" ), "facebookUrl" ),
						field( props, __( "X URL", "tr724-news" ), "xUrl" ),
						field( props, __( "Instagram URL", "tr724-news" ), "instagramUrl" ),
						field( props, __( "YouTube URL", "tr724-news" ), "youtubeUrl" )
					),
					el(
						PanelBody,
						{ title: __( "App stores", "tr724-news" ), initialOpen: false },
						field( props, __( "App Store URL", "tr724-news" ), "appStoreUrl" ),
						field( props, __( "Google Play URL", "tr724-news" ), "playStoreUrl" )
					),
					el(
						PanelBody,
						{ title: __( "Columns", "tr724-news" ), initialOpen: false },
						field( props, __( "Column 1 heading", "tr724-news" ), "col1Heading" ),
						field( props, __( "Column 2 heading", "tr724-news" ), "col2Heading" ),
						field( props, __( "Column 3 heading", "tr724-news" ), "col3Heading" ),
						field( props, __( "Column 4 heading", "tr724-news" ), "col4Heading" )
					),
					el(
						PanelBody,
						{ title: __( "Bottom bar", "tr724-news" ), initialOpen: false },
						field( props, __( "Copyright", "tr724-news" ), "copyright" ),
						field( props, __( "Privacy label", "tr724-news" ), "privacyLabel" ),
						field( props, __( "Privacy URL", "tr724-news" ), "privacyUrl" ),
						field( props, __( "Terms label", "tr724-news" ), "termsLabel" ),
						field( props, __( "Terms URL", "tr724-news" ), "termsUrl" )
					)
				),
				el( InnerBlocks, {
					template: template,
					allowedBlocks: [ "core/navigation" ],
				} )
			);
		},
		save: function () {
			return el( InnerBlocks.Content );
		},
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n );
