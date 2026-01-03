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

// Shortcode for showing the translated title
function multilang_title_shortcode($atts) {
	$atts = shortcode_atts(array(
		'post_id' => null,
		'lang' => null
	), $atts, 'multilang_title');
	
	return multilang_get_title($atts['post_id'], $atts['lang']);
}
add_shortcode('multilang_title', 'multilang_title_shortcode');

// Filter to wrap title with translation spans
function multilang_filter_the_title($title, $post_id = null) {
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
	
	// Check if this post has multilang titles
	$multilang_titles = get_post_meta($post_id, '_multilang_titles', true);
	
	// If it has translations, wrap with multilang structure
	if (is_array($multilang_titles) && !empty($multilang_titles)) {
		$available_languages = get_multilang_available_languages();
		$default_lang = get_option('multilang_container_default_language', 'en');
		$default_translation = isset($multilang_titles[$default_lang]) ? $multilang_titles[$default_lang] : $title;
		
		// Build the wrapper
		$output = '<span class="multilang-wrapper" data-original-text="' . esc_attr($title) . '" data-default-text="' . esc_attr($default_translation) . '">';
		
		foreach ($available_languages as $lang) {
			$translation = isset($multilang_titles[$lang]) ? $multilang_titles[$lang] : $title;
			$encoded = htmlspecialchars($translation, ENT_QUOTES, 'UTF-8');
			
			$output .= '<span class="translate lang-' . esc_attr($lang) . '" data-translation="' . esc_attr($encoded) . '" data-default-text="' . esc_attr($default_translation) . '">';
			$output .= esc_html($translation);
			$output .= '</span>';
		}
		
		$output .= '</span>';
		
		return $output;
	}
	
	return $title;
}
add_filter('the_title', 'multilang_filter_the_title', 10, 2);

/**
 * Filter document title (in <head>) to show translated version
 */
function multilang_filter_document_title($title) {
	// Only filter on frontend
	if (is_admin()) {
		return $title;
	}
	
	// Only on singular posts/pages
	if (!is_singular()) {
		return $title;
	}
	
	global $post;
	if (!$post) {
		return $title;
	}
	
	// Get current language
	$lang = isset($_COOKIE['lang']) ? sanitize_text_field($_COOKIE['lang']) : get_option('multilang_container_default_language', 'en');
	
	// Check if this post has multilang titles
	$multilang_titles = get_post_meta($post->ID, '_multilang_titles', true);
	if (is_array($multilang_titles) && isset($multilang_titles[$lang]) && !empty($multilang_titles[$lang])) {
		// Replace the post title part in the document title
		// Document title is usually "Post Title | Site Name" or similar
		remove_filter('the_title', 'multilang_filter_the_title', 10);
		$original_title = get_the_title($post->ID);
		add_filter('the_title', 'multilang_filter_the_title', 10, 2);
		
		// Replace the original title with translated version
		$title = str_replace($original_title, $multilang_titles[$lang], $title);
	}
	
	return $title;
}
add_filter('document_title_parts', 'multilang_filter_document_title_parts', 10);

/**
 * Filter document title parts to translate the title and tagline
 */
function multilang_filter_document_title_parts($parts) {
	// Only filter on frontend
	if (is_admin()) {
		return $parts;
	}
	
	global $post;
	
	// Get current language
	$lang = isset($_COOKIE['lang']) ? sanitize_text_field($_COOKIE['lang']) : get_option('multilang_container_default_language', 'en');
	
	// Translate post/page title
	if (is_singular() && $post && isset($parts['title'])) {
		// Check if this post has multilang titles
		$multilang_titles = get_post_meta($post->ID, '_multilang_titles', true);
		if (is_array($multilang_titles) && isset($multilang_titles[$lang]) && !empty($multilang_titles[$lang])) {
			$parts['title'] = $multilang_titles[$lang];
		}
	}
	
	// Translate tagline
	if (isset($parts['tagline'])) {
		$taglines = get_option('multilang_container_taglines', array());
		if (is_array($taglines) && isset($taglines[$lang]) && !empty($taglines[$lang])) {
			$parts['tagline'] = $taglines[$lang];
		}
	}
	
	return $parts;
}

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