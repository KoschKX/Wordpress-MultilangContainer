<?php
/*
 * Multilang Container - Frontend Rendering
 * Handles how stuff shows up on the site
 */

// Block direct access
if (!defined('ABSPATH')) {
    exit;
}

// Render the multilang container block
function multilang_container_render( $attributes ) {
	$texts = isset( $attributes['texts'] ) ? $attributes['texts'] : array();
	$langs = get_option('multilang_container_languages', array_keys($texts));
	$default_lang = get_multilang_default_language();
	$default_text = isset($texts[$default_lang]) ? $texts[$default_lang] : '';
	
	$output = '<div class="multilang-container">';
	foreach ($langs as $lang) {
		$text = isset($texts[$lang]) ? $texts[$lang] : '';
		
		if (empty($text) && !empty($default_text) && $lang !== $default_lang) {
			$text = $default_text;
		}
		
		if (!empty($text)) {
			$output .= '<span class="translate lang-' . esc_attr($lang) . '">' . wp_kses_post($text) . '</span>';
		} else {
			$output .= '<span class="translate lang-' . esc_attr($lang) . '"></span>';
		}
	}
	$output .= '</div>';
	return $output;
}

// Swap 'lang-xx' for 'translate lang-xx' in block HTML
add_filter('render_block', function($block_content, $block) {
    if ($block['blockName'] === 'multilang/container') {
        $block_content = preg_replace('/(<div[^>]*class=["\'][^"\']*)\s*lang-([a-z]{2})(\s*)/i', '$1translate', $block_content, 1);
    }
    return $block_content;
}, 10, 2);

// Fill empty language blocks with default content
add_filter('the_content', function($content) {
    if (is_admin()) {
        return $content;
    }
    
        $default_lang = get_multilang_default_language();

        // Only apply fallback for blocks inside .multilang-wrapper
        if (preg_match('/<div[^>]*class="[^"]*multilang-wrapper[^"]*lang-' . preg_quote($default_lang) . '[^"]*"[^>]*>(.*?)<\/div>/s', $content, $default_match)) {
        $default_inner = $default_match[1];
        
        if (strpos($default_inner, 'class="translate') === false) {
            $content = preg_replace_callback('/(<div[^>]*class="[^"]*lang-([a-z]{2})[^"]*"[^>]*>)(\s*<p><\/p>\s*|)(<\/div>)/s', 
                function($matches) use ($default_inner, $default_lang) {
                    $opening_tag = $matches[1];
                    $lang_code = $matches[2];
                    $closing_tag = $matches[4];
                    
                    if ($lang_code !== $default_lang && !empty(trim(strip_tags($default_inner)))) {
                        return $opening_tag . $default_inner . $closing_tag;
                    }
                    
                    return $matches[0];
                }, $content);
        }
    }
    
    return $content;
}, 20, 1);