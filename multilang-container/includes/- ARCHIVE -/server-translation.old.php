<?php
/**
 * Multilang Container - Server-side Translation
 * 
 * Handles server-side translation processing, HTML parsing, and DOM manipulation
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Server-side translation processing - optimized for performance
 */
function multilang_server_side_translate( $content ) {
    static $call_count = 0;
    static $translations_cache = null;
    static $lang_data_cache = array();
    
    $call_count++;
    
    // Debug: Log function entry (basic logging only)
    if (!is_admin()) {
        // Removed verbose logging for performance
    }
    
    // Don't process backend operations
    if ( multilang_is_backend_operation() ) {
        if (!is_admin()) {
            // error_log('Multilang: Skipped - backend operation detected');
        }
        return $content;
    }
    
    // Skip if content is too short or empty
    if ( empty($content) || strlen(trim($content)) < 10 ) {
        if (!is_admin()) {
            // error_log('Multilang: Skipped - content too short or empty');
        }
        return $content;
    }
    
    // Early return if no translatable content detected
    if ( strpos($content, '<') === false || strlen($content) < 100 ) {
        if (!is_admin()) {
            // error_log('Multilang: Skipped - no HTML or content too short');
        }
        return $content;
    }
    
    // Cache language detection
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
        
        // Debug: Log language detection
        if (!is_admin()) {
            // error_log('Multilang: Languages - Current: ' . $current_lang_cache . ', Default: ' . $default_lang_cache);
        }
    }
    
    // Cache translation data loading
    if ($translations_cache === null) {
        $translations_cache = load_translations();
        if ( empty($translations_cache) ) {
            $translations_cache = false; // Cache negative result
        }
    }
    
    if ( $translations_cache === false ) {
        if (!is_admin()) {
            // error_log('Multilang: Skipped - no translation data available');
        }
        return $content;
    }
    

    $processed_content = multilang_process_text_for_translations($content, $translations_cache, $current_lang_cache, $default_lang_cache);
    
    // Debug: Log processing result
    if (!is_admin()) {
        $changed = ($processed_content !== $content);
        // error_log('Multilang: Processing complete - Changes made: ' . ($changed ? 'YES' : 'NO'));
    }
    
    return $processed_content;
}

/**
 * Optimized HTML processing with early returns and DOM optimization
 */
function multilang_process_text_for_translations($html, $translations, $current_lang, $default_lang) {
    // Quick size and content checks
    if (empty($translations) || strlen($html) < 100) {
        return $html;
    }
    
    // Skip if already processed
    if (strpos($html, 'multilang-wrapper') !== false) {
        return $html;
    }
    
    // Cache language data loading
    static $lang_data_cache = array();
    
    if (!isset($lang_data_cache[$current_lang])) {
        $lang_data_cache[$current_lang] = multilang_get_language_data($current_lang);
    }
    if (!isset($lang_data_cache[$default_lang])) {
        $lang_data_cache[$default_lang] = multilang_get_language_data($default_lang);
    }
    
    $current_lang_translations = $lang_data_cache[$current_lang];
    $default_lang_translations = $lang_data_cache[$default_lang];
    
    // Skip if no translation data
    if (empty($current_lang_translations) && empty($default_lang_translations)) {
        return $html;
    }
    
    // Optimize DOM parsing
    $dom = new DOMDocument();
    $dom->encoding = 'UTF-8';
    $dom->preserveWhiteSpace = true;
    $dom->formatOutput = false;
    
    // Suppress errors during parsing
    libxml_use_internal_errors(true);
    
    // Use faster loading method
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    
    // Find the body element to process
    $body = $dom->getElementsByTagName('body')->item(0);
    if (!$body) {
        return $html;
    }
    

    $replacements_made = multilang_wrap_text_nodes_selective($body, $current_lang_translations, $default_lang_translations, $current_lang, $default_lang);
    
    // Only generate output if changes were made
    if ($replacements_made === 0) {
        return $html;
    }
    
    // Generate output
    $result = $dom->saveHTML();
    
    // Clean up the XML declaration
    $result = preg_replace('/^<!DOCTYPE.+?>/', '<!DOCTYPE html>', str_replace('<?xml encoding="UTF-8">', '', $result));
    
    return $result;
}

/**
 * Get language data structure (matches JavaScript langData format) - with caching
 */
