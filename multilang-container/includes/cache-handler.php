<?php
// File-based caching for translated content

if (!defined('ABSPATH')) {
    exit;
}

function multilang_is_cache_debug_logging_enabled() {
    $json_options = multilang_get_cache_options();
    if (isset($json_options['cache_debug_logging'])) {
        return (bool) $json_options['cache_debug_logging'];
    }
    return false;
}




/**
 * Fragment-based JSON caching for selector fragments
 */
function multilang_get_fragment_cache_file($cache_key) {
    $cache_dir = multilang_get_cache_dir();
    $safe_key = sanitize_file_name($cache_key);
    return $cache_dir . $safe_key . '.fragments.json';
}

function multilang_set_fragment_cache($cache_key, $selector, $fragment) {
    // Always use the exact selector from structure data
    $file = multilang_get_fragment_cache_file($cache_key);
    $data = array();
    if (file_exists($file)) {
        $content = file_get_contents($file);
        $data = json_decode($content, true) ?: array();
    }
    // Cache the full HTML of the selected element, with all language spans present
    $data[$selector] = $fragment;
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return true;
}

function multilang_get_fragment_cache($cache_key, $selector) {
    $file = multilang_get_fragment_cache_file($cache_key);
    if (!file_exists($file)) {
        return false;
    }
    $content = file_get_contents($file);
    $data = json_decode($content, true);
    if (!is_array($data) || !isset($data[$selector])) {
        return false;
    }
    return $data[$selector];
}

function multilang_delete_fragment_cache($cache_key, $selector) {
    $file = multilang_get_fragment_cache_file($cache_key);
    if (!file_exists($file)) {
        return false;
    }
    $content = file_get_contents($file);
    $data = json_decode($content, true);
    if (!is_array($data) || !isset($data[$selector])) {
        return false;
    }
    unset($data[$selector]);
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return true;
}

/**
 * Save cache options to JSON file
 */
