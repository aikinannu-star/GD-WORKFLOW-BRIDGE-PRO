<?php
namespace GDWB\Admin;

use GDWB\Core\ModuleInterface;
use GDWB\Core\ServiceContainer;

if (!defined('ABSPATH')) exit;

class Stripe_Manager implements ModuleInterface {

    private ServiceContainer $container;

    public function init(ServiceContainer $container): void {
        $this->container = $container;
        add_action('admin_post_gdwb_register_stripe_webhook', [$this, 'handle_register_webhook']);
        add_action('admin_post_gdwb_delete_stripe_webhook', [$this, 'handle_delete_webhook']);
        add_action('admin_init', [$this, 'maybe_auto_register']);
    }

    public function handle_register_webhook() {
        if (!current_user_can('manage_options')) wp_die(__('Permission denied', 'gdwb'), 403);
        check_admin_referer('gdwb_stripe_register_nonce');

        $api_key = get_option('gdwb_stripe_api_key', '');
        if (empty($api_key)) $api_key = getenv('STRIPE_SECRET_KEY') ?: '';

        if (empty($api_key)) {
            wp_redirect(admin_url('admin.php?page=gdwb-settings&stripe_error=missing_key'));
            exit;
        }

        $result = $this->do_register_webhook($api_key);
        if (!empty($result['error'])) {
            wp_redirect(admin_url('admin.php?page=gdwb-settings&stripe_error=' . rawurlencode($result['error'])));
            exit;
        }

        if (!empty($result['secret'])) update_option('gdwb_stripe_webhook_secret', $result['secret']);
        if (!empty($result['id'])) update_option('gdwb_stripe_webhook_id', $result['id']);
        update_option('gdwb_stripe_webhook_endpoint_url', $result['url'] ?? rest_url('gdwb/v1/stripe-webhook'));

        wp_redirect(admin_url('admin.php?page=gdwb-settings&stripe_registered=1'));
        exit;
    }

    public function handle_delete_webhook() {
        if (!current_user_can('manage_options')) wp_die(__('Permission denied', 'gdwb'), 403);
        check_admin_referer('gdwb_stripe_delete_nonce');

        $api_key = get_option('gdwb_stripe_api_key', '');
        if (empty($api_key)) $api_key = getenv('STRIPE_SECRET_KEY') ?: '';

        $id = get_option('gdwb_stripe_webhook_id', '');
        if (empty($id)) {
            wp_redirect(admin_url('admin.php?page=gdwb-settings&stripe_error=no_id'));
            exit;
        }

        if (empty($api_key)) {
            wp_redirect(admin_url('admin.php?page=gdwb-settings&stripe_error=missing_key'));
            exit;
        }

        $url = 'https://api.stripe.com/v1/webhook_endpoints/' . rawurlencode($id);
        $args = [ 'method' => 'DELETE', 'headers' => [ 'Authorization' => 'Bearer ' . $api_key ], 'timeout' => 20 ];
        $resp = wp_remote_request($url, $args);
        if (is_wp_error($resp)) {
            wp_redirect(admin_url('admin.php?page=gdwb-settings&stripe_error=' . rawurlencode($resp->get_error_message())));
            exit;
        }

        $code = wp_remote_retrieve_response_code($resp);
        $resp_body = wp_remote_retrieve_body($resp);
        if ($code === 200 || $code === 204) {
            delete_option('gdwb_stripe_webhook_secret');
            delete_option('gdwb_stripe_webhook_id');
            delete_option('gdwb_stripe_webhook_endpoint_url');
            wp_redirect(admin_url('admin.php?page=gdwb-settings&stripe_deleted=1'));
            exit;
        }

        wp_redirect(admin_url('admin.php?page=gdwb-settings&stripe_error=' . rawurlencode($resp_body)));
        exit;
    }

    /**
     * Attempt to register webhook programmatically. Returns array with id/secret/url or ['error'=>string]
     */
    private function do_register_webhook(string $api_key): array {
        $webhook_url = rest_url('gdwb/v1/stripe-webhook');

        $events = [
            'invoice.payment_succeeded',
            'invoice.payment_failed',
            'customer.subscription.updated',
            'customer.subscription.deleted',
            'customer.subscription.created',
            'checkout.session.completed'
        ];

        $body = [ 'url' => $webhook_url ];
        foreach ($events as $e) $body['enabled_events[]'][] = $e;

        $args = [
            'headers' => [ 'Authorization' => 'Bearer ' . $api_key ],
            'body' => $body,
            'timeout' => 20
        ];

        $resp = wp_remote_post('https://api.stripe.com/v1/webhook_endpoints', $args);
        if (is_wp_error($resp)) return ['error' => $resp->get_error_message()];

        $code = wp_remote_retrieve_response_code($resp);
        $resp_body = wp_remote_retrieve_body($resp);
        $data = json_decode($resp_body, true);
        if (!in_array($code, [200,201], true) || empty($data) || empty($data['id'])) return ['error' => $resp_body];

        return [ 'id' => $data['id'], 'secret' => $data['secret'] ?? null, 'url' => $data['url'] ?? $webhook_url ];
    }

    public function maybe_auto_register(): void {
        // Only run in admin and when enabled
        if (!is_admin()) return;
        if (!current_user_can('manage_options')) return;
        if (defined('DOING_AJAX') && DOING_AJAX) return;

        $auto = get_option('gdwb_stripe_auto_register', 0);
        if (empty($auto)) return;

        $existing = get_option('gdwb_stripe_webhook_id', '');
        if (!empty($existing)) return; // already registered

        $api_key = get_option('gdwb_stripe_api_key', '');
        if (empty($api_key)) $api_key = getenv('STRIPE_SECRET_KEY') ?: '';
        if (empty($api_key)) return;

        $result = $this->do_register_webhook($api_key);
        if (!empty($result['error'])) {
            error_log('gdwb: stripe auto-register failed: ' . $result['error']);
            return;
        }

        if (!empty($result['secret'])) update_option('gdwb_stripe_webhook_secret', $result['secret']);
        if (!empty($result['id'])) update_option('gdwb_stripe_webhook_id', $result['id']);
        update_option('gdwb_stripe_webhook_endpoint_url', $result['url'] ?? rest_url('gdwb/v1/stripe-webhook'));
    }
}
