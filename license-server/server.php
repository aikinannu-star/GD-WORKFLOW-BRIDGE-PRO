<?php
// Minimal license server scaffold for development/testing.
// WARNING: This is a simple example — do NOT use in production without proper auth, storage, rate-limiting.

// Enable CORS for development (allows cross-origin requests from React frontend)
$cors_origin = getenv('CORS_ORIGIN') ?: ($_SERVER['HTTP_ORIGIN'] ?? 'http://localhost:3001');
header('Access-Control-Allow-Origin: ' . $cors_origin);
header('Access-Control-Allow-Credentials: true');
header('Vary: Origin');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header('Content-Type: application/json');
// Load secrets from Vault (optional). Uses VAULT_ADDR, VAULT_TOKEN, VAULT_SECRET_PATH.
if (file_exists(__DIR__ . '/vault.php')) {
    require_once __DIR__ . '/vault.php';
    if (function_exists('vault_load_secrets')) {
        @vault_load_secrets();
    }
}

// Load secrets from AWS Secrets Manager (optional). Uses AWS_SECRET_ID and AWS_REGION.
if (file_exists(__DIR__ . '/aws_secrets.php')) {
    require_once __DIR__ . '/aws_secrets.php';
    if (function_exists('aws_load_secrets')) {
        @aws_load_secrets();
    }
}

// Paths (allow env overrides for secret management and isolation)
$privateKeyPath = getenv('LICENSE_PRIVATE_KEY_PATH') ?: __DIR__ . '/keys/private.pem';
$dataPath = getenv('LICENSE_DATA_PATH') ?: __DIR__ . '/data/licenses.json';
$adminSecretPath = getenv('LICENSE_ADMIN_SECRET_PATH') ?: __DIR__ . '/keys/admin_secret.txt';
// Runtime environment (dev|production)
$licenseEnv = getenv('LICENSE_SERVER_ENV') ?: 'dev';

// Optional admin helper (auth + rate-limiting)
if (file_exists(__DIR__ . '/admin.php')) {
    require_once __DIR__ . '/admin.php';
}
// Optional Redis helper for JTI blacklist
if (file_exists(__DIR__ . '/redis.php')) {
    require_once __DIR__ . '/redis.php';
}
// Optional metrics helper
if (file_exists(__DIR__ . '/metrics_lib.php')) {
    require_once __DIR__ . '/metrics_lib.php';
}

// Optional entitlements helper for plan enforcement
if (file_exists(__DIR__ . '/entitlements.php')) {
    require_once __DIR__ . '/entitlements.php';
}

// Ensure data directory exists
if (!is_dir(dirname($dataPath))) {
    @mkdir(dirname($dataPath), 0755, true);
}

// Initialize licenses file if missing
if (!file_exists($dataPath)) {
    file_put_contents($dataPath, json_encode(new stdClass(), JSON_PRETTY_PRINT));
}
if (!file_exists($privateKeyPath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Private key missing', 'detail' => 'Set LICENSE_PRIVATE_KEY_PATH or place PEM at license-server/keys/private.pem and ensure permissions are secure.']);
    exit;
}

$privateKey = file_get_contents($privateKeyPath);

// Startup validation: enforce private key isolation in production
if (strtolower($licenseEnv) === 'production') {
    $keyReal = realpath($privateKeyPath);
    $repoReal = realpath(__DIR__);
    if ($keyReal !== false && $repoReal !== false && strpos($keyReal, $repoReal) === 0) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'private_key_in_repo', 'detail' => 'In production set LICENSE_PRIVATE_KEY_PATH to a secure path outside the repository.']);
        exit;
    }
}

// Startup validation: ensure client registry does not contain plaintext secrets in production
$clientsFileGlobal = getenv('LICENSE_CLIENTS_FILE') ?: __DIR__ . '/keys/clients.json';
$clientsJsonEnv = getenv('LICENSE_CLIENTS_JSON');
$clientsToCheck = [];
if ($clientsJsonEnv !== false && trim($clientsJsonEnv) !== '') {
    $tmp = json_decode($clientsJsonEnv, true);
    if (is_array($tmp)) $clientsToCheck = $tmp;
} elseif (file_exists($clientsFileGlobal)) {
    $tmp = json_decode(file_get_contents($clientsFileGlobal), true);
    if (is_array($tmp)) $clientsToCheck = $tmp;
}
if (strtolower($licenseEnv) === 'production' && !empty($clientsToCheck)) {
    foreach ($clientsToCheck as $cid => $cinfo) {
        if (is_array($cinfo) && !empty($cinfo['client_secret'])) {
            $allow = getenv('LICENSE_ALLOW_PLAINTEXT_CLIENTS');
            if (!in_array($allow, ['1', 'true', 'yes'], true)) {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'plaintext_client_secrets_detected', 'detail' => "Client '$cid' has a plaintext secret. Use hashed secrets or set LICENSE_ALLOW_PLAINTEXT_CLIENTS=1 for emergency."]);
                exit;
            }
        }
    }
}

$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Lightweight health endpoint
if ($method === 'GET' && ($uri === '/health' || $uri === '/health/')) {
    $db_ok = false;
    // quick DB connectivity check if db helper is present
    if (file_exists(__DIR__ . '/db.php')) {
        try {
            require_once __DIR__ . '/db.php';
            $pdo = get_db_connection();
            if ($pdo) $db_ok = true;
        } catch (Throwable $e) {
            $db_ok = false;
        }
    }

    http_response_code(200);
    echo json_encode([
        'status' => 'ok',
        'service' => 'license-server',
        'env' => $licenseEnv ?? (getenv('LICENSE_SERVER_ENV') ?: 'dev'),
        'db' => $db_ok,
        'time' => gmdate('c'),
    ]);
    exit;
}

// Load user authentication endpoints
if (file_exists(__DIR__ . '/auth.php')) {
    require_once __DIR__ . '/auth.php';
}

function base64UrlEncode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64UrlDecode($input) {
    $remainder = strlen($input) % 4;
    if ($remainder) $input .= str_repeat('=', 4 - $remainder);
    $input = strtr($input, '-_', '+/');
    return base64_decode($input);
}

// Load plan -> features mapping (optional). File format: { "free": {"features":[...]} , "pro": {"features":[...] } }
$plansFile = getenv('LICENSE_PLANS_FILE') ?: __DIR__ . '/data/plans.json';
$plans = [];
if (file_exists($plansFile)) {
    $pj = json_decode(file_get_contents($plansFile), true);
    if (is_array($pj)) $plans = $pj;
}

