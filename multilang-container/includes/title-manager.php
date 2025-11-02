<?php
/**
 * Multilang Container - Title Manager
 * 
 * Handles multilingual title metabox, title filters, and title functionality
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Add multilingual title metabox
 */
function multilang_title_add_metabox() {

	$languages = get_option('multilang_container_languages', array());
	if (empty($languages)) {
		return;
	}
	
	$post_types = array('post', 'page');
	foreach ($post_types as $post_type) {
		add_meta_box(
			'multilang_title_metabox',
			'Multilang Title',
			'multilang_title_metabox_callback',
			$post_type,
			'normal',
			'high'
		);
	}
}
add_action('add_meta_boxes', 'multilang_title_add_metabox');

/**
 * Multilang title metabox callback
 */
function multilang_title_metabox_callback($post) {

	wp_nonce_field('multilang_title_save', 'multilang_title_nonce');
	

	$languages = get_multilang_available_languages();
	

	$multilang_titles = get_post_meta($post->ID, '_multilang_titles', true);
	if (!is_array($multilang_titles)) {
		$multilang_titles = array();
	}
	

	$json_path = plugin_dir_path(dirname(__FILE__)) . '/data/languages-flags.json';
	$lang_flags = array();
	if (file_exists($json_path)) {
		$json = file_get_contents($json_path);
		$decoded = json_decode($json, true);
		if (is_array($decoded)) {
			$lang_flags = $decoded;
		}
	}
	

	$lang_lookup = array();
	$flag_lookup = array();
	if (is_array($lang_flags)) {
		foreach ($lang_flags as $lang) {
			if (is_array($lang) && isset($lang['code']) && isset($lang['name'])) {
				$lang_lookup[$lang['code']] = $lang['name'];
				if (isset($lang['flag'])) {
					$flag_lookup[$lang['code']] = $lang['flag'];
				}
			}
		}
	}
	
	$plugin_url = plugins_url('', dirname(__FILE__));
	
	echo '<div class="multilang-title-metabox">';
	echo '<div class="multilang-title-editor" style="padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 5px;">';
	
	// Language selector dropdown
	echo '<div class="multilang-language-selector" style="margin-bottom: 15px;">';
	echo '<label for="multilang_language_select" style="display: block; margin-bottom: 5px; font-weight: 600;">Select Language:</label>';
	echo '<select id="multilang_language_select" style="width: 250px; padding: 8px 8px 8px 40px; background-position: 8px center; background-repeat: no-repeat; background-size: 20px 20px;">';
	
	foreach ($languages as $lang_code) {
		$lang_name = isset($lang_lookup[$lang_code]) ? $lang_lookup[$lang_code] : strtoupper($lang_code);
		$flag_path = isset($flag_lookup[$lang_code]) ? $flag_lookup[$lang_code] : "img/flags/{$lang_code}.svg";
		
		// Fix flag filename to match actual files
		$flag_actual = multilang_fix_title_flag_filename($lang_code, $flag_path);
		$flag_url = $plugin_url . '/' . $flag_actual;
		
		echo '<option value="' . esc_attr($lang_code) . '" data-flag="' . esc_url($flag_url) . '">';
		echo esc_html($lang_name) . ' (' . esc_html(strtoupper($lang_code)) . ')';
		echo '</option>';
	}
	
	echo '</select>';
	echo '</div>';
	
	// Single title input field
	echo '<div class="multilang-title-input-section">';
	echo '<label for="multilang_current_title" style="display: block; margin-bottom: 5px; font-weight: 600;">Title Translation:</label>';
	echo '<input type="text" id="multilang_current_title" style="width: 100%; padding: 8px 12px; font-size: 14px;" placeholder="Enter title translation..." />';
	echo '</div>';
	
	// Status indicator
	echo '<div id="multilang_status" style="margin-top: 10px; padding: 8px; background: #fff; border: 1px solid #ddd; border-radius: 3px; font-size: 13px; color: #666;">';
	echo 'Select a language to edit its title translation.';
	echo '</div>';
	
	echo '</div>';
	
	// Hidden inputs to store all translations
	foreach ($languages as $lang_code) {
		$current_value = isset($multilang_titles[$lang_code]) ? $multilang_titles[$lang_code] : '';
		echo '<input type="hidden" id="multilang_hidden_' . esc_attr($lang_code) . '" name="multilang_titles[' . esc_attr($lang_code) . ']" value="' . esc_attr($current_value) . '" />';
	}
	
	echo '<div style="margin-top: 15px; padding: 10px; background: #f9f9f9; border-left: 4px solid #0073aa;">';
	echo '<p><strong>Usage:</strong> Use the function <code>multilang_get_title($post_id)</code> in your theme to display the translated title based on the current language.</p>';
	echo '<p><strong>Shortcode:</strong> Use <code>[multilang_title]</code> to display the current post\'s translated title.</p>';
	echo '</div>';
	
	echo '</div>';
	

	multilang_title_add_metabox_styles_and_scripts();
}

