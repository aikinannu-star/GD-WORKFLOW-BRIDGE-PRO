<?php
namespace GDWB\Admin;

use GDWB\Core\ModuleInterface;
use GDWB\Core\ServiceContainer;

if (!defined('ABSPATH')) exit;

class Audit_Logger implements ModuleInterface {

    private ServiceContainer $container;

    public function init(ServiceContainer $container): void {
        $this->container = $container;
        add_action('save_post_gdwb_project', [$this, 'log_post_change'], 20, 3);
        add_action('delete_post', [$this, 'log_post_delete']);
        add_action('wp_ajax_gdwb_upload_file', [$this, 'log_file_upload'], 1);
    }

    public function log_post_change($post_id, $post, $update) {
        if ($post->post_type !== 'gdwb_project') {
            return;
        }

        $action = $update ? 'updated' : 'created';
        $this->log_action($post_id, "project_$action", get_current_user_id(), [
            'post_title' => $post->post_title,
            'post_status' => $post->post_status,
        ]);
    }

    public function log_post_delete($post_id) {
        $post = get_post($post_id);
        if ($post && $post->post_type === 'gdwb_project') {
            $this->log_action($post_id, 'project_deleted', get_current_user_id(), [
                'post_title' => $post->post_title,
            ]);
        }
    }

    public function log_file_upload() {
        $project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
        if ($project_id) {
            $this->log_action($project_id, 'file_uploaded', get_current_user_id(), [
                'file_name' => isset($_FILES['file']['name']) ? $_FILES['file']['name'] : '',
            ]);
        }
    }

    private function log_action($project_id, $action, $user_id, $data = []) {
        global $wpdb;
        $table = $wpdb->prefix . 'gdwb_audit_log';

        $wpdb->insert($table, [
            'project_id' => (int)$project_id,
            'action' => sanitize_text_field($action),
            'user_id' => (int)$user_id,
            'data' => maybe_serialize($data),
            'ip_address' => sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? ''),
            'created_at' => current_time('mysql'),
        ]);
    }

    public function get_audit_log($project_id, $limit = 100) {
        global $wpdb;
        $table = $wpdb->prefix . 'gdwb_audit_log';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE project_id = %d ORDER BY created_at DESC LIMIT %d",
            $project_id,
            $limit
        ));
    }
}
