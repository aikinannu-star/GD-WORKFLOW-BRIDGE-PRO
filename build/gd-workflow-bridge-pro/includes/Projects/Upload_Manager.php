<?php
namespace GDWB\Projects;

use GDWB\Core\ModuleInterface;
use GDWB\Core\ServiceContainer;

if (!defined('ABSPATH')) exit;

class Upload_Manager implements ModuleInterface {

    private ServiceContainer $container;
    private const MAX_FILE_SIZE = 10485760; // 10MB
    private const ALLOWED_TYPES = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'application/zip'];

    public function init(ServiceContainer $container): void {
        $this->container = $container;
        add_action('wp_ajax_gdwb_upload_file', [$this, 'handle_file_upload']);
    }

    public function handle_file_upload() {
        check_ajax_referer('gdwb_upload_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('Permission denied', 'gdwb')]);
        }

        if (!isset($_FILES['file']) || !isset($_POST['project_id'])) {
            wp_send_json_error(['message' => __('Missing file or project ID', 'gdwb')]);
        }

        $project_id = (int)$_POST['project_id'];
        $post = get_post($project_id);

        if (!$post || $post->post_type !== 'gdwb_project') {
            wp_send_json_error(['message' => __('Invalid project', 'gdwb')]);
        }

        $file = $_FILES['file'];
        if ($file['size'] > self::MAX_FILE_SIZE) {
            wp_send_json_error(['message' => __('File too large (max 10MB)', 'gdwb')]);
        }

        $file_type = $file['type'];
        if (!in_array($file_type, self::ALLOWED_TYPES, true)) {
            wp_send_json_error(['message' => __('File type not allowed', 'gdwb')]);
        }

        $attachment_id = $this->upload_file($file, $project_id);
        if (is_wp_error($attachment_id)) {
            wp_send_json_error(['message' => $attachment_id->get_error_message()]);
        }

        do_action('gdwb_file_uploaded', $project_id, $attachment_id);

        wp_send_json_success(['attachment_id' => $attachment_id]);
    }

    private function upload_file($file, $project_id) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $upload_overrides = ['test_form' => false];
        $uploaded_file = wp_handle_upload($file, $upload_overrides);

        if (isset($uploaded_file['error'])) {
            return new \WP_Error('upload_error', $uploaded_file['error']);
        }

        $attachment = [
            'post_mime_type' => $uploaded_file['type'],
            'post_title' => sanitize_file_name($file['name']),
            'post_content' => '',
            'post_status' => 'inherit',
        ];

        $attachment_id = wp_insert_attachment($attachment, $uploaded_file['file'], $project_id);

        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }

        wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $uploaded_file['file']));

        return $attachment_id;
    }
}
