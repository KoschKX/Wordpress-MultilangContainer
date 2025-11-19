<?php

if (!defined('ABSPATH')) {
    exit;
}

// Fallback: Load language data from file if cache-handler is not present
function load_language_data($lang) {
    $base_dir = dirname(__FILE__) . '/../languages/';
    $file = $base_dir . $lang . '.json';
    if (!file_exists($file)) {
        // Try old uploads location
        $uploads_dir = dirname(__FILE__) . '/../../../uploads/multilang/';
        $old_file = $uploads_dir . $lang . '.json';
        if (file_exists($old_file)) {
            $file = $old_file;
        }
    }
    if (file_exists($file)) {
        $json = file_get_contents($file);
        $data = json_decode($json, true);
        if (is_array($data)) {
            return $data;
        }
    }
    error_log('[multilang] load_language_data: No file or invalid JSON for ' . $lang . ' at ' . $file);
    return array();
}

function multilang_server_side_translate( $content, $force_lang = null ) {
    
    static $call_count = 0;
    static $translations = null;
    static $lang_cache = array();
    
    $call_count++;
    
    if ( multilang_is_backend_operation() ) {
        error_log('[multilang] Backend operation, skipping translation.');
        return $content;
    }

    if ( empty($content) || strlen(trim($content)) < 10 ) {
        error_log('[multilang] Content too short, skipping translation.');
        return $content;
    }

    if ( strpos($content, '<') === false || strlen($content) < 100 ) {
        error_log('[multilang] Content not HTML or too short, skipping translation.');
        return $content;
    }
    
    static $current_lang_cache = null;
    static $default_lang_cache = null;
    
    if ($current_lang_cache === null) {
        $default_lang_cache = get_multilang_default_language();
        $current_lang_cache = $default_lang_cache;
        
        if ( isset($_COOKIE['lang']) ) {
            $cookie_lang = sanitize_text_field($_COOKIE['lang']);
            $available_langs = get_multilang_available_languages();
            if ( in_array($cookie_lang, $available_langs) ) {
                $current_lang_cache = $cookie_lang;
            }
        }
    }
    
    // Override current language if force_lang is provided
    $current_lang = $force_lang ?: $current_lang_cache;
    
    if ($translations === null) {
        $translations = load_translations();
        if ( empty($translations) ) {
            $translations = false;
        }
    }
    
    if ( $translations === false ) {
        return $content;
    }
    
    $processed_content = multilang_process_text_for_translations($content, $translations, $current_lang, $default_lang_cache);
    
    return $processed_content;
}

function multilang_process_text_for_translations($html, $translations, $current_lang, $default_lang) {
    if (empty($translations) || strlen($html) < 100) {
        error_log('[multilang] No translations or HTML too short.');
        return $html;
    }
    
    static $lang_cache = array();
    
    if (!isset($lang_cache[$current_lang])) {
        $lang_cache[$current_lang] = multilang_get_language_data($current_lang);
    }
    if (!isset($lang_cache[$default_lang])) {
        $lang_cache[$default_lang] = multilang_get_language_data($default_lang);
    }

    $current_lang_translations = $lang_cache[$current_lang];
    $default_lang_translations = $lang_cache[$default_lang];

    if (empty($current_lang_translations) && empty($default_lang_translations)) {
        error_log('[multilang] No translation data for current or default language: ' . $current_lang . ', ' . $default_lang);
        return $html;
    }
    
    $dom = new DOMDocument();
    $dom->encoding = 'UTF-8';
    $dom->preserveWhiteSpace = true;
    $dom->formatOutput = false;
    
    libxml_use_internal_errors(true);
    
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    
    $body = $dom->getElementsByTagName('body')->item(0);
    if (!$body) {
        error_log('[multilang] No <body> found in HTML.');
        return $html;
    }

    $replacements_made = multilang_wrap_text_nodes_selective($body, $current_lang_translations, $default_lang_translations, $current_lang, $default_lang);

    if ($replacements_made === 0) {
        error_log('[multilang] No replacements made in content.');
        return $html;
    }
    
    $result = $dom->saveHTML();
    
    $result = preg_replace('/^<!DOCTYPE.+?>/', '<!DOCTYPE html>', str_replace('<?xml encoding="UTF-8">', '', $result));
    
    return $result;
}

