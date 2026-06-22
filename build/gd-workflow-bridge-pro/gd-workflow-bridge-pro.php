<?php
/**
 * Plugin Name: GD Workflow Bridge Pro
 * Description: Advanced WooCommerce workflow automation for service projects with live chat, file vault, and customer portal.
 * Version: 3.4.0
 * Author: Aikin Annu
 * Text Domain: gdwb
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) exit;

define('GDWB_VERSION', '3.4.0');
define('GDWB_PATH', plugin_dir_path(__FILE__));
define('GDWB_URL', plugin_dir_url(__FILE__));

// Composer autoload (if available)
if (file_exists(GDWB_PATH . 'vendor/autoload.php')) {
    require_once GDWB_PATH . 'vendor/autoload.php';
}

require_once GDWB_PATH . 'includes/Core/Plugin.php';

register_uninstall_hook(__FILE__, 'gdwb_uninstall');

function gdwb_uninstall() {
    require_once GDWB_PATH . 'includes/Core/Uninstall.php';
}

GDWB\Core\Plugin::instance();
