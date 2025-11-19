<?php

if (!defined('ABSPATH')) {
    exit;
}

// Get the path to the options file
if (!function_exists('multilang_get_options_file_path')) {
    function multilang_get_options_file_path() {
        $upload_dir = wp_upload_dir();
        $multilang_dir = trailingslashit($upload_dir['basedir']) . 'multilang/';
        if (!is_dir($multilang_dir)) {
            wp_mkdir_p($multilang_dir);
        }
        return $multilang_dir . 'options.json';
    }
}

// Read plugin options from the JSON file
if (!function_exists('multilang_get_options')) {
    function multilang_get_options() {
        $file_path = multilang_get_options_file_path();
        if (!file_exists($file_path)) {
            return array();
        }
        $json_content = file_get_contents($file_path);
        $options = json_decode($json_content, true);
        return is_array($options) ? $options : array();
    }
}

// Save plugin options to the JSON file
if (!function_exists('multilang_save_options')) {
    function multilang_save_options($options) {
        $file_path = multilang_get_options_file_path();
        $json_content = json_encode($options, JSON_PRETTY_PRINT);
        file_put_contents($file_path, $json_content);
    }
}

// Handles AJAX requests to save cache settings
function multilang_ajax_save_cache_settings() {
    // Make sure the user has permission
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Permission denied.'));
        return;
    }
    
    // Security check for the request
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'multilang_cache_settings_nonce')) {
        wp_send_json_error(array('message' => 'Invalid nonce.'));
        return;
    }
    
    // Read settings from the request and save them
    $options = multilang_get_options();
    $options['cache_enabled'] = isset($_POST['multilang_cache_enabled']) ? intval($_POST['multilang_cache_enabled']) : 0;
    $options['cache_debug_logging'] = isset($_POST['multilang_cache_debug_logging']) ? intval($_POST['multilang_cache_debug_logging']) : 0;
    $options['cache_ajax_requests'] = isset($_POST['multilang_cache_ajax_requests']) ? intval($_POST['multilang_cache_ajax_requests']) : 0;
    $options['cache_exclude_pages'] = isset($_POST['multilang_cache_exclude_pages']) ? sanitize_textarea_field($_POST['multilang_cache_exclude_pages']) : '';
    $options['cache_logged_in'] = (isset($_POST['multilang_cache_logged_in']) && intval($_POST['multilang_cache_logged_in']) === 1) ? 1 : 0;
    $options['excerpt_line_limit_enabled'] = isset($_POST['multilang_excerpt_line_limit_enabled']) ? intval($_POST['multilang_excerpt_line_limit_enabled']) : 0;
    $options['excerpt_line_limit'] = isset($_POST['multilang_excerpt_line_limit']) ? intval($_POST['multilang_excerpt_line_limit']) : 0;
    $options['language_query_string_enabled'] = isset($_POST['multilang_language_query_string_enabled']) ? intval($_POST['multilang_language_query_string_enabled']) : 0;
    $options['language_switcher_query_string'] = isset($_POST['multilang_language_switcher_query_string']) ? intval($_POST['multilang_language_switcher_query_string']) : 0;
    $options['language_switcher_refresh_on_switch'] = isset($_POST['multilang_language_switcher_refresh_on_switch']) ? intval($_POST['multilang_language_switcher_refresh_on_switch']) : 0;
    multilang_save_options($options);
    
    // Store some settings in WordPress options for quick access
    update_option('multilang_container_cache_enabled', $options['cache_enabled']);
    update_option('multilang_container_cache_debug_logging', $options['cache_debug_logging']);
    update_option('multilang_container_cache_ajax_requests', $options['cache_ajax_requests']);
    update_option('multilang_container_cache_exclude_pages', $options['cache_exclude_pages']);
    
    wp_send_json_success(array('message' => 'Settings saved successfully.'));
}
add_action('wp_ajax_multilang_save_cache_settings', 'multilang_ajax_save_cache_settings');

// Handles AJAX requests to clear the cache
function multilang_ajax_clear_cache_option() {
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Permission denied.'));
        return;
    }
    
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'multilang_cache_nonce')) {
        wp_send_json_error(array('message' => 'Invalid nonce.'));
        return;
    }
    
    // Clear cache
    $deleted_count = multilang_clear_all_cache();
    
    wp_send_json_success(array(
        'message' => sprintf(__('%d cache files cleared successfully.', 'multilang-container'), $deleted_count),
        'deleted_count' => $deleted_count
    ));
}
add_action('wp_ajax_multilang_clear_cache', 'multilang_ajax_clear_cache_option');

