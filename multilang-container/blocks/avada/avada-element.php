<?php
/**
 * Multilang Container - Clean Avada Element
 * 
 * Simple, working Fusion Builder element for multilingual content
 */



// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Simple multilang container shortcode
 * Uses Gutenberg-style approach: parse existing content and rebuild with auto-fill
 */
function multilang_avada_shortcode($atts) {

    $available_languages = function_exists('get_multilang_available_languages') ? 
        get_multilang_available_languages() : array('en');
    
    if (!is_array($available_languages) || empty($available_languages)) {
        $available_languages = array('en');
    }
    

    $default_language = function_exists('get_multilang_default_language') ? 
        get_multilang_default_language() : $available_languages[0];
    
    // Setup defaults and merge with attributes
    $atts = shortcode_atts(array(
        'css_class' => '', 
        'unique_id' => '',
        'content' => ''
    ), $atts);
    
    // Deobfuscate and process content
    $content = !empty($atts['content']) ? do_shortcode(deobfuscate($atts['content'])) : '';
    
    // AGGRESSIVELY REMOVE ALL MARKER COLUMNS AND TEXT
    if (!empty($content)) {

        $content = preg_replace('/\[fusion_text[^\]]*\].*?multilang_marker_.*?\[\/fusion_text\]/is', '', $content);
        

        $content = preg_replace('/\[fusion_builder_column_inner[^\]]*multilang_marker[^\]]*\].*?\[\/fusion_builder_column_inner\]/is', '', $content);
        

        // $content = preg_replace('/multilang_marker_[a-zA-Z0-9_]+/', '', $content);
        

        // $content = preg_replace('/<div[^>]*multilang_marker[^>]*>.*?<\/div>/is', '', $content);
        

        //$content = preg_replace('/<div[^>]*fusion-builder-column-inner[^>]*multilang_marker[^>]*>.*?<\/div>/is', '', $content);
        
        // Clean up any double spaces or empty lines
        $content = preg_replace('/\s+/', ' ', $content);
        $content = trim($content);
    }
    

    $language_blocks = array();
    $default_content = '';
    
    if (!empty($content)) {
        // Use DOMDocument to parse
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        
        $xpath = new DOMXPath($dom);
        
        // FIND THE FUSION-BUILDER-ROW-INNER THAT CONTAINS THE MULTILANG_MARKER
        $marker_elements = $xpath->query('//*[contains(@class, "multilang_marker")]');
        
        $lang_divs = null;
        
        if ($marker_elements->length > 0) {

            $marker = $marker_elements->item(0);
            
            // Find the nearest parent fusion-builder-row-inner
            $row_inner = $marker;
            $depth = 0;
            while ($row_inner && !preg_match('/fusion-builder-row-inner|fusion_builder_row_inner/', $row_inner->getAttribute('class'))) {
                $row_inner = $row_inner->parentNode;
                $depth++;
                if ($depth > 10) break; // Safety
            }
            
            if ($row_inner) {
                // Find all language columns in this row_inner (direct children with lang- class)
                $lang_divs = $xpath->query('.//*[contains(@class, "lang-")]', $row_inner);
            }
        }
        
        // Fallback: try old method
        if (!$lang_divs || $lang_divs->length === 0) {
            $wrapper = $xpath->query('//*[contains(@class, "multilang-wrapper")]')->item(0);
            $lang_divs = $wrapper ? 
                $xpath->query('./div[contains(@class, "translate") and contains(@class, "lang-")]', $wrapper) :
                $xpath->query('//div[contains(@class, "translate") and contains(@class, "lang-")]');
        }
        

        foreach ($lang_divs as $div) {
            // Skip marker columns
            if (strpos($div->getAttribute('class'), 'multilang_marker') !== false) {
                continue;
            }
            
            if (preg_match('/lang-([a-z]{2})/i', $div->getAttribute('class'), $matches)) {
                $lang = $matches[1];
                

                $inner_html = '';
                foreach ($div->childNodes as $child) {
                    $inner_html .= $dom->saveHTML($child);
                }
                
                $inner_html = trim($inner_html);
                
                // Strip ALL nested translate divs from this content
                while (preg_match('/<div class="translate lang-[a-z]{2}"[^>]*>/i', $inner_html)) {
                    $inner_html = preg_replace('/<div class="translate lang-[a-z]{2}"[^>]*>.*?<\/div>/is', '', $inner_html);
                }
                
                // Also remove any marker text that might be left
                $inner_html = preg_replace('/multilang_marker_[a-z0-9_]+/i', '', $inner_html);
                
                $language_blocks[$lang] = $inner_html;
                
                // Store default language content for fallback (check once)
                if (!$default_content && $lang === $default_language && strip_tags($inner_html)) {
                    $default_content = $inner_html;
                }
            }
        }
        
        // If no default content, use first non-empty language
        if (!$default_content) {
            foreach ($language_blocks as $html_content) {
                if (strip_tags($html_content)) {
                    $default_content = $html_content;
                    break;
                }
            }
        }
    }
    

    $lang_divs = array();
    foreach ($available_languages as $lang) {
        $content_to_use = '';
        
        if (isset($language_blocks[$lang])) {

            $text_content = trim(strip_tags($language_blocks[$lang]));
            if (empty($text_content) && !empty($default_content)) {
                // Use fallback content
                $content_to_use = $default_content;
            } else {
                // Use existing content
                $content_to_use = $language_blocks[$lang];
            }
        } else if (!empty($default_content)) {

            $content_to_use = $default_content;
        }
        
        $lang_divs[] = '<div class="translate lang-' . esc_attr($lang) . '">' . $content_to_use . '</div>';
    }
    

    $css_class = $atts['css_class'] ? ' ' . sanitize_html_class($atts['css_class']) : '';
    $unique_id = $atts['unique_id'] ?: uniqid('ml_', true);
    
    return '<div id="' . esc_attr($unique_id) . '" class="multilang-container' . $css_class . 
           '"><div class="multilang-wrapper">' . implode('', $lang_divs) . '</div></div>';
}

