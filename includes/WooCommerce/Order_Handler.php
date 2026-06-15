<?php
namespace GDWB\WooCommerce;

use GDWB\Core\ModuleInterface;
use GDWB\Core\ServiceContainer;

if (!defined('ABSPATH')) exit;

class Order_Handler implements ModuleInterface {

    private ServiceContainer $container;

    public function init(ServiceContainer $container): void {
        $this->container = $container;
        add_action('woocommerce_order_status_completed', [$this, 'create_project']);
    }

    public function create_project($order_id) {

        if (!function_exists('wc_get_order')) {
            return;
        }

        $order = wc_get_order($order_id);

        if (!$order) {
            return;
        }

        if (get_post_meta($order_id, '_gdwb_project_created', true)) {
            return;
        }

        // Only create a project if the order contains at least one 'Services' product
        $service_products = [];
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            if ($product_id && $this->is_service_product($product_id)) {
                $service_products[] = $product_id;
            }
        }

        if (empty($service_products)) {
            return; // no services in this order
        }

        $project_title = 'Service Project - Order #' . intval($order_id);
        $project_id = wp_insert_post([
            'post_type' => 'gdwb_project',
            'post_status' => 'publish',
            'post_title' => $project_title
        ]);

        if ($project_id && !is_wp_error($project_id)) {
            update_post_meta($project_id, '_gdwb_order_id', $order_id);
            update_post_meta($order_id, '_gdwb_project_created', $project_id);
            update_post_meta($project_id, '_gdwb_order_product_ids', $service_products);
            // Detect and persist subscription IDs related to this order (if Subscriptions plugin is active)
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
            }
            $subscription_ids = array_values(array_unique(array_filter($subscription_ids)));
            if (!empty($subscription_ids)) {
                update_post_meta($project_id, '_gdwb_subscription_ids', $subscription_ids);
                update_post_meta($order_id, '_gdwb_subscription_ids', $subscription_ids);
            }
            do_action('gdwb_project_created', $project_id);
        }
    }

    private function is_service_product($product_id) {
        $service_term = get_option('gdwb_service_category_id', 0);
        if (!$service_term) {
            $term = get_term_by('slug', 'services', 'product_cat');
            $service_term = $term ? $term->term_id : 0;
        }

        if (!$service_term) {
            return false;
        }

        $terms = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'ids']);
        if (empty($terms)) {
            return false;
        }

        foreach ($terms as $t) {
            if ($t == $service_term || term_is_ancestor_of($service_term, $t, 'product_cat')) {
                return true;
            }
        }

        return false;
    }
}
