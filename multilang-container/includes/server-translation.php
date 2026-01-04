<?php

if (!defined('ABSPATH')) {
    exit;
}

// Load language data from file if cache-handler is not available
function load_language_data($lang) {
    // Static cache to avoid repeated file I/O
    static $language_data_cache = array();
    
    if (isset($language_data_cache[$lang])) {
        return $language_data_cache[$lang];
    }
    
    $base_dir = dirname(__FILE__) . '/../languages/';
    $file = $base_dir . $lang . '.json';
    if (!file_exists($file)) {
        // Check old uploads location for language file
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
            $language_data_cache[$lang] = $data;
            return $data;
        }
    }
    
    $language_data_cache[$lang] = array();
    return array();
}

/**
 * Get current page path and slug for page-specific translation filtering
 */
function multilang_get_current_page_info() {
    static $page_info = null;
    
    if ($page_info !== null) {
        return $page_info;
    }
    
    global $post;
    
    // Get URL path
    $url_path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
    
    // Check if we're on the home page
    if (is_front_page() || is_home() || empty($url_path)) {
        $page_info = array(
            'path' => '/',
            'slug' => 'home'
        );
        return $page_info;
    }
    
    // Get page/post slug
    $slug = '';
    if (isset($post->post_name)) {
        $slug = $post->post_name;
    } else {
        $parts = explode('/', $url_path);
        $slug = end($parts);
    }
    
    $page_info = array(
        'path' => '/' . $url_path,
        'slug' => $slug
    );
    
    return $page_info;
}

/**
 * Check if a section should be applied to the current page
 */
function multilang_should_apply_section($section_pages) {
    // If no pages setting or *, apply to all pages
    if (empty($section_pages) || $section_pages === '*') {
        return true;
    }
    
    static $page_info = null;
    if ($page_info === null) {
        $page_info = multilang_get_current_page_info();
    }
    
    $current_path = $page_info['path'];
    $current_slug = $page_info['slug'];
    
    // Cache processed pages per section_pages string
    static $processed_cache = array();
    $cache_key = $section_pages;
    
    if (!isset($processed_cache[$cache_key])) {
        // Split pages by comma and trim
        $allowed_pages = array_map('trim', explode(',', $section_pages));
        
        // Clean up each allowed page entry (strip domains, normalize paths)
        $cleaned_pages = array();
        foreach ($allowed_pages as $page) {
            // Strip http://, https://, and domain if present
            if (strpos($page, 'http') === 0) {
                $page = preg_replace('#^https?://[^/]+#i', '', $page);
            }
            $page = trim($page);
            
            // Ensure it starts with / if not empty
            if (!empty($page) && $page[0] !== '/') {
                $page = '/' . $page;
            }
            
            $cleaned_pages[] = array(
                'path' => $page,
                'slug' => trim($page, '/')
            );
        }
        $processed_cache[$cache_key] = $cleaned_pages;
    }
    
    // Check against cached cleaned pages
    foreach ($processed_cache[$cache_key] as $page_data) {
        if ($page_data['path'] === $current_path || $page_data['slug'] === $current_slug) {
            return true;
        }
    }
    
    return false;
}

