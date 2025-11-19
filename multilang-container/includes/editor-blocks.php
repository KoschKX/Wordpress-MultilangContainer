<?php
/**
 * WordPress Block Editor Functionality
 * 
 * This file contains all block editor (Gutenberg) specific functionality.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Include Avada element
require_once plugin_dir_path(__FILE__) . '../blocks/avada/avada-element.php';

// Include Gutenberg blocks
require_once plugin_dir_path(__FILE__) . '../blocks/gutenberg/multilang-container-block.php';
require_once plugin_dir_path(__FILE__) . '../blocks/gutenberg/multilang-excerpt-block.php';