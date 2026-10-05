( function ( blocks, element, blockEditor, components, data, serverSideRender, i18n ) {
	var el = element.createElement;
	var Fragment = element.Fragment;
	var useState = element.useState;
	var useEffect = element.useEffect;
	var useRef = element.useRef;
	var useBlockProps = blockEditor.useBlockProps;
	var BlockControls = blockEditor.BlockControls;
	var MediaUpload = blockEditor.MediaUpload;
	var MediaUploadCheck = blockEditor.MediaUploadCheck;
	var Modal = components.Modal;
	var TextControl = components.TextControl;
	var Button = components.Button;
	var ToolbarGroup = components.ToolbarGroup;
	var ToolbarButton = components.ToolbarButton;
	var useSelect = data.useSelect;
	var ServerSideRender = serverSideRender.default || serverSideRender;
	var __ = i18n.__;

	function normalizeCountries( value ) {
		var rows = [];
		( value || [] ).forEach( function ( row ) {
			var imageId;
			if ( ! row || typeof row !== "object" ) {
				return;
			}
			imageId = parseInt( row.imageId, 10 );
			rows.push( {
				code: String( row.code || "" ).toUpperCase().replace( /[^A-Z]/g, "" ).slice( 0, 2 ),
				imageId: imageId > 0 ? imageId : 0,
				url: typeof row.url === "string" ? row.url : "",
			} );
		} );
		return rows;
	}

	function CountryAdsEdit( props ) {
		var attributes = props.attributes;
		var countries = normalizeCountries( attributes.countries );
		var openState = useState( false );
		var isOpen = openState[0];
		var setIsOpen = openState[1];
		var openedOnce = useRef( false );
		var imageIds = [];
		var idsKey;

		if ( attributes.fallbackImageId ) {
			imageIds.push( attributes.fallbackImageId );
		}
		countries.forEach( function ( row ) {
			if ( row.imageId && imageIds.indexOf( row.imageId ) === -1 ) {
				imageIds.push( row.imageId );
			}
		} );
		idsKey = imageIds.join( "," );

		var mediaMap = useSelect( function ( select ) {
			var map = {};
			imageIds.forEach( function ( id ) {
				var media = select( "core" ).getMedia( id );
				if ( media && media.source_url ) {
					map[ id ] = media.source_url;
				}
			} );
			return map;
		}, [ idsKey ] );

		useEffect( function () {
			if ( openedOnce.current || ! props.isSelected || attributes.fallbackImageId ) {
				return undefined;
			}
			openedOnce.current = true;
			setIsOpen( true );
			return undefined;
		}, [ props.isSelected, attributes.fallbackImageId ] );

		function setCountries( next ) {
			props.setAttributes( { countries: next } );
		}

		function updateCountry( index, patch ) {
			var next = normalizeCountries( attributes.countries );
			if ( ! next[ index ] ) {
				return;
			}
			next[ index ] = Object.assign( {}, next[ index ], patch );
			setCountries( next );
		}

		function setCode( index, value ) {
			var next = normalizeCountries( attributes.countries );
			var code = String( value || "" ).toUpperCase().replace( /[^A-Z]/g, "" ).slice( 0, 2 );
			if ( ! next[ index ] ) {
				return;
			}
			next[ index ] = Object.assign( {}, next[ index ], { code: code } );
			if ( code.length === 2 ) {
				next = next.filter( function ( row, rowIndex ) {
					return rowIndex === index || row.code !== code;
				} );
			}
			setCountries( next );
		}

		function imageFields( imageId, onSelect, onRemove ) {
			var url = imageId && mediaMap[ imageId ] ? mediaMap[ imageId ] : "";
			return el(
				"div",
				{ className: "country-ads-editor__image" },
				url
					? el( "img", { className: "country-ads-editor__thumb", src: url, alt: "" } )
					: el( "div", { className: "country-ads-editor__placeholder" }, __( "No image", "tr724-news" ) ),
				el( MediaUploadCheck, null, el( MediaUpload, {
					onSelect: function ( media ) {
						onSelect( media && media.id ? media.id : 0 );
					},
					allowedTypes: [ "image" ],
					value: imageId || 0,
					render: function ( obj ) {
						return el(
							Button,
							{ variant: "secondary", onClick: obj.open },
							imageId
								? __( "Replace image", "tr724-news" )
								: __( "Select image", "tr724-news" )
						);
					},
				} ) ),
				imageId
					? el(
						Button,
						{
							variant: "link",
							isDestructive: true,
							onClick: onRemove,
						},
						__( "Remove image", "tr724-news" )
					)
					: null
			);
		}

		return el(
			Fragment,
			null,
			el(
				BlockControls,
				null,
				el(
					ToolbarGroup,
					null,
					el( ToolbarButton, {
						icon: "edit",
						text: __( "Edit ads", "tr724-news" ),
						label: __( "Edit ads", "tr724-news" ),
						onClick: function () {
							setIsOpen( true );
						},
					} )
				)
			),
			isOpen
				? el(
					Modal,
					{
						title: __( "Country ads", "tr724-news" ),
						className: "country-ads-editor__modal",
						onRequestClose: function () {
							setIsOpen( false );
						},
					},
					el( "div", { className: "country-ads-editor" },
						el(
							"p",
							{ className: "country-ads-editor__hint" },
							__( "Add a fallback image or GIF. It shows when a country has no ad. Country codes are two letters, such as CA for Canada.", "tr724-news" )
						),
						el(
							"div",
							{ className: "country-ads-editor__section" },
							el( "p", { className: "country-ads-editor__label" }, __( "Fallback", "tr724-news" ) ),
							attributes.fallbackImageId
								? null
								: el(
									"p",
									{ className: "country-ads-editor__hint" },
									__( "A fallback image is required.", "tr724-news" )
								),
							imageFields(
								attributes.fallbackImageId || 0,
								function ( imageId ) {
									props.setAttributes( { fallbackImageId: imageId } );
								},
								function () {
									props.setAttributes( { fallbackImageId: 0 } );
								}
							),
							el( TextControl, {
								label: __( "Target link", "tr724-news" ),
								help: __( "Leave empty to show the image without a link.", "tr724-news" ),
								type: "url",
								value: attributes.fallbackUrl || "",
								onChange: function ( value ) {
									props.setAttributes( { fallbackUrl: value } );
								},
							} )
						),
						el(
							"div",
							{ className: "country-ads-editor__section" },
							el( "p", { className: "country-ads-editor__label" }, __( "Country ads", "tr724-news" ) ),
							countries.map( function ( row, index ) {
								return el(
									"div",
									{ className: "country-ads-editor__row", key: "country-" + index },
									el( "input", {
										className: "country-ads-editor__code",
										type: "text",
										value: row.code,
										maxLength: 2,
										placeholder: "CA",
										"aria-label": __( "Country code", "tr724-news" ),
										autoCapitalize: "characters",
										spellCheck: "false",
										onChange: function ( event ) {
											setCode( index, event.target.value );
										},
									} ),
									el(
										"div",
										null,
										imageFields(
											row.imageId,
											function ( imageId ) {
												updateCountry( index, { imageId: imageId } );
											},
											function () {
												updateCountry( index, { imageId: 0 } );
											}
										),
										el( TextControl, {
											label: __( "Target link", "tr724-news" ),
											type: "url",
											value: row.url,
											onChange: function ( value ) {
												updateCountry( index, { url: value } );
											},
										} ),
										el(
											Button,
											{
												className: "country-ads-editor__remove",
												variant: "link",
												isDestructive: true,
												onClick: function () {
													setCountries( countries.filter( function ( item, rowIndex ) {
														return rowIndex !== index;
													} ) );
												},
											},
											__( "Remove", "tr724-news" )
										)
									)
								);
							} ),
							el(
								Button,
								{
									variant: "secondary",
									onClick: function () {
										setCountries( countries.concat( [ { code: "", imageId: 0, url: "" } ] ) );
									},
								},
								__( "Add country", "tr724-news" )
							)
						),
						el(
							"div",
							{ className: "country-ads-editor__footer" },
							el(
								Button,
								{
									variant: "primary",
									onClick: function () {
										setIsOpen( false );
									},
								},
								__( "Done", "tr724-news" )
							)
						)
					)
				)
				: null,
			el(
				"div",
				useBlockProps(),
				el( ServerSideRender, {
					block: "tr724/country-ads",
					attributes: attributes,
				} )
			)
		);
	}

	blocks.registerBlockType( "tr724/country-ads", {
		edit: CountryAdsEdit,
		save: function () {
			return null;
		},
	} );
} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.data,
	window.wp.serverSideRender,
	window.wp.i18n
);
