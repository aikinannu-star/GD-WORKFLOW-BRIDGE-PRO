<?php
namespace GDWB\Admin;

use GDWB\Core\ModuleInterface;
use GDWB\Core\ServiceContainer;
use GDWB\Admin\License_Manager;

if (!defined('ABSPATH')) exit;

class Webhook_Manager implements ModuleInterface {

    private ServiceContainer $container;

    public function init(ServiceContainer $container): void {
        $this->container = $container;
        // Disable webhooks when license not active
        $license = new License_Manager();
        if (!$license->is_license_active()) {
            return;
        }
        add_action('gdwb_project_created', [$this, 'trigger_webhook']);
        add_action('gdwb_project_updated', [$this, 'trigger_webhook']);
        add_action('gdwb_file_uploaded', [$this, 'trigger_webhook']);
    }

    public function trigger_webhook($project_id) {
        $webhooks = get_option('gdwb_webhooks', []);
        if (empty($webhooks)) {
            return;
        }

        $post = get_post($project_id);
        if (!$post) {
            return;
        }

        $payload = [
            'event' => current_filter(),
            'project_id' => $project_id,
            'project_title' => $post->post_title,
            'timestamp' => current_time('mysql'),
        ];

        foreach ($webhooks as $webhook) {
            if (!isset($webhook['url']) || !filter_var($webhook['url'], FILTER_VALIDATE_URL)) {
                continue;
            }

            wp_remote_post($webhook['url'], [
                'body' => json_encode($payload),
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-GDWB-Signature' => hash_hmac('sha256', json_encode($payload), get_option('gdwb_webhook_secret', 'secret')),
                ],
                'timeout' => 5,
            ]);
        }
    }

    public static function add_webhook($url) {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return new \WP_Error('invalid_url', __('Invalid webhook URL', 'gdwb'));
        }

        $webhooks = get_option('gdwb_webhooks', []);
        $webhooks[] = ['url' => esc_url_raw($url), 'created_at' => current_time('mysql')];
        update_option('gdwb_webhooks', $webhooks);

        return true;
    }

    public static function get_webhooks() {
        return get_option('gdwb_webhooks', []);
    }
}