function multilang_save_cache_options($options) {
    $upload_dir = wp_upload_dir();
    $options_file = trailingslashit($upload_dir['basedir']) . 'multilang/cache-options.json';
    file_put_contents($options_file, json_encode($options, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function multilang_get_cache_dir() {
    $upload_dir = wp_upload_dir();
    $cache_dir = trailingslashit($upload_dir['basedir']) . 'multilang/cache/';
    
    if (!is_dir($cache_dir)) {
        wp_mkdir_p($cache_dir);
        
        $htaccess_file = $cache_dir . '.htaccess';
        if (!file_exists($htaccess_file)) {
            file_put_contents($htaccess_file, "# Protect cache directory\n<Files \"*.cache\">\n    Require all denied\n</Files>");
        }
        
        $index_file = $cache_dir . 'index.php';
        if (!file_exists($index_file)) {
            file_put_contents($index_file, "<?php\n// Silence is golden.\n");
        }
    }
    
    return $cache_dir;
}

function multilang_get_cache_file_path($cache_key) {
    $cache_dir = multilang_get_cache_dir();
    $safe_key = sanitize_file_name($cache_key);
    return $cache_dir . $safe_key . '.cache';
}

function multilang_get_cache($cache_key, $expiration = 3600) {
    $cache_file = multilang_get_cache_file_path($cache_key);
    
    if (!file_exists($cache_file)) {
        return false;
    }
    
    if ($expiration > 0) {
        $file_time = filemtime($cache_file);
        if ((time() - $file_time) > $expiration) {
            @unlink($cache_file);
            return false;
        }
    }
    
    $cached_content = file_get_contents($cache_file);
    if ($cached_content === false) {
        return false;
    }
    
    $data = @unserialize($cached_content);
    return $data !== false ? $data : false;
}

function multilang_set_cache($cache_key, $data) {
    // Optionally cache for logged-in users
    $json_options = array();
    if (function_exists('multilang_get_options')) {
        $json_options = multilang_get_options();
    }
    $cache_logged_in = isset($json_options['cache_logged_in']) ? intval($json_options['cache_logged_in']) : 0;
    if (function_exists('is_user_logged_in') && is_user_logged_in()) {
        if (intval($cache_logged_in) !== 1) {
            return false;
        } else if (is_string($data)) {
            // Remove admin bar markup and related scripts from HTML when caching for logged-in users
            // Remove <div id="wpadminbar"> ... </div>
            $data = preg_replace('/<div id="wpadminbar"[\s\S]*?<\/div>/i', '', $data);
            // Remove admin bar <script> blocks
            $data = preg_replace('/<script[^>]*>[^<]*customize-support[^<]*<\/script>/i', '', $data);
            // Remove all <li> elements with id starting with wp-admin-bar-
            $data = preg_replace('/<li[^>]+id="wp-admin-bar-[^>]+>[\s\S]*?<\/li>/i', '', $data);
        }
    }
    $cache_file = multilang_get_cache_file_path($cache_key);
    $serialized = serialize($data);
    $result = file_put_contents($cache_file, $serialized, LOCK_EX);
    return $result !== false;
}

function multilang_delete_cache($cache_key) {
    $cache_file = multilang_get_cache_file_path($cache_key);
    
    if (file_exists($cache_file)) {
        return @unlink($cache_file);
    }
    
    return true;
}

function multilang_clear_all_cache() {
    $cache_dir = multilang_get_cache_dir();
    $deleted_count = 0;
    
    if (multilang_is_cache_debug_logging_enabled()) {
        error_log('[Multilang Cache] Cache cleared');
    }
    
    if (!is_dir($cache_dir)) {
        return 0;
    }
    
    $patterns = [
        $cache_dir . '*.cache',
        $cache_dir . '*.fragments.json'
    ];
    foreach ($patterns as $pattern) {
        $files = glob($pattern);
        if ($files === false) {
            continue;
        }
        foreach ($files as $file) {
            if (is_file($file)) {
                if (@unlink($file)) {
                    $deleted_count++;
                }
            }
        }
    }
    
    do_action('multilang_cache_cleared', $deleted_count);
    
    return $deleted_count;
}

function multilang_get_cache_info() {
    $cache_dir = multilang_get_cache_dir();
    $info = array(
        'count' => 0,
        'size' => 0,
        'size_formatted' => '0 B'
    );
    
    if (!is_dir($cache_dir)) {
        return $info;
    }
    
    $files = glob($cache_dir . '*.cache');
    if ($files === false) {
        return $info;
    }
    
    foreach ($files as $file) {
        if (is_file($file)) {
            $info['count']++;
            $info['size'] += filesize($file);
        }
    }
    
    $units = array('B', 'KB', 'MB', 'GB');
    $size = $info['size'];
    $unit_index = 0;
    
    while ($size >= 1024 && $unit_index < count($units) - 1) {
        $size /= 1024;
        $unit_index++;
    }
    
    $info['size_formatted'] = round($size, 2) . ' ' . $units[$unit_index];
    
    return $info;
}

function multilang_get_cached_inline_css() {
    $cache_key = 'inline_css_' . multilang_get_current_language();
    $cached = multilang_get_cache($cache_key, 0);
    
    if ($cached !== false) {
        return $cached;
    }
    
    // Generate CSS
    $available_langs = get_multilang_available_languages();
    $default_lang = get_multilang_default_language();
    $current_lang = multilang_get_current_language();
    
    $css = '
    /* Show current language when data-lang is set */
    html[data-lang="' . esc_attr($current_lang) . '"] .translate .lang-' . esc_attr($current_lang) . ',
    html[data-lang="' . esc_attr($current_lang) . '"] .wp-block-multilang-container .lang-' . esc_attr($current_lang) . ' {
        visibility: visible !important;
    }';
    
    multilang_set_cache($cache_key, $css);
    
    return $css;
}

/**
 * Get cached inline JavaScript
 */
function multilang_get_cached_inline_js() {
    $cache_key = 'inline_js';
    $cached = multilang_get_cache($cache_key, 0);
    
    if ($cached !== false) {
        return $cached;
    }
    
    // Generate JavaScript
    $available_langs = get_multilang_available_languages();
    $default_lang = get_multilang_default_language();
    
    $js = '
    // Check for language preference and update CSS
    (function() {
        // Get cookie value
        function getCookie(name) {
            var value = "; " + document.cookie;
            var parts = value.split("; " + name + "=");
            if (parts.length == 2) return parts.pop().split(";").shift();
            return null;
        }
        
        // Get language: cookie > localStorage > default
        var cookieLang = getCookie("lang");
        var storageLang = localStorage.getItem("preferredLanguage");
        var savedLang = cookieLang || storageLang || "' . esc_js($default_lang) . '";
        var availableLangs = ' . json_encode($available_langs) . ';
        
        // Validate
        if (availableLangs.indexOf(savedLang) === -1) {
            savedLang = "' . esc_js($default_lang) . '";
        }
        
        // Save to localStorage and cookie
        localStorage.setItem("preferredLanguage", savedLang);
        document.cookie = "lang=" + savedLang + "; path=/; max-age=31536000; SameSite=Lax";
        
        // Update CSS rules
        var style = document.getElementById("multilang-immediate-css");
        if (style) {
            var newCSS = "/* Show selected language */ " +
                "html[data-lang=\"" + savedLang + "\"] .translate .lang-" + savedLang + ", html[data-lang=\"" + savedLang + "\"] .wp-block-multilang-container .lang-" + savedLang + " { visibility: visible !important; }";
            style.textContent = newCSS;
        }
        
        var html = document.documentElement;
        
        if (html) {
            html.setAttribute("lang", savedLang);
            html.setAttribute("data-lang", savedLang);
        }
        
        // Wait for body
        function setBodyAttrs() {
            var body = document.body;
            if (body) {
                body.setAttribute("lang", savedLang);
                body.setAttribute("data-lang", savedLang);
                
                // Remove existing lang- classes
                body.className = body.className.replace(/\\blang-[a-z]{2}\\b/g, "");
                body.className += " lang-" + savedLang;
            } else {
                setTimeout(setBodyAttrs, 1);
            }
        }
        setBodyAttrs();
        
        window.currentLanguage = savedLang;
    })();';
    
    multilang_set_cache($cache_key, $js);
    
    return $js;
}

/**
 * Get cached structure data
 */
function multilang_get_cached_structure_data() {
    $structure_file = get_structure_file_path();
    
    // Include file mtime in cache key for auto-invalidation
    $file_mtime = file_exists($structure_file) ? filemtime($structure_file) : 0;
    $cache_key = 'structure_data_' . $file_mtime;
    
    $cached = multilang_get_cache($cache_key, 0);
    
    if ($cached !== false) {
        return $cached;
    }
    
    $structure_data = array();
    if (file_exists($structure_file)) {
        $structure_content = file_get_contents($structure_file);
        $structure_data = json_decode($structure_content, true) ?: array();
    }
    
    multilang_set_cache($cache_key, $structure_data);
    
    return $structure_data;
}

/**
 * Get cached language data
 */
function multilang_get_cached_language_data($lang) {
    $lang_file = get_language_file_path($lang);
    
    // Include file mtime in cache key for auto-invalidation
    $file_mtime = file_exists($lang_file) ? filemtime($lang_file) : 0;
    $cache_key = 'lang_data_' . $lang . '_' . $file_mtime;
    
    $cached = multilang_get_cache($cache_key, 0);
    
    if ($cached !== false) {
        return $cached;
    }
    
    $lang_data = array();
    if (file_exists($lang_file)) {
        $lang_content = file_get_contents($lang_file);
        $lang_data = json_decode($lang_content, true) ?: array();
    }
    
    multilang_set_cache($cache_key, $lang_data);
    
    return $lang_data;
}

/**
 * Get cache key for current page
 * Returns false if page shouldn't be cached
 */
function multilang_get_page_cache_key() {
    global $post;
    
    // Don't cache AJAX requests unless explicitly enabled
    if (wp_doing_ajax() && !multilang_is_ajax_cache_enabled()) {
        return false;
    }
    
    if (is_singular() && $post && !empty($post->ID)) {
        $post_modified = get_post_modified_time('U', false, $post->ID);
        return 'page_' . $post->ID . '_' . $post_modified;
    }
    
    if (is_front_page() || is_home()) {
        $page_on_front = get_option('page_on_front');
        if ($page_on_front) {
            $post_modified = get_post_modified_time('U', false, $page_on_front);
            return 'home_' . $page_on_front . '_' . $post_modified;
        }
        // Blog home
        $latest_post = wp_get_recent_posts(array('numberposts' => 1));
        if (!empty($latest_post)) {
            $latest_time = strtotime($latest_post[0]['post_modified']);
            return 'blog_home_' . $latest_time;
        }
        return 'home_static';
    }
    
    if (is_category()) {
        $cat = get_queried_object();
        $latest = get_posts(array(
            'category' => $cat->term_id,
            'numberposts' => 1,
            'orderby' => 'modified'
        ));
        if (!empty($latest)) {
            $latest_time = strtotime($latest[0]->post_modified);
            return 'cat_' . $cat->term_id . '_' . $latest_time;
        }
        return 'cat_' . $cat->term_id;
    }
    
    if (is_tag()) {
        $tag = get_queried_object();
        $latest = get_posts(array(
            'tag' => $tag->slug,
            'numberposts' => 1,
            'orderby' => 'modified'
        ));
        if (!empty($latest)) {
            $latest_time = strtotime($latest[0]->post_modified);
            return 'tag_' . $tag->term_id . '_' . $latest_time;
        }
        return 'tag_' . $tag->term_id;
    }
    
    if (is_author()) {
        $author = get_queried_object();
        $latest = get_posts(array(
            'author' => $author->ID,
            'numberposts' => 1,
            'orderby' => 'modified'
        ));
        if (!empty($latest)) {
            $latest_time = strtotime($latest[0]->post_modified);
            return 'author_' . $author->ID . '_' . $latest_time;
        }
        return 'author_' . $author->ID;
    }
    
    if (is_date()) {
        $year = get_query_var('year');
        $month = get_query_var('monthnum');
        $day = get_query_var('day');
        return 'date_' . $year . '_' . $month . '_' . $day;
    }
    
    if (is_search()) {
        return false; // Don't cache search
    }
    
    if (is_404()) {
        return '404_page';
    }
    
    return false;
}

/**
 * Get cached page translation
 */

/**
 * Get cached fragment for a selector on a page
 */
function multilang_get_cached_fragment($post_id, $lang, $selector) {
    $cache_key = 'page_' . $post_id;
    return multilang_get_fragment_cache($cache_key, $selector);
}


// PAGE-LEVEL CACHE FUNCTIONS DEPRECATED
// Use fragment-based caching functions instead:
// multilang_set_fragment_cache($cache_key, $selector, $fragment)
// multilang_get_fragment_cache($cache_key, $selector)
// multilang_delete_fragment_cache($cache_key, $selector)

/**
 * Set cached page translation
 */

/**
 * Set cached fragment for a selector on a page
 */
function multilang_set_cached_fragment($post_id, $lang, $selector, $fragment) {
    $cache_key = 'page_' . $post_id;
    return multilang_set_fragment_cache($cache_key, $selector, $fragment);
}

/**
 * Clear cache for a specific post
 */
function multilang_clear_post_cache($post_id) {
    $cache_dir = multilang_get_cache_dir();
    $deleted_count = 0;
    
    if (!is_dir($cache_dir)) {
        return 0;
    }
    
    $patterns = [
        $cache_dir . 'page_' . $post_id . '_*.cache',
        $cache_dir . 'page_' . $post_id . '_*.fragments.json'
    ];
    foreach ($patterns as $pattern) {
        $files = glob($pattern);
        if ($files === false) {
            continue;
        }
        foreach ($files as $file) {
            if (is_file($file)) {
                if (@unlink($file)) {
                    $deleted_count++;
                }
            }
        }
    }
    
    if ($deleted_count > 0 && multilang_is_cache_debug_logging_enabled()) {
        error_log('[Multilang Cache] Cleared cache for post ' . $post_id . ' (' . $deleted_count . ' files)');
    }
    
    return $deleted_count;
}

/**
 * Clear cache when translations are updated
 */
function multilang_clear_cache_on_update() {
    multilang_clear_all_cache();
    multilang_clear_external_cache_all();
}

add_action('multilang_translations_updated', 'multilang_clear_cache_on_update');
add_action('multilang_structure_updated', 'multilang_clear_cache_on_update');
add_action('multilang_languages_updated', 'multilang_clear_cache_on_update');

/**
 * Clear cache when post is saved
 */
function multilang_clear_cache_on_post_save($post_id, $post, $update) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    
    if (wp_is_post_revision($post_id)) {
        return;
    }
    
    multilang_clear_post_cache($post_id);
    
    $categories = wp_get_post_categories($post_id);
    foreach ($categories as $cat_id) {
        multilang_clear_category_cache($cat_id);
    }
    
    $tags = wp_get_post_tags($post_id, array('fields' => 'ids'));
    foreach ($tags as $tag_id) {
        multilang_clear_tag_cache($tag_id);
    }
    
    multilang_clear_author_cache($post->post_author);
    multilang_clear_home_cache();
    multilang_clear_external_cache_for_post($post_id);
}
add_action('save_post', 'multilang_clear_cache_on_post_save', 10, 3);

/**
 * Clear cache for category archive
 */
function multilang_clear_category_cache($cat_id) {
    $cache_dir = multilang_get_cache_dir();
    $pattern = $cache_dir . 'cat_' . $cat_id . '_*.cache';
    $files = glob($pattern);
    
    if ($files !== false) {
        foreach ($files as $file) {
            @unlink($file);
        }
    }
}

/**
 * Clear cache for tag archive
 */
function multilang_clear_tag_cache($tag_id) {
    $cache_dir = multilang_get_cache_dir();
    $pattern = $cache_dir . 'tag_' . $tag_id . '_*.cache';
    $files = glob($pattern);
    
    if ($files !== false) {
        foreach ($files as $file) {
            @unlink($file);
        }
    }
}

/**
 * Clear cache for author archive
 */
function multilang_clear_author_cache($author_id) {
    $cache_dir = multilang_get_cache_dir();
    $pattern = $cache_dir . 'author_' . $author_id . '_*.cache';
    $files = glob($pattern);
    
    if ($files !== false) {
        foreach ($files as $file) {
            @unlink($file);
        }
    }
}

/**
 * Clear cache for home/blog page
 */
function multilang_clear_home_cache() {
    $cache_dir = multilang_get_cache_dir();
    $patterns = array(
        $cache_dir . 'home_*.cache',
        $cache_dir . 'blog_home_*.cache'
    );
    
    foreach ($patterns as $pattern) {
        $files = glob($pattern);
        if ($files !== false) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
    }
}

/**
 * Clear external cache for specific post
 */
function multilang_clear_external_cache_for_post($post_id) {
    if (class_exists('WpFastestCache')) {
        $wpfc = new WpFastestCache();
        if (method_exists($wpfc, 'singleDeleteCache')) {
            $wpfc->singleDeleteCache(false, $post_id);
        }
    }
    
    if (function_exists('w3tc_flush_post')) {
        w3tc_flush_post($post_id);
    }
    
    if (function_exists('rocket_clean_post')) {
        rocket_clean_post($post_id);
    }
    
    if (class_exists('LiteSpeed\Purge')) {
        do_action('litespeed_purge_post', $post_id);
    }
    
    if (function_exists('wp_cache_post_change')) {
        wp_cache_post_change($post_id);
    }
    
    if (class_exists('Cache_Enabler')) {
        if (method_exists('Cache_Enabler', 'clear_page_cache_by_post_id')) {
            Cache_Enabler::clear_page_cache_by_post_id($post_id);
        }
    }
    
    // SG Optimizer
    if (function_exists('sg_cachepress_purge_cache')) {
        $url = get_permalink($post_id);
        if ($url) {
            sg_cachepress_purge_cache($url);
        }
    }
}

/**
 * Clear all external cache
 */
function multilang_clear_external_cache_all() {
    if (class_exists('WpFastestCache')) {
        $wpfc = new WpFastestCache();
        if (method_exists($wpfc, 'deleteCache')) {
            $wpfc->deleteCache(true);
        }
    }
    
    if (function_exists('w3tc_flush_all')) {
        w3tc_flush_all();
    }
    
    if (function_exists('rocket_clean_domain')) {
        rocket_clean_domain();
    }
    
    if (class_exists('LiteSpeed\Purge')) {
        do_action('litespeed_purge_all');
    }
    
    if (function_exists('wp_cache_clear_cache')) {
        wp_cache_clear_cache();
    }
    
    if (class_exists('Cache_Enabler')) {
        if (method_exists('Cache_Enabler', 'clear_complete_cache')) {
            Cache_Enabler::clear_complete_cache();
        }
    }
    
    // SG Optimizer
    if (function_exists('sg_cachepress_purge_cache')) {
        sg_cachepress_purge_cache();
    }
}

/**
 * Clear cache when post is deleted
 */
function multilang_clear_cache_on_post_delete($post_id) {
    multilang_clear_post_cache($post_id);
}
add_action('before_delete_post', 'multilang_clear_cache_on_post_delete');

/**
 * AJAX handler to clear cache
 */
function multilang_ajax_clear_cache() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Permission denied.'));
        return;
    }
    
    $deleted_count = multilang_clear_all_cache();
    
    wp_send_json_success(array(
        'message' => sprintf(__('%d cache files cleared.', 'multilang-container'), $deleted_count),
        'deleted_count' => $deleted_count
    ));
}
add_action('wp_ajax_multilang_clear_cache', 'multilang_ajax_clear_cache');