// Helper to check whether a request should be treated as an admin request
function is_request_admin($input = []) {
    global $adminSecretPath;
    // Prefer admin helper when present
    if (function_exists('admin_is_authorized')) {
        try {
            if (admin_is_authorized($input)) return true;
        } catch (Throwable $e) {
            // ignore
        }
    }

    // Check for admin bearer token
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        if (!empty($auth) && stripos($auth, 'Bearer ') === 0) {
            $token = trim(substr($auth, 7));
            $expected = getenv('LICENSE_ADMIN_TOKEN') ?: getenv('ADMIN_TOKEN') ?: '';
            if (empty($expected) && file_exists(__DIR__ . '/keys/admin_token.txt')) $expected = trim(file_get_contents(__DIR__ . '/keys/admin_token.txt'));
            if (!empty($expected) && hash_equals($expected, $token)) return true;
        }
    }

    // Legacy admin_secret in body
    $secret = is_array($input) && isset($input['admin_secret']) ? trim($input['admin_secret']) : '';
    $expectedSecret = file_exists($adminSecretPath) ? trim(file_get_contents($adminSecretPath)) : '';
    if (!empty($secret) && !empty($expectedSecret) && hash_equals($expectedSecret, $secret)) return true;

    return false;
}

function plan_features($plan, $fallback = ['files_vault','analytics','webhooks']) {
    // Use entitlements helper if available
    if (function_exists('get_plan_features')) {
        $features = get_plan_features($plan);
        return !empty($features) ? $features : $fallback;
    }
    
    // Fallback to legacy plans global
    global $plans;
    if (empty($plan) || !is_array($plans)) return $fallback;
    if (isset($plans[$plan])) {
        $entry = $plans[$plan];
        if (is_array($entry) && isset($entry['features']) && is_array($entry['features'])) return $entry['features'];
        if (is_array($entry)) return $entry;
    }
    return $fallback;
}

// JWKS endpoint (key discovery and rotation)
if (preg_match('#/api/v1/jwks#', $uri)) {
    require_once __DIR__ . '/jwks.php';
    exit;
}

// Metrics endpoint (Prometheus)
if ($uri === '/metrics') {
    require_once __DIR__ . '/metrics.php';
    exit;
}

// Observability dashboard (simple)
if ($uri === '/observability' || $uri === '/observability/') {
    header('Content-Type: text/html');
    echo file_get_contents(__DIR__ . '/observability.html');
    exit;
}

