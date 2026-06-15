<?php
namespace GDWB\Integrations;

use GDWB\Core\ModuleInterface;
use GDWB\Core\ServiceContainer;
use GDWB\Admin\License_Manager;

if (!defined('ABSPATH')) exit;

class ActionSchedulerIntegration implements ModuleInterface {

    private ServiceContainer $container;

    public function init(ServiceContainer $container): void {
        $this->container = $container;
        // Gate ActionScheduler integration behind license
        $license = new License_Manager();
        if (!$license->is_license_active()) {
            return;
        }

        add_action('init', [$this, 'maybe_load_actions']);
        add_action('gdwb_process_project', [$this, 'process_project'], 10, 1);
    }

    public function maybe_load_actions() {
        // If Action Scheduler is not present but installed via composer, try to load it
        if (!class_exists('ActionScheduler')) {
            if (file_exists(GDWB_PATH . 'vendor/action-scheduler/action-scheduler/action-scheduler.php')) {
                require_once GDWB_PATH . 'vendor/action-scheduler/action-scheduler/action-scheduler.php';
            }
        }
    }

    public function process_project($args) {
        $project_id = 0;
        if (is_array($args) && isset($args['project_id'])) {
            $project_id = (int) $args['project_id'];
        } elseif (is_int($args)) {
            $project_id = $args;
        }

        if (!$project_id) {
            return;
        }

        $logger = $this->container->get('logger');
        if ($logger) {
            $logger->log('Processing project ' . $project_id);
        } else {
            error_log('[gdwb] Processing project ' . $project_id);
        }

        // TODO: perform heavy tasks: sync external systems, generate assets, send notifications
    }
}
