<?php
/*
 * Multilang Container - Utilities Admin
 */

function multilang_generate_css($langs) {
	$css = "";

	$selected_lang_flags = get_selected_languages_flags($langs);
	
	// Get exclude selectors
	$custom_selectors = get_option('multilang_container_exclude_selectors', '');
	$not_selectors = array('.no-translate');
	if (trim($custom_selectors) !== '') {
		foreach (explode(',', $custom_selectors) as $sel) {
			$sel = trim($sel);
			if ($sel !== '') $not_selectors[] = $sel;
		}
	}
	$not_chain = '';
	foreach ($not_selectors as $sel) {
		$not_chain .= ":not($sel)";
	}
	
	$default_lang = get_option('multilang_container_default_language', 'en');
	
	// Generate CSS for each selected language
	foreach ($selected_lang_flags as $lang) {
		$code = $lang['code'];
		$flag = isset($lang['flag']) ? $lang['flag'] : "img/flags/$code.svg";
		
		$css .= ".multilang-flags .lang-item > a[lang=\"$code\"] { background-image: url(./$flag) !important; }\n";
		$css .= "html[data-lang=\"$code\"] .multilang-flags .lang-item > a[lang=\"$code\"] { filter: saturate(1) !important; }\n";
		$css .= "html[data-lang=$code] $not_chain .translate:not(.lang-$code) { display: none !important; }\n";
		$css .= "html[data-lang=$code] $not_chain .translate > .wp-block-group:not(.lang-$code):not(.lang-$default_lang) { display: none !important; }\n";
		
		if ($code !== $default_lang) {
			// $css .= "html[data-lang=$code] $not_chain .translate .lang-$default_lang { display: revert !important; }\n";
			// $css .= "html[data-lang=$code] $not_chain .translate.lang-$default_lang { display: revert !important; }\n";
		}
		
		$css .= ".wp-block-multilang-container .lang-$code { display: none !important; }\n";
		$css .= ".wp-block-multilang-container.selected-lang-$code .lang-$code { display: block !important; }\n";
		if ($code !== $default_lang) {
			$css .= ".wp-block-multilang-container.selected-lang-$code .lang-$default_lang { display: none !important; }\n";
		}
		$css .= ".editor-styles-wrapper .wp-block-multilang-container .lang-$code { display: none !important; }\n";
		$css .= ".editor-styles-wrapper .wp-block-multilang-container.selected-lang-$code .lang-$code { display: block !important; }\n";
	}
	
	$css .= ".multilang-flags .lang-item > a img { display: none !important; }\n";
	
	// Fallback CSS
	foreach ($selected_lang_flags as $lang) {
		$code = $lang['code'];
		if ($code !== $default_lang) {
			$css .= ".lang-fallback-$code { display: none !important; }\n";
			$css .= "html[data-lang=$code] .lang-fallback-$code { display: block !important; }\n";
		}
	}
	
	return $css;
}

function multliang_copy_flag_images($langs){
	
	$selected_lang_flags = get_selected_languages_flags($langs);
	// Ensure chosen flags are present in uploads/multilang/img
	$img_dir = get_images_file_path() . 'flags/';
	if (!is_dir($img_dir)) {
		wp_mkdir_p($img_dir);
	}
	foreach ($selected_lang_flags as $lang) {
		$code = $lang['code'];

		$flag_file = 'img/flags/' . $code . '.svg';
		// Special cases
		$flag_file = multilang_fix_flag_filename($code, $flag_file);
		$src_path = plugin_dir_path(dirname(__FILE__)) . $flag_file;
		$dest_path = $img_dir . basename($flag_file);
		if (file_exists($src_path)) {
			copy($src_path, $dest_path);
		}
	}
}