// Admin audit recent (admin-only) - returns last N audit log entries
if ($method === 'GET' && preg_match('#/api/v1/admin/audit/recent$#', $uri)) {
    if (!file_exists(__DIR__ . '/admin.php')) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'admin_helper_missing']);
        exit;
    }
    require_once __DIR__ . '/admin.php';
    if (!function_exists('admin_authorize') || !admin_authorize(['status'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $n = isset($_GET['n']) ? intval($_GET['n']) : 50;
    $file = __DIR__ . '/data/admin_audit.log';
    if (!file_exists($file)) {
        echo json_encode([]);
        exit;
    }
    $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $tail = array_slice($lines, -$n);
    $out = [];
    foreach ($tail as $l) {
        $j = json_decode($l, true);
        if (is_array($j)) $out[] = $j;
    }
    echo json_encode($out);
    exit;
}

// Admin: issue short-lived admin JWTs (admin-only)
if ($method === 'POST' && preg_match('#/api/v1/admin/token$#', $uri)) {
    if (!file_exists(__DIR__ . '/admin.php')) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'admin_helper_missing']);
        exit;
    }
    require_once __DIR__ . '/admin.php';
    // Allow existing admin credentials or token-store to create new admin JWTs
    if (!admin_is_authorized() && !admin_authorize(['admin:issue'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = $_POST;
    $scopes = [];
    if (!empty($input['scopes'])) {
        if (is_array($input['scopes'])) $scopes = $input['scopes']; else $scopes = preg_split('/\s+/', trim($input['scopes']));
    }
    $exp = isset($input['exp_seconds']) ? intval($input['exp_seconds']) : intval(getenv('LICENSE_ADMIN_JWT_TTL') ?: 3600);
    try {
        $token = admin_issue_jwt($scopes, $exp, 'admin');
        $payload = admin_verify_jwt($token);
        if ($payload === false) throw new Exception('failed to verify issued token');
        if (function_exists('audit_log_admin')) audit_log_admin('issue_admin_jwt', ['scopes' => $scopes, 'exp' => $exp, 'jti' => $payload['jti'] ?? null]);
        echo json_encode(['success' => true, 'token' => $token, 'jti' => $payload['jti'] ?? null, 'exp' => $payload['exp'] ?? null]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// Admin: revoke admin JWT by jti or token string
if ($method === 'POST' && preg_match('#/api/v1/admin/token/revoke$#', $uri)) {
    if (!file_exists(__DIR__ . '/admin.php')) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'admin_helper_missing']);
        exit;
    }
    require_once __DIR__ . '/admin.php';
    if (!admin_is_authorized() && !admin_authorize(['admin:revoke'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = $_POST;
    $jti = $input['jti'] ?? null;
    if (empty($jti) && !empty($input['token'])) {
        $tok = $input['token'];
        $pl = admin_verify_jwt($tok);
        if ($pl !== false && !empty($pl['jti'])) $jti = $pl['jti'];
    }
    if (empty($jti)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'jti_or_token_required']);
        exit;
    }
    $exp = isset($input['exp_seconds']) ? intval($input['exp_seconds']) : null;
    $reason = $input['reason'] ?? 'admin_revoke';
    $actor = function_exists('admin_get_actor') ? admin_get_actor() : null;
    admin_blacklist_add($jti, $exp, $reason, $actor);
    if (function_exists('audit_log_admin')) audit_log_admin('revoke_admin_jwt', ['jti' => $jti, 'reason' => $reason, 'actor' => $actor]);
    echo json_encode(['success' => true, 'jti' => $jti]);
    exit;
}

// Metrics history endpoint for observability UI
if ($method === 'GET' && preg_match('#/api/v1/metrics/history$#', $uri)) {
    $n = isset($_GET['n']) ? intval($_GET['n']) : 50;
    $file = __DIR__ . '/data/metrics_history.log';
    if (!file_exists($file)) { echo json_encode([]); exit; }
    $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $tail = array_slice($lines, -$n);
    $out = [];
    foreach ($tail as $l) {
        $j = json_decode($l, true);
        if (is_array($j)) $out[] = $j;
    }
    header('Content-Type: application/json');
    echo json_encode($out);
    exit;
}

// OpenID Connect discovery
if (preg_match('#/\\.well-known/openid-configuration$#', $uri) || preg_match('#/\\.well-known/oauth-authorization-server$#', $uri)) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    if (isset($_SERVER['SERVER_PORT']) && !in_array($_SERVER['SERVER_PORT'], ['80','443'])) {
        if (strpos($host, ':') === false) $host .= ':' . $_SERVER['SERVER_PORT'];
    }
    $base = $scheme . '://' . $host;

    $config = [
        'issuer' => $base,
        'authorization_endpoint' => $base . '/oauth/authorize',
        'token_endpoint' => $base . '/api/v1/token',
        'jwks_uri' => $base . '/api/v1/jwks',
        'userinfo_endpoint' => $base . '/api/v1/userinfo',
        'introspection_endpoint' => $base . '/api/v1/introspect',
        'revocation_endpoint' => $base . '/api/v1/revoke',
        'response_types_supported' => ['code','token','id_token','code token','code id_token','token id_token'],
        'grant_types_supported' => ['authorization_code','implicit','refresh_token','client_credentials','password'],
        'subject_types_supported' => ['public'],
        'id_token_signing_alg_values_supported' => ['RS256'],
        'token_endpoint_auth_methods_supported' => ['client_secret_basic','client_secret_post','none'],
        'scopes_supported' => ['openid','profile','email','offline_access'],
        'claims_supported' => ['sub','iss','aud','exp','iat','jti','features','site'],
        'code_challenge_methods_supported' => ['S256']
    ];

    echo json_encode($config, JSON_PRETTY_PRINT);
    exit;
}

// Token endpoint - minimal support for client_credentials and license grants
if ($method === 'POST' && (preg_match('#/api/v1/token$#', $uri) || preg_match('#/oauth/token$#', $uri))) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = $_POST;

    $grant = isset($input['grant_type']) ? trim($input['grant_type']) : '';
    // optional clients registry (license-server/keys/clients.json)
    $clientsFile = getenv('LICENSE_CLIENTS_FILE') ?: __DIR__ . '/keys/clients.json';
    $clients = [];
    // 1) allow a full clients JSON via env var (useful for containers/secrets)
    $envClientsJson = getenv('LICENSE_CLIENTS_JSON');
    if ($envClientsJson !== false && trim($envClientsJson) !== '') {
        $cjson = json_decode($envClientsJson, true);
        if (is_array($cjson)) $clients = $cjson;
    } else {
        if (file_exists($clientsFile)) {
            $cjson = json_decode(file_get_contents($clientsFile), true);
            if (is_array($cjson)) $clients = $cjson;
        }
    }

    // helper: check per-client env secrets (CLIENT_<ID>_SECRET or CLIENT_<ID>_SECRET_HASH)
    $get_env_client = function(string $id) {
        $norm = strtoupper(preg_replace('/[^A-Z0-9]/', '_', $id));
        $plainKey = 'CLIENT_' . $norm . '_SECRET';
        $hashKey = 'CLIENT_' . $norm . '_SECRET_HASH';
        $plain = getenv($plainKey);
        if ($plain !== false && $plain !== '') return ['secret' => $plain, 'hashed' => false];
        $h = getenv($hashKey);
        if ($h !== false && $h !== '') return ['secret' => $h, 'hashed' => true];
        return null;
    };

    if (empty($grant)) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_request', 'error_description' => 'grant_type required']);
        exit;
    }

    // rate limiting (if available)
    if (function_exists('rate_limit_allow')) {
        $clientIp = function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if (!rate_limit_allow($clientIp)) {
            http_response_code(429);
            echo json_encode(['error' => 'rate_limited']);
            exit;
        }
    }

    // helper: generate jti
    try {
        $jti_raw = random_bytes(16);
    } catch (Throwable $e) {
        $jti_raw = openssl_random_pseudo_bytes(16) ?: uniqid('', true);
    }
    $jti = base64UrlEncode($jti_raw);

    // client_credentials: allow service/admin clients to obtain short-lived tokens
    if ($grant === 'client_credentials') {
        $authorized = false;
        $sub = 'client';

        // Authorization header (Bearer admin token or Basic creds)
        $headers = [];
        if (function_exists('getallheaders')) $headers = getallheaders();
        if (!empty($headers['Authorization'])) {
            $auth = $headers['Authorization'];
            if (stripos($auth, 'Bearer ') === 0) {
                $token = trim(substr($auth, 7));
                // prefer env admin token
                $expected = getenv('LICENSE_ADMIN_TOKEN') ?: getenv('ADMIN_TOKEN');
                if (empty($expected) && file_exists(__DIR__ . '/keys/admin_token.txt')) {
                    $expected = trim(file_get_contents(__DIR__ . '/keys/admin_token.txt'));
                }
                if (!empty($expected) && hash_equals($expected, $token)) {
                    $authorized = true;
                    $sub = 'admin_token';
                }
            }
            if (stripos($auth, 'Basic ') === 0) {
                $b64 = substr($auth, 6);
                $decoded = base64_decode($b64);
                if ($decoded !== false) {
                    $parts = explode(':', $decoded, 2);
                    if (count($parts) === 2) {
                        $user = $parts[0];
                        $pass = $parts[1];
                        if (function_exists('load_admin_credentials')) {
                            $creds = load_admin_credentials();
                            if ($creds && $user === $creds['username'] && password_verify($pass, $creds['password_hash'])) {
                                $authorized = true;
                                $sub = 'admin:' . $user;
                            }
                        }
                        // check clients registry (plaintext secret or password hash)
                        if (!$authorized && !empty($clients[$user])) {
                            $c = $clients[$user];
                            if (!empty($c['client_secret']) && hash_equals($c['client_secret'], $pass)) {
                                $authorized = true;
                                $sub = 'client:' . $user;
                            } elseif (!empty($c['client_secret_hash']) && password_verify($pass, $c['client_secret_hash'])) {
                                $authorized = true;
                                $sub = 'client:' . $user;
                            }
                        }
                        // check per-client env secrets (CLIENT_<ID>_SECRET or _HASH)
                        if (!$authorized) {
                            $envc = $get_env_client($user);
                            if (!empty($envc)) {
                                if (!empty($envc['hashed']) && $envc['hashed'] && password_verify($pass, $envc['secret'])) {
                                    $authorized = true;
                                    $sub = 'client:' . $user;
                                } elseif (empty($envc['hashed']) && hash_equals($envc['secret'], $pass)) {
                                    $authorized = true;
                                    $sub = 'client:' . $user;
                                }
                            }
                        }
                    }
                }
            }
        }

        // POST body client_id + client_secret support
        if (!$authorized) {
            $cid = isset($input['client_id']) ? trim($input['client_id']) : '';
            $csecret = isset($input['client_secret']) ? trim($input['client_secret']) : '';
            if (!empty($cid) && !empty($csecret)) {
                if (!empty($clients[$cid])) {
                    $c = $clients[$cid];
                    if (!empty($c['client_secret']) && hash_equals($c['client_secret'], $csecret)) {
                        $authorized = true;
                        $sub = 'client:' . $cid;
                    } elseif (!empty($c['client_secret_hash']) && password_verify($csecret, $c['client_secret_hash'])) {
                        $authorized = true;
                        $sub = 'client:' . $cid;
                    }
                }
                // check env-based client secrets
                if (!$authorized) {
                    $envc = $get_env_client($cid);
                    if (!empty($envc)) {
                        if (!empty($envc['hashed']) && $envc['hashed'] && password_verify($csecret, $envc['secret'])) {
                            $authorized = true;
                            $sub = 'client:' . $cid;
                        } elseif (empty($envc['hashed']) && hash_equals($envc['secret'], $csecret)) {
                            $authorized = true;
                            $sub = 'client:' . $cid;
                        }
                    }
                }
            }
        }

        if (!$authorized) {
            http_response_code(401);
            echo json_encode(['error' => 'invalid_client']);
            exit;
        }

        $exp = time() + 3600; // 1 hour
        $payload = [
            'iss' => 'gdwb-license-server',
            'sub' => $sub,
            'aud' => 'gd-workflow-bridge-pro',
            'iat' => time(),
            'exp' => $exp,
            'jti' => $jti,
            'scope' => ['admin']
        ];

        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        // attach kid if available from keys index
        $keysIndexFile = __DIR__ . '/keys/keys_index.json';
        if (file_exists($keysIndexFile)) {
            $idx = json_decode(file_get_contents($keysIndexFile), true);
            if (is_array($idx) && !empty($idx['current_kid'])) $header['kid'] = $idx['current_kid'];
        }
        $header_b64 = base64UrlEncode(json_encode($header));
        $payload_b64 = base64UrlEncode(json_encode($payload));
        $signed = $header_b64 . '.' . $payload_b64;

        $signature = '';
        $ok = false;
        // reload private key from disk to support runtime rotation
        $privateKey = @file_get_contents($privateKeyPath);
        $pkey = @openssl_pkey_get_private($privateKey);
        if ($pkey !== false) {
            $ok = openssl_sign($signed, $signature, $pkey, OPENSSL_ALGO_SHA256);
        }
        if (!$ok) {
            http_response_code(500);
            echo json_encode(['error' => 'server_error', 'error_description' => 'signing_failed']);
            exit;
        }
        $token = $signed . '.' . base64UrlEncode($signature);

        echo json_encode(['access_token' => $token, 'token_type' => 'bearer', 'expires_in' => $exp - time()]);
        exit;
    }

    // license/password-like grant: issue license token (backwards compatible with /validate)
    if ($grant === 'license' || $grant === 'password') {
        $license_key = isset($input['license_key']) ? trim($input['license_key']) : '';
        $site = isset($input['site']) ? trim($input['site']) : '';

        if (empty($license_key)) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid_request', 'error_description' => 'license_key required']);
            exit;
        }

        if (!preg_match('/^[A-Z0-9\-]{20,}$/', $license_key)) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid_grant', 'error_description' => 'invalid_format']);
            exit;
        }

        $pdo = null;
        $db_available = false;
        if (file_exists(__DIR__ . '/db.php')) {
            require_once __DIR__ . '/db.php';
            $pdo = get_db_connection();
            if ($pdo) $db_available = true;
        }

        if (stripos($license_key, 'TEST-') === 0) {
            $exp = time() + 30 * 24 * 3600;
        } else {
            $exp = time() + 365 * 24 * 3600;
        }

        $defaultFeatures = ['files_vault', 'analytics', 'webhooks'];
        $features = $defaultFeatures;
        $plan = 'free';
        $planRequested = isset($input['plan']) ? trim($input['plan']) : '';
        $adminRequest = is_request_admin($input);

        if ($db_available) {
            $row = db_get_license($pdo, $license_key);
            if (empty($row)) {
                // If an admin explicitly requested a plan and it exists, honor it when creating
                if ($adminRequest && !empty($planRequested) && isset($plans[$planRequested])) {
                    $plan = $planRequested;
                    $features = plan_features($plan, $defaultFeatures);
                }
                db_create_license($pdo, $license_key, $features, $exp, $plan);
                $row = db_get_license($pdo, $license_key);
            }

            if (!empty($row['revoked_at']) || (isset($row['status']) && $row['status'] === 'revoked')) {
                http_response_code(403);
                echo json_encode(['error' => 'invalid_grant', 'error_description' => 'revoked']);
                exit;
            }

            // If an admin explicitly requested a plan during this validate/token call, override stored plan
            if ($adminRequest && !empty($planRequested) && isset($plans[$planRequested])) {
                $plan = $planRequested;
                $features = plan_features($plan, $defaultFeatures);
            } else {
                // Prefer plan stored in meta, otherwise fall back to stored features
                if (!empty($row['meta'])) {
                    $meta = is_string($row['meta']) ? json_decode($row['meta'], true) : $row['meta'];
                    if (is_array($meta) && !empty($meta['plan'])) {
                        $plan = $meta['plan'];
                        $features = plan_features($plan, $defaultFeatures);
                    }
                }

                if (!isset($features) || empty($features)) {
                    if (!empty($row['features'])) {
                        $f = is_string($row['features']) ? json_decode($row['features'], true) : $row['features'];
                        if (is_array($f) && !empty($f)) $features = $f;
                    }
                }
            }
        } else {
            $licenses = json_decode(file_get_contents($dataPath), true) ?: [];
            if (empty($licenses[$license_key])) {
                if ($adminRequest && !empty($planRequested) && isset($plans[$planRequested])) {
                    $plan = $planRequested;
                    $features = plan_features($plan, $defaultFeatures);
                }
                $licenses[$license_key] = [
                    'key' => $license_key,
                    'created_at' => date('c'),
                    'revoked' => false,
                    'features' => $features,
                    'plan' => $plan,
                ];
                file_put_contents($dataPath, json_encode($licenses, JSON_PRETTY_PRINT));
            }

            // If admin requested a plan change for an existing JSON-backed license, apply and persist it
            if (!empty($licenses[$license_key]) && $adminRequest && !empty($planRequested) && isset($plans[$planRequested])) {
                $plan = $planRequested;
                $features = plan_features($plan, $defaultFeatures);
                $licenses[$license_key]['plan'] = $plan;
                $licenses[$license_key]['features'] = $features;
                file_put_contents($dataPath, json_encode($licenses, JSON_PRETTY_PRINT));
            }

            if (!empty($licenses[$license_key]['revoked'])) {
                http_response_code(403);
                echo json_encode(['error' => 'invalid_grant', 'error_description' => 'revoked']);
                exit;
            }

            if (!empty($licenses[$license_key]['plan'])) {
                $plan = $licenses[$license_key]['plan'];
                $features = plan_features($plan, $defaultFeatures);
            } else {
                $features = $licenses[$license_key]['features'] ?? $features;
            }
        }

        $payload = [
            'iss' => 'gdwb-license-server',
            'sub' => $license_key,
            'aud' => 'gd-workflow-bridge-pro',
            'iat' => time(),
            'exp' => $exp,
            'jti' => $jti,
            'plan' => $plan,
            'tier' => function_exists('get_plan_tier') ? get_plan_tier($plan) : 0,
            'features' => $features,
            'site' => $site
        ];

        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        // attach kid if available
        $keysIndexFile = __DIR__ . '/keys/keys_index.json';
        if (file_exists($keysIndexFile)) {
            $idx = json_decode(file_get_contents($keysIndexFile), true);
            if (is_array($idx) && !empty($idx['current_kid'])) $header['kid'] = $idx['current_kid'];
        }
        $header_b64 = base64UrlEncode(json_encode($header));
        $payload_b64 = base64UrlEncode(json_encode($payload));
        $signed = $header_b64 . '.' . $payload_b64;

        $signature = '';
        $ok = false;
        // reload private key from disk to support runtime rotation
        $privateKey = @file_get_contents($privateKeyPath);
        $pkey = @openssl_pkey_get_private($privateKey);
        if ($pkey !== false) {
            $ok = openssl_sign($signed, $signature, $pkey, OPENSSL_ALGO_SHA256);
        }
        if (!$ok) {
            http_response_code(500);
            echo json_encode(['error' => 'server_error', 'error_description' => 'signing_failed']);
            exit;
        }
        $token = $signed . '.' . base64UrlEncode($signature);

        // Persist activation/jti similar to /validate
        if ($db_available) {
            db_update_license_after_activation($pdo, $license_key, $exp, $features, $plan);
            if (function_exists('db_record_activation')) {
                db_record_activation($pdo, $license_key, $site, $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '', 'issued', $jti);
            }
            if (function_exists('log_server')) log_server('server: token issued for ' . $license_key . ' jti=' . $jti);
        } else {
            if (!isset($licenses[$license_key]['jtis']) || !is_array($licenses[$license_key]['jtis'])) $licenses[$license_key]['jtis'] = [];
            $licenses[$license_key]['jtis'][] = $jti;
            file_put_contents($dataPath, json_encode($licenses, JSON_PRETTY_PRINT));
        }

        echo json_encode(['access_token' => $token, 'token_type' => 'bearer', 'expires_in' => $exp - time()]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'unsupported_grant_type']);
    exit;
}