// Register shortcode
add_shortcode('multilang_avada', 'multilang_avada_shortcode');

/**
 * Filter the content to replace fusion-builder-row-inner with multilang containers
 */
function multilang_filter_content($content) {
    // Only process if we have content
    if (empty($content)) {
        return $content;
    }
    

    $available_languages = function_exists('get_multilang_available_languages') ? 
        get_multilang_available_languages() : array('en');
    
    if (!is_array($available_languages) || empty($available_languages)) {
        $available_languages = array('en');
    }
    
    $default_language = function_exists('get_multilang_default_language') ? 
        get_multilang_default_language() : $available_languages[0];
    
    // Use DOMDocument to parse
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    
    $xpath = new DOMXPath($dom);
    
    // Find all elements with multilang_marker class
    $marker_elements = $xpath->query('//*[contains(@class, "multilang_marker")]');
    
    if ($marker_elements->length > 0) {
        foreach ($marker_elements as $marker) {
            // Find the parent fusion-builder-row-inner
            $row_inner = $marker;
            while ($row_inner && !preg_match('/fusion-builder-row-inner|fusion_builder_row_inner/', $row_inner->getAttribute('class'))) {
                $row_inner = $row_inner->parentNode;
            }
            
            if ($row_inner) {

                $language_blocks = array();
                $default_content = '';
                
                // Find all language columns
                $lang_columns = $xpath->query('.//*[contains(@class, "lang-")]', $row_inner);
                
                foreach ($lang_columns as $col) {
                    // Skip marker
                    if (strpos($col->getAttribute('class'), 'multilang_marker') !== false) {
                        continue;
                    }
                    

                    if (preg_match('/lang-([a-z]{2})/i', $col->getAttribute('class'), $matches)) {
                        $lang = $matches[1];
                        

                        $inner_html = '';
                        foreach ($col->childNodes as $child) {
                            $inner_html .= $dom->saveHTML($child);
                        }
                        
                        // DON'T clean up anything - preserve all content exactly as is
                        $inner_html = trim($inner_html);
                        
                        $language_blocks[$lang] = $inner_html;
                        
                        if (!$default_content && $lang === $default_language && strip_tags($inner_html)) {
                            $default_content = $inner_html;
                        }
                    }
                }
                

                $lang_divs = array();
                foreach ($available_languages as $lang) {
                    $content_to_use = '';
                    
                    if (isset($language_blocks[$lang])) {
                        $text_content = trim(strip_tags($language_blocks[$lang]));
                        $content_to_use = empty($text_content) && !empty($default_content) ? 
                            $default_content : $language_blocks[$lang];
                    } else if (!empty($default_content)) {
                        $content_to_use = $default_content;
                    }
                    
                    $lang_divs[] = '<div class="translate lang-' . esc_attr($lang) . '">' . $content_to_use . '</div>';
                }
                
                $unique_id = uniqid('ml_', true);
                $replacement_html = '<div id="' . esc_attr($unique_id) . '" class="multilang-container"><div class="multilang-wrapper">' . implode('', $lang_divs) . '</div></div>';
                

                $fragment = $dom->createDocumentFragment();
                $fragment->appendXML($replacement_html);
                
                // Replace row_inner with our clean container
                $row_inner->parentNode->replaceChild($fragment, $row_inner);
            }
        }
        

        $content = $dom->saveHTML();
    }
    
    return $content;
}
add_filter('the_content', 'multilang_filter_content', 999);

/**
 * Register Fusion Builder element
 */
