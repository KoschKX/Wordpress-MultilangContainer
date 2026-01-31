<?php
/*
 * Multilang Container - Assets Handler
 * Handles loading CSS/JS and other frontend stuff
 */

// Block direct access
if (!defined('ABSPATH')) {
    exit;
}

// Optionally require cache-handler if needed

// CSS solution: hide all languages by default, show only the one matching the body class. Now with caching!
function multilang_inject_immediate_css() {
	// Don't run on admin, AJAX, or cron
	if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
		return;
	}
	
	// Extra check for backend operations
    if (multilang_is_backend_operation()) {
        return;
    }
	// Bail if cache-handler functions are missing
    if (!function_exists('multilang_get_cached_inline_css') || !function_exists('multilang_get_cached_inline_js')) {
        return;
    }
	// Grab cached CSS/JS or generate if needed
    $css = multilang_get_cached_inline_css();
    $js = multilang_get_cached_inline_js();
    
	// Add CSS/JS inline with WP functions
    wp_register_style('multilang-immediate-css', false);
    wp_enqueue_style('multilang-immediate-css');
    wp_add_inline_style('multilang-immediate-css', $css);
    
    wp_register_script('multilang-immediate-js', false);
    wp_enqueue_script('multilang-immediate-js');
    wp_add_inline_script('multilang-immediate-js', $js);
}
add_action( 'wp_enqueue_scripts', 'multilang_inject_immediate_css', 0 );

// Load CSS for the frontend and block editor

function multilang_container_enqueue_styles() {
	$css_path = get_switchcss_file_path();
	$css_url = '';
	// Convert absolute path to URL
	if (strpos($css_path, ABSPATH) === 0) {
		$css_url = site_url(str_replace(ABSPATH, '', $css_path));
	} else {
		// Fallback: use plugins_url if not in plugin dir
		$css_url = plugins_url('css/multilang-container.css', dirname(__FILE__));
	}
	wp_enqueue_style(
		'multilang-container-css',
		$css_url,
		array(),
		filemtime($css_path)
	);
	
	// Load extra CSS for excerpts
	$excerpts_css_path = plugin_dir_path(dirname(__FILE__)) . 'css/multilang-excerpts.css';
	if (file_exists($excerpts_css_path)) {
		$excerpts_css_url = plugins_url('css/multilang-excerpts.css', dirname(__FILE__));
		wp_enqueue_style(
			'multilang-excerpts-css',
			$excerpts_css_url,
			array(),
			filemtime($excerpts_css_path)
		);
	}
}
add_action('wp_enqueue_scripts', 'multilang_container_enqueue_styles', 0);
add_action('enqueue_block_editor_assets', 'multilang_container_enqueue_styles', 0);