// Simple router
// Validate / activate license
if ($method === 'POST' && preg_match('#/api/v1/validate$#', $uri)) {
    // Accept JSON or form data
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    $license_key = isset($input['license_key']) ? trim($input['license_key']) : '';
    $site = isset($input['site']) ? trim($input['site']) : '';

    if (empty($license_key)) {
        if (function_exists('inc_metric')) inc_metric('validate_failed_total', 1);
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'license_key required']);
        exit;
    }

    if (!preg_match('/^[A-Z0-9\-]{20,}$/', $license_key)) {
        if (function_exists('inc_metric')) inc_metric('validate_failed_bad_format_total', 1);
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'invalid_format']);
        exit;
    }

    // Attempt to use a configured DB (Postgres/MySQL) when available; otherwise fall back to JSON file store.
    $pdo = null;
    $db_available = false;
    if (file_exists(__DIR__ . '/db.php')) {
        require_once __DIR__ . '/db.php';
        $pdo = get_db_connection();
        if ($pdo) {
            $db_available = true;
            if (function_exists('log_server')) {
                log_server('server: using DB backend for license operations');
            }
        } else {
            if (function_exists('log_server')) {
                log_server('server: DB helper present but no connection established');
            }
        }
    }

    // Determine expiry based on key type (test vs full)
    if (stripos($license_key, 'TEST-') === 0) {
        $exp = time() + 30 * 24 * 3600; // 30 days
    } else {
        $exp = time() + 365 * 24 * 3600; // 1 year
    }

    // Default features and plan handling
    $defaultFeatures = ['files_vault', 'analytics', 'webhooks'];
    $features = $defaultFeatures;
    $plan = 'free';
    $planRequested = isset($input['plan']) ? trim($input['plan']) : '';
    $adminRequest = is_request_admin($input);

    if ($db_available) {
        // Ensure license exists in DB
        $row = db_get_license($pdo, $license_key);
        if (empty($row)) {
            if ($adminRequest && !empty($planRequested) && isset($plans[$planRequested])) {
                $plan = $planRequested;
                $features = plan_features($plan, $defaultFeatures);
            }
            db_create_license($pdo, $license_key, $features, $exp, $plan);
            $row = db_get_license($pdo, $license_key);
        }

        // Check revocation
        if (!empty($row['revoked_at']) || (isset($row['status']) && $row['status'] === 'revoked')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'revoked']);
            exit;
        }

        // If an admin requested a plan, override stored plan for this issuance
        if ($adminRequest && !empty($planRequested) && isset($plans[$planRequested])) {
            $plan = $planRequested;
            $features = plan_features($plan, $defaultFeatures);
        } else {
            // Prefer plan stored in meta, otherwise fall back to stored features
            if (!empty($row['meta'])) {
                $meta = is_string($row['meta']) ? json_decode($row['meta'], true) : $row['meta'];
                if (is_array($meta) && !empty($meta['plan'])) {
                    $plan = $meta['plan'];
                    $features = plan_features($plan, $defaultFeatures);
                }
            }

            if (!isset($features) || empty($features)) {
                if (!empty($row['features'])) {
                    $f = is_string($row['features']) ? json_decode($row['features'], true) : $row['features'];
                    if (is_array($f) && !empty($f)) {
                        $features = $f;
                    }
                }
            }
        }
    } else {
        // JSON file fallback
        $licenses = json_decode(file_get_contents($dataPath), true) ?: [];

        // If license doesn't exist in central DB, create it (activation)
        if (empty($licenses[$license_key])) {
            if ($adminRequest && !empty($planRequested) && isset($plans[$planRequested])) {
                $plan = $planRequested;
                $features = plan_features($plan, $defaultFeatures);
            }
            $licenses[$license_key] = [
                'key' => $license_key,
                'created_at' => date('c'),
                'revoked' => false,
                'features' => $features,
                'plan' => $plan,
            ];
            file_put_contents($dataPath, json_encode($licenses, JSON_PRETTY_PRINT));
        }

        // If admin requested a plan change for an existing JSON-backed license, apply and persist it
        if (!empty($licenses[$license_key]) && $adminRequest && !empty($planRequested) && isset($plans[$planRequested])) {
            $plan = $planRequested;
            $features = plan_features($plan, $defaultFeatures);
            $licenses[$license_key]['plan'] = $plan;
            $licenses[$license_key]['features'] = $features;
            file_put_contents($dataPath, json_encode($licenses, JSON_PRETTY_PRINT));
        }

        // Check revocation
        if (!empty($licenses[$license_key]['revoked'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'revoked']);
            exit;
        }

        if (!empty($licenses[$license_key]['plan'])) {
            $plan = $licenses[$license_key]['plan'];
            $features = plan_features($plan, $defaultFeatures);
        } else {
            $features = $licenses[$license_key]['features'] ?? $features;
        }
    }

    // Generate a JTI for this token
    try {
        $jti_raw = random_bytes(16);
    } catch (Throwable $e) {
        $jti_raw = openssl_random_pseudo_bytes(16) ?: uniqid('', true);
    }
    $jti = base64UrlEncode($jti_raw);

    $payload = [
        'iss' => 'gdwb-license-server',
        'sub' => $license_key,
        'aud' => 'gd-workflow-bridge-pro',
        'iat' => time(),
        'exp' => $exp,
        'jti' => $jti,
        'features' => $features,
        'site' => $site,
    ];

    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    // attach kid if available
    $keysIndexFile = __DIR__ . '/keys/keys_index.json';
    if (file_exists($keysIndexFile)) {
        $idx = json_decode(file_get_contents($keysIndexFile), true);
        if (is_array($idx) && !empty($idx['current_kid'])) $header['kid'] = $idx['current_kid'];
    }
    $header_b64 = base64UrlEncode(json_encode($header));
    $payload_b64 = base64UrlEncode(json_encode($payload));
    $signed = $header_b64 . '.' . $payload_b64;

    $signature = '';
    $ok = false;
    $pkey = @openssl_pkey_get_private($privateKey);
    if ($pkey !== false) {
        $ok = openssl_sign($signed, $signature, $pkey, OPENSSL_ALGO_SHA256);
    }
    if ($ok) {
        $token = $signed . '.' . base64UrlEncode($signature);
    } else {
        if (function_exists('inc_metric')) inc_metric('validate_signing_failed_total', 1);
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'signing_failed']);
        exit;
    }

    // Persist license state back to DB or file depending on configuration
    if ($db_available) {
        db_update_license_after_activation($pdo, $license_key, $exp, $features, $plan);
        // record activation event and jti
        if (function_exists('db_record_activation')) {
            db_record_activation($pdo, $license_key, $site, $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '', 'issued', $jti);
        }
        if (function_exists('log_server')) log_server('server: updated license in DB: ' . $license_key . ' jti=' . $jti);
    } else {
        // store jti in JSON fallback
        if (!isset($licenses[$license_key]['jtis']) || !is_array($licenses[$license_key]['jtis'])) $licenses[$license_key]['jtis'] = [];
        $licenses[$license_key]['jtis'][] = $jti;
        file_put_contents($dataPath, json_encode($licenses, JSON_PRETTY_PRINT));
    }
    if (function_exists('inc_metric')) inc_metric('validate_issued_total', 1);
    echo json_encode(['success' => true, 'token' => $token, 'exp' => $exp]);
    exit;
}