/**
 * Add CSS and JavaScript for title metabox
 */
function multilang_title_add_metabox_styles_and_scripts() {
	echo '<style>
	.multilang-title-metabox {
		font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
	}
	.multilang-title-editor {
		background: #f8f9fa !important;
		border: 1px solid #e1e5e9 !important;
		border-radius: 6px !important;
	}
	.multilang-title-metabox select {
		border: 1px solid #ddd;
		border-radius: 4px;
		padding: 8px 12px;
		font-size: 14px;
		background: white;
	}
	.multilang-title-metabox select:focus {
		border-color: #0073aa;
		box-shadow: 0 0 0 1px #0073aa;
		outline: none;
	}
	.multilang-title-metabox input[type="text"] {
		border: 1px solid #ddd;
		border-radius: 4px;
		font-size: 14px;
	}
	.multilang-title-metabox input[type="text"]:focus {
		border-color: #0073aa;
		box-shadow: 0 0 0 1px #0073aa;
		outline: none;
	}
	#multilang_status {
		font-style: italic;
	}
	#multilang_status.has-content {
		background: #e8f5e8 !important;
		border-color: #4caf50 !important;
		color: #2e7d32 !important;
	}
	#multilang_status.empty-content {
		background: #fff3e0 !important;
		border-color: #ff9800 !important;
		color: #f57c00 !important;
	}
	</style>
	
	<script type="text/javascript">
	jQuery(document).ready(function($) {
		var currentLang = "";
		var titleData = {};
		

		$("input[name^=\'multilang_titles[\']").each(function() {
			var match = $(this).attr("name").match(/multilang_titles\[([^\]]+)\]/);
			if (match) {
				titleData[match[1]] = $(this).val();
			}
		});
		

		function updateDisplay() {
			var selectedLang = $("#multilang_language_select").val();
			currentLang = selectedLang;
			

			var selectedOption = $("#multilang_language_select option:selected");
			var flagUrl = selectedOption.attr("data-flag");
			if (flagUrl) {
				$("#multilang_language_select").css("background-image", "url(" + flagUrl + ")");
			}
			

			var currentTitle = titleData[selectedLang] || "";
			$("#multilang_current_title").val(currentTitle);
			

			var statusEl = $("#multilang_status");
			var langName = selectedOption.text();
			
			if (currentTitle) {
				statusEl.removeClass("empty-content").addClass("has-content");
				statusEl.html("<strong>✓ Translation exists:</strong> " + langName + " - \"" + currentTitle + "\"");
			} else {
				statusEl.removeClass("has-content").addClass("empty-content");
				statusEl.html("<strong>○ No translation:</strong> " + langName + " - Enter a title translation above");
			}
		}
		

		function saveCurrentInput() {
			if (currentLang) {
				var inputValue = $("#multilang_current_title").val();
				titleData[currentLang] = inputValue;
				$("#multilang_hidden_" + currentLang).val(inputValue);
			}
		}
		
		// Language dropdown change
		$("#multilang_language_select").on("change", function() {
			saveCurrentInput();
			updateDisplay();
		});
		
		// Input field change
		$("#multilang_current_title").on("input", function() {
			if (currentLang) {
				titleData[currentLang] = $(this).val();
				$("#multilang_hidden_" + currentLang).val($(this).val());
				updateDisplay(); // Update status in real-time
			}
		});
		

		updateDisplay();
	});
	</script>';
}