// Handle form submission
function multilang_handle_options_save() {
    if (isset($_POST['multilang_save_options']) && check_admin_referer('multilang_options_nonce')) {
        $options = multilang_get_options();
        $options['excerpt_line_limit_enabled'] = isset($_POST['multilang_excerpt_line_limit_enabled']) ? 1 : 0;
        $options['excerpt_line_limit'] = isset($_POST['multilang_excerpt_line_limit']) ? intval($_POST['multilang_excerpt_line_limit']) : 0;
        $options['cache_logged_in'] = isset($_POST['multilang_cache_logged_in']) && intval($_POST['multilang_cache_logged_in']) === 1 ? 1 : 0;
    $options['language_query_string_enabled'] = isset($_POST['multilang_language_query_string_enabled']) ? 1 : 0;
    $options['language_switcher_query_string'] = isset($_POST['multilang_language_switcher_query_string']) ? 1 : 0;
    $options['language_switcher_refresh_on_switch'] = isset($_POST['multilang_language_switcher_refresh_on_switch']) ? 1 : 0;
        multilang_save_options($options);
    }
}
add_action('admin_init', 'multilang_handle_options_save');

// Render options tab content
function multilang_render_options_tab($active_tab) {
    $options = multilang_get_options();
    $language_query_string_enabled = isset($options['language_query_string_enabled']) ? $options['language_query_string_enabled'] : 1;
    $language_switcher_query_string = isset($options['language_switcher_query_string']) ? $options['language_switcher_query_string'] : 1;
    $language_switcher_refresh_on_switch = isset($options['language_switcher_refresh_on_switch']) ? $options['language_switcher_refresh_on_switch'] : 0;
    $line_limit_enabled = isset($options['excerpt_line_limit_enabled']) ? $options['excerpt_line_limit_enabled'] : 0;
    $line_limit = isset($options['excerpt_line_limit']) ? $options['excerpt_line_limit'] : '';
    $cache_enabled = isset($options['cache_enabled']) ? $options['cache_enabled'] : 1; // Enabled by default
    $cache_debug_logging = isset($options['cache_debug_logging']) ? $options['cache_debug_logging'] : 0; // Disabled by default
    $cache_ajax_requests = isset($options['cache_ajax_requests']) ? $options['cache_ajax_requests'] : 0; // Disabled by default
    $cache_exclude_pages = isset($options['cache_exclude_pages']) ? $options['cache_exclude_pages'] : '';
    $cache_logged_in = isset($options['cache_logged_in']) ? intval($options['cache_logged_in']) : 0;
    
    // Get cache info
    $cache_info = multilang_get_cache_info();
    
    echo '<div id="tab-misc" class="multilang-tab-content" style="' . ($active_tab !== 'misc' ? 'display:none;' : '') . '">';
    
    // Show any notices
    settings_errors('multilang_options');
    
    echo '<form id="multilang-cache-form" method="post" action="" style="background:#fff;padding:2em 2em 1em 2em;border-radius:1em;box-shadow:0 2px 16px rgba(0,0,0,0.07);">';
    wp_nonce_field('multilang_options_nonce');
    ?>
    
    <h2>Cache</h2>
    
    <div id="multilang-cache-message" style="display:none;margin:10px 0;padding:10px;border-radius:4px;"></div>
    
    <table class="form-table">
        <tr>
            <th scope="row">
                <label for="multilang_cache_enabled">Enable Page Caching</label>
            </th>
            <td>
                <input 
                    type="checkbox" 
                    id="multilang_cache_enabled" 
                    name="multilang_cache_enabled" 
                    value="1"
                    <?php checked($cache_enabled, 1); ?>
                />
                <span class="description">Cache translated pages/posts. Cache is automatically cleared when content is saved.</span>
            </td>
        </tr>
        <tr>
            <th scope="row">
                <label for="multilang_cache_logged_in">Cache pages for logged-in users?</label>
            </th>
            <td>
                <input type="checkbox" id="multilang_cache_logged_in" name="multilang_cache_logged_in" value="1" <?php checked($cache_logged_in, 1); ?> />
                <span class="description">If checked, pages will be cached for logged-in users (admin bar will be removed from cached content).</span>
            </td>
        </tr>
        <tr>
            <th scope="row">
                <label for="multilang_cache_ajax_requests">Cache AJAX Requests</label>
            </th>
            <td>
                <input 
                    type="checkbox" 
                    id="multilang_cache_ajax_requests" 
                    name="multilang_cache_ajax_requests" 
                    value="1"
                    <?php checked($cache_ajax_requests, 1); ?>
                />
                <span class="description">Cache AJAX requests (like "load more" posts). <strong>Disable this if you experience issues with dynamic content loading.</strong></span>
            </td>
        </tr>
        <tr>
            <th scope="row">
                <label for="multilang_cache_exclude_pages">Exclude Pages from Cache</label>
            </th>
            <td>
                <textarea 
                    id="multilang_cache_exclude_pages" 
                    name="multilang_cache_exclude_pages" 
                    rows="3"
                    class="large-text"
                    placeholder="/cart, /checkout, /my-account"
                ><?php echo esc_textarea($cache_exclude_pages); ?></textarea>
                <p class="description">Comma-separated list of page slugs or paths to exclude from caching (e.g., <code>/cart, /checkout, /my-account</code>). These pages will never be cached.</p>
            </td>
        </tr>
        <tr>
            <th scope="row">
                <label for="multilang_cache_debug_logging">Enable Debug Logging</label>
            </th>
            <td>
                <input 
                    type="checkbox" 
                    id="multilang_cache_debug_logging" 
                    name="multilang_cache_debug_logging" 
                    value="1"
                    <?php checked($cache_debug_logging, 1); ?>
                />
                <span class="description">Log cache hits, misses, generation, and clearing to the PHP error log (debug.log). Useful for troubleshooting.</span>
            </td>
        </tr>
        <tr>
            <th scope="row">Caching Strategy</th>
            <td>
                <p><strong>Per-Page Caching:</strong> Each page and post has its own cache file.</p>
                <p><strong>Auto-Invalidation:</strong> Cache is cleared automatically when:</p>
                <ul style="margin-left: 20px; list-style: disc;">
                    <li>A post/page is saved or updated</li>
                    <li>A post is deleted</li>
                    <li>Translations are updated</li>
                    <li>Language settings change</li>
                </ul>
                <p class="description">No time-based expiration - cache stays fresh until content changes.</p>
            </td>
        </tr>
        <tr>
            <th scope="row">Cache Statistics</th>
            <td>
                <p><strong>Files:</strong> <?php echo esc_html($cache_info['count']); ?></p>
                <p><strong>Size:</strong> <?php echo esc_html($cache_info['size_formatted']); ?></p>
                <p><strong>Location:</strong> <code><?php echo esc_html(multilang_get_cache_dir()); ?></code></p>
            </td>
        </tr>
    </table>
    
    <div style="display: flex; gap: 10px; align-items: center;">
        <button type="button" class="button button-secondary" id="multilang-clear-cache-btn">Clear All Cache</button>
        <span id="multilang-cache-spinner" class="spinner" style="float:none;margin:0;"></span>
    </div>
    </form>
    
    <script>
    jQuery(document).ready(function($) {
        // Save cache settings via AJAX
        $('#multilang-cache-form').on('submit', function(e) {
            e.preventDefault();
            
            var $form = $(this);
            var $message = $('#multilang-cache-message');
            var $spinner = $('#multilang-cache-spinner');
            var $submitBtn = $('#multilang-save-cache-settings');
            
            $spinner.addClass('is-active');
            $submitBtn.prop('disabled', true);
            $message.hide();
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'multilang_save_cache_settings',
                    nonce: '<?php echo wp_create_nonce('multilang_cache_settings_nonce'); ?>',
                    cache_enabled: $('#multilang_cache_enabled').is(':checked') ? 1 : 0,
                    cache_ajax_requests: $('#multilang_cache_ajax_requests').is(':checked') ? 1 : 0,
                    cache_exclude_pages: $('#multilang_cache_exclude_pages').val(),
                    cache_debug_logging: $('#multilang_cache_debug_logging').is(':checked') ? 1 : 0,
                    cache_logged_in: $('#multilang_cache_logged_in').is(':checked') ? 1 : 0,
                    language_switcher_query_string: $('#multilang_language_switcher_query_string').is(':checked') ? 1 : 0
                },
                success: function(response) {
                    $spinner.removeClass('is-active');
                    $submitBtn.prop('disabled', false);
                    
                    if (response.success) {
                        $message.removeClass('error').addClass('success')
                            .css({
                                'background-color': '#d4edda',
                                'border': '1px solid #c3e6cb',
                                'color': '#155724'
                            })
                            .text('Settings saved successfully.')
                            .slideDown();
                    } else {
                        $message.removeClass('success').addClass('error')
                            .css({
                                'background-color': '#f8d7da',
                                'border': '1px solid #f5c6cb',
                                'color': '#721c24'
                            })
                            .text(response.data.message || 'Failed to save settings.')
                            .slideDown();
                    }
                    
                    setTimeout(function() {
                        $message.slideUp();
                    }, 3000);
                },
                error: function() {
                    $spinner.removeClass('is-active');
                    $submitBtn.prop('disabled', false);
                    $message.removeClass('success').addClass('error')
                        .css({
                            'background-color': '#f8d7da',
                            'border': '1px solid #f5c6cb',
                            'color': '#721c24'
                        })
                        .text('An error occurred while saving settings.')
                        .slideDown();
                }
            });
        });
        
        // Clear cache via AJAX
        $('#multilang-clear-cache-btn').on('click', function(e) {
            e.preventDefault();
            
            var $btn = $(this);
            var $message = $('#multilang-cache-message');
            var $spinner = $('#multilang-cache-spinner');
            
            if (!confirm('Are you sure you want to clear all cache files?')) {
                return;
            }
            
            $spinner.addClass('is-active');
            $btn.prop('disabled', true);
            $message.hide();
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'multilang_clear_cache',
                    nonce: '<?php echo wp_create_nonce('multilang_cache_nonce'); ?>'
                },
                success: function(response) {
                    $spinner.removeClass('is-active');
                    $btn.prop('disabled', false);
                    
                    if (response.success) {
                        $message.removeClass('error').addClass('success')
                            .css({
                                'background-color': '#d4edda',
                                'border': '1px solid #c3e6cb',
                                'color': '#155724'
                            })
                            .text(response.data.message)
                            .slideDown();
                        
                        // Reload cache stats
                        location.reload();
                    } else {
                        $message.removeClass('success').addClass('error')
                            .css({
                                'background-color': '#f8d7da',
                                'border': '1px solid #f5c6cb',
                                'color': '#721c24'
                            })
                            .text(response.data.message || 'Failed to clear cache.')
                            .slideDown();
                    }
                },
                error: function() {
                    $spinner.removeClass('is-active');
                    $btn.prop('disabled', false);
                    $message.removeClass('success').addClass('error')
                        .css({
                            'background-color': '#f8d7da',
                            'border': '1px solid #f5c6cb',
                            'color': '#721c24'
                        })
                        .text('An error occurred while clearing cache.')
                        .slideDown();
                }
            });
        });
    });
    </script>
    
    <h2 style="margin-top:2em;">Excerpt Settings</h2>
    
    <form method="post" action="" style="background:#fff;padding:2em 2em 1em 2em;border-radius:1em;box-shadow:0 2px 16px rgba(0,0,0,0.07);">
    <?php wp_nonce_field('multilang_options_nonce'); ?>
    <table class="form-table">
        <tr>
            <th scope="row">
                <label for="multilang_excerpt_line_limit_enabled">Enable Line Limiting</label>
            </th>
            <td>
                <input 
                    type="checkbox" 
                    id="multilang_excerpt_line_limit_enabled" 
                    name="multilang_excerpt_line_limit_enabled" 
                    value="1"
                    <?php checked($line_limit_enabled, 1); ?>
                />
            </td>
        </tr>
        <tr>
            <th scope="row">
                <label for="multilang_excerpt_line_limit">Line Limit for Excerpts</label>
            </th>
            <td>
                <input 
                    type="number" 
                    id="multilang_excerpt_line_limit" 
                    name="multilang_excerpt_line_limit" 
                    value="<?php echo esc_attr($line_limit); ?>" 
                    min="0"
                    class="regular-text"
                />
                <p class="description">Leave empty for no limit. Applied server-side with inline styles.</p>
            </td>
        </tr>
    </table>
    
    <!-- Save button removed from Excerpt section -->
    </form>
    
    <h2 style="margin-top:2em;">Language Switcher</h2>
    <form method="post" action="" style="background:#fff;padding:2em 2em 1em 2em;border-radius:1em;box-shadow:0 2px 16px rgba(0,0,0,0.07);">
    <?php wp_nonce_field('multilang_options_nonce'); ?>
    <table class="form-table">
        <tr>
            <th scope="row">
                <label for="multilang_language_query_string_enabled">Enable query string language switching</label>
            </th>
            <td>
                <input type="checkbox" id="multilang_language_query_string_enabled" name="multilang_language_query_string_enabled" value="1"<?php checked($language_query_string_enabled, 1); ?> />
                <span class="description">If checked, you can use <code>?lang=xx</code> in the address bar to switch languages.</span>
            </td>
        </tr>
        <tr>
            <th scope="row">
                <label for="multilang_language_switcher_query_string">Use query string in language switcher</label>
            </th>
            <td>
                <input type="checkbox" id="multilang_language_switcher_query_string" name="multilang_language_switcher_query_string" value="1"<?php checked(isset($options['language_switcher_query_string']) && $options['language_switcher_query_string'], 1); ?> <?php echo $language_query_string_enabled ? '' : 'disabled="disabled"'; ?> />
                <span class="description">If checked, language switcher links will use <code>?lang=xx</code> in the URL for caching and SEO.</span>
            </td>
        </tr>
        <tr>
            <th scope="row">
                <label for="multilang_language_switcher_refresh_on_switch">Refresh page on language switch</label>
            </th>
            <td>
                <input type="checkbox" id="multilang_language_switcher_refresh_on_switch" name="multilang_language_switcher_refresh_on_switch" value="1"<?php checked($language_switcher_refresh_on_switch, 1); ?> />
                <span class="description">If checked, switching languages will reload the page. If unchecked, only the query string in the address bar will change (no refresh).</span>
            </td>
        </tr>
    </table>
    <!-- Save button removed from Language Switcher section -->
    </form>
    <div style="margin-top:2em;text-align:center;">
        <button type="button" class="button button-primary" id="multilang-save-all-settings">Save All Settings</button>
    </div>
    <script>
    jQuery(document).ready(function($) {
        // Remove individual form submissions
        $('#multilang-cache-form, #multilang-excerpt-form, #multilang-language-form').on('submit', function(e) {
            e.preventDefault();
        });

        // Live enable/disable for language switcher query string
        function updateLanguageSwitcherQueryStringState() {
            var $enable = $('#multilang_language_query_string_enabled');
            var $switcher = $('#multilang_language_switcher_query_string');
            if ($enable.is(':checked')) {
                $switcher.prop('disabled', false).closest('td').css('opacity', 1);
            } else {
                $switcher.prop('disabled', true).closest('td').css('opacity', 0.5);
            }
        }
        updateLanguageSwitcherQueryStringState();
        $('#multilang_language_query_string_enabled').on('change', updateLanguageSwitcherQueryStringState);

        // Save all settings via AJAX
        $('#multilang-save-all-settings').on('click', function(e) {
            e.preventDefault();
            var $btn = $(this);
            $btn.prop('disabled', true);
            var $spinner = $('#multilang-cache-spinner');
            var $message = $('#multilang-cache-message');
            $spinner.addClass('is-active');
            $message.hide();

            // Collect all values from all sections
            var data = {
                action: 'multilang_save_cache_settings',
                nonce: '<?php echo wp_create_nonce('multilang_cache_settings_nonce'); ?>',
                multilang_cache_enabled: $('#multilang_cache_enabled').is(':checked') ? 1 : 0,
                multilang_cache_logged_in: $('#multilang_cache_logged_in').is(':checked') ? 1 : 0,
                multilang_cache_ajax_requests: $('#multilang_cache_ajax_requests').is(':checked') ? 1 : 0,
                multilang_cache_exclude_pages: $('#multilang_cache_exclude_pages').val(),
                multilang_cache_debug_logging: $('#multilang_cache_debug_logging').is(':checked') ? 1 : 0,
                multilang_excerpt_line_limit_enabled: $('#multilang_excerpt_line_limit_enabled').is(':checked') ? 1 : 0,
                multilang_excerpt_line_limit: $('#multilang_excerpt_line_limit').val(),
                multilang_language_query_string_enabled: $('#multilang_language_query_string_enabled').is(':checked') ? 1 : 0,
                multilang_language_switcher_query_string: $('#multilang_language_switcher_query_string').is(':checked') ? 1 : 0,
                multilang_language_switcher_refresh_on_switch: $('#multilang_language_switcher_refresh_on_switch').is(':checked') ? 1 : 0
            };

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: data,
                success: function(response) {
                    $spinner.removeClass('is-active');
                    $btn.prop('disabled', false);
                    if (response.success) {
                        $message.removeClass('error').addClass('success')
                            .css({
                                'background-color': '#d4edda',
                                'border': '1px solid #c3e6cb',
                                'color': '#155724'
                            })
                            .text('Settings saved successfully.')
                            .slideDown();
                    } else {
                        $message.removeClass('success').addClass('error')
                            .css({
                                'background-color': '#f8d7da',
                                'border': '1px solid #f5c6cb',
                                'color': '#721c24'
                            })
                            .text(response.data.message || 'Failed to save settings.')
                            .slideDown();
                    }
                    setTimeout(function() {
                        $message.slideUp();
                    }, 3000);
                },
                error: function() {
                    $spinner.removeClass('is-active');
                    $btn.prop('disabled', false);
                    $message.removeClass('success').addClass('error')
                        .css({
                            'background-color': '#f8d7da',
                            'border': '1px solid #f5c6cb',
                            'color': '#721c24'
                        })
                        .text('An error occurred while saving settings.')
                        .slideDown();
                }
            });
        });

        // Clear cache via AJAX
        $('#multilang-clear-cache-btn').on('click', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var $message = $('#multilang-cache-message');
            var $spinner = $('#multilang-cache-spinner');
            if (!confirm('Are you sure you want to clear all cache files?')) {
                return;
            }
            $spinner.addClass('is-active');
            $btn.prop('disabled', true);
            $message.hide();
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'multilang_clear_cache',
                    nonce: '<?php echo wp_create_nonce('multilang_cache_nonce'); ?>'
                },
                success: function(response) {
                    $spinner.removeClass('is-active');
                    $btn.prop('disabled', false);
                    if (response.success) {
                        $message.removeClass('error').addClass('success')
                            .css({
                                'background-color': '#d4edda',
                                'border': '1px solid #c3e6cb',
                                'color': '#155724'
                            })
                            .text(response.data.message)
                            .slideDown();
                        location.reload();
                    } else {
                        $message.removeClass('success').addClass('error')
                            .css({
                                'background-color': '#f8d7da',
                                'border': '1px solid #f5c6cb',
                                'color': '#721c24'
                            })
                            .text(response.data.message || 'Failed to clear cache.')
                            .slideDown();
                    }
                },
                error: function() {
                    $spinner.removeClass('is-active');
                    $btn.prop('disabled', false);
                    $message.removeClass('success').addClass('error')
                        .css({
                            'background-color': '#f8d7da',
                            'border': '1px solid #f5c6cb',
                            'color': '#721c24'
                        })
                        .text('An error occurred while clearing cache.')
                        .slideDown();
                }
            });
        });
    });
    </script>
    <?php
    echo '</div>';
}

// Output inline CSS for excerpt line limiting
function multilang_excerpt_line_limit_css() {
    $options = multilang_get_options();
    $line_limit_enabled = isset($options['excerpt_line_limit_enabled']) ? $options['excerpt_line_limit_enabled'] : 0;
    $line_limit = isset($options['excerpt_line_limit']) ? $options['excerpt_line_limit'] : '';
    
    if ($line_limit_enabled && !empty($line_limit) && is_numeric($line_limit)) {
        echo '<style id="multilang-excerpt-limit">';
        echo '.recent-posts-content .meta + p .translate,';
        echo '.fusion-blog-archive .translate {';
        echo 'display: -webkit-box;';
        echo '-webkit-box-orient: vertical;';
        echo '-webkit-line-clamp: ' . intval($line_limit) . ';';
        echo 'line-clamp: ' . intval($line_limit) . ';';
        echo 'overflow: hidden;';
        echo '}';
        echo '</style>';
    }
}
add_action('wp_head', 'multilang_excerpt_line_limit_css', -10000);