// Purchase endpoint: handle payment orchestration (admin or simulate for dev)
if ($method === 'POST' && preg_match('#/api/v1/purchase$#', $uri)) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = $_POST;

    $plan = isset($input['plan']) ? trim($input['plan']) : 'pro';
    $site = isset($input['site']) ? trim($input['site']) : '';
    $simulate = false;
    if (!empty($input['simulate'])) $simulate = in_array($input['simulate'], ['1','true',true], true);
    if (in_array(strtolower(getenv('LICENSE_PURCHASE_SIMULATE') ?: ''), ['1','true','yes'], true)) $simulate = true;

    // Price mapping (cents)
    $prices = ['pro' => 2999, 'enterprise' => 19999, 'free' => 0];
    $price_cents = $prices[$plan] ?? ($prices['pro']);

    // Only allow non-payment issuance when admin or simulation mode
    if (!is_request_admin($input) && !$simulate) {
        http_response_code(402);
        echo json_encode(['success' => false, 'message' => 'payment_required', 'detail' => 'Provide payment confirmation or call with admin credentials, or enable simulate=true for testing.']);
        exit;
    }

    // Generate a license key
    try {
        $rand = bin2hex(random_bytes(8));
    } catch (Throwable $e) {
        $rand = bin2hex(openssl_random_pseudo_bytes(8));
    }
    $license_key = 'GDWB-' . strtoupper($rand);

    // Expiry for purchased licenses: 1 year by default
    $exp = time() + 365 * 24 * 3600;

    $defaultFeatures = ['files_vault', 'analytics', 'webhooks'];
    $features = plan_features($plan, $defaultFeatures);

    // Persist license (DB preferred, else JSON file)
    $pdo = null;
    $db_available = false;
    if (file_exists(__DIR__ . '/db.php')) {
        require_once __DIR__ . '/db.php';
        $pdo = get_db_connection();
        if ($pdo) $db_available = true;
    }

    if ($db_available) {
        db_create_license($pdo, $license_key, $features, $exp, $plan);
    } else {
        $licenses = json_decode(file_get_contents($dataPath), true) ?: [];
        $licenses[$license_key] = [
            'key' => $license_key,
            'created_at' => date('c'),
            'revoked' => false,
            'features' => $features,
            'plan' => $plan,
        ];
        file_put_contents($dataPath, json_encode($licenses, JSON_PRETTY_PRINT));
    }

    // Generate token (JWT) for the new license
    try {
        $jti_raw = random_bytes(16);
    } catch (Throwable $e) {
        $jti_raw = openssl_random_pseudo_bytes(16) ?: uniqid('', true);
    }
    $jti = base64UrlEncode($jti_raw);

    $payload = [
        'iss' => 'gdwb-license-server',
        'sub' => $license_key,
        'aud' => 'gd-workflow-bridge-pro',
        'iat' => time(),
        'exp' => $exp,
        'jti' => $jti,
        'features' => $features,
        'site' => $site,
    ];

    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $keysIndexFile = __DIR__ . '/keys/keys_index.json';
    if (file_exists($keysIndexFile)) {
        $idx = json_decode(file_get_contents($keysIndexFile), true);
        if (is_array($idx) && !empty($idx['current_kid'])) $header['kid'] = $idx['current_kid'];
    }
    $header_b64 = base64UrlEncode(json_encode($header));
    $payload_b64 = base64UrlEncode(json_encode($payload));
    $signed = $header_b64 . '.' . $payload_b64;

    $signature = '';
    $ok = false;
    $pkey = @openssl_pkey_get_private($privateKey);
    if ($pkey !== false) {
        $ok = openssl_sign($signed, $signature, $pkey, OPENSSL_ALGO_SHA256);
    }
    if (!$ok) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'signing_failed']);
        exit;
    }
    $token = $signed . '.' . base64UrlEncode($signature);

    // Persist activation record / jti
    if ($db_available) {
        db_update_license_after_activation($pdo, $license_key, $exp, $features, $plan);
        if (function_exists('db_record_activation')) {
            db_record_activation($pdo, $license_key, $site, $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '', 'purchased', $jti);
        }
    } else {
        $licenses = json_decode(file_get_contents($dataPath), true) ?: [];
        if (!isset($licenses[$license_key]['jtis']) || !is_array($licenses[$license_key]['jtis'])) $licenses[$license_key]['jtis'] = [];
        $licenses[$license_key]['jtis'][] = $jti;
        file_put_contents($dataPath, json_encode($licenses, JSON_PRETTY_PRINT));
    }

    if (function_exists('inc_metric')) inc_metric('purchase_issued_total', 1);
    echo json_encode(['success' => true, 'license_key' => $license_key, 'token' => $token, 'exp' => $exp, 'price_cents' => $price_cents, 'currency' => 'USD']);
    exit;
}
// Revoke license (admin only) - POST { license_key }
// Supports: Bearer token (Authorization: Bearer <token>), Basic auth (Authorization: Basic ...),
// X-Admin-Token header, or legacy admin_secret body param (fallback).
if ($method === 'POST' && preg_match('#/api/v1/revoke$#', $uri)) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    $license_key = isset($input['license_key']) ? trim($input['license_key']) : '';

    if (empty($license_key)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'license_key required']);
        exit;
    }

    // Rate limiting per-client IP
    if (function_exists('rate_limit_allow')) {
        $clientIp = function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if (!rate_limit_allow($clientIp)) {
            if (function_exists('log_server')) log_server('server: rate-limited revoke request from ' . $clientIp);
            http_response_code(429);
            echo json_encode(['success' => false, 'message' => 'rate_limited']);
            exit;
        }
    }

    // Authorization
    $authorized = false;
    if (function_exists('admin_is_authorized')) {
        $authorized = admin_is_authorized($input);
    } else {
        // Legacy: check admin_secret in file
        $secret = isset($input['admin_secret']) ? trim($input['admin_secret']) : '';
        $expected = file_exists($adminSecretPath) ? trim(file_get_contents($adminSecretPath)) : '';
        if (!empty($expected) && !empty($secret) && hash_equals($expected, $secret)) {
            $authorized = true;
        }
    }

    if (!$authorized) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'forbidden']);
        exit;
    }

    // Try DB first
    $pdo = null;
    if (file_exists(__DIR__ . '/db.php')) {
        require_once __DIR__ . '/db.php';
        $pdo = get_db_connection();
    }

    if ($pdo) {
        $row = db_get_license($pdo, $license_key);
        if (empty($row)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'not_found']);
            exit;
        }

        $ok = db_revoke_license($pdo, $license_key);
        // blacklist known JTIs for this license (use Redis when available)
        $expires_ts = null;
        if (!empty($row['expires_at'])) {
            $expires_ts = strtotime($row['expires_at']);
        }
        $ttl = ($expires_ts && $expires_ts > time()) ? max(1, $expires_ts - time()) : (365 * 24 * 3600);

        if (function_exists('db_get_jtis_for_license')) {
            $jtis = db_get_jtis_for_license($pdo, $license_key);
            foreach ($jtis as $j) {
                if (!empty($j) && function_exists('redis_blacklist_add')) {
                    redis_blacklist_add($j, $ttl);
                    if (function_exists('log_server')) log_server('server: blacklisted jti=' . $j . ' ttl=' . $ttl);
                }
            }
        }

        if (function_exists('db_record_activation')) {
            db_record_activation($pdo, $license_key, null, $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '', 'revoked', null);
        }

        if (function_exists('log_server')) {
            log_server('server: revoked license in DB: ' . $license_key . ' ok=' . ($ok ? '1' : '0'));
        }

        if (function_exists('inc_metric')) inc_metric('revoke_success_total', 1);
        echo json_encode(['success' => true, 'message' => 'revoked']);
        exit;
    }

    // Fallback to file store
    $licenses = json_decode(file_get_contents($dataPath), true) ?: [];
    if (empty($licenses[$license_key])) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'not_found']);
        exit;
    }

    $licenses[$license_key]['revoked'] = true;
    // Blacklist JTIs from the JSON fallback store
    if (!empty($licenses[$license_key]['jtis']) && is_array($licenses[$license_key]['jtis'])) {
        $ttl = 365 * 24 * 3600;
        foreach ($licenses[$license_key]['jtis'] as $j) {
            if (!empty($j) && function_exists('redis_blacklist_add')) {
                redis_blacklist_add($j, $ttl);
                if (function_exists('log_server')) log_server('server: blacklisted jti (json): ' . $j);
            }
        }
    }

    file_put_contents($dataPath, json_encode($licenses, JSON_PRETTY_PRINT));

    if (function_exists('inc_metric')) inc_metric('revoke_success_total', 1);
    echo json_encode(['success' => true, 'message' => 'revoked']);
    exit;
}

