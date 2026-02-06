<?php
/*
 * Multilang Container - Excerpt Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('multilang_get_options_file_path')) {
    function multilang_get_options_file_path() {
        $upload_dir = wp_upload_dir();
        $multilang_dir = trailingslashit($upload_dir['basedir']) . 'multilang/';
        if (!is_dir($multilang_dir)) {
            wp_mkdir_p($multilang_dir);
        }
        return $multilang_dir . 'options.json';
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

if (!function_exists('multilang_get_excerpt_line_limit_style')) {
    function multilang_get_excerpt_line_limit_style() {
        $options = multilang_get_options();
        $enabled = isset($options['excerpt_line_limit_enabled']) ? (int) $options['excerpt_line_limit_enabled'] : 0;
        $limit = isset($options['excerpt_line_limit']) ? absint($options['excerpt_line_limit']) : 0;

        if ($enabled !== 1 || $limit <= 0) {
            return '';
        }

        return ' style="display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:' . $limit . ';overflow:hidden;"';
    }
}

function multilang_get_formatted_excerpt($post_id = null) {
    // Skip on backend operations
    if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
        return '';
    }
    
    if (!$post_id) {
        $post_id = get_the_ID();
    }
    
    $excerpts = get_post_meta($post_id, '_multilang_excerpts', true);
    
    if (empty($excerpts) || !is_array($excerpts)) {
        return '';
    }
    
    $default_lang = get_multilang_default_language();
    $current_lang = multilang_get_current_language();
    
    if (!$default_lang) {
        $default_lang = 'en';
    }
    if (!$current_lang) {
        $current_lang = $default_lang;
    }
    
    // Build excerpt HTML with language wrappers
    $style_attr = multilang_get_excerpt_line_limit_style();
    $formatted_excerpt = '<p class="multilang-wrapper"' . $style_attr . '>';
    $all_langs = array();
  
    if (function_exists('get_option')) {
        $all_langs = get_option('multilang_container_languages', array_keys($excerpts));
    }
    if (empty($all_langs)) {
        $all_langs = array_keys($excerpts);
    }
    $default_excerpt = isset($excerpts[$default_lang]) ? $excerpts[$default_lang] : '';
    foreach ($all_langs as $lang) {
        $excerpt = isset($excerpts[$lang]) ? $excerpts[$lang] : '';
        if (empty($excerpt) && !empty($default_excerpt)) {
            $excerpt = $default_excerpt;
        }
        $is_current = ($lang === $current_lang);
        $data_attr = '';
        if (!$is_current) {
            $data_attr = ' data-translation="' . esc_attr($excerpt) . '"';
        }
        $formatted_excerpt .= '<span class="translate lang-' . esc_attr($lang) . '" data-lang="' . esc_attr($lang) . '"' . $data_attr . '>';
        if ($is_current) {
            $formatted_excerpt .= $excerpt;
        }
        $formatted_excerpt .= '</span>';
    }
    $formatted_excerpt .= '</p>';
    return $formatted_excerpt;
}

if (!function_exists('multilang_clean_excerpt_content')) {
    function multilang_clean_excerpt_content($text) {
        if (is_admin() || wp_doing_ajax()) {
            return $text;
        }
        
        $current_lang = multilang_get_current_language();
        if (!$current_lang) {
            return $text;
        }
        
        if (strpos($text, 'translate lang-') === false) {
            return $text;
        }
        
        $pattern = '/<span class="translate lang-(' . $current_lang . ')"[^>]*>(.*?)<\/span>/s';
        preg_match_all($pattern, $text, $matches);
        
        $result = '';
        if (!empty($matches[2])) {
            foreach ($matches[2] as $match) {
                $result .= $match . ' ';
            }
        }
        
        $other_langs_pattern = '/<span class="translate lang-(?!' . $current_lang . ')[a-z]{2}"[^>]*>.*?<\/span>/s';
        $text = preg_replace($other_langs_pattern, '', $text);
        
        if (!empty($result)) {
            return trim($result);
        }
        
        return $text;
    }
}

if (!function_exists('multilang_clean_auto_excerpt')) {
    function multilang_clean_auto_excerpt($text, $raw_excerpt) {
        if (is_admin() || wp_doing_ajax()) {
            return $text;
        }
        
        if (!empty($raw_excerpt)) {
            return $text;
        }
        
        global $post;
        if (!$post) {
            return $text;
        }
        
        $current_lang = multilang_get_current_language();
        if (!$current_lang) {
            return $text;
        }
        
        $content = $post->post_content;
        
        if (strpos($content, 'translate lang-') !== false) {
            $pattern = '/<span class="translate lang-' . preg_quote($current_lang, '/') . '"[^>]*>(.*?)<\/span>/s';
            preg_match_all($pattern, $content, $matches);
            
            if (!empty($matches[1])) {
                $content = implode(' ', $matches[1]);
            }
        }
        
        $content = strip_shortcodes($content);
        $content = excerpt_remove_blocks($content);
        $content = str_replace(']]>', ']]&gt;', $content);
        $excerpt_length = apply_filters('excerpt_length', 55);
        $excerpt_more = apply_filters('excerpt_more', ' [&hellip;]');
        $text = wp_trim_words($content, $excerpt_length, $excerpt_more);
        
        return $text;
    }
}

add_filter('get_the_excerpt', 'multilang_use_translated_excerpt', 5);
add_filter('get_the_excerpt', 'multilang_clean_excerpt_content', 999);
add_filter('get_the_excerpt', 'multilang_apply_excerpt_line_limit', 1000);
add_filter('wp_trim_excerpt', 'multilang_clean_auto_excerpt', 10, 2);
add_filter('the_excerpt', 'multilang_clean_excerpt_content', 999);
add_filter('the_excerpt', 'multilang_apply_excerpt_line_limit', 1000);

if (!function_exists('multilang_use_translated_excerpt')) {
    function multilang_use_translated_excerpt($excerpt) {
        if (is_admin() || wp_doing_ajax()) {
            return $excerpt;
        }
        
        $formatted_excerpt = multilang_get_formatted_excerpt();
        
        if (!empty($formatted_excerpt)) {
            return $formatted_excerpt;
        }
        
        return $excerpt;
    }
}

if (!function_exists('multilang_apply_excerpt_line_limit')) {
    function multilang_apply_excerpt_line_limit($excerpt) {
        if (is_admin() || wp_doing_ajax()) {
            return $excerpt;
        }

        $style_attr = multilang_get_excerpt_line_limit_style();
        if (empty($style_attr)) {
            return $excerpt;
        }

        if (stripos($excerpt, '<p') !== false) {
            return preg_replace('/<p(\s[^>]*)?>/i', '<p$1' . $style_attr . '>', $excerpt, 1);
        }

        return '<p class="multilang-wrapper"' . $style_attr . '>' . $excerpt . '</p>';
    }
}

// Avada theme compatibility: override excerpt output
function multilang_override_fusion_excerpt() {
    if (!function_exists('fusion_builder_get_post_content')) {
        function fusion_builder_get_post_content($page = '', $strip_html = 'yes', $amount = 285, $strip_shortcodes = false) {
            $multilang_excerpts = get_post_meta(get_the_ID(), '_multilang_excerpts', true);
            $has_multilang_excerpt = !empty($multilang_excerpts) && is_array($multilang_excerpts);
            
            if (!has_excerpt() && !$has_multilang_excerpt) {
                return '';
            }
            
            if ($has_multilang_excerpt) {
                return multilang_get_formatted_excerpt();
            }
            
            return fusion_builder_get_post_content_excerpt($amount, $strip_html === 'yes');
        }
    }
}
add_action('after_setup_theme', 'multilang_override_fusion_excerpt', 1);
