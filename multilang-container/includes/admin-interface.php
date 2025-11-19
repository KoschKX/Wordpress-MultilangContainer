<?php
/**
 * Multilang Container - Admin Interface
 * 
 * Handles settings page, language configuration, and admin UI
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// AJAX handler for saving languages
add_action('wp_ajax_save_languages_ajax', 'multilang_save_languages_ajax');
function multilang_save_languages_ajax() {
	// Check nonce
	if (!isset($_POST['multilang_container_nonce']) || !wp_verify_nonce($_POST['multilang_container_nonce'], 'multilang_container_settings')) {
		wp_send_json_error(['message' => 'Invalid nonce']);
	}

	$languages = isset($_POST['multilang_languages']) ? array_map('sanitize_text_field', $_POST['multilang_languages']) : [];
	update_option('multilang_container_languages', $languages);

	$default_lang = isset($_POST['multilang_default_language']) ? sanitize_text_field($_POST['multilang_default_language']) : '';
	update_option('multilang_container_default_language', $default_lang);

	$exclude_selectors = isset($_POST['multilang_exclude_selectors']) ? sanitize_text_field($_POST['multilang_exclude_selectors']) : '';
	update_option('multilang_container_exclude_selectors', $exclude_selectors);

	// Regenerate CSS file
	$css_file_path = function_exists('get_switchcss_file_path') ? get_switchcss_file_path() : '';
	$css_content = function_exists('multilang_generate_css') ? multilang_generate_css($languages) : '';
	$result = ($css_file_path && $css_content) ? file_put_contents($css_file_path, $css_content, LOCK_EX) : false;

	if (function_exists('multliang_copy_flag_images')) {
		multliang_copy_flag_images($languages);
	}

	if ($result !== false) {
		wp_send_json_success(['message' => 'Settings saved.']);
	} else {
		wp_send_json_error(['message' => 'Settings saved but CSS regeneration failed!']);
	}
}

/**
 * Admin settings page for languages
 */
function multilang_container_admin_menu() {
	add_options_page(
		'Multilang Container Settings',
		'Multilang Container',
		'manage_options',
		'multilang-container',
		'multilang_container_settings_page'
	);
}
add_action('admin_menu', 'multilang_container_admin_menu');

/**
 * Main settings page function
 */