// Expose a simple GET endpoint to retrieve the license payload for the
// caller's license token. This mirrors the introspect behavior but is
// provided as a convenient GET for clients that prefer Authorization header.
if ($method === 'GET' && preg_match('#/api/v1/license/me$#', $uri)) {
    $headers = [];
    if (function_exists('getallheaders')) $headers = getallheaders();
    $token = '';
    if (!empty($headers['Authorization'])) {
        $a = $headers['Authorization'];
        if (stripos($a, 'Bearer ') === 0) $token = trim(substr($a, 7));
    }
    if (empty($token)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Authorization required']);
        exit;
    }

    // Call the existing introspect handler internally to verify and decode
    // the token. Use the local service base to avoid duplicating verification logic.
    $introspectUrl = 'http://127.0.0.1:8001/api/v1/introspect';
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $introspectUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['token' => $token]));
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false) {
            http_response_code(502);
            echo json_encode(['success' => false, 'message' => 'introspect_failed']);
            exit;
        }
        http_response_code($code ?: 200);
        header('Content-Type: application/json');
        echo $resp;
        exit;
    }

    // Fallback: proxy via stream context
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => json_encode(['token' => $token]), 'timeout' => 5, 'ignore_errors' => true]]);
    $resp = @file_get_contents($introspectUrl, false, $ctx);
    if ($resp === false) {
        http_response_code(502);
        echo json_encode(['success' => false, 'message' => 'introspect_failed']);
        exit;
    }
    // Forward the response from /introspect
    header('Content-Type: application/json');
    echo $resp;
    exit;
}

