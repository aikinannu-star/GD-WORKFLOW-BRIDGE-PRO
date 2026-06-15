<?php
namespace GDWB\Admin;

if (!defined('ABSPATH')) exit;

class License_Client {

    // Enable remote license server validation by default (change via WP option 'gdwb_license_server_enabled').
    const SERVER_ENABLED = true;
    const SERVER_ENDPOINT = 'http://127.0.0.1:8001';
    const VALIDATE_PATH = '/api/v1/validate';
    const JWKS_PATH = '/api/v1/jwks';
    // Cache JWKS for a short period by default (5 minutes). Can be overridden by env LICENSE_JWKS_CACHE_TTL
    const JWKS_CACHE_TTL = 300; // seconds

    // Fallback placeholder public key; License_Client will attempt to load license-server/keys/public.pem
    const PUBLIC_KEY_PLACEHOLDER = "-----BEGIN PUBLIC KEY-----\nREPLACE_WITH_REAL_PUBLIC_KEY\n-----END PUBLIC KEY-----";

    public function is_enabled(): bool {
        if (function_exists('get_option')) {
            $opt = get_option('gdwb_license_server_enabled', null);
            if ($opt !== null) {
                return (bool) $opt;
            }
        }

        return (bool) self::SERVER_ENABLED && !empty(self::SERVER_ENDPOINT);
    }

    private function get_endpoint(): string {
        if (function_exists('get_option')) {
            $opt = get_option('gdwb_license_server_endpoint', '');
            if (!empty($opt)) {
                return rtrim($opt, '/');
            }
        }

        return rtrim(self::SERVER_ENDPOINT, '/');
    }

