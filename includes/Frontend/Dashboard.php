<?php
namespace GDWB\Frontend;

use GDWB\Core\ModuleInterface;
use GDWB\Core\ServiceContainer;
use GDWB\Admin\License_Manager;

if (!defined('ABSPATH')) exit;

class Dashboard implements ModuleInterface {

    private ServiceContainer $container;

    public function init(ServiceContainer $container): void {
        $this->container = $container;
        add_shortcode('gdwb_dashboard_stats', [$this, 'dashboard_stats']);
        add_shortcode('gdwb_timeline', [$this, 'project_timeline']);
    }

    public function dashboard_stats() {
        if (!is_user_logged_in() || !current_user_can('read')) {
            return '<p>' . esc_html__('Access denied.', 'gdwb') . '</p>';
        }

        $license = new License_Manager();
        if (!$license->is_license_active()) {
            return '<p>' . esc_html__('Advanced analytics are available with a premium license. Activate to view.', 'gdwb') . '</p>';
        }

        if (!class_exists('GDWB\\Integrations\\Analytics')) {
            return '<p>' . esc_html__('Analytics not available.', 'gdwb') . '</p>';
        }

        $analytics = new \GDWB\Integrations\Analytics();
        $stats = $analytics->get_dashboard_stats();

        $output = '<div class="gdwb-dashboard-stats">' . "\n";
        $output .= '<h3>' . esc_html__('Project Statistics', 'gdwb') . '</h3>' . "\n";
        $output .= '<div class="stat-box">' . "\n";
        $output .= '<strong>' . esc_html__('Total Projects:', 'gdwb') . '</strong> ' . intval($stats['total_projects']) . "\n";
        $output .= '</div>' . "\n";
        $output .= '<div class="stat-box">' . "\n";
        $output .= '<strong>' . esc_html__('Total Files:', 'gdwb') . '</strong> ' . intval($stats['total_files']) . "\n";
        $output .= '</div>' . "\n";
        $output .= '</div>' . "\n";

        return wp_kses_post($output);
    }

    public function project_timeline($atts) {
        $atts = shortcode_atts(['project_id' => 0], $atts);
        $project_id = (int)$atts['project_id'];

        if (!$project_id) {
            return '<p>' . esc_html__('Invalid project ID.', 'gdwb') . '</p>';
        }

        $post = get_post($project_id);
        if (!$post || $post->post_type !== 'gdwb_project') {
            return '<p>' . esc_html__('Project not found.', 'gdwb') . '</p>';
        }

        if (!current_user_can('read_post', $project_id)) {
            return '<p>' . esc_html__('Access denied.', 'gdwb') . '</p>';
        }

        if (!class_exists('GDWB\\Projects\\Timeline_Manager')) {
            return '<p>' . esc_html__('Timeline not available.', 'gdwb') . '</p>';
        }

        $timeline_manager = new \GDWB\Projects\Timeline_Manager();
        $timeline_manager->init($this->container);
        $entries = $timeline_manager->get_timeline($project_id);

        $output = '<div class="gdwb-timeline">' . "\n";
        $output .= '<h3>' . esc_html__('Project Timeline', 'gdwb') . '</h3>' . "\n";

        if (empty($entries)) {
            $output .= '<p>' . esc_html__('No timeline entries yet.', 'gdwb') . '</p>' . "\n";
        } else {
            $output .= '<ul class="timeline-list">' . "\n";
            foreach ($entries as $entry) {
                $user = get_userdata($entry->user_id);
                $user_name = $user ? $user->display_name : __('System', 'gdwb');
                $output .= '<li class="timeline-entry">' . "\n";
                $output .= '<strong>' . esc_html($entry->event_type) . '</strong> - ' . esc_html($user_name) . ' (' . esc_html($entry->created_at) . ')' . "\n";
                $output .= '<p>' . wp_kses_post($entry->message) . '</p>' . "\n";
                $output .= '</li>' . "\n";
            }
            $output .= '</ul>' . "\n";
        }

        $output .= '</div>' . "\n";

        return $output;
    }
}
