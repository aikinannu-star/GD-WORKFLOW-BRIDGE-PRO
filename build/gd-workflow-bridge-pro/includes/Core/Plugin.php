<?php
namespace GDWB\Core;

if (!defined('ABSPATH')) exit;

class Plugin {

    private static $instance = null;

    private $container;

    private $module_loader;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        add_action('init', [$this, 'load_textdomain']);
        add_action('init', [$this, 'register_post_type']);
        add_action('init', [$this, 'ensure_db_tables']);
        register_activation_hook(GDWB_PATH . 'gd-workflow-bridge-pro.php', [$this, 'activate_plugin']);
        register_deactivation_hook(GDWB_PATH . 'gd-workflow-bridge-pro.php', [$this, 'deactivate_plugin']);

        // Try composer autoload first
        if (file_exists(GDWB_PATH . 'vendor/autoload.php')) {
            require_once GDWB_PATH . 'vendor/autoload.php';
        } else {
            // Fallback to manual includes
            require_once GDWB_PATH . 'includes/Core/PluginSetup.php';
            require_once GDWB_PATH . 'includes/Core/ServiceContainer.php';
            require_once GDWB_PATH . 'includes/Core/ModuleInterface.php';
            require_once GDWB_PATH . 'includes/Core/ModuleLoader.php';
            require_once GDWB_PATH . 'includes/Core/Logger.php';
            require_once GDWB_PATH . 'includes/Core/Activator.php';
            require_once GDWB_PATH . 'includes/Core/Deactivator.php';
        }

        // Initialize DI container and loader
        $this->container = new ServiceContainer();
        $this->container->set('logger', new Logger());
        $this->module_loader = new ModuleLoader($this->container);

        // Register modules (lazy loading via module loader)
        $this->module_loader->registerModule(\GDWB\Frontend\Shortcodes::class);
        $this->module_loader->registerModule(\GDWB\Frontend\Dashboard::class);
        $this->module_loader->registerModule(\GDWB\Frontend\Project_Client_Dashboard::class);
        $this->module_loader->registerModule(\GDWB\Projects\Project_Manager::class);
        $this->module_loader->registerModule(\GDWB\Projects\Upload_Manager::class);
        $this->module_loader->registerModule(\GDWB\Projects\Timeline_Manager::class);
        $this->module_loader->registerModule(\GDWB\Projects\Chat_Manager::class);
        $this->module_loader->registerModule(\GDWB\Projects\Forms_Manager::class);
        $this->module_loader->registerModule(\GDWB\Admin\License_Manager::class);
        $this->module_loader->registerModule(\GDWB\Admin\Admin_Menu::class);
        $this->module_loader->registerModule(\GDWB\Admin\Subscription_Licenses::class);
        $this->module_loader->registerModule(\GDWB\Admin\Stripe_Manager::class);
        $this->module_loader->registerModule(\GDWB\Admin\Capabilities_Manager::class);
        $this->module_loader->registerModule(\GDWB\Admin\Audit_Logger::class);
        $this->module_loader->registerModule(\GDWB\Admin\Webhook_Manager::class);
        $this->module_loader->registerModule(\GDWB\API\Rest_API::class);
        $this->module_loader->registerModule(\GDWB\API\Stats_API::class);
        $this->module_loader->registerModule(\GDWB\Notifications\Email_Manager::class);
        $this->module_loader->registerModule(\GDWB\Notifications\Live_Notifications::class);
        $this->module_loader->registerModule(\GDWB\Integrations\ActionSchedulerIntegration::class);
        $this->module_loader->registerModule(\GDWB\Integrations\Analytics::class);
        $this->module_loader->registerModule(\GDWB\Integrations\Files_Vault::class);

        if ($this->is_woocommerce_active()) {
            $this->module_loader->registerModule(\GDWB\WooCommerce\Order_Handler::class);
            $this->module_loader->registerModule(\GDWB\WooCommerce\License_Provisioner::class);
            $this->module_loader->registerModule(\GDWB\WooCommerce\Subscription_Handler::class);
            $this->module_loader->registerModule(\GDWB\WooCommerce\Stripe_Webhook::class);
        }

        // Initialize modules
        $this->module_loader->init();
    }

    private function is_woocommerce_active() {
        return function_exists('is_plugin_active') && is_plugin_active('woocommerce/woocommerce.php');
    }

    public function activate_plugin() {
        flush_rewrite_rules();
    }

    public function deactivate_plugin() {
        flush_rewrite_rules();
    }

    public function load_textdomain() {
        load_plugin_textdomain('gdwb', false, dirname(plugin_basename(GDWB_PATH . 'gd-workflow-bridge-pro.php')) . '/languages');
    }

    public function register_post_type() {
        register_post_type('gdwb_project', [
            'labels' => [
                'name' => __('Workflow Projects', 'gdwb'),
                'singular_name' => __('Workflow Project', 'gdwb')
            ],
            'public' => false,
            'show_ui' => true,
            'supports' => ['title'],
            'menu_icon' => 'dashicons-portfolio'
        ]);
    }

    public function ensure_db_tables() {
        $activator = new Activator();
        $activator->activate();
    }
}