/**
 * AJAX handler to get cache info
 */
function multilang_ajax_get_cache_info() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Permission denied.'));
        return;
    }
    
    $info = multilang_get_cache_info();
    
    wp_send_json_success($info);
}
add_action('wp_ajax_multilang_get_cache_info', 'multilang_ajax_get_cache_info');

/**
 * Filter fragment to show only current language spans
 */
function multilang_filter_fragment_to_current_language($fragment_html) {
    $current_lang = multilang_get_current_language();
    $available_langs = get_multilang_available_languages();
    
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $fragment_html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    
    $xpath = new DOMXPath($dom);
    $span_nodes = $xpath->query('//span[contains(@class, "translate") and contains(@class, "lang-")]');
    
    foreach ($span_nodes as $span) {
        $span_classes = $span->getAttribute('class');
        $lang_class = null;
        foreach ($available_langs as $lang) {
            if (strpos($span_classes, 'lang-' . $lang) !== false) {
                $lang_class = $lang;
                break;
            }
        }
        if ($lang_class) {
            // $visibility = ($lang_class === $current_lang) ? 'visible' : 'hidden';
            // $span->setAttribute('style', 'visibility: ' . $visibility . ' !important;');
        }
    }
    
    $result = $dom->saveHTML();
    $result = preg_replace('/^<!DOCTYPE.+?>/', '', $result);
    $result = preg_replace('/<html[^>]*>/', '', $result);
    $result = preg_replace('/<\/html>/', '', $result);
    $result = preg_replace('/<body[^>]*>/', '', $result);
    $result = preg_replace('/<\/body>/', '', $result);
    $result = trim($result);
    
    return $result;
}


