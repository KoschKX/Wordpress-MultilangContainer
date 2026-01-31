<?php
/*
 * Multilang Container - SEO Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get a clean excerpt for SEO/meta tags, using only the current language
function multilang_get_clean_seo_excerpt($post_id = null) {
    // Skip on backend operations
    if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
        return '';
    }
    
    if (!$post_id) {
        $post_id = get_the_ID();
    }
    
    if (!$post_id) {
        return '';
    }
    
    // Figure out which language to use
    $current_lang = multilang_get_current_language();
    if (!$current_lang) {
        $current_lang = get_multilang_default_language();
        if (!$current_lang) {
            $current_lang = 'en';
        }
    }
    
    // See if the post has excerpts for multiple languages
    $excerpts = get_post_meta($post_id, '_multilang_excerpts', true);
    
    if (!empty($excerpts) && is_array($excerpts) && isset($excerpts[$current_lang])) {
    // Use the excerpt for the current language
        return wp_strip_all_tags($excerpts[$current_lang]);
    }
    
    // If no multilang excerpt, try the regular post excerpt
    $post = get_post($post_id);
    if ($post && !empty($post->post_excerpt)) {
        return wp_strip_all_tags($post->post_excerpt);
    }
    
    // If there's no excerpt, try to make one from the post content
    if ($post && !empty($post->post_content)) {
        $content = $post->post_content;
        
    // If the content has language-specific spans, grab just the current language
        if (strpos($content, 'translate lang-') !== false || strpos($content, 'data-lang="') !== false) {
            // Pull out the content for the current language
            $pattern = '/<span[^>]*(?:class="[^"]*translate[^"]*lang-' . preg_quote($current_lang, '/') . '[^"]*"|data-lang="' . preg_quote($current_lang, '/') . '")[^>]*>(.*?)<\/span>/s';
            preg_match_all($pattern, $content, $matches);
            
            if (!empty($matches[1])) {
                $content = implode(' ', $matches[1]);
            }
        }
        
    // Remove shortcodes and HTML tags
        $content = strip_shortcodes($content);
        $content = wp_strip_all_tags($content);
        
    // Trim the content to make an excerpt
        return wp_trim_words($content, 55, '...');
    }
    
    return '';
}

// Filter Avada's Open Graph description to use the right language
function multilang_filter_avada_og_description($description) {
    if (empty($description)) {
        return $description;
    }
    // Remove any multilang_marker_* from the description
    $description = preg_replace('/multilang_marker_[a-zA-Z0-9_-]+/', '', $description);
    $description = trim($description);

    $post = get_queried_object();
    if (!isset($post->ID)) {
        return $description;
    }

    // Get the excerpt for the current language
    $clean_excerpt = multilang_get_clean_seo_excerpt($post->ID);

    if (!empty($clean_excerpt)) {
        return $clean_excerpt;
    }

    return $description;
}
add_filter('awb_og_meta_description', 'multilang_filter_avada_og_description', 5);

/**
 * Filter for common SEO plugins
 */
function multilang_filter_seo_description($description) {
    if (empty($description)) {
        return $description;
    }
    // Remove any multilang_marker_* from the description
    $description = preg_replace('/multilang_marker_[a-zA-Z0-9_-]+/', '', $description);
    $description = trim($description);
    
    // Get current language
    $current_lang = multilang_get_current_language();
    if (!$current_lang) {
        $current_lang = get_multilang_default_language();
        if (!$current_lang) {
            $current_lang = 'en';
        }
    }
    
    // If description contains HTML language spans, extract only current language
    if (strpos($description, 'translate lang-') !== false || strpos($description, 'data-lang="') !== false) {
        // Pattern for class-based language spans
        $pattern1 = '/<span[^>]*class="[^"]*translate[^"]*lang-' . preg_quote($current_lang, '/') . '[^"]*"[^>]*>(.*?)<\/span>/s';
        preg_match_all($pattern1, $description, $matches1);
        
        if (!empty($matches1[1])) {
            $result = implode(' ', $matches1[1]);
            return wp_strip_all_tags(trim($result));
        }
        
        // Pattern for data-lang attribute
        $pattern2 = '/<span[^>]*data-lang="' . preg_quote($current_lang, '/') . '"[^>]*>(.*?)<\/span>/s';
        preg_match_all($pattern2, $description, $matches2);
        
        if (!empty($matches2[1])) {
            $result = implode(' ', $matches2[1]);
            return wp_strip_all_tags(trim($result));
        }
    }
    
    // If plain text contains all languages merged together, try to get clean excerpt
    $post_id = get_the_ID();
    if ($post_id) {
        $clean_excerpt = multilang_get_clean_seo_excerpt($post_id);
        if (!empty($clean_excerpt)) {
            return $clean_excerpt;
        }
    }
    
    // Fallback: strip all HTML tags
    return wp_strip_all_tags($description);
}

// Yoast SEO filters
add_filter('wpseo_metadesc', 'multilang_filter_seo_description', 999);
add_filter('wpseo_opengraph_desc', 'multilang_filter_seo_description', 999);
add_filter('wpseo_twitter_description', 'multilang_filter_seo_description', 999);

// Rank Math filters
add_filter('rank_math/opengraph/description', 'multilang_filter_seo_description', 999);
add_filter('rank_math/frontend/description', 'multilang_filter_seo_description', 999);

// All in One SEO
add_filter('aioseop_description', 'multilang_filter_seo_description', 999);

// Jetpack
add_filter('jetpack_og_description', 'multilang_filter_seo_description', 999);

// Slim SEO
add_filter('slim_seo_meta_description_tag', 'multilang_filter_seo_description', 999);
