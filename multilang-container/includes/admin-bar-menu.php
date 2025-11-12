<?php
/**
 * Multilang Container - Admin Bar Menu
 * 
 * Adds quick cache management options to the WordPress admin bar
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Add Multilang menu to admin bar
 */
function multilang_add_admin_bar_menu($wp_admin_bar) {
    // Temporarily disabled
    return;
    
    // Only show to users who can manage options
    if (!current_user_can('manage_options')) {
        return;
    }
    
    // Don't show in admin area
    if (is_admin()) {
        return;
    }
    
    global $post;
    $cache_info = multilang_get_cache_info();
    
    // Main menu item
    $wp_admin_bar->add_node(array(
        'id'    => 'multilang-container',
        'title' => '🌐 Multilang',
        'href'  => admin_url('options-general.php?page=multilang-container'),
        'meta'  => array(
            'title' => 'Multilang Container Cache',
        ),
    ));
    
    // Cache info submenu
    $wp_admin_bar->add_node(array(
        'id'     => 'multilang-cache-info',
        'parent' => 'multilang-container',
        'title'  => '📊 Cache: ' . $cache_info['count'] . ' files (' . $cache_info['size_formatted'] . ')',
        'href'   => false,
        'meta'   => array(
            'title' => 'Current cache statistics',
        ),
    ));
    
    // Separator
    $wp_admin_bar->add_node(array(
        'id'     => 'multilang-separator-1',
        'parent' => 'multilang-container',
        'title'  => '<hr style="margin: 5px 0; border: none; border-top: 1px solid #464646;">',
        'href'   => false,
    ));
    
    // Clear current page cache (only on singular pages)
    if (is_singular() && $post && !empty($post->ID)) {
        $wp_admin_bar->add_node(array(
            'id'     => 'multilang-clear-current',
            'parent' => 'multilang-container',
            'title'  => '🗑️ Clear This Page Cache',
            'href'   => wp_nonce_url(
                add_query_arg('multilang_clear_current', $post->ID),
                'multilang_clear_current_' . $post->ID
            ),
            'meta'   => array(
                'title' => 'Clear cache for this page/post',
            ),
        ));
    } elseif (is_category()) {
        $cat = get_queried_object();
        $wp_admin_bar->add_node(array(
            'id'     => 'multilang-clear-current',
            'parent' => 'multilang-container',
            'title'  => '🗑️ Clear This Category Cache',
            'href'   => wp_nonce_url(
                add_query_arg('multilang_clear_category', $cat->term_id),
                'multilang_clear_category_' . $cat->term_id
            ),
            'meta'   => array(
                'title' => 'Clear cache for this category',
            ),
        ));
    } elseif (is_tag()) {
        $tag = get_queried_object();
        $wp_admin_bar->add_node(array(
            'id'     => 'multilang-clear-current',
            'parent' => 'multilang-container',
            'title'  => '🗑️ Clear This Tag Cache',
            'href'   => wp_nonce_url(
                add_query_arg('multilang_clear_tag', $tag->term_id),
                'multilang_clear_tag_' . $tag->term_id
            ),
            'meta'   => array(
                'title' => 'Clear cache for this tag',
            ),
        ));
    } elseif (is_author()) {
        $author = get_queried_object();
        $wp_admin_bar->add_node(array(
            'id'     => 'multilang-clear-current',
            'parent' => 'multilang-container',
            'title'  => '🗑️ Clear This Author Cache',
            'href'   => wp_nonce_url(
                add_query_arg('multilang_clear_author', $author->ID),
                'multilang_clear_author_' . $author->ID
            ),
            'meta'   => array(
                'title' => 'Clear cache for this author',
            ),
        ));
    } elseif (is_front_page() || is_home()) {
        $wp_admin_bar->add_node(array(
            'id'     => 'multilang-clear-current',
            'parent' => 'multilang-container',
            'title'  => '🗑️ Clear Home Page Cache',
            'href'   => wp_nonce_url(
                add_query_arg('multilang_clear_home', '1'),
                'multilang_clear_home'
            ),
            'meta'   => array(
                'title' => 'Clear cache for home page',
            ),
        ));
    }
    
    // Clear all cache
    $wp_admin_bar->add_node(array(
        'id'     => 'multilang-clear-all',
        'parent' => 'multilang-container',
        'title'  => '🗑️ Clear All Cache',
        'href'   => wp_nonce_url(
            add_query_arg('multilang_clear_all', '1'),
            'multilang_clear_all'
        ),
        'meta'   => array(
            'title' => 'Clear all cached pages',
        ),
    ));
    
    // Separator
    $wp_admin_bar->add_node(array(
        'id'     => 'multilang-separator-2',
        'parent' => 'multilang-container',
        'title'  => '<hr style="margin: 5px 0; border: none; border-top: 1px solid #464646;">',
        'href'   => false,
    ));
    
    // Settings link
    $wp_admin_bar->add_node(array(
        'id'     => 'multilang-settings',
        'parent' => 'multilang-container',
        'title'  => '⚙️ Settings',
        'href'   => admin_url('options-general.php?page=multilang-container'),
        'meta'   => array(
            'title' => 'Go to Multilang Container settings',
        ),
    ));
}
add_action('admin_bar_menu', 'multilang_add_admin_bar_menu', 100);