/**
 * Get excerpt cache settings from JSON file
 */
function multilang_get_cache_excerpt_settings() {
    $options = multilang_get_cache_options();
    return isset($options['excerpt_settings']) ? $options['excerpt_settings'] : array();
}

/**
 * Save excerpt cache settings to JSON file
 */
function multilang_save_cache_excerpt_settings($excerpt_settings) {
    $options = multilang_get_cache_options();
    $options['excerpt_settings'] = $excerpt_settings;
    multilang_save_cache_options($options);
}

// Ensure admin bar is shown for logged-in users even if page is cached
add_action('wp_footer', function() {
    if (function_exists('is_user_logged_in') && is_user_logged_in() && function_exists('wp_admin_bar_render')) {
        wp_admin_bar_render();
    }
});

add_action('wp_body_open', function() {
    if (function_exists('is_user_logged_in') && is_user_logged_in() && function_exists('wp_admin_bar_render')) {
        wp_admin_bar_render();
    }
});

// Inject cached fragment into DOMDocument
function multilang_filter_fragment_to_current_language_regex($fragment_html, $current_lang) {
    // Remove content for non-current language spans, but keep attributes and add data-translation
    return preg_replace_callback(
        '/(<span\s+class="[^"]*translate[^"]*lang-([a-z]{2})[^"]*"[^>]*>)(.*?)(<\/span>)/is',
        function($matches) use ($current_lang) {
            $opening_tag = $matches[1];
            $element_lang = $matches[2];
            $span_content = $matches[3];
            $closing_tag = $matches[4];
            if ($element_lang !== $current_lang) {
                // Preserve data-translation attribute with original content, but make span empty
                $encoded_content = htmlspecialchars($span_content, ENT_QUOTES, 'UTF-8');
                // If data-translation already exists, don't duplicate
                if (strpos($opening_tag, 'data-translation=') === false) {
                    $opening_tag = preg_replace('/>$/', ' data-translation="' . $encoded_content . '">', $opening_tag);
                }
                // Return empty span with data-translation
                return $opening_tag . $closing_tag;
            } else {
                // Keep content for current language
                return $opening_tag . $span_content . $closing_tag;
            }
        },
        $fragment_html
    );
}

