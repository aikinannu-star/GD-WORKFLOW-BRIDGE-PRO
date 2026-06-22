<?php
namespace GDWB\Admin;

use GDWB\Core\ModuleInterface;
use GDWB\Core\ServiceContainer;

if (!defined('ABSPATH')) exit;
require_once GDWB_PATH . 'includes/Admin/License_Client.php';

class License_Manager implements ModuleInterface {

    private ServiceContainer $container;
    const LICENSE_KEY_OPTION = 'gdwb_license_key';
    const LICENSE_STATUS_OPTION = 'gdwb_license_status';
    const LICENSE_EXPIRY_OPTION = 'gdwb_license_expiry';
    const LICENSE_ACTIVATED_OPTION = 'gdwb_license_activated_at';
    const LICENSE_DOMAIN_OPTION = 'gdwb_license_domain';
    const LICENSE_HASH_OPTION = 'gdwb_license_hash';
    const LICENSE_TOKEN_OPTION = 'gdwb_license_token';

    public function init(ServiceContainer $container): void {
        $this->container = $container;
        add_action('init', [$this, 'check_license']);
        add_action('admin_init', [$this, 'check_license']);
        add_action('gdwb_revalidate_license_cron', [$this, 'revalidate_license']);
        if (!wp_next_scheduled('gdwb_revalidate_license_cron')) {
            wp_schedule_event(time() + 3600, 'hourly', 'gdwb_revalidate_license_cron');
        }
        add_action('wp_ajax_gdwb_activate_license', [$this, 'activate_license']);
        add_action('wp_ajax_gdwb_deactivate_license', [$this, 'deactivate_license']);
        add_action('admin_post_gdwb_activate_license', [$this, 'activate_license']);
        add_action('admin_post_gdwb_deactivate_license', [$this, 'deactivate_license']);
        // Clear legacy/staging options: remove shared-secret and DB-stored public key if present
        if (function_exists('delete_option')) {
            @delete_option('gdwb_license_shared_secret');
            @delete_option('gdwb_license_public_key');
        }
    }

    public function revalidate_license() {
        $license_key = get_option(self::LICENSE_KEY_OPTION, '');
        if (empty($license_key)) return;

        $client = new License_Client();
        if (!$client->is_enabled()) return;

        $res = $client->validateLicense($license_key);
        if (!empty($res['success']) && !empty($res['token'])) {
            update_option(self::LICENSE_TOKEN_OPTION, $res['token']);
            $payload = $client->getPayloadFromJwt($res['token']);
            $expiry = isset($payload['exp']) ? (int) $payload['exp'] : 0;
            update_option(self::LICENSE_EXPIRY_OPTION, $expiry);
            update_option(self::LICENSE_STATUS_OPTION, 'active');
        } else {
            update_option(self::LICENSE_STATUS_OPTION, 'invalid');
        }
    }

    private function send_response(bool $success, array $data = [], int $status = 200) {
        if (wp_doing_ajax()) {
            if ($success) {
                wp_send_json_success($data, $status);
            }
            wp_send_json_error($data, $status);
        }

        $redirect_url = admin_url('admin.php?page=gdwb-license');
        if (!empty($data['message'])) {
            $redirect_url = add_query_arg(
                [
                    'license_status' => $success ? 'success' : 'error',
                    'license_message' => rawurlencode($data['message']),
                ],
                $redirect_url
            );
        }

        wp_safe_redirect($redirect_url);
        exit;
    }

