<?php
namespace GDWB\Core;

if (!defined('ABSPATH')) exit;

class Activator {
    public function activate(): void {
        $this->ensure_product_categories();
        $this->create_tables();
        update_option('gdwb_version', GDWB_VERSION);
    }

    private function ensure_product_categories(): void {
        // Ensure WooCommerce product categories exist (safe if WooCommerce not active)
        if (!function_exists('taxonomy_exists') || !taxonomy_exists('product_cat')) {
            return;
        }

        $parents = [
            'Physical Products' => 'physical-products',
            'Digital Products' => 'digital-products',
            'Services' => 'services',
        ];

        foreach ($parents as $name => $slug) {
            $existing = term_exists($name, 'product_cat');
            if ($existing === 0 || $existing === null) {
                $term = wp_insert_term($name, 'product_cat', ['slug' => $slug]);
                if (!is_wp_error($term) && isset($term['term_id'])) {
                    if ($name === 'Services') {
                        update_option('gdwb_service_category_id', (int)$term['term_id']);
                    }
                }
            } else {
                $term = get_term_by('name', $name, 'product_cat');
                if ($name === 'Services' && $term && !is_wp_error($term)) {
                    update_option('gdwb_service_category_id', (int)$term->term_id);
                }
            }
        }
    }

    private function create_tables(): void {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $tables = [
            'gdwb_projects' => "CREATE TABLE {$wpdb->prefix}gdwb_projects (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                order_id bigint(20) unsigned NULL,
                post_id bigint(20) unsigned NULL,
                status varchar(50) NOT NULL DEFAULT 'new',
                data longtext NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY order_id (order_id)
            ) $charset_collate;",

            'gdwb_timeline' => "CREATE TABLE {$wpdb->prefix}gdwb_timeline (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                project_id bigint(20) unsigned NOT NULL,
                event_type varchar(100) NOT NULL,
                message longtext NOT NULL,
                user_id bigint(20) unsigned NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY project_id (project_id),
                KEY event_type (event_type)
            ) $charset_collate;",

            'gdwb_analytics' => "CREATE TABLE {$wpdb->prefix}gdwb_analytics (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                metric_name varchar(255) NOT NULL,
                metric_value bigint(20) NOT NULL DEFAULT 1,
                recorded_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY metric_name (metric_name),
                KEY recorded_at (recorded_at)
            ) $charset_collate;",

            'gdwb_audit_log' => "CREATE TABLE {$wpdb->prefix}gdwb_audit_log (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                project_id bigint(20) unsigned NOT NULL,
                action varchar(255) NOT NULL,
                user_id bigint(20) unsigned NULL,
                data longtext NULL,
                ip_address varchar(45) NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY project_id (project_id),
                KEY action (action),
                KEY user_id (user_id),
                KEY created_at (created_at)
            ) $charset_collate;",

            'gdwb_files' => "CREATE TABLE {$wpdb->prefix}gdwb_files (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                project_id bigint(20) unsigned NOT NULL,
                attachment_id bigint(20) unsigned NULL,
                file_name varchar(255) NOT NULL,
                file_type varchar(100) NULL,
                file_size bigint(20) NULL,
                uploaded_by bigint(20) unsigned NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY project_id (project_id),
                KEY uploaded_by (uploaded_by)
            ) $charset_collate;",

            'gdwb_chat' => "CREATE TABLE {$wpdb->prefix}gdwb_chat (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                project_id bigint(20) unsigned NOT NULL,
                user_id bigint(20) unsigned NULL,
                message longtext NOT NULL,
                is_private tinyint(1) NOT NULL DEFAULT 0,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY project_id (project_id),
                KEY user_id (user_id),
                KEY created_at (created_at)
            ) $charset_collate;",

            'gdwb_notifications' => "CREATE TABLE {$wpdb->prefix}gdwb_notifications (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint(20) unsigned NOT NULL,
                type varchar(100) NOT NULL,
                project_id bigint(20) unsigned NULL,
                payload longtext NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY user_id (user_id),
                KEY project_id (project_id)
            ) $charset_collate;",
        ];

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        foreach ($tables as $name => $sql) {
            dbDelta($sql);
        }
    }
}