function multilang_get_language_data($lang) {
    // Use cached version if available, otherwise load directly
    if (function_exists('multilang_get_cached_language_data')) {
        $data = multilang_get_cached_language_data($lang);
        // error_log('[multilang] Using cached language data for ' . $lang . ': ' . (is_array($data) ? count($data) : 'not array'));
        return $data;
    }
    // Fallback: load language data directly
    if (function_exists('load_language_data')) {
        $data = load_language_data($lang);
        // error_log('[multilang] Using direct loaded language data for ' . $lang . ': ' . (is_array($data) ? count($data) : 'not array'));
        return $data;
    }
    // Try global translations if available
    global $multilang_translations;
    if (isset($multilang_translations[$lang])) {
        error_log('[multilang] Using global $multilang_translations for ' . $lang . ': ' . (is_array($multilang_translations[$lang]) ? count($multilang_translations[$lang]) : 'not array'));
        return $multilang_translations[$lang];
    }
    error_log('[multilang] No language data found for ' . $lang);
    return array();
}

function multilang_find_translation_in_data($text, $data) {
    if (!is_array($data)) {
        return null;
    }
    
    foreach ($data as $category => $keys) {
        if (is_array($keys) && isset($keys[$text])) {
            $translation = $keys[$text];
            return !empty(trim($translation)) ? $translation : null;
        }
    }
    
    return null;
}

function multilang_translate_text($text, $current_lang_translations, $default_lang_translations, $current_lang, $default_lang) {
    static $call_count = 0;
    static $translations = null;
    static $lang_cache = array();
    static $langs = null;
    
    $call_count++;
    
    if (empty(trim($text))) {
        return $text;
    }
    
    if (strpos($text, 'multilang-wrapper') !== false || strpos($text, 'class="translate') !== false) {
        return $text;
    }
    
    // Preserve leading and trailing whitespace
    preg_match('/^(\s*)(.+?)(\s*)$/s', $text, $matches);
    $leading_space = isset($matches[1]) ? $matches[1] : '';
    $trimmed_text = isset($matches[2]) ? $matches[2] : trim($text);
    $trailing_space = isset($matches[3]) ? $matches[3] : '';
    
    $cache_key = md5($trimmed_text . $current_lang . $default_lang);
    if (isset($cache[$cache_key])) {
        return $leading_space . $cache[$cache_key] . $trailing_space;
    }
    
    $current_translation = multilang_find_translation_in_data($trimmed_text, $current_lang_translations);
    $default_translation = multilang_find_translation_in_data($trimmed_text, $default_lang_translations);
    
    $default_partial_translation = null;
    if (!$default_translation && strlen($trimmed_text) <= 50) {
        $default_partial_translation = multilang_process_partial_translation($trimmed_text, $default_lang_translations);
    }
    
    if (strlen($trimmed_text) > 50 && !$current_translation && !$default_translation && !$default_partial_translation) {
        $cache[$cache_key] = $text;
        return $leading_space . $text . $trailing_space;
    }
    
    $should_process = $current_translation || $default_translation || $default_partial_translation;
    
    $has_section_specific = !empty($current_lang_translations) && is_array($current_lang_translations) && count($current_lang_translations) > 0;
    
    if (!$should_process && !$has_section_specific) {
        $should_process = multilang_text_contains_translatable_words($trimmed_text);
    }
    
    if ($should_process) {
        if ($langs === null) {
            $langs = get_multilang_available_languages();
        }
        
        $full_result = '';
        
        $best_fallback = $default_translation ? $default_translation : 
                        ($default_partial_translation ? $default_partial_translation : $trimmed_text);
        
        foreach ($langs as $lang) {
            // Get the full language file data for this language
            $lang_file_data = multilang_get_language_data($lang);
            $lang_data = array();
            
            // Extract only the sections that are in current_lang_translations
            if (is_array($current_lang_translations)) {
                foreach ($current_lang_translations as $category => $translations) {
                    if ($category !== '_selectors' && $category !== '_collapsed' && $category !== '_method') {
                        if (isset($lang_file_data[$category])) {
                            $lang_data[$category] = $lang_file_data[$category];
                        }
                    }
                }
            }
            
            // If no section-specific data, use the full language file
            if (empty($lang_data)) {
                $lang_data = $lang_file_data;
            }
            
            $lang_translation = multilang_find_translation_in_data($trimmed_text, $lang_data);
            
            if (!$lang_translation && strlen($trimmed_text) <= 50) {
                $lang_translation = multilang_process_partial_translation($trimmed_text, $lang_data);
            }
            
            if (!$lang_translation) {
                $full_result .= '<span class="translate lang-' . esc_attr($lang) . '">' . esc_html($best_fallback) . '</span>';
            } else {
                $full_result .= '<span class="translate lang-' . esc_attr($lang) . '">' . esc_html($lang_translation) . '</span>';
            }
        }
        
        $result = !empty($full_result) ? $full_result : $text;
        $cache[$cache_key] = $result;
        return $leading_space . $result . $trailing_space;
    }
    
    $cache[$cache_key] = $text;
    return $text;
}

