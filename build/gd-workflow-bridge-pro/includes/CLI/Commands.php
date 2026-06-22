<?php
namespace GDWB\CLI;

if (!defined('ABSPATH')) exit;

class Commands {

    public static function register() {
        if (!class_exists('WP_CLI')) {
            return;
        }

        \WP_CLI::add_command('gdwb project list', [__CLASS__, 'list_projects']);
        \WP_CLI::add_command('gdwb project create', [__CLASS__, 'create_project']);
        \WP_CLI::add_command('gdwb analytics', [__CLASS__, 'get_analytics']);
    }

    public static function list_projects($args, $assoc_args) {
        $projects = get_posts(['post_type' => 'gdwb_project', 'posts_per_page' => -1]);

        if (empty($projects)) {
            \WP_CLI::warning('No projects found.');
            return;
        }

        $rows = [];
        foreach ($projects as $project) {
            $rows[] = [
                'ID' => $project->ID,
                'Title' => $project->post_title,
                'Status' => $project->post_status,
                'Created' => $project->post_date,
            ];
        }

        \WP_CLI\Utils\format_items('table', $rows, ['ID', 'Title', 'Status', 'Created']);
    }

    public static function create_project($args, $assoc_args) {
        $title = $args[0] ?? 'Untitled Project';
        $post_id = wp_insert_post([
            'post_type' => 'gdwb_project',
            'post_status' => 'publish',
            'post_title' => $title,
        ]);

        if (is_wp_error($post_id)) {
            \WP_CLI::error($post_id->get_error_message());
        } else {
            \WP_CLI::success("Project created with ID: $post_id");
        }
    }

    public static function get_analytics($args, $assoc_args) {
        if (!class_exists('GDWB\\Integrations\\Analytics')) {
            \WP_CLI::error('Analytics module not loaded.');
            return;
        }

        $analytics = new \GDWB\Integrations\Analytics();
        $stats = $analytics->get_dashboard_stats();

        \WP_CLI::line("Total Projects: " . intval($stats['total_projects']));
        \WP_CLI::line("Total Files: " . intval($stats['total_files']));
        \WP_CLI::line("Metrics: " . count($stats['metrics']));
    }
}

add_action('init', [Commands::class, 'register']);
