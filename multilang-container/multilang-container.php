<?php
/*
Plugin Name: Multilang Container
Description: Adds a custom block for the FSE editor to display text in different languages.
Version: 1.0
Author: Gary Angelone Jr.
*/

if (!defined('ABSPATH')) {
    exit;
}

// Prevent WP Fastest Cache from caching AJAX - run as early as possible
if (defined('DOING_AJAX') && DOING_AJAX) {
    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }
    if (!defined('DONOTCACHEDB')) {
        define('DONOTCACHEDB', true);
    }
}


require_once plugin_dir_path(__FILE__) . 'includes/utilities.php';
require_once plugin_dir_path(__FILE__) . 'includes/cache-handler.php';
require_once plugin_dir_path(__FILE__) . 'includes/cache-folder-monitor.php';

//require_once plugin_dir_path(__FILE__) . 'includes/admin-bar-menu.php';

require_once plugin_dir_path(__FILE__) . 'includes/language-switcher.php';
require_once plugin_dir_path(__FILE__) . 'includes/assets-handler.php';
require_once plugin_dir_path(__FILE__) . 'includes/server-translation.php';
require_once plugin_dir_path(__FILE__) . 'includes/frontend-rendering.php';
require_once plugin_dir_path(__FILE__) . 'includes/editor-blocks.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin-interface.php';

require_once plugin_dir_path(__FILE__) . 'admin/tab-language-settings.php';
require_once plugin_dir_path(__FILE__) . 'admin/tab-options.php';
require_once plugin_dir_path(__FILE__) . 'admin/utilities-admin.php';
require_once plugin_dir_path(__FILE__) . 'includes/metaboxes.php';

require_once plugin_dir_path(__FILE__) . 'includes/title-manager.php';
require_once plugin_dir_path(__FILE__) . 'includes/manager-excerpt.php';
require_once plugin_dir_path(__FILE__) . 'includes/manager-seo.php';
require_once plugin_dir_path(__FILE__) . 'includes/multilang-hide-filter.php';

// Don't cache AJAX unless the user opts in
// Run at priority 999 to ensure it runs AFTER WP Fastest Cache (which runs at priority 10)
add_action('init', function() {
    if (wp_doing_ajax()) {
        // if (!function_exists('multilang_is_ajax_cache_enabled')) {
            // require_once plugin_dir_path(__FILE__) . 'includes/cache-handler.php';
        //}
        
        
        if (!multilang_is_ajax_cache_enabled()) {
            // error_log('[Multilang] AJAX detected - preventing cache for action: ' . ($_REQUEST['action'] ?? 'unknown'));
            
            if (!defined('DONOTCACHEPAGE')) {
                define('DONOTCACHEPAGE', true);
            }
            if (!defined('DONOTCACHEDB')) {
                define('DONOTCACHEDB', true);
            }
            if (!defined('DONOTMINIFY')) {
                define('DONOTMINIFY', true);
            }
            if (!defined('DONOTCDN')) {
                define('DONOTCDN', true);
            }
            if (!defined('DONOTCACHEOBJECT')) {
                define('DONOTCACHEOBJECT', true);
            }
            
            add_action('send_headers', function() {
                if (wp_doing_ajax()) {
                    // error_log('[Multilang] Sending no-cache headers for AJAX');
                    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0', true);
                    header('Pragma: no-cache', true);
                    header('Expires: 0', true);
                    header('X-Accel-Expires: 0', true);
                }
            }, 999);
            
            // Fix for "load more" showing duplicate posts
            add_action('wp_ajax_get_fusion_blog', function() {
                // error_log('[Multilang] wp_ajax_get_fusion_blog triggered - setting nocache_headers');
                nocache_headers();
                header('X-Multilang-Time: ' . time(), true);
            }, 0);
            add_action('wp_ajax_nopriv_get_fusion_blog', function() {
                // error_log('[Multilang] wp_ajax_nopriv_get_fusion_blog triggered - setting nocache_headers');
                nocache_headers();
                header('X-Multilang-Time: ' . time(), true);
            }, 0);
        }
    }
}, 999);

// Exclude AJAX from WP Fastest Cache - run early to catch it before WPFC processes
add_filter('wpfc_exclude_current_page', function($exclude) {
    if (wp_doing_ajax()) {
        return true;
    }
    return $exclude;
}, 1);

// Tell WP Fastest Cache to exclude specific AJAX actions
add_filter('wpfc_toolbar_exclude_ajax', function($actions) {
    $actions[] = 'get_fusion_blog';
    return $actions;
}, 1, 1);

add_action('wp_ajax_multilang_save_languages_json', function() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied.']);
    }
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!isset($data['settings']['languages']) || !isset($data['settings']['default'])) {
        wp_send_json_error(['message' => 'Invalid data.']);
    }
    require_once plugin_dir_path(__FILE__) . 'includes/utilities.php';
    $upload_dir = multilang_get_uploads_dir();
    $file = trailingslashit($upload_dir) . 'languages.json';
    $json = json_encode([
        'languages' => $data['settings']['languages'],
        'default' => $data['settings']['default']
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if (file_put_contents($file, $json) === false) {
        wp_send_json_error(['message' => 'Failed to write file.']);
    }
    wp_send_json_success(['message' => 'Saved.', 'file' => $file]);
});

function multilang_container_admin_scripts($hook) {

    wp_enqueue_script(
        'multilang-utils',
        plugins_url('js/utilities.js', __FILE__),
        array('jquery'),
        null,
        true
    );

    wp_enqueue_script(
        'multilang-pako',
        plugins_url('js/pako.min.js', __FILE__),
        array('jquery'),
        null,
        true
    );

    if ($hook !== 'settings_page_multilang-container') {
        return;
    }
    
    wp_enqueue_style(
        'multilang-sortable-css',
        plugins_url('css/jquery-ui-sortable.css', __FILE__),
        array(),
        filemtime(plugin_dir_path(__FILE__) . 'css/jquery-ui-sortable.css')
    );
    
    
    wp_enqueue_script(
        'multilang-sortable-js',
        plugins_url('js/jquery-ui-sortable.js', __FILE__),
        array('jquery'),
        filemtime(plugin_dir_path(__FILE__) . 'js/jquery-ui-sortable.js'),
        true
    );
    
    wp_localize_script('multilang-sortable-js', 'multilangAjax', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'section_nonce' => wp_create_nonce('save_section_order'),
        'key_nonce' => wp_create_nonce('save_key_order')
    ));
}
add_action('admin_enqueue_scripts', 'multilang_container_admin_scripts');