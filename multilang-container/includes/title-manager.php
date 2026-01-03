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

// Filter to automatically swap out titles on the frontend
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
		// Get multilang titles directly from post meta to avoid recursion
		$multilang_titles = get_post_meta($post_id, '_multilang_titles', true);
		
		if (is_array($multilang_titles) && !empty($multilang_titles)) {
			// Get available languages
			$available_languages = get_multilang_available_languages();
			if (empty($available_languages)) {
				$available_languages = array_keys($multilang_titles);
			}
			
			// Get default language
			$default_lang = get_option('multilang_container_default_language', 'en');
			
			// Check if hide filter is enabled
			$hide_filter = get_option('multilang_container_hide_filter', false);
			
			// Build translation spans for all languages
			$spans = array();
			foreach ($available_languages as $language) {
				$translated_title = isset($multilang_titles[$language]) && !empty($multilang_titles[$language]) 
					? $multilang_titles[$language] 
					: $title;
				
				// Encode for data attribute (matching the JS encoding)
				$encoded = htmlspecialchars($translated_title, ENT_QUOTES, 'UTF-8');
				
				if ($hide_filter) {
					$spans[] = sprintf(
						'<span class="translate lang-%s" data-translation="%s">%s</span>',
						esc_attr($language),
						$encoded,
						esc_html($translated_title)
					);
				} else {
					$spans[] = sprintf(
						'<span class="translate lang-%s" data-translation="%s">%s</span>',
						esc_attr($language),
						$encoded,
						esc_html($translated_title)
					);
				}
			}
			
			if (!empty($spans)) {
				$processing = false;
				return '<span class="multilang-wrapper" data-original-text="' . esc_attr($title) . '">' . implode('', $spans) . '</span>';
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
 * Add translation data to title elements via JavaScript
 */
function multilang_add_title_translation_script() {
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
	
	// Get original title
	remove_filter('the_title', 'multilang_filter_the_title', 10);
	$original_title = get_the_title($post->ID);
	add_filter('the_title', 'multilang_filter_the_title', 10, 2);
	
	$available_languages = get_multilang_available_languages();
	$hide_filter = get_option('multilang_container_hide_filter', false);
	
	?>
	<script type="text/javascript">
	(function() {
		function wrapPostTitle() {
			var titleSelectors = [
				'.wp-block-post-title',
				'h1.entry-title',
				'h1.page-title',
				'h1.post-title',
				'.entry-header h1',
				'article h1:first-child'
			];
			
			var originalTitle = <?php echo wp_json_encode($original_title); ?>;
			var translations = <?php echo wp_json_encode($multilang_titles); ?>;
			var languages = <?php echo wp_json_encode($available_languages); ?>;
			var hideFilter = <?php echo wp_json_encode($hide_filter); ?>;
			
			titleSelectors.forEach(function(selector) {
				var elements = document.querySelectorAll(selector);
				elements.forEach(function(element) {
					// Skip if already wrapped
					if (element.querySelector('.multilang-wrapper')) {
						return;
					}
					
					// Check if this element contains a title text
					var elementText = element.textContent.trim();
					var hasTitle = elementText === originalTitle;
					
					// Also check if it matches any translation
					if (!hasTitle) {
						for (var lang in translations) {
							if (elementText === translations[lang]) {
								hasTitle = true;
								break;
							}
						}
					}
					
					if (hasTitle) {
						// Build translation spans
						var spans = [];
						languages.forEach(function(lang) {
							var translation = translations[lang] || originalTitle;
							var encoded = translation.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
							
							var span = document.createElement('span');
							span.className = 'translate lang-' + lang;
							span.setAttribute('data-translation', encoded);
							span.textContent = translation;
							spans.push(span);
						});
						
						// Create wrapper
						var wrapper = document.createElement('span');
						wrapper.className = 'multilang-wrapper';
						wrapper.setAttribute('data-original-text', originalTitle);
						
						spans.forEach(function(span) {
							wrapper.appendChild(span);
						});
						
						// Replace element content
						element.innerHTML = '';
						element.appendChild(wrapper);
						element.setAttribute('data-multilang-title', 'true');
					}
				});
			});
		}
		
		// Run on page load
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', wrapPostTitle);
		} else {
			wrapPostTitle();
		}
	})();
	</script>
	<?php
}
add_action('wp_footer', 'multilang_add_title_translation_script', 5);

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