/**
 * Retrieve cached fragments for a page based on structure data
 */
function multilang_retrieve_cached_fragments($cache_page_key, $structure_data) {
    $fragments = array();
    $all_found = true;
    foreach ($structure_data as $section => $config) {
        if (!isset($config['_selectors']) || !is_array($config['_selectors'])) continue;
        foreach ($config['_selectors'] as $selector) {
            $normalized_selector = trim($selector);
            // Try to get fragments for this selector (may have multiple instances)
            $index = 0;
            $selector_fragments = array();
            while (true) {
                $fragment_key = $normalized_selector . '|' . $index;
                $fragment_html = multilang_get_cached_fragment($cache_page_key, null, $fragment_key);
                if ($fragment_html === false) {
                    break; // No more fragments for this selector
                }
                $selector_fragments[] = $fragment_html;
                $index++;
            }
            
            if (!empty($selector_fragments)) {
                $fragments[$normalized_selector] = $selector_fragments;
            } else {
                $all_found = false;
            }
        }
    }
    return array($fragments, $all_found);
}

/**
 * Inject cached fragments into HTML
 */
function multilang_inject_fragments_into_html($html, $fragments) {
    if (empty($fragments) || !is_array($fragments)) {
        return $html;
    }
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);
    $any_injected = false;
        foreach ($fragments as $selector => $selector_fragments) {
            if (function_exists('multilang_filter_fragment_to_current_language')) {
                $selector_fragments = array_map('multilang_filter_fragment_to_current_language', $selector_fragments);
            }
            // Use regex for all selectors in the array
            $tag = strtolower($selector);
            $regex = '';
            if ($tag === 'body' || $tag === 'html') {
                // Never inject body or html
                continue;
            } else if (preg_match('/^[a-z0-9_-]+$/', $tag)) {
                // Tag selector (e.g., footer, form, header)
                $regex = '/<' . $tag . '\b[^>]*>(.*?)<\/' . $tag . '>/is';
            } else if (strpos($tag, '.') === 0) {
                // Class selector (e.g., .myclass)
                $class = preg_quote(substr($tag, 1), '/');
                $regex = '/<([a-z0-9]+)[^>]*class=["\'][^>]*\b' . $class . '\b[^>]*["\'][^>]*>(.*?)<\/\1>/is';
            } else if (strpos($tag, '#') === 0) {
                // ID selector (e.g., #myid)
                $id = preg_quote(substr($tag, 1), '/');
                $regex = '/<([a-z0-9]+)[^>]*id=["\']' . $id . '["\'][^>]*>(.*?)<\/\1>/is';
            }
            if ($regex && preg_match($regex, $html)) {
                foreach ($selector_fragments as $fragment_html) {
                    $fragment_html = preg_replace('/<\?xml[^>]+\?>/i', '', $fragment_html);
                    $frag_len = strlen(trim($fragment_html));
                    if ($frag_len === 0) continue;
                    $old_html = $html;
                    $html = preg_replace($regex, $fragment_html, $html, 1, $count);
                    if ($count > 0) {
                        $any_injected = true;
                        error_log('[Multilang Debug] Regex injection for selector ' . $selector . ' result (first 1000 chars): ' . substr($html, 0, 1000));
                        break;
                    }
                }
                continue;
            }
            // Fallback to DOMDocument for other selectors
            $xp = multilang_css_to_xpath($selector);
            if ($xp) {
                $nodes = $xpath->query($xp);
                if ($nodes->length === 0) {
                    continue;
                }
                $fragment_index = 0;
                foreach ($nodes as $node) {
                    if (isset($selector_fragments[$fragment_index])) {
                        $fragment_html = $selector_fragments[$fragment_index];
                        $frag_len = strlen(trim($fragment_html));
                        if ($frag_len === 0) {
                            $fragment_index++;
                            continue;
                        }
                        $fragment_dom = new DOMDocument();
                        libxml_use_internal_errors(true);
                        $fragment_dom->loadHTML('<?xml encoding="UTF-8">' . $fragment_html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                        libxml_clear_errors();
                        $fragment_root = $fragment_dom->documentElement;
                        if ($fragment_root) {
                            $imported_fragment = $dom->importNode($fragment_root, true);
                            $node->parentNode->replaceChild($imported_fragment, $node);
                            $any_injected = true;
                        }
                    }
                    $fragment_index++;
                }
            }
        }
    if (!$any_injected) {
        return $html;
    }
    $result_html = $dom->saveHTML();
    return $result_html;
}

