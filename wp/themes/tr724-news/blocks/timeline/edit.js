( function ( blocks, element, blockEditor, components, serverSideRender, i18n ) {
	var el = element.createElement;
	var Fragment = element.Fragment;
	var useBlockProps = blockEditor.useBlockProps;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var ServerSideRender = serverSideRender.default || serverSideRender;
	var __ = i18n.__;

	blocks.registerBlockType( "tr724/timeline", {
		edit: function () {
			var adminUrl = window.tr724TimelineAdmin || "";
			var ui = window.tr724TimelineUi || {};

			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: ui.title || __( "Timeline", "tr724-news" ), initialOpen: true },
						el(
							"p",
							null,
							ui.help || __( "Programs come from Editorial → Timeline. This block cannot override them.", "tr724-news" )
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
									ui.edit || __( "Edit the schedule", "tr724-news" )
								)
							)
							: null
					)
				),
				el(
					"div",
					useBlockProps(),
					el( ServerSideRender, {
						block: "tr724/timeline",
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