// Introspect token - POST { token } or Authorization: Bearer <token>
if ($method === 'POST' && preg_match('#/api/v1/introspect$#', $uri)) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = $_POST;

    $token = '';
    if (!empty($input['token'])) {
        $token = trim($input['token']);
    } else {
        $headers = [];
        if (function_exists('getallheaders')) $headers = getallheaders();
        if (!empty($headers['Authorization'])) {
            $a = $headers['Authorization'];
            if (stripos($a, 'Bearer ') === 0) $token = trim(substr($a, 7));
        }
    }

    if (empty($token)) {
        if (function_exists('inc_metric')) inc_metric('introspect_failed_total', 1);
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'token required']);
        exit;
    }

    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        if (function_exists('inc_metric')) inc_metric('introspect_failed_malformed_total', 1);
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'invalid_token']);
        exit;
    }

    $signed = $parts[0] . '.' . $parts[1];
    $signature = base64UrlDecode($parts[2]);
    $payload_json = base64UrlDecode($parts[1]);
    $payload = json_decode($payload_json, true);

    // Get public key (prefer explicit public.pem)
    $publicKeyPath = __DIR__ . '/keys/public.pem';
    $publicKey = '';
    if (file_exists($publicKeyPath)) {
        $publicKey = file_get_contents($publicKeyPath);
    } else {
        // reload private key to ensure public key details reflect rotated keys if any
        $privateKey = @file_get_contents($privateKeyPath);
        $p = @openssl_pkey_get_private($privateKey);
        if ($p !== false) {
            $det = openssl_pkey_get_details($p);
            if (!empty($det['key'])) $publicKey = $det['key'];
        }
    }

    if (empty($publicKey)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'no_public_key']);
        exit;
    }

    $verified = false;
    // First try keys from the keys index (if present) to support rotated keys explicitly
    $keysIndexFile = __DIR__ . '/keys/keys_index.json';
    if (file_exists($keysIndexFile)) {
        $idx = json_decode(file_get_contents($keysIndexFile), true) ?: [];
        if (!empty($idx['keys']) && is_array($idx['keys'])) {
            $now = time();
            // Prefer the kid from the token header if present
            $header_json = base64UrlDecode($parts[0]);
            $header = json_decode($header_json, true) ?: [];
            $preferredKid = $header['kid'] ?? null;

            if ($preferredKid && !empty($idx['keys'][$preferredKid])) {
                $meta = $idx['keys_meta'][$preferredKid] ?? null;
                if (empty($meta['retire_at']) || strtotime($meta['retire_at']) > $now) {
                    $candidate = __DIR__ . '/keys/public_' . $preferredKid . '.pem';
                    if (file_exists($candidate)) {
                        $candKey = file_get_contents($candidate);
                        if (openssl_verify($signed, $signature, $candKey, OPENSSL_ALGO_SHA256) === 1) {
                            $verified = true;
                        }
                    }
                }
            }

            // Fall back to checking all non-retired keys
            if (!$verified) {
                foreach (array_keys($idx['keys']) as $kid) {
                    $meta = $idx['keys_meta'][$kid] ?? null;
                    if (!empty($meta['retire_at']) && strtotime($meta['retire_at']) <= $now) continue;
                    $candidate = __DIR__ . '/keys/public_' . $kid . '.pem';
                    if (file_exists($candidate)) {
                        $candKey = file_get_contents($candidate);
                        if (openssl_verify($signed, $signature, $candKey, OPENSSL_ALGO_SHA256) === 1) {
                            $verified = true;
                            break;
                        }
                    }
                }
            }
        }
    }
    // If not verified yet, try the canonical public.pem (legacy)
    if (!$verified && !empty($publicKey)) {
        $verified = (openssl_verify($signed, $signature, $publicKey, OPENSSL_ALGO_SHA256) === 1);
    }
    if (!$verified) {
        if (function_exists('inc_metric')) inc_metric('introspect_failed_signature_total', 1);
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'invalid_signature']);
        exit;
    }

    $exp = isset($payload['exp']) ? (int)$payload['exp'] : 0;
    if ($exp && $exp < time()) {
        if (function_exists('inc_metric')) inc_metric('introspect_failed_expired_total', 1);
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'expired']);
        exit;
    }

    // Check jti blacklist
    if (!empty($payload['jti']) && function_exists('redis_blacklist_check') && redis_blacklist_check($payload['jti'])) {
        if (function_exists('inc_metric')) inc_metric('introspect_failed_revoked_total', 1);
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'revoked_jti']);
        exit;
    }

    // Check license status in DB (if available)
    if (file_exists(__DIR__ . '/db.php')) {
        require_once __DIR__ . '/db.php';
        $pdo = get_db_connection();
        if ($pdo && !empty($payload['sub'])) {
            $row = db_get_license($pdo, $payload['sub']);
            if (!empty($row) && (!empty($row['revoked_at']) || (isset($row['status']) && $row['status'] === 'revoked'))) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'revoked_license']);
                exit;
            }
        }
    }

    if (function_exists('inc_metric')) inc_metric('introspect_success_total', 1);
    echo json_encode(['success' => true, 'payload' => $payload]);
    exit;
}

http_response_code(404);
echo json_encode(['success' => false, 'message' => 'not_found']);