/**
 * Cache fragments from processed HTML
 */
function multilang_cache_fragments_from_html($processed_html, $cache_page_key, $structure_data) {
    // Extract and cache fragments from the processed HTML using regex for reliability
    foreach ($structure_data as $section => $config) {
        if (!isset($config['_selectors']) || !is_array($config['_selectors'])) continue;
        foreach ($config['_selectors'] as $selector) {
            $normalized_selector = trim($selector);
            $tag = strtolower($normalized_selector);
            $regex = '';
            if ($tag === 'body' || $tag === 'html') {
                // Never cache body or html
                continue;
            } else if (preg_match('/^[a-z0-9_-]+$/', $tag)) {
                // Tag selector (e.g., footer, form, header)
                $regex = '/<' . $tag . '\b[^>]*>(.*?)<\/' . $tag . '>/is';
            } else if (strpos($tag, '.') === 0) {
                // Class selector (e.g., .myclass)
                $class = preg_quote(substr($tag, 1), '/');
                $regex = '/<([a-z0-9]+)[^>]*class=["\'][^>]*\b' . $class . '\b[^>]*["\'][^>]*>(.*?)<\/\1>/is';
            } else if (strpos($tag, '#') === 0) {
                // ID selector (e.g., #myid)
                $id = preg_quote(substr($tag, 1), '/');
                $regex = '/<([a-z0-9]+)[^>]*id=["\']' . $id . '["\'][^>]*>(.*?)<\/\1>/is';
            }
            $matches = array();
            $found = false;
            if ($regex && preg_match_all($regex, $processed_html, $matches)) {
                $found = true;
                foreach ($matches[0] as $index => $fragment_html) {
                    // Add multilang-cached class if not present
                    if (preg_match('/class=["\']([^"\']*)["\']/', $fragment_html, $class_match)) {
                        if (strpos($class_match[1], 'multilang-cached') === false) {
                            $fragment_html = preg_replace('/class=["\']([^"\']*)["\']/', 'class="' . trim($class_match[1] . ' multilang-cached') . '"', $fragment_html, 1);
                        }
                    } else {
                        // Add class if missing
                        $fragment_html = preg_replace('/^<([a-z0-9]+)/i', '<$1 class="multilang-cached"', $fragment_html, 1);
                    }
                    multilang_set_cached_fragment($cache_page_key, null, $normalized_selector . '|' . $index, $fragment_html);
                }
                if (function_exists('multilang_is_cache_debug_logging_enabled') && multilang_is_cache_debug_logging_enabled()) {
                    error_log('[Multilang Cache] Regex fragment extraction for selector ' . $normalized_selector . ' found ' . count($matches[0]) . ' matches.');
                }
            }
            // Fallback to DOMDocument/XPath if regex fails
            if (!$found) {
                $processed_dom = new DOMDocument();
                libxml_use_internal_errors(true);
                $processed_dom->loadHTML('<?xml encoding="UTF-8">' . $processed_html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                libxml_clear_errors();
                $processed_xpath = new DOMXPath($processed_dom);
                $xp = multilang_css_to_xpath($normalized_selector);
                if ($xp) {
                    $nodes = $processed_xpath->query($xp);
                    $index = 0;
                    foreach ($nodes as $node) {
                        $existing_class = $node->getAttribute('class');
                        if (strpos($existing_class, 'multilang-cached') === false) {
                            $new_class = trim($existing_class . ' multilang-cached');
                            $node->setAttribute('class', $new_class);
                        }
                        $fragment_html = $processed_dom->saveHTML($node);
                        multilang_set_cached_fragment($cache_page_key, null, $normalized_selector . '|' . $index, $fragment_html);
                        $index++;
                    }
                    if (function_exists('multilang_is_cache_debug_logging_enabled') && multilang_is_cache_debug_logging_enabled()) {
                        error_log('[Multilang Cache] DOM fallback fragment extraction for selector ' . $normalized_selector . ' found ' . $index . ' matches.');
                    }
                }
            }
        }
    }
}