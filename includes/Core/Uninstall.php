<?php
namespace GDWB\Core;

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$gdwb_projects = $wpdb->get_results(
    "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'gdwb_project'"
);

foreach ($gdwb_projects as $project) {
    wp_delete_post($project->ID, true);
}

delete_option('gdwb_version');
