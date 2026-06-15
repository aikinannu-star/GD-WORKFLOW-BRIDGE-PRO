<?php
namespace GDWB\Projects;

use GDWB\Core\ModuleInterface;
use GDWB\Core\ServiceContainer;

if (!defined('ABSPATH')) exit;

class Forms_Manager implements ModuleInterface {

    private ServiceContainer $container;

    public function init(ServiceContainer $container): void {
        $this->container = $container;
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('wp_ajax_gdwb_submit_form', [$this, 'handle_form_submission']);
    }

    public function register_routes(): void {
        register_rest_route('gdwb/v1', '/forms/(?P<project_id>\d+)/revision-request', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'submit_revision_request'],
            'permission_callback' => [$this, 'form_permission'],
        ]);

        register_rest_route('gdwb/v1', '/forms/(?P<project_id>\d+)/requirements', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'submit_requirements_upload'],
            'permission_callback' => [$this, 'form_permission'],
        ]);

        register_rest_route('gdwb/v1', '/forms/(?P<project_id>\d+)/submissions', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'get_form_submissions'],
            'permission_callback' => [$this, 'form_permission'],
        ]);
    }

    public function form_permission(\WP_REST_Request $request) {
        $project_id = (int)$request['project_id'];
        $post = get_post($project_id);

        if (!$post || $post->post_type !== 'gdwb_project') {
            return false;
        }

        // Allow project author, admin, or order customer
        if ($post->post_author == get_current_user_id() || current_user_can('manage_gdwb_projects')) {
            return true;
        }

        $order_id = get_post_meta($project_id, '_gdwb_order_id', true);
        if ($order_id && function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
            if ($order && $order->get_customer_id() == get_current_user_id()) {
                return true;
            }
        }

        return false;
    }

    public function submit_revision_request(\WP_REST_Request $request) {
        $project_id = (int)$request['project_id'];
        $title = sanitize_text_field($request->get_param('title'));
        $description = sanitize_textarea_field($request->get_param('description'));
        $priority = sanitize_text_field($request->get_param('priority')); // low, medium, high

        if (empty($title) || empty($description)) {
            return new \WP_Error('missing_fields', __('Title and description are required', 'gdwb'), ['status' => 400]);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'gdwb_timeline';

        $wpdb->insert($table, [
            'project_id' => $project_id,
            'event_type' => 'revision_request',
            'message' => maybe_serialize([
                'title' => $title,
                'description' => $description,
                'priority' => $priority,
            ]),
            'user_id' => get_current_user_id(),
            'created_at' => current_time('mysql'),
        ]);

        do_action('gdwb_revision_requested', $project_id, $title, $priority);

        return rest_ensure_response(['success' => true, 'message' => __('Revision request submitted', 'gdwb')]);
    }

    public function submit_requirements_upload(\WP_REST_Request $request) {
        $project_id = (int)$request['project_id'];
        $requirements = sanitize_textarea_field($request->get_param('requirements'));
        $deadline = sanitize_text_field($request->get_param('deadline'));

        if (empty($requirements)) {
            return new \WP_Error('missing_requirements', __('Requirements are required', 'gdwb'), ['status' => 400]);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'gdwb_timeline';

        $wpdb->insert($table, [
            'project_id' => $project_id,
            'event_type' => 'requirements_submitted',
            'message' => maybe_serialize([
                'requirements' => $requirements,
                'deadline' => $deadline,
            ]),
            'user_id' => get_current_user_id(),
            'created_at' => current_time('mysql'),
        ]);

        do_action('gdwb_requirements_submitted', $project_id, $requirements, $deadline);

        return rest_ensure_response(['success' => true, 'message' => __('Requirements submitted', 'gdwb')]);
    }

    public function get_form_submissions(\WP_REST_Request $request) {
        global $wpdb;
        $project_id = (int)$request['project_id'];
        $table = $wpdb->prefix . 'gdwb_timeline';

        $submissions = $wpdb->get_results($wpdb->prepare(
            "SELECT id, event_type, message, user_id, created_at FROM $table WHERE project_id = %d AND event_type IN ('revision_request', 'requirements_submitted') ORDER BY created_at DESC",
            $project_id
        ));

        $response = [];
        foreach ($submissions as $sub) {
            $payload = maybe_unserialize($sub->message) ?: [];
            $user = get_user_by('id', $sub->user_id);
            $response[] = [
                'id' => (int)$sub->id,
                'type' => $sub->event_type,
                'author' => $user ? $user->display_name : 'Unknown',
                'data' => $payload,
                'created' => $sub->created_at,
            ];
        }

        return rest_ensure_response($response);
    }

    public function handle_form_submission() {
        check_ajax_referer('gdwb_forms_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Not authenticated', 'gdwb')]);
        }

        $project_id = (int)($_POST['project_id'] ?? 0);
        $form_type = sanitize_text_field($_POST['form_type'] ?? '');

        if (!$project_id || !$form_type) {
            wp_send_json_error(['message' => __('Missing parameters', 'gdwb')]);
        }

        $endpoint = $form_type === 'revision' ? 'revision-request' : 'requirements';
        $request = new \WP_REST_Request('POST', '/gdwb/v1/forms/' . $project_id . '/' . $endpoint);
        $request->set_body_params($_POST);

        $response = rest_do_request($request);
        if (in_array($response->get_status(), [200, 201])) {
            wp_send_json_success($response->get_data());
        } else {
            wp_send_json_error($response->get_data());
        }
    }
}
