<?php
/**
 * Multilang Container - Language Switcher
 * 
 * Handles language detection, body classes, and language switching functionality
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Add language attribute to HTML
 */
function lang_attribute( $output ) {
    $default_lang = get_multilang_default_language();
    $current_lang = $default_lang;
    
    if ( isset($_COOKIE['lang']) ) {
        $cookie_lang = sanitize_text_field($_COOKIE['lang']);
        $available_langs = get_multilang_available_languages();
        if ( in_array($cookie_lang, $available_langs) ) {
            $current_lang = $cookie_lang;
        }
    }
    
    // Add data-lang attribute for CSS targeting
    $output .= ' data-lang="' . esc_attr($current_lang) . '"';
    
    return $output;
}
add_filter( 'language_attributes', 'lang_attribute' );

/**
 * Body class based on cookie or default language
 */
function multilang_body_class_lang( $classes ) {
    $default_lang = get_multilang_default_language();
    $current_lang = $default_lang;
    
    if ( isset($_COOKIE['lang']) ) {
        $cookie_lang = sanitize_text_field($_COOKIE['lang']);
        $available_langs = get_multilang_available_languages();
        if ( in_array($cookie_lang, $available_langs) ) {
            $current_lang = $cookie_lang;
        }
    }
    
    $classes[] = 'lang-' . $current_lang;
    return $classes;
}
add_filter( 'body_class', 'multilang_body_class_lang' );

/**
 * Generate language bar HTML
 */
function multilang_generate_langbar() {
	$json_path = plugin_dir_path(dirname(__FILE__)) . '/data/languages-flags.json';
	$lang_flags = array();
	if (file_exists($json_path)) {
		$json = file_get_contents($json_path);
		$lang_flags = json_decode($json, true);
	}
	
	$selected_langs = get_multilang_available_languages();
	
	$langbar = '<ul class="multilang-flags">';
	foreach ($lang_flags as $lang) {
		$code = esc_attr($lang['code']);
		$name = esc_attr($lang['name']);
		if (in_array($code, $selected_langs)) {
			$langbar .= '<li style="" class="lang-item lang-item-'.$code.'"><a lang="'.$code.'" hreflang="'.$code.'" title="'.$name.'"></a></li>';
        }
	}
	$langbar .= '</ul>';
	
	return $langbar;
}

/**
 * Get language bar data for JavaScript
 */
function multilang_get_langbar_data() {
	return array(
		'html' => multilang_generate_langbar(),
		'pluginPath' => plugins_url('', dirname(__FILE__)),
		'ajaxUrl' => admin_url('admin-ajax.php')
	);
}

/**
 * Enqueue language switcher JavaScript
 */
function multilang_enqueue_language_switcher() {
    if ( multilang_is_backend_operation() ) {
        return;
    }
    
	wp_enqueue_script(
		'multilang-language-switcher-js',
		plugins_url('js/language-switcher.js', dirname(__FILE__)),
		array('multilang-container-js'),
		filemtime(plugin_dir_path(dirname(__FILE__)) . 'js/language-switcher.js'),
		true
	);
}
add_action('wp_enqueue_scripts', 'multilang_enqueue_language_switcher', 11);