function multilang_get_language_data($lang) {
    static $language_data_cache = array();
    

    if (isset($language_data_cache[$lang])) {
        return $language_data_cache[$lang];
    }
    
    $lang_file = get_language_file_path($lang);
    if (file_exists($lang_file)) {
        $lang_content = file_get_contents($lang_file);
        $data = json_decode($lang_content, true) ?: array();
        
        // Cache the data
        $language_data_cache[$lang] = $data;
        
        return $data;
    }
    
    // Cache empty result too
    $language_data_cache[$lang] = array();
    return array();
}

/**
 * PHP version of JavaScript findTranslationInData function
 */
function multilang_find_translation_in_data($text, $lang_data) {
    if (!is_array($lang_data)) {
        return null;
    }
    
    // Search through all categories
    foreach ($lang_data as $category => $keys) {
        if (is_array($keys) && isset($keys[$text])) {
            $translation = $keys[$text];
            // Treat empty strings as no translation found
            return !empty(trim($translation)) ? $translation : null;
        }
    }
    
    return null;
}

/**
 * Optimized text translation with caching and early returns
 */
function multilang_translate_text($text, $current_lang_translations, $default_lang_translations, $current_lang, $default_lang) {
    static $translation_cache = array();
    static $available_langs_cache = null;
    
    if (empty(trim($text))) {
        return $text;
    }
    
    // Don't process text that already contains multilang-wrapper spans
    if (strpos($text, 'multilang-wrapper') !== false || strpos($text, 'class="translate') !== false) {
        return $text;
    }
    
    $trimmed_text = trim($text);
    

    $cache_key = md5($trimmed_text . $current_lang . $default_lang);
    if (isset($translation_cache[$cache_key])) {
        return $translation_cache[$cache_key];
    }
    
    // Look for exact translation first
    $current_translation = multilang_find_translation_in_data($trimmed_text, $current_lang_translations);
    $default_translation = multilang_find_translation_in_data($trimmed_text, $default_lang_translations);
    
    // Also check for partial translations in default language for fallback
    $default_partial_translation = null;
    if (!$default_translation && strlen($trimmed_text) <= 50) {
        $default_partial_translation = multilang_process_partial_translation($trimmed_text, $default_lang_translations);
    }
    
    // Early return for long text without explicit translations
    if (strlen($trimmed_text) > 50 && !$current_translation && !$default_translation && !$default_partial_translation) {
        $translation_cache[$cache_key] = $text;
        return $text;
    }
    

    $should_process = $current_translation || $default_translation || $default_partial_translation;
    
    if (!$should_process) {
        // Quick check for translatable words
        $should_process = multilang_text_contains_translatable_words($trimmed_text);
    }
    
    if ($should_process) {
        // Cache available languages
        if ($available_langs_cache === null) {
            $available_langs_cache = get_multilang_available_languages();
        }
        
        $full_result = '';
        
        // Determine the best fallback text (prefer default partial translation over original)
        $best_fallback = $default_translation ? $default_translation : 
                        ($default_partial_translation ? $default_partial_translation : $trimmed_text);
        
        foreach ($available_langs_cache as $lang) {
            // Use cached language data
            static $lang_data_global_cache = array();
            if (!isset($lang_data_global_cache[$lang])) {
                $lang_data_global_cache[$lang] = multilang_get_language_data($lang);
            }
            
            $lang_data = $lang_data_global_cache[$lang];
            $lang_translation = multilang_find_translation_in_data($trimmed_text, $lang_data);
            
            // If no exact match, try partial translation for short texts
            if (!$lang_translation && strlen($trimmed_text) <= 50) {
                $lang_translation = multilang_process_partial_translation($trimmed_text, $lang_data);
            }
            
            // If still no translation, use the pre-computed best fallback
            if (!$lang_translation) {
                $full_result .= '<span class="translate lang-' . esc_attr($lang) . '">' . esc_html($best_fallback) . '</span>';
            } else {
                $full_result .= '<span class="translate lang-' . esc_attr($lang) . '">' . esc_html($lang_translation) . '</span>';
            }
        }
        
        $result = !empty($full_result) ? $full_result : $text;
        $translation_cache[$cache_key] = $result;
        return $result;
    }
    
    // Cache negative result
    $translation_cache[$cache_key] = $text;
    return $text;
}

/**
 * Optimized selective text node wrapping with caching
 */
