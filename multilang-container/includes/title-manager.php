<?php

if (!defined('ABSPATH')) {
    exit;
}

function multilang_get_title($post_id = null, $lang = null) {
	if ($post_id === null) {
		global $post;
		$post_id = $post ? $post->ID : 0;
	}
	
	// Validate post ID
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
		
		// Return translated title if exists
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
		// Get current language
		$lang = isset($_COOKIE['lang']) ? sanitize_text_field($_COOKIE['lang']) : 'en';
		
		// Get multilang titles directly from post meta to avoid recursion
		$multilang_titles = get_post_meta($post_id, '_multilang_titles', true);
		
		if (is_array($multilang_titles)) {
			// Return translated title if exists
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
	
	// Check if this post has multilingual titles
	$multilang_titles = get_post_meta($post->ID, '_multilang_titles', true);
	if (!is_array($multilang_titles) || empty($multilang_titles)) {
		return $content;
	}
	
	// Add class to common title patterns
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
	
	// Add the original title
	$multilang_titles['original'] = $original_title;
	
	echo '<script type="text/javascript">';
	echo 'window.multilangPageTitles = ' . wp_json_encode($multilang_titles, JSON_HEX_TAG | JSON_HEX_AMP) . ';';
	echo '</script>' . "\n";
}
add_action('wp_head', 'multilang_add_title_data_to_head', 999);