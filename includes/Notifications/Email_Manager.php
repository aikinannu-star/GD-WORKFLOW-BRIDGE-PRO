<?php
namespace GDWB\Notifications;

use GDWB\Core\ModuleInterface;
use GDWB\Core\ServiceContainer;

if (!defined('ABSPATH')) exit;

class Email_Manager implements ModuleInterface {

    private ServiceContainer $container;

    public function init(ServiceContainer $container): void {
        $this->container = $container;
        add_action('gdwb_project_created', [$this, 'on_project_created']);
        add_action('gdwb_project_updated', [$this, 'on_project_updated']);
    }

    public function on_project_created($project_id) {
        $this->send_project_notification($project_id, 'created');
    }

    public function on_project_updated($project_id) {
        $this->send_project_notification($project_id, 'updated');
    }

    private function send_project_notification($project_id, $action) {
        $post = get_post($project_id);
        if (!$post || $post->post_type !== 'gdwb_project') {
            return;
        }

        $order_id = get_post_meta($project_id, '_gdwb_order_id', true);
        if (!$order_id) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $customer_email = $order->get_billing_email();
        if (!$customer_email) {
            return;
        }

        $subject = sprintf(__('Project %s: %s', 'gdwb'), $action, $post->post_title);
        $template = $this->get_template($action, $project_id);

        wp_mail($customer_email, $subject, $template);

        if ($logger = $this->container->get('logger')) {
            $logger->log("Email sent to $customer_email for project $project_id ($action)");
        }
    }

    private function get_template($action, $project_id) {
        $post = get_post($project_id);
        ob_start();
        include GDWB_PATH . "templates/emails/$action.php";
        $content = ob_get_clean();
        return $content ?: "Project $action: " . $post->post_title;
    }
}
