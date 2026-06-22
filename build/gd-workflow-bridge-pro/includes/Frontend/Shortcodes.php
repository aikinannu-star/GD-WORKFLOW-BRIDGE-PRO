<?php
namespace GDWB\Frontend;

use GDWB\Core\ModuleInterface;
use GDWB\Core\ServiceContainer;

if (!defined('ABSPATH')) exit;

class Shortcodes implements ModuleInterface {

    private ServiceContainer $container;

    public function init(ServiceContainer $container): void {
        $this->container = $container;
        add_shortcode('gdwb_projects', [$this, 'projects_shortcode']);
    }

    public function projects_shortcode() {

        if (!is_user_logged_in()) {
            return '<p>' . esc_html__('Please log in to view your projects.', 'gdwb') . '</p>';
        }

        $current_user = wp_get_current_user();
        if (!user_can($current_user, 'read')) {
            return '<p>' . esc_html__('You do not have permission to view projects.', 'gdwb') . '</p>';
        }

        $output = '<div class="gdwb-dashboard">' . "\n";
        $output .= '  <h2>' . esc_html__('Your Projects', 'gdwb') . '</h2>' . "\n";
        $output .= '  <p>' . esc_html__('Dashboard module initialized successfully.', 'gdwb') . '</p>' . "\n";
        $output .= '</div>';

        return wp_kses_post($output);
    }
}
