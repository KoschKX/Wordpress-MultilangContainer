<?php
/**
 * Debug script for translation issues
 * Add this to wp-config.php: define('WP_DEBUG', true); define('WP_DEBUG_LOG', true);
 */


add_action('wp_footer', function() {
    if (current_user_can('administrator')) {
        echo "<!-- TRANSLATION DEBUG INFO -->\n";
        echo "<!-- Available languages: " . implode(', ', get_multilang_available_languages()) . " -->\n";
        echo "<!-- Default language: " . get_multilang_default_language() . " -->\n";
        

        $available_langs = get_multilang_available_languages();
        foreach ($available_langs as $lang) {
            $lang_file = get_language_file_path($lang);
            $exists = file_exists($lang_file) ? 'YES' : 'NO';
            $readable = is_readable($lang_file) ? 'YES' : 'NO';
            echo "<!-- Language file $lang: exists=$exists, readable=$readable, path=$lang_file -->\n";
            
            if (file_exists($lang_file)) {
                $content = file_get_contents($lang_file);
                $data = json_decode($content, true);
                $valid_json = json_last_error() === JSON_ERROR_NONE ? 'YES' : 'NO';
                $categories = is_array($data) ? count($data) : 0;
                echo "<!-- Language file $lang: valid_json=$valid_json, categories=$categories -->\n";
            }
        }
        
        // Test server-side translation is enabled
        $server_side_enabled = get_option('multilang_container_server_side_translation', true);
        echo "<!-- Server-side translation enabled: " . ($server_side_enabled ? 'YES' : 'NO') . " -->\n";
        

        $current_lang_cookie = isset($_COOKIE['lang']) ? $_COOKIE['lang'] : 'not set';
        echo "<!-- Current language cookie: $current_lang_cookie -->\n";
        
        // Test a simple translation
        $test_translation = multilang_translate_text('Search', 
            multilang_get_language_data('de'), 
            multilang_get_language_data('en'), 
            'de', 'en');
        echo "<!-- Test translation 'Search' -> '$test_translation' -->\n";
    }
});