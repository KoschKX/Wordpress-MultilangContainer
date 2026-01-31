<?php
/*
 * WordPress Block Editor Stuff
 *
 * All the Gutenberg block editor code goes here
 */

// Block direct access
if (!defined('ABSPATH')) {
    exit;
}

// Bring in Avada element
require_once plugin_dir_path(__FILE__) . '../blocks/avada/avada-element.php';

// Bring in Gutenberg blocks
require_once plugin_dir_path(__FILE__) . '../blocks/gutenberg/multilang-container-block.php';
require_once plugin_dir_path(__FILE__) . '../blocks/gutenberg/multilang-excerpt-block.php';