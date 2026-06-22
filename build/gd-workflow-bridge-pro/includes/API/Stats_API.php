<?php
namespace GDWB\API;

use GDWB\Core\ModuleInterface;
use GDWB\Core\ServiceContainer;

if (!defined('ABSPATH')) exit;

class Stats_API implements ModuleInterface {

    private ServiceContainer $container;

    public function init(ServiceContainer $container): void {
        $this->container = $container;
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void {
        register_rest_route('gdwb/v1', '/stats', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'get_stats'],
            'permission_callback' => [$this, 'stats_permission'],
        ]);
    }

    public function stats_permission(\WP_REST_Request $request) {
        // Admins always have access
        if (current_user_can('manage_options')) {
            return true;
        }

        // Users with analytics capability can view site-wide stats
        if (current_user_can('view_gdwb_analytics')) {
            return true;
        }

        // Allow logged-in users to view their own stats
        if (is_user_logged_in()) {
            $user_id = $request->get_param('user_id');
            if (!$user_id || get_current_user_id() === intval($user_id)) {
                return true;
            }
        }

        return false;
    }

    public function get_stats(\WP_REST_Request $request) {
        global $wpdb;

        $user_id = $request->get_param('user_id');
        $projects_table = $wpdb->prefix . 'gdwb_projects';
        $files_table = $wpdb->prefix . 'gdwb_files';
        $orders_table = $wpdb->posts;
        $postmeta = $wpdb->postmeta;

        if ($user_id) {
            $total_projects = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $projects_table p JOIN {$wpdb->posts} wp ON p.post_id = wp.ID WHERE wp.post_author = %d", $user_id));
            $total_files = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $files_table WHERE uploaded_by = %d", $user_id));
        } else {
            $total_projects = (int) $wpdb->get_var("SELECT COUNT(*) FROM $projects_table");
            $total_files = (int) $wpdb->get_var("SELECT COUNT(*) FROM $files_table");
        }

        $total_orders = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$orders_table} WHERE post_type = 'shop_order' AND post_status LIKE 'wc-%'");
        $total_revenue = $wpdb->get_var($wpdb->prepare("SELECT SUM(CAST(pm.meta_value AS DECIMAL(10,2))) FROM {$orders_table} o JOIN {$postmeta} pm ON o.ID = pm.post_id WHERE o.post_type = 'shop_order' AND pm.meta_key = %s", '_order_total'));
        $total_revenue = floatval($total_revenue) ?: 0.0;
        $this_month = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$orders_table} WHERE post_type = 'shop_order' AND post_status LIKE 'wc-%' AND post_date >= %s", date('Y-m-01 00:00:00')));

        $response = [
            'total_projects' => $total_projects,
            'total_files' => $total_files,
            'total_orders' => $total_orders,
            'total_revenue' => $total_revenue,
            'this_month' => $this_month,
        ];

        return rest_ensure_response($response);
    }
}
