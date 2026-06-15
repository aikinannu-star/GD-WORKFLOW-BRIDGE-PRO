<?php
namespace GDWB\WooCommerce;

use GDWB\Core\ModuleInterface;
use GDWB\Core\ServiceContainer;

if (!defined('ABSPATH')) exit;

class License_Provisioner implements ModuleInterface {

    private ServiceContainer $container;

    public function init(ServiceContainer $container): void {
        $this->container = $container;
        add_action('gdwb_project_created', [$this, 'provision_license_for_project']);
    }

    public function provision_license_for_project($project_id) {
        if (!function_exists('wc_get_order')) return;
        if (empty($project_id)) return;

        $order_id = get_post_meta($project_id, '_gdwb_order_id', true);
        if (empty($order_id)) return;

        $order = wc_get_order($order_id);
        if (!$order) return;

        $product_ids = get_post_meta($project_id, '_gdwb_order_product_ids', true);
        if (empty($product_ids) || !is_array($product_ids)) {
            $product_ids = [];
            foreach ($order->get_items() as $item) {
                $pid = $item->get_product_id();
                if ($pid) $product_ids[] = $pid;
            }
        }

        if (empty($product_ids)) return;

        $subscription_ids = [];
        if (function_exists('wcs_get_subscriptions_for_order')) {
            try {
                $subs = wcs_get_subscriptions_for_order($order, ['order_type' => 'any']);
                if (!empty($subs) && is_array($subs)) {
                    foreach ($subs as $s) {
                        $sid = null;
                        if (is_object($s) && method_exists($s, 'get_id')) {
                            $sid = $s->get_id();
                        } elseif (is_numeric($s)) {
                            $sid = intval($s);
                        }
                        if ($sid) $subscription_ids[] = $sid;
                    }
                }
            } catch (\Throwable $e) { /* ignore */ }
        }

        if (empty($subscription_ids)) {
            $meta_sub = get_post_meta($order_id, '_subscription_id', true);
            if (!empty($meta_sub)) $subscription_ids[] = intval($meta_sub);
            foreach ($order->get_items() as $item) {
                if (is_object($item) && method_exists($item, 'get_meta')) {
                    $im = $item->get_meta('_subscription_id', true);
                    if (!empty($im)) $subscription_ids[] = intval($im);
                }
            }
        }

        $subscription_ids = array_values(array_unique(array_filter($subscription_ids)));

        $plan = null;
        foreach ($product_ids as $pid) {
            $meta_plan = get_post_meta($pid, '_gdwb_plan', true);
            if (!empty($meta_plan)) { $plan = $meta_plan; break; }

            $terms = wp_get_post_terms($pid, 'product_cat', ['fields' => 'slugs']);
            if (!empty($terms) && is_array($terms)) {
                foreach ($terms as $slug) {
                    $slug = strtolower($slug);
                    if (in_array($slug, ['enterprise','pro','free'], true)) {
                        $plan = $slug;
                        break 2;
                    }
                }
            }
        }

        if (empty($plan)) $plan = 'pro';

        try {
            $rand = bin2hex(random_bytes(8));
        } catch (\Throwable $e) {
            $rand = bin2hex(openssl_random_pseudo_bytes(8));
        }
        $license_key = 'GDWB-' . strtoupper($rand);

        $billing_endpoint = get_option('gdwb_billing_service_endpoint', 'http://127.0.0.1:8003');
        $billing_url = rtrim($billing_endpoint, '/') . '/api/v1/purchase';

        $order_total = floatval($order->get_total());
        $amount_cents = intval(round($order_total * 100));
        $currency = $order->get_currency() ?: 'USD';

        $email = $order->get_billing_email();

        $payload = [
            'plan' => $plan,
            'amount_cents' => $amount_cents,
            'currency' => $currency,
            'gateway' => 'woocommerce',
            'order_id' => $order_id,
            'email' => $email,
            'metadata' => [
                'license_key' => $license_key,
                'project_id' => $project_id,
                'product_ids' => $product_ids,
                'site' => function_exists('home_url') ? home_url() : ''
            ],
        ];

        $headers = [ 'Accept' => 'application/json', 'Content-Type' => 'application/json' ];

        $admin_token = get_option('gdwb_license_server_admin_token', '');
        if (empty($admin_token)) {
            $token_path = GDWB_PATH . 'license-server/keys/admin_token.txt';
            if (file_exists($token_path)) {
                $admin_token = trim(@file_get_contents($token_path));
            }
        }
        if (!empty($admin_token)) {
            $headers['Authorization'] = 'Bearer ' . $admin_token;
        }

        $args = [ 'body' => json_encode($payload), 'timeout' => 20, 'headers' => $headers ];

        $resp = wp_remote_post($billing_url, $args);
        if (is_wp_error($resp)) {
            error_log('gdwb: billing purchase failed (request): ' . $resp->get_error_message());
            return;
        }

        $code = wp_remote_retrieve_response_code($resp);
        $resp_body = wp_remote_retrieve_body($resp);
        $data = json_decode($resp_body, true);

        if ($code !== 200 || empty($data) || empty($data['success'])) {
            if (!empty($data['redirect_url'])) {
                update_post_meta($order_id, '_gdwb_billing_redirect', $data['redirect_url']);
                update_post_meta($project_id, '_gdwb_billing_invoice_id', $data['invoice_id'] ?? null);

                if (!empty($email) && is_email($email)) {
                    $subject = __('Complete your payment to activate license', 'gdwb');
                    $message = sprintf(__("Hello,\n\nPlease complete your payment to activate your license:\n\n%s\n\nThank you.", 'gdwb'), $data['redirect_url']);
                    wp_mail($email, $subject, $message);
                }

                return;
            }

            error_log('gdwb: billing purchase failed (response): ' . $resp_body);
            return;
        }

        $token = $data['license_response']['token'] ?? $data['token'] ?? $data['access_token'] ?? ($data['license_response']['access_token'] ?? null);
        $returnedLicenseKey = $data['license_key'] ?? $data['licenseKey'] ?? null;
        if (!empty($returnedLicenseKey)) $license_key = $returnedLicenseKey;

        update_post_meta($project_id, '_gdwb_license_key', $license_key);
        if (!empty($token)) update_post_meta($project_id, '_gdwb_license_token', $token);
        update_post_meta($order_id, '_gdwb_license_key', $license_key);
        if (!empty($token)) update_post_meta($order_id, '_gdwb_license_token', $token);

        $customer_id = $order->get_user_id();
        if (!empty($customer_id)) {
            update_user_meta($customer_id, 'gdwb_license_key', $license_key);
            if (!empty($token)) update_user_meta($customer_id, 'gdwb_license_token', $token);
        }

        if (!empty($subscription_ids)) {
            update_post_meta($project_id, '_gdwb_subscription_ids', $subscription_ids);
            update_post_meta($order_id, '_gdwb_subscription_ids', $subscription_ids);
            foreach ($subscription_ids as $sid) {
                update_post_meta($sid, '_gdwb_license_key', $license_key);
                if (!empty($token)) update_post_meta($sid, '_gdwb_license_token', $token);
            }
            if (!empty($customer_id)) {
                $existing = get_user_meta($customer_id, 'gdwb_subscription_ids', true);
                if (empty($existing) || !is_array($existing)) $existing = [];
                $existing = array_values(array_unique(array_merge($existing, $subscription_ids)));
                update_user_meta($customer_id, 'gdwb_subscription_ids', $existing);
            }
        }

        if (!empty($data['license_key']) || !empty($token)) {
            $email = $order->get_billing_email();
            if (!empty($email) && is_email($email)) {
                $subject = __('Your license for purchased services', 'gdwb');
                $message = sprintf(__("Hello,\n\nThank you for your purchase. Your license has been provisioned.\n\nLicense key: %s\n", 'gdwb'), $license_key);
                if (!empty($token)) {
                    $message .= sprintf(__("License token (short-lived): %s\n", 'gdwb'), $token);
                }
                $message .= "\n" . __('You can manage the license in your account.', 'gdwb');
                wp_mail($email, $subject, $message);
            }
        }

        do_action('gdwb_license_provisioned', $project_id, $license_key, $token);
    }
}
