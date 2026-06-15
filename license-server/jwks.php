<?php
/**
 * JWKS endpoint for public key discovery and key rotation.
 * 
 * Implements JSON Web Key Set (JWKS) to allow distributed clients to:
 * - Discover the current public signing key
 * - Verify JWT tokens offline with the public key
 * - Support gradual key rotation (old and new keys available during transition)
 */

// Ensure proper error handling
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error',
        'error' => $errstr
    ]);
    exit;
});

header('Content-Type: application/json');
header('Cache-Control: public, max-age=3600');

$keysDir = __DIR__ . '/keys';
$keysIndexFile = $keysDir . '/keys_index.json';

// Ensure keys directory exists
if (!is_dir($keysDir)) {
    @mkdir($keysDir, 0755, true);
}

/**
 * POST /api/v1/jwks/activate
 * Admin-only: set a specific existing kid as the current signing key.
 * Body: { "kid": "kid_xxx" }
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && preg_match('#/api/v1/jwks/activate$#', $_SERVER['REQUEST_URI'])) {
    if (file_exists(__DIR__ . '/admin.php')) {
        require_once __DIR__ . '/admin.php';
        if (!function_exists('admin_authorize') || !admin_authorize(['activate'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = $_POST;
    $kid = isset($input['kid']) ? trim($input['kid']) : '';
    if (empty($kid)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'kid required']);
        exit;
    }

    try {
        $index = getKeysIndex();
        if (empty($index['keys'][$kid])) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'kid_not_found']);
            exit;
        }

        $now = time();
        $grace = intval(getenv('LICENSE_KEY_GRACE_PERIOD_SECONDS') ?: (7 * 24 * 3600));
        $old = $index['current_kid'] ?? null;

        // set retire_at for old key
        if (!empty($old)) {
            if (!isset($index['keys_meta'][$old])) $index['keys_meta'][$old] = ['created_at' => null, 'retire_at' => null];
            $index['keys_meta'][$old]['retire_at'] = date('c', $now + $grace);
        }

        // set as current
        $index['current_kid'] = $kid;
        $index['rotation_history'][] = [
            'action' => 'activate',
            'old_kid' => $old,
            'new_kid' => $kid,
            'timestamp' => date('c')
        ];

        // update canonical public/private paths
        $pubPath = $index['keys_meta'][$kid]['public_key_path'] ?? (__DIR__ . '/keys/public_' . $kid . '.pem');
        $privPath = $index['keys_meta'][$kid]['private_key_path'] ?? (__DIR__ . '/keys/private_' . $kid . '.pem');

        $currentPublicPath = __DIR__ . '/keys/public.pem';
        @unlink($currentPublicPath);
        $linked = false;
        $isWindows = (strtoupper(substr(PHP_OS,0,3)) === 'WIN');
        if (!$isWindows && function_exists('symlink')) {
            try { @symlink($pubPath, $currentPublicPath); if (file_exists($currentPublicPath)) $linked = true; } catch (Throwable $e) { }
        }
        if (!$linked) { copy($pubPath, $currentPublicPath); @chmod($currentPublicPath, 0644); }

        $currentPrivatePath = __DIR__ . '/keys/private.pem';
        @unlink($currentPrivatePath);
        $linked = false;
        if (!$isWindows && function_exists('symlink')) {
            try { @symlink($privPath, $currentPrivatePath); if (file_exists($currentPrivatePath)) $linked = true; } catch (Throwable $e) { }
        }
        if (!$linked) { copy($privPath, $currentPrivatePath); @chmod($currentPrivatePath, 0600); }

        saveKeysIndex($index);

        if (function_exists('inc_metric')) inc_metric('jwks_activates_total', 1);
        if (function_exists('audit_log_admin')) audit_log_admin('activate', ['kid' => $kid, 'old_kid' => $old]);

        echo json_encode(['success' => true, 'message' => 'activated', 'kid' => $kid, 'old_kid' => $old]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

/**
 * POST /api/v1/jwks/rollback
 * Admin-only: rollback to the previous key recorded in rotation_history.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && preg_match('#/api/v1/jwks/rollback$#', $_SERVER['REQUEST_URI'])) {
    if (file_exists(__DIR__ . '/admin.php')) {
        require_once __DIR__ . '/admin.php';
        if (!function_exists('admin_authorize') || !admin_authorize(['rollback'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
    }

    try {
        $index = getKeysIndex();
        // find last rotation/activate record
        $hist = array_reverse($index['rotation_history']);
        $prevKid = null;
        foreach ($hist as $h) {
            if (!empty($h['action']) && in_array($h['action'], ['rotation','activate'])) {
                $prevKid = $h['old_kid'] ?? null;
                if (!empty($prevKid)) break;
            }
        }
        if (empty($prevKid) || empty($index['keys'][$prevKid])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'no_previous_key']);
            exit;
        }

        // reuse activate logic: set prevKid as current
        $now = time();
        $grace = intval(getenv('LICENSE_KEY_GRACE_PERIOD_SECONDS') ?: (7 * 24 * 3600));
        $old = $index['current_kid'] ?? null;
        if (!empty($old)) {
            if (!isset($index['keys_meta'][$old])) $index['keys_meta'][$old] = ['created_at' => null, 'retire_at' => null];
            $index['keys_meta'][$old]['retire_at'] = date('c', $now + $grace);
        }
        $index['current_kid'] = $prevKid;
        $index['rotation_history'][] = [
            'action' => 'rollback',
            'old_kid' => $old,
            'new_kid' => $prevKid,
            'timestamp' => date('c')
        ];

        // update canonical symlinks/copies
        $pubPath = $index['keys_meta'][$prevKid]['public_key_path'] ?? (__DIR__ . '/keys/public_' . $prevKid . '.pem');
        $privPath = $index['keys_meta'][$prevKid]['private_key_path'] ?? (__DIR__ . '/keys/private_' . $prevKid . '.pem');
        $currentPublicPath = __DIR__ . '/keys/public.pem';
        @unlink($currentPublicPath);
        $linked = false;
        $isWindows = (strtoupper(substr(PHP_OS,0,3)) === 'WIN');
        if (!$isWindows && function_exists('symlink')) {
            try { @symlink($pubPath, $currentPublicPath); if (file_exists($currentPublicPath)) $linked = true; } catch (Throwable $e) { }
        }
        if (!$linked) { copy($pubPath, $currentPublicPath); @chmod($currentPublicPath, 0644); }

        $currentPrivatePath = __DIR__ . '/keys/private.pem';
        @unlink($currentPrivatePath);
        $linked = false;
        if (!$isWindows && function_exists('symlink')) {
            try { @symlink($privPath, $currentPrivatePath); if (file_exists($currentPrivatePath)) $linked = true; } catch (Throwable $e) { }
        }
        if (!$linked) { copy($privPath, $currentPrivatePath); @chmod($currentPrivatePath, 0600); }

        saveKeysIndex($index);
        if (function_exists('inc_metric')) inc_metric('jwks_rollbacks_total', 1);
        if (function_exists('audit_log_admin')) audit_log_admin('rollback', ['kid' => $prevKid, 'restored_from' => $old]);
        echo json_encode(['success' => true, 'message' => 'rolled_back', 'kid' => $prevKid, 'restored_from' => $old]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// Load helper library (pure functions)
require_once __DIR__ . '/jwks_lib.php';
// Optional audit + metrics
if (file_exists(__DIR__ . '/admin_audit.php')) require_once __DIR__ . '/admin_audit.php';
if (file_exists(__DIR__ . '/metrics_lib.php')) require_once __DIR__ . '/metrics_lib.php';

/**
 * Generate a new key pair and rotate if needed.
 * POST /api/v1/jwks/rotate (admin only)
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && preg_match('#/api/v1/jwks/rotate$#', $_SERVER['REQUEST_URI'])) {
    // Require admin auth
    if (file_exists(__DIR__ . '/admin.php')) {
        require_once __DIR__ . '/admin.php';
        if (!function_exists('admin_authorize') || !admin_authorize(['rotate'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
    }
    
    try {
        // Generate new keypair
        $config = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];
        
        $res = openssl_pkey_new($config);
        if ($res === false) {
            throw new Exception('Failed to generate key');
        }
        
        openssl_pkey_export($res, $privateKey);
        $details = openssl_pkey_get_details($res);
        $publicKeyPem = $details['key'];
        
        // Generate kid (key ID) based on timestamp + hash
        $kid = 'kid_' . date('YmdHis') . '_' . substr(hash('sha256', $publicKeyPem), 0, 8);

        // Convert to JWK
        $jwk = pemToJwk($publicKeyPem);
        $jwk['kid'] = $kid;

        // Save private key. Allow overriding the storage directory (e.g., a secure mount or KMS sync folder)
        $privateKeyBase = getenv('LICENSE_PRIVATE_KEYS_DIR') ?: $keysDir;
        if (!is_dir($privateKeyBase)) @mkdir($privateKeyBase, 0700, true);
        $privateKeyPath = rtrim($privateKeyBase, '/\\') . '/private_' . $kid . '.pem';
        file_put_contents($privateKeyPath, $privateKey, LOCK_EX);
        @chmod($privateKeyPath, 0600);

        // Save public key
        $publicKeyPath = $keysDir . '/public_' . $kid . '.pem';
        file_put_contents($publicKeyPath, $publicKeyPem, LOCK_EX);

        // Also update the canonical public.pem (symlink or copy)
        $currentPublicPath = $keysDir . '/public.pem';
        @unlink($currentPublicPath);
        $pubLinked = false;
        $isWindows = (strtoupper(substr(PHP_OS,0,3)) === 'WIN');
        if (!$isWindows && function_exists('symlink')) {
            try {
                @symlink($publicKeyPath, $currentPublicPath);
                if (file_exists($currentPublicPath)) $pubLinked = true;
            } catch (Throwable $e) { /* ignore */ }
        }
        if (!$pubLinked) {
            copy($publicKeyPath, $currentPublicPath);
            @chmod($currentPublicPath, 0644);
        }
        
        // Update index + metadata
        $index = getKeysIndex();
        $now = time();
        $grace = intval(getenv('LICENSE_KEY_GRACE_PERIOD_SECONDS') ?: (7 * 24 * 3600));

        // store the jwk for discovery
        $index['keys'][$kid] = $jwk;
        // store metadata separately so jwks remains a valid JWKS response
        if (!isset($index['keys_meta']) || !is_array($index['keys_meta'])) $index['keys_meta'] = [];
        $index['keys_meta'][$kid] = [
            'created_at' => date('c', $now),
            'retire_at' => null,
            'private_key_path' => $privateKeyPath,
            'public_key_path' => $publicKeyPath
        ];

        // If there was a previous current key, set its retire_at to now + grace
        if (!empty($index['current_kid'])) {
            $old = $index['current_kid'];
            if (!isset($index['keys_meta'][$old])) $index['keys_meta'][$old] = ['created_at' => null, 'retire_at' => null];
            $index['keys_meta'][$old]['retire_at'] = date('c', $now + $grace);
        }

        $previous_kid = $index['current_kid'] ?? null;
        $index['rotation_history'][] = [
            'action' => 'rotation',
            'old_kid' => $previous_kid,
            'new_kid' => $kid,
            'timestamp' => date('c'),
            'private_key_path' => $privateKeyPath
        ];

        // Set as current
        $index['current_kid'] = $kid;
        saveKeysIndex($index);

        // Optionally prune expired keys immediately after rotation
        $autoPrune = in_array(strtolower(getenv('LICENSE_ENABLE_AUTO_PRUNE') ?: '0'), ['1','true','yes'], true);
        $pruned = [];
        if ($autoPrune) {
            $pruned = pruneExpiredKeys($index);
        }
        
        // Optionally create a canonical private.pem in the repo for compatibility.
        // In production, prefer the private key to live outside the repository. Set LICENSE_ALLOW_PRIVATE_IN_REPO=1 to override.
        $currentPrivatePath = $keysDir . '/private.pem';
        @unlink($currentPrivatePath);
        $linked = false;
        $isWindows = (strtoupper(substr(PHP_OS,0,3)) === 'WIN');
        $allowInRepo = in_array(strtolower(getenv('LICENSE_ALLOW_PRIVATE_IN_REPO') ?: '0'), ['1','true','yes'], true);
        $privatePathReal = realpath($privateKeyPath) ?: $privateKeyPath;
        $repoReal = realpath(__DIR__);
        $privateInRepo = ($repoReal !== false && strpos($privatePathReal, $repoReal) === 0);
        if ($allowInRepo || $privateInRepo) {
            if (!$isWindows && function_exists('symlink')) {
                try {
                    @symlink($privateKeyPath, $currentPrivatePath);
                    if (file_exists($currentPrivatePath)) $linked = true;
                } catch (Throwable $e) { /* ignore */ }
            }
            if (!$linked) {
                // fallback to copying the private key
                copy($privateKeyPath, $currentPrivatePath);
                @chmod($currentPrivatePath, 0600);
            }
        } else {
            // Skip creating private.pem inside repo for safety in production.
            if (function_exists('log_server')) log_server('jwks: private key stored outside repo at ' . $privateKeyPath);
        }
        
        // metrics + audit
        if (function_exists('inc_metric')) inc_metric('jwks_rotations_total', 1);
        if (function_exists('audit_log_admin')) audit_log_admin('rotate', ['kid' => $kid, 'old_kid' => $previous_kid]);

        echo json_encode([
            'success' => true,
            'message' => 'Key rotated',
            'kid' => $kid,
            'old_kid' => $previous_kid,
            'private_key_path' => $privateKeyPath,
            'pruned' => $pruned
        ]);
        exit;
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

/**
 * GET /api/v1/jwks
 * Returns the JWKS with all currently valid (current + recently rotated) public keys.
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && preg_match('#/api/v1/jwks/?$#', $_SERVER['REQUEST_URI'])) {
    try {
        $index = getKeysIndex();
        $keys = [];
        $now = time();
        // Include only keys that are not yet retired (within grace period)
        foreach ($index['keys'] as $kid => $jwk) {
            $meta = $index['keys_meta'][$kid] ?? null;
            if (!empty($meta['retire_at']) && strtotime($meta['retire_at']) <= $now) {
                // expired/retired - skip
                continue;
            }
            $keys[] = $jwk;
        }
        
        echo json_encode([
            'keys' => $keys
        ]);
        exit;
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

/**
 * GET /api/v1/jwks/status
 * Returns the current key status (admin only).
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && preg_match('#/api/v1/jwks/status$#', $_SERVER['REQUEST_URI'])) {
    if (file_exists(__DIR__ . '/admin.php')) {
        require_once __DIR__ . '/admin.php';
        if (!function_exists('admin_authorize') || !admin_authorize(['status'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
    }
    
    try {
        $index = getKeysIndex();
        if (function_exists('audit_log_admin')) audit_log_admin('status_view', ['current_kid' => $index['current_kid'] ?? null]);
        
        echo json_encode([
            'success' => true,
            'current_kid' => $index['current_kid'],
            'total_keys' => count($index['keys']),
            'keys' => array_map(function($kid, $jwk) use ($index) {
                $meta = $index['keys_meta'][$kid] ?? [];
                return [
                    'kid' => $kid,
                    'alg' => $jwk['alg'],
                    'is_current' => ($kid === $index['current_kid']),
                    'created_at' => $meta['created_at'] ?? null,
                    'retire_at' => $meta['retire_at'] ?? null
                ];
            }, array_keys($index['keys']), array_values($index['keys'])),
            'rotation_history' => array_slice($index['rotation_history'], -10) // Last 10 rotations
        ]);
        exit;
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

/**
 * POST /api/v1/jwks/prune
 * Admin-only: prune expired keys from the index and filesystem
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && preg_match('#/api/v1/jwks/prune$#', $_SERVER['REQUEST_URI'])) {
    if (file_exists(__DIR__ . '/admin.php')) {
        require_once __DIR__ . '/admin.php';
        if (!function_exists('admin_authorize') || !admin_authorize(['prune'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
    }

    try {
        $index = getKeysIndex();
        $removed = pruneExpiredKeys($index);
        if (function_exists('inc_metric')) inc_metric('jwks_prunes_total', 1);
        if (function_exists('audit_log_admin')) audit_log_admin('prune', ['pruned' => $removed]);
        echo json_encode(['success' => true, 'pruned' => $removed]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// No matching endpoint
http_response_code(404);
echo json_encode(['success' => false, 'message' => 'not_found']);
