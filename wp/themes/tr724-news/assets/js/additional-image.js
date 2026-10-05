( function ( plugins, editPost, element, components, data, blockEditor, i18n ) {
	var el = element.createElement;
	var useSelect = data.useSelect;
	var useDispatch = data.useDispatch;
	var MediaUpload = blockEditor.MediaUpload;
	var MediaUploadCheck = blockEditor.MediaUploadCheck;
	var Button = components.Button;
	var TextControl = components.TextControl;
	var CheckboxControl = components.CheckboxControl;
	var VStack = components.VStack || components.__experimentalVStack;
	var PluginDocumentSettingPanel = editPost.PluginDocumentSettingPanel;
	var __ = i18n.__;

	function previewUrl( media ) {
		if ( ! media ) {
			return "";
		}
		var sizes = media.media_details && media.media_details.sizes;
		if ( sizes && sizes.thumbnail && sizes.thumbnail.source_url ) {
			return sizes.thumbnail.source_url;
		}
		if ( sizes && sizes.medium && sizes.medium.source_url ) {
			return sizes.medium.source_url;
		}
		return media.source_url || "";
	}

	function AdditionalImagePanel() {
		var postType = useSelect( function ( select ) {
			return select( "core/editor" ).getCurrentPostType();
		}, [] );
		var meta = useSelect( function ( select ) {
			return select( "core/editor" ).getEditedPostAttribute( "meta" ) || {};
		}, [] );
		var imageId = parseInt( meta.tr724_additional_image, 10 ) || 0;
		var additionalTitle = meta.tr724_additional_title || "";
		var upperTitle = meta.tr724_upper_title || "";
		var hideTitle = !!meta.tr724_hide_title;
		var media = useSelect(
			function ( select ) {
				return imageId ? select( "core" ).getMedia( imageId ) : null;
			},
			[ imageId ]
		);
		var editPostAction = useDispatch( "core/editor" ).editPost;

		if ( "post" !== postType ) {
			return null;
		}

		function setMeta( patch ) {
			editPostAction( { meta: patch } );
		}

		function setImage( id ) {
			setMeta( { tr724_additional_image: id } );
		}

		var url = previewUrl( media );
		var fields = [];

		if ( url ) {
			fields.push( el( "img", {
				src: url,
				alt: "",
				style: {
					display: "block",
					maxWidth: "100%",
					height: "auto",
				},
			} ) );
		}

		fields.push( el(
			MediaUploadCheck,
			null,
			el( MediaUpload, {
				onSelect: function ( selected ) {
					setImage( selected && selected.id ? selected.id : 0 );
				},
				allowedTypes: [ "image" ],
				value: imageId,
				render: function ( obj ) {
					return el(
						Button,
						{ variant: "secondary", onClick: obj.open },
						imageId
							? __( "Replace image", "tr724-news" )
							: __( "Select image", "tr724-news" )
					);
				},
			} )
		) );

		if ( imageId ) {
			fields.push( el(
				Button,
				{
					variant: "link",
					isDestructive: true,
					onClick: function () {
						setImage( 0 );
					},
				},
				__( "Remove image", "tr724-news" )
			) );
		}

		fields.push( el( TextControl, {
			label: __( "Üst Başlık", "tr724-news" ),
			value: upperTitle,
			onChange: function ( value ) {
				setMeta( { tr724_upper_title: value } );
			},
		} ) );
		fields.push( el( TextControl, {
			label: __( "Ana Başlık", "tr724-news" ),
			help: __( "Boş bırakılırsa yazı başlığı kullanılır.", "tr724-news" ),
			value: additionalTitle,
			onChange: function ( value ) {
				setMeta( { tr724_additional_title: value } );
			},
		} ) );
		fields.push( el( CheckboxControl, {
			label: __( "Başlığı gizle", "tr724-news" ),
			checked: hideTitle,
			onChange: function ( value ) {
				setMeta( { tr724_hide_title: !!value } );
			},
		} ) );

		return el(
			PluginDocumentSettingPanel,
			{
				name: "tr724-additional-image",
				title: __( "Manşet", "tr724-news" ),
			},
			el.apply( null, [ VStack, { spacing: 4 } ].concat( fields ) )
		);
	}

	plugins.registerPlugin( "tr724-additional-image", {
		render: AdditionalImagePanel,
		icon: "format-image",
	} );

	function PostDetailPanel() {
		var postType = useSelect( function ( select ) {
			return select( "core/editor" ).getCurrentPostType();
		}, [] );
		var youtubeUrl = useSelect( function ( select ) {
			var meta = select( "core/editor" ).getEditedPostAttribute( "meta" ) || {};
			return meta.tr724_youtube_url || "";
		}, [] );
		var editPostAction = useDispatch( "core/editor" ).editPost;

		if ( "post" !== postType ) {
			return null;
		}

		return el(
			PluginDocumentSettingPanel,
			{
				name: "tr724-post-detail",
				title: __( "Yazı Detayı", "tr724-news" ),
			},
			el(
				VStack,
				{ spacing: 4 },
				el( TextControl, {
					label: __( "YouTube bağlantısı", "tr724-news" ),
					help: __( "Doluysa yazı sayfasında öne çıkan görselin yerine bu video gösterilir.", "tr724-news" ),
					type: "url",
					value: youtubeUrl,
					onChange: function ( value ) {
						editPostAction( {
							meta: {
								tr724_youtube_url: value,
							},
						} );
					},
				} )
			)
		);
	}

	plugins.registerPlugin( "tr724-post-detail", {
		render: PostDetailPanel,
		icon: "video-alt3",
	} );
} )( wp.plugins, wp.editPost, wp.element, wp.components, wp.data, wp.blockEditor, wp.i18n );
