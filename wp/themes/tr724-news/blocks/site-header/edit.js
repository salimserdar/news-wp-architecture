( function ( blocks, element, blockEditor, components, i18n, data ) {
	var el = element.createElement;
	var InnerBlocks = blockEditor.InnerBlocks;
	var useBlockProps = blockEditor.useBlockProps;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var TextControl = components.TextControl;
	var __ = i18n.__;

	function navLinks( labels ) {
		return labels.map( function ( label ) {
			return [
				"core/navigation-link",
				{ label: label, url: "#", kind: "custom", type: "custom" },
			];
		} );
	}

	var links = navLinks( [
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
	] );

	var drawerLinks = navLinks( [
		"SON DAKİKA",
		"YAZARLAR",
		"GÜNDEM",
		"EKONOMİ",
		"DÜNYA",
		"GÜNÜN İÇİNDEN",
		"SPOR",
		"HAYAT",
		"MAGAZİN",
		"E-GAZETE",
		"OTOMOTİV",
		"FİNANS",
		"EĞİTİM",
		"RESMİ İLANLAR",
	] );

	var blockLock = { remove: true, move: true };
	var template = [
		[
			"core/site-logo",
			{ width: 180, shouldSyncIcon: false, isLink: true, lock: blockLock },
		],
		[
			"core/navigation",
			{
				metadata: { name: "Üst menü" },
				overlayMenu: "never",
				ariaLabel: "Bölümler",
				lock: blockLock,
			},
			links,
		],
		[
			"core/navigation",
			{
				metadata: { name: "Hamburger menü" },
				className: "is-drawer-nav",
				overlayMenu: "never",
				ariaLabel: "Tüm bölümler",
				lock: blockLock,
			},
			drawerLinks,
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

	function drawerBlock() {
		return blocks.createBlock(
			"core/navigation",
			{
				metadata: { name: "Hamburger menü" },
				className: "is-drawer-nav",
				overlayMenu: "never",
				ariaLabel: "Tüm bölümler",
				lock: blockLock,
			},
			drawerLinks.map( function ( link ) {
				return blocks.createBlock( link[0], link[1] );
			} )
		);
	}

	blocks.registerBlockType( "tr724/site-header", {
		edit: function ( props ) {
			var attributes = props.attributes;
			var clientId = props.clientId;
			var inserted = element.useRef( false );
			var innerBlocks = data.useSelect(
				function ( select ) {
					return select( "core/block-editor" ).getBlocks( clientId );
				},
				[ clientId ]
			);
			var insertBlock = data.useDispatch( "core/block-editor" ).insertBlock;

			element.useEffect(
				function () {
					if ( inserted.current || ! innerBlocks.length ) {
						return;
					}

					var hasDrawer = innerBlocks.some( function ( block ) {
						var className = block.attributes.className || "";
						return (
							"core/navigation" === block.name &&
							className.indexOf( "is-drawer-nav" ) !== -1
						);
					} );

					if ( hasDrawer ) {
						return;
					}

					inserted.current = true;

					var index = innerBlocks.findIndex( function ( block ) {
						return "core/search" === block.name;
					} );

					insertBlock(
						drawerBlock(),
						index < 0 ? innerBlocks.length : index,
						clientId,
						false
					);
				},
				[ innerBlocks, clientId, insertBlock ]
			);

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
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n, window.wp.data );
