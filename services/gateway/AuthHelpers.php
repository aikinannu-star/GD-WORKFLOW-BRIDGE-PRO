<?php
require_once __DIR__ . '/../lib/ServiceHelpers.php';

class GatewayAuthHelpers
{
    public static function getApiKeyInfoFromHeaders(array $headers): ?array
    {
        $lower = array_change_key_case($headers, CASE_LOWER);
        $key = $lower['x-api-key'] ?? $lower['x_api_key'] ?? $lower['xapikey'] ?? null;
        if (!$key) return null;
        $keys = ServiceHelpers::loadJson('gateway', 'api_keys.json');
        if (empty($keys)) {
            $fallback = __DIR__ . '/../../services/data/api_keys.json';
            if (file_exists($fallback)) {
                $keys = json_decode(file_get_contents($fallback), true) ?? [];
            }
        }
        if (isset($keys[$key]) && is_array($keys[$key])) return $keys[$key];
        foreach ($keys as $k => $v) {
            if (is_array($v) && ((isset($v['key']) && $v['key'] === $key) || $k === $key)) return $v;
        }
        return null;
    }

    public static function apiKeyAllowedForService(array $apiKeyInfo, string $serviceKey): array
    {
        // revoked check
        if (!empty($apiKeyInfo['revoked'])) {
            return ['ok' => false, 'status' => 401, 'error' => 'invalid_api_key', 'message' => 'API key revoked'];
        }
        // expiry check
        if (!empty($apiKeyInfo['expires_at'])) {
            $ts = @strtotime($apiKeyInfo['expires_at']);
            if ($ts !== false && time() > $ts) {
                return ['ok' => false, 'status' => 401, 'error' => 'invalid_api_key', 'message' => 'API key expired'];
            }
        }

        // scopes check: allow when scopes empty (legacy permissive) OR when any scope matches required candidates
        $scopes = $apiKeyInfo['scopes'] ?? [];
        if (!empty($scopes) && is_array($scopes)) {
            $candidates = [
                $serviceKey . ':invoke',
                $serviceKey,
                'service:' . $serviceKey,
                'gateway:' . $serviceKey,
                '*',
            ];
            $matched = false;
            foreach ($scopes as $s) {
                if (in_array($s, $candidates, true)) {
                    $matched = true;
                    break;
                }
                // allow prefix match like assistant:*
                foreach ($candidates as $cand) {
                    if (strpos($s, '*') !== false) {
                        $pat = str_replace('\\*', '.*', preg_quote($s, '/'));
                        if (preg_match('/^' . $pat . '$/i', $cand)) {
                            $matched = true;
                            break 2;
                        }
                    }
                }
            }
            if (!$matched) {
                return ['ok' => false, 'status' => 403, 'error' => 'insufficient_scope', 'message' => 'API key missing required scope for target service'];
            }
        }
        return ['ok' => true];
    }
}

?>
