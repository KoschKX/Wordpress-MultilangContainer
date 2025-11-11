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
    
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->encoding = 'UTF-8';
    $dom->loadHTML('<?xml encoding="UTF-8">' . $content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    
    $xpath = new DOMXPath($dom);
    
    $translation_elements = $xpath->query('//*[contains(@class, "translate") and contains(@class, "lang-")]');
    
    $lang_pattern = '/lang-([a-z]{2})/';
    
    foreach ($translation_elements as $element) {
        $classes = $element->getAttribute('class');
        
        if (!preg_match($lang_pattern, $classes, $matches)) {
            continue;
        }
        
        $element_lang = $matches[1];
        
        $original_content = '';
        foreach ($element->childNodes as $child) {
            $original_content .= $dom->saveHTML($child);
        }
        
        $trimmed = trim($original_content);
        if (!empty($trimmed)) {
            $element->setAttribute('data-translation', multilang_hide_filter_encode_for_data_attr($original_content));
        }
        
        if ($element_lang !== $current_lang) {
            while ($element->firstChild) {
                $element->removeChild($element->firstChild);
            }
        }
    }
    
    return $dom->saveHTML();
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
    
    ob_start(function($html) {
        if (strpos($html, 'multilang-wrapper') !== false || strpos($html, 'translate lang-') !== false) {
            return multilang_hide_non_current_language($html);
        }
        return $html;
    });
}

add_action('init', 'multilang_hide_filter_final_processing', 1);