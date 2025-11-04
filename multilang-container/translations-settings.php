<?php
/**
 * Translations Settings Page for Multilang Container Plugin
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Get the path to translations JSON file
function get_translations_file_path() {
    return plugin_dir_path(__FILE__) . 'data/';
}

// Load translations from JSON file
function load_translations() {
    $file_path = get_translations_file_path();
    if (!file_exists($file_path)) {
        return array();
    }
    
    $json_content = file_get_contents($file_path);
    $translations = json_decode($json_content, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        return array();
    }
    
    return $translations;
}

// Save translations to JSON file
function save_translations($translations) {
    $file_path = get_translations_file_path();
    
    // Ensure data directory exists
    $data_dir = dirname($file_path);
    if (!file_exists($data_dir)) {
        wp_mkdir_p($data_dir);
    }
    
    $json_content = json_encode($translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
    if ($json_content === false) {
        return false;
    }
    
    return file_put_contents($file_path, $json_content) !== false;
}

// Get available languages from plugin settings
function get_multilang_available_languages() {
    return get_option('multilang_container_languages', array('en'));
}

// Settings page content
function multilang_translations_settings_page() {
    $translations = load_translations();
    $available_languages = get_multilang_available_languages();
    $message = '';
    $message_type = '';
    
    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!wp_verify_nonce($_POST['translations_nonce'], 'save_translations')) {
            $message = 'Security check failed.';
            $message_type = 'error';
        }
    }
    
    ?>
    <div class="wrap">
        <h1>Translation Manager</h1>
        
        <?php if (!empty($message)): ?>
            <div class="notice notice-<?php echo esc_attr($message_type); ?> is-dismissible">
                <p><?php echo esc_html($message); ?></p>
            </div>
        <?php endif; ?>
        
        <div class="translations-manager" style="max-width: 1200px;">
            
            <!-- Add New Translation Key -->
            <div class="postbox" style="margin-bottom: 20px;">
                <h2 class="hndle" style="padding: 10px 15px; margin: 0; background: #f1f1f1;">Add New Translation Key</h2>
                <div class="inside" style="padding: 15px;">
                    <form method="post" style="display: flex; gap: 10px; align-items: end;">
                        <?php wp_nonce_field('save_translations', 'translations_nonce'); ?>
                        
                        <div>
                            <label for="new_category">Category:</label><br>
                            <input type="text" name="new_category" id="new_category" 
                                   placeholder="e.g., ui, footer, calendar" 
                                   style="width: 200px;" required>
                        </div>
                        
                        <div>
                            <label for="new_key">Translation Key:</label><br>
                            <input type="text" name="new_key" id="new_key" 
                                   placeholder="e.g., Submit, Next, Search" 
                                   style="width: 200px;" required>
                        </div>
                        
                        <div>
                            <input type="submit" name="add_key" class="button button-primary" value="Add Key">
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Translations Form -->
            <form method="post">
                <?php wp_nonce_field('save_translations', 'translations_nonce'); ?>
                
                <?php if (empty($translations)): ?>
                    <div class="notice notice-info">
                        <p>No translations found. Add your first translation key above.</p>
                    </div>
                <?php else: ?>
                    
                    <?php foreach ($translations as $category => $keys): ?>
                        <div class="postbox" style="margin-bottom: 20px;">
                            <h2 class="hndle" style="padding: 10px 15px; margin: 0; background: #f1f1f1; text-transform: capitalize;">
                                <?php echo esc_html($category); ?> 
                                <span style="font-weight: normal; color: #666;">(<?php echo count($keys); ?> keys)</span>
                            </h2>
                            <div class="inside" style="padding: 0;">
                                <table class="wp-list-table widefat fixed striped">
                                    <thead>
                                        <tr>
                                            <th style="width: 200px; padding: 10px;">Translation Key</th>
                                            <?php foreach ($available_languages as $lang): ?>
                                                <th style="padding: 10px; text-transform: uppercase;"><?php echo esc_html($lang); ?></th>
                                            <?php endforeach; ?>
                                            <th style="width: 80px; padding: 10px;">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($keys as $key => $lang_translations): ?>
                                            <tr>
                                                <td style="padding: 10px; font-weight: 500; vertical-align: top;">
                                                    <strong><?php echo esc_html($key); ?></strong>
                                                </td>
                                                
                                                <?php foreach ($available_languages as $lang): ?>
                                                    <td style="padding: 5px;">
                                                        <textarea 
                                                            name="translations[<?php echo esc_attr($category); ?>][<?php echo esc_attr($key); ?>][<?php echo esc_attr($lang); ?>]"
                                                            style="width: 100%; min-height: 40px; padding: 5px; resize: vertical;"
                                                            placeholder="Enter <?php echo esc_attr($lang); ?> translation"
                                                        ><?php echo esc_textarea($lang_translations[$lang] ?? ''); ?></textarea>
                                                    </td>
                                                <?php endforeach; ?>
                                                
                                                <td style="padding: 10px; vertical-align: top;">
                                                    <button type="submit" name="delete_key" 
                                                            onclick="return confirm('Are you sure you want to delete this translation key?')"
                                                            class="button button-link-delete" 
                                                            style="color: #a00;">
                                                        Delete
                                                    </button>
                                                    <input type="hidden" name="delete_category" value="<?php echo esc_attr($category); ?>">
                                                    <input type="hidden" name="delete_key" value="<?php echo esc_attr($key); ?>">
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                    <div style="text-align: center; padding: 20px 0;">
                        <input type="submit" name="save_translations" class="button button-primary button-large" 
                               value="Save All Translations" style="padding: 10px 30px; font-size: 16px;">
                    </div>
                    
                <?php endif; ?>
            </form>
            
            <!-- File Information -->
            <div class="postbox" style="margin-top: 30px;">
                <h2 class="hndle" style="padding: 10px 15px; margin: 0; background: #f1f1f1;">File Information</h2>
                <div class="inside" style="padding: 15px;">
                    <p><strong>File Path:</strong> <code><?php echo esc_html(get_translations_file_path()); ?></code></p>
                    <p><strong>File Status:</strong> 
                        <?php if (file_exists(get_translations_file_path())): ?>
                            <span style="color: green;">✓ File exists</span>
                        <?php else: ?>
                            <span style="color: orange;">⚠ File will be created on first save</span>
                        <?php endif; ?>
                    </p>
                    <p><strong>Available Languages:</strong> <?php echo implode(', ', array_map('strtoupper', $available_languages)); ?></p>
                    <p><small>Note: Languages are managed in the <a href="<?php echo admin_url('options-general.php?page=multilang-container'); ?>">Multilang Container Settings</a>.</small></p>
                </div>
            </div>
            
        </div>
        
        <style>
        .translations-manager .postbox h2.hndle {
            cursor: default;
        }
        
        .translations-manager table th {
            background: #f9f9f9;
            font-weight: 600;
        }
        
        .translations-manager textarea:focus {
            border-color: #0073aa;
            box-shadow: 0 0 0 1px #0073aa;
        }
        
        .translations-manager .button-link-delete {
            text-decoration: none;
            border: none;
            background: none;
            padding: 2px 5px;
            cursor: pointer;
            font-size: 12px;
        }
        
        .translations-manager .button-link-delete:hover {
            background: #ffebee;
            border-radius: 3px;
        }
        </style>
    </div>
    <?php
}

// Initialize the translations settings
function init_multilang_translations_settings() {
    // Only initialize if we're in admin area
    if (is_admin()) {
        // The functions are already defined above, just need to make sure the menu hook is added
    }
}
add_action('init', 'init_multilang_translations_settings');