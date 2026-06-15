<?php
/**
 * Lightweight JWT signature verification helper used by tests.
 */

// Defaults for keys directory and keys index file when included from other scripts
$keysDir = isset($keysDir) && !empty($keysDir) ? $keysDir : (__DIR__ . '/keys');
$keysIndexFile = isset($keysIndexFile) && !empty($keysIndexFile) ? $keysIndexFile : ($keysDir . '/keys_index.json');

if (!function_exists('base64UrlDecode')) {
    function base64UrlDecode($input) {
        $remainder = strlen($input) % 4;
        if ($remainder) $input .= str_repeat('=', 4 - $remainder);
        $input = strtr($input, '-_', '+/');
        return base64_decode($input);
    }
}

if (!function_exists('generate_jwt')) {
    function generate_jwt(array $payload, string $privateKeyPem, ?string $kid = null) : string {
        $header = ['alg'=>'RS256','typ'=>'JWT'];
        if ($kid !== null) $header['kid'] = $kid;
        $header_b64 = rtrim(strtr(base64_encode(json_encode($header)), '+/', '-_'), '=');
        $payload_b64 = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
        $signed = $header_b64 . '.' . $payload_b64;
        $pkey = openssl_pkey_get_private($privateKeyPem);
        if ($pkey === false) throw new Exception('Invalid private key');
        $sig = '';
        $ok = openssl_sign($signed, $sig, $pkey, OPENSSL_ALGO_SHA256);
        if (!$ok) throw new Exception('Signing failed');
        $sig_b64 = rtrim(strtr(base64_encode($sig), '+/', '-_'), '=');
        return $signed . '.' . $sig_b64;
    }
}

if (!function_exists('verify_token_signature')) {
    function verify_token_signature(string $token) : array {
        // Returns ['verified' => bool, 'payload' => array|null, 'kid_used' => string|null]
        global $keysDir, $keysIndexFile;
        if (empty($token)) return ['verified'=>false,'payload'=>null,'kid_used'=>null];
        $parts = explode('.', $token);
        if (count($parts) !== 3) return ['verified'=>false,'payload'=>null,'kid_used'=>null];
        $signed = $parts[0] . '.' . $parts[1];
        $signature = base64UrlDecode($parts[2]);
        $payload_json = base64UrlDecode($parts[1]);
        $payload = json_decode($payload_json, true);
        $header_json = base64UrlDecode($parts[0]);
        $header = json_decode($header_json, true) ?: [];

        // Load index
        $idx = [];
        if (file_exists($keysIndexFile)) {
            $idx = json_decode(file_get_contents($keysIndexFile), true) ?: [];
        }
        $now = time();

        // Prefer kid from header
        $preferredKid = $header['kid'] ?? null;
        if ($preferredKid && !empty($idx['keys'][$preferredKid])) {
            $meta = $idx['keys_meta'][$preferredKid] ?? null;
            if (empty($meta['retire_at']) || strtotime($meta['retire_at']) > $now) {
                $candidate = rtrim($keysDir, "\/") . '/public_' . $preferredKid . '.pem';
                if (file_exists($candidate)) {
                    $candKey = file_get_contents($candidate);
                    if (openssl_verify($signed, $signature, $candKey, OPENSSL_ALGO_SHA256) === 1) {
                        return ['verified'=>true,'payload'=>$payload,'kid_used'=>$preferredKid];
                    }
                }
            }
        }

        // Fallback: check all non-retired keys
        if (!empty($idx['keys']) && is_array($idx['keys'])) {
            foreach (array_keys($idx['keys']) as $kid) {
                $meta = $idx['keys_meta'][$kid] ?? null;
                if (!empty($meta['retire_at']) && strtotime($meta['retire_at']) <= $now) continue;
                $candidate = rtrim($keysDir, "\/") . '/public_' . $kid . '.pem';
                if (!file_exists($candidate)) continue;
                $candKey = file_get_contents($candidate);
                if (openssl_verify($signed, $signature, $candKey, OPENSSL_ALGO_SHA256) === 1) {
                    return ['verified'=>true,'payload'=>$payload,'kid_used'=>$kid];
                }
            }
        }

        // Legacy: try canonical public.pem
        $canon = rtrim($keysDir, "\/") . '/public.pem';
        if (file_exists($canon)) {
            $candKey = file_get_contents($canon);
            if (openssl_verify($signed, $signature, $candKey, OPENSSL_ALGO_SHA256) === 1) {
                return ['verified'=>true,'payload'=>$payload,'kid_used'=>null];
            }
        }

        return ['verified'=>false,'payload'=>$payload,'kid_used'=>null];
    }
}
