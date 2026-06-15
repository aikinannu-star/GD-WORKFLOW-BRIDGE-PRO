<?php
namespace GDWB\API;

use GDWB\Core\ModuleInterface;
use GDWB\Core\ServiceContainer;

if (!defined('ABSPATH')) exit;

class Rest_API implements ModuleInterface {

    private ServiceContainer $container;

    public function init(ServiceContainer $container): void {
        $this->container = $container;
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void {
        register_rest_route('gdwb/v1', '/projects', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'get_projects'],
            'permission_callback' => [$this, 'get_projects_permissions'],
        ]);

        register_rest_route('gdwb/v1', '/projects/(?P<id>\d+)', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'get_project'],
            'permission_callback' => [$this, 'get_projects_permissions'],
        ]);

        register_rest_route('gdwb/v1', '/projects', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'create_project'],
            'permission_callback' => [$this, 'create_project_permissions'],
            'args' => [
                'title' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'order_id' => ['required' => false, 'sanitize_callback' => 'absint'],
            ],
        ]);
    }

    public function get_projects(\WP_REST_Request $request) {
        $args = [
            'post_type' => 'gdwb_project',
            'post_status' => 'any',
            'posts_per_page' => -1,
        ];
        $posts = get_posts($args);
        $data = [];
        foreach ($posts as $post) {
            $data[] = [
                'id' => $post->ID,
                'title' => get_the_title($post),
                'status' => $post->post_status,
                'order_id' => get_post_meta($post->ID, '_gdwb_order_id', true),
            ];
        }
        return rest_ensure_response($data);
    }

    public function get_project(\WP_REST_Request $request) {
        $id = (int)$request['id'];
        $post = get_post($id);
        if (!$post || $post->post_type !== 'gdwb_project') {
            return new \WP_Error('gdwb_not_found', __('Project not found', 'gdwb'), ['status' => 404]);
        }
        $data = [
            'id' => $post->ID,
            'title' => get_the_title($post),
            'status' => $post->post_status,
            'order_id' => get_post_meta($post->ID, '_gdwb_order_id', true),
        ];
        return rest_ensure_response($data);
    }

    public function create_project(\WP_REST_Request $request) {
        $params = $request->get_params();
        $title = sanitize_text_field($params['title'] ?? 'Untitled Project');
        $order_id = isset($params['order_id']) ? absint($params['order_id']) : null;

        $post_arr = [
            'post_type' => 'gdwb_project',
            'post_status' => 'publish',
            'post_title' => $title,
        ];

        $post_id = wp_insert_post($post_arr, true);
        if (is_wp_error($post_id)) {
            return $post_id;
        }

        if ($order_id) {
            update_post_meta($post_id, '_gdwb_order_id', $order_id);
            // If the order exists (shop_order post), mark it
            update_post_meta($order_id, '_gdwb_project_created', $post_id);
        }

        return rest_ensure_response(['id' => $post_id]);
    }

    public function get_projects_permissions(\WP_REST_Request $request) {
        return is_user_logged_in() && current_user_can('read');
    }

    public function create_project_permissions(\WP_REST_Request $request) {
        return current_user_can('edit_posts');
    }

}
