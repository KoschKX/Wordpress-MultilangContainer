<?php

if (!defined('ABSPATH')) {
    exit;
}

function multilang_hide_filter_encode_for_data_attr($text) {
    if (empty($text)) {
        return '';
    }
    
    $has_lt = strpos($text, '<');
    if ($has_lt !== false && strpos($text, '>', $has_lt) !== false) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
    
    $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    
    if (!preg_match('/[\x{0080}-\x{FFFF}"\']]/u', $decoded)) {
        return htmlspecialchars($decoded, ENT_QUOTES, 'UTF-8');
    }
    
    $json_encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
    return substr($json_encoded, 1, -1);
}

function multilang_hide_non_current_language($content) {
    if (is_admin() || wp_doing_ajax()) {
        return $content;
    }
    
    $current_lang = multilang_get_current_language();
    if (!$current_lang) {
        return $content;
    }
    
    $has_wrapper = strpos($content, 'multilang-wrapper');
    $has_translate = strpos($content, 'translate lang-');
    
    if ($has_wrapper === false && $has_translate === false) {
        return $content;
    }
    
    // Use regex to find and process translation spans
    $content = preg_replace_callback(
        '/(<span\s+class="[^"]*translate[^"]*lang-([a-z]{2})[^"]*"[^>]*>)(.*?)<\/span>/is',
        function($matches) use ($current_lang) {
            $opening_tag = $matches[1];
            $element_lang = $matches[2];
            $span_content = $matches[3];
            
            // Remove inline style attributes
            $opening_tag = preg_replace('/\s+style="[^"]*"/i', '', $opening_tag);
            
            if ($element_lang !== $current_lang) {
                // Remove content for non-current languages, but keep attributes and add data-translation
                $encoded_content = multilang_hide_filter_encode_for_data_attr($span_content);
                $opening_with_data = preg_replace('/>$/', ' data-translation="' . $encoded_content . '">', $opening_tag);
                return $opening_with_data . '</span>';
            } else {
                // Keep content for current language
                return $opening_tag . $span_content . '</span>';
            }
        },
        $content
    );
    return $content;
}

add_filter('the_content', 'multilang_hide_non_current_language', 999);
add_filter('the_excerpt', 'multilang_hide_non_current_language', 999);
add_filter('widget_text', 'multilang_hide_non_current_language', 999);

/**
 * Add body attribute for hide filtering
 */
function multilang_add_hide_filter_body_attribute($classes) {
    $classes[] = 'multilang-hide-filter-active';
    return $classes;
}
add_filter('body_class', 'multilang_add_hide_filter_body_attribute');

/**
 * Add data attribute to body
 */
function multilang_add_hide_filter_body_data() {
    if (is_admin() || wp_doing_ajax()) {
        return;
    }
    
    echo '<script>document.addEventListener("DOMContentLoaded",function(){document.body.setAttribute("data-multilang-hide-filter","enabled")});</script>';
}
add_action('wp_footer', 'multilang_add_hide_filter_body_data', 1);

function multilang_hide_filter_final_processing() {
    if (is_admin() || wp_doing_ajax()) {
        return;
    }
    $cache_handler_included = function_exists('multilang_is_cache_enabled');
    ob_start(function($html) use ($cache_handler_included) {
        if (strpos($html, 'multilang-wrapper') !== false || strpos($html, 'translate lang-') !== false) {
            // Only cache fragments if cache-handler.php is included AND page is not excluded
            if ($cache_handler_included && multilang_is_cache_enabled() && !multilang_is_page_excluded_from_cache()) {
                $cache_key = multilang_get_page_cache_key();
                if ($cache_key) {
                    $structure_data = multilang_get_cached_structure_data();
                    if (!empty($structure_data)) {
                        multilang_cache_fragments_from_html($html, $cache_key, $structure_data);
                    }
                }
            }
            // If cache-handler is not included OR page is excluded, skip fragment caching
            return multilang_hide_non_current_language($html);
        }
        return $html;
    });
}

add_action('init', 'multilang_hide_filter_final_processing', 1);