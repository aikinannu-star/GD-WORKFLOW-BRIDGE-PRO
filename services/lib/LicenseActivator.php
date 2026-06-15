<?php
/**
 * LicenseActivator
 * Simple helper to call license-server validate/create endpoints
 */

class LicenseActivator
{
    public static function activate(string $licenseKey, ?string $site = null): array
    {
        $base = rtrim($_ENV['LICENSE_SERVER_BASE'] ?? 'http://127.0.0.1:8001', '/');
        $url = $base . '/api/v1/validate';

        $data = ['license_key' => $licenseKey];
        if ($site) $data['site'] = $site;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);

        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result = json_decode($resp, true) ?: ['success' => false, 'http_code' => $code, 'raw' => $resp];
        return $result;
    }
}