/**
 * Handle cache clear requests from admin bar
 */
function multilang_handle_admin_bar_cache_clear() {
    // Clear current page
    if (isset($_GET['multilang_clear_current']) && is_numeric($_GET['multilang_clear_current'])) {
        $post_id = intval($_GET['multilang_clear_current']);
        
        if (!wp_verify_nonce($_GET['_wpnonce'], 'multilang_clear_current_' . $post_id)) {
            wp_die('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Permission denied');
        }
        
        multilang_clear_post_cache($post_id);
        
        // Redirect back without query args
        wp_safe_redirect(remove_query_arg(array('multilang_clear_current', '_wpnonce')));
        exit;
    }
    
    // Clear category
    if (isset($_GET['multilang_clear_category']) && is_numeric($_GET['multilang_clear_category'])) {
        $cat_id = intval($_GET['multilang_clear_category']);
        
        if (!wp_verify_nonce($_GET['_wpnonce'], 'multilang_clear_category_' . $cat_id)) {
            wp_die('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Permission denied');
        }
        
        multilang_clear_category_cache($cat_id);
        
        wp_safe_redirect(remove_query_arg(array('multilang_clear_category', '_wpnonce')));
        exit;
    }
    
    // Clear tag
    if (isset($_GET['multilang_clear_tag']) && is_numeric($_GET['multilang_clear_tag'])) {
        $tag_id = intval($_GET['multilang_clear_tag']);
        
        if (!wp_verify_nonce($_GET['_wpnonce'], 'multilang_clear_tag_' . $tag_id)) {
            wp_die('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Permission denied');
        }
        
        multilang_clear_tag_cache($tag_id);
        
        wp_safe_redirect(remove_query_arg(array('multilang_clear_tag', '_wpnonce')));
        exit;
    }
    
    // Clear author
    if (isset($_GET['multilang_clear_author']) && is_numeric($_GET['multilang_clear_author'])) {
        $author_id = intval($_GET['multilang_clear_author']);
        
        if (!wp_verify_nonce($_GET['_wpnonce'], 'multilang_clear_author_' . $author_id)) {
            wp_die('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Permission denied');
        }
        
        multilang_clear_author_cache($author_id);
        
        wp_safe_redirect(remove_query_arg(array('multilang_clear_author', '_wpnonce')));
        exit;
    }
    
    // Clear home
    if (isset($_GET['multilang_clear_home'])) {
        if (!wp_verify_nonce($_GET['_wpnonce'], 'multilang_clear_home')) {
            wp_die('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Permission denied');
        }
        
        multilang_clear_home_cache();
        
        wp_safe_redirect(remove_query_arg(array('multilang_clear_home', '_wpnonce')));
        exit;
    }
    
    // Clear all cache
    if (isset($_GET['multilang_clear_all'])) {
        if (!wp_verify_nonce($_GET['_wpnonce'], 'multilang_clear_all')) {
            wp_die('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Permission denied');
        }
        
        multilang_clear_all_cache();
        
        wp_safe_redirect(remove_query_arg(array('multilang_clear_all', '_wpnonce')));
        exit;
    }
}
add_action('init', 'multilang_handle_admin_bar_cache_clear');

/**
 * Add CSS for admin bar menu
 */
function multilang_admin_bar_css() {
    if (!is_admin_bar_showing()) {
        return;
    }
    ?>
    <style>
        #wp-admin-bar-multilang-container .ab-item {
            font-weight: 600;
        }
        #wp-admin-bar-multilang-cache-info .ab-item {
            cursor: default;
            color: #72aee6 !important;
        }
        #wp-admin-bar-multilang-cache-info:hover .ab-item {
            color: #72aee6 !important;
        }
        #wp-admin-bar-multilang-separator-1 .ab-item,
        #wp-admin-bar-multilang-separator-2 .ab-item {
            cursor: default;
            padding: 0 !important;
            height: 1px;
        }
        #wp-admin-bar-multilang-separator-1:hover,
        #wp-admin-bar-multilang-separator-2:hover {
            background: transparent;
        }
    </style>
    <?php
}
add_action('wp_head', 'multilang_admin_bar_css');
add_action('admin_head', 'multilang_admin_bar_css');
