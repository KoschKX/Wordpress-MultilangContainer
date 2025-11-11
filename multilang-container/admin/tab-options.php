<?php

if (!defined('ABSPATH')) {
    exit;
}

// Helper function to get options file path
function multilang_get_options_file_path() {
    $upload_dir = wp_upload_dir();
    $multilang_dir = trailingslashit($upload_dir['basedir']) . 'multilang/';
    if (!is_dir($multilang_dir)) {
        wp_mkdir_p($multilang_dir);
    }
    return $multilang_dir . 'options.json';
}

// Get all options from JSON file
function multilang_get_options() {
    $file_path = multilang_get_options_file_path();
    if (!file_exists($file_path)) {
        return array();
    }
    $json_content = file_get_contents($file_path);
    $options = json_decode($json_content, true);
    return is_array($options) ? $options : array();
}

// Save all options to JSON file
function multilang_save_options($options) {
    $file_path = multilang_get_options_file_path();
    $json_content = json_encode($options, JSON_PRETTY_PRINT);
    file_put_contents($file_path, $json_content);
}

// Handle form submission
function multilang_handle_options_save() {
    if (isset($_POST['multilang_save_options']) && check_admin_referer('multilang_options_nonce')) {
        $options = multilang_get_options();
        $options['excerpt_line_limit_enabled'] = isset($_POST['multilang_excerpt_line_limit_enabled']) ? 1 : 0;
        $options['excerpt_line_limit'] = isset($_POST['multilang_excerpt_line_limit']) ? intval($_POST['multilang_excerpt_line_limit']) : 0;
        multilang_save_options($options);
        /*
        add_settings_error(
            'multilang_options',
            'multilang_settings_updated',
            'Settings saved successfully.',
            'updated'
        );
        */
    }
}
add_action('admin_init', 'multilang_handle_options_save');

// Render options tab content
function multilang_render_options_tab($active_tab) {
    $options = multilang_get_options();
    $line_limit_enabled = isset($options['excerpt_line_limit_enabled']) ? $options['excerpt_line_limit_enabled'] : 0;
    $line_limit = isset($options['excerpt_line_limit']) ? $options['excerpt_line_limit'] : '';
    
    echo '<div id="tab-misc" class="multilang-tab-content" style="' . ($active_tab !== 'misc' ? 'display:none;' : '') . '">';
    
    // Show any notices
    settings_errors('multilang_options');
    
    echo '<form method="post" action="" style="background:#fff;padding:2em 2em 1em 2em;border-radius:1em;box-shadow:0 2px 16px rgba(0,0,0,0.07);">';
    wp_nonce_field('multilang_options_nonce');
    ?>
    <h2>Excerpt Settings</h2>
    
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
    
    <?php 
    submit_button('Save Settings', 'primary', 'multilang_save_options');
    ?>
    </form>
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
