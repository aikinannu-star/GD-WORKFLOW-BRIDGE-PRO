<?php
namespace GDWB\Integrations;

use GDWB\Core\ModuleInterface;
use GDWB\Core\ServiceContainer;
use GDWB\Admin\License_Manager;

if (!defined('ABSPATH')) exit;

class Analytics implements ModuleInterface {

    private ServiceContainer $container;

    public function init(ServiceContainer $container): void {
        $this->container = $container;
        // Disable analytics when no active license is present
        $license = new License_Manager();
        if (!$license->is_license_active()) {
            return;
        }
        add_action('gdwb_project_created', [$this, 'track_project_created']);
        add_action('gdwb_file_uploaded', [$this, 'track_file_uploaded']);
        add_action('save_post_gdwb_project', [$this, 'track_status_change'], 20, 3);
    }

    public function track_project_created($project_id) {
        $this->record_metric('projects_created', 1);
    }

    public function track_file_uploaded($project_id, $attachment_id) {
        $this->record_metric('files_uploaded', 1);
    }

    public function track_status_change($post_id, $post, $update) {
        if ($post->post_type !== 'gdwb_project') {
            return;
        }

        $old_status = get_post_meta($post_id, '_gdwb_last_status', true) ?: $post->post_status;
        if ($old_status !== $post->post_status) {
            $this->record_metric("status_$post->post_status", 1);
        }
    }

    private function record_metric($metric_name, $value = 1) {
        global $wpdb;
        $table = $wpdb->prefix . 'gdwb_analytics';

        $wpdb->insert($table, [
            'metric_name' => sanitize_text_field($metric_name),
            'metric_value' => (int)$value,
            'recorded_at' => current_time('mysql'),
        ]);
    }

    public function get_metrics($days = 30) {
        global $wpdb;
        $table = $wpdb->prefix . 'gdwb_analytics';
        $date_threshold = date('Y-m-d H:i:s', strtotime("-$days days"));

        return $wpdb->get_results($wpdb->prepare(
            "SELECT metric_name, SUM(metric_value) as total FROM $table WHERE recorded_at >= %s GROUP BY metric_name",
            $date_threshold
        ));
    }

    public function get_dashboard_stats() {
        $stats = [
            'total_projects' => wp_count_posts('gdwb_project')->publish ?? 0,
            'total_files' => count(get_posts(['post_type' => 'attachment', 'posts_per_page' => -1, 'post_parent__in' => get_posts(['post_type' => 'gdwb_project', 'fields' => 'ids', 'posts_per_page' => -1])])),
            'metrics' => $this->get_metrics(30),
        ];
        return $stats;
    }
}