function multilang_wrap_text_nodes_selective($body, $current_lang_translations, $default_lang_translations, $current_lang, $default_lang) {
    
    static $structure_data = null;
    static $sections = null;
    static $all_selectors = null;
    
    $replacements_made = 0;
    
    if ($structure_data === null) {
        // Use cached structure data if available, otherwise load directly
        if (function_exists('multilang_get_cached_structure_data')) {
            $structure_data = multilang_get_cached_structure_data();
            // error_log('[multilang] Using cached structure data: ' . (is_array($structure_data) ? count($structure_data) : 'not array'));
        } else if (function_exists('load_structure_data')) {
            $structure_data = load_structure_data();
            // error_log('[multilang] Using direct loaded structure data: ' . (is_array($structure_data) ? count($structure_data) : 'not array'));
        } else {
            // error_log('[multilang] No structure data found');
            $structure_data = false;
        }
    }

    // Always add multilang-cached class to elements matching selectors
    if ($structure_data && is_array($structure_data)) {
        if ($all_selectors === null) {
            $all_selectors = array();
            foreach ($structure_data as $section => $config) {
                if (is_array($config) && isset($config['_selectors'])) {
                    $selectors = $config['_selectors'];
                    if (is_array($selectors)) {
                        foreach ($selectors as $selector) {
                            $xp = multilang_css_to_xpath($selector);
                            if ($xp) {
                                $all_selectors[] = $xp;
                            }
                        }
                    }
                }
            }
        }
        
        $xpath = new DOMXPath($body->ownerDocument);
        foreach ($all_selectors as $xp) {
            $matching_elements = $xpath->query($xp, $body);
            foreach ($matching_elements as $element) {
                if (multilang_should_skip_element($element)) {
                    continue;
                }
                
                // Add multilang-cached class to the element (only if not already present)
                $existing_class = $element->getAttribute('class');
                if (strpos($existing_class, 'multilang-cached') === false) {
                    $new_class = trim($existing_class . ' multilang-cached');
                    $element->setAttribute('class', $new_class);
                }
            }
        }
    }

    if (!$structure_data || !is_array($structure_data)) {
        return multilang_wrap_text_nodes($body, $current_lang_translations, $default_lang_translations, $current_lang, $default_lang);
    }

    if ($sections === null) {
        $sections = array();
        
        foreach ($structure_data as $section => $config) {
            if (!is_array($config) || !isset($config['_selectors'])) continue;
            
            $section_method = isset($config['_method']) ? $config['_method'] : 'server';
            if ($section_method !== 'server') {
                continue;
            }
            
            $selectors = $config['_selectors'];
            if (!is_array($selectors)) continue;
            
            $section_xpath_selectors = array();
            foreach ($selectors as $css_selector) {
                $xp = multilang_css_to_xpath($css_selector);
                if ($xp) $section_xpath_selectors[] = $xp;
            }
            if (!empty($section_xpath_selectors)) {
                $sections[$section] = $section_xpath_selectors;
            }
        }
    }

    if (empty($sections)) {
        return 0;
    }

    $xpath = new DOMXPath($body->ownerDocument);

    uksort($sections, function($a, $b) use ($structure_data) {
        $a_selectors = isset($structure_data[$a]['_selectors']) ? $structure_data[$a]['_selectors'] : array();
        $b_selectors = isset($structure_data[$b]['_selectors']) ? $structure_data[$b]['_selectors'] : array();
        
        $a_has_body = is_array($a_selectors) && in_array('body', $a_selectors);
        $b_has_body = is_array($b_selectors) && in_array('body', $b_selectors);
        
        if ($a_has_body && !$b_has_body) return 1;
        if (!$a_has_body && $b_has_body) return -1;
        
        return 0;
    });

    foreach ($sections as $section => $section_xpaths) {
        $section_current_lang = isset($current_lang_translations[$section]) ? array($section => $current_lang_translations[$section]) : array();
        $section_default_lang = isset($default_lang_translations[$section]) ? array($section => $default_lang_translations[$section]) : array();
        
        $other_section_classes = array();
        foreach ($sections as $other_section => $other_xpaths) {
            if ($other_section !== $section) {
                if (isset($structure_data[$other_section]['_selectors'])) {
                    foreach ($structure_data[$other_section]['_selectors'] as $sel) {
                        if (strpos($sel, '.') === 0) {
                            $other_section_classes[] = substr($sel, 1);
                        }
                    }
                }
            }
        }
        
        $javascript_section_classes = array();
        
        $current_section_selectors = isset($structure_data[$section]['_selectors']) ? $structure_data[$section]['_selectors'] : array();
        $current_section_classes = array();
        foreach ($current_section_selectors as $sel) {
            if (strpos($sel, '.') === 0) {
                $current_section_classes[] = substr($sel, 1);
            }
        }
        
        foreach ($structure_data as $other_section => $config) {
            if ($other_section !== $section && isset($config['_method']) && $config['_method'] === 'javascript') {
                if (isset($config['_selectors'])) {
                    foreach ($config['_selectors'] as $sel) {
                        if (strpos($sel, '.') === 0) {
                            $class_name = substr($sel, 1);
                            if (!in_array($class_name, $current_section_classes)) {
                                $javascript_section_classes[] = $class_name;
                            }
                        }
                    }
                }
            }
        }
        
        foreach ($section_xpaths as $sel) {
            $matching_elements = $xpath->query($sel, $body);
            foreach ($matching_elements as $element) {
                if (multilang_should_skip_element($element)) {
                    continue;
                }
                
                // Add multilang-cached class to the element (only if not already present)
                $existing_class = $element->getAttribute('class');
                if (strpos($existing_class, 'multilang-cached') === false) {
                    $new_class = trim($existing_class . ' multilang-cached');
                    $element->setAttribute('class', $new_class);
                }
                
                $replacements_made += multilang_wrap_text_nodes($element, $section_current_lang, $section_default_lang, $current_lang, $default_lang, $javascript_section_classes, $section);
            }
        }
    }

    return $replacements_made;
}