// Load multilang-container.js on the frontend
function multilang_container_enqueue_scripts() {
	// Don't load scripts on admin, AJAX, or cron
    if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
        return;
    }
    
	// Extra backend check
    if (multilang_is_backend_operation()) {
        return;
    }
    
	// Figure out which translation method to use
	$translation_method = get_option('multilang_container_translation_method', 'javascript');
	
	// Backward compatibility: sync old server_side option if needed
	if (!get_option('multilang_container_translation_method') && get_option('multilang_container_server_side_translation') !== false) {
		$server_side_enabled = get_option('multilang_container_server_side_translation', false);
		$translation_method = $server_side_enabled ? 'server' : 'javascript';
		update_option('multilang_container_translation_method', $translation_method);
	}
	
	// Always enqueue the main script (needed for language switcher)
	wp_enqueue_script(
		'multilang-container-js',
		plugins_url('js/multilang-container.js', dirname(__FILE__)),
		array(),
		filemtime(plugin_dir_path(dirname(__FILE__)) . 'js/multilang-container.js'),
		true
	);
	
	// See if any section uses JS translation (check structure.json)
	$structure_data = function_exists('multilang_get_cached_structure_data') ? multilang_get_cached_structure_data() : false;
	$has_javascript_sections = false;
	if ($structure_data && is_array($structure_data)) {
		foreach ($structure_data as $section => $config) {
			$section_method = isset($config['_method']) ? $config['_method'] : 'server';
			if ($section_method === 'javascript') {
				$has_javascript_sections = true;
				break;
			}
		}
	}
	
	// If no structure data, use global setting
	if (!$has_javascript_sections) {
		$has_javascript_sections = ($translation_method === 'javascript');
	}
	
	// Only enqueue translation script if needed
	if ($has_javascript_sections) {
		// Hide JS-translated sections until they're ready

		$hide_css = '';
		if ($structure_data && is_array($structure_data)) {
			$selectors = array();
			foreach ($structure_data as $section => $config) {
				$section_method = isset($config['_method']) ? $config['_method'] : 'server';
				$is_disabled = isset($config['_disabled']) && $config['_disabled'];
				$section_pages = isset($config['_pages']) ? $config['_pages'] : '*';
				
				// Only add selectors for sections that apply to this page
				$applies_to_page = function_exists('multilang_should_apply_section') ? multilang_should_apply_section($section_pages) : true;
				
				if ($section_method === 'javascript' && !$is_disabled && $applies_to_page && isset($config['_selectors']) && is_array($config['_selectors'])) {
					$selectors = array_merge($selectors, $config['_selectors']);
				}
			}
			if (!empty($selectors)) {
				$selectors = array_unique($selectors);
				$hide_css = implode(', ', $selectors) . ' { visibility: hidden; }';
				wp_add_inline_style('multilang-container-css', $hide_css);
			}
		}
		
		wp_enqueue_script(
			'multilang-translate-js',
			plugins_url('js/multilang-translate.js', dirname(__FILE__)),
			array('multilang-container-js'),
			filemtime(plugin_dir_path(dirname(__FILE__)) . 'js/multilang-translate.js'),
			true
		);
	}
	
	// Language switcher script is handled elsewhere
	
	// Build the language bar HTML
	$langbar = multilang_generate_langbar();
	
	// Get translated page titles if on a single post/page
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
	
	// Get translated site taglines
	$page_taglines = array();
	$multilang_taglines = get_option('multilang_container_taglines', array());
	if (is_array($multilang_taglines) && !empty($multilang_taglines)) {
		$page_taglines = $multilang_taglines;
		// Add original tagline
		$page_taglines['original'] = get_bloginfo('description');
	}
	
	// Grab the site name
	$site_name = get_bloginfo('name');
	
	// Load each language file for the frontend
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
	
	// Get structure data for JS (with caching)
	$structure_data = function_exists('multilang_get_cached_structure_data') ? multilang_get_cached_structure_data() : false;
	
	// Set up data for JS localization
	$localized_data = array(
		'html' => $langbar,
		'pluginPath' => plugins_url('', dirname(__FILE__)),
		'translations' => array(), // Empty to prevent conflicts with new system
		'pageTitles' => $page_titles,
		'pageTaglines' => $page_taglines,
		'siteName' => $site_name,
		'ajaxUrl' => admin_url('admin-ajax.php'),
		'translationMethod' => $has_javascript_sections ? 'javascript' : 'server',
		'structureData' => $structure_data,
		'structureFileUrl' => admin_url('admin-ajax.php?action=get_structure_file')
	);
	
	// Only include language files if JS translation is used
	if ($has_javascript_sections) {
		$localized_data['languageFiles'] = $individual_lang_data;
	}
	
	wp_localize_script('multilang-container-js', 'multilangLangBar', $localized_data);
	
	// Note: enhance_translations_with_fallbacks removed (using individual files now)
	
	// Add inline script to set the default language
	$default_lang = get_multilang_default_language();
	$inline_script = 'window.translations = {}; // Disabled for individual file system
	window.defaultLanguage = "' . esc_js($default_lang) . '";';
	
	wp_add_inline_script('multilang-container-js', $inline_script, 'before');
}
add_action('wp_enqueue_scripts', 'multilang_container_enqueue_scripts', 0);


/*
 * AJAX endpoint for serving individual language files
 */
add_action('wp_ajax_get_language_file', 'multilang_get_language_file_ajax');
add_action('wp_ajax_nopriv_get_language_file', 'multilang_get_language_file_ajax');

function multilang_get_language_file_ajax() {
	// Make sure we got a language param
	$lang = isset($_GET['lang']) ? sanitize_text_field($_GET['lang']) : '';
	if (empty($lang)) {
		wp_send_json_error('Language parameter required');
		return;
	}
	
	// See if the language file exists
	$lang_file = get_language_file_path($lang);
	if (!file_exists($lang_file)) {
		wp_send_json_error('Language file not found for: ' . $lang);
		return;
	}
	
	// Load and parse the language file
	$lang_content = file_get_contents($lang_file);
	$lang_data = json_decode($lang_content, true);
	if (!$lang_data) {
		wp_send_json_error('Invalid language file format');
		return;
	}
	
	// Send back the language data
	wp_send_json_success($lang_data);
}