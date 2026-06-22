<?php
namespace GDWB\Core;

if (!defined('ABSPATH')) exit;

class PluginSetup {
    public static function verify_setup(): array {
        $status = [
            'db_tables_ok' => self::check_db_tables(),
            'modules_loaded' => self::check_modules(),
            'composer_autoload' => file_exists(GDWB_PATH . 'vendor/autoload.php'),
            'php_version' => version_compare(PHP_VERSION, '8.0', '>='),
        ];
        return $status;
    }

    private static function check_db_tables(): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'gdwb_projects';
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    private static function check_modules(): bool {
        return class_exists('GDWB\\Frontend\\Shortcodes') && 
               class_exists('GDWB\\Projects\\Project_Manager') &&
               class_exists('GDWB\\API\\Rest_API');
    }
}