function multilang_element_has_class_in_tree($element, $class_name) {
    
    $current = $element;
    $depth = 0;
    $max_depth = 10;
    
    while ($current && $depth < $max_depth) {
        if ($current->nodeType === XML_ELEMENT_NODE && $current->hasAttribute('class')) {
            $classes = $current->getAttribute('class');
            if (strpos($classes, $class_name) !== false) {
                return true;
            }
        }
        $current = $current->parentNode;
        $depth++;
    }
    
    return false;
}

function multilang_should_skip_element($element) {
    static $cache = array();
    
    $cache_key = spl_object_hash($element);
    if (isset($cache[$cache_key])) {
        return $cache[$cache_key];
    }
    
    $should_skip = false;
    $current_element = $element;
    
    for ($i = 0; $i < 5; $i++) {
        if ($current_element) {
            $tag_name = strtolower($current_element->nodeName);
            $classes = $current_element->hasAttribute('class') ? $current_element->getAttribute('class') : '';
            
            if ($tag_name === 'blockquote' || 
                strpos($classes, 'quote') !== false || 
                strpos($classes, 'testimonial') !== false || 
                strpos($classes, 'wp-block-quote') !== false) {
                
                if (strpos($classes, 'calendar') === false && 
                    strpos($classes, 'wp-calendar') === false && 
                    $tag_name !== 'table') {
                    $should_skip = true;
                    break;
                }
            }
        }
        $current_element = $current_element->parentNode;
        if (!$current_element || $current_element->nodeType !== XML_ELEMENT_NODE) break;
    }
    
    $cache[$cache_key] = $should_skip;
    return $should_skip;
}

