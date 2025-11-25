(function(wp){
	const { registerBlockType } = wp.blocks;
	const { TextControl, SelectControl, PanelBody, ColorPalette, RangeControl } = wp.components;

	const { InspectorControls, useBlockProps, InnerBlocks } = wp.blockEditor;
	const { useState, useEffect } = wp.element;

	// Use dynamic language list from plugin settings
	const LANGUAGES = (typeof multilangBlockSettings !== 'undefined' && Array.isArray(multilangBlockSettings.languages))
		? multilangBlockSettings.languages.map(function(code){ return { label: code, value: code }; })
		: [{ label: 'en', value: 'en' }]; // Minimal fallback - only English

	registerBlockType('multilang/container', {
		title: 'Multilang Container',
		icon: 'translation',
		category: 'text',
		supports: {
			inserter: true,
			delete: true
		},
		attributes: {
			texts: {
				type: 'object',
				default: {},
			},
			language: {
				type: 'string',
				default: 'en',
			},
		},
		edit: function({ attributes, setAttributes }) {
			const { language } = attributes;
			const [currentLang, setCurrentLang] = useState(language);
			const languageContainers = LANGUAGES.map(lang => [
				'core/group',
				{
					className: 'lang-' + lang.value,
					title: lang.label
				},
				[]
			]);
			const blockProps = useBlockProps({
				className: 'selected-lang-' + currentLang
			});

			// Hide .lang-xx sections for languages not in LANGUAGES
			useEffect(() => {
				// Get allowed language codes
				const allowed = LANGUAGES.map(l => l.value);
				// Find all elements with class lang-xx
				document.querySelectorAll('[class*="lang-"]').forEach(el => {
					const match = el.className.match(/lang-([a-zA-Z0-9_-]+)/);
					if (match) {
						const code = match[1];
						if (!allowed.includes(code)) {
							el.style.display = 'none';
						} else {
							el.style.display = '';
						}
					}
				});
			}, [currentLang, LANGUAGES]);

			return wp.element.createElement(
				wp.element.Fragment,
				null,
				wp.element.createElement(
					InspectorControls,
					null,
					wp.element.createElement(
						PanelBody,
						{ title: 'Settings', initialOpen: true },
						wp.element.createElement(SelectControl, {
							label: 'Language',
							value: currentLang,
							options: LANGUAGES,
							onChange: function(val) {
								setCurrentLang(val);
								setAttributes({ language: val });
							}
						})
					),
				),
				wp.element.createElement(
					'div',
					blockProps,
					wp.element.createElement(
						wp.components.DropdownMenu,
						{
							icon: 'admin-site-alt3',
							label: 'Edit Language',
							toggleProps: {
								children: 'Language: ' + (LANGUAGES.find(function(l){return l.value===currentLang;})?.label || currentLang)
							},
							controls: LANGUAGES.map(function(lang) {
								return {
									title: lang.label,
									onClick: function() {
										setCurrentLang(lang.value);
										setAttributes({ language: lang.value });
									},
									isActive: lang.value === currentLang
								};
							})
						}
					),
					wp.element.createElement(
						InnerBlocks,
						{
							template: languageContainers,
							templateLock: false
						}
					)
				)
			);
		},
		save: function({ attributes }) {
			const lang = attributes.language || 'en';
			return wp.element.createElement(
				'div',
				{ className: 'lang-' + lang },
				wp.element.createElement(
					InnerBlocks.Content,
					null
				)
			);
		}
	});

	// Custom SVG icon for multilang-excerpt
	const multilangExcerptIcon = wp.element.createElement(
		'svg',
		{ width: 24, height: 24, viewBox: '0 0 24 24', fill: 'none', xmlns: 'http://www.w3.org/2000/svg' },
		wp.element.createElement('rect', { x: 3, y: 5, width: 18, height: 14, rx: 2, fill: '#F3B400', stroke: '#333', strokeWidth: 1.5 }),
		wp.element.createElement('text', { x: 12, y: 15, textAnchor: 'middle', fontSize: 8, fill: '#333', fontFamily: 'Arial' }, 'ML')
	);

	registerBlockType('multilang/excerpt', {
		title: 'Multilang Excerpt',
		icon: multilangExcerptIcon,
		category: 'text',
		   attributes: {
			   words: {
				   type: 'number',
				   default: 0
			   },
			   style: {
				   type: 'object'
			   }
		   },
		supports: {
			html: false,
			className: true,
			color: {
				text: true,
				background: true,
				link: true
			},
			typography: {
				fontSize: true
			},
			spacing: {
				padding: true,
				margin: true
			}
		},
		   edit: function({ attributes, setAttributes }) {
			   const { words } = attributes;
			   const blockProps = useBlockProps();
			   return (
				   wp.element.createElement(
					   wp.element.Fragment,
					   null,
					   wp.element.createElement('div', blockProps,
						   wp.element.createElement('div', null,
							   'This block will display the first Multilang Container block in the post as the excerpt.'
						   )
					   ),
					   wp.element.createElement(
						   InspectorControls,
						   null,
						   wp.element.createElement(
							   PanelBody,
							   { title: 'Settings', initialOpen: true },
							   wp.element.createElement(RangeControl, {
								   label: 'Max words',
								   min: 10,
								   max: 100,
								   value: words || 10,
								   onChange: function(val) { setAttributes({ words: val }); }
							   })
						   ),
					   )
				   )
			   );
		   },
		save: function() {
			   return null; // Server-side render only
		   }
		});

})(window.wp);