function multilang_container_settings_page() {
	// Initialize variables
	$translation_message = '';
	$translation_message_type = 'updated';
	$translations = load_translations();
	$available_languages = get_option('multilang_container_languages', array('en'));
	
	// Handle tab switching
	$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'languages';
	
	// Load all available languages from JSON
	$json_path = plugin_dir_path(dirname(__FILE__)) . '/data/languages-flags.json';
	$lang_flags = array();
	if (file_exists($json_path)) {
		$json = file_get_contents($json_path);
		$lang_flags = json_decode($json, true);
	}
	
	// Handle form submission - simplified to match original
	if (isset($_POST['multilang_languages']) && check_admin_referer('multilang_container_settings', 'multilang_container_nonce')) {
		$languages = array_map('sanitize_text_field', $_POST['multilang_languages']);
		update_option('multilang_container_languages', $languages);
		
		$default_lang = sanitize_text_field($_POST['multilang_default_language']);
		update_option('multilang_container_default_language', $default_lang);
		
		$exclude_selectors = sanitize_text_field($_POST['multilang_exclude_selectors']);
		update_option('multilang_container_exclude_selectors', $exclude_selectors);
		
		// Regenerate CSS file when settings change - FORCED VERSION
		$css_file_path = get_switchcss_file_path();
		$css_content = multilang_generate_css($languages);
		$result = file_put_contents($css_file_path, $css_content, LOCK_EX);

		multliang_copy_flag_images($languages);
		
		if ($result !== false) {
			echo '<div class="updated"><p>Settings saved.</p></div>';
		} else {
			echo '<div class="error"><p>Settings saved but CSS regeneration failed!</p></div>';
		}
	}
	
	// Handle translation form submissions - simplified to match original
	if (isset($_POST['save_translations']) && check_admin_referer('save_translations', 'translations_nonce')) {

		// Handle section order if provided (will be restored later)
		if (isset($_POST['section_order']) && !empty($_POST['section_order'])) {
			$section_order = json_decode(stripslashes($_POST['section_order']), true);
			if (is_array($section_order)) {
				save_section_order($section_order);
			}
		}
		
		// Build list of keys marked for deletion
		$deleted_keys = array();
		if (isset($_POST['delete_keys']) && is_array($_POST['delete_keys'])) {
			foreach ($_POST['delete_keys'] as $key_data) {
				$parts = explode('|', $key_data, 2);
				if (count($parts) === 2) {
					$category = trim($parts[0]);
					$key = trim($parts[1]);
					$deleted_keys[$category][] = $key;
				}
			}
		}
		
		// Handle key name changes
		$key_renames = array();
		if (isset($_POST['key_names']) && is_array($_POST['key_names'])) {
			foreach ($_POST['key_names'] as $category => $key_names) {
				foreach ($key_names as $old_key => $new_key) {
					$old_key = stripslashes($old_key);
					$new_key = stripslashes($new_key);
					if ($old_key !== $new_key && !empty($new_key)) {
						$key_renames[$category][$old_key] = $new_key;
					}
				}
			}
		}
		
		// Key orders are now handled directly in the translation saving logic		// Handle add key to category
		if (isset($_POST['add_key_to_category'])) {
			$parts = explode('|', $_POST['add_key_to_category'], 2);
			if (count($parts) === 2) {
				$category = trim($parts[0]);
				$key = trim($parts[1]); // Use trim instead of sanitize_text_field to preserve exact key format
				$translations = load_translations();
				$translations = add_translation_key($category, $key, $translations);
				save_translations($translations);
				$translation_message = "Added new key '$key' to section '$category'";
				$translation_message_type = 'updated';
			}
		}
		
		// Handle section and key deletions
		if (isset($_POST['delete_sections']) && is_array($_POST['delete_sections'])) {
			foreach ($_POST['delete_sections'] as $section) {
				delete_translation_section(trim($section)); // Preserve exact section name format
			}
		}
		
		// Key deletion is now handled in the translation saving logic below
		
		// Load translations once
		$translations = load_translations();
		$sections_created = 0;
		
		// Process selectors first to create any new sections
		if (isset($_POST['selectors'])) {
			foreach ($_POST['selectors'] as $category => $selectors_text) {
				$category = trim($category); // Preserve exact category format
				$selectors_text = trim($selectors_text); // Only trim, don't sanitize
				// Split by commas and clean up
				$selectors_array = array_filter(array_map('trim', explode(',', $selectors_text)));
				if (empty($selectors_array)) {
					$selectors_array = array('body'); // Default fallback
				}
				
				// Create section if it doesn't exist
				if (!isset($translations[$category])) {
					$translations[$category] = array();
					$sections_created++;
				}
				
				// Update selectors
				$translations[$category]['_selectors'] = $selectors_array;
			}
		}
		
		// Process section translation methods - save to structure.json
		if (isset($_POST['section_methods'])) {
			foreach ($_POST['section_methods'] as $category => $method) {
				$category = sanitize_text_field($category);
				$method = sanitize_text_field($method);
				if (in_array($method, array('javascript', 'server'))) {
					// Update the translations array with the new method
					if (!isset($translations[$category])) {
						$translations[$category] = array();
					}
					$translations[$category]['_method'] = $method;
				}
			}
		}
		
		// Save translations
		if (isset($_POST['translations'])) {
			foreach ($_POST['translations'] as $category => $keys) {
				$category = trim($category); // Preserve exact category format
				
				// Get the key order for this category if it exists
				$ordered_keys = array();
				if (isset($_POST['key_orders'][$category])) {
					$key_order = json_decode(stripslashes($_POST['key_orders'][$category]), true);
					if (is_array($key_order)) {
						$ordered_keys = $key_order;
					}
				}
				
				// Clear existing translations for this category to rebuild in correct order
				if (isset($translations[$category])) {
					// Preserve non-translation data (like _method)
					$preserved_data = array();
					foreach ($translations[$category] as $key => $value) {
						if (substr($key, 0, 1) === '_') {
							$preserved_data[$key] = $value;
						}
					}
					$translations[$category] = $preserved_data;
				}
				
				// If we have a custom order, process keys in that order
				if (!empty($ordered_keys)) {
					// Process keys in the saved order
					foreach ($ordered_keys as $key) {
						if (isset($keys[$key])) {
							$original_key = $key; // Preserve exact key format
							
							// Skip if this key is marked for deletion
							if (isset($deleted_keys[$category]) && in_array($original_key, $deleted_keys[$category])) {
								continue;
							}
							
							// Check if this key has been renamed
							$final_key = $original_key;
							if (isset($key_renames[$category][$original_key])) {
								$final_key = $key_renames[$category][$original_key];
							}
							
							foreach ($keys[$key] as $lang => $translation) {
								$lang = trim($lang); // Preserve exact language code
								$translations[$category][$final_key][$lang] = stripslashes($translation);
							}
						}
					}
					// Then process any remaining keys not in the order
					foreach ($keys as $key => $lang_translations) {
						if (!in_array($key, $ordered_keys)) {
							$original_key = $key; // Preserve exact key format
							
							// Skip if this key is marked for deletion
							if (isset($deleted_keys[$category]) && in_array($original_key, $deleted_keys[$category])) {
								continue;
							}
							
							// Check if this key has been renamed
							$final_key = $original_key;
							if (isset($key_renames[$category][$original_key])) {
								$final_key = $key_renames[$category][$original_key];
							}
							
							foreach ($lang_translations as $lang => $translation) {
								$lang = trim($lang); // Preserve exact language code
								$translations[$category][$final_key][$lang] = stripslashes($translation);
							}
						}
					}
				} else {
					// No custom order, process normally
					foreach ($keys as $key => $lang_translations) {
						$original_key = $key; // Preserve exact key format
						
						// Skip if this key is marked for deletion
						if (isset($deleted_keys[$category]) && in_array($original_key, $deleted_keys[$category])) {
							continue;
						}
						
						// Check if this key has been renamed
						$final_key = $original_key;
						if (isset($key_renames[$category][$original_key])) {
							$final_key = $key_renames[$category][$original_key];
						}
						
						foreach ($lang_translations as $lang => $translation) {
							$lang = trim($lang); // Preserve exact language code
							$translations[$category][$final_key][$lang] = stripslashes($translation);
						}
					}
				}
			}
		}
		
		// Save everything once
		save_translations($translations);
		
		if ($sections_created > 0) {
			$translation_message = 'Translations saved successfully! Created ' . $sections_created . ' new section(s).';
		} else {
			$translation_message = 'Translations saved successfully!';
		}
		$translation_message_type = 'updated';
		
		// Reload translations to show new sections
		$translations = load_translations();
	}

	
	// Get languages, defaulting to English only if nothing is saved
	$langs = get_option('multilang_container_languages', false);
	if ($langs === false) {
		// First time setup - default to English only
		$langs = array('en');
		update_option('multilang_container_languages', $langs);
	}
	$plugin_url = plugins_url('', dirname(__FILE__));
	
	// Update available languages with current settings
	$available_languages = $langs;
	
	// Render the actual UI
	multilang_render_admin_ui($active_tab, $lang_flags, $langs, $plugin_url, $translation_message, $translation_message_type, $translations, $available_languages);
}