    private function getPublicKey(): string {
        // Prefer a packaged public key inside the plugin `keys/public.pem`.
        if (defined('GDWB_PATH')) {
            $path = rtrim(GDWB_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'keys' . DIRECTORY_SEPARATOR . 'public.pem';
            if (file_exists($path)) {
                $contents = trim(file_get_contents($path));
                if (!empty($contents)) {
                    return $contents;
                }
            }
        }

        return self::PUBLIC_KEY_PLACEHOLDER;
    }

    /**
     * Validate license with remote server and verify returned signed token.
     * Returns array: ['success' => bool, 'token' => string|null, 'message' => string|null]
     */
    public function validateLicense(string $license_key): array {
        if (!$this->is_enabled()) {
            return ['success' => false, 'message' => 'server_disabled'];
        }

        $url = $this->get_endpoint() . self::VALIDATE_PATH;
        $args = [
            'body' => [
                'license_key' => $license_key,
                'site' => function_exists('home_url') ? home_url() : '',
            ],
            'timeout' => 15,
            'headers' => [ 'Accept' => 'application/json' ],
        ];

        $resp = wp_remote_post($url, $args);
        if (is_wp_error($resp)) {
            return ['success' => false, 'message' => 'request_failed', 'error' => $resp->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);
        $data = json_decode($body, true);

        if ($code !== 200 || empty($data) || empty($data['success'])) {
            return ['success' => false, 'message' => $data['message'] ?? 'invalid_response'];
        }

        if (empty($data['token'])) {
            return ['success' => false, 'message' => 'no_token'];
        }

        $token = $data['token'];
        if (!$this->isJwtValid($token)) {
            return ['success' => false, 'message' => 'invalid_token'];
        }

        return ['success' => true, 'token' => $token, 'data' => $data];
    }

    /**
     * Fetch JWKS from the server (with caching).
     */
    private function fetchJwks(): ?array {
        $endpoint = $this->get_endpoint();
        
        // Try to get from cache first
        $cache_key = 'gdwb_license_jwks_cache';
        if (function_exists('get_transient')) {
            $cached = get_transient($cache_key);
            if ($cached !== false) {
                $decoded = json_decode($cached, true);
                if (is_array($decoded) && !empty($decoded['keys'])) {
                    return $decoded;
                }
            }
        }
        
        // Fetch from server
        $url = $endpoint . self::JWKS_PATH;
        $args = [
            'timeout' => 10,
            'headers' => ['Accept' => 'application/json'],
        ];
        
        $resp = wp_remote_get($url, $args);
        if (is_wp_error($resp)) {
            return null;
        }
        
        $code = wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);
        $data = json_decode($body, true);
        
        if ($code !== 200 || empty($data) || empty($data['keys'])) {
            return null;
        }
        
        // Cache it
        if (function_exists('set_transient')) {
            set_transient($cache_key, $body, self::JWKS_CACHE_TTL);
        }
        
        return $data;
    }

    /**
     * Get a specific key from JWKS by kid.
     */
    private function getKeyFromJwks(?string $kid): ?array {
        $jwks = $this->fetchJwks();
        if (empty($jwks['keys'])) {
            return null;
        }
        
        foreach ($jwks['keys'] as $key) {
            if (isset($key['kid']) && $key['kid'] === $kid) {
                return $key;
            }
        }
        
        return null;
    }

    /**
     * Convert JWK (RSA) to PEM format for verification.
     */
    private function jwkToPem(array $jwk): ?string {
        if ($jwk['kty'] !== 'RSA' || empty($jwk['n']) || empty($jwk['e'])) {
            return null;
        }
        
        $n = $this->base64UrlDecode($jwk['n']);
        $e = $this->base64UrlDecode($jwk['e']);
        
        if ($n === false || $e === false) {
            return null;
        }
        
        // Build OpenSSL RSA key structure
        $rsa = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        
        if ($rsa === false) {
            return null;
        }
        
        // Unfortunately, PHP's OpenSSL doesn't have a direct JWK-to-PEM function,
        // so we use a workaround: create a fake RSA resource and extract public key details.
        // For now, fall back to using the modulus/exponent directly if possible.
        
        // As a simpler approach: construct a minimal PEM using phpseclib or similar.
        // For this basic implementation, we'll try to use openssl_pkey_export_to_zval
        // or another method. For now, return null and rely on fallback.
        
        return null;
    }

    /**
     * Extract the JWT header (which contains the 'kid').
     */
    private function getHeaderFromJwt(string $jwt): array {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return [];
        }
        
        $header = $this->base64UrlDecode($parts[0]);
        if ($header === false) {
            return [];
        }
        
        $decoded = json_decode($header, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function isJwtValid(string $jwt): bool {
        $payload = $this->getPayloadFromJwt($jwt);
        if (empty($payload)) {
            return false;
        }

        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return false;
        }

        $signed = $parts[0] . '.' . $parts[1];
        $signature = $this->base64UrlDecode($parts[2]);

        // Try JWKS-based verification first (new approach with key rotation support)
        $header = $this->getHeaderFromJwt($jwt);
        $kid = isset($header['kid']) ? $header['kid'] : null;
        
        $verified = false;
        if ($kid) {
            $key = $this->getKeyFromJwks($kid);
            if ($key) {
                // Try to verify using JWKS key
                // Note: Full JWK-to-PEM conversion requires external library
                // For now, we'll verify via optional introspection endpoint
                // In production, use phpseclib or similar for direct JWK verification
            }
        }
        
        // Fall back to static public key verification (original approach)
        if (!$verified) {
            $publicKey = $this->getPublicKey();
            $verified = openssl_verify($signed, $signature, $publicKey, OPENSSL_ALGO_SHA256);
            if ($verified !== 1) {
                return false;
            }
        }

        $exp = isset($payload['exp']) ? (int) $payload['exp'] : 0;
        if ($exp && $exp < time()) {
            return false;
        }

        // Optional remote introspection to consult server-side JTI blacklist. Disabled by default.
        $introspectOpt = false;
        if (function_exists('get_option')) {
            $opt = get_option('gdwb_license_server_introspect', null);
            if ($opt !== null) $introspectOpt = (bool) $opt;
        }

        if ($introspectOpt && $this->is_enabled()) {
            $url = $this->get_endpoint() . '/api/v1/introspect';
            $args = [
                'body' => ['token' => $jwt],
                'timeout' => 5,
                'headers' => ['Accept' => 'application/json'],
            ];

            $resp = wp_remote_post($url, $args);
            if (is_wp_error($resp)) return false;
            $code = wp_remote_retrieve_response_code($resp);
            $body = wp_remote_retrieve_body($resp);
            $data = json_decode($body, true);
            if ($code !== 200 || empty($data) || empty($data['success'])) {
                return false;
            }
        }

        return true;
    }

    // No shared-secret fallback: only RS256/ public key verification is supported.

    public function getPayloadFromJwt(string $jwt): array {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return [];
        }

        $payload = $this->base64UrlDecode($parts[1]);
        if ($payload === false) {
            return [];
        }

        $decoded = json_decode($payload, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function base64UrlDecode(string $input) {
        $remainder = strlen($input) % 4;
        if ($remainder) {
            $input .= str_repeat('=', 4 - $remainder);
        }
        $input = strtr($input, '-_', '+/');
        return base64_decode($input);
    }
}
