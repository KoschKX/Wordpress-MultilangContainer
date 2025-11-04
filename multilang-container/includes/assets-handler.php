<?php
/**
 * Multilang Container - Assets Handler
 * 
 * Handles CSS and JavaScript enqueuing, asset management, and frontend resource loading
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Pure CSS solution - hide all languages by default, show only the one with matching body class
 */
function multilang_inject_immediate_css() {
    // Don't load during backend operations
    if ( multilang_is_backend_operation() ) {
        return;
    }
    
    $available_langs = get_multilang_available_languages();
    $default_lang = get_multilang_default_language();
    $current_lang = $default_lang;
        
        // Check for cookie to determine initial language
        if ( isset($_COOKIE['lang']) ) {
            $cookie_lang = sanitize_text_field($_COOKIE['lang']);
            if ( in_array($cookie_lang, $available_langs) ) {
                $current_lang = $cookie_lang;
            }
        }
        
        // Build CSS string
        $css = '
        /* Hide all language variants by default */
        .translate .lang-en,
        .translate .lang-de, 
        .translate .lang-fr,
        .translate .lang-es,
        .translate .lang-it,
        .translate .lang-pt,
        .translate .lang-nl,
        .translate .lang-pl,
        .translate .lang-ru,
        .translate .lang-zh,
        .translate .lang-ja,
        .translate .lang-ko,
        .wp-block-multilang-container .lang-en,
        .wp-block-multilang-container .lang-de,
        .wp-block-multilang-container .lang-fr,
        .wp-block-multilang-container .lang-es,
        .wp-block-multilang-container .lang-it,
        .wp-block-multilang-container .lang-pt,
        .wp-block-multilang-container .lang-nl,
        .wp-block-multilang-container .lang-pl,
        .wp-block-multilang-container .lang-ru,
        .wp-block-multilang-container .lang-zh,
        .wp-block-multilang-container .lang-ja,
        .wp-block-multilang-container .lang-ko { 
            display: none !important; 
        }
        
        /* Show current language using html[data-lang] selector to match main CSS */
        html[data-lang="' . esc_attr($current_lang) . '"] .translate .lang-' . esc_attr($current_lang) . ',
        html[data-lang="' . esc_attr($current_lang) . '"] .wp-block-multilang-container .lang-' . esc_attr($current_lang) . ' { 
            display: block !important; 
        }';
        
        // Build JavaScript
        $js = '
        // Immediately check for language preference and update CSS
        (function() {
            // Helper function to get cookie value
            function getCookie(name) {
                var value = "; " + document.cookie;
                var parts = value.split("; " + name + "=");
                if (parts.length == 2) return parts.pop().split(";").shift();
                return null;
            }
            
            // Get language from cookie first, then localStorage, then default
            var cookieLang = getCookie("lang");
            var storageLang = localStorage.getItem("preferredLanguage");
            var savedLang = cookieLang || storageLang || "' . esc_js($default_lang) . '";
            var availableLangs = ' . json_encode($available_langs) . ';
            
            // Validate saved language
            if (availableLangs.indexOf(savedLang) === -1) {
                savedLang = "' . esc_js($default_lang) . '";
            }
            
            // Save to both localStorage and cookie
            localStorage.setItem("preferredLanguage", savedLang);
            document.cookie = "lang=" + savedLang + "; path=/; max-age=31536000; SameSite=Lax";
            
            // Update CSS rules immediately with html[data-lang] selector to match main CSS
            var style = document.getElementById("multilang-immediate-css");
            if (style) {
                var newCSS = "/* Hide all languages */ " +
                    ".translate .lang-en, .translate .lang-de, .translate .lang-fr, .translate .lang-es, .translate .lang-it, .translate .lang-pt, .translate .lang-nl, .translate .lang-pl, .translate .lang-ru, .translate .lang-zh, .translate .lang-ja, .translate .lang-ko, " +
                    ".wp-block-multilang-container .lang-en, .wp-block-multilang-container .lang-de, .wp-block-multilang-container .lang-fr, .wp-block-multilang-container .lang-es, .wp-block-multilang-container .lang-it, .wp-block-multilang-container .lang-pt, .wp-block-multilang-container .lang-nl, .wp-block-multilang-container .lang-pl, .wp-block-multilang-container .lang-ru, .wp-block-multilang-container .lang-zh, .wp-block-multilang-container .lang-ja, .wp-block-multilang-container .lang-ko " +
                    "{ display: none !important; } " +
                    "/* Show selected language using html[data-lang] selector */ " +
                    "html[data-lang=\"" + savedLang + "\"] .translate .lang-" + savedLang + ", html[data-lang=\"" + savedLang + "\"] .wp-block-multilang-container .lang-" + savedLang + " { display: block !important; }";
                style.textContent = newCSS;
            }
            
            // Set attributes
            var html = document.documentElement;
            
            if (html) {
                html.setAttribute("lang", savedLang);
                html.setAttribute("data-lang", savedLang);
            }
            
            // Wait for body to be available
            function setBodyAttrs() {
                var body = document.body;
                if (body) {
                    body.setAttribute("lang", savedLang);
                    body.setAttribute("data-lang", savedLang);
                    
                    // Remove all existing lang- classes
                    body.className = body.className.replace(/\\blang-[a-z]{2}\\b/g, "");
                    body.className += " lang-" + savedLang;
                } else {
                    setTimeout(setBodyAttrs, 1);
                }
            }
            setBodyAttrs();
            
            // Store current language globally for other scripts
            window.currentLanguage = savedLang;
        })();';
        
        // Use wp_add_inline_style and wp_add_inline_script
        wp_register_style('multilang-immediate-css', false);
        wp_enqueue_style('multilang-immediate-css');
        wp_add_inline_style('multilang-immediate-css', $css);
        
        wp_register_script('multilang-immediate-js', false);
        wp_enqueue_script('multilang-immediate-js');
        wp_add_inline_script('multilang-immediate-js', $js);
}
add_action( 'wp_enqueue_scripts', 'multilang_inject_immediate_css', 0 );

