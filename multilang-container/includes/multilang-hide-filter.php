<?php
/**
 * Multilang Hide Filter - Removes content from non-current language spans
 * This file should ONLY be included when you want to hide non-current languages
 * 
 * Simple logic: Remove content from spans that are NOT the current language
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Local encoding function for data attributes (optimized version)
 * Handles special characters, quotes, HTML entities, and multi-byte Unicode
 */
function multilang_hide_filter_encode_for_data_attr($text) {
    if (empty($text)) {
        return '';
    }
    
    // For HTML content, preserve the structure and just escape for HTML attributes
    // Check both conditions in one pass for efficiency
    $has_lt = strpos($text, '<');
    if ($has_lt !== false && strpos($text, '>', $has_lt) !== false) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
    
    // Clean the text first - decode any existing entities and normalize
    $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    
    // Single regex check for both conditions (faster than two separate checks)
    if (!preg_match('/[\x{0080}-\x{FFFF}"\']]/u', $decoded)) {
        return htmlspecialchars($decoded, ENT_QUOTES, 'UTF-8');
    }
    
    // For complex content, use JSON encoding but preserve Unicode
    $json_encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
    // Remove surrounding quotes from JSON
    return substr($json_encoded, 1, -1);
}

/**
 * Hide non-current language spans by removing their content and storing in data-translation
 * Optimized version with reduced DOM operations and early returns
 */
function multilang_hide_non_current_language($content) {
    // Skip processing for admin pages
    if (is_admin() || wp_doing_ajax()) {
        return $content;
    }
    
    // Get current language using the same method as server translation
    $current_lang = multilang_get_current_language();
    if (!$current_lang) {
        return $content;
    }
    
    // Check both patterns in one pass for efficiency
    $has_wrapper = strpos($content, 'multilang-wrapper');
    $has_translate = strpos($content, 'translate lang-');
    
    if ($has_wrapper === false && $has_translate === false) {
        return $content;
    }
    
    // Create DOM document once
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->encoding = 'UTF-8';
    $dom->loadHTML('<?xml encoding="UTF-8">' . $content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    
    $xpath = new DOMXPath($dom);
    
    // Process all translation elements in a single query (more efficient)
    // This gets both wrapped and standalone elements
    $translation_elements = $xpath->query('//*[contains(@class, "translate") and contains(@class, "lang-")]');
    
    // Pre-compile regex pattern (done once instead of per element)
    $lang_pattern = '/lang-([a-z]{2})/';
    
    foreach ($translation_elements as $element) {
        // Get the language from the class
        $classes = $element->getAttribute('class');
        
        if (!preg_match($lang_pattern, $classes, $matches)) {
            continue;
        }
        
        $element_lang = $matches[1];
        
        // Get all content including HTML (optimized: build string once)
        $original_content = '';
        foreach ($element->childNodes as $child) {
            $original_content .= $dom->saveHTML($child);
        }
        
        // Always store the content in data-translation attribute
        $trimmed = trim($original_content);
        if (!empty($trimmed)) {
            $element->setAttribute('data-translation', multilang_hide_filter_encode_for_data_attr($original_content));
        }
        
        // If this element is NOT the current language, remove its content
        if ($element_lang !== $current_lang) {
            // Faster: use textContent = '' for elements without complex children
            while ($element->firstChild) {
                $element->removeChild($element->firstChild);
            }
        }
    }
    
    // Return the modified content
    return $dom->saveHTML();
}

// Hook the filter to modify page content - run at high priority
add_filter('the_content', 'multilang_hide_non_current_language', 999);
add_filter('the_excerpt', 'multilang_hide_non_current_language', 999);
add_filter('widget_text', 'multilang_hide_non_current_language', 999);

/**
 * Add body attribute to indicate that hide filtering is active
 */
function multilang_add_hide_filter_body_attribute($classes) {
    // Add a class to indicate hide filtering is active
    $classes[] = 'multilang-hide-filter-active';
    return $classes;
}
add_filter('body_class', 'multilang_add_hide_filter_body_attribute');

/**
 * Add data attribute to body to indicate hide filtering is enabled
 * Optimized: inline script only when needed
 */
function multilang_add_hide_filter_body_data() {
    // Single condition check
    if (is_admin() || wp_doing_ajax()) {
        return;
    }
    
    // Minified inline script
    echo '<script>document.addEventListener("DOMContentLoaded",function(){document.body.setAttribute("data-multilang-hide-filter","enabled")});</script>';
}
add_action('wp_footer', 'multilang_add_hide_filter_body_data', 1);

// Optimized final output buffering
function multilang_hide_filter_final_processing() {
    // Early return for admin/ajax
    if (is_admin() || wp_doing_ajax()) {
        return;
    }
    
    // Start output buffering with optimized callback
    ob_start(function($html) {
        // Quick check before processing (strpos is faster than regex)
        if (strpos($html, 'multilang-wrapper') !== false || strpos($html, 'translate lang-') !== false) {
            return multilang_hide_non_current_language($html);
        }
        return $html;
    });
}

// Hook very early to catch all output
add_action('init', 'multilang_hide_filter_final_processing', 1);