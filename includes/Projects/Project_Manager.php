<?php
namespace GDWB\Projects;

use GDWB\Core\ModuleInterface;
use GDWB\Core\ServiceContainer;

if (!defined('ABSPATH')) exit;

class Project_Manager implements ModuleInterface {

    private ServiceContainer $container;

    public function init(ServiceContainer $container): void {
        $this->container = $container;
        add_action('save_post_gdwb_project', [$this, 'on_project_save'], 10, 3);
    }

    public function on_project_save($post_id, $post, $update) {
        global $wpdb;
        $table = $wpdb->prefix . 'gdwb_projects';
        $order_id = get_post_meta($post_id, '_gdwb_order_id', true) ?: null;

        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE post_id = %d", $post_id));

        $data = [
            'post_id' => $post_id,
            'order_id' => $order_id,
            'status' => $post->post_status,
            'data' => maybe_serialize([]),
            'created_at' => current_time('mysql')
        ];

        if ($existing) {
            $wpdb->update($table, $data, ['id' => $existing]);
        } else {
            $wpdb->insert($table, $data);
        }

        // Schedule background processing using Action Scheduler if available
        if (function_exists('as_schedule_single_action')) {
            // Schedule only if not already scheduled
            if (!as_next_scheduled_action('gdwb_process_project', ['project_id' => $post_id])) {
                as_schedule_single_action(time() + 60, 'gdwb_process_project', ['project_id' => $post_id]);
            }
        } else {
            // Fallback: trigger the action immediately (synchronous)
            do_action('gdwb_process_project', ['project_id' => $post_id]);
        }
    }
}
