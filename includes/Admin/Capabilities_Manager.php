<?php
namespace GDWB\Admin;

use GDWB\Core\ModuleInterface;
use GDWB\Core\ServiceContainer;

if (!defined('ABSPATH')) exit;

class Capabilities_Manager implements ModuleInterface {

    private ServiceContainer $container;
    const CAPABILITIES = [
        'read_gdwb_project' => 'Read projects',
        'edit_gdwb_project' => 'Edit projects',
        'delete_gdwb_project' => 'Delete projects',
        'manage_gdwb_projects' => 'Manage all projects',
        'manage_gdwb_settings' => 'Manage settings',
        'view_gdwb_analytics' => 'View analytics',
    ];

    public function init(ServiceContainer $container): void {
        $this->container = $container;
        add_action('admin_init', [$this, 'register_capabilities']);
    }

    public function register_capabilities() {
        $admin_role = get_role('administrator');
        if ($admin_role) {
            foreach (self::CAPABILITIES as $cap => $label) {
                $admin_role->add_cap($cap);
            }
        }

        $editor_role = get_role('editor');
        if ($editor_role) {
            $editor_role->add_cap('read_gdwb_project');
            $editor_role->add_cap('edit_gdwb_project');
            $editor_role->add_cap('manage_gdwb_projects');
        }
    }

    public static function user_can($user_id, $capability) {
        return user_can($user_id, $capability);
    }
}