/**
 * Enqueue CSS for frontend and block editor
 */

function multilang_container_enqueue_styles() {
	$css_path = get_switchcss_file_path();
	$css_url = '';
	// Convert absolute path to URI
	if (strpos($css_path, ABSPATH) === 0) {
		$css_url = site_url(str_replace(ABSPATH, '', $css_path));
	} else {
		// fallback: use plugins_url if in plugin dir
		$css_url = plugins_url('css/multilang-container.css', dirname(__FILE__));
	}
	wp_enqueue_style(
		'multilang-container-css',
		$css_url,
		array(),
		filemtime($css_path)
	);
}
add_action('wp_enqueue_scripts', 'multilang_container_enqueue_styles', 0);
add_action('enqueue_block_editor_assets', 'multilang_container_enqueue_styles', 0);

/**
 * Enqueue multilang-container.js on the frontend
 */
function multilang_container_enqueue_scripts() {
    // Don't load during backend operations
    if ( multilang_is_backend_operation() ) {
        return;
    }
    
	// Check translation method setting
	$translation_method = get_option('multilang_container_translation_method', 'javascript');
	
	// Ensure backward compatibility - if server_side option exists but translation_method doesn't, sync them
	if (!get_option('multilang_container_translation_method') && get_option('multilang_container_server_side_translation') !== false) {
		$server_side_enabled = get_option('multilang_container_server_side_translation', false);
		$translation_method = $server_side_enabled ? 'server' : 'javascript';
		update_option('multilang_container_translation_method', $translation_method);
	}
	
	// Always enqueue main multilang script (for language switcher)
	wp_enqueue_script(
		'multilang-container-js',
		plugins_url('js/multilang-container.js', dirname(__FILE__)),
		array(),
		filemtime(plugin_dir_path(dirname(__FILE__)) . 'js/multilang-container.js'),
		true
	);
	
	// Check if any section uses JavaScript translation by reading structure.json
	$structure_file = get_structure_file_path();
	$has_javascript_sections = false;
	
	if (file_exists($structure_file)) {
		$structure_content = file_get_contents($structure_file);
		$structure_data = json_decode($structure_content, true);
		
		if ($structure_data && is_array($structure_data)) {
			foreach ($structure_data as $section => $config) {
				$section_method = isset($config['_method']) ? $config['_method'] : 'server';
				if ($section_method === 'javascript') {
					$has_javascript_sections = true;
					break;
				}
			}
		}
	}
	
	// Fallback to global setting if no structure data found
	if (!$has_javascript_sections) {
		$has_javascript_sections = ($translation_method === 'javascript');
	}
	
	// Conditionally enqueue translation script if any section uses JavaScript
	if ($has_javascript_sections) {
		wp_enqueue_script(
			'multilang-translate-js',
			plugins_url('js/multilang-translate.js', dirname(__FILE__)),
			array('multilang-container-js'),
			filemtime(plugin_dir_path(dirname(__FILE__)) . 'js/multilang-translate.js'),
			true
		);
	}
	
	// Language switcher script is now enqueued in language-switcher.php
	
	// Generate langbar HTML using dedicated handler
	$langbar = multilang_generate_langbar();
	
	// Get current page title translations if we're on a single page/post
	$page_titles = array();
	if (is_singular()) {
		global $post;
		if ($post && !empty($post->ID)) {
			$multilang_titles = get_post_meta($post->ID, '_multilang_titles', true);
			if (is_array($multilang_titles) && !empty($multilang_titles)) {
				$page_titles = $multilang_titles;
				
				// Safely get original title without triggering filters
				remove_filter('the_title', 'multilang_filter_the_title', 10);
				$original_title = get_the_title($post->ID);
				add_filter('the_title', 'multilang_filter_the_title', 10, 2);
				
				if (!empty($original_title)) {
					$page_titles['original'] = $original_title;
				}
			}
		}
	}
	
	// Load individual language files for frontend
	$languages = get_multilang_available_languages();
	$individual_lang_data = array();
	foreach ($languages as $lang) {
		$lang_file = get_language_file_path($lang);
		if (file_exists($lang_file)) {
			$lang_content = file_get_contents($lang_file);
			$lang_data = json_decode($lang_content, true);
			if ($lang_data) {
				$individual_lang_data[$lang] = $lang_data;
			}
		}
	}
	
	// Load structure data for JavaScript
	$structure_file = get_structure_file_path();
	$structure_data = array();
	if (file_exists($structure_file)) {
		$structure_content = file_get_contents($structure_file);
		$structure_data = json_decode($structure_content, true) ?: array();
	}
	
	// Prepare localized data
	$localized_data = array(
		'html' => $langbar,
		'pluginPath' => plugins_url('', dirname(__FILE__)),
		'translations' => array(), // Empty to prevent conflicts with new system
		'pageTitles' => $page_titles,
		'ajaxUrl' => admin_url('admin-ajax.php'),
		'translationMethod' => $has_javascript_sections ? 'javascript' : 'server',
		'structureData' => $structure_data,
		'structureFileUrl' => admin_url('admin-ajax.php?action=get_structure_file')
	);
	
	// Only include language files if JavaScript translation is enabled for any section
	if ($has_javascript_sections) {
		$localized_data['languageFiles'] = $individual_lang_data;
	}
	
	wp_localize_script('multilang-container-js', 'multilangLangBar', $localized_data);
	
	// Note: Removed enhance_translations_with_fallbacks call since $translations was undefined
	// and we're using individual language files now
	
	// Add inline script to set default language only
	$default_lang = get_multilang_default_language();
	$inline_script = 'window.translations = {}; // Disabled for individual file system
	window.defaultLanguage = "' . esc_js($default_lang) . '";';
	
	wp_add_inline_script('multilang-container-js', $inline_script, 'before');
}
add_action('wp_enqueue_scripts', 'multilang_container_enqueue_scripts', 0);


/**
 * AJAX endpoint to serve individual language files
 */
add_action('wp_ajax_get_language_file', 'multilang_get_language_file_ajax');
add_action('wp_ajax_nopriv_get_language_file', 'multilang_get_language_file_ajax');

function multilang_get_language_file_ajax() {
	// Verify we have the language parameter
	$lang = isset($_GET['lang']) ? sanitize_text_field($_GET['lang']) : '';
	if (empty($lang)) {
		wp_send_json_error('Language parameter required');
		return;
	}
	
	// Check if language file exists
	$lang_file = get_language_file_path($lang);
	if (!file_exists($lang_file)) {
		wp_send_json_error('Language file not found for: ' . $lang);
		return;
	}
	
	// Load and parse language file
	$lang_content = file_get_contents($lang_file);
	$lang_data = json_decode($lang_content, true);
	if (!$lang_data) {
		wp_send_json_error('Invalid language file format');
		return;
	}
	
	// Return the language data
	wp_send_json_success($lang_data);
}