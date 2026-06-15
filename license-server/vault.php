<?php
// Helper: load secrets from HashiCorp Vault (KV v2 preferred)
// Usage: set VAULT_ADDR, VAULT_TOKEN, VAULT_SECRET_PATH (e.g. secret/data/gdwb)
if (!function_exists('vault_load_secrets')) {
    function vault_load_secrets(): bool {
        $addr = getenv('VAULT_ADDR') ?: getenv('VAULT_URL');
        $token = getenv('VAULT_TOKEN');
        $path = getenv('VAULT_SECRET_PATH') ?: getenv('VAULT_KV_PATH') ?: 'secret/data/gdwb';
        if (empty($addr) || empty($token)) return false;
        $addr = rtrim($addr, '/');
        $url = $addr . '/v1/' . ltrim($path, '/');

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Vault-Token: ' . $token, 'Accept: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($res === false) {
            error_log('vault: curl error: ' . curl_error($ch));
            curl_close($ch);
            return false;
        }
        curl_close($ch);

        $json = json_decode($res, true);
        $data = [];
        // KV v2 stores secrets under data.data
        if (isset($json['data']['data']) && is_array($json['data']['data'])) {
            $data = $json['data']['data'];
        } elseif (isset($json['data']) && is_array($json['data'])) {
            $data = $json['data'];
        } elseif (is_array($json) && isset($json['value']) && is_array($json['value'])) {
            $data = $json['value'];
        } else {
            return false;
        }

        foreach ($data as $k => $v) {
            if (is_array($v) || is_object($v)) {
                $val = json_encode($v);
            } else {
                $val = (string)$v;
            }
            // Do not override existing env vars unless empty
            if (getenv($k) === false || getenv($k) === '') {
                putenv($k . '=' . $val);
                if (isset($_ENV)) $_ENV[$k] = $val;
            }
        }

        return true;
    }
}
