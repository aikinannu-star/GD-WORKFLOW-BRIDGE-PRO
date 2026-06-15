<?php
namespace GDWB\Frontend;

use GDWB\Core\ModuleInterface;
use GDWB\Core\ServiceContainer;

if (!defined('ABSPATH')) exit;

class Project_Client_Dashboard implements ModuleInterface {

    private ServiceContainer $container;

    public function init(ServiceContainer $container): void {
        $this->container = $container;
        add_shortcode('gdwb_project_dashboard', [$this, 'render_dashboard']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function enqueue_assets() {
        if (is_singular('gdwb_project') || strpos(get_the_content(), '[gdwb_project_dashboard') !== false) {
            wp_enqueue_script('gdwb-project-dashboard', GDWB_URL . 'assets/js/project-dashboard.js', ['jquery'], GDWB_VERSION);
            wp_enqueue_style('gdwb-project-dashboard', GDWB_URL . 'assets/css/project-dashboard.css', [], GDWB_VERSION);

            wp_localize_script('gdwb-project-dashboard', 'gdwb_dashboard', [
                'project_id' => get_the_ID(),
                'rest_url' => rest_url('gdwb/v1/'),
                'nonce' => wp_create_nonce('wp_rest'),
                'i18n' => [
                    'no_files' => __('No files uploaded yet', 'gdwb'),
                    'send_message' => __('Send Message', 'gdwb'),
                ],
            ]);
        }
    }

    public function render_dashboard($atts) {
        $atts = shortcode_atts(['project_id' => null], $atts);
        $project_id = $atts['project_id'] ?: get_the_ID();

        if (!$project_id) {
            return '<p>' . esc_html__('Invalid project', 'gdwb') . '</p>';
        }

        $post = get_post($project_id);
        if (!$post || $post->post_type !== 'gdwb_project') {
            return '<p>' . esc_html__('Project not found', 'gdwb') . '</p>';
        }

        // Permission check
        if (!$this->user_can_view_project($project_id)) {
            return '<p>' . esc_html__('You do not have permission to view this project', 'gdwb') . '</p>';
        }

        $license_active = (new \GDWB\Admin\License_Manager())->is_license_active();

        ob_start();
        ?>
        <div class="gdwb-project-dashboard">
            <div class="dashboard-header">
                <h1><?php echo esc_html($post->post_title); ?></h1>
                <div class="project-status">
                    <span class="status-badge status-<?php echo esc_attr($post->post_status); ?>">
                        <?php echo esc_html(ucfirst($post->post_status)); ?>
                    </span>
                </div>
            </div>

            <div class="dashboard-container">
                <!-- Files Vault Section -->
                <?php if ($license_active) : ?>
                <div class="dashboard-section files-vault-section">
                    <h2><?php esc_html_e('Files Vault', 'gdwb'); ?></h2>
                    <div class="vault-upload-area">
                        <p><?php esc_html_e('Drag files here or click to browse', 'gdwb'); ?></p>
                        <input type="file" name="vault-file-input" multiple style="display:none;">
                    </div>
                    <div class="vault-file-list"></div>
                </div>
                <?php else : ?>
                <div class="dashboard-section files-vault-section">
                    <h2><?php esc_html_e('Files Vault', 'gdwb'); ?></h2>
                    <p><?php esc_html_e('Files Vault is a premium feature. Activate your license to enable secure file storage and uploads.', 'gdwb'); ?></p>
                    <?php if (current_user_can('manage_options')) : ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=gdwb-license')); ?>" class="btn btn-secondary"><?php esc_html_e('Activate License', 'gdwb'); ?></a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Messages Section -->
                <div class="dashboard-section chat-section">
                    <h2><?php esc_html_e('Project Messages', 'gdwb'); ?></h2>
                    <div class="gdwb-chat-container">
                        <div class="gdwb-chat-messages"></div>
                        <div class="gdwb-chat-input">
                            <form id="chat-form">
                                <div class="chat-input-group">
                                    <textarea name="message" placeholder="<?php esc_attr_e('Type a message...', 'gdwb'); ?>" rows="2" required></textarea>
                                    <button type="submit" class="btn btn-primary"><?php esc_html_e('Send', 'gdwb'); ?></button>
                                </div>
                                <label style="margin-top: 10px;">
                                    <input type="checkbox" name="is_private"> <?php esc_html_e('Private message to staff', 'gdwb'); ?>
                                </label>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Forms Section -->
                <div class="dashboard-section forms-section">
                    <h2><?php esc_html_e('Project Forms', 'gdwb'); ?></h2>

                    <!-- Revision Request Form -->
                    <div class="gdwb-form-revision-request">
                        <h3><?php esc_html_e('Request a Revision', 'gdwb'); ?></h3>
                        <form id="revision-request-form" data-project-id="<?php echo intval($project_id); ?>">
                            <div class="form-group">
                                <label><?php esc_html_e('Revision Title', 'gdwb'); ?></label>
                                <input type="text" name="title" placeholder="<?php esc_attr_e('e.g., Change color scheme', 'gdwb'); ?>" required>
                            </div>

                            <div class="form-group">
                                <label><?php esc_html_e('Detailed Description', 'gdwb'); ?></label>
                                <textarea name="description" rows="5" placeholder="<?php esc_attr_e('Describe what changes you need...', 'gdwb'); ?>" required></textarea>
                            </div>

                            <div class="form-group">
                                <label><?php esc_html_e('Priority', 'gdwb'); ?></label>
                                <select name="priority">
                                    <option value="low"><?php esc_html_e('Low', 'gdwb'); ?></option>
                                    <option value="medium" selected><?php esc_html_e('Medium', 'gdwb'); ?></option>
                                    <option value="high"><?php esc_html_e('High', 'gdwb'); ?></option>
                                </select>
                            </div>

                            <button type="submit" class="btn btn-primary"><?php esc_html_e('Submit Revision Request', 'gdwb'); ?></button>
                            <span class="spinner" style="display:none;"></span>
                        </form>
                        <div class="message" style="display:none;"></div>
                    </div>

                    <!-- Requirements Form -->
                    <div class="gdwb-form-requirements">
                        <h3><?php esc_html_e('Submit Requirements', 'gdwb'); ?></h3>
                        <form id="requirements-form" data-project-id="<?php echo intval($project_id); ?>" enctype="multipart/form-data">
                            <div class="form-group">
                                <label><?php esc_html_e('Project Requirements', 'gdwb'); ?></label>
                                <textarea name="requirements" rows="6" placeholder="<?php esc_attr_e('Describe your project requirements in detail...', 'gdwb'); ?>" required></textarea>
                            </div>

                            <div class="form-group">
                                <label><?php esc_html_e('Expected Deadline', 'gdwb'); ?></label>
                                <input type="date" name="deadline" required>
                            </div>

                            <div class="form-group">
                                <label><?php esc_html_e('Attachment (Optional)', 'gdwb'); ?></label>
                                <input type="file" name="attachment" accept=".pdf,.doc,.docx,.txt,.zip">
                                <small><?php esc_html_e('Max file size: 50MB. Allowed: PDF, DOC, DOCX, TXT, ZIP', 'gdwb'); ?></small>
                            </div>

                            <button type="submit" class="btn btn-primary"><?php esc_html_e('Submit Requirements', 'gdwb'); ?></button>
                            <span class="spinner" style="display:none;"></span>
                        </form>
                        <div class="message" style="display:none;"></div>
                    </div>
                </div>

                <!-- Timeline Section -->
                <div class="dashboard-section timeline-section">
                    <h2><?php esc_html_e('Project Timeline', 'gdwb'); ?></h2>
                    <div class="gdwb-timeline"></div>
                </div>
            </div>
        </div>

        <style>
            .gdwb-project-dashboard {
                background: #f5f5f5;
                padding: 20px;
                border-radius: 8px;
            }

            .dashboard-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 30px;
                background: white;
                padding: 20px;
                border-radius: 8px;
            }

            .status-badge {
                padding: 8px 16px;
                border-radius: 20px;
                font-size: 12px;
                font-weight: bold;
                text-transform: uppercase;
            }

            .status-publish { background: #c8e6c9; color: #2e7d32; }
            .status-pending { background: #fff3cd; color: #856404; }
            .status-draft { background: #e2e3e5; color: #383d41; }

            .dashboard-container {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 20px;
            }

            @media (max-width: 768px) {
                .dashboard-container {
                    grid-template-columns: 1fr;
                }
            }

            .dashboard-section {
                background: white;
                padding: 20px;
                border-radius: 8px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }

            .dashboard-section h2 {
                margin-top: 0;
                color: #333;
                border-bottom: 2px solid #1976d2;
                padding-bottom: 10px;
            }

            .files-vault-section {
                grid-column: span 2;
            }

            .forms-section {
                grid-column: span 2;
            }

            .timeline-section {
                grid-column: span 2;
            }
        </style>

        <?php
        return ob_get_clean();
    }

    private function user_can_view_project($project_id) {
        if (!is_user_logged_in()) {
            return false;
        }

        $post = get_post($project_id);
        if (!$post) {
            return false;
        }

        // Project author
        if ($post->post_author == get_current_user_id()) {
            return true;
        }

        // Admin
        if (current_user_can('manage_options')) {
            return true;
        }

        // Order customer
        $order_id = get_post_meta($project_id, '_gdwb_order_id', true);
        if ($order_id && function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
            if ($order && $order->get_customer_id() == get_current_user_id()) {
                return true;
            }
        }

        return false;
    }
}
