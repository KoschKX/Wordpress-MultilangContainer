<?php
/**
 * Multilang Container - Cache Folder Monitor
 * 
 * Monitors external cache folders
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get cache folders to monitor
 */
function multilang_get_cache_folders_to_monitor() {
    $folders = array();
    
    // Main WP cache folder
    $main_cache = WP_CONTENT_DIR . '/cache/';
    if (is_dir($main_cache)) {
        $folders[] = $main_cache;
    }
    
    return $folders;
}

/**
 * Get folder modification time
 */
function multilang_get_folder_mtime($folder_path) {
    if (!is_dir($folder_path)) {
        return 0;
    }
    
    return filemtime($folder_path);
}

/**
 * Check if cache folders have changed
 */
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
        
        // Check if folder changed
        if (isset($stored_mtimes[$folder]) && $stored_mtimes[$folder] !== $current_mtime) {
            $cache_was_cleared = true;
        }
    }
    
    if ($cache_was_cleared) {
        if (multilang_is_cache_debug_logging_enabled()) {
            error_log('[Multilang Cache] External cache cleared - clearing Multilang cache');
        }
        multilang_clear_all_cache();
        
        // Update stored mtimes after clearing
        update_option('multilang_cache_folder_mtimes', $current_mtimes, false);
    }
    
    return $cache_was_cleared;
}

/**
 * Monitor cache folders on init
 */
function multilang_init_cache_folder_monitor() {
    multilang_check_cache_folder_changes();
}
add_action('init', 'multilang_init_cache_folder_monitor', 999);

/**
 * Hook into WPFC clear actions
 */
function multilang_hook_wpfc_clear() {
    add_action('wpfc_clear_all_cache', 'multilang_clear_all_cache');
    
    // Exclude AJAX requests from being cached by WPFC - priority 1 to run early
    add_filter('wpfc_is_cacheable', function($cacheable) {
        if (wp_doing_ajax()) {
            return false;
        }
        return $cacheable;
    }, 1, 1);
}
add_action('plugins_loaded', 'multilang_hook_wpfc_clear', 1);

/**
 * Init cache folder mtimes on activation
 */
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

/**
 * Update mtimes after cache clear
 */
function multilang_update_cache_mtimes_after_clear() {
    $folders = multilang_get_cache_folders_to_monitor();
    $mtimes = array();
    
    foreach ($folders as $folder) {
        $mtimes[$folder] = multilang_get_folder_mtime($folder);
    }
    
    update_option('multilang_cache_folder_mtimes', $mtimes, false);
}
add_action('multilang_cache_cleared', 'multilang_update_cache_mtimes_after_clear');
