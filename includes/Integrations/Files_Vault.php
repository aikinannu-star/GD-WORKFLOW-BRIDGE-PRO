<?php
namespace GDWB\Integrations;

use GDWB\Core\ModuleInterface;
use GDWB\Core\ServiceContainer;
use GDWB\Admin\License_Manager;

if (!defined('ABSPATH')) exit;

class Files_Vault implements ModuleInterface {

    private ServiceContainer $container;
    private const MAX_FILE_SIZE = 52428800; // 50MB
    private const ALLOWED_TYPES = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'application/zip', 'application/x-rar-compressed', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];

    public function init(ServiceContainer $container): void {
        $this->container = $container;
        add_action('wp_ajax_gdwb_vault_upload', [$this, 'handle_vault_upload']);
        add_action('wp_ajax_nopriv_gdwb_vault_upload', [$this, 'handle_vault_upload']);
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void {
        register_rest_route('gdwb/v1', '/vault/(?P<project_id>\d+)/files', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'get_project_files'],
            'permission_callback' => [$this, 'vault_permission'],
        ]);

        register_rest_route('gdwb/v1', '/vault/(?P<project_id>\d+)/upload', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'upload_to_vault'],
            'permission_callback' => [$this, 'vault_permission'],
        ]);

        register_rest_route('gdwb/v1', '/vault/(?P<file_id>\d+)/delete', [
            'methods' => \WP_REST_Server::DELETABLE,
            'callback' => [$this, 'delete_vault_file'],
            'permission_callback' => [$this, 'vault_permission'],
        ]);
    }

    public function vault_permission(\WP_REST_Request $request) {
        // Require active license for Files Vault access
        $license_ok = (new \GDWB\Admin\License_Manager())->is_license_active();
        if (!$license_ok) {
            return new \WP_Error('license_required', __('Files Vault requires an active license', 'gdwb'), ['status' => 403]);
        }

        $project_id = (int)$request['project_id'];
        $post = get_post($project_id);

        if (!$post || $post->post_type !== 'gdwb_project') {
            return false;
        }

        // Allow project author and admins
        if ($post->post_author == get_current_user_id() || current_user_can('manage_gdwb_projects')) {
            return true;
        }

        // Allow order customer (if order linked to this project)
        $order_id = get_post_meta($project_id, '_gdwb_order_id', true);
        if ($order_id && function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
            if ($order && $order->get_customer_id() == get_current_user_id()) {
                return true;
            }
        }

        return false;
    }

    public function get_project_files(\WP_REST_Request $request) {
        global $wpdb;
        $project_id = (int)$request['project_id'];
        $table = $wpdb->prefix . 'gdwb_files';

        $files = $wpdb->get_results($wpdb->prepare(
            "SELECT id, file_name, file_type, file_size, uploaded_by, created_at FROM $table WHERE project_id = %d ORDER BY created_at DESC",
            $project_id
        ));

        $response = [];
        foreach ($files as $file) {
            $response[] = [
                'id' => (int)$file->id,
                'name' => $file->file_name,
                'type' => $file->file_type,
                'size' => (int)$file->file_size,
                'uploader' => get_user_by('id', $file->uploaded_by) ? get_user_by('id', $file->uploaded_by)->display_name : 'Unknown',
                'created' => $file->created_at,
            ];
        }

        return rest_ensure_response($response);
    }

    public function upload_to_vault(\WP_REST_Request $request) {
        $project_id = (int)$request['project_id'];

        if (!isset($_FILES['file'])) {
            return new \WP_Error('missing_file', __('No file provided', 'gdwb'), ['status' => 400]);
        }

        $file = $_FILES['file'];

        if ($file['size'] > self::MAX_FILE_SIZE) {
            return new \WP_Error('file_too_large', __('File exceeds 50MB limit', 'gdwb'), ['status' => 400]);
        }

        if (!in_array($file['type'], self::ALLOWED_TYPES, true)) {
            return new \WP_Error('invalid_type', __('File type not allowed', 'gdwb'), ['status' => 400]);
        }

        $attachment_id = $this->upload_file($file, $project_id);
        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'gdwb_files';
        $wpdb->insert($table, [
            'project_id' => $project_id,
            'attachment_id' => $attachment_id,
            'file_name' => sanitize_file_name($file['name']),
            'file_type' => $file['type'],
            'file_size' => $file['size'],
            'uploaded_by' => get_current_user_id(),
            'created_at' => current_time('mysql'),
        ]);

        do_action('gdwb_file_uploaded', $project_id, $attachment_id);

        return rest_ensure_response(['success' => true, 'file_id' => $wpdb->insert_id, 'attachment_id' => $attachment_id]);
    }

    public function delete_vault_file(\WP_REST_Request $request) {
        global $wpdb;
        $file_id = (int)$request['file_id'];
        $table = $wpdb->prefix . 'gdwb_files';

        $file = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $file_id));
        if (!$file) {
            return new \WP_Error('not_found', __('File not found', 'gdwb'), ['status' => 404]);
        }

        // Permission check: only uploader or admin can delete
        if ($file->uploaded_by != get_current_user_id() && !current_user_can('manage_gdwb_projects')) {
            return new \WP_Error('forbidden', __('Permission denied', 'gdwb'), ['status' => 403]);
        }

        if ($file->attachment_id) {
            wp_delete_attachment($file->attachment_id, true);
        }

        $wpdb->delete($table, ['id' => $file_id]);

        return rest_ensure_response(['success' => true]);
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

    public function handle_vault_upload() {
        check_ajax_referer('gdwb_vault_nonce', 'nonce');

        // Require active license for AJAX uploads
        if (!(new \GDWB\Admin\License_Manager())->is_license_active()) {
            wp_send_json_error(['message' => __('Files Vault requires an active license', 'gdwb')]);
        }

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Not authenticated', 'gdwb')]);
        }

        if (!isset($_FILES['file']) || !isset($_POST['project_id'])) {
            wp_send_json_error(['message' => __('Missing parameters', 'gdwb')]);
        }

        $project_id = (int)$_POST['project_id'];

        $request = new \WP_REST_Request('POST', '/gdwb/v1/vault/' . $project_id . '/upload');
        $request->set_file_params($_FILES);

        $response = rest_do_request($request);
        if (200 === $response->get_status()) {
            wp_send_json_success($response->get_data());
        } else {
            wp_send_json_error($response->get_data());
        }
    }
}
