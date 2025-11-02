<?php
/**
 * Multilang Container - Utilities
 * 
 * Handles helper functions, language utilities, and common functions
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

	/**
	 * Check if current request is a backend operation that should be excluded from translation
	 * 
	 * @return bool True if this is a backend operation, false if frontend
	 */
	function multilang_is_backend_operation() {
		// Don't process during backend operations (AJAX, cron, autosave)
		if ( wp_doing_ajax() || wp_doing_cron() || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) ) {
			return true;
		}
		
		// Don't process admin dashboard pages (URL-based check)
		if ( isset($_SERVER['REQUEST_URI']) && (
			strpos($_SERVER['REQUEST_URI'], '/wp-admin/') !== false ||
			strpos($_SERVER['REQUEST_URI'], '/wp-login.php') !== false ||
			strpos($_SERVER['REQUEST_URI'], '/wp-cron.php') !== false ||
			strpos($_SERVER['REQUEST_URI'], '/xmlrpc.php') !== false
		)) {
			return true;
		}
		
		// Don't process REST API requests
		if ( defined('REST_REQUEST') && REST_REQUEST ) {
			return true;
		}
		
		// Don't process during backup operations (BackWPup and other backup plugins)
		if ( defined('DOING_BACKUP') && DOING_BACKUP ) {
			return true;
		}
		
		// Check for BackWPup specific operations
		if ( isset($_REQUEST['page']) && (
			strpos($_REQUEST['page'], 'backwpup') !== false ||
			strpos($_REQUEST['page'], 'backup') !== false
		)) {
			return true;
		}
		
		// Check for common backup plugin actions
		if ( isset($_REQUEST['action']) && (
			strpos($_REQUEST['action'], 'backup') !== false ||
			strpos($_REQUEST['action'], 'export') !== false ||
			strpos($_REQUEST['action'], 'backwpup') !== false ||
			$_REQUEST['action'] === 'download' ||
			$_REQUEST['action'] === 'updraftplus_download'
		)) {
			return true;
		}
		
		// Check for CLI/WP-CLI operations
		if ( defined('WP_CLI') && WP_CLI ) {
			return true;
		}
		
		// Check for other heavy operations
		if ( isset($_GET['doing_wp_cron']) || isset($_POST['doing_wp_cron']) ) {
			return true;
		}
		
		// Check for import/export operations
		if ( isset($_REQUEST['import']) || isset($_REQUEST['export']) ) {
			return true;
		}
		
		return false;
	}

	/**
	 * Enhance translations with fallbacks
	 */
	function enhance_translations_with_fallbacks($translations) {
		// Handle null or empty translations
		if (!is_array($translations)) {
			return array();
		}
		
		$languages = get_multilang_available_languages();
		$default_lang = get_multilang_default_language();
		$enhanced = $translations;
		
		// For each category and key, ensure all languages have a value (using fallback if needed)
		foreach ($enhanced as $category => $keys) {
			if (is_array($keys)) {
				foreach ($keys as $key => $lang_translations) {
					if (is_array($lang_translations)) {
						// For each language, if translation is missing, use default language
						foreach ($languages as $lang) {
							if (!isset($lang_translations[$lang]) || empty(trim($lang_translations[$lang]))) {
								// Missing or empty translation, use default language if available
								if ($lang !== $default_lang && isset($lang_translations[$default_lang]) && !empty(trim($lang_translations[$default_lang]))) {
									$enhanced[$category][$key][$lang] = $lang_translations[$default_lang];
								}
							}
						}
					}
				}
			}
		}
		
		return $enhanced;
	}

	/**
	 * Get available languages from settings
	 */
	function get_multilang_available_languages() {
		return get_option('multilang_container_languages', array('en'));
	}

	/**
	 * Get default language from settings
	 */
	function get_multilang_default_language() {
		$default = get_option('multilang_container_default_language', 'en');
		$available = get_multilang_available_languages();
		// Ensure default language is still available
		return in_array($default, $available) ? $default : (isset($available[0]) ? $available[0] : 'en');
	}

	/**
	 * Get current language (from cookie, URL parameter, or default)
	 */
	function multilang_get_current_language() {
		$default_lang = get_multilang_default_language();
		$current_lang = $default_lang;
		
		// Check for language cookie (same as server translation logic)
		if (isset($_COOKIE['lang'])) {
			$cookie_lang = sanitize_text_field($_COOKIE['lang']);
			$available_languages = get_multilang_available_languages();
			if (in_array($cookie_lang, $available_languages)) {
				$current_lang = $cookie_lang;
			}
		}
		
		// Also check URL parameter as fallback
		if (isset($_GET['lang'])) {
			$url_lang = sanitize_text_field($_GET['lang']);
			$available_languages = get_multilang_available_languages();
			if (in_array($url_lang, $available_languages)) {
				$current_lang = $url_lang;
			}
		}
		
		return $current_lang;
	}

	/**
	 * Fix flag filename for special cases
	 */
	function multilang_fix_flag_filename($lang_code, $flag) {
		$flag_actual = $flag;
		if ($lang_code === 'en') $flag_actual = 'img/flags/gb.svg';
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

	function get_selected_languages_flags($langs) {

		$json_path = plugin_dir_path(dirname(__FILE__)) . 'data/languages-flags.json';
		$lang_flags = array();
		if (file_exists($json_path)) {
			$json = file_get_contents($json_path);
			$lang_flags = json_decode($json, true);
		}

		$selected_lang_flags = array();
		foreach ($lang_flags as $lang) {
			if (in_array($lang['code'], $langs)) {
				$selected_lang_flags[] = $lang;
			}
		}
		return $selected_lang_flags;
	}


	/**
	 * Debug: Show admin notice if block is registered
	 */
	function multilang_container_admin_notice() {
		if ( function_exists('register_block_type') ) {
			// echo '<div class="notice notice-success is-dismissible"><p>Multilang Container is registered and active.</p></div>';
		} else {
			echo '<div class="notice notice-error"><p>Multilang Container registration failed. Check for errors in block.json or PHP.</p></div>';
		}
	}
	add_action('admin_notices', 'multilang_container_admin_notice');


	/**
	 * Helper functions for translation management
	 * Updated functions to handle separate language files
	 */
	function get_translations_data_dir() {
		//return plugin_dir_path(dirname(__FILE__)) . 'data/';
		$upload_dir = wp_upload_dir();
		$multilang_dir = trailingslashit($upload_dir['basedir']) . 'multilang/';
		if (!is_dir($multilang_dir)) {
			wp_mkdir_p($multilang_dir);
		}
		return $multilang_dir;
	}

	function get_language_file_path($language) {
		return get_translations_data_dir() . $language . '.json';
	}
	function get_structure_file_path() {
		return get_translations_data_dir() . 'structure.json';
	}
	function get_images_file_path() {
		return get_translations_data_dir() . 'img/';
	}
	function get_switchcss_file_path() {
		return get_translations_data_dir() . 'langswitch.css';
	}
	function get_translations_file_path() {
		return get_translations_data_dir();
	}
	function get_translations_data_url() {
		$upload_dir = wp_upload_dir();
		return trailingslashit($upload_dir['baseurl']) . 'multilang/';
	}


/* OBFUSCATION */

	function obfuscate($str) {
		if (!is_string($str)) return $str;
		$replace = array(
			'[' => '⦋', // U+298B
			']' => '⦌', // U+298C
			'<' => '‹', // U+2039
			'>' => '›', // U+203A
			'{' => '❴', // U+2774
			'}' => '❵', // U+2775
			'&' => '＆', // U+FF06
			'"' => '＂', // U+FF02
			"'" => '＇', // U+FF07
		);
		return strtr($str, $replace);
	}

	function deobfuscate($str) {
		if (!is_string($str)) return $str;
		$replace = array(
			'⦋' => '[', // U+298B
			'⦌' => ']', // U+298C
			'‹' => '<', // U+2039
			'›' => '>', // U+203A
			'❴' => '{', // U+2774
			'❵' => '}', // U+2775
			'＆' => '&', // U+FF06
			'＂' => '"', // U+FF02
			'＇' => "'", // U+FF07
		);
		return strtr($str, $replace);
	}

	function gzip_compress($data) {
		// Compress string using gzip
		return gzencode($data, 9);
	}

	function gzip_decompress($gzdata) {
		// Decompress gzip-compressed string
		return gzdecode($gzdata);
	}

	function base_decode($str) {
		// Decode base64 and handle UTF-8
		if (!is_string($str)) return $str;
		$decoded = base64_decode($str, true);
		if ($decoded === false) return '';

		return mb_convert_encoding($decoded, 'UTF-8', 'UTF-8');
	}

	function base_encode($str) {
		// Encode to base64 safely for Unicode
		if (!is_string($str)) return $str;

		$utf8 = mb_convert_encoding($str, 'UTF-8', 'UTF-8');
		return base64_encode($utf8);
	}