function multilang_wrap_text_nodes($element, $current_lang_translations, $default_lang_translations, $current_lang, $default_lang, $javascript_section_classes = array(), $current_section = '') {
    $skip_tags = array('SCRIPT', 'STYLE', 'CODE', 'PRE', 'BLOCKQUOTE');
    $replacements_made = 0;
    
    if (in_array(strtoupper($element->nodeName), $skip_tags)) {
        return 0;
    }
    
    $element_has_class = $element->hasAttribute('class');
    $element_classes = $element_has_class ? $element->getAttribute('class') : '';
    
    if (!empty($javascript_section_classes) && $element_has_class) {
        foreach ($javascript_section_classes as $js_class) {
            if (strpos($element_classes, $js_class) !== false) {
                return 0;
            }
        }
    }
    
    if ($element_has_class && strpos($element_classes, 'multilang-wrapper') !== false) {
        return 0;
    }
    
    if (strtolower($element->nodeName) === 'blockquote') {
        return 0;
    }
    
    $parent = $element->parentNode;
    while ($parent && $parent->nodeType === XML_ELEMENT_NODE) {
        if (strtolower($parent->nodeName) === 'blockquote') {
            return 0;
        }
        $parent = $parent->parentNode;
    }
    
    if ($element_has_class) {
        if (strpos($element_classes, 'token') !== false || 
            strpos(strtolower($element_classes), 'code') !== false ||
            strpos($element_classes, 'no-translate') !== false) {
            return 0;
        }
        
        if (strpos($element_classes, 'translate') !== false) {
            return 0;
        }
    }
    
    $child_nodes = array();
    foreach ($element->childNodes as $node) {
        $child_nodes[] = $node;
    }
    
    foreach ($child_nodes as $node) {
        if ($node->nodeType === XML_TEXT_NODE) {
            $original_text = $node->nodeValue;
            if (empty(trim($original_text))) {
                continue;
            }
            
            $parent_element = $node->parentNode;
            while ($parent_element && $parent_element->nodeType === XML_ELEMENT_NODE) {
                if ($parent_element->hasAttribute('class') && 
                    strpos($parent_element->getAttribute('class'), 'multilang-wrapper') !== false) {
                    continue 2;
                }
                $parent_element = $parent_element->parentNode;
            }
            
            $translated = multilang_translate_text($original_text, $current_lang_translations, $default_lang_translations, $current_lang, $default_lang);
            
            // Only wrap if translation contains language spans
            if ($translated !== $original_text && strpos($translated, 'class="translate lang-') !== false) {
                $wrapper_html = '<span class="multilang-wrapper" data-original-text="' . esc_attr(trim($original_text)) . '">' . $translated . '</span>';
                
                $temp_doc = new DOMDocument();
                $temp_doc->encoding = 'UTF-8';
                libxml_use_internal_errors(true);
                $temp_doc->loadHTML('<?xml encoding="UTF-8"><div>' . $wrapper_html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                libxml_clear_errors();
                
                $temp_wrapper = $temp_doc->getElementsByTagName('span')->item(0);
                if ($temp_wrapper) {
                    $imported_wrapper = $element->ownerDocument->importNode($temp_wrapper, true);
                    
                    $element->replaceChild($imported_wrapper, $node);
                    $replacements_made++;
                }
            }
        } elseif ($node->nodeType === XML_ELEMENT_NODE) {
            $replacements_made += multilang_wrap_text_nodes($node, $current_lang_translations, $default_lang_translations, $current_lang, $default_lang, $javascript_section_classes, $current_section);
            
            foreach (array('title', 'data-title', 'alt') as $attr) {
                if ($node->hasAttribute($attr)) {
                    $attr_value = $node->getAttribute($attr);
                    $translated_attr = multilang_translate_text($attr_value, $current_lang_translations, $default_lang_translations, $current_lang, $default_lang);
                    if ($translated_attr !== $attr_value) {
                        $node->setAttribute($attr, $translated_attr);
                    }
                }
            }
        }
    }
    
    return $replacements_made;
}

// Page translation - buffers output and processes entire HTML
static $multilang_translation_cache = null;

function multilang_start_page_buffer() {

    // Skip if page is excluded from translation
    if (function_exists('multilang_is_page_excluded_from_cache') && multilang_is_page_excluded_from_cache()) {
        return;
    }
    if (function_exists('multilang_is_cache_enabled') && !multilang_is_cache_enabled()) {
        return;
    }
    
    if ( multilang_is_backend_operation() ) {
        return;
    }

    // Don't buffer AJAX - check multiple ways
    if ( defined('DOING_AJAX') && DOING_AJAX ) {
        return;
    }
    if ( function_exists('wp_doing_ajax') && wp_doing_ajax() ) {
        return;
    }
    if ( !empty($_REQUEST['action']) || !empty($_POST['action']) || !empty($_GET['action']) ) {
        return;
    }
    if ( !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest' ) {
        return;
    }
    $structure_data = function_exists('multilang_get_cached_structure_data') ? multilang_get_cached_structure_data() : false;
    $has_server_sections = false;
    if ($structure_data && is_array($structure_data)) {
        foreach ($structure_data as $section => $config) {
            $section_method = isset($config['_method']) ? $config['_method'] : 'server';
            if ($section_method === 'server') {
                $has_server_sections = true;
                break;
            }
        }
    }
    if (!$has_server_sections) {
        $translation_method = get_option('multilang_container_translation_method', 'server');
        $has_server_sections = ($translation_method === 'server');
    }
    if (!$has_server_sections) {
        return;
    }
    ob_start('multilang_process_entire_page');
}
add_action('template_redirect', 'multilang_start_page_buffer', 0);

function multilang_process_entire_page($html) {

    if ( multilang_is_backend_operation() ) {
        return $html;
    }

    if (function_exists('multilang_is_page_excluded_from_cache') && multilang_is_page_excluded_from_cache()) {
        return $html;
    }
    if (function_exists('multilang_is_cache_enabled') && !multilang_is_cache_enabled()) {
        return $html;
    }
    
    if ( defined('DOING_AJAX') && DOING_AJAX ) {
        return $html;
    }
    
    if ( wp_doing_ajax() ) {
        return $html;
    }
    
    if (strlen($html) < 100) {
        return $html;
    }
    
    if ( strpos($html, '/wp-admin/') !== false && strpos($html, 'wp-admin-bar') === false ) {
        //return $html;
    }
    
    // Get current language and post ID for cache key
    $current_lang = multilang_get_current_language();
    global $post;
    $post_id = null;
    // error_log('[POST ID DEBUG] $post->ID=' . (isset($post) ? var_export($post->ID, true) : 'unset'));
    if (isset($post) && !empty($post->ID)) {
        $post_id = $post->ID;
    }
    if (!$post_id && function_exists('get_the_ID')) {
        $the_id = get_the_ID();
        // error_log('[POST ID DEBUG] get_the_ID()=' . var_export($the_id, true));
        if ($the_id) {
            $post_id = $the_id;
        }
    }
    if (!$post_id && function_exists('get_queried_object_id')) {
        $query_id = get_queried_object_id();
        // error_log('[POST ID DEBUG] get_queried_object_id()=' . var_export($query_id, true));
        if ($query_id) {
            $post_id = $query_id;
        }
    }
    // Fallback: use request URI as cache key for non-post pages
    $cache_page_key = $post_id ? $post_id : md5($_SERVER['REQUEST_URI']);

    // Get structure data and selectors
    $structure_data = function_exists('multilang_get_cached_structure_data') ? multilang_get_cached_structure_data() : false;
    if (!$structure_data || !is_array($structure_data)) {
        // Fallback: process whole page
        return multilang_server_side_translate($html);
    }

    // Check if fragment caching functions are available
    if (!function_exists('multilang_retrieve_cached_fragments') || 
        !function_exists('multilang_inject_fragments_into_html') || 
        !function_exists('multilang_cache_fragments_from_html')) {
        // Fallback: process whole page without fragment caching
        return multilang_server_side_translate($html);
    }

    // For each section/selector, try to get cached fragment
    // error_log('[FRAGMENT RETRIEVAL] post_id=' . var_export($post_id, true) . ', current_lang=' . var_export($current_lang, true));
    list($fragments, $all_found) = multilang_retrieve_cached_fragments($cache_page_key, $structure_data);

    // If all fragments are found, inject them and bypass heavy work
    // error_log('Fragment injection block check: all_found=' . ($all_found ? 'true' : 'false'));
    // error_log('Fragment injection block check: fragments=' . print_r($fragments, true));
    if (!empty($fragments)) {
        $result_html = multilang_inject_fragments_into_html($html, $fragments);
        return $result_html;
    } else {
       // error_log('Fragment injection block skipped: no fragments found');
    }

    // Otherwise, process the HTML and cache fragments
    $processed_html = multilang_server_side_translate($html);
    $processed_html = str_replace('</head>', '<!-- Server-side translation processed and cached --></head>', $processed_html);

    multilang_cache_fragments_from_html($processed_html, $cache_page_key, $structure_data);

    return $processed_html;
}

function multilang_text_has_any_translation($text) {
    $available_langs = get_multilang_available_languages();
    $trimmed_text = trim($text);
    
    foreach ($available_langs as $lang) {
        $lang_data = multilang_get_language_data($lang);
        if (multilang_find_translation_in_data($trimmed_text, $lang_data)) {
            return true;
        }
    }
    return false;
}

function multilang_text_contains_translatable_words($text) {
    static $all_translatable_words = null;
    
    if ($all_translatable_words === null) {
        $all_translatable_words = array();
        $available_langs = get_multilang_available_languages();
        
        foreach ($available_langs as $lang) {
            $lang_data = multilang_get_language_data($lang);
            foreach ($lang_data as $category => $keys) {
                if (is_array($keys)) {
                    foreach ($keys as $key => $translation) {
                        if ($key !== '_selectors' && $key !== '_collapsed' && !empty($key)) {
                            $all_translatable_words[strtolower($key)] = true;
                        }
                    }
                }
            }
        }
    }
    
    preg_match_all('/\b\w+\b/u', strtolower($text), $matches);
    $words_in_text = $matches[0];
    
    foreach ($words_in_text as $word) {
        if (isset($all_translatable_words[$word])) {
            return true;
        }
    }
    
    return false;
}

/**
 * Translate partial text
 */
function multilang_translate_partial_text($text, $lang_data) {
    $result = $text;
    $has_translation = false;
    
    preg_match_all('/\b\w+\b/u', $text, $matches, PREG_OFFSET_CAPTURE);
    $words = $matches[0];
    
    $words = array_reverse($words);
    
    foreach ($words as $word_data) {
        $word = $word_data[0];
        $offset = $word_data[1];
        
        if (strlen($word) === 1) {
            $before_char = $offset > 0 ? $text[$offset - 1] : ' ';
            $after_char = $offset + 1 < strlen($text) ? $text[$offset + 1] : ' ';
            
            if ($before_char === "'" || $after_char === "'" || 
                ctype_alpha($before_char) || ctype_alpha($after_char)) {
                continue;
            }
        }
        
        $word_translation = multilang_find_translation_in_data($word, $lang_data);
        if ($word_translation && $word_translation !== $word) {
            $pattern = '/\b' . preg_quote($word, '/') . '\b/u';
            $replacement_made = false;
            
            if (preg_match($pattern, substr($text, $offset, strlen($word)))) {
                $result = substr_replace($result, $word_translation, $offset, strlen($word));
                $has_translation = true;
            }
        }
    }
    
    return $has_translation ? $result : null;
}

function multilang_get_all_container_selectors() {
    static $all_selectors = null;
    if ($all_selectors === null) {
        $all_selectors = array();
        // Use cached structure data only if function exists
        if (function_exists('multilang_get_cached_structure_data')) {
            $structure_data = multilang_get_cached_structure_data();
            if ($structure_data && is_array($structure_data)) {
                foreach ($structure_data as $category => $config) {
                    if (is_array($config) && isset($config['_selectors'])) {
                        $selectors = $config['_selectors'];
                        if (is_array($selectors)) {
                            $all_selectors = array_merge($all_selectors, $selectors);
                        }
                    }
                }
                $all_selectors = array_unique($all_selectors);
            }
        }
    }
    return $all_selectors;
}

function multilang_css_to_xpath($selector) {
    if (preg_match('/^\.([a-zA-Z0-9_-]+)$/', $selector, $matches)) {
        return "//*[contains(concat(' ', normalize-space(@class), ' '), ' " . $matches[1] . " ')]";
    }
    
    if (preg_match('/^#([a-zA-Z0-9_-]+)$/', $selector, $matches)) {
        return "//*[@id='" . $matches[1] . "']";
    }
    
    if (preg_match('/^[a-zA-Z0-9]+$/', $selector)) {
        return "//" . $selector;
    }
    
    if (preg_match('/^(\.[a-zA-Z0-9_-]+)+$/', $selector)) {
        $classes = explode('.', ltrim($selector, '.'));
        $xpath = "//*";
        foreach ($classes as $class) {
            if (!empty($class)) {
                $xpath .= "[contains(concat(' ', normalize-space(@class), ' '), ' " . $class . " ')]";
            }
        }
        return $xpath;
    }
    
    return null;
}

/**
 * Process partial translation for text (for individual words like month names)
 */
function multilang_process_partial_translation($text, $lang_data) {
    static $partial_cache = array();
    
    $cache_key = md5($text . serialize($lang_data));
    if (isset($partial_cache[$cache_key])) {
        return $partial_cache[$cache_key];
    }
    
    if (!is_array($lang_data)) {
        $partial_cache[$cache_key] = null;
        return null;
    }
    
    $translated_text = $text;
    $found_translation = false;
    
    // Search through all categories for partial matches
    foreach ($lang_data as $category => $keys) {
        if (is_array($keys)) {
            foreach ($keys as $source => $translation) {
                // Skip empty translations or non-string keys
                if (empty(trim($translation)) || !is_string($source)) {
                    continue;
                }
                
                // Use Unicode word boundaries for better matching with special characters
                // This will match "Sep" in "« Sep" or "Sep »"
                $pattern = '/(?<=^|[\s\p{P}\p{Z}])' . preg_quote($source, '/') . '(?=[\s\p{P}\p{Z}]|$)/ui';
                if (preg_match($pattern, $text)) {
                    $translated_text = preg_replace($pattern, $translation, $translated_text);
                    $found_translation = true;
                }
            }
        }
    }
    
    $result = $found_translation ? $translated_text : null;
    $partial_cache[$cache_key] = $result;
    return $result;
}

