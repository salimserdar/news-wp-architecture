( function ( blocks, element, blockEditor, components, serverSideRender, i18n ) {
	var el = element.createElement;
	var Fragment = element.Fragment;
	var useBlockProps = blockEditor.useBlockProps;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var ServerSideRender = serverSideRender.default || serverSideRender;
	var __ = i18n.__;

	blocks.registerBlockType( "tr724/featured-topics", {
		edit: function () {
			var adminUrl = window.tr724FeaturedTopicsAdmin || "";
			var ui = window.tr724FeaturedTopicsUi || {};

			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: ui.title || __( "Featured Topics", "tr724-news" ), initialOpen: true },
						el(
							"p",
							null,
							ui.help || __( "Topics come from Editorial → Featured Topics. This block cannot override them.", "tr724-news" )
						),
						adminUrl
							? el(
								"p",
								null,
								el(
									"a",
									{
										href: adminUrl,
										target: "_blank",
										rel: "noopener noreferrer",
									},
									ui.edit || __( "Edit featured topics", "tr724-news" )
								)
							)
							: null
					)
				),
				el(
					"div",
					useBlockProps(),
					el( ServerSideRender, {
						block: "tr724/featured-topics",
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
