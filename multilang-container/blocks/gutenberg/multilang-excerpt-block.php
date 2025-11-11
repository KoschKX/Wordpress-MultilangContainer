<?php

if (!defined('ABSPATH')) {
    exit;
}


// Register block
function multilang_excerpt_register_block() {
	wp_register_script(
		'excerpt-block-editor',
		plugins_url('/gutenberg/multilang-excerpt-block.js', dirname(__FILE__)),
		array('wp-blocks', 'wp-element', 'wp-editor', 'wp-components'),
		null
	);
	register_block_type( dirname(__FILE__, 2) . '/gutenberg/multilang-excerpt-block.json', array(
		'editor_script' => 'excerpt-block-editor',
		'render_callback' => 'multilang_excerpt_render_new',
	) );
}
add_action('init', 'multilang_excerpt_register_block');


// Limit words in HTML, preserving tags
function multilang_limit_words_html($html, $limit, &$truncated = false) {
    if ($limit <= 0) return $html;
    $fragment = '<div>' . $html . '</div>';
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML(mb_convert_encoding($fragment, 'HTML-ENTITIES', 'UTF-8'));
    libxml_clear_errors();
    $divs = $doc->getElementsByTagName('div');
    $body = $divs->item(0);
    $wordCount = 0;
    $output = '';
    $stop = false;
    $addedEllipsis = false;
    $walker = function($node) use (&$walker, &$wordCount, $limit, &$stop, &$addedEllipsis) {
        if ($stop) return '';
        $result = '';
        if ($node->nodeType === XML_TEXT_NODE) {
            $words = preg_split('/(\s+)/u', $node->nodeValue, -1, PREG_SPLIT_DELIM_CAPTURE);
            $newText = '';
            foreach ($words as $w) {
                if (trim($w) === '') {
                    $newText .= $w;
                    continue;
                }
                if ($wordCount < $limit) {
                    $newText .= $w;
                    $wordCount++;
                } else {
                    $stop = true;
                    if (!$addedEllipsis) {
                        $newText .= '...';
                        $addedEllipsis = true;
                    }
                    break;
                }
            }
            $result .= htmlspecialchars($newText, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
        } elseif ($node->nodeType === XML_ELEMENT_NODE) {
            $result .= '<' . $node->nodeName;
            foreach ($node->attributes as $attr) {
                $result .= ' ' . $attr->nodeName . '="' . htmlspecialchars($attr->nodeValue) . '"';
            }
            $result .= '>';
            foreach ($node->childNodes as $child) {
                $result .= $walker($child);
                if ($stop) break;
            }
            $result .= '</' . $node->nodeName . '>';
        }
        return $result;
    };
    $output = $walker($body);
    $truncated = $stop;
    return $output;
}

// Extract content for a specific language
function multilang_extract_language_content($html, $language) {
	$pattern = '/<(?:span|div)[^>]*class=["\'][^"\']*lang-' . preg_quote($language, '/') . '[^"\']*["\'][^>]*>(.*?)<\/(?:span|div)>/is';
	
	if (preg_match($pattern, $html, $matches)) {
		return isset($matches[1]) ? $matches[1] : '';
	}
	
	return '';
}

// Get current language from cookie or default
function get_multilang_current_language() {
	$default_lang = get_multilang_default_language();
	
	if (isset($_COOKIE['lang'])) {
		$cookie_lang = sanitize_text_field($_COOKIE['lang']);
		$available_langs = get_multilang_available_languages();
		if (in_array($cookie_lang, $available_langs)) {
			return $cookie_lang;
		}
	}
	
	return $default_lang;
}

// Render callback
function multilang_excerpt_render_new($attributes, $content) {
	global $post;
	if (empty($post)) return '';
	
	$wordLimit = isset($attributes['words']) ? intval($attributes['words']) : 0;
	$preserveHtml = isset($attributes['preserveHtml']) ? (bool)$attributes['preserveHtml'] : false;
	
	$blocks = parse_blocks($post->post_content);
	foreach ($blocks as $block) {
		if ($block['blockName'] === 'multilang/container') {
			$html = render_block($block);
			
			$available_langs = get_multilang_available_languages();
			$default_lang = get_multilang_default_language();
			$current_lang = get_multilang_current_language();
			
			$default_content = multilang_extract_language_content($html, $default_lang);
			
			$existing_langs = array();
			preg_match_all('/lang-([a-z]{2})/', $html, $matches);
			if (!empty($matches[1])) {
				$existing_langs = $matches[1];
			}
			
			$html = preg_replace_callback(
				'/(<(?:span|div)[^>]*class=["\'][^"\']*lang-([a-z]{2})[^"\']*["\'][^>]*>)(.*?)(<\/(?:span|div)>)/is',
				function($matches) use ($wordLimit, $preserveHtml, $default_content, $default_lang, $current_lang) {
					$open_tag = $matches[1];
					$lang = $matches[2];
					$content = $matches[3];
					$close_tag = $matches[4];
					
					if (empty(trim($content))) {
						$content = $default_content;
					}
					
					if ($wordLimit > 0 && !empty(trim($content))) {
						if ($preserveHtml) {
							$truncated = false;
							$content = multilang_limit_words_html($content, $wordLimit, $truncated);
						} else {
							$words = preg_split('/\s+/u', strip_tags($content), -1, PREG_SPLIT_NO_EMPTY);
							$content = implode(' ', array_slice($words, 0, $wordLimit));
							if (count($words) > $wordLimit) {
								$content .= '...';
							}
							$content = esc_html($content);
						}
					}
					
					if ($lang !== $current_lang) {
						$open_tag = preg_replace('/(<(?:span|div)([^>]*)>)/', '$1', $open_tag);
						if (strpos($open_tag, 'data-translation=') === false) {
							$open_tag = str_replace('>', ' data-translation="' . esc_attr($content) . '">', $open_tag);
						}
						$content = '';
					}
					
					return $open_tag . $content . $close_tag;
				},
				$html
			);
			
			foreach ($available_langs as $lang) {
				if (!in_array($lang, $existing_langs)) {
					$fallback_content = $default_content;
					
					if ($wordLimit > 0 && !empty(trim($fallback_content))) {
						if ($preserveHtml) {
							$truncated = false;
							$fallback_content = multilang_limit_words_html($fallback_content, $wordLimit, $truncated);
						} else {
							$words = preg_split('/\s+/u', strip_tags($fallback_content), -1, PREG_SPLIT_NO_EMPTY);
							$fallback_content = implode(' ', array_slice($words, 0, $wordLimit));
							if (count($words) > $wordLimit) {
								$fallback_content .= '...';
							}
							$fallback_content = esc_html($fallback_content);
						}
					}
					
					if ($lang !== $current_lang) {
						$missing_div = '<div class="wp-block-group lang-' . $lang . ' has-global-padding is-layout-constrained wp-block-group-is-layout-constrained" data-translation="' . esc_attr($fallback_content) . '"></div>';
					} else {
						$missing_div = '<div class="wp-block-group lang-' . $lang . ' has-global-padding is-layout-constrained wp-block-group-is-layout-constrained">' . $fallback_content . '</div>';
					}
					$html .= $missing_div;
				}
			}
			
			$style_attrs = array();
			if (!empty($attributes['style'])) {
				if (is_string($attributes['style'])) {
					$style_attrs['style'] = $attributes['style'];
				} elseif (is_array($attributes['style'])) {
					$style_attrs['style'] = '';
				}
			}
			
			$wrapper = get_block_wrapper_attributes(array_merge(['class' => 'wp-block-multilang-excerpt'], $style_attrs));
			
			return '<div ' . $wrapper . '>' . $html . '</div>';
		}
	}
	return '';
}

// Enqueue CSS
function multilang_excerpt_enqueue_styles() {
    wp_enqueue_style(
        'multilang-excerpt-block',
        plugins_url('/gutenberg/multilang-excerpt-block.css', dirname(__FILE__)),
        array(),
        null
    );
}
add_action('wp_enqueue_scripts', 'multilang_excerpt_enqueue_styles', 0);
add_action('enqueue_block_editor_assets', 'multilang_excerpt_enqueue_styles', 0);