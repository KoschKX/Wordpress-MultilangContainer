<?php
/**
 * Multilang Container - Language Switcher
 * 
 * Manages language detection, body classes, and switching between languages
 */

// Don't allow direct access to this file
if (!defined('ABSPATH')) {
    exit;
}

// Load plugin options from options.json
if (!function_exists('multilang_get_options_file_path')) {
    function multilang_get_options_file_path() {
        if (function_exists('wp_upload_dir')) {
            $upload_dir = wp_upload_dir();
            $multilang_dir = trailingslashit($upload_dir['basedir']) . 'multilang/';
            return $multilang_dir . 'options.json';
        }
        return dirname(__FILE__, 3) . '/uploads/multilang/options.json';
    }
}

if (!function_exists('multilang_get_options')) {
    function multilang_get_options() {
        $file_path = multilang_get_options_file_path();
        if (!file_exists($file_path)) {
            return array();
        }
        $json_content = file_get_contents($file_path);
        $options = json_decode($json_content, true);
        return is_array($options) ? $options : array();
    }
}




// Add the current language as a data attribute to the HTML tag
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

// Add a language-specific class to the body tag
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
    // Load options using helper
    $options = function_exists('multilang_get_options') ? multilang_get_options() : array();
    $use_query_string = isset($options['language_switcher_query_string']) && $options['language_switcher_query_string'];
    $query_string_enabled = isset($options['language_query_string_enabled']) && $options['language_query_string_enabled'];
    $refresh_on_switch = isset($options['language_switcher_refresh_on_switch']) ? $options['language_switcher_refresh_on_switch'] : 1;
	$default_lang = get_multilang_default_language();
    $langbar = '<ul class="multilang-flags" data-use-query-string="' . (($use_query_string && $query_string_enabled) ? '1' : '0') . '" data-refresh-on-switch="' . ($refresh_on_switch ? '1' : '0') . '">';
    foreach ($lang_flags as $lang) {
        $code = esc_attr($lang['code']);
        $name = esc_attr($lang['name']);
        if (in_array($code, $selected_langs)) {
            if ($use_query_string && $query_string_enabled) {
                if ($code === $default_lang) {
                    // Default language: canonical link (no ?lang=xx), keep current URL and other query params
                    if (function_exists('remove_query_arg')) {
                        $current_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
                        $link = remove_query_arg('lang', $current_url);
                    } else {
                        $link = strtok($_SERVER['REQUEST_URI'], '?');
                    }
                } else {
                    $link = '?lang=' . $code;
                }
            } else {
                $link = '#';
            }
            $langbar .= '<li style="" class="lang-item lang-item-'.$code.'"><a lang="'.$code.'" hreflang="'.$code.'" title="'.$name.'" href="'.$link.'"></a></li>';
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

/* QUERY STRING */

    // Unified function to append ?lang=xx to all internal links
    function multilang_append_lang_to_links($html) {
        $options = function_exists('multilang_get_options') ? multilang_get_options() : array();
        $use_query_string = isset($options['language_switcher_query_string']) && $options['language_switcher_query_string'];
        $query_string_enabled = isset($options['language_query_string_enabled']) && $options['language_query_string_enabled'];
        $default_lang = function_exists('get_multilang_default_language') ? get_multilang_default_language() : 'en';
        // Always use cookie if set, fallback to $_GET, then default
        $lang = isset($_COOKIE['lang']) ? sanitize_text_field($_COOKIE['lang']) : (isset($_GET['lang']) ? sanitize_text_field($_GET['lang']) : $default_lang);
        if ($use_query_string && $query_string_enabled && $lang) {
            $site_url = preg_replace('#^https?://#', '', rtrim(home_url(), '/'));
            // Always add data-multilang-link to internal links for JS targeting
            $html = preg_replace_callback('/<a([^>]+)href=["\']((?:\/|\.|\?)[^"\'>]*|https?:\/\/(?:' . preg_quote($site_url, '/') . ')[^"\'>]*)["\']/', function($matches) use ($lang, $site_url, $default_lang) {
                $url = $matches[2];
                $tag = $matches[0];
                // Only update if relative or absolute for current site
                if (
                    preg_match('/^(https?:)?\/\//', $url) && strpos($url, $site_url) === false
                ) return $tag;
                // Remove lang=xx for default language
                if ($lang === $default_lang) {
                    $new_url = preg_replace('/([&?])lang=[^&#]*(&)?/', '$1', $url);
                    $new_url = rtrim($new_url, '?&');
                } else {
                    // Only update if not already has ?lang=xx
                    if (strpos($url, 'lang=') !== false) $new_url = $url;
                    else {
                        $sep = strpos($url, '?') === false ? '?' : '&';
                        $new_url = $url . $sep . 'lang=' . $lang;
                    }
                }
                // Add data-multilang-link attribute if not present
                if (strpos($tag, 'data-multilang-link') === false) {
                    $tag = preg_replace('/<a/', '<a data-multilang-link="true"', $tag, 1);
                }
                return str_replace($url, $new_url, $tag);
            }, $html);
        }
        return $html;
    }

    // Ensure all post/page/custom post type permalinks include ?lang=xx
    function multilang_add_lang_to_permalink($url) {
        $options = function_exists('multilang_get_options') ? multilang_get_options() : array();
        $use_query_string = isset($options['language_switcher_query_string']) && $options['language_switcher_query_string'];
        $query_string_enabled = isset($options['language_query_string_enabled']) && $options['language_query_string_enabled'];
        $default_lang = function_exists('get_multilang_default_language') ? get_multilang_default_language() : 'en';
        $lang = isset($_COOKIE['lang']) ? sanitize_text_field($_COOKIE['lang']) : (isset($_GET['lang']) ? sanitize_text_field($_GET['lang']) : $default_lang);
        if ($use_query_string && $query_string_enabled && $lang) {
            // Don't add if already has ?lang=xx or if it's the default language
            if (strpos($url, 'lang=') === false && $lang !== $default_lang) {
                $sep = strpos($url, '?') === false ? '?' : '&';
                $url .= $sep . 'lang=' . $lang;
            }
        }
        return $url;
    }

    // Detect ?lang=xx in query string and set cookie (server-side) ONLY if enabled in options
    add_action('init', function() {
        $options = function_exists('multilang_get_options') ? multilang_get_options() : array();
        $query_string_enabled = isset($options['language_query_string_enabled']) && $options['language_query_string_enabled'];
        if ($query_string_enabled && isset($_GET['lang'])) {
            $lang = sanitize_text_field($_GET['lang']);
            $available_langs = function_exists('get_multilang_available_languages') ? get_multilang_available_languages() : array();
            if (in_array($lang, $available_langs)) {
                // Set cookie for 1 year
                setcookie('lang', $lang, time() + 365*24*60*60, '/');
                $_COOKIE['lang'] = $lang; // For immediate use in this request
            }
        }
    });

    add_filter('wp_exclude_query_strings', function($query_strings) {
        // Remove 'lang' from the exclusion list so WPFC caches by it
        if (($key = array_search('lang', $query_strings)) !== false) {
            unset($query_strings[$key]);
        }
        return $query_strings;
    });
    add_filter('wp_include_query_strings', function($query_strings) {
        // Ensure 'lang' is included for cache differentiation
        $query_strings[] = 'lang';
        $query_strings = array_unique($query_strings);
        return $query_strings;
    });
   
    // Redirect to ?lang=xx if query string switching is enabled and ?lang is missing
    add_action('template_redirect', function() {
        $options = function_exists('multilang_get_options') ? multilang_get_options() : array();
        $use_query_string = isset($options['language_switcher_query_string']) && $options['language_switcher_query_string'];
        $query_string_enabled = isset($options['language_query_string_enabled']) && $options['language_query_string_enabled'];
        if (!is_admin() && $use_query_string && $query_string_enabled && !isset($_GET['lang'])) {
            $default_lang = function_exists('get_multilang_default_language') ? get_multilang_default_language() : 'en';
            $available_langs = function_exists('get_multilang_available_languages') ? get_multilang_available_languages() : array($default_lang);
            $cookie_lang = isset($_COOKIE['lang']) && in_array($_COOKIE['lang'], $available_langs) ? sanitize_text_field($_COOKIE['lang']) : $default_lang;
            $current_lang = $cookie_lang;
            // Only redirect to ?lang=xx if NOT default language
            if ($current_lang !== $default_lang) {
                $url = $_SERVER['REQUEST_URI'];
                $sep = strpos($url, '?') === false ? '?' : '&';
                $redirect_url = $url . $sep . 'lang=' . $current_lang;
                wp_redirect($redirect_url, 302);
                exit;
            }
        }
    });


    add_filter('post_link', 'multilang_add_lang_to_permalink', 10, 1);
    add_filter('page_link', 'multilang_add_lang_to_permalink', 10, 1);
    add_filter('post_type_link', 'multilang_add_lang_to_permalink', 10, 1);
    add_filter('widget_text', function($html) {
        $options = function_exists('multilang_get_options') ? multilang_get_options() : array();
        $use_query_string = isset($options['language_switcher_query_string']) && $options['language_switcher_query_string'];
        $query_string_enabled = isset($options['language_query_string_enabled']) && $options['language_query_string_enabled'];
        $default_lang = function_exists('get_multilang_default_language') ? get_multilang_default_language() : 'en';
    $lang = isset($_COOKIE['lang']) ? sanitize_text_field($_COOKIE['lang']) : (isset($_GET['lang']) ? sanitize_text_field($_GET['lang']) : $default_lang);
        if ($use_query_string && $query_string_enabled && $lang !== $default_lang) {
            return multilang_append_lang_to_links($html);
        } else {
            // Remove any ?lang=xx from widget links for default language
            return preg_replace('/([&?])lang=[^&#]*(&)?/', '$1', $html);
        }
    }, 99);
    add_filter('widget_content', function($html) {
        $options = function_exists('multilang_get_options') ? multilang_get_options() : array();
        $use_query_string = isset($options['language_switcher_query_string']) && $options['language_switcher_query_string'];
        $query_string_enabled = isset($options['language_query_string_enabled']) && $options['language_query_string_enabled'];
        $default_lang = function_exists('get_multilang_default_language') ? get_multilang_default_language() : 'en';
    $lang = isset($_COOKIE['lang']) ? sanitize_text_field($_COOKIE['lang']) : (isset($_GET['lang']) ? sanitize_text_field($_GET['lang']) : $default_lang);
        if ($use_query_string && $query_string_enabled && $lang !== $default_lang) {
            return multilang_append_lang_to_links($html);
        } else {
            return preg_replace('/([&?])lang=[^&#]*(&)?/', '$1', $html);
        }
    }, 99);
    add_filter('get_calendar', function($html) {
        $options = function_exists('multilang_get_options') ? multilang_get_options() : array();
        $use_query_string = isset($options['language_switcher_query_string']) && $options['language_switcher_query_string'];
        $query_string_enabled = isset($options['language_query_string_enabled']) && $options['language_query_string_enabled'];
        $default_lang = function_exists('get_multilang_default_language') ? get_multilang_default_language() : 'en';
    $lang = isset($_COOKIE['lang']) ? sanitize_text_field($_COOKIE['lang']) : (isset($_GET['lang']) ? sanitize_text_field($_GET['lang']) : $default_lang);
        if ($use_query_string && $query_string_enabled && $lang !== $default_lang) {
            return multilang_append_lang_to_links($html);
        } else {
            return preg_replace('/([&?])lang=[^&#]*(&)?/', '$1', $html);
        }
    }, 99);

    // Append ?lang=xx to all internal links in post/page content using the_content filter
    add_filter('the_content', 'multilang_append_lang_to_links');
    // Use shutdown to process final HTML output and append ?lang=xx to all internal links
    add_action('init', function() {
        if (!is_admin() && !headers_sent()) {
            ob_start('multilang_append_lang_to_links');
        }
    });