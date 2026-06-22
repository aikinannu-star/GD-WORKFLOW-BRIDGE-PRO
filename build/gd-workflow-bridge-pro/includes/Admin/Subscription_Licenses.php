<?php
namespace GDWB\Admin;

use GDWB\Core\ModuleInterface;
use GDWB\Core\ServiceContainer;

if (!defined('ABSPATH')) exit;

class Subscription_Licenses implements ModuleInterface {

    private ServiceContainer $container;

    public function init(ServiceContainer $container): void {
        $this->container = $container;
        add_action('admin_menu', [$this, 'register_menu']);
    }

    public function register_menu(): void {
        add_submenu_page(
            'gdwb-dashboard',
            __('Subscription Licenses', 'gdwb'),
            __('Subscription Licenses', 'gdwb'),
            'manage_options',
            'gdwb-subscriptions-licenses',
            [$this, 'render_page']
        );
    }

    public function render_page(): void {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Subscription → License Mapping', 'gdwb'); ?></h1>
            <p class="description"><?php esc_html_e('Shows subscription posts and any associated GDWB license keys or projects.', 'gdwb'); ?></p>
            <?php
            $subs = get_posts(['post_type' => 'shop_subscription', 'posts_per_page' => 200, 'post_status' => 'any']);
            if (empty($subs)) {
                echo '<p>' . esc_html__('No subscriptions found.', 'gdwb') . '</p>';
                return;
            }
            ?>
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Subscription ID', 'gdwb'); ?></th>
                        <th><?php esc_html_e('Title', 'gdwb'); ?></th>
                        <th><?php esc_html_e('License Key', 'gdwb'); ?></th>
                        <th><?php esc_html_e('License Token', 'gdwb'); ?></th>
                        <th><?php esc_html_e('Project', 'gdwb'); ?></th>
                        <th><?php esc_html_e('Order', 'gdwb'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($subs as $s) :
                    $license_key = get_post_meta($s->ID, '_gdwb_license_key', true);
                    $license_token = get_post_meta($s->ID, '_gdwb_license_token', true);

                    // find project by serialized subscription meta
                    $project = get_posts([
                        'post_type' => 'gdwb_project',
                        'meta_query' => [[ 'key' => '_gdwb_subscription_ids', 'value' => '"' . $s->ID . '"', 'compare' => 'LIKE' ]],
                        'posts_per_page' => 1,
                        'fields' => 'ids'
                    ]);

                    $project_link = '';
                    if (!empty($project)) $project_link = '<a href="' . esc_url(admin_url('post.php?post=' . $project[0] . '&action=edit')) . '">' . esc_html($project[0]) . '</a>';

                    $order = get_posts([
                        'post_type' => 'shop_order',
                        'meta_query' => [[ 'key' => '_gdwb_subscription_ids', 'value' => '"' . $s->ID . '"', 'compare' => 'LIKE' ]],
                        'posts_per_page' => 1,
                        'fields' => 'ids'
                    ]);

                    $order_link = '';
                    if (!empty($order)) $order_link = '<a href="' . esc_url(admin_url('post.php?post=' . $order[0] . '&action=edit')) . '">' . esc_html($order[0]) . '</a>';

                    echo '<tr>' .
                        '<td>' . esc_html($s->ID) . '</td>' .
                        '<td>' . esc_html(get_the_title($s->ID)) . '</td>' .
                        '<td>' . esc_html($license_key) . '</td>' .
                        '<td>' . esc_html($license_token) . '</td>' .
                        '<td>' . $project_link . '</td>' .
                        '<td>' . $order_link . '</td>' .
                        '</tr>';
                endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