/**
 * Render the admin UI
 */
function multilang_render_admin_ui($active_tab, $lang_flags, $langs, $plugin_url, $translation_message, $translation_message_type, $translations, $available_languages) {
	
	multilang_admin_ui_settings_resources();
	
	echo '<div class="wrap" style="max-width:1200px;margin:auto;">';
	echo '<h1 style="margin-bottom:1.5em;">Multilang Container Settings</h1>';
	
	// Tab navigation
	echo '<h2 class="nav-tab-wrapper" style="margin-bottom: 20px;">';
	echo '<a href="#" class="nav-tab ' . ($active_tab == 'languages' ? 'nav-tab-active' : '') . '" data-tab="languages">Languages</a>';
	echo '<a href="#" class="nav-tab ' . ($active_tab == 'translations' ? 'nav-tab-active' : '') . '" data-tab="translations">Translation Manager</a>';
	echo '<a href="#" class="nav-tab ' . ($active_tab == 'misc' ? 'nav-tab-active' : '') . '" data-tab="misc">Options</a>';
	echo '</h2>';
	
	// Show translation messages
	if (!empty($translation_message)) {
		echo '<div class="notice notice-' . esc_attr($translation_message_type) . ' is-dismissible">';
		echo '<p>' . esc_html($translation_message) . '</p>';
		echo '</div>';
	}
	
	// Languages tab
	multilang_render_languages_tab($active_tab, $lang_flags, $langs, $plugin_url);
	
	// Translations tab
	multilang_render_translations_tab($active_tab, $translations, $available_languages);
	
	// Options tab
	multilang_render_options_tab($active_tab);
	
	echo '</div>';
}



/**
 * Resources for admin UI
 */
function multilang_admin_ui_settings_resources() {
    wp_enqueue_style(
        'multilang-ui-admin',
        plugins_url('/admin/css/ui-admin.css', dirname(__FILE__)),
        array(),
        true
    );
	wp_enqueue_script(
		'multilang-ui-admin',
		plugins_url('/admin/js/ui-admin.js', dirname(__FILE__)),
		[],
		null,
		true
	);

	/*
	wp_localize_script(
		'multilang-admin-translations-tab'
       
		'multilangVars',
		[
			'langData'   => $lang_flags,
			'pluginUrl'  => $plugin_url
		]
       
	);
	 */

}

require_once plugin_dir_path(dirname(__FILE__)) . 'admin/tab-language-settings.php';
require_once plugin_dir_path(dirname(__FILE__)) . 'admin/tab-translations-manager.php';
require_once plugin_dir_path(dirname(__FILE__)) . 'admin/utilities-admin.php';