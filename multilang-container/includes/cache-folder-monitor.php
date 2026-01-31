<?php
// Watch cache folders and sync with other cache plugins

if (!defined('ABSPATH')) {
    exit;
}

function multilang_get_cache_folders_to_monitor() {
    $folders = array();
    
    $main_cache = WP_CONTENT_DIR . '/cache/';
    if (is_dir($main_cache)) {
        $folders[] = $main_cache;
    }
    
    return $folders;
}

function multilang_get_folder_mtime($folder_path) {
    if (!is_dir($folder_path)) {
        return 0;
    }
    
    return filemtime($folder_path);
}

function multilang_check_cache_folder_changes() {
    $folders = multilang_get_cache_folders_to_monitor();
    
    if (empty($folders)) {
        return false;
    }
    
    $stored_mtimes = get_option('multilang_cache_folder_mtimes', array());
    $current_mtimes = array();
    $cache_was_cleared = false;
    
    foreach ($folders as $folder) {
        $current_mtime = multilang_get_folder_mtime($folder);
        $current_mtimes[$folder] = $current_mtime;
        
        if (isset($stored_mtimes[$folder]) && $stored_mtimes[$folder] !== $current_mtime) {
            $cache_was_cleared = true;
            if (multilang_is_cache_debug_logging_enabled()) {
                error_log('[Multilang Cache] /wp-content/cache/ folder changed - clearing Multilang cache');
            }
        }
    }
    
    update_option('multilang_cache_folder_mtimes', $current_mtimes, false);
    
    if ($cache_was_cleared) {
        multilang_clear_all_cache();
    }
    
    return $cache_was_cleared;
}

function multilang_init_cache_folder_monitor() {
    multilang_check_cache_folder_changes();
}
add_action('init', 'multilang_init_cache_folder_monitor', 999);

function multilang_hook_wpfc_clear() {
    // Hook into WPFC cache clear events
    add_action('wpfc_clear_all_cache', 'multilang_clear_all_cache');
    add_action('wpfc_clear_cache_of_allsites', 'multilang_clear_all_cache');
    add_action('wpfc_delete_cache', 'multilang_clear_all_cache');
    
    // Don't let WPFC cache AJAX requests
    add_filter('wpfc_is_cacheable', function($cacheable) {
        if (wp_doing_ajax()) {
            return false;
        }
        return $cacheable;
    }, 1, 1);
}
add_action('plugins_loaded', 'multilang_hook_wpfc_clear', 1);

// Hook into other popular cache plugins
function multilang_hook_other_cache_plugins() {
    add_action('w3tc_flush_all', 'multilang_clear_all_cache');
    add_action('w3tc_flush_posts', 'multilang_clear_all_cache');
    add_action('wp_cache_cleared', 'multilang_clear_all_cache');
    add_action('litespeed_purged_all', 'multilang_clear_all_cache');
    add_action('after_rocket_clean_domain', 'multilang_clear_all_cache');
    add_action('rocket_purge_cache', 'multilang_clear_all_cache');
    add_action('autoptimize_action_cachepurged', 'multilang_clear_all_cache');
    add_action('ce_clear_cache', 'multilang_clear_all_cache');
    add_action('comet_cache_wipe_cache', 'multilang_clear_all_cache');
}
add_action('plugins_loaded', 'multilang_hook_other_cache_plugins', 1);

function multilang_init_cache_mtimes() {
    if (!get_option('multilang_cache_folder_mtimes')) {
        $folders = multilang_get_cache_folders_to_monitor();
        $mtimes = array();
        
        foreach ($folders as $folder) {
            $mtimes[$folder] = multilang_get_folder_mtime($folder);
        }
        
        update_option('multilang_cache_folder_mtimes', $mtimes, false);
    }
}
add_action('admin_init', 'multilang_init_cache_mtimes');

function multilang_update_cache_mtimes_after_clear() {
    // Update mtimes after clearing cache
    $folders = multilang_get_cache_folders_to_monitor();
    $mtimes = array();
    
    foreach ($folders as $folder) {
        $mtimes[$folder] = multilang_get_folder_mtime($folder);
    }
    
    update_option('multilang_cache_folder_mtimes', $mtimes, false);
}
add_action('multilang_cache_cleared', 'multilang_update_cache_mtimes_after_clear');