/**
 * Save multilang title metabox
 */
function multilang_title_save_metabox($post_id) {

	if (!$post_id || !is_numeric($post_id)) {
		return;
	}
	
	// Check nonce
	if (!isset($_POST['multilang_title_nonce']) || !wp_verify_nonce($_POST['multilang_title_nonce'], 'multilang_title_save')) {
		return;
	}
	

	if (!current_user_can('edit_post', $post_id)) {
		return;
	}
	
	// Don't save on autosave
	if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
		return;
	}
	
	// Skip for revisions and auto-drafts
	if (wp_is_post_revision($post_id) || get_post_status($post_id) === 'auto-draft') {
		return;
	}
	

	if (isset($_POST['multilang_titles']) && is_array($_POST['multilang_titles'])) {
		$multilang_titles = array();
		foreach ($_POST['multilang_titles'] as $lang => $title) {
			$lang = sanitize_text_field($lang);
			$title = sanitize_text_field($title);
			if (!empty($title)) {
				$multilang_titles[$lang] = $title;
			}
		}
		
		if (!empty($multilang_titles)) {
			update_post_meta($post_id, '_multilang_titles', $multilang_titles);
		} else {
			delete_post_meta($post_id, '_multilang_titles');
		}
	}
}
add_action('save_post', 'multilang_title_save_metabox');

/**
 * Function to get translated title
 */
function multilang_get_title($post_id = null, $lang = null) {
	if ($post_id === null) {
		global $post;
		$post_id = $post ? $post->ID : 0;
	}
	

	if (!$post_id || !is_numeric($post_id)) {
		return '';
	}
	
	if ($lang === null) {
		$lang = isset($_COOKIE['lang']) ? sanitize_text_field($_COOKIE['lang']) : 'en';
	}
	
	// Sanitize language code
	$lang = sanitize_text_field($lang);
	
	try {
		$multilang_titles = get_post_meta($post_id, '_multilang_titles', true);
		

		if (is_array($multilang_titles) && isset($multilang_titles[$lang]) && !empty($multilang_titles[$lang])) {
			return $multilang_titles[$lang];
		}
		
		// Fallback to English if current language not found
		if ($lang !== 'en' && is_array($multilang_titles) && isset($multilang_titles['en']) && !empty($multilang_titles['en'])) {
			return $multilang_titles['en'];
		}
	} catch (Exception $e) {
		// If there's any error with meta data, fall back to original title
	}
	
	// Fallback to original WordPress title (remove filter temporarily to avoid recursion)
	remove_filter('the_title', 'multilang_filter_the_title', 10);
	$original_title = get_the_title($post_id);
	add_filter('the_title', 'multilang_filter_the_title', 10, 2);
	
	return $original_title;
}

/**
 * Shortcode for displaying translated title
 */
function multilang_title_shortcode($atts) {
	$atts = shortcode_atts(array(
		'post_id' => null,
		'lang' => null
	), $atts, 'multilang_title');
	
	return multilang_get_title($atts['post_id'], $atts['lang']);
}
add_shortcode('multilang_title', 'multilang_title_shortcode');

/**
 * Filter to automatically replace titles in frontend
 */