function multilang_server_side_translate( $content, $force_lang = null ) {
    
    static $call_count = 0;
    static $translations = null;
    static $lang_cache = array();
    
    $call_count++;
    
    if ( multilang_is_backend_operation() ) {
        return $content;
    }

    // Early return for empty or very short content (performance optimization)
    if (empty($content) || strlen($content) < 10) {
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
    
    // Use force_lang if set, otherwise use current language
    $current_lang = $force_lang ?: $current_lang_cache;
    
    // Note: We no longer skip translation for default language because we need to wrap
    // content with <span class="translate lang-xx"> elements so JavaScript can show/hide
    // them when users switch languages. Without this wrapping, the default language
    // content is invisible to the translation system.
    
    if ($translations === null) {
        $translations = load_translations();
        if ( empty($translations) ) {
            $translations = false;
        }
    }
    
    if ( $translations === false ) {
        return $content;
    }
    
    // Remove disabled sections from translations if structure data is available
    $structure_data = false;
    if (function_exists('multilang_get_cached_structure_data')) {
        $structure_data = multilang_get_cached_structure_data();
    } else if (function_exists('load_structure_data')) {
        $structure_data = load_structure_data();
    }
    $filtered_translations = $translations;
    if ($structure_data && is_array($structure_data)) {
        foreach ($filtered_translations as $section => $keys) {
            // Skip disabled sections
            if (isset($structure_data[$section]['_disabled']) && $structure_data[$section]['_disabled']) {
                unset($filtered_translations[$section]);
                continue;
            }
            // Skip sections that don't apply to current page
            $section_pages = isset($structure_data[$section]['_pages']) ? $structure_data[$section]['_pages'] : '*';
            if (!multilang_should_apply_section($section_pages)) {
                unset($filtered_translations[$section]);
            }
        }
    }
    $processed_content = multilang_process_text_for_translations($content, $filtered_translations, $current_lang, $default_lang_cache);
    return $processed_content;
}

function multilang_process_text_for_translations($html, $translations, $current_lang, $default_lang) {
    // Set up structure data if not already done (single cached instance)
    static $structure_data = null;
    if ($structure_data === null) {
        if (function_exists('multilang_get_cached_structure_data')) {
            $structure_data = multilang_get_cached_structure_data();
        } else if (function_exists('load_structure_data')) {
            $structure_data = load_structure_data();
        } else {
            $structure_data = false;
        }
    }
    if (empty($translations) || strlen($html) < 100) {
        return $html; // Early return if translations are empty or HTML is too short
    }
    
    // Use the same structure_data instance for filtering
    $filtered_translations = array();
    if ($structure_data && is_array($structure_data)) {
        foreach ($translations as $section => $keys) {
            // Skip disabled sections
            if (isset($structure_data[$section]['_disabled']) && $structure_data[$section]['_disabled']) {
                continue;
            }
            // Skip sections that don't apply to current page
            $section_pages = isset($structure_data[$section]['_pages']) ? $structure_data[$section]['_pages'] : '*';
            if (!multilang_should_apply_section($section_pages)) {
                continue;
            }
            $filtered_translations[$section] = $keys;
        }
    } else {
        $filtered_translations = $translations;
    }
    $translations = $filtered_translations;

    static $lang_cache = array();
    if (!isset($lang_cache[$current_lang])) {
        $lang_cache[$current_lang] = multilang_get_language_data($current_lang);
    }
    if (!isset($lang_cache[$default_lang])) {
        $lang_cache[$default_lang] = multilang_get_language_data($default_lang);
    }
    $current_lang_translations = $lang_cache[$current_lang];
    $default_lang_translations = $lang_cache[$default_lang];

    // Direct HTML block translation using DOM normalization
    $normalize_html = function($str) {
        $str = str_replace(['\/', '\/'], '/', $str);
        $str = str_replace('\\', '/', $str);
        $str = html_entity_decode($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"?><div>' . $str . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $div = $doc->getElementsByTagName('div')->item(0);
        $normalized = $div ? $doc->saveHTML($div) : $str;
        $normalized = html_entity_decode($normalized, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalized = preg_replace('/>\s+</', '><', $normalized);
        $normalized = trim($normalized);
        $normalized = str_replace(['\/', '\/'], '/', $normalized);
        $normalized = str_replace('\\', '/', $normalized);
        return $normalized;
    };

    // Skip block matching for very large input or full page content
    // Don't try to match entire pages - this logic is for fragments only
    $is_full_page = (strpos($html, '<html') !== false || strpos($html, '<body') !== false || strpos($html, '<!DOCTYPE') !== false);
    if (strlen($html) > 100000 || $is_full_page) {
        // Large input or full page: skip block matching, use DOM processing instead
    } else {
        // Only attempt direct HTML matching for small fragments (not full pages)
        $direct_translation = null;
        
        if ($structure_data && is_array($structure_data)) {
            foreach ([$current_lang_translations, $default_lang_translations] as $translations_set) {
                foreach ($translations_set as $category => $keys) {
                    if ($structure_data && isset($structure_data[$category]['_disabled']) && $structure_data[$category]['_disabled']) continue;
                    if (!is_array($keys)) continue;
                    foreach ($keys as $key => $val) {
                        if (strpos($key, '<') !== false && strpos($key, '>') !== false) {
                            $normalized_key = $normalize_html($key);
                            $normalized_input = $normalize_html($html);
                            
                            // Only match if the fragment is roughly the same size (not entire page)
                            $key_len = strlen($normalized_key);
                            $input_len = strlen($normalized_input);
                            
                            // Skip if input is much larger than key (likely a full page, not a fragment)
                            if ($input_len > $key_len * 3) {
                                continue;
                            }
                            
                            $pattern = preg_quote($normalized_key, '#');
                            $match = preg_match('#' . $pattern . '#u', $normalized_input);
                            if ($match && !empty($val)) {
                                // Direct translation triggered - but only for small fragments
                                $direct_translation = $val;
                                $direct_translation_key = $key;
                                break 3;
                            }
                        }
                    }
                }
            }
        }
        
        if ($direct_translation) {
            // Always wrap in <span class="multilang-wrapper"> and <div class="translate lang-xx">
            $all_langs = function_exists('get_multilang_available_languages') ? get_multilang_available_languages() : [$current_lang];
            $default_lang = function_exists('get_multilang_default_language') ? get_multilang_default_language() : $current_lang;
            $translations = [];
            $data_translation = [];
            $data_default_text = '';
            foreach ($all_langs as $lang) {
                $lang_class = 'lang-' . htmlspecialchars($lang);
                $lang_val = $direct_translation;
                if (function_exists('multilang_get_language_data')) {
                    $lang_data = multilang_get_language_data($lang);
                    foreach ($lang_data as $cat => $keys) {
                        if (isset($keys[$direct_translation_key])) {
                            $lang_val = $keys[$direct_translation_key];
                            break;
                        }
                    }
                }
                if ($lang === $default_lang) {
                    $data_default_text = $lang_val;
                }
                $data_translation[$lang] = $lang_val;
                
                // CRITICAL: Always encode data-translation attribute, even if it contains the same text
                // This ensures JavaScript can properly decode and display it when switching languages
                $encoded_translation = htmlspecialchars($lang_val, ENT_QUOTES, 'UTF-8');
                $translations[] = '<span class=\'translate lang-' . $lang_class . '\' data-default-text=\'' . $encoded_translation . '\' data-translation=\'' . $encoded_translation . '\' data-original-text=\'' . htmlspecialchars($direct_translation_key, ENT_QUOTES, 'UTF-8') . '\'>' . $lang_val . '</span>';
            }
            $data_translation_json = htmlspecialchars(json_encode($data_translation), ENT_QUOTES, 'UTF-8');
            $wrapped = '<span class="multilang-wrapper" data-original-text="' . htmlspecialchars($direct_translation_key, ENT_QUOTES, 'UTF-8') . '" data-multilang="1" data-translation="' . $data_translation_json . '" data-default-text="' . htmlspecialchars($data_default_text, ENT_QUOTES, 'UTF-8') . '">' . implode('', $translations) . '</span>';
            return $wrapped;
        }
    }
    if (empty($current_lang_translations) && empty($default_lang_translations)) {
        return $html;
    }

    // Only use keys from the current section for inline/partial translation
    $dom = new DOMDocument();
    $dom->encoding = 'UTF-8';
    $dom->preserveWhiteSpace = true;
    $dom->formatOutput = false;
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    $body = $dom->getElementsByTagName('body')->item(0);
    if (!$body) {
        return $html;
    }

    // Try to find the main selector(s) for this section from structure_data
    $replacements_made = 0;
    if ($structure_data && is_array($structure_data)) {
        $xpath = new DOMXPath($dom);
        foreach ($structure_data as $category => $config) {
            if (isset($config['_selectors']) && is_array($config['_selectors']) && isset($translations[$category])) {
                foreach ($config['_selectors'] as $selector) {
                    $xp = multilang_css_to_xpath($selector);
                    if ($xp) {
                        $elements = $xpath->query($xp);
                        if ($elements && $elements->length > 0) {
                            foreach ($elements as $el) {
                                // Always pass section name for wrapper logic, matching selective function
                                $section_current_lang = isset($current_lang_translations[$category]) ? array($category => $current_lang_translations[$category]) : array();
                                $section_default_lang = isset($default_lang_translations[$category]) ? array($category => $default_lang_translations[$category]) : array();
                                $replacements_made += multilang_wrap_text_nodes($el, $section_current_lang, $section_default_lang, $current_lang, $default_lang, array(), $category);
                            }
                        }
                    }
                }
            }
        }
    } else {
        // Fallback: walk the whole body
        $replacements_made = multilang_wrap_text_nodes_selective($body, $current_lang_translations, $default_lang_translations, $current_lang, $default_lang);
    }

    if ($replacements_made === 0) {
        // Fallback: robust HTML fragment replacement inside selectors with ultra-verbose debug logging
        $did_replace = false;
        if ($structure_data && is_array($structure_data)) {
            $dom_fallback = new DOMDocument();
            $dom_fallback->encoding = 'UTF-8';
            libxml_use_internal_errors(true);
            $dom_fallback->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            libxml_clear_errors();
            $xpath = new DOMXPath($dom_fallback);
            foreach ($structure_data as $category => $config) {
                if (isset($config['_selectors']) && is_array($config['_selectors']) && isset($translations[$category])) {
                    // Collect all keys/vals for this section only
                    $all_keys = [];
                    $all_vals = [];
                    foreach ([$current_lang_translations[$category] ?? [], $default_lang_translations[$category] ?? []] as $keys) {
                        if (!is_array($keys)) continue;
                        foreach ($keys as $key => $val) {
                            if (!empty($key) && !empty($val)) {
                                $all_keys[] = $key;
                                $all_vals[] = $val;
                            }
                        }
                    }
                    array_multisort(array_map('strlen', $all_keys), SORT_DESC, $all_keys, $all_vals);
                    foreach ($config['_selectors'] as $selector) {
                        $xp = multilang_css_to_xpath($selector);
                        // Selector and XPath info
                        if ($xp) {
                            $elements = $xpath->query($xp);
                            if ($elements && $elements->length > 0) {
                                foreach ($elements as $el) {
                                    $orig_html = '';
                                    foreach ($el->childNodes as $child) {
                                        $orig_html .= $dom_fallback->saveHTML($child);
                                    }
                                    $normalized_html = $normalize_html($orig_html);
                                    // Original and normalized HTML
                                    foreach ($all_keys as $i => $k) {
                                        $normalized_key = $normalize_html($k);
                                        $pos = mb_strpos($normalized_html, $normalized_key);
                                        if ($pos !== false) {
                                            $pattern = '/' . preg_quote($k, '/') . '/u';
                                            $replacement = '<span class="multilang-wrapper" data-multilang="1">' . $all_vals[$i] . '</span>';
                                            $new_html = preg_replace($pattern, $replacement, $orig_html, 1, $count);
                                            // Replacement made
                                            if ($count > 0) {
                                                $did_replace = true;
                                                while ($el->firstChild) {
                                                    $el->removeChild($el->firstChild);
                                                }
                                                $tmp_dom = new DOMDocument();
                                                libxml_use_internal_errors(true);
                                                $tmp_dom->loadHTML('<?xml encoding="UTF-8">' . $new_html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                                                libxml_clear_errors();
                                                $tmp_body = $tmp_dom->getElementsByTagName('body')->item(0);
                                                if ($tmp_body) {
                                                    foreach ($tmp_body->childNodes as $import) {
                                                        $el->appendChild($dom_fallback->importNode($import, true));
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
            $result = $dom_fallback->saveHTML();
            $result = preg_replace('/^<!DOCTYPE.+?>/', '<!DOCTYPE html>', str_replace('<?xml encoding="UTF-8">', '', $result));
            if ($did_replace && is_string($result) && strlen(trim($result)) > 0 && strpos($result, '<') !== false) {
                return $result;
            }
            return $html;
        } else {
            $replacements_made = multilang_wrap_text_nodes_selective($body, $current_lang_translations, $default_lang_translations, $current_lang, $default_lang);
        }
    }
    $result = $dom->saveHTML();
    $result = preg_replace('/^<!DOCTYPE.+?>/', '<!DOCTYPE html>', str_replace('<?xml encoding="UTF-8">', '', $result));
    if (!is_string($result) || strlen(trim($result)) === 0 || strpos($result, '<') === false) {
        return $html;
    }
    return $result;
}

function multilang_get_language_data($lang) {
    // Use cached version if available, otherwise load directly
    if (function_exists('multilang_get_cached_language_data')) {
        $data = multilang_get_cached_language_data($lang);
        return $data;
    }
    // Fallback: load language data directly
    if (function_exists('load_language_data')) {
        $data = load_language_data($lang);
        return $data;
    }
    // Try global translations if available
    global $multilang_translations;
    if (isset($multilang_translations[$lang])) {
        return $multilang_translations[$lang];
    }
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
    
    // Remove disabled sections from translation arrays before lookup
    static $structure_data_guard = null;
    if ($structure_data_guard === null) {
        if (function_exists('multilang_get_cached_structure_data')) {
            $structure_data_guard = multilang_get_cached_structure_data();
        } else if (function_exists('load_structure_data')) {
            $structure_data_guard = load_structure_data();
        } else {
            $structure_data_guard = false;
        }
    }
    $current_translation = null;
    $default_translation = null;
    // Remove disabled sections from translation arrays before lookup
    if ($structure_data_guard && is_array($structure_data_guard)) {
        $filtered_current = array();
        foreach ($current_lang_translations as $category => $keys) {
            if (empty($structure_data_guard[$category]['_disabled'])) {
                $filtered_current[$category] = $keys;
            }
        }
        $filtered_default = array();
        foreach ($default_lang_translations as $category => $keys) {
            if (empty($structure_data_guard[$category]['_disabled'])) {
                $filtered_default[$category] = $keys;
            }
        }
        $current_lang_translations = $filtered_current;
        $default_lang_translations = $filtered_default;
    }
    $current_translation = multilang_find_translation_in_data($trimmed_text, $current_lang_translations);
    $default_translation = multilang_find_translation_in_data($trimmed_text, $default_lang_translations);

    $default_partial_translation = null;
    if (!$default_translation && strlen($trimmed_text) <= 50) {
        $default_partial_translation = multilang_process_partial_translation($trimmed_text, $default_lang_translations);
    }

    // Only process if a translation exists in the current section (no cross-section fallback)
    $should_process = $current_translation || $default_translation || $default_partial_translation;

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
        } else if (function_exists('load_structure_data')) {
            $structure_data = load_structure_data();
        } else {
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
        // If section is disabled, pass empty translations so nothing is translated
        $is_disabled = isset($structure_data[$section]['_disabled']) && $structure_data[$section]['_disabled'];
        $section_current_lang = (!$is_disabled && isset($current_lang_translations[$section])) ? array($section => $current_lang_translations[$section]) : array();
        $section_default_lang = (!$is_disabled && isset($default_lang_translations[$section])) ? array($section => $default_lang_translations[$section]) : array();
        
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
                                error_log('[Multilang Server] Added JS section class to skip: ' . $class_name . ' from section: ' . $other_section);
                            }
                        }
                    }
                }
            }
        }
        
        error_log('[Multilang Server] Processing section: ' . $section . ' | JS classes to skip: ' . implode(', ', $javascript_section_classes));
        
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
        // If this section is disabled, do not translate anything
        static $structure_data_guard = null;
        if ($structure_data_guard === null) {
            if (function_exists('multilang_get_cached_structure_data')) {
                $structure_data_guard = multilang_get_cached_structure_data();
            } else if (function_exists('load_structure_data')) {
                $structure_data_guard = load_structure_data();
            } else {
                $structure_data_guard = false;
            }
        }
        if ($current_section && $structure_data_guard && isset($structure_data_guard[$current_section]['_disabled']) && $structure_data_guard[$current_section]['_disabled']) {
            return 0;
        }
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
                error_log('[Multilang Server] Skipping element with JS class: ' . $js_class . ' | Element classes: ' . $element_classes);
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
    
    // --- NEW: Use DOM to reach selector, regex/string replace for HTML fragment translation ---
    $html_keys = array();
    foreach ([$current_lang_translations, $default_lang_translations] as $lang_translations) {
        if (!is_array($lang_translations)) continue;
        foreach ($lang_translations as $cat => $keys) {
            if (!is_array($keys)) continue;
            foreach ($keys as $key => $val) {
                if (strpos($key, '<') !== false && strpos($key, '>') !== false && !empty($val)) {
                    $html_keys[$key] = $val;
                }
            }
        }
    }

    if (!empty($html_keys)) {
        // Get inner HTML as string
        $inner_html = '';
        foreach ($child_nodes as $child) {
            $inner_html .= $element->ownerDocument->saveHTML($child);
        }
        $replaced_html = $inner_html;
        foreach ($html_keys as $frag => $replacement) {
            // Always wrap the replacement in the required structure
            $all_langs = function_exists('get_multilang_available_languages') ? get_multilang_available_languages() : [$current_lang];
            $default_lang = function_exists('get_multilang_default_language') ? get_multilang_default_language() : $current_lang;
            $translations = [];
            $data_translation = [];
            $data_default_text = '';
            foreach ($all_langs as $lang) {
                $lang_class = 'lang-' . htmlspecialchars($lang);
                $lang_val = $replacement;
                if (function_exists('multilang_get_language_data')) {
                    $lang_data = multilang_get_language_data($lang);
                    foreach ($lang_data as $cat => $keys) {
                        if (isset($keys[$frag])) {
                            $lang_val = $keys[$frag];
                            break;
                        }
                    }
                }
                if ($lang === $default_lang) {
                    $data_default_text = $lang_val;
                }
                $data_translation[$lang] = $lang_val;
                $translations[] = '<div class=\'translate ' . $lang_class . '\' data-default-text=\'' . htmlspecialchars($lang_val, ENT_QUOTES, 'UTF-8') . '\' data-translation=\'' . htmlspecialchars($lang_val, ENT_QUOTES, 'UTF-8') . '\'>' . $lang_val . '</div>';
            }
            $data_translation_json = htmlspecialchars(json_encode($data_translation), ENT_QUOTES, 'UTF-8');
            $wrapped = '<div class="multilang-wrapper" data-multilang="1" data-translation="' . $data_translation_json . '" data-default-text="' . htmlspecialchars($data_default_text) . '">' . implode('', $translations) . '</div>';
            // Try direct string replacement first
            $count = 0;
            $replaced_html = str_replace($frag, $wrapped, $replaced_html, $count);
            if ($count > 0) {
                $replacements_made += $count;
                continue;
            }
            // Try regex replacement (ignore whitespace, allow flexible attribute order)
            $pattern = '/' . preg_quote($frag, '/') . '/u';
            $replaced_html2 = preg_replace($pattern, $wrapped, $replaced_html, -1, $count2);
            if ($count2 > 0) {
                $replacements_made += $count2;
                $replaced_html = $replaced_html2;
            }
        }
        // If any replacements were made, update the element's children
        if ($replacements_made > 0) {
            while ($element->firstChild) {
                $element->removeChild($element->firstChild);
            }
            $tmp_doc = new DOMDocument();
            $tmp_doc->encoding = 'UTF-8';
            libxml_use_internal_errors(true);
            $tmp_doc->loadHTML('<?xml encoding="UTF-8"?><div>' . $replaced_html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            libxml_clear_errors();
            $div = $tmp_doc->getElementsByTagName('div')->item(0);
            if ($div) {
                foreach ($div->childNodes as $new_child) {
                    $imported = $element->ownerDocument->importNode($new_child, true);
                    $element->appendChild($imported);
                }
            }
        }
    }

    // Continue with text node and attribute translation as before
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

            // Only wrap if translation contains language spans (full match)
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
            } else {
                // Try partial translation (replace any matching substring)
                $partial_translated = multilang_process_partial_translation($original_text, $current_lang_translations);
                if (!$partial_translated) {
                    $partial_translated = multilang_process_partial_translation($original_text, $default_lang_translations);
                }
                if ($partial_translated && $partial_translated !== $original_text) {
                    $node->nodeValue = $partial_translated;
                    $replacements_made++;
                }
            }
        } elseif ($node->nodeType === XML_ELEMENT_NODE) {
            $replacements_made += multilang_wrap_text_nodes($node, $current_lang_translations, $default_lang_translations, $current_lang, $default_lang, $javascript_section_classes, $current_section);

            // Translate common attributes for all elements
            foreach (array('title', 'data-title', 'alt') as $attr) {
                if ($node->hasAttribute($attr)) {
                    $attr_value = $node->getAttribute($attr);
                    $translated_attr = multilang_translate_text($attr_value, $current_lang_translations, $default_lang_translations, $current_lang, $default_lang);
                    if ($translated_attr !== $attr_value) {
                        $node->setAttribute($attr, $translated_attr);
                    }
                }
            }

            // For input elements, add value-xx attributes for each language and set value to default language
            if (strtolower($node->nodeName) === 'input' && $node->hasAttribute('value')) {
                $attr_value = $node->getAttribute('value');
                if (!empty($attr_value)) {
                    // Get all available languages
                    $langs = function_exists('get_multilang_available_languages') ? get_multilang_available_languages() : array($default_lang);
                    // Set value-xx for each language
                    foreach ($langs as $lang) {
                        $lang_data = function_exists('multilang_get_language_data') ? multilang_get_language_data($lang) : array();
                        $translation = multilang_find_translation_in_data($attr_value, $lang_data);
                        if (!$translation && strlen($attr_value) <= 50) {
                            $translation = function_exists('multilang_process_partial_translation') ? multilang_process_partial_translation($attr_value, $lang_data) : null;
                        }
                        $final_value = $translation ? $translation : $attr_value;
                        $node->setAttribute('value-' . $lang, $final_value);
                    }
                    // Set value to default language
                    $default_translation = multilang_find_translation_in_data($attr_value, $default_lang_translations);
                    if (!$default_translation && strlen($attr_value) <= 50) {
                        $default_translation = function_exists('multilang_process_partial_translation') ? multilang_process_partial_translation($attr_value, $default_lang_translations) : null;
                    }
                    $final_default = $default_translation ? $default_translation : $attr_value;
                    $node->setAttribute('value', $final_default);
                }
            }
        }
    }

    return $replacements_made;
}

function multilang_wrap_text_nodes_regex($html, $current_lang_translations, $default_lang_translations, $current_lang, $default_lang) {
    // Tags to skip
    $skip_tags = '(script|style|code|pre|blockquote)';
    // Regex to match text nodes not inside skip tags
    $pattern = '/(<(?!' . $skip_tags . ')[a-zA-Z0-9]+[^>]*>)([^<]+)(<\/(?!' . $skip_tags . ')[a-zA-Z0-9]+>)/i';

    $callback = function($matches) use ($current_lang_translations, $default_lang_translations, $current_lang, $default_lang) {
        $original_text = $matches[2];
        if (empty(trim($original_text))) return $matches[0];

        $translated = multilang_translate_text($original_text, $current_lang_translations, $default_lang_translations, $current_lang, $default_lang);
        if ($translated !== $original_text && strpos($translated, 'class="translate lang-') !== false) {
            $wrapper_html = '<span class="multilang-wrapper" data-original-text="' . esc_attr(trim($original_text)) . '">' . $translated . '</span>';
            return $matches[1] . $wrapper_html . $matches[3];
        }
        return $matches[0];
    };

    $result = preg_replace_callback($pattern, $callback, $html);
    return $result;
}

// Page translation - buffers output and processes entire HTML
static $multilang_translation_cache = null;

function multilang_start_page_buffer() {
    if (is_admin()) {
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
// add_action('wp', 'multilang_start_page_buffer', 999);


function multilang_process_entire_page($html) {

    if ( multilang_is_backend_operation() ) {
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
    if (isset($post) && !empty($post->ID)) {
        $post_id = $post->ID;
    }
    if (!$post_id && function_exists('get_the_ID')) {
        $the_id = get_the_ID();
        if ($the_id) {
            $post_id = $the_id;
        }
    }
    if (!$post_id && function_exists('get_queried_object_id')) {
        $query_id = get_queried_object_id();
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
        if (function_exists('multilang_server_side_translate')) {
            return multilang_server_side_translate($html);
        } else {
            return $html;
        }
    }

    // Check if fragment caching functions are available
    if (!function_exists('multilang_retrieve_cached_fragments') || 
        !function_exists('multilang_inject_fragments_into_html') || 
        !function_exists('multilang_cache_fragments_from_html')) {
        // Fallback: process whole page without fragment caching
        if (function_exists('multilang_server_side_translate')) {
            return multilang_server_side_translate($html);
        } else {
            return $html;
        }
    }

    if (function_exists('multilang_inject_fragments_into_html')) {
        // For each section/selector, try to get cached fragment
        list($fragments, $all_found) = multilang_retrieve_cached_fragments($cache_page_key, $structure_data);

        // If all fragments are found, inject them and bypass heavy work
        if (!empty($fragments)) {
            $result_html = multilang_inject_fragments_into_html($html, $fragments);
            // If result is blank, empty, or missing key content, fallback to full translation
            $is_empty = empty(trim(strip_tags($result_html))) || strlen(trim($result_html)) < 100;
            $missing_body = (strpos($result_html, '<body') === false);
            if ($is_empty || $missing_body) {
                // Fallback: process whole page
                if (function_exists('multilang_server_side_translate')) {
                    $result_html = multilang_server_side_translate($html);
                } else {
                    $result_html = $html;
                }
            }
            return $result_html;
        }
    }

    // Otherwise, process the HTML and cache fragments
    if (function_exists('multilang_server_side_translate')) {
        $processed_html = multilang_server_side_translate($html);
        $processed_html = str_replace('</head>', '<!-- Server-side translation processed and cached --></head>', $processed_html);
    } else {
        $processed_html = $html;
    }

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

// Filter out translation keys from disabled sections globally
global $structure_data;
if (isset($structure_data) && is_array($structure_data)) {
    foreach ([$current_lang_translations, $default_lang_translations] as &$translations_set) {
        foreach ($translations_set as $category => $keys) {
            if (isset($structure_data[$category]['_disabled']) && $structure_data[$category]['_disabled']) {
                unset($translations_set[$category]);
            }
        }
    }
    unset($translations_set); // break reference
}

/**
 * Apply partial translation to all text nodes in an HTML string
 */
function multilang_partial_translate_html($html, $lang_data) {
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    $div = $doc->getElementsByTagName('div')->item(0);
    if (!$div) return $html;
    $walker = function($node) use (&$walker, $lang_data) {
        if ($node->nodeType === XML_TEXT_NODE) {
            $translated = multilang_process_partial_translation($node->nodeValue, $lang_data);
            if ($translated && $translated !== $node->nodeValue) {
                $node->nodeValue = $translated;
            }
        } elseif ($node->hasChildNodes()) {
            foreach ($node->childNodes as $child) {
                $walker($child);
            }
        }
    };
    $walker($div);
    $result = $doc->saveHTML($div);
    // Remove outer <div> if present
    if (strpos($result, '<div>') === 0 && substr($result, -6) === '</div>') {
        $result = substr($result, 5, -6);
    }
    return $result;
}