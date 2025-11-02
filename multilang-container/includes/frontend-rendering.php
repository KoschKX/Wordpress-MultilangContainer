<?php
/**
 * Multilang Container - Frontend Rendering
 * 
 * Handles frontend rendering functionality for live pages
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Render callback for multilang container block
 * Outputs the multilingual content on the frontend
 */
function multilang_container_render( $attributes ) {
	$texts = isset( $attributes['texts'] ) ? $attributes['texts'] : array();

	$langs = get_option('multilang_container_languages', array_keys($texts));
	$default_lang = get_multilang_default_language();
	$default_text = isset($texts[$default_lang]) ? $texts[$default_lang] : '';
	
	$output = '<div class="multilang-container">';
	foreach ($langs as $lang) {
		$text = isset($texts[$lang]) ? $texts[$lang] : '';
		
		// If no text for this language, use default language text as fallback
		if (empty($text) && !empty($default_text) && $lang !== $default_lang) {
			$text = $default_text;
		}
		
		// Always create span for every language (even if empty, for CSS targeting)
		if (!empty($text)) {
			$output .= '<span class="translate lang-' . esc_attr($lang) . '">' . wp_kses_post($text) . '</span>';
		} else {

			$output .= '<span class="translate lang-' . esc_attr($lang) . '"></span>';
		}
	}
	$output .= '</div>';
	return $output;
}

/**
 * Filter to replace 'lang-xx' class with 'translate lang-xx' on the main block wrapper
 */
add_filter('render_block', function($block_content, $block) {
    if ($block['blockName'] === 'multilang/container') {
        // Replace lang-xx with translate lang-xx on the first parent div
        $block_content = preg_replace('/(<div[^>]*class=["\'][^"\']*)\s*lang-([a-z]{2})(\s*)/i', '$1translate', $block_content, 1);
    }
    return $block_content;
}, 10, 2);

/**
 * Simple content filter - copy default language content to empty language blocks
 * Run AFTER server-side translation to avoid conflicts
 */
add_filter('the_content', function($content) {
    if (is_admin()) {
        return $content;
    }
    
    $default_lang = get_multilang_default_language();
    
    // Find the default language block and get its content
    if (preg_match('/class="[^"]*lang-' . preg_quote($default_lang) . '[^"]*"[^>]*>(.*?)<\/div>/s', $content, $default_match)) {
        $default_inner = $default_match[1];
        
        // Only process if default content doesn't already have translation spans
        if (strpos($default_inner, 'class="translate') === false) {
            // Now find empty language blocks and fill them with default content
            $content = preg_replace_callback('/(<div[^>]*class="[^"]*lang-([a-z]{2})[^"]*"[^>]*>)(\s*<p><\/p>\s*|)(<\/div>)/s', 
                function($matches) use ($default_inner, $default_lang) {
                    $opening_tag = $matches[1];
                    $lang_code = $matches[2];
                    $closing_tag = $matches[4];
                    
                    if ($lang_code !== $default_lang && !empty(trim(strip_tags($default_inner)))) {
                        // This is an empty non-default language block, fill it with default content
                        return $opening_tag . $default_inner . $closing_tag;
                    }
                    
                    return $matches[0]; // Return unchanged
                }, $content);
        }
    }
    
    return $content;
}, 20, 1); // Run AFTER server-side translation (which runs at priority 10)