<?php

if (!defined('ABSPATH')) {
    exit;
}

// If cache-handler isn't available, load language data from file
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
    return array();
}

function multilang_server_side_translate( $content, $force_lang = null ) {
    
    static $call_count = 0;
    static $translations = null;
    static $lang_cache = array();
    
    $call_count++;
    
    if ( multilang_is_backend_operation() ) {
        return $content;
    }

    if (empty($content)) {
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
    
    // Use force_lang if provided, otherwise use the current language
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
    
    // If structure data is available, remove disabled sections from translations before processing
    $structure_data = false;
    if (function_exists('multilang_get_cached_structure_data')) {
        $structure_data = multilang_get_cached_structure_data();
    } else if (function_exists('load_structure_data')) {
        $structure_data = load_structure_data();
    }
    $filtered_translations = $translations;
    if ($structure_data && is_array($structure_data)) {
        foreach ($filtered_translations as $section => $keys) {
            if (isset($structure_data[$section]['_disabled']) && $structure_data[$section]['_disabled']) {
                unset($filtered_translations[$section]);
            }
        }
    }
    $processed_content = multilang_process_text_for_translations($content, $filtered_translations, $current_lang, $default_lang_cache);
    return $processed_content;
}

function multilang_process_text_for_translations($html, $translations, $current_lang, $default_lang) {
    if (empty($translations) || strlen($html) < 100) {
        return $html; // Early return if translations are empty or HTML is too short
    }
    // Remove all keys from disabled sections globally
    static $structure_data_patch = null;
    if ($structure_data_patch === null) {
        if (function_exists('multilang_get_cached_structure_data')) {
            $structure_data_patch = multilang_get_cached_structure_data();
        } else if (function_exists('load_structure_data')) {
            $structure_data_patch = load_structure_data();
        } else {
            $structure_data_patch = false;
        }
    }
    $filtered_translations = array();
    if ($structure_data_patch && is_array($structure_data_patch)) {
        foreach ($translations as $section => $keys) {
            if (isset($structure_data_patch[$section]['_disabled']) && $structure_data_patch[$section]['_disabled']) {
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

    // --- DIRECT HTML BLOCK TRANSLATION (with DOM-based normalization) ---
    // Ensure $structure_data is always initialized before use
    static $structure_data_initialized = false;
    // static $structure_data = null; // Removed duplicate declaration
    if (!$structure_data_initialized) {
        if (function_exists('multilang_get_cached_structure_data')) {
            $structure_data = multilang_get_cached_structure_data();
        } else if (function_exists('load_structure_data')) {
            $structure_data = load_structure_data();
        } else {
            $structure_data = false;
        }
        $structure_data_initialized = true;
    }

    $normalize_html = function($str, $strip_div = false) {
        $str = str_replace(['\\/', '\/'], '/', $str);
        $str = str_replace('\\', '/', $str);
        $str = str_replace('"', '"', $str);
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
        $normalized = str_replace(['\\/', '\/'], '/', $normalized);
        $normalized = str_replace('\\', '/', $normalized);
        if ($strip_div && strpos($normalized, '<div>') === 0 && substr($normalized, -6) === '</div>') {
            $normalized = substr($normalized, 5, -6);
        }
        return $normalized;
    };

    // Patch: If a translation key contains HTML, extract and normalize only the relevant section(s) before matching
    $extracted_section_html = null;
    $normalized_html = null;
    $html_key_section_match = null;
    if ($structure_data && is_array($structure_data)) {
        foreach ([$current_lang_translations, $default_lang_translations] as $translations_set) {
            foreach ($translations_set as $category => $keys) {
                $is_disabled = ($structure_data && isset($structure_data[$category]['_disabled']) && $structure_data[$category]['_disabled']);
                // Skip keys from disabled sections
                if ($is_disabled) {
                    continue;
                }
                if (!is_array($keys)) continue;
                foreach ($keys as $key => $val) {
                    // Double-check: skip if this key is from a disabled section
                    if ($is_disabled) {
                        continue;
                    }
                    if (strpos($key, '<') !== false && strpos($key, '>') !== false) {
                        // Skip keys from disabled sections
                        if ($is_disabled) {
                            continue;
                        }
                        if (isset($structure_data[$category]['_selectors']) && is_array($structure_data[$category]['_selectors'])) {
                            foreach ($structure_data[$category]['_selectors'] as $selector) {
                                $dom = new DOMDocument();
                                libxml_use_internal_errors(true);
                                $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                                libxml_clear_errors();
                                $xpath = multilang_css_to_xpath($selector);
                                if ($xpath) {
                                    $xp = new DOMXPath($dom);
                                    $elements = $xp->query($xpath);
                                    if ($elements && $elements->length > 0) {
                                        $section_htmls = [];
                                        foreach ($elements as $el) {
                                            $section_htmls[] = $dom->saveHTML($el);
                                        }
                                        $extracted_section_html = implode("\n", $section_htmls);
                                        $normalized_html = $normalize_html($extracted_section_html, true);
                                        $html_key_section_match = $category;
                                        break 4;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
    // Fallback: if no section extracted, normalize the whole HTML as before
    if ($normalized_html === null) {
        $normalized_html = $normalize_html($html);
    }
    $direct_translation = null;
    // Enhancement: If a translation key contains HTML, check for a match in the section selectors using regex
    $html_key_section_match = null;
    if ($structure_data && is_array($structure_data)) {
        foreach ([$current_lang_translations, $default_lang_translations] as $translations_set) {
            foreach ($translations_set as $category => $keys) {
                // Skip keys from disabled sections
                if ($structure_data && isset($structure_data[$category]['_disabled']) && $structure_data[$category]['_disabled']) continue;
                if (!is_array($keys)) continue;
                foreach ($keys as $key => $val) {
                    // Double-check: skip if this key is from a disabled section
                    if ($structure_data && isset($structure_data[$category]['_disabled']) && $structure_data[$category]['_disabled']) continue;
                    // Only process if the key contains HTML
                    if (strpos($key, '<') !== false && strpos($key, '>') !== false) {
                        // For each selector in this section, check for a regex match
                        if (isset($structure_data[$category]['_selectors']) && is_array($structure_data[$category]['_selectors'])) {
                            foreach ($structure_data[$category]['_selectors'] as $selector) {
                                // Class selector
                                if (strpos($selector, '.') === 0) {
                                    $class = preg_quote(substr($selector, 1), '/');
                                    if (preg_match('/class=["\"][^"\"]*' . $class . '\b/', $html)) {
                                        $html_key_section_match = $category;
                                        break 3;
                                    }
                                }
                                // Tag selector
                                elseif (preg_match('/^[a-zA-Z0-9_-]+$/', $selector)) {
                                    $tag = preg_quote($selector, '/');
                                    if (preg_match('/<' . $tag . '\b/', $html)) {
                                        $html_key_section_match = $category;
                                        break 3;
                                    }
                                }
                                // ID selector
                                elseif (strpos($selector, '#') === 0) {
                                    $id = preg_quote(substr($selector, 1), '/');
                                    if (preg_match('/id=["\"]' . $id . '\b/', $html)) {
                                        $html_key_section_match = $category;
                                        break 3;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
    // Load structure data to check for disabled sections (declare and initialize before any use)
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
    // DEBUG: Log normalized HTML and all keys for user extraction and troubleshooting
    $debug_normalized_keys = [];
        $debug_raw_keys = []; // Initialize raw keys array
    foreach ([$current_lang_translations, $default_lang_translations] as $translations_set) {
        foreach ($translations_set as $category => $keys) {
            if ($structure_data && isset($structure_data[$category]['_disabled']) && $structure_data[$category]['_disabled']) continue;
            if (!is_array($keys)) continue;
            foreach ($keys as $key => $val) {
                // If the key contains HTML, normalize it with strip_div=true to match extracted section normalization
                $norm_key = (strpos($key, '<') !== false && strpos($key, '>') !== false)
                    ? $normalize_html($key, true)
                    : $normalize_html($key);
                $debug_normalized_keys[] = $norm_key;
                $debug_raw_keys[] = $key;
            }
        }
    }
    // error_log('[multilang] Normalized HTML: ' . $normalized_html);
    // error_log('[multilang] Normalized keys: ' . json_encode($debug_normalized_keys));
    // error_log('[multilang] Raw keys: ' . json_encode($debug_raw_keys));
    // error_log('[multilang] Input HTML: ' . $html);
    // Also output as HTML comment for easier inspection in page source
    // if (isset($_GET['multilang_debug']) || (defined('MULTILANG_DEBUG') && MULTILANG_DEBUG)) {
    //    echo "\n<!-- multilang debug: normalized_html='" . htmlspecialchars($normalized_html) . "'\nnormalized_keys='" . htmlspecialchars(json_encode($debug_normalized_keys)) . "'\nraw_keys='" . htmlspecialchars(json_encode($debug_raw_keys)) . "'\ninput_html='" . htmlspecialchars($html) . "'\n-->\n";
    // }
    // New concise debug output: log only normalized input and first 5 normalized keys
    $debug_keys_sample = [];
    $found_match = false;
    // If we found a section match for an HTML key, only check that section for direct translation (normalized, full-block match only)
    if ($html_key_section_match) {
        foreach ([$current_lang_translations, $default_lang_translations] as $translations_set) {
            if (isset($translations_set[$html_key_section_match]) && is_array($translations_set[$html_key_section_match])) {
                foreach ($translations_set[$html_key_section_match] as $key => $val) {
                    // Export only the concatenated child nodes of a dummy wrapper (no wrapper in output)
                    $get_inner_html = function($html) {
                        $doc = new DOMDocument();
                        libxml_use_internal_errors(true);
                        $doc->loadHTML('<?xml encoding="UTF-8"?><dummy>' . $html . '</dummy>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                        libxml_clear_errors();
                        $dummy = $doc->getElementsByTagName('dummy')->item(0);
                        $inner = '';
                        if ($dummy) {
                            foreach ($dummy->childNodes as $child) {
                                $inner .= $doc->saveHTML($child);
                            }
                        } else {
                            $inner = $html;
                        }
                        return trim($inner);
                    };
                    $norm_key_inner = $get_inner_html($key);
                    // For the section: if it's a single element, unwrap and use only its inner HTML
                    $doc = new DOMDocument();
                    libxml_use_internal_errors(true);
                    $doc->loadHTML('<?xml encoding="UTF-8"?><dummy>' . $extracted_section_html . '</dummy>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                    libxml_clear_errors();
                    $dummy = $doc->getElementsByTagName('dummy')->item(0);
                    $normalized_html_inner = '';
                    if ($dummy && $dummy->childNodes->length === 1 && $dummy->firstChild->nodeType === XML_ELEMENT_NODE) {
                        // Unwrap: use only the inner HTML of the single element
                        $el = $dummy->firstChild;
                        foreach ($el->childNodes as $child) {
                            $normalized_html_inner .= $doc->saveHTML($child);
                        }
                    } else if ($dummy) {
                        // Multiple nodes: concatenate all
                        foreach ($dummy->childNodes as $child) {
                            $normalized_html_inner .= $doc->saveHTML($child);
                        }
                    } else {
                        $normalized_html_inner = $extracted_section_html;
                    }
                    $normalized_html_inner = trim($normalized_html_inner);

                    // DEBUG: Always save all normalized keys and originals, and save match if found
                    $all_keys_debug = [];
                    $found_match_debug = false;
                    $matched_key = null;
                    $matched_val = null;
                    foreach ([$current_lang_translations, $default_lang_translations] as $translations_set) {
                        foreach ($translations_set as $category => $keys) {
                            if (!is_array($keys)) continue;
                            foreach ($keys as $key => $val) {
                                $norm_key_inner = $get_inner_html($key);
                                $all_keys_debug[] = [
                                    'original' => $key,
                                    'normalized' => $norm_key_inner
                                ];
                                // Robust matching: strict, whitespace-insensitive, then regex substring
                                $norm_key_ws = preg_replace('/\s+/', '', $norm_key_inner);
                                $norm_html_ws = preg_replace('/\s+/', '', $normalized_html_inner);
                                $matched = false;
                                if ($norm_key_inner === $normalized_html_inner) {
                                    $matched = true;
                                } elseif ($norm_key_ws === $norm_html_ws) {
                                    $matched = true;
                                } elseif ($norm_key_inner && preg_match('/' . preg_quote($norm_key_inner, '/') . '/i', $normalized_html_inner)) {
                                    $matched = true;
                                }
                                if ($matched) {
                                    $found_match_debug = true;
                                    $matched_key = $key;
                                    $matched_val = $val;
                                    @file_put_contents('/var/www/html/search4god/matching_key_for_normalized_html_inner.txt', "MATCHED KEY (original):\n" . $key . "\nMATCHED KEY (normalized):\n" . $norm_key_inner . "\nMATCHED VALUE (normalized_html_inner):\n" . $normalized_html_inner);
                                    // Debug log for category, selector, and value
                                    $debug_line = '[MATCH] Category: ' . $html_key_section_match . ' | Selector: (see structure.json) | Key: ' . $key . ' | Value: ' . $val;
                                    @file_put_contents('/var/www/html/search4god/translation_debug.log', $debug_line . "\n", FILE_APPEND);
                                }
                            }
                        }
                    }
                    // Always save all keys for inspection
                    @file_put_contents('/var/www/html/search4god/all_normalized_keys_for_inner.txt', print_r($all_keys_debug, true));

                    // If a match is found, replace the block's inner HTML with the translation value
                    if ($found_match_debug && $matched_val !== null) {
                        // Remove all children from the block
                        while ($el->firstChild) {
                            $el->removeChild($el->firstChild);
                        }
                        // Insert the translation value as HTML
                        $tmp = new DOMDocument();
                        libxml_use_internal_errors(true);
                        $tmp->loadHTML('<?xml encoding="UTF-8"?><dummy>' . $matched_val . '</dummy>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                        libxml_clear_errors();
                        $dummy = $tmp->getElementsByTagName('dummy')->item(0);
                        if ($dummy) {
                            foreach ($dummy->childNodes as $newChild) {
                                $imported = $doc->importNode($newChild, true);
                                $el->appendChild($imported);
                            }
                        }
                    }
                    $is_match = ($norm_key_inner === $normalized_html_inner);
                    @file_put_contents('/var/www/html/search4god/normalized_section.html', $normalized_html_inner);
                    @file_put_contents('/var/www/html/search4god/normalized_key.html', $norm_key_inner);
                    if (count($debug_keys_sample) < 5) {
                        $debug_keys_sample[] = $norm_key;
                    }
                    if ($is_match) {
                        if (!empty($val)) {
                            // Unwrap the translation value (use only its inner HTML)
                            $val_inner = '';
                            $doc_val = new DOMDocument();
                            libxml_use_internal_errors(true);
                            $doc_val->loadHTML('<?xml encoding="UTF-8"?><dummy>' . $val . '</dummy>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                            libxml_clear_errors();
                            $dummy_val = $doc_val->getElementsByTagName('dummy')->item(0);
                            if ($dummy_val && $dummy_val->childNodes->length === 1 && $dummy_val->firstChild->nodeType === XML_ELEMENT_NODE) {
                                $el = $dummy_val->firstChild;
                                foreach ($el->childNodes as $child) {
                                    $val_inner .= $doc_val->saveHTML($child);
                                }
                            } else if ($dummy_val) {
                                foreach ($dummy_val->childNodes as $child) {
                                    $val_inner .= $doc_val->saveHTML($child);
                                }
                            } else {
                                $val_inner = $val;
                            }
                            $val_inner = trim($val_inner);


                            // Update the DOM: replace the inner HTML of the matched selector with a multilang-wrapper
                            $dom = new DOMDocument();
                            libxml_use_internal_errors(true);
                            $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                            libxml_clear_errors();

                            // Helper to set inner HTML of a DOMElement, using the correct DOMDocument
                            $set_inner_html = function($element, $html_fragment, $dom_for_import) {
                                while ($element->firstChild) {
                                    $element->removeChild($element->firstChild);
                                }
                                $tmp = new DOMDocument();
                                libxml_use_internal_errors(true);
                                $tmp->loadHTML('<?xml encoding="UTF-8"?><dummy>' . $html_fragment . '</dummy>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                                libxml_clear_errors();
                                $dummy = $tmp->getElementsByTagName('dummy')->item(0);
                                if ($dummy) {
                                    foreach ($dummy->childNodes as $child) {
                                        $imported = $dom_for_import->importNode($child, true);
                                        $element->appendChild($imported);
                                    }
                                }
                            };

                            // Update the DOM: replace the inner HTML of the matched selector with a multilang-wrapper
                            $dom = new DOMDocument();
                            libxml_use_internal_errors(true);
                            $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                            libxml_clear_errors();
                            $xpath = multilang_css_to_xpath($selector);
                            if ($xpath) {
                                $xp = new DOMXPath($dom);
                                $elements = $xp->query($xpath);
                                if ($elements && $elements->length > 0) {
                                    foreach ($elements as $el) {
                                        // Build the multilang-wrapper div with all language variants
                                        $langs = get_multilang_available_languages();
                                        $current_lang_code = $current_lang;
                                        $wrapper_html = '<div class="multilang-wrapper" data-original-text="' . htmlspecialchars($norm_key_inner, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '" data-default-text="">';
                                        foreach ($langs as $lang) {
                                            $lang_translations = isset($lang_cache[$lang]) ? $lang_cache[$lang] : multilang_get_language_data($lang);
                                            $lang_val = null;
                                            foreach ($lang_translations as $category => $keys) {
                                                if (!is_array($keys)) continue;
                                                foreach ($keys as $key => $val) {
                                                    $lang_norm_key_inner = $get_inner_html($key);
                                                    if ($lang_norm_key_inner === $norm_key_inner && !empty($val)) {
                                                        // Unwrap translation value for this language
                                                        $val_inner_lang = '';
                                                        $doc_val_lang = new DOMDocument();
                                                        libxml_use_internal_errors(true);
                                                        $doc_val_lang->loadHTML('<?xml encoding="UTF-8"?><dummy>' . $val . '</dummy>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                                                        libxml_clear_errors();
                                                        $dummy_val_lang = $doc_val_lang->getElementsByTagName('dummy')->item(0);
                                                        if ($dummy_val_lang && $dummy_val_lang->childNodes->length === 1 && $dummy_val_lang->firstChild->nodeType === XML_ELEMENT_NODE) {
                                                            $el_lang = $dummy_val_lang->firstChild;
                                                            foreach ($el_lang->childNodes as $child_lang) {
                                                                $val_inner_lang .= $doc_val_lang->saveHTML($child_lang);
                                                            }
                                                        } else if ($dummy_val_lang) {
                                                            foreach ($dummy_val_lang->childNodes as $child_lang) {
                                                                $val_inner_lang .= $doc_val_lang->saveHTML($child_lang);
                                                            }
                                                        } else {
                                                            $val_inner_lang = $val;
                                                        }
                                                        $lang_val = trim($val_inner_lang);
                                                        break 2;
                                                    }
                                                }
                                            }
                                            if (!$lang_val) {
                                                $lang_val = $val_inner;
                                            }
                                            $display = ($lang === $current_lang_code) ? '' : 'display:none;';
                                            $wrapper_html .= '<div class="translate lang-' . htmlspecialchars($lang, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '" style="' . $display . '">' . $lang_val . '</div>';
                                        }
                                        $wrapper_html .= '</div>';
                                        $set_inner_html($el, $wrapper_html, $dom);
                                    }
                                    // Save the full HTML after replacement to preserve layout
                                    $full_html = $dom->saveHTML();
                                    // Remove only the XML encoding declaration, but keep the rest of the HTML structure
                                    $full_html = preg_replace('/^<!DOCTYPE.+?>/', '', $full_html);
                                    $full_html = preg_replace('/<\?xml.*?\?>/', '', $full_html);
                                    $full_html = trim($full_html);
                                    $direct_translation = $full_html;
                                } else {
                                    $direct_translation = $val_inner;
                                }
                            } else {
                                $direct_translation = $val_inner;
                            }
                            $found_match = true;
                            break 2;
                        }
                    }
                }
            }
        }
    }
    // Fallback: search all sections if no match found (partial/fuzzy match for HTML keys)
    if (!$found_match) {
        foreach ([$current_lang_translations, $default_lang_translations] as $translations_set) {
            foreach ($translations_set as $category => $keys) {
                if ($structure_data && isset($structure_data[$category]['_disabled']) && $structure_data[$category]['_disabled']) continue;
                if (!is_array($keys)) continue;
                foreach ($keys as $key => $val) {
                    // Skip keys from disabled sections
                    if ($structure_data && isset($structure_data[$category]['_disabled']) && $structure_data[$category]['_disabled']) continue;
                    $norm_key = $normalize_html($key);
                    if (count($debug_keys_sample) < 5) {
                        $debug_keys_sample[] = $norm_key;
                    }
                    // Exact match
                    if ($norm_key === $normalized_html) {
                        if (!empty($val)) {
                            $direct_translation = $val;
                            $found_match = true;
                            break 2;
                        }
                    }
                    // Partial/fuzzy match: if key contains HTML and is a substring of normalized_html
                    if (!$found_match && strpos($key, '<') !== false && strpos($key, '>') !== false) {
                        if (strpos($normalized_html, $norm_key) !== false && !empty($val)) {
                            $direct_translation = $val;
                            $found_match = true;
                            break 2;
                        }
                    }
                }
            }
        }
    }
    // Always log debug info for troubleshooting
    if ($direct_translation) {
        // If a direct translation was made by DOM replacement, return the full HTML (layout preserved)
        if (is_string($direct_translation) && strlen(trim($direct_translation)) > 0 && strpos($direct_translation, '<') !== false) {
            return $direct_translation;
        } else {
            // If no direct translation, fall through to partial translation below
        }
    }
    if (empty($current_lang_translations) && empty($default_lang_translations)) {
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
        return $html;
    }

    // Try to find the main selector(s) for this section from structure_data
    $main_selectors = [];
    if ($structure_data && is_array($structure_data)) {
        foreach ($structure_data as $category => $config) {
            if (isset($config['_selectors']) && is_array($config['_selectors']) && isset($translations[$category])) {
                foreach ($config['_selectors'] as $selector) {
                    $main_selectors[] = $selector;
                }
            }
        }
    }

    $replacements_made = 0;
    if (!empty($main_selectors)) {
        $xpath = new DOMXPath($dom);
        foreach ($main_selectors as $selector) {
            $xp = multilang_css_to_xpath($selector);
            if ($xp) {
                $elements = $xpath->query($xp);
                if ($elements && $elements->length > 0) {
                    foreach ($elements as $el) {
                        $replacements_made += multilang_wrap_text_nodes($el, $current_lang_translations, $default_lang_translations, $current_lang, $default_lang);
                    }
                }
            }
        }
    } else {
        // Fallback: walk the whole body
        $replacements_made = multilang_wrap_text_nodes_selective($body, $current_lang_translations, $default_lang_translations, $current_lang, $default_lang);
    }

    if ($replacements_made === 0) {
        // Fallback: regex replace all translation keys inside the main selector(s) only, preserving HTML structure
        $dom_fallback = new DOMDocument();
        $dom_fallback->encoding = 'UTF-8';
        libxml_use_internal_errors(true);
        $dom_fallback->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom_fallback);
        $all_keys = [];
        $all_vals = [];
        foreach ([$current_lang_translations, $default_lang_translations] as $translations_set) {
            foreach ($translations_set as $category => $keys) {
                // Strictly skip keys from disabled sections
                if ($structure_data && isset($structure_data[$category]['_disabled']) && $structure_data[$category]['_disabled']) {
                    continue;
                }
                if (!is_array($keys)) continue;
                foreach ($keys as $key => $val) {
                    // Double-check: skip if this key is from a disabled section
                    if ($structure_data && isset($structure_data[$category]['_disabled']) && $structure_data[$category]['_disabled']) {
                        continue;
                    }
                    if (!empty($key) && !empty($val)) {
                        $all_keys[] = $key;
                        $all_vals[] = $val;
                    }
                }
            }
        }
        array_multisort(array_map('strlen', $all_keys), SORT_DESC, $all_keys, $all_vals);
        // Find main selectors from structure_data
        $main_selectors = [];
        if ($structure_data && is_array($structure_data)) {
            foreach ($structure_data as $category => $config) {
                if (isset($config['_selectors']) && is_array($config['_selectors']) && isset($translations[$category])) {
                    foreach ($config['_selectors'] as $selector) {
                        $main_selectors[] = $selector;
                    }
                }
            }
        }
        $did_replace = false;
        if (!empty($main_selectors) && !empty($all_keys)) {
            foreach ($main_selectors as $selector) {
                $xp = multilang_css_to_xpath($selector);
                if ($xp) {
                    $elements = $xpath->query($xp);
                    if ($elements && $elements->length > 0) {
                        foreach ($elements as $el) {
                            // Recursively walk text nodes and regex replace keys
                            $walker = function($node) use (&$walker, $all_keys, $all_vals, &$did_replace) {
                                if ($node->nodeType === XML_TEXT_NODE) {
                                    $orig = $node->nodeValue;
                                    $replaced = $orig;
                                    foreach ($all_keys as $i => $k) {
                                        $replaced = preg_replace('/' . preg_quote($k, '/') . '/u', $all_vals[$i], $replaced, -1, $count);
                                        if ($count > 0) $did_replace = true;
                                    }
                                    if ($replaced !== $orig) {
                                        $node->nodeValue = $replaced;
                                    }
                                } elseif ($node->hasChildNodes()) {
                                    foreach ($node->childNodes as $child) {
                                        $walker($child);
                                    }
                                }
                            };
                            $walker($el);
                        }
                    }
                }
            }
        }
        if ($did_replace) {
            $result = $dom_fallback->saveHTML();
            $result = preg_replace('/^<!DOCTYPE.+?>/', '<!DOCTYPE html>', str_replace('<?xml encoding="UTF-8">', '', $result));
            if (!is_string($result) || strlen(trim($result)) === 0 || strpos($result, '<') === false) {
                return $html;
            }
            return $result;
        }
        return $html;
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
    $force_footer_translation = true;
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

    $footer_translated = false;
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
            // Check if footer is present and translated
            if ($force_footer_translation && strpos($result_html, 'class="multilang-wrapper"') === false && strpos($result_html, '<footer') !== false) {
                // Fallback: process footer element directly
                $dom = new DOMDocument();
                $dom->encoding = 'UTF-8';
                libxml_use_internal_errors(true);
                $dom->loadHTML('<?xml encoding="UTF-8">' . $result_html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                libxml_clear_errors();
                $footer = $dom->getElementsByTagName('footer')->item(0);
                if ($footer) {
                    $current_lang = multilang_get_current_language();
                    $default_lang = get_multilang_default_language();
                    $current_lang_translations = multilang_get_language_data($current_lang);
                    $default_lang_translations = multilang_get_language_data($default_lang);
                    multilang_wrap_text_nodes($footer, $current_lang_translations, $default_lang_translations, $current_lang, $default_lang);
                    $result_html = $dom->saveHTML();
                    $footer_translated = true;
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