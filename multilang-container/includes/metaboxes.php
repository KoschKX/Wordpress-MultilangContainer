<?php

if (!defined('ABSPATH')) {
    exit;
}

function multilang_add_metabox() {
	$languages = get_option('multilang_container_languages', array());
	if (empty($languages)) {
		return;
	}
	
	$post_types = array('post', 'page');
	foreach ($post_types as $post_type) {
		// Add Multilang Translations metabox to posts and pages
		add_meta_box(
			'multilang_metabox',
			'Multilang Translations',
			'multilang_metabox_callback',
			$post_type,
			'normal',
			'high'
		);
	}
}
add_action('add_meta_boxes', 'multilang_add_metabox');

function multilang_metabox_callback($post) {
	wp_nonce_field('multilang_save', 'multilang_nonce');
	
	$languages = get_multilang_available_languages();
	
	$multilang_titles = get_post_meta($post->ID, '_multilang_titles', true);
	if (!is_array($multilang_titles)) {
		$multilang_titles = array();
	}
	
	$multilang_excerpts = get_post_meta($post->ID, '_multilang_excerpts', true);
	if (!is_array($multilang_excerpts)) {
		$multilang_excerpts = array();
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
	
	echo '<div class="multilang-metabox">';
	echo '<div class="multilang-editor" style="padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 5px;">';
	
	echo '<div class="multilang-language-selector" style="margin-bottom: 15px;">';
	echo '<label for="multilang_language_select" style="display: block; margin-bottom: 5px; font-weight: 600;">Select Language:</label>';
	echo '<select id="multilang_language_select" style="width: 250px; padding: 8px 8px 8px 40px; background-position: 8px center; background-repeat: no-repeat; background-size: 20px 20px;">';
	
	foreach ($languages as $lang_code) {
		$lang_name = isset($lang_lookup[$lang_code]) ? $lang_lookup[$lang_code] : strtoupper($lang_code);
		$flag_path = isset($flag_lookup[$lang_code]) ? $flag_lookup[$lang_code] : "img/flags/{$lang_code}.svg";
		
		$flag_actual = multilang_fix_title_flag_filename($lang_code, $flag_path);
		$flag_url = $plugin_url . '/' . $flag_actual;
		
		echo '<option value="' . esc_attr($lang_code) . '" data-flag="' . esc_url($flag_url) . '">';
		echo esc_html($lang_name) . ' (' . esc_html(strtoupper($lang_code)) . ')';
		echo '</option>';
	}
	
	echo '</select>';
	echo '</div>';
	
	echo '<div class="multilang-input-section" style="margin-bottom: 15px;">';
	echo '<label for="multilang_current_title" style="display: block; margin-bottom: 5px; font-weight: 600;">Title Translation:</label>';
	echo '<input type="text" id="multilang_current_title" style="width: 100%; padding: 8px 12px; font-size: 14px;" placeholder="Enter title translation..." />';
	echo '</div>';
	
	echo '<div class="multilang-input-section">';
	echo '<label for="multilang_current_excerpt" style="display: block; margin-bottom: 5px; font-weight: 600;">Excerpt Translation:</label>';
	echo '<textarea id="multilang_current_excerpt" rows="4" style="width: 100%; padding: 8px 12px; font-size: 14px;" placeholder="Enter excerpt translation..."></textarea>';
	echo '</div>';
	
	echo '<div id="multilang_status" style="margin-top: 10px; padding: 8px; background: #fff; border: 1px solid #ddd; border-radius: 3px; font-size: 13px; color: #666;">';
	echo 'Select a language to edit its title translation.';
	echo '</div>';
	
	echo '</div>';
	
	foreach ($languages as $lang_code) {
		$title_value = isset($multilang_titles[$lang_code]) ? $multilang_titles[$lang_code] : '';
		echo '<input type="hidden" id="multilang_hidden_title_' . esc_attr($lang_code) . '" name="multilang_titles[' . esc_attr($lang_code) . ']" value="' . esc_attr($title_value) . '" />';
		
		$excerpt_value = isset($multilang_excerpts[$lang_code]) ? $multilang_excerpts[$lang_code] : '';
		echo '<input type="hidden" id="multilang_hidden_excerpt_' . esc_attr($lang_code) . '" name="multilang_excerpts[' . esc_attr($lang_code) . ']" value="' . esc_attr($excerpt_value) . '" />';
	}
	
	echo '<div style="margin-top: 15px; padding: 10px; background: #f9f9f9; border-left: 4px solid #0073aa;">';
	echo '<p><strong>Title Usage:</strong> Use <code>multilang_get_title($post_id)</code> or shortcode <code>[multilang_title]</code></p>';
	echo '<p><strong>Excerpt Usage:</strong> Use <code>multilang_get_excerpt($post_id)</code> or shortcode <code>[multilang_excerpt]</code></p>';
	echo '</div>';
	
	echo '</div>';
	
	multilang_add_metabox_styles_and_scripts();
}

function multilang_add_metabox_styles_and_scripts() {
	echo '<style>
	/* Force all metabox elements to be visible */
	#multilang_metabox,
	.multilang-metabox,
	.multilang-editor,
	.multilang-language-selector,
	.multilang-input-section {
		display: block !important;
	}
	.multilang-metabox {
		font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
	}
	.multilang-editor {
		background: #f8f9fa !important;
		border: 1px solid #e1e5e9 !important;
		border-radius: 6px !important;
	}
	.multilang-metabox select {
		border: 1px solid #ddd;
		border-radius: 4px;
		padding: 8px 12px;
		font-size: 14px;
		background: white;
	}
	.multilang-metabox select:focus {
		border-color: #0073aa;
		box-shadow: 0 0 0 1px #0073aa;
		outline: none;
	}
	.multilang-metabox input[type="text"],
	.multilang-metabox textarea {
		border: 1px solid #ddd;
		border-radius: 4px;
		font-size: 14px;
		font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
	}
	.multilang-metabox input[type="text"]:focus,
	.multilang-metabox textarea:focus {
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
		var excerptData = {};
		
		$("input[name^=\'multilang_titles[\']").each(function() {
			var match = $(this).attr("name").match(/multilang_titles\[([^\]]+)\]/);
			if (match) {
				titleData[match[1]] = $(this).val();
			}
		});
		
		$("input[name^=\'multilang_excerpts[\']").each(function() {
			var match = $(this).attr("name").match(/multilang_excerpts\[([^\]]+)\]/);
			if (match) {
				excerptData[match[1]] = $(this).val();
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
			
			var currentExcerpt = excerptData[selectedLang] || "";
			$("#multilang_current_excerpt").val(currentExcerpt);
			
			var statusEl = $("#multilang_status");
			var langName = selectedOption.text();
			
			if (currentTitle || currentExcerpt) {
				statusEl.removeClass("empty-content").addClass("has-content");
				var parts = [];
				if (currentTitle) parts.push("Title");
				if (currentExcerpt) parts.push("Excerpt");
				statusEl.html("<strong>✓ Translation exists:</strong> " + langName + " - " + parts.join(" & "));
			} else {
				statusEl.removeClass("has-content").addClass("empty-content");
				statusEl.html("<strong>○ No translation:</strong> " + langName + " - Enter translations above");
			}
		}
		
		function saveCurrentInput() {
			if (currentLang) {
				var titleValue = $("#multilang_current_title").val();
				titleData[currentLang] = titleValue;
				$("#multilang_hidden_title_" + currentLang).val(titleValue);
				
				var excerptValue = $("#multilang_current_excerpt").val();
				excerptData[currentLang] = excerptValue;
				$("#multilang_hidden_excerpt_" + currentLang).val(excerptValue);
			}
		}
		
		$("#multilang_language_select").on("change", function() {
			saveCurrentInput();
			updateDisplay();
		});
		
		$("#multilang_current_title, #multilang_current_excerpt").on("input", function() {
			if (currentLang) {
				saveCurrentInput();
				updateDisplay();
			}
		});
		
		updateDisplay();
	});
	</script>';
}

function multilang_save_metabox($post_id) {
	if (!$post_id || !is_numeric($post_id)) {
		return;
	}
	
	if (!isset($_POST['multilang_nonce']) || !wp_verify_nonce($_POST['multilang_nonce'], 'multilang_save')) {
		return;
	}
	
	if (!current_user_can('edit_post', $post_id)) {
		return;
	}
	
	if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
		return;
	}
	
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
	
	if (isset($_POST['multilang_excerpts']) && is_array($_POST['multilang_excerpts'])) {
		$multilang_excerpts = array();
		foreach ($_POST['multilang_excerpts'] as $lang => $excerpt) {
			$lang = sanitize_text_field($lang);
			$excerpt = sanitize_textarea_field($excerpt);
			if (!empty($excerpt)) {
				$multilang_excerpts[$lang] = $excerpt;
			}
		}
		
		if (!empty($multilang_excerpts)) {
			update_post_meta($post_id, '_multilang_excerpts', $multilang_excerpts);
		} else {
			delete_post_meta($post_id, '_multilang_excerpts');
		}
	}
}
add_action('save_post', 'multilang_save_metabox');

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
