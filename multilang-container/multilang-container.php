<?php
/*
Plugin Name: Multilang Container
Description: Adds a custom block for the FSE editor to display text in different languages.
Version: 1.0
Author: Gary Angelone Jr.
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Include all modular components
require_once plugin_dir_path(__FILE__) . 'includes/utilities.php';
require_once plugin_dir_path(__FILE__) . 'includes/cache-handler.php';
require_once plugin_dir_path(__FILE__) . 'includes/cache-folder-monitor.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin-bar-menu.php';
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

require_once plugin_dir_path(__FILE__) . 'demo-usage.php';

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

/**
 * Enqueue admin scripts for multilang container settings page
 */
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