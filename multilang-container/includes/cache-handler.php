<?php
// File-based caching for translated content

if (!defined('ABSPATH')) {
    exit;
}

function multilang_is_cache_debug_logging_enabled() {
    $options = get_option('multilang_container_cache_debug_logging');
    
    if ($options !== false) {
        return (bool) $options;
    }
    
    $json_options = multilang_get_options();
    if (isset($json_options['cache_debug_logging'])) {
        return (bool) $json_options['cache_debug_logging'];
    }
    
    return false;
}

function multilang_is_ajax_cache_enabled() {
    $options = get_option('multilang_container_cache_ajax_requests');
    
    if ($options !== false) {
        return (bool) $options;
    }
    
    $json_options = multilang_get_options();
    if (isset($json_options['cache_ajax_requests'])) {
        return (bool) $json_options['cache_ajax_requests'];
    }
    
    return false;
}

function multilang_is_page_excluded_from_cache() {
    $options = get_option('multilang_container_cache_exclude_pages');
    
    if ($options === false) {
        $json_options = multilang_get_options();
        $options = isset($json_options['cache_exclude_pages']) ? $json_options['cache_exclude_pages'] : '';
    }
    
    if (empty($options)) {
        return false;
    }
    
    // Get current page path
    $current_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    
    // Parse comma-separated list
    $excluded_pages = array_map('trim', explode(',', $options));
    
    foreach ($excluded_pages as $excluded) {
        if (empty($excluded)) {
            continue;
        }
        
        // Remove leading/trailing slashes for comparison
        $excluded = trim($excluded, '/');
        $current = trim($current_path, '/');
        
        // Check if current path matches or starts with excluded path
        if ($current === $excluded || strpos($current, $excluded) === 0) {
            return true;
        }
    }
    
    return false;
}

function multilang_is_cache_enabled() {
    $options = get_option('multilang_container_cache_enabled');
    
    if ($options !== false) {
        return (bool) $options;
    }
    
    $json_options = multilang_get_options();
    if (isset($json_options['cache_enabled'])) {
        return (bool) $json_options['cache_enabled'];
    }
    
    return true;
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
    
    $files = glob($cache_dir . '*.cache');
    if ($files === false) {
        return 0;
    }
    
    foreach ($files as $file) {
        if (is_file($file)) {
            if (@unlink($file)) {
                $deleted_count++;
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
    /* Hide all languages by default */
    .translate .lang-en,
    .translate .lang-de, 
    .translate .lang-fr,
    .translate .lang-es,
    .translate .lang-it,
    .translate .lang-pt,
    .translate .lang-nl,
    .translate .lang-pl,
    .translate .lang-ru,
    .translate .lang-zh,
    .translate .lang-ja,
    .translate .lang-ko,
    .wp-block-multilang-container .lang-en,
    .wp-block-multilang-container .lang-de,
    .wp-block-multilang-container .lang-fr,
    .wp-block-multilang-container .lang-es,
    .wp-block-multilang-container .lang-it,
    .wp-block-multilang-container .lang-pt,
    .wp-block-multilang-container .lang-nl,
    .wp-block-multilang-container .lang-pl,
    .wp-block-multilang-container .lang-ru,
    .wp-block-multilang-container .lang-zh,
    .wp-block-multilang-container .lang-ja,
    .wp-block-multilang-container .lang-ko { 
        display: none !important; 
    }
    
    /* Show current language */
    html[data-lang="' . esc_attr($current_lang) . '"] .translate .lang-' . esc_attr($current_lang) . ',
    html[data-lang="' . esc_attr($current_lang) . '"] .wp-block-multilang-container .lang-' . esc_attr($current_lang) . ' { 
        display: block !important; 
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
            var newCSS = "/* Hide all languages */ " +
                ".translate .lang-en, .translate .lang-de, .translate .lang-fr, .translate .lang-es, .translate .lang-it, .translate .lang-pt, .translate .lang-nl, .translate .lang-pl, .translate .lang-ru, .translate .lang-zh, .translate .lang-ja, .translate .lang-ko, " +
                ".wp-block-multilang-container .lang-en, .wp-block-multilang-container .lang-de, .wp-block-multilang-container .lang-fr, .wp-block-multilang-container .lang-es, .wp-block-multilang-container .lang-it, .wp-block-multilang-container .lang-pt, .wp-block-multilang-container .lang-nl, .wp-block-multilang-container .lang-pl, .wp-block-multilang-container .lang-ru, .wp-block-multilang-container .lang-zh, .wp-block-multilang-container .lang-ja, .wp-block-multilang-container .lang-ko " +
                "{ display: none !important; } " +
                "/* Show selected language */ " +
                "html[data-lang=\"" + savedLang + "\"] .translate .lang-" + savedLang + ", html[data-lang=\"" + savedLang + "\"] .wp-block-multilang-container .lang-" + savedLang + " { display: block !important; }";
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
    
    // Don't cache excluded pages
    if (multilang_is_page_excluded_from_cache()) {
        return false;
    }
    
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
function multilang_get_cached_page_translation($post_id, $lang) {
    $post_modified = get_post_modified_time('U', false, $post_id);
    $cache_key = 'page_' . $post_id . '_' . $lang . '_' . $post_modified;
    
    return multilang_get_cache($cache_key, 0);
}

/**
 * Get cached content for current page
 */
function multilang_get_cached_page_content($lang) {
    if (!multilang_is_cache_enabled()) {
        return false;
    }
    
    $cache_base_key = multilang_get_page_cache_key();
    
    if ($cache_base_key === false) {
        return false;
    }
    
    $cache_key = $cache_base_key . '_' . $lang;
    return multilang_get_cache($cache_key, 0);
}

/**
 * Set cached content for current page
 */
function multilang_set_cached_page_content($lang, $content) {
    if (!multilang_is_cache_enabled()) {
        return false;
    }
    
    // Never cache AJAX responses
    if (defined('DOING_AJAX') && DOING_AJAX) {
        return false;
    }
    
    if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
        return false;
    }
    
    $cache_base_key = multilang_get_page_cache_key();
    
    if ($cache_base_key === false) {
        return false;
    }
    
    $cache_key = $cache_base_key . '_' . $lang;
    return multilang_set_cache($cache_key, $content);
}

/**
 * Set cached page translation
 */
function multilang_set_cached_page_translation($post_id, $lang, $translated_content) {
    $post_modified = get_post_modified_time('U', false, $post_id);
    $cache_key = 'page_' . $post_id . '_' . $lang . '_' . $post_modified;
    
    return multilang_set_cache($cache_key, $translated_content);
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
    
    $pattern = $cache_dir . 'page_' . $post_id . '_*.cache';
    $files = glob($pattern);
    
    if ($files === false) {
        return 0;
    }
    
    foreach ($files as $file) {
        if (is_file($file)) {
            if (@unlink($file)) {
                $deleted_count++;
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
