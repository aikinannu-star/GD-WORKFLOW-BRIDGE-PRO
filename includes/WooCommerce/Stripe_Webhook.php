<?php
namespace GDWB\WooCommerce;

use GDWB\Core\ModuleInterface;
use GDWB\Core\ServiceContainer;

if (!defined('ABSPATH')) exit;

class Stripe_Webhook implements ModuleInterface {

    private ServiceContainer $container;

    public function init(ServiceContainer $container): void {
        $this->container = $container;
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void {
        register_rest_route('gdwb/v1', '/stripe-webhook', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_webhook'],
            'permission_callback' => '__return_true',
        ]);
    }

    private function verify_signature(string $payload, ?string $sigHeader, string $secret, int $tolerance = 300): bool {
        if (empty($sigHeader) || empty($secret)) return false;

        $parts = explode(',', $sigHeader);
        $timestamp = null;
        $v1 = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if (strpos($p, 't=') === 0) $timestamp = (int) substr($p, 2);
            if (strpos($p, 'v1=') === 0) $v1[] = substr($p, 3);
        }

        if (empty($timestamp) || empty($v1)) return false;
        if (abs(time() - $timestamp) > $tolerance) return false;

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        foreach ($v1 as $sig) {
            if (hash_equals($expected, $sig)) return true;
        }

        return false;
    }

    public function handle_webhook(\WP_REST_Request $request) {
        $payload = $request->get_body();
        $sigHeader = $request->get_header('stripe-signature') ?: (isset($_SERVER['HTTP_STRIPE_SIGNATURE']) ? $_SERVER['HTTP_STRIPE_SIGNATURE'] : null);

        $secret = get_option('gdwb_stripe_webhook_secret', '');
        if (empty($secret)) $secret = getenv('STRIPE_WEBHOOK_SECRET') ?: '';

        if (empty($secret)) {
            error_log('gdwb: stripe webhook secret not configured');
            return new \WP_REST_Response(['success' => false, 'message' => 'webhook_secret_missing'], 400);
        }

        if (!$this->verify_signature($payload, $sigHeader, $secret)) {
            error_log('gdwb: stripe webhook signature verification failed');
            return new \WP_REST_Response(['success' => false, 'message' => 'signature_verification_failed'], 401);
        }

        $event = json_decode($payload, true);
        if (!is_array($event)) {
            return new \WP_REST_Response(['success' => false, 'message' => 'invalid_json'], 400);
        }

        $type = $event['type'] ?? '';
        $object = $event['data']['object'] ?? [];

        // Determine provider subscription id from event payload
        $providerSub = $object['subscription'] ?? ($object['id'] ?? null);
        $customer = $object['customer'] ?? null;

        // Try to map provider subscription id to a WP subscription post
        $wp_sub_post_id = $this->find_subscription_post_by_provider_id($providerSub);
        $wp_subscription = null;
        if ($wp_sub_post_id && function_exists('wcs_get_subscription')) {
            $wp_subscription = wcs_get_subscription($wp_sub_post_id);
        }

        // Instantiate handler (safe to create a new one)
        $handler = new Subscription_Handler();

        // Route common events
        switch ($type) {
            case 'invoice.payment_succeeded':
                if ($wp_subscription) $handler->handle_renewal($wp_subscription, null);
                break;

            case 'invoice.payment_failed':
                if ($wp_subscription) $handler->handle_cancel($wp_subscription);
                break;

            case 'customer.subscription.updated':
            case 'customer.subscription.deleted':
                $status = $object['status'] ?? null;
                if ($type === 'customer.subscription.updated' && in_array($status, ['active','trialing'], true)) {
                    if ($wp_subscription) $handler->handle_renewal($wp_subscription, null);
                } else {
                    if ($wp_subscription) $handler->handle_cancel($wp_subscription);
                }
                break;

            case 'checkout.session.completed':
                // Stripe checkout may create a subscription; allow other modules to handle mapping
                do_action('gdwb_stripe_checkout_completed', $event);
                break;

            default:
                // Provide a generic hook for other modules
                do_action('gdwb_stripe_event', $event);
                break;
        }

        return new \WP_REST_Response(['success' => true], 200);
    }

    private function find_subscription_post_by_provider_id($providerId) {
        global $wpdb;
        if (empty($providerId)) return null;

        $pm = $wpdb->postmeta;
        $p = $wpdb->posts;
        $sql = $wpdb->prepare("SELECT pm.post_id FROM {$pm} pm JOIN {$p} p ON pm.post_id = p.ID WHERE pm.meta_value = %s AND p.post_type = 'shop_subscription' LIMIT 1", $providerId);
        $post_id = $wpdb->get_var($sql);
        if ($post_id) return intval($post_id);
        return null;
    }
}
