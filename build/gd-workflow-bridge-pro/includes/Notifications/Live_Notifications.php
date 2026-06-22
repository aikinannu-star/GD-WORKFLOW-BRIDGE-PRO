<?php
namespace GDWB\Notifications;

use GDWB\Core\ModuleInterface;
use GDWB\Core\ServiceContainer;
use GDWB\Admin\License_Manager;

if (!defined('ABSPATH')) exit;

class Live_Notifications implements ModuleInterface {

    private ServiceContainer $container;

    public function init(ServiceContainer $container): void {
        $this->container = $container;
        // Disable live notifications when license is not active
        $license = new License_Manager();
        if (!$license->is_license_active()) {
            return;
        }
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('gdwb_project_created', [$this, 'notify_project_created']);
        add_action('gdwb_project_updated', [$this, 'notify_project_updated']);
        add_action('gdwb_file_uploaded', [$this, 'notify_file_uploaded']);
        add_action('gdwb_message_sent', [$this, 'notify_message_sent']);
    }

    public function register_routes(): void {
        register_rest_route('gdwb/v1', '/notifications', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'get_notifications'],
            'permission_callback' => [$this, 'notifications_permission'],
        ]);

        register_rest_route('gdwb/v1', '/notifications/mark-read', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'mark_as_read'],
            'permission_callback' => [$this, 'notifications_permission'],
        ]);
    }

    public function notifications_permission(\WP_REST_Request $request) {
        return is_user_logged_in();
    }

    public function get_notifications(\WP_REST_Request $request) {
        global $wpdb;
        $table = $wpdb->prefix . 'gdwb_notifications';
        $limit = $request->get_param('limit') ?: 50;

        $notifications = $wpdb->get_results($wpdb->prepare(
            "SELECT id, type, project_id, payload, created_at FROM $table WHERE user_id = %d ORDER BY created_at DESC LIMIT %d",
            get_current_user_id(),
            (int)$limit
        ));

        $response = [];
        foreach ($notifications as $notif) {
            $payload = maybe_unserialize($notif->payload) ?: [];
            $response[] = [
                'id' => (int)$notif->id,
                'type' => $notif->type,
                'project_id' => (int)$notif->project_id,
                'message' => $this->format_notification_message($notif->type, $payload),
                'created' => $notif->created_at,
            ];
        }

        return rest_ensure_response($response);
    }

    public function mark_as_read(\WP_REST_Request $request) {
        $notification_id = $request->get_param('notification_id');

        if (!$notification_id) {
            return new \WP_Error('missing_id', __('Notification ID required', 'gdwb'), ['status' => 400]);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'gdwb_notifications';

        $wpdb->delete($table, ['id' => (int)$notification_id, 'user_id' => get_current_user_id()]);

        return rest_ensure_response(['success' => true]);
    }

    public function notify_project_created($project_id) {
        $post = get_post($project_id);
        if (!$post) {
            return;
        }

        $order_id = get_post_meta($project_id, '_gdwb_order_id', true);
        if ($order_id && function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
            if ($order) {
                $this->create_notification($order->get_customer_id(), 'project_created', $project_id, [
                    'project_title' => $post->post_title,
                ]);
            }
        }
    }

    public function notify_project_updated($project_id) {
        $post = get_post($project_id);
        if (!$post) {
            return;
        }

        $order_id = get_post_meta($project_id, '_gdwb_order_id', true);
        if ($order_id && function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
            if ($order) {
                $this->create_notification($order->get_customer_id(), 'project_updated', $project_id, [
                    'project_title' => $post->post_title,
                ]);
            }
        }
    }

    public function notify_file_uploaded($project_id, $attachment_id) {
        $post = get_post($project_id);
        if (!$post) {
            return;
        }

        $order_id = get_post_meta($project_id, '_gdwb_order_id', true);
        if ($order_id && function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
            if ($order) {
                $attachment = get_post($attachment_id);
                $this->create_notification($order->get_customer_id(), 'file_uploaded', $project_id, [
                    'file_name' => $attachment ? $attachment->post_title : '',
                ]);
            }
        }
    }

    public function notify_message_sent($project_id, $message_id, $user_id) {
        $post = get_post($project_id);
        if (!$post) {
            return;
        }

        $order_id = get_post_meta($project_id, '_gdwb_order_id', true);
        if ($order_id && function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
            if ($order && $order->get_customer_id() != $user_id) {
                $user = get_user_by('id', $user_id);
                $this->create_notification($order->get_customer_id(), 'message_received', $project_id, [
                    'author' => $user ? $user->display_name : 'Staff',
                ]);
            }
        }
    }

    private function create_notification($user_id, $type, $project_id, $payload) {
        global $wpdb;
        $table = $wpdb->prefix . 'gdwb_notifications';

        $wpdb->insert($table, [
            'user_id' => (int)$user_id,
            'type' => sanitize_text_field($type),
            'project_id' => (int)$project_id,
            'payload' => maybe_serialize($payload),
            'created_at' => current_time('mysql'),
        ]);
    }

    private function format_notification_message($type, $payload) {
        switch ($type) {
            case 'project_created':
                return sprintf(__('Your project "%s" has been created', 'gdwb'), $payload['project_title'] ?? 'Project');
            case 'project_updated':
                return sprintf(__('Your project "%s" has been updated', 'gdwb'), $payload['project_title'] ?? 'Project');
            case 'file_uploaded':
                return sprintf(__('New file uploaded: %s', 'gdwb'), $payload['file_name'] ?? 'File');
            case 'message_received':
                return sprintf(__('New message from %s', 'gdwb'), $payload['author'] ?? 'Staff');
            default:
                return __('New notification', 'gdwb');
        }
    }
}