function multilang_register_fusion_element() {
    if (!function_exists('fusion_builder_map')) {
        return;
    }


    $available_languages = function_exists('get_multilang_available_languages') ? 
        get_multilang_available_languages() : array('en', 'de');
        
    if (!is_array($available_languages) || empty($available_languages)) {
        $available_languages = array('en', 'de');
    }

    // Register the element
    $element_name = 'Multilang Container';
    
    fusion_builder_map(array(
        'name' => $element_name,
        'shortcode' => 'multilang_avada',
        'icon' => 'fusiona-flag',
        'category' => 'content',
        'params' => array(
            array(
                'type' => 'textarea',
                'heading' => 'Multilang Content',
                'param_name' => 'content',
                'value' => '',
                'description' => 'Automatically populated when you edit the multilang content.',
            ),
            array(
                'type' => 'textfield',
                'heading' => 'Unique ID',
                'param_name' => 'unique_id',
                'value' => uniqid('ml_', true),
                'description' => 'A unique identifier for this element instance.',
            )
        ),
    ));
    
    // Store element name globally for JavaScript
    global $multilang_element_name;
    $multilang_element_name = $element_name;
}

// Register element on Fusion Builder initialization
add_action('fusion_builder_before_init', 'multilang_register_fusion_element');


/**
 * Remove all [fusion_builder_container ... class="ml_mock_container" ...][/fusion_builder_container] blocks from a shortcode string
 * @param string $shortcode
 * @return string
 */
function remove_mock_container_shortcodes($shortcode) {
    return preg_replace('/\[fusion_builder_container[^\]]*class="ml_mock_container"[^\]]*\][\s\S]*?\[\/fusion_builder_container\]/i', '', $shortcode);
}

/**
 * Enqueue basic CSS and builder JS for multilang functionality
 */


function multilang_avada_enqueue_assets() {
    if (!function_exists('get_multilang_available_languages')) {
        return;
    }
    $available_languages = get_multilang_available_languages();
    if (!is_array($available_languages) || empty($available_languages)) {
        $available_languages = array('en');
    }
    $default_language = function_exists('get_multilang_default_language') ? get_multilang_default_language() : 'en';
    $css = '
    /* HIDE THE ENTIRE FUSION ROW-INNER THAT CONTAINS LANGUAGE COLUMNS */
    .fusion-builder-row-inner:has(.multilang_marker),
    .fusion_builder_row_inner:has(.multilang_marker) {
        display: none !important;
    }
    
    /* Fallback for browsers without :has() support */
    .fusion-builder-row-inner .multilang_marker,
    .fusion_builder_row_inner .multilang_marker {
        display: none !important;
    }
    
    .fusion-builder-row-inner .ml-lang-column,
    .fusion_builder_row_inner .ml-lang-column {
        display: none !important;
    }
    
    /* Hide marker columns on frontend */
    .multilang_marker,
    .fusion-builder-column.multilang_marker,
    .fusion-column.multilang_marker,
    div[class*="multilang_marker"],
    div[id*="multilang_marker"] { 
        display: none !important; 
        visibility: hidden !important;
        height: 0 !important;
        width: 0 !important;
        overflow: hidden !important;
    }
    
    /* Default: show default language, hide others */
    .multilang-container .translate { display: none; }
    .multilang-container .translate.lang-' . esc_attr($default_language) . ' { display: block; }
    /* Language switching via body classes */';
    foreach ($available_languages as $lang) {
        $css .= '
    body.lang-' . esc_attr($lang) . ' .multilang-container .translate { display: none; }
    body.lang-' . esc_attr($lang) . ' .multilang-container .translate.lang-' . esc_attr($lang) . ' { display: block; }';
    }
    wp_register_style('multilang-avada-inline', false);
    wp_enqueue_style('multilang-avada-inline');
    wp_add_inline_style('multilang-avada-inline', $css);

    wp_enqueue_style(
        'multilang-avada-css',
        plugins_url('avada-element.css', __FILE__),
        array(),
        filemtime(plugin_dir_path(__FILE__) . 'avada-element.css')
    );
    

    // Always enqueue builder JS in admin
    if (is_admin()) {

        wp_enqueue_style(
            'multilang-avada-builder-events-css',
            plugins_url('avada-events.css', __FILE__),
            array(),
            filemtime(plugin_dir_path(__FILE__) . 'avada-events.css')
        );

        wp_enqueue_script(
            'multilang-avada-builder-events',
            plugins_url('avada-events.js', __FILE__),
            array('jquery', 'jquery-ui-sortable'),
            null,
            true
        );

        wp_enqueue_script(
            'multilang-avada-builder',
            plugins_url('avada-element-builder.js', __FILE__),
            array('jquery', 'jquery-ui-sortable', 'multilang-avada-builder-events'),
            time(), // Use timestamp to bust cache
            true
        );
        

        global $multilang_element_name;
        $element_name = !empty($multilang_element_name) ? $multilang_element_name : 'Multilang Container';
        
        wp_localize_script(
            'multilang-avada-builder',
            'multilangAvadaData',
            array(
                'available_languages' => $available_languages,
                'default_language' => $default_language,
                'element_name' => $element_name,
                'plugin_url' => plugins_url('', dirname(dirname(__FILE__))) 
            )
        );
    }
}
add_action('wp_enqueue_scripts', 'multilang_avada_enqueue_assets');
add_action('admin_enqueue_scripts', 'multilang_avada_enqueue_assets');