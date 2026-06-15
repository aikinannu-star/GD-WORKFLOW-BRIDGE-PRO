<?php
/**
 * JWKS helper library — pure functions suitable for unit testing.
 * This file intentionally avoids sending HTTP headers or exiting.
 */

$keysDir = $keysDir ?? __DIR__ . '/keys';
$keysIndexFile = $keysIndexFile ?? $keysDir . '/keys_index.json';

if (!function_exists('getKeysIndex')) {
    function getKeysIndex() {
        global $keysIndexFile;
        if (file_exists($keysIndexFile)) {
            $index = json_decode(file_get_contents($keysIndexFile), true);
            if (is_array($index)) return $index;
        }
        return [
            'current_kid' => null,
            'keys' => [],
            'rotation_history' => []
        ];
    }
}

if (!function_exists('saveKeysIndex')) {
    function saveKeysIndex($index) {
        global $keysIndexFile;
        $tmpFile = $keysIndexFile . '.tmp';
        if (file_put_contents($tmpFile, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
            throw new Exception('Failed to write keys index');
        }
        if (!rename($tmpFile, $keysIndexFile)) {
            @unlink($tmpFile);
            throw new Exception('Failed to rename keys index');
        }
    }
}

if (!function_exists('extractPublicKeyFromPrivate')) {
    function extractPublicKeyFromPrivate($privateKeyPem) {
        $privateKey = openssl_pkey_get_private($privateKeyPem);
        if ($privateKey === false) throw new Exception('Failed to parse private key');
        $details = openssl_pkey_get_details($privateKey);
        if (!isset($details['key'])) throw new Exception('Failed to extract public key');
        return $details['key'];
    }
}

if (!function_exists('base64UrlEncode')) {
    function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

if (!function_exists('pemToJwk')) {
    function pemToJwk($publicKeyPem) {
        $publicKey = openssl_pkey_get_public($publicKeyPem);
        if ($publicKey === false) throw new Exception('Failed to parse public key');
        $details = openssl_pkey_get_details($publicKey);
        if ($details['type'] !== OPENSSL_KEYTYPE_RSA) throw new Exception('Only RSA keys are supported');
        $rsa = $details['rsa'];
        $n = base64UrlEncode($rsa['n']);
        $e = base64UrlEncode($rsa['e']);
        return [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'n' => $n,
            'e' => $e
        ];
    }
}

if (!function_exists('pruneExpiredKeys')) {
    function pruneExpiredKeys(&$index) {
        global $keysDir;
        $now = time();
        $removed = [];
        if (empty($index['keys_meta']) || !is_array($index['keys_meta'])) return $removed;
        foreach ($index['keys_meta'] as $kid => $meta) {
            if (!empty($meta['retire_at']) && strtotime($meta['retire_at']) <= $now) {
                $pub = rtrim($keysDir, "\/") . '/public_' . $kid . '.pem';
                $priv = rtrim($keysDir, "\/") . '/private_' . $kid . '.pem';
                if (file_exists($pub)) @unlink($pub);
                if (file_exists($priv)) @unlink($priv);
                unset($index['keys'][$kid]);
                unset($index['keys_meta'][$kid]);
                $index['rotation_history'][] = [
                    'action' => 'pruned',
                    'kid' => $kid,
                    'timestamp' => date('c')
                ];
                $removed[] = $kid;
            }
        }
        if (!empty($removed)) saveKeysIndex($index);
        return $removed;
    }
}