function multilang_wrap_text_nodes_selective($body, $current_lang_translations, $default_lang_translations, $current_lang, $default_lang) {
    static $structure_data_cache = null;
    static $sections_cache = null;
    
    $replacements_made = 0;
    
    // Cache structure data loading
    if ($structure_data_cache === null) {
       $structure_file =  get_structure_file_path();
        if (file_exists($structure_file)) {
            $structure_content = file_get_contents($structure_file);
            $structure_data_cache = json_decode($structure_content, true);
        } else {
            $structure_data_cache = false;
        }
    }

    // If we don't have structure data, fall back to processing the whole body
    if (!$structure_data_cache || !is_array($structure_data_cache)) {
        return multilang_wrap_text_nodes($body, $current_lang_translations, $default_lang_translations, $current_lang, $default_lang);
    }

    // Cache sections processing
    if ($sections_cache === null) {
        $sections_cache = array();
        

        foreach ($structure_data_cache as $section => $config) {
            if (!is_array($config) || !isset($config['_selectors'])) continue;
            
            // Only process sections that are set to 'server' method
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
                $sections_cache[$section] = $section_xpath_selectors;
            }
        }
    }

    // If no valid sections found, return without processing
    if (empty($sections_cache)) {
        return 0;
    }

    $xpath = new DOMXPath($body->ownerDocument);


    foreach ($sections_cache as $section => $section_xpaths) {
        foreach ($section_xpaths as $sel) {
            $matching_elements = $xpath->query($sel, $body);
            foreach ($matching_elements as $element) {
                // Quick skip check for testimonials/quotes
                if (multilang_should_skip_element($element)) {
                    continue;
                }
                $replacements_made += multilang_wrap_text_nodes($element, $current_lang_translations, $default_lang_translations, $current_lang, $default_lang);
            }
        }
    }

    return $replacements_made;
}

/**
 * Fast element skip check
 */
function multilang_should_skip_element($element) {
    static $skip_cache = array();
    

    $cache_key = spl_object_hash($element);
    if (isset($skip_cache[$cache_key])) {
        return $skip_cache[$cache_key];
    }
    
    $should_skip = false;
    $current_element = $element;
    
    // Check up to 5 parent levels (reduced from 10)
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
    
    $skip_cache[$cache_key] = $should_skip;
    return $should_skip;
}

/**
 * PHP version of JavaScript wrapTextNodes function  
 */
