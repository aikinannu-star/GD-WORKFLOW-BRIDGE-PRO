<?php
namespace GDWB\Projects;

use GDWB\Core\ModuleInterface;
use GDWB\Core\ServiceContainer;

if (!defined('ABSPATH')) exit;

class Chat_Manager implements ModuleInterface {

    private ServiceContainer $container;

    public function init(ServiceContainer $container): void {
        $this->container = $container;
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('wp_ajax_gdwb_send_message', [$this, 'handle_message']);
        add_action('wp_ajax_nopriv_gdwb_send_message', [$this, 'handle_message']);
    }

    public function register_routes(): void {
        register_rest_route('gdwb/v1', '/chat/(?P<project_id>\d+)/messages', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'get_messages'],
            'permission_callback' => [$this, 'chat_permission'],
        ]);

        register_rest_route('gdwb/v1', '/chat/(?P<project_id>\d+)/send', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'send_message_rest'],
            'permission_callback' => [$this, 'chat_permission'],
        ]);
    }

    public function chat_permission(\WP_REST_Request $request) {
        $project_id = (int)$request['project_id'];
        $post = get_post($project_id);

        if (!$post || $post->post_type !== 'gdwb_project') {
            return false;
        }

        return is_user_logged_in() && ($post->post_author == get_current_user_id() || current_user_can('manage_gdwb_projects'));
    }

    public function get_messages(\WP_REST_Request $request) {
        global $wpdb;
        $project_id = (int)$request['project_id'];
        $table = $wpdb->prefix . 'gdwb_chat';

        $messages = $wpdb->get_results($wpdb->prepare(
            "SELECT id, user_id, message, is_private, created_at FROM $table WHERE project_id = %d ORDER BY created_at ASC LIMIT 500",
            $project_id
        ));

        $response = [];
        foreach ($messages as $msg) {
            $user = get_user_by('id', $msg->user_id);
            $response[] = [
                'id' => (int)$msg->id,
                'author' => $user ? $user->display_name : 'System',
                'author_id' => (int)$msg->user_id,
                'message' => wp_kses_post($msg->message),
                'is_private' => (bool)$msg->is_private,
                'created' => $msg->created_at,
            ];
        }

        return rest_ensure_response($response);
    }

    public function send_message_rest(\WP_REST_Request $request) {
        $project_id = (int)$request['project_id'];
        $message = sanitize_textarea_field($request->get_param('message'));
        $is_private = (bool)$request->get_param('is_private');

        if (empty($message)) {
            return new \WP_Error('empty_message', __('Message cannot be empty', 'gdwb'), ['status' => 400]);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'gdwb_chat';

        $result = $wpdb->insert($table, [
            'project_id' => $project_id,
            'user_id' => get_current_user_id(),
            'message' => $message,
            'is_private' => $is_private ? 1 : 0,
            'created_at' => current_time('mysql'),
        ]);

        if (!$result) {
            return new \WP_Error('db_error', __('Failed to save message', 'gdwb'), ['status' => 500]);
        }

        do_action('gdwb_message_sent', $project_id, $wpdb->insert_id, get_current_user_id());

        return rest_ensure_response(['success' => true, 'message_id' => $wpdb->insert_id]);
    }

    public function handle_message() {
        check_ajax_referer('gdwb_chat_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Not authenticated', 'gdwb')]);
        }

        $project_id = (int)($_POST['project_id'] ?? 0);
        $message = sanitize_textarea_field($_POST['message'] ?? '');

        if (!$project_id || empty($message)) {
            wp_send_json_error(['message' => __('Missing parameters', 'gdwb')]);
        }

        $request = new \WP_REST_Request('POST', '/gdwb/v1/chat/' . $project_id . '/send');
        $request->set_body_params(['message' => $message, 'is_private' => isset($_POST['is_private'])]);

        $response = rest_do_request($request);
        if (in_array($response->get_status(), [200, 201])) {
            wp_send_json_success($response->get_data());
        } else {
            wp_send_json_error($response->get_data());
        }
    }
}
