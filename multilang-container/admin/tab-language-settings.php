<?php

/**
 * Render languages configuration tab
 */
function multilang_render_languages_tab($active_tab, $lang_flags, $langs, $plugin_url) {
	echo '<div id="tab-languages" class="multilang-tab-content" style="' . ($active_tab !== 'languages' ? 'display:none;' : '') . '">';
	echo '<form method="post" style="background:#fff;padding:2em 2em 1em 2em;border-radius:1em;box-shadow:0 2px 16px rgba(0,0,0,0.07);">';
	echo wp_nonce_field('multilang_container_settings', 'multilang_container_nonce', true, false);

	// Language selection grid
	echo '<div style="font-size:1.1em;font-weight:500;margin-bottom:1em;display:block;">Select Languages:</div>';
	echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1.2em;margin-top:1em;">';
	
	foreach ($lang_flags as $lang) {
		$code = esc_attr($lang['code']);
		$name = esc_attr($lang['name']);
		$flag = esc_attr($lang['flag']);
		$checked = in_array($code, $langs) ? 'checked' : '';
		
		// Fix flag filename to match actual files
		$flag_actual = multilang_fix_flag_filename($code, $flag);
		$flag_url = $plugin_url . '/' . $flag_actual;
		
		echo '<span style="display:flex;align-items:center;gap:0.8em;padding:0.7em 1em;border:1px solid #e0e0e0;border-radius:0.7em;background:#f6f8fa;box-shadow:0 1px 4px rgba(0,0,0,0.04);cursor:pointer;transition:box-shadow 0.2s;">';
		echo '<input type="checkbox" name="multilang_languages[]" value="' . $code . '" ' . $checked . ' style="margin:0 0.2em 0 0;transform:scale(1.2);" />';
		echo '<img src="' . $flag_url . '" alt="' . $name . '" style="width:2.2em;height:2.2em;border-radius:100%;border:1px solid #bbb;object-fit:cover;box-shadow:0 1px 4px rgba(0,0,0,0.07);background:#fff;" />';
		echo '<span style="font-size:1.08em;font-weight:400;">' . $name . ' <span style="color:#888;font-size:0.95em;">(' . $code . ')</span></span>';
		echo '</span>';
	}
	echo '</div>';
	
	// Default language dropdown and other settings
	multilang_render_language_settings($lang_flags, $langs, $plugin_url);
	
	echo '<br /><input type="submit" class="button button-primary" value=" Save Languages" style="font-size:1.1em;padding:0.7em 2em;margin-top:1.5em;border-radius:0.5em;" />';
	echo '</form>';
	echo '</div>';
}

/**
 * Render language settings (default language, selectors, etc.)
 */
function multilang_render_language_settings($lang_flags, $langs, $plugin_url) {
	// Default language dropdown logic
	$default_lang = get_option('multilang_container_default_language', '');
	if (empty($default_lang) || !in_array($default_lang, $langs)) {
		$default_lang = isset($langs[0]) ? $langs[0] : 'en';
		update_option('multilang_container_default_language', $default_lang);
	}
	
	$default_flag_url = '';
	foreach ($lang_flags as $lang) {
		if ($lang['code'] === $default_lang && !empty($lang['flag'])) {
			$default_flag_url = $plugin_url . '/' . $lang['flag'];
			break;
		}
	}
	// Fallback if not found in JSON
	if (empty($default_flag_url)) {
		$default_flag_url = $plugin_url . '/img/flags/fallback.svg';
	}
	
	echo '<br /><div style="margin: 2em 0 1.5em 0;">';
	echo '<label for="multilang_default_language">Default Language:</label>';
	echo '<select class="flag_bg" name="multilang_default_language" id="multilang_default_language" style="background-image:url(' . esc_url($default_flag_url) . ');">';
	
	foreach ($langs as $lang_code) {
		$selected = ($lang_code === $default_lang) ? 'selected' : '';
		$lang_name = '';
		$flag = '';
		foreach ($lang_flags as $lang) {
			if ($lang['code'] === $lang_code) {
				$lang_name = $lang['name'];
				$flag = $lang['flag'];
				break;
			}
		}
		if (empty($lang_name)) $lang_name = strtoupper($lang_code);
		
		$flag_actual = multilang_fix_flag_filename($lang_code, $flag);
		echo '<option value="' . esc_attr($lang_code) . '" data-flag="' . esc_attr($plugin_url . '/' . $flag_actual) . '" ' . $selected . '>' . esc_html($lang_name) . ' (' . esc_html(strtoupper($lang_code)) . ')</option>';
	}
	echo '</select>';
	echo '</div>';
	
	// Exclude selectors
	echo '<label for="multilang_exclude_selectors">Selectors to exclude (comma separated):</label>';
	$custom_selectors = get_option('multilang_container_exclude_selectors', '');
	echo '<input type="text" name="multilang_exclude_selectors" id="multilang_exclude_selectors" value="' . esc_attr($custom_selectors) . '" style="width:100%;max-width:500px;font-size:1em;padding:0.5em;margin-bottom:1.2em;border-radius:0.3em;border:1px solid #ccc;" />';
	
	multilang_add_language_settings_resources($lang_flags, $plugin_url);
}

/**
 * Render translations management tab
 */
function multilang_render_translations_tab($active_tab, $translations, $available_languages) {
	echo '<div id="tab-translations" class="multilang-tab-content" style="' . ($active_tab !== 'translations' ? 'display:none;' : '') . '">';
	multilang_translations_tab_content();
	echo '</div>';
}


/**
 * Resources for language settings
 */
function multilang_add_language_settings_resources($lang_flags, $plugin_url) {
	wp_enqueue_script(
		'multilang-admin-languages-tab',
		plugins_url('/admin/js/tab-language-settings.js', dirname(__FILE__)),
		[],
		null,
		true
	);
	wp_localize_script(
		'multilang-admin-languages-tab',
		'multilangVars',
		[
			'langData'   => $lang_flags,
			'pluginUrl'  => $plugin_url,
			'langDataUrl' => get_translations_data_url()
		]
	);
}
