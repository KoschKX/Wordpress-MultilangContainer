<?php
/**
 * Multilang Container - Utilities
 * 
 * Functions for language management and other common tasks
 */

if (!defined('ABSPATH')) {
    exit;
}

	function multilang_is_backend_operation() {
		if ( wp_doing_ajax() || wp_doing_cron() || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) ) {
			return true;
		}
		
		if ( isset($_SERVER['REQUEST_URI']) && (
			strpos($_SERVER['REQUEST_URI'], '/wp-admin/') !== false ||
			strpos($_SERVER['REQUEST_URI'], '/wp-login.php') !== false ||
			strpos($_SERVER['REQUEST_URI'], '/wp-cron.php') !== false ||
			strpos($_SERVER['REQUEST_URI'], '/xmlrpc.php') !== false
		)) {
			return true;
		}
		
		if ( defined('REST_REQUEST') && REST_REQUEST ) {
			return true;
		}
		
		if ( defined('DOING_BACKUP') && DOING_BACKUP ) {
			return true;
		}
		
		if ( isset($_REQUEST['page']) && (
			strpos($_REQUEST['page'], 'backwpup') !== false ||
			strpos($_REQUEST['page'], 'backup') !== false
		)) {
			return true;
		}
		
		if ( isset($_REQUEST['action']) && (
			strpos($_REQUEST['action'], 'backup') !== false ||
			strpos($_REQUEST['action'], 'export') !== false ||
			strpos($_REQUEST['action'], 'backwpup') !== false ||
			$_REQUEST['action'] === 'download' ||
			$_REQUEST['action'] === 'updraftplus_download'
		)) {
			return true;
		}
		
		if ( defined('WP_CLI') && WP_CLI ) {
			return true;
		}
		
		if ( isset($_GET['doing_wp_cron']) || isset($_POST['doing_wp_cron']) ) {
			return true;
		}
		
		if ( isset($_REQUEST['import']) || isset($_REQUEST['export']) ) {
			return true;
		}
		
		return false;
	}

	function enhance_translations_with_fallbacks($translations) {
		if (!is_array($translations)) {
			return array();
		}
		
		$languages = get_multilang_available_languages();
		$default_lang = get_multilang_default_language();
		$enhanced = $translations;
		
		foreach ($enhanced as $category => $keys) {
			if (is_array($keys)) {
				foreach ($keys as $key => $lang_translations) {
					if (is_array($lang_translations)) {
						foreach ($languages as $lang) {
							if (!isset($lang_translations[$lang]) || empty(trim($lang_translations[$lang]))) {
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


	function get_multilang_available_languages() {
		static $cached_languages = null;
		
		if ($cached_languages !== null) {
			return $cached_languages;
		}
		
		if (function_exists('multilang_get_options')) {
			$options = multilang_get_options();
			$cached_languages = isset($options['languages']) && is_array($options['languages']) ? $options['languages'] : array('en');
		} else {
			$cached_languages = array('en');
		}
		
		return $cached_languages;
	}

	function get_multilang_default_language() {
		static $cached_default = null;
		
		if ($cached_default !== null) {
			return $cached_default;
		}
		
		if (function_exists('multilang_get_options')) {
			$options = multilang_get_options();
			$default = isset($options['default_language']) ? $options['default_language'] : 'en';
			$available = get_multilang_available_languages();
			$cached_default = in_array($default, $available) ? $default : (isset($available[0]) ? $available[0] : 'en');
		} else {
			$cached_default = 'en';
		}
		
		return $cached_default;
	}

	function multilang_get_current_language() {
		$default_lang = get_multilang_default_language();
		$current_lang = $default_lang;
		
		if (isset($_COOKIE['lang'])) {
			$cookie_lang = sanitize_text_field($_COOKIE['lang']);
			$available_languages = get_multilang_available_languages();
			if (in_array($cookie_lang, $available_languages)) {
				$current_lang = $cookie_lang;
			}
		}
		
		if (isset($_GET['lang'])) {
			$url_lang = sanitize_text_field($_GET['lang']);
			$available_languages = get_multilang_available_languages();
			if (in_array($url_lang, $available_languages)) {
				$current_lang = $url_lang;
			}
		}
		
		return $current_lang;
	}

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

	function multilang_container_admin_notice() {
		if ( function_exists('register_block_type') ) {
			
		} else {
			echo '<div class="notice notice-error"><p>Multilang Container registration failed. Check for errors in block.json or PHP.</p></div>';
		}
	}
	add_action('admin_notices', 'multilang_container_admin_notice');

	function get_translations_data_dir() {
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
		return gzencode($data, 9);
	}

	function gzip_decompress($gzdata) {
		return gzdecode($gzdata);
	}

	function base_decode($str) {
		if (!is_string($str)) return $str;
		$decoded = base64_decode($str, true);
		if ($decoded === false) return '';
		return mb_convert_encoding($decoded, 'UTF-8', 'UTF-8');
	}

	function base_encode($str) {
		if (!is_string($str)) return $str;
		$utf8 = mb_convert_encoding($str, 'UTF-8', 'UTF-8');
		return base64_encode($utf8);
	}


/* CACHING OPTIONS */

	function multilang_is_page_excluded_from_cache() {
		$json_options = multilang_get_cache_options();
		$options = isset($json_options['cache_exclude_pages']) ? $json_options['cache_exclude_pages'] : '';
		$current_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
		if (empty($options)) {
			return false;
		}
		$excluded_pages = array_map('trim', explode(',', $options));
		foreach ($excluded_pages as $excluded) {
			if (empty($excluded)) {
				continue;
			}
			$excluded = trim($excluded, '/');
			$current = trim($current_path, '/');
			if ($current === $excluded || strpos($current, $excluded) === 0) {
				return true;
			}
		}
		return false;
	}

	function multilang_get_cache_options() {
		$options_file = dirname(__FILE__) . '/../../../uploads/multilang/options.json';
		if (file_exists($options_file)) {
			$json = file_get_contents($options_file);
			$data = json_decode($json, true);
			if (is_array($data)) {
				return $data;
			}
		}
		return array();
	}

	function multilang_is_ajax_cache_enabled() {
		$json_options = multilang_get_cache_options();
		if (isset($json_options['cache_ajax_requests'])) {
			return (bool) $json_options['cache_ajax_requests'];
		}
		return false;
	}