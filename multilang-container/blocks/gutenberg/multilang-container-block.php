<?php
/**
 * Multilang Container Block - Gutenberg Block
 * 
 * Handles the main multilang container block registration and rendering
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register the multilang container block
 */
function multilang_container_register_block() {
	// Get languages from settings using the dynamic function
	$langs = get_multilang_available_languages();
	
	register_block_type( dirname(__FILE__, 2) . '/gutenberg/multilang-container-block.json', array(
		'render_callback' => 'multilang_container_render_callback',
	));
	
	wp_register_script(
		'multilang-container-editor',
		plugins_url('/gutenberg/multilang-container-block.js', dirname(__FILE__)),
		array('wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-i18n'),
		filemtime(plugin_dir_path(dirname(__FILE__)) . '/gutenberg/multilang-container-block.js')
	);
	wp_localize_script('multilang-container-editor', 'multilangBlockSettings', array(
		'languages' => $langs
	));
}
add_action( 'init', 'multilang_container_register_block' );

/**
 * Simple render callback that only adds fallback functionality without breaking layout
 */
function multilang_container_render_callback($attributes, $content) {
	// Get available languages and default language
	$available_languages = get_multilang_available_languages();
	$default_language = get_multilang_default_language();
	
	if (!is_array($available_languages) || empty($available_languages)) {
		return $content;
	}
	
	// Parse existing language content from InnerBlocks
	$dom = new DOMDocument();
	libxml_use_internal_errors(true);
	$dom->loadHTML('<?xml encoding="UTF-8">' . $content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
	libxml_clear_errors();
	
	$xpath = new DOMXPath($dom);
	$lang_divs = $xpath->query('//div[contains(@class, "lang-")]');
	
	$language_blocks = array();
	$default_content = '';
	
	// Extract existing language content
	foreach ($lang_divs as $div) {
		$classes = $div->getAttribute('class');
		if (preg_match('/lang-([a-z]{2})/', $classes, $matches)) {
			$lang = $matches[1];
			$inner_html = '';
			foreach ($div->childNodes as $child) {
				$inner_html .= $dom->saveHTML($child);
			}
			
			$language_blocks[$lang] = trim($inner_html);
			
			// Store default language content for fallback
			if ($lang === $default_language && !empty(trim(strip_tags($inner_html)))) {
				$default_content = trim($inner_html);
			}
		}
	}
	
	// If no default content, use first non-empty language
	if (empty($default_content)) {
		foreach ($language_blocks as $lang => $html_content) {
			$text = trim(strip_tags($html_content));
			if (!empty($text)) {
				$default_content = $html_content;
				break;
			}
		}
	}
	
	// Build output with all languages, each lang-xx also gets .translate
	$output = '<div class="multilang-container">';
	foreach ($available_languages as $lang) {
		$classes = 'wp-block-group lang-' . esc_attr($lang) . ' translate has-global-padding is-layout-constrained wp-block-group-is-layout-constrained';
		if (isset($language_blocks[$lang])) {
			$text_content = trim(strip_tags($language_blocks[$lang]));
			if (empty($text_content) && !empty($default_content)) {
				$output .= '<div class="' . $classes . '">' . $default_content . '</div>';
			} else {
				$output .= '<div class="' . $classes . '">' . $language_blocks[$lang] . '</div>';
			}
		} else if (!empty($default_content)) {
			$output .= '<div class="' . $classes . '">' . $default_content . '</div>';
		}
	}
	$output .= '</div>';
	return $output;
}