    public function activate_license() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'gdwb_license_nonce')) {
            $this->send_response(false, ['message' => __('Security check failed. Please refresh and try again.', 'gdwb')], 403);
        }

        if (!current_user_can('manage_options')) {
            $this->send_response(false, ['message' => __('Permission denied', 'gdwb')], 403);
        }

        $license_key = isset($_POST['license_key']) ? strtoupper(trim(sanitize_text_field($_POST['license_key']))) : '';
        if (empty($license_key)) {
            $this->send_response(false, ['message' => __('License key is required', 'gdwb')], 400);
        }

        if (!$this->validate_license_format($license_key)) {
            $this->send_response(false, ['message' => __('Invalid license key format. Must be 20+ characters with letters, numbers and hyphens.', 'gdwb')], 400);
        }

        $client = new License_Client();
        if ($client->is_enabled()) {
            $serverRes = $client->validateLicense($license_key);
            if (empty($serverRes['success'])) {
                $msg = is_string($serverRes['message']) ? $serverRes['message'] : __('License server validation failed', 'gdwb');
                $this->send_response(false, ['message' => __($msg, 'gdwb')], 400);
            }

            $token = $serverRes['token'] ?? '';
            $payload = $client->getPayloadFromJwt($token);
            $expiry = isset($payload['exp']) ? (int) $payload['exp'] : $this->calculate_license_expiry($license_key);

            update_option(self::LICENSE_KEY_OPTION, $license_key);
            update_option(self::LICENSE_STATUS_OPTION, 'active');
            update_option(self::LICENSE_EXPIRY_OPTION, $expiry);
            update_option(self::LICENSE_ACTIVATED_OPTION, time());
            update_option(self::LICENSE_DOMAIN_OPTION, home_url());
            update_option(self::LICENSE_HASH_OPTION, $this->generate_license_hash($license_key));
            update_option(self::LICENSE_TOKEN_OPTION, $token);
        } else {
            // Fallback to local activation when server validation is disabled
            $expiry = $this->calculate_license_expiry($license_key);
            update_option(self::LICENSE_KEY_OPTION, $license_key);
            update_option(self::LICENSE_STATUS_OPTION, 'active');
            update_option(self::LICENSE_EXPIRY_OPTION, $expiry);
            update_option(self::LICENSE_ACTIVATED_OPTION, time());
            update_option(self::LICENSE_DOMAIN_OPTION, home_url());
            update_option(self::LICENSE_HASH_OPTION, $this->generate_license_hash($license_key));
        }

        $this->send_response(true, ['message' => __('License activated successfully', 'gdwb')]);
    }

    public function deactivate_license() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'gdwb_license_nonce')) {
            $this->send_response(false, ['message' => __('Security check failed. Please refresh and try again.', 'gdwb')], 403);
        }

        if (!current_user_can('manage_options')) {
            $this->send_response(false, ['message' => __('Permission denied', 'gdwb')], 403);
        }

        update_option(self::LICENSE_STATUS_OPTION, 'inactive');
        update_option(self::LICENSE_EXPIRY_OPTION, 0);
        update_option(self::LICENSE_ACTIVATED_OPTION, 0);
        update_option(self::LICENSE_HASH_OPTION, '');

        $this->send_response(true, ['message' => __('License deactivated successfully', 'gdwb')]);
    }

    public function check_license() {
        $client = new License_Client();

        // If a remote license server is enabled, treat it as authoritative.
        if ($client->is_enabled()) {
            $token = get_option(self::LICENSE_TOKEN_OPTION, '');
            $license_key = get_option(self::LICENSE_KEY_OPTION, '');

            // If we have a valid cached token, ensure expiry/status reflect it.
            if (!empty($token) && $client->isJwtValid($token)) {
                $payload = $client->getPayloadFromJwt($token);
                $expiry = isset($payload['exp']) ? (int) $payload['exp'] : 0;
                update_option(self::LICENSE_EXPIRY_OPTION, $expiry);
                update_option(self::LICENSE_STATUS_OPTION, 'active');
                return;
            }

            // No valid token: try to revalidate using the stored license key (migration path).
            if (!empty($license_key) && $this->validate_license_format($license_key)) {
                $res = $client->validateLicense($license_key);
                if (!empty($res['success']) && !empty($res['token'])) {
                    update_option(self::LICENSE_TOKEN_OPTION, $res['token']);
                    $payload = $client->getPayloadFromJwt($res['token']);
                    $expiry = isset($payload['exp']) ? (int) $payload['exp'] : 0;
                    update_option(self::LICENSE_EXPIRY_OPTION, $expiry);
                    update_option(self::LICENSE_STATUS_OPTION, 'active');
                    return;
                }

                // Server rejected the key
                update_option(self::LICENSE_STATUS_OPTION, 'invalid');
                return;
            }

            // No token and no valid key => license inactive
            update_option(self::LICENSE_STATUS_OPTION, 'inactive');
            return;
        }

        // Legacy/local-only license handling (no remote server)
        $status = get_option(self::LICENSE_STATUS_OPTION, 'inactive');
        $expiry = get_option(self::LICENSE_EXPIRY_OPTION, 0);
        $license_key = get_option(self::LICENSE_KEY_OPTION, '');

        if ($status === 'active') {
            if ($expiry && $expiry < time()) {
                update_option(self::LICENSE_STATUS_OPTION, 'expired');
                return;
            }

            if (!$this->validate_license_format($license_key) || !$this->is_saved_license_hash_valid($license_key)) {
                update_option(self::LICENSE_STATUS_OPTION, 'invalid');
            }
        }
    }

    public function is_license_active() {
        $client = new License_Client();

        if ($client->is_enabled()) {
            $token = get_option(self::LICENSE_TOKEN_OPTION, '');
            if (!empty($token) && $client->isJwtValid($token)) {
                // Ensure cached expiry/status are up to date
                $payload = $client->getPayloadFromJwt($token);
                $expiry = isset($payload['exp']) ? (int) $payload['exp'] : 0;
                update_option(self::LICENSE_EXPIRY_OPTION, $expiry);
                update_option(self::LICENSE_STATUS_OPTION, 'active');
                return true;
            }

            // Try to revalidate using stored license key when token missing/invalid
            $license_key = get_option(self::LICENSE_KEY_OPTION, '');
            if (!empty($license_key) && $this->validate_license_format($license_key)) {
                $res = $client->validateLicense($license_key);
                if (!empty($res['success']) && !empty($res['token'])) {
                    update_option(self::LICENSE_TOKEN_OPTION, $res['token']);
                    $payload = $client->getPayloadFromJwt($res['token']);
                    $expiry = isset($payload['exp']) ? (int) $payload['exp'] : 0;
                    update_option(self::LICENSE_EXPIRY_OPTION, $expiry);
                    update_option(self::LICENSE_STATUS_OPTION, 'active');
                    return true;
                }

                update_option(self::LICENSE_STATUS_OPTION, 'invalid');
                return false;
            }

            return false;
        }

        return get_option(self::LICENSE_STATUS_OPTION, 'inactive') === 'active';
    }

    public function get_license_info() {
        $status = get_option(self::LICENSE_STATUS_OPTION, 'inactive');
        $expiry = get_option(self::LICENSE_EXPIRY_OPTION, 0);
        $license_key = get_option(self::LICENSE_KEY_OPTION, '');

        return [
            'status' => $status,
            'expiry' => $expiry,
            'expiry_label' => $expiry ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $expiry) : __('N/A', 'gdwb'),
            'key' => $this->mask_license_key($license_key),
            'domain' => get_option(self::LICENSE_DOMAIN_OPTION, home_url()),
            'activated_at' => get_option(self::LICENSE_ACTIVATED_OPTION, 0),
        ];
    }

    public function validate_license_format(string $license_key): bool {
        return !empty($license_key) && strlen($license_key) >= 20 && (bool) preg_match('/^[A-Z0-9\-]+$/', $license_key);
    }

    private function calculate_license_expiry(string $license_key): int {
        if (stripos($license_key, 'TEST-') === 0) {
            return strtotime('+30 days');
        }

        return strtotime('+1 year');
    }

    private function mask_license_key(string $license_key): string {
        if (empty($license_key)) {
            return __('None', 'gdwb');
        }

        $visible = substr($license_key, 0, 8);
        return $visible . '***';
    }

    private function generate_license_hash(string $license_key): string {
        return hash_hmac('sha256', $license_key . '|' . home_url(), 'gdwb_secret_key_2026');
    }

    private function is_saved_license_hash_valid(string $license_key): bool {
        $saved_hash = get_option(self::LICENSE_HASH_OPTION, '');

        if (empty($saved_hash) || empty($license_key)) {
            return false;
        }

        return hash_equals($saved_hash, $this->generate_license_hash($license_key));
    }
}
