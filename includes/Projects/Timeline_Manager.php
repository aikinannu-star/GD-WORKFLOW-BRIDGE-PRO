<?php
namespace GDWB\Projects;

use GDWB\Core\ModuleInterface;
use GDWB\Core\ServiceContainer;

if (!defined('ABSPATH')) exit;

class Timeline_Manager implements ModuleInterface {

    private ServiceContainer $container;

    public function init(ServiceContainer $container): void {
        $this->container = $container;
        add_action('gdwb_project_created', [$this, 'log_created']);
        add_action('gdwb_project_updated', [$this, 'log_updated']);
        add_action('gdwb_file_uploaded', [$this, 'log_file_uploaded'], 10, 2);
        add_action('save_post_gdwb_project', [$this, 'log_status_change'], 10, 3);
    }

    public function log_created($project_id) {
        $this->add_timeline_entry($project_id, 'created', __('Project created', 'gdwb'));
    }

    public function log_updated($project_id) {
        $this->add_timeline_entry($project_id, 'updated', __('Project updated', 'gdwb'));
    }

    public function log_file_uploaded($project_id, $attachment_id) {
        $file_name = get_the_title($attachment_id);
        $message = sprintf(__('File uploaded: %s', 'gdwb'), $file_name);
        $this->add_timeline_entry($project_id, 'file_uploaded', $message);
    }

    public function log_status_change($post_id, $post, $update) {
        if ($post->post_type !== 'gdwb_project') {
            return;
        }

        $old_status = get_post_meta($post_id, '_gdwb_last_status', true) ?: $post->post_status;
        if ($old_status !== $post->post_status) {
            $message = sprintf(__('Status changed to: %s', 'gdwb'), $post->post_status);
            $this->add_timeline_entry($post_id, 'status_changed', $message);
            update_post_meta($post_id, '_gdwb_last_status', $post->post_status);
        }
    }

    private function add_timeline_entry($project_id, $type, $message) {
        global $wpdb;
        $table = $wpdb->prefix . 'gdwb_timeline';

        $wpdb->insert($table, [
            'project_id' => (int)$project_id,
            'event_type' => sanitize_text_field($type),
            'message' => sanitize_textarea_field($message),
            'user_id' => get_current_user_id(),
            'created_at' => current_time('mysql'),
        ]);
    }

    public function get_timeline($project_id, $limit = 50) {
        global $wpdb;
        $table = $wpdb->prefix . 'gdwb_timeline';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE project_id = %d ORDER BY created_at DESC LIMIT %d",
            $project_id,
            $limit
        ));
    }
}
