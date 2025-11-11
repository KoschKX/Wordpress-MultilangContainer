
(function(wp){
	const { addFilter } = wp.hooks;
	const { createElement, Fragment } = wp.element;
	const { InspectorControls } = wp.blockEditor;
	const { PanelBody, ToggleControl } = wp.components;

	function addPreserveHtmlToggle(BlockEdit) {
		return function(props) {
			if (props.name !== 'multilang/excerpt') {
				return createElement(BlockEdit, props);
			}
			const { attributes, setAttributes } = props;
			return createElement(
				Fragment,
				null,
				createElement(BlockEdit, props),
				createElement(
					InspectorControls,
					null,
					createElement(
						PanelBody,
						{ title: 'Settings', initialOpen: true },
						createElement(ToggleControl, {
							label: 'Preserve HTML in Excerpt',
							checked: !!attributes.preserveHtml,
							onChange: function(val) { setAttributes({ preserveHtml: val }); }
						})
					)
				)
			);
		};
	}

	addFilter(
		'editor.BlockEdit',
		'multilang/excerpt-preserve-html-toggle',
		addPreserveHtmlToggle
	);
})(window.wp);