function multilang_wrap_text_nodes($element, $current_lang_translations, $default_lang_translations, $current_lang, $default_lang) {
    $skip_tags = array('SCRIPT', 'STYLE', 'CODE', 'PRE', 'BLOCKQUOTE');
    $replacements_made = 0;
    
    if (in_array(strtoupper($element->nodeName), $skip_tags)) {
        return 0;
    }
    
    // Skip if this element already has multilang-wrapper class (avoid nesting)
    if ($element->hasAttribute('class') && strpos($element->getAttribute('class'), 'multilang-wrapper') !== false) {
        return 0;
    }
    
    // Skip if this element contains any child elements with multilang-wrapper class (avoid nesting)
    $xpath = new DOMXPath($element->ownerDocument);
    $existing_wrappers = $xpath->query('.//span[contains(@class, "multilang-wrapper")]', $element);
    if ($existing_wrappers->length > 0) {
        return 0;
    }
    
    // Skip blockquote elements and their children completely
    if (strtolower($element->nodeName) === 'blockquote') {
        return 0;
    }
    

    $parent = $element->parentNode;
    while ($parent && $parent->nodeType === XML_ELEMENT_NODE) {
        if (strtolower($parent->nodeName) === 'blockquote') {
            return 0; // Skip if inside a blockquote
        }
        $parent = $parent->parentNode;
    }
    
    // Check for excluded classes
    if ($element->hasAttribute('class')) {
        $classes = $element->getAttribute('class');
        if (strpos($classes, 'token') !== false || 
            strpos(strtolower($classes), 'code') !== false ||
            strpos($classes, 'no-translate') !== false) {
            return 0;
        }
        
        // Skip elements with translate class (already processed)
        if (strpos($classes, 'translate') !== false) {
            return 0;
        }
    }
    

    $child_nodes = array();
    foreach ($element->childNodes as $node) {
        $child_nodes[] = $node;
    }
    
    foreach ($child_nodes as $node) {
        if ($node->nodeType === XML_TEXT_NODE) {
            $original_text = $node->nodeValue; // Don't trim here, preserve whitespace structure
            if (empty(trim($original_text))) {
                continue; // Skip empty text nodes
            }
            
            // Skip if this text node is inside a multilang-wrapper
            $parent_element = $node->parentNode;
            while ($parent_element && $parent_element->nodeType === XML_ELEMENT_NODE) {
                if ($parent_element->hasAttribute('class') && 
                    strpos($parent_element->getAttribute('class'), 'multilang-wrapper') !== false) {
                    continue 2; // Skip this text node entirely
                }
                $parent_element = $parent_element->parentNode;
            }
            
            $translated = multilang_translate_text($original_text, $current_lang_translations, $default_lang_translations, $current_lang, $default_lang);
            if ($translated !== $original_text) {
                // Use a simpler approach - create the HTML string and parse it
                $wrapper_html = '<span class="multilang-wrapper" data-original-text="' . esc_attr(trim($original_text)) . '">' . $translated . '</span>';
                

                $temp_doc = new DOMDocument();
                $temp_doc->encoding = 'UTF-8';
                libxml_use_internal_errors(true);
                $temp_doc->loadHTML('<?xml encoding="UTF-8"><div>' . $wrapper_html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                libxml_clear_errors();
                
                $temp_wrapper = $temp_doc->getElementsByTagName('span')->item(0);
                if ($temp_wrapper) {
                    // Import the wrapper into the main document
                    $imported_wrapper = $element->ownerDocument->importNode($temp_wrapper, true);
                    

                    $xpath = new DOMXPath($element->ownerDocument);
                    $translation_spans = $xpath->query('.//span[contains(@class, "translate")]', $imported_wrapper);
                    foreach ($translation_spans as $span) {
                        $span->setAttribute('data-original-text', trim($original_text));
                    }
                    
                    $element->replaceChild($imported_wrapper, $node);
                    $replacements_made++;
                }
            }
        } elseif ($node->nodeType === XML_ELEMENT_NODE) {
            // Recursively process child elements
            $replacements_made += multilang_wrap_text_nodes($node, $current_lang_translations, $default_lang_translations, $current_lang, $default_lang);
            

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

/**
 * Server-side translation that processes the entire page HTML
 */
static $multilang_translation_cache = null;

/**
 * Start output buffering to capture entire page
 */
function multilang_start_page_buffer() {
    // Don't process backend operations
    if ( multilang_is_backend_operation() ) {
        return;
    }
    
    // Debug: Add comment to show function is being called
    if (!is_admin()) {
        // error_log('Multilang: start_page_buffer called for: ' . $_SERVER['REQUEST_URI']);
    }
    

    $structure_file = get_structure_file_path();
    $has_server_sections = false;
    
    if (file_exists($structure_file)) {
        $structure_content = file_get_contents($structure_file);
        $structure_data = json_decode($structure_content, true);
        
        if ($structure_data && is_array($structure_data)) {
            foreach ($structure_data as $section => $config) {
                $section_method = isset($config['_method']) ? $config['_method'] : 'server';
                if ($section_method === 'server') {
                    $has_server_sections = true;
                    break;
                }
            }
        }
    }
    
    // Fallback to global setting if no structure data found
    if (!$has_server_sections) {
        $translation_method = get_option('multilang_container_translation_method', 'server');
        $has_server_sections = ($translation_method === 'server');
    }
    
    // Debug: Log whether server sections were found
    if (!is_admin()) {
        // error_log('Multilang: has_server_sections = ' . ($has_server_sections ? 'true' : 'false'));
    }
    
    if (!$has_server_sections) {
        return;
    }
    
    ob_start('multilang_process_entire_page');
}
add_action('template_redirect', 'multilang_start_page_buffer', 0);

/**
 * Process the entire page HTML output
 */
function multilang_process_entire_page($html) {
    // Don't process backend operations
    if ( multilang_is_backend_operation() ) {
        if (!is_admin()) {
            // error_log('Multilang: Skipping - backend operation detected');
        }
        return $html;
    }
    
    // Debug: Log processing attempt (minimal logging)
    if (!is_admin()) {
        // Removed verbose HTML processing logs
    }
    
    // Skip if HTML is too short or already processed
    if (strlen($html) < 100 || strpos($html, 'multilang-wrapper') !== false) {
        if (!is_admin()) {
            // error_log('Multilang: Skipping - HTML too short (' . strlen($html) . ') or already processed');
        }
        return $html;
    }
    
    // Don't process if this is actual admin dashboard content
    if ( strpos($html, '/wp-admin/') !== false && strpos($html, 'wp-admin-bar') === false ) {
        //return $html;
    }
    

    $processed_html = multilang_server_side_translate($html);
    $processed_html = str_replace('</head>', '<!-- Server-side translation processed --></head>', $processed_html);
    
    // Log if translations were made
    return $processed_html;
}

/**
 * Check if text has translation in ANY language file
 */
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

/**
 * Check if text contains any words that have translations in ANY language file (universal detection)
 */
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
 * Universal partial text translation - finds and translates any translatable words in text
 * ONLY replaces complete words, never partial matches within words
 */
function multilang_translate_partial_text($text, $lang_data) {
    $result = $text;
    $has_translation = false;
    
    // Find all words in the text with word boundaries
    preg_match_all('/\b\w+\b/u', $text, $matches, PREG_OFFSET_CAPTURE);
    $words = $matches[0];
    

    $words = array_reverse($words);
    
    foreach ($words as $word_data) {
        $word = $word_data[0];
        $offset = $word_data[1];
        
        // Skip single letter "words" unless they're standalone (like weekday abbreviations)
        if (strlen($word) === 1) {
            // Only translate single letters if they're truly standalone with spaces around them
            $before_char = $offset > 0 ? $text[$offset - 1] : ' ';
            $after_char = $offset + 1 < strlen($text) ? $text[$offset + 1] : ' ';
            
            // Skip if single letter is part of a contraction or compound word
            if ($before_char === "'" || $after_char === "'" || 
                ctype_alpha($before_char) || ctype_alpha($after_char)) {
                continue;
            }
        }
        

        $word_translation = multilang_find_translation_in_data($word, $lang_data);
        if ($word_translation && $word_translation !== $word) {
            // Use word boundary replacement to ensure we don't break contractions
            $pattern = '/\b' . preg_quote($word, '/') . '\b/u';
            $replacement_made = false;
            
            // Only replace if it matches at the exact position we found
            if (preg_match($pattern, substr($text, $offset, strlen($word)))) {
                $result = substr_replace($result, $word_translation, $offset, strlen($word));
                $has_translation = true;
            }
        }
    }
    
    return $has_translation ? $result : null;
}

/**
 * Get all container selectors from structure.json
 */
function multilang_get_all_container_selectors() {
    static $all_selectors = null;
    
    if ($all_selectors === null) {
        $all_selectors = array();
        

        $structure_file = get_structure_file_path();
        if (file_exists($structure_file)) {
            $structure_content = file_get_contents($structure_file);
            $structure_data = json_decode($structure_content, true);
            
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

/**
 * Convert basic CSS selectors to XPath (simplified version for common cases)
 */
function multilang_css_to_xpath($selector) {
    // Handle basic class selectors (.classname)
    if (preg_match('/^\.([a-zA-Z0-9_-]+)$/', $selector, $matches)) {
        return "//*[contains(concat(' ', normalize-space(@class), ' '), ' " . $matches[1] . " ')]";
    }
    
    // Handle ID selectors (#idname)
    if (preg_match('/^#([a-zA-Z0-9_-]+)$/', $selector, $matches)) {
        return "//*[@id='" . $matches[1] . "']";
    }
    
    // Handle element selectors (tagname)
    if (preg_match('/^[a-zA-Z0-9]+$/', $selector)) {
        return "//" . $selector;
    }
    
    // Handle combined class selectors (.class1.class2)
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
    
    // Fallback for complex selectors - return null to skip
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
                // Skip empty translations
                if (empty(trim($translation))) {
                    continue;
                }
                
                // Look for word boundaries to avoid partial word replacements
                if (preg_match('/\b' . preg_quote($source, '/') . '\b/i', $text)) {
                    $translated_text = preg_replace('/\b' . preg_quote($source, '/') . '\b/i', $translation, $translated_text);
                    $found_translation = true;
                }
            }
        }
    }
    
    $result = $found_translation ? $translated_text : null;
    $partial_cache[$cache_key] = $result;
    return $result;
}