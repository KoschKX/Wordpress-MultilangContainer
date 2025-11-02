<?php
/**
 * Demo file showing how to use the Translation Manager
 * 
 * This file demonstrates various ways to use translations from the JSON file
 * in your WordPress theme or plugin.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Example 1: Using the PHP helper function
 * This function is available anywhere in WordPress after the plugin loads
 */
function demo_php_translations() {
    echo '<h3>PHP Translation Examples:</h3>';
    

    echo '<p>UI Submit Button: ' . multilang_get_translation('Submit', 'ui') . '</p>';
    echo '<p>Footer Contact: ' . multilang_get_translation('Contact', 'footer') . '</p>';
    echo '<p>Calendar January: ' . multilang_get_translation('January', 'calendar') . '</p>';
    

    echo '<p>Search anywhere: ' . multilang_get_translation('Next') . '</p>';
    

    echo '<p>Submit in German: ' . multilang_get_translation('Submit', 'ui', 'de') . '</p>';
    echo '<p>Contact in Italian: ' . multilang_get_translation('Contact', 'footer', 'it') . '</p>';
}

/**
 * Example 2: Using translations in JavaScript
 * The translations are available in the global multilangLangBar.translations object
 */
function demo_javascript_translations() {
    ?>
    <script>
    // Wait for the page to load
    document.addEventListener('DOMContentLoaded', function() {

        if (typeof multilangLangBar !== 'undefined' && multilangLangBar.translations) {
            // console.log('All translations:', multilangLangBar.translations);
            

            const currentLang = document.documentElement.getAttribute('data-lang') || 'en';
            
            // Function to get translation in JavaScript
            function getTranslation(key, category, lang = currentLang) {
                const translations = multilangLangBar.translations;
                
                // If category specified, look there first
                if (category && translations[category] && translations[category][key] && translations[category][key][lang]) {
                    return translations[category][key][lang];
                }
                
                // Search all categories if no category specified
                if (!category) {
                    for (const cat in translations) {
                        if (translations[cat][key] && translations[cat][key][lang]) {
                            return translations[cat][key][lang];
                        }
                    }
                }
                
                // Fallback to English
                if (lang !== 'en') {
                    return getTranslation(key, category, 'en');
                }
                

                return key;
            }
            
            // Example usage
            // console.log('Submit button:', getTranslation('Submit', 'ui'));
            // console.log('Contact:', getTranslation('Contact', 'footer'));
            

            const submitButtons = document.querySelectorAll('input[type="submit"], button[type="submit"]');
            submitButtons.forEach(button => {
                if (button.value === 'Submit' || button.textContent === 'Submit') {
                    const translation = getTranslation('Submit', 'ui');
                    if (button.value) {
                        button.value = translation;
                    } else {
                        button.textContent = translation;
                    }
                }
            });
        }
    });
    </script>
    <?php
}

/**
 * Example 3: Shortcode for using translations in content
 */
function multilang_translation_shortcode($atts) {
    $atts = shortcode_atts(array(
        'key' => '',
        'category' => '',
        'lang' => ''
    ), $atts);
    
    if (empty($atts['key'])) {
        return '';
    }
    
    return multilang_get_translation($atts['key'], $atts['category'], $atts['lang']);
}
add_shortcode('translate', 'multilang_translation_shortcode');

/**
 * Example 4: Widget or theme integration
 */
function demo_theme_integration() {
    ?>
    <div class="multilang-demo">
        <h3>Theme Integration Examples:</h3>
        
        <!-- In your theme templates, you can use: -->
        <form>
            <input type="text" placeholder="<?php echo esc_attr(multilang_get_translation('Search...', 'footer')); ?>">
            <input type="submit" value="<?php echo esc_attr(multilang_get_translation('Search', 'footer')); ?>">
        </form>
        
        <footer>
            <h4><?php echo multilang_get_translation('Contact', 'footer'); ?></h4>
            <p>
                <?php echo multilang_get_translation('Phone', 'footer'); ?>: +1 234 567 890<br>
                <?php echo multilang_get_translation('Email', 'footer'); ?>: contact@example.com
            </p>
            
            <p>
                <?php echo multilang_get_translation('Copyright', 'footer'); ?> © 2025. 
                <?php echo multilang_get_translation('All Rights Reserved', 'footer'); ?>
            </p>
        </footer>
        
        <!-- Using shortcodes in content: -->
        <!-- [translate key="Submit" category="ui"] -->
        <!-- [translate key="Contact" category="footer" lang="de"] -->
    </div>
    <?php
}

// Hook to add the JavaScript example to the footer
add_action('wp_footer', 'demo_javascript_translations');

/**
 * Usage Instructions:
 * 
 * 1. PHP Function:
 *    multilang_get_translation($key, $category = null, $lang = null)
 *    - $key: The translation key (required)
 *    - $category: The category to search in (optional, searches all if not provided)
 *    - $lang: Language code (optional, uses current language from cookie)
 * 
 * 2. JavaScript:
 *    getTranslation(key, category, lang)
 *    - Same parameters as PHP function
 *    - Available after page load via multilangLangBar.translations
 * 
 * 3. Shortcode:
 *    [translate key="Submit" category="ui" lang="de"]
 *    - All attributes are optional except 'key'
 * 
 * 4. Direct JSON access:
 *    $translations = json_decode(file_get_contents(plugin_dir_path(__FILE__) . 'data/translations.json'), true);
 */
?>