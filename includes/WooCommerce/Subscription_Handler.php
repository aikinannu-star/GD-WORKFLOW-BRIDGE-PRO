<?php
namespace GDWB\WooCommerce;

use GDWB\Core\ModuleInterface;
use GDWB\Core\ServiceContainer;

if (!defined('ABSPATH')) exit;

class Subscription_Handler implements ModuleInterface {

    private ServiceContainer $container;

    public function init(ServiceContainer $container): void {
        $this->container = $container;

        // Subscribe to common WooCommerce Subscriptions hooks. Safe to add even if Subscriptions not installed.
        add_action('woocommerce_subscription_renewal_payment_complete', [$this, 'handle_renewal'], 10, 2);
        add_action('woocommerce_subscription_status_cancelled', [$this, 'handle_cancel'], 10, 1);
        add_action('woocommerce_subscription_status_expired', [$this, 'handle_cancel'], 10, 1);
    }

    public function handle_renewal($subscription, $order = null) {
        $license_key = $this->extract_license_from_subscription_or_order($subscription, $order);
        if (empty($license_key)) {
            $user_id = null;
            if (is_object($subscription) && method_exists($subscription, 'get_user_id')) $user_id = $subscription->get_user_id();
            if (is_object($order) && method_exists($order, 'get_user_id')) $user_id = $order->get_user_id() ?: $user_id;
            if ($user_id) $license_key = get_user_meta($user_id, 'gdwb_license_key', true);
        }

        if (empty($license_key)) return;

        $res = $this->admin_validate_license($license_key);
        if (is_wp_error($res)) {
            error_log('gdwb: subscription renewal license extend failed: ' . $res->get_error_message());
        }
    }

    public function handle_cancel($subscription) {
        $license_key = $this->extract_license_from_subscription_or_order($subscription, null);
        if (empty($license_key)) {
            $user_id = is_object($subscription) && method_exists($subscription, 'get_user_id') ? $subscription->get_user_id() : null;
            if ($user_id) $license_key = get_user_meta($user_id, 'gdwb_license_key', true);
        }

        if (empty($license_key)) return;

        $res = $this->admin_revoke_license($license_key);
        if (is_wp_error($res)) {
            error_log('gdwb: subscription cancel license revoke failed: ' . $res->get_error_message());
        }
    }

    private function extract_license_from_subscription_or_order($subscription, $order = null): ?string {
        if ($order && is_object($order) && method_exists($order, 'get_id')) {
            $k = get_post_meta($order->get_id(), '_gdwb_license_key', true);
            if (!empty($k)) return $k;
        }

        if (is_object($subscription)) {
            if (method_exists($subscription, 'get_meta')) {
                $k = $subscription->get_meta('_gdwb_license_key');
                if (!empty($k)) return $k;
            }
            if (method_exists($subscription, 'get_id')) {
                $k = get_post_meta($subscription->get_id(), '_gdwb_license_key', true);
                if (!empty($k)) return $k;
            }
        }

        if (is_numeric($subscription)) {
            $k = get_post_meta(intval($subscription), '_gdwb_license_key', true);
            if (!empty($k)) return $k;
        }

        return null;
    }

    private function admin_validate_license(string $license_key) {
        $endpoint = get_option('gdwb_license_server_endpoint', 'http://127.0.0.1:8001');
        $url = rtrim($endpoint, '/') . '/api/v1/validate';

        $body = [ 'license_key' => $license_key, 'site' => function_exists('home_url') ? home_url() : '' ];
        $headers = [ 'Accept' => 'application/json' ];

        $admin_token = get_option('gdwb_license_server_admin_token', '');
        if (empty($admin_token)) {
            $token_path = GDWB_PATH . 'license-server/keys/admin_token.txt';
            if (file_exists($token_path)) $admin_token = trim(@file_get_contents($token_path));
        }

        if (!empty($admin_token)) {
            $headers['Authorization'] = 'Bearer ' . $admin_token;
        } else {
            $admin_secret = get_option('gdwb_license_server_admin_secret', '');
            if (empty($admin_secret)) {
                $secret_path = GDWB_PATH . 'license-server/keys/admin_secret.txt';
                if (file_exists($secret_path)) $admin_secret = trim(@file_get_contents($secret_path));
            }
            if (!empty($admin_secret)) $body['admin_secret'] = $admin_secret;
        }

        $args = [ 'body' => $body, 'timeout' => 20, 'headers' => $headers ];
        $resp = wp_remote_post($url, $args);
        if (is_wp_error($resp)) return $resp;

        $code = wp_remote_retrieve_response_code($resp);
        $resp_body = wp_remote_retrieve_body($resp);
        $data = json_decode($resp_body, true);

        if ($code !== 200 || empty($data) || empty($data['success'])) {
            return new \WP_Error('license_extend_failed', $resp_body ?: 'invalid_response');
        }

        // Optionally persist token returned by server
        $token = $data['token'] ?? $data['access_token'] ?? null;
        return $token ?: true;
    }

    private function admin_revoke_license(string $license_key) {
        $endpoint = get_option('gdwb_license_server_endpoint', 'http://127.0.0.1:8001');
        $url = rtrim($endpoint, '/') . '/api/v1/revoke';

        $body = [ 'license_key' => $license_key ];
        $headers = [ 'Accept' => 'application/json' ];

        $admin_token = get_option('gdwb_license_server_admin_token', '');
        if (empty($admin_token)) {
            $token_path = GDWB_PATH . 'license-server/keys/admin_token.txt';
            if (file_exists($token_path)) $admin_token = trim(@file_get_contents($token_path));
        }

        if (!empty($admin_token)) {
            $headers['Authorization'] = 'Bearer ' . $admin_token;
        } else {
            $admin_secret = get_option('gdwb_license_server_admin_secret', '');
            if (empty($admin_secret)) {
                $secret_path = GDWB_PATH . 'license-server/keys/admin_secret.txt';
                if (file_exists($secret_path)) $admin_secret = trim(@file_get_contents($secret_path));
            }
            if (!empty($admin_secret)) $body['admin_secret'] = $admin_secret;
        }

        $args = [ 'body' => $body, 'timeout' => 20, 'headers' => $headers ];
        $resp = wp_remote_post($url, $args);
        if (is_wp_error($resp)) return $resp;

        $code = wp_remote_retrieve_response_code($resp);
        $resp_body = wp_remote_retrieve_body($resp);
        $data = json_decode($resp_body, true);

        if ($code !== 200 || empty($data) || empty($data['success'])) {
            return new \WP_Error('license_revoke_failed', $resp_body ?: 'invalid_response');
        }

        return true;
    }
}
