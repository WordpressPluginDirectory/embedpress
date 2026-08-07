<?php

/**
 * EmbedPress Core Initialization
 * 
 * This file initializes core EmbedPress functionality including the asset manager
 */

use EmbedPress\Core\AssetManager;
use EmbedPress\Core\LocalizationManager;
use EmbedPress\Includes\Classes\Pdf_Thumbnail_Handler;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Include the AssetManager and LocalizationManager classes
require_once EMBEDPRESS_PLUGIN_DIR_PATH . 'Core/AssetManager.php';
require_once EMBEDPRESS_PLUGIN_DIR_PATH . 'Core/LocalizationManager.php';

// Include Analytics class
require_once EMBEDPRESS_PATH_BASE . 'EmbedPress/Analytics/Analytics.php';

// Initialize AssetManager and LocalizationManager when WordPress is ready
add_action('init', function() {
    AssetManager::init();
    LocalizationManager::init();
    // Generates a poster image once per PDF upload so every render surface can
    // read a cached thumbnail instead of rasterising page 1 in the visitor's
    // browser on every page view (FB #84167).
    Pdf_Thumbnail_Handler::init();
}, 5); // Early priority to ensure it's loaded before other components
