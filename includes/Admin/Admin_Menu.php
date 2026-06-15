<?php
namespace GDWB\Admin;

use GDWB\Core\ModuleInterface;
use GDWB\Core\ServiceContainer;

if (!defined('ABSPATH')) exit;

class Admin_Menu implements ModuleInterface {

    private ServiceContainer $container;

    public function init(ServiceContainer $container): void {
        $this->container = $container;
        add_action('admin_menu', [$this, 'register_menus']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function register_menus() {
        add_menu_page(
            __('GD Workflow Bridge', 'gdwb'),
            __('GD Workflow', 'gdwb'),
            'manage_options',
            'gdwb-dashboard',
            [$this, 'render_dashboard'],
            'dashicons-portfolio'
        );

        add_submenu_page(
            'gdwb-dashboard',
            __('Projects', 'gdwb'),
            __('Projects', 'gdwb'),
            'manage_gdwb_projects',
            'edit.php?post_type=gdwb_project'
        );

        add_submenu_page(
            'gdwb-dashboard',
            __('Analytics', 'gdwb'),
            __('Analytics', 'gdwb'),
            'manage_options',
            'gdwb-analytics',
            [$this, 'render_analytics']
        );

        add_submenu_page(
            'gdwb-dashboard',
            __('Settings', 'gdwb'),
            __('Settings', 'gdwb'),
            'manage_options',
            'gdwb-settings',
            [$this, 'render_settings']
        );

        add_submenu_page(
            'gdwb-dashboard',
            __('License', 'gdwb'),
            __('License', 'gdwb'),
            'manage_options',
            'gdwb-license',
            [$this, 'render_license']
        );
    }

    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'gdwb-') !== false) {
            wp_enqueue_script('gdwb-admin', GDWB_URL . 'assets/js/admin.js', ['jquery'], GDWB_VERSION);
            wp_enqueue_style('gdwb-admin', GDWB_URL . 'assets/css/admin.css', [], GDWB_VERSION);
            wp_localize_script('gdwb-admin', 'gdwb_admin', [
                'nonce' => wp_create_nonce('wp_rest'),
                'rest_url' => rest_url('gdwb/v1/'),
                'license_nonce' => wp_create_nonce('gdwb_license_nonce'),
                'ajax_url' => admin_url('admin-ajax.php'),
            ]);
        }
    }

    public function render_dashboard() {
        ?>
        <div class="wrap gdwb-dashboard-wrap">
            <h1><?php esc_html_e('GD Workflow Bridge Dashboard', 'gdwb'); ?></h1>
            <div id="gdwb-dashboard-root"></div>
        </div>
        <?php
    }

    public function render_analytics() {
        ?>
        <div class="wrap gdwb-analytics-wrap">
            <h1><?php esc_html_e('Analytics', 'gdwb'); ?></h1>
            <div id="gdwb-analytics-root"></div>
        </div>
        <?php
    }

    public function render_settings() {
        ?>
        <div class="wrap gdwb-settings-wrap">
            <h1><?php esc_html_e('Settings', 'gdwb'); ?></h1>
            <?php if (isset($_GET['stripe_registered'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Stripe webhook registered successfully.', 'gdwb'); ?></p></div>
            <?php endif; ?>
            <?php if (isset($_GET['stripe_deleted'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Stripe webhook deleted.', 'gdwb'); ?></p></div>
            <?php endif; ?>
            <?php if (isset($_GET['stripe_error'])) : ?>
                <div class="notice notice-error is-dismissible"><p><?php echo esc_html(rawurldecode(sanitize_text_field($_GET['stripe_error']))); ?></p></div>
            <?php endif; ?>
            <form method="post" action="options.php">
                <?php
                settings_fields('gdwb_settings');
                do_settings_sections('gdwb_settings');
                ?>

                <h2><?php esc_html_e('License Server', 'gdwb'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="gdwb_license_server_enabled"><?php esc_html_e('Enable License Server', 'gdwb'); ?></label></th>
                        <td>
                            <input type="checkbox" name="gdwb_license_server_enabled" id="gdwb_license_server_enabled" value="1" <?php checked(get_option('gdwb_license_server_enabled', 1), 1); ?> />
                            <p class="description"><?php esc_html_e('Toggle remote license server validation (recommended for production).', 'gdwb'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gdwb_license_server_endpoint"><?php esc_html_e('License Server Endpoint', 'gdwb'); ?></label></th>
                        <td>
                            <input type="text" name="gdwb_license_server_endpoint" id="gdwb_license_server_endpoint" class="regular-text" value="<?php echo esc_attr(get_option('gdwb_license_server_endpoint', 'http://127.0.0.1:8001')); ?>" />
                            <p class="description"><?php esc_html_e('Base URL for the license API (e.g., https://licenses.example.com).', 'gdwb'); ?></p>
                        </td>
                    </tr>
                    <!-- Public key is file-based: place `keys/public.pem` inside the plugin root. -->
                </table>

                <h2><?php esc_html_e('Stripe', 'gdwb'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="gdwb_stripe_api_key"><?php esc_html_e('Stripe Secret Key', 'gdwb'); ?></label></th>
                        <td>
                            <input type="password" name="gdwb_stripe_api_key" id="gdwb_stripe_api_key" class="regular-text" value="<?php echo esc_attr(get_option('gdwb_stripe_api_key', '')); ?>" />
                            <p class="description"><?php esc_html_e('Optional: store your Stripe secret key (or set STRIPE_SECRET_KEY env var).', 'gdwb'); ?></p>
                        </td>
                    </tr>
                        <tr>
                            <th scope="row"><label for="gdwb_stripe_auto_register"><?php esc_html_e('Auto-register webhook', 'gdwb'); ?></label></th>
                            <td>
                                <input type="checkbox" name="gdwb_stripe_auto_register" id="gdwb_stripe_auto_register" value="1" <?php checked(get_option('gdwb_stripe_auto_register', 0), 1); ?> />
                                <p class="description"><?php esc_html_e('When enabled, the plugin will attempt to register the webhook endpoint with Stripe automatically when an administrator visits the settings page.', 'gdwb'); ?></p>
                            </td>
                        </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Webhook endpoint', 'gdwb'); ?></th>
                        <td>
                            <code><?php echo esc_html( get_option('gdwb_stripe_webhook_endpoint_url', rest_url('gdwb/v1/stripe-webhook')) ); ?></code>
                            <p class="description"><?php esc_html_e('This is the URL you can register in Stripe (or use the Register button below).', 'gdwb'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gdwb_stripe_webhook_secret"><?php esc_html_e('Webhook signing secret', 'gdwb'); ?></label></th>
                        <td>
                            <input type="text" readonly id="gdwb_stripe_webhook_secret" class="regular-text" value="<?php echo esc_attr(get_option('gdwb_stripe_webhook_secret', '')); ?>" />
                            <p class="description"><?php esc_html_e('Saved signing secret used to verify incoming Stripe webhooks.', 'gdwb'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <div style="margin-top:1.5rem;">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:1rem;">
                    <?php wp_nonce_field('gdwb_stripe_register_nonce'); ?>
                    <input type="hidden" name="action" value="gdwb_register_stripe_webhook" />
                    <?php submit_button(__('Register Stripe Webhook', 'gdwb'), 'primary', 'gdwb_register_stripe', false); ?>
                </form>

                <?php if (get_option('gdwb_stripe_webhook_id')) : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                        <?php wp_nonce_field('gdwb_stripe_delete_nonce'); ?>
                        <input type="hidden" name="action" value="gdwb_delete_stripe_webhook" />
                        <?php submit_button(__('Delete Stripe Webhook', 'gdwb'), 'secondary', 'gdwb_delete_stripe', false); ?>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public function register_settings() {
        register_setting('gdwb_settings', 'gdwb_license_server_enabled', ['type' => 'boolean', 'default' => 1]);
        register_setting('gdwb_settings', 'gdwb_license_server_endpoint', ['type' => 'string', 'default' => 'http://127.0.0.1:8001']);
        register_setting('gdwb_settings', 'gdwb_stripe_api_key', ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);
        register_setting('gdwb_settings', 'gdwb_stripe_webhook_secret', ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);
        register_setting('gdwb_settings', 'gdwb_stripe_webhook_id', ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);
        register_setting('gdwb_settings', 'gdwb_stripe_webhook_endpoint_url', ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);
        register_setting('gdwb_settings', 'gdwb_stripe_auto_register', ['type' => 'boolean', 'default' => 0]);
    }

    public function render_license() {
        $license_manager = new License_Manager();
        $license_info = $license_manager->get_license_info();
        $is_active = $license_info['status'] === 'active';
        $is_expired = $license_info['status'] === 'expired';
        ?>
        <div class="wrap gdwb-license-wrap">
            <h1><?php esc_html_e('License Management', 'gdwb'); ?></h1>
            <div id="gdwb-license-message"></div>
            <?php if (isset($_GET['license_message'])) : ?>
                <div class="notice notice-<?php echo esc_attr($_GET['license_status'] === 'success' ? 'success' : 'error'); ?> is-dismissible">
                    <p><?php echo esc_html(rawurldecode(sanitize_text_field($_GET['license_message']))); ?></p>
                </div>
            <?php endif; ?>

            <div class="gdwb-license-form">
                <div class="gdwb-license-status">
                    <p><?php esc_html_e('Status:', 'gdwb'); ?> <strong><?php echo esc_html(ucfirst($license_info['status'])); ?></strong></p>
                    <p><?php esc_html_e('License Key:', 'gdwb'); ?> <code><?php echo esc_html($license_info['key']); ?></code></p>
                    <p><?php esc_html_e('Expires:', 'gdwb'); ?> <strong><?php echo esc_html($license_info['expiry_label']); ?></strong></p>
                    <p><?php esc_html_e('Activated on:', 'gdwb'); ?> <strong><?php echo $license_info['activated_at'] ? esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $license_info['activated_at'])) : esc_html__('N/A', 'gdwb'); ?></strong></p>
                    <p><?php esc_html_e('Licensed domain:', 'gdwb'); ?> <strong><?php echo esc_html($license_info['domain']); ?></strong></p>
                </div>

                <hr>

                <h2>
                    <?php 
                    if ($is_active) {
                        esc_html_e('Update License', 'gdwb');
                    } else {
                        esc_html_e('Enter License Key', 'gdwb');
                    }
                    ?>
                </h2>
                <form id="gdwb-license-form" method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('gdwb_license_nonce', 'nonce'); ?>
                    <input type="hidden" name="action" value="gdwb_activate_license">
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="license_key"><?php esc_html_e('License Key', 'gdwb'); ?></label>
                            </th>
                            <td>
                                <input type="text" name="license_key" id="license_key" class="regular-text" placeholder="<?php esc_attr_e('Paste your license key here', 'gdwb'); ?>" required />
                                <p class="description"><?php esc_html_e('Enter your license key to activate premium features.', 'gdwb'); ?></p>
                            </td>
                        </tr>
                    </table>
                    <?php 
                    if ($is_active) {
                        submit_button(__('Update License', 'gdwb'));
                    } else {
                        submit_button(__('Activate License', 'gdwb'));
                    }
                    ?>
                </form>

                <?php if ($is_active || $is_expired) : ?>
                    <form id="gdwb-license-deactivate-form" method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top: 1.5rem;">
                        <?php wp_nonce_field('gdwb_license_nonce', 'nonce'); ?>
                        <input type="hidden" name="action" value="gdwb_deactivate_license">
                        <?php submit_button(__('Deactivate License', 'gdwb'), 'secondary', 'gdwb-license-deactivate', false); ?>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}