function multilang_filter_the_title($title, $post_id = null) {
	// Prevent infinite recursion
	static $processing = false;
	if ($processing) {
		return $title;
	}
	
	// Only filter on frontend, not in admin
	if (is_admin()) {
		return $title;
	}
	
	// Skip if no post ID or if it's not a valid post
	if (!$post_id || !is_numeric($post_id)) {
		return $title;
	}
	
	// Skip for REST API requests to avoid JSON issues
	if (defined('REST_REQUEST') && REST_REQUEST) {
		return $title;
	}
	
	$processing = true;
	
	try {

		$lang = isset($_COOKIE['lang']) ? sanitize_text_field($_COOKIE['lang']) : 'en';
		

		$multilang_titles = get_post_meta($post_id, '_multilang_titles', true);
		
		if (is_array($multilang_titles)) {

			if (isset($multilang_titles[$lang]) && !empty($multilang_titles[$lang])) {
				$processing = false;
				return $multilang_titles[$lang];
			}
			
			// Fallback to English if current language not found
			if ($lang !== 'en' && isset($multilang_titles['en']) && !empty($multilang_titles['en'])) {
				$processing = false;
				return $multilang_titles['en'];
			}
		}
	} catch (Exception $e) {
		// If there's any error, just return the original title
	}
	
	$processing = false;
	return $title;
}
add_filter('the_title', 'multilang_filter_the_title', 10, 2);

/**
 * Add classes to title elements for easier JavaScript targeting
 */
function multilang_add_title_classes($content) {
	// Only on frontend and for singular posts/pages
	if (is_admin() || !is_singular()) {
		return $content;
	}
	
	global $post;
	if (!$post) {
		return $content;
	}
	

	$multilang_titles = get_post_meta($post->ID, '_multilang_titles', true);
	if (!is_array($multilang_titles) || empty($multilang_titles)) {
		return $content;
	}
	

	$patterns = array(
		'/<h1([^>]*class="[^"]*entry-title[^"]*"[^>]*)>/i' => '<h1$1 data-multilang-title="true">',
		'/<h1([^>]*class="[^"]*page-title[^"]*"[^>]*)>/i' => '<h1$1 data-multilang-title="true">',
		'/<h1([^>]*class="[^"]*post-title[^"]*"[^>]*)>/i' => '<h1$1 data-multilang-title="true">',
		'/<h1([^>]*class="[^"]*wp-block-post-title[^"]*"[^>]*)>/i' => '<h1$1 data-multilang-title="true">',
	);
	
	foreach ($patterns as $pattern => $replacement) {
		$content = preg_replace($pattern, $replacement, $content);
	}
	
	return $content;
}

/**
 * Add title data to page head for JavaScript access
 */
function multilang_add_title_data_to_head() {
	if (!is_singular()) {
		return;
	}
	
	global $post;
	if (!$post) {
		return;
	}
	
	$multilang_titles = get_post_meta($post->ID, '_multilang_titles', true);
	if (!is_array($multilang_titles) || empty($multilang_titles)) {
		return;
	}
	
	// Temporarily remove the title filter to get original title without recursion
	remove_filter('the_title', 'multilang_filter_the_title', 10);
	$original_title = get_the_title($post->ID);
	add_filter('the_title', 'multilang_filter_the_title', 10, 2);
	

	$multilang_titles['original'] = $original_title;
	
	echo '<script type="text/javascript">';
	echo 'window.multilangPageTitles = ' . wp_json_encode($multilang_titles, JSON_HEX_TAG | JSON_HEX_AMP) . ';';
	echo '</script>' . "\n";
}
add_action('wp_head', 'multilang_add_title_data_to_head', 999);

/**
 * Fix flag filename for special cases in title manager
 */
function multilang_fix_title_flag_filename($lang_code, $flag_path) {
	$flag_actual = $flag_path;
	if ($lang_code === 'zh') $flag_actual = 'img/flags/cn.svg';
	if ($lang_code === 'ja') $flag_actual = 'img/flags/jp.svg';
	if ($lang_code === 'ko') $flag_actual = 'img/flags/kr.svg';
	if ($lang_code === 'he') $flag_actual = 'img/flags/il.svg';
	if ($lang_code === 'uk') $flag_actual = 'img/flags/ua.svg';
	if ($lang_code === 'ar') $flag_actual = 'img/flags/sa.svg';
	if ($lang_code === 'sv') $flag_actual = 'img/flags/se.svg';
	if ($lang_code === 'da') $flag_actual = 'img/flags/dk.svg';
	if ($lang_code === 'cs') $flag_actual = 'img/flags/cz.svg';
	if ($lang_code === 'el') $flag_actual = 'img/flags/gr.svg';
	return $flag_actual;
}