( function ( blocks, element, blockEditor, components, serverSideRender, apiFetch, i18n ) {
	var el = element.createElement;
	var Fragment = element.Fragment;
	var useState = element.useState;
	var useEffect = element.useEffect;
	var useBlockProps = blockEditor.useBlockProps;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var SelectControl = components.SelectControl;
	var Button = components.Button;
	var Flex = components.Flex;
	var FlexBlock = components.FlexBlock;
	var ServerSideRender = serverSideRender.default || serverSideRender;
	var __ = i18n.__;

	var maxRates = 12;
	var groups = {
		freeMarket: __( "Free market", "tr724-news" ),
		centralBank: __( "Central bank", "tr724-news" ),
		parities: __( "Parities", "tr724-news" ),
		gold: __( "Gold", "tr724-news" ),
		bist: __( "BIST", "tr724-news" ),
	};

	function moveSymbol( symbols, index, delta ) {
		var next = index + delta;
		if ( next < 0 || next >= symbols.length ) {
			return symbols;
		}
		var copy = symbols.slice();
		var symbol = copy[ index ];
		copy.splice( index, 1 );
		copy.splice( next, 0, symbol );
		return copy;
	}

	function optionLabel( item ) {
		var group = groups[ item.group ] || item.group;
		if ( item.name && item.label && item.label !== item.name ) {
			return item.label + " — " + item.name + " (" + group + ")";
		}
		return ( item.label || item.symbol ) + " (" + group + ")";
	}

	blocks.registerBlockType( "tr724/ticker", {
		edit: function ( props ) {
			var attributes = props.attributes;
			var symbols = Array.isArray( attributes.symbols ) ? attributes.symbols : [];
			var catalogState = useState( null );
			var errorState = useState( "" );
			var catalog = catalogState[ 0 ];
			var setCatalog = catalogState[ 1 ];
			var error = errorState[ 0 ];
			var setError = errorState[ 1 ];

			useEffect( function () {
				var active = true;
				apiFetch( { path: "/tr724/v1/exchange-rates" } )
					.then( function ( data ) {
						if ( ! active ) {
							return;
						}
						setCatalog( data && Array.isArray( data.instruments ) ? data.instruments : [] );
					} )
					.catch( function () {
						if ( ! active ) {
							return;
						}
						setError( __( "Exchange rates are unavailable.", "tr724-news" ) );
						setCatalog( [] );
					} );
				return function () {
					active = false;
				};
			}, [] );

			var bySymbol = {};
			( catalog || [] ).forEach( function ( item ) {
				bySymbol[ item.symbol ] = item;
			} );

			var addOptions = [
				{
					label: __( "Add a rate…", "tr724-news" ),
					value: "",
				},
			];
			( catalog || [] ).forEach( function ( item ) {
				if ( symbols.indexOf( item.symbol ) !== -1 ) {
					return;
				}
				addOptions.push( {
					label: optionLabel( item ),
					value: item.symbol,
				} );
			} );

			var rows = symbols.map( function ( symbol, index ) {
				var item = bySymbol[ symbol ];
				var label = item ? item.label + " — " + item.name : symbol;
				return el(
					Flex,
					{
						key: symbol,
						align: "center",
						justify: "space-between",
						gap: 2,
						style: { marginBottom: "8px" },
					},
					el( FlexBlock, null, label ),
					el(
						Button,
						{
							size: "small",
							variant: "secondary",
							disabled: 0 === index,
							label: __( "Move up", "tr724-news" ),
							onClick: function () {
								props.setAttributes( {
									symbols: moveSymbol( symbols, index, -1 ),
								} );
							},
						},
						__( "Up", "tr724-news" )
					),
					el(
						Button,
						{
							size: "small",
							variant: "secondary",
							disabled: index === symbols.length - 1,
							label: __( "Move down", "tr724-news" ),
							onClick: function () {
								props.setAttributes( {
									symbols: moveSymbol( symbols, index, 1 ),
								} );
							},
						},
						__( "Down", "tr724-news" )
					),
					el(
						Button,
						{
							size: "small",
							variant: "tertiary",
							isDestructive: true,
							label: __( "Remove", "tr724-news" ),
							onClick: function () {
								props.setAttributes( {
									symbols: symbols.filter( function ( current ) {
										return current !== symbol;
									} ),
								} );
							},
						},
						__( "Remove", "tr724-news" )
					)
				);
			} );

			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( "Rates", "tr724-news" ), initialOpen: true },
						error
							? el(
								"p",
								null,
								error
							)
							: null,
						null === catalog
							? el( "p", null, __( "Loading rates…", "tr724-news" ) )
							: null,
						rows,
						symbols.length >= maxRates
							? el(
								"p",
								null,
								__( "This ticker shows up to 12 rates.", "tr724-news" )
							)
							: el( SelectControl, {
								label: __( "Add a rate", "tr724-news" ),
								help: __( "Any value returned by the exchange rates service can be shown.", "tr724-news" ),
								value: "",
								options: addOptions,
								disabled: null === catalog || addOptions.length < 2,
								onChange: function ( symbol ) {
									if ( ! symbol || symbols.indexOf( symbol ) !== -1 || symbols.length >= maxRates ) {
										return;
									}
									props.setAttributes( {
										symbols: symbols.concat( [ symbol ] ),
									} );
								},
							} )
					)
				),
				el(
					"div",
					useBlockProps(),
					el( ServerSideRender, {
						block: "tr724/ticker",
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
	window.wp.apiFetch,
	window.wp.i18n
);
