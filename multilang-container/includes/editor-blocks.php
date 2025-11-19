<?php
/**
 * WordPress Block Editor Functionality
 * 
 * Contains all Gutenberg block editor specific code
 */

// Don't allow direct access to this file
if (!defined('ABSPATH')) {
    exit;
}

// Load Avada element
require_once plugin_dir_path(__FILE__) . '../blocks/avada/avada-element.php';

// Load Gutenberg blocks
require_once plugin_dir_path(__FILE__) . '../blocks/gutenberg/multilang-container-block.php';
require_once plugin_dir_path(__FILE__) . '../blocks/gutenberg/multilang-excerpt-block.php';