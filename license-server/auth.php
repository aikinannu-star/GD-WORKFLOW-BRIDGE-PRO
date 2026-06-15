<?php
// User authentication endpoints for the license server
// Provides registration, login, and user info endpoints

// User database file path
// Prefer DB storage if LICENSE_DB_DSN is set; otherwise fall back to file storage
$usersDataPath = getenv('LICENSE_USERS_DATA_PATH') ?: __DIR__ . '/data/users.json';
$pdo = null;
$licenseDsn = getenv('LICENSE_DB_DSN') ?: '';
if (!empty($licenseDsn)) {
    $dbUser = getenv('LICENSE_DB_USER') ?: null;
    $dbPass = getenv('LICENSE_DB_PASSWORD') ?: null;
    try {
        $pdo = new PDO($licenseDsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        // Ensure users table exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            email TEXT PRIMARY KEY,
            password_hash TEXT NOT NULL,
            created_at TIMESTAMP WITH TIME ZONE,
            updated_at TIMESTAMP WITH TIME ZONE
        )");
    } catch (Throwable $e) {
        error_log('LICENSE DB connect error: ' . $e->getMessage());
        $pdo = null;
    }
}

// Ensure users data directory exists
if (!is_dir(dirname($usersDataPath))) {
    @mkdir(dirname($usersDataPath), 0755, true);
}

// Initialize users file if missing
if (!file_exists($usersDataPath)) {
    file_put_contents($usersDataPath, json_encode(new stdClass(), JSON_PRETTY_PRINT));
}

// JWT verification helper (uses keys/public_*.pem or keys/public.pem)
if (file_exists(__DIR__ . '/jwt_verify.php')) require_once __DIR__ . '/jwt_verify.php';

// Helper: Get all users
function get_users_data() {
    global $usersDataPath, $pdo;
    if ($pdo) {
        $out = [];
        $stmt = $pdo->query('SELECT email, password_hash, created_at, updated_at FROM users');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[$row['email']] = [
                'email' => $row['email'],
                'password_hash' => $row['password_hash'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ];
        }
        return $out;
    }
    $json = @file_get_contents($usersDataPath);
    $data = $json ? json_decode($json, true) : [];
    return is_array($data) ? $data : [];
}

// Helper: Save users data
function save_users_data($data) {
    global $usersDataPath, $pdo;
    if ($pdo) {
        // Use upserts instead of truncating table to avoid data loss
        $driver = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) ?: '');
        if ($driver === 'pgsql') {
            $sql = 'INSERT INTO users (email, password_hash, created_at, updated_at) VALUES (:email, :password_hash, :created_at, :updated_at) ON CONFLICT (email) DO UPDATE SET password_hash = EXCLUDED.password_hash, updated_at = EXCLUDED.updated_at';
        } elseif ($driver === 'mysql') {
            $sql = 'INSERT INTO users (email, password_hash, created_at, updated_at) VALUES (:email, :password_hash, :created_at, :updated_at) ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), updated_at = VALUES(updated_at)';
        } else {
            $sql = 'INSERT INTO users (email, password_hash, created_at, updated_at) VALUES (:email, :password_hash, :created_at, :updated_at)';
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare($sql);
            foreach ($data as $email => $user) {
                $stmt->execute([
                    ':email' => $user['email'] ?? $email,
                    ':password_hash' => $user['password_hash'] ?? '',
                    ':created_at' => $user['created_at'] ?? null,
                    ':updated_at' => $user['updated_at'] ?? null,
                ]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return;
    }
    file_put_contents($usersDataPath, json_encode($data, JSON_PRETTY_PRINT));
}

// Helper: Get user by email
function get_user_by_email($email) {
    global $pdo;
    if ($pdo) {
        $stmt = $pdo->prepare('SELECT email, password_hash, created_at, updated_at FROM users WHERE email = :email');
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    $users = get_users_data();
    return isset($users[$email]) ? $users[$email] : null;
}

// Helper: Create new user
function create_user($email, $password) {
    global $pdo;
    $now = date('c');
    // Prefer Argon2id when available, fall back to bcrypt
    $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    $hash = password_hash($password, $algo);
    if ($pdo) {
        $stmt = $pdo->prepare('INSERT INTO users(email, password_hash, created_at, updated_at) VALUES(:email, :password_hash, :created_at, :updated_at)');
        $stmt->execute([':email' => $email, ':password_hash' => $hash, ':created_at' => $now, ':updated_at' => $now]);
        return ['email' => $email, 'password_hash' => $hash, 'created_at' => $now, 'updated_at' => $now];
    }
    $users = get_users_data();
    $users[$email] = [
        'email' => $email,
        'password_hash' => $hash,
        'created_at' => $now,
        'updated_at' => $now,
    ];
    save_users_data($users);
    return $users[$email];
}

// Helper: Verify password
function verify_user_password($email, $password) {
    $user = get_user_by_email($email);
    if (!$user) return false;
    return password_verify($password, $user['password_hash']);
}

// Helper: Update password
function update_user_password($email, $newPassword) {
    global $pdo;
    $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    $newHash = password_hash($newPassword, $algo);
    $now = date('c');
    if ($pdo) {
        $stmt = $pdo->prepare('UPDATE users SET password_hash = :hash, updated_at = :updated WHERE email = :email');
        $stmt->execute([':hash' => $newHash, ':updated' => $now, ':email' => $email]);
        return $stmt->rowCount() > 0;
    }
    $users = get_users_data();
    if (!isset($users[$email])) return false;
    $users[$email]['password_hash'] = $newHash;
    $users[$email]['updated_at'] = $now;
    save_users_data($users);
    return true;
}

// User registration endpoint: POST /api/v1/auth/register
if ($method === 'POST' && preg_match('#/api/v1/auth/register$#', $uri)) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    $email = isset($input['email']) ? trim($input['email']) : '';
    $password = isset($input['password']) ? $input['password'] : '';

    // Validate input
    if (empty($email)) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_request', 'error_description' => 'email required']);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_request', 'error_description' => 'invalid email format']);
        exit;
    }

    if (empty($password) || strlen($password) < 6) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_request', 'error_description' => 'password must be at least 6 characters']);
        exit;
    }

    // Check if user already exists
    $existing = get_user_by_email($email);
    if ($existing) {
        http_response_code(409);
        echo json_encode(['error' => 'user_exists', 'error_description' => 'Email already registered']);
        exit;
    }

    // Create new user
    $user = create_user($email, $password);

    // Issue JWT token
    try {
        $jti_raw = random_bytes(16);
    } catch (Throwable $e) {
        $jti_raw = openssl_random_pseudo_bytes(16) ?: uniqid('', true);
    }
    $jti = base64UrlEncode($jti_raw);

    $exp = time() + 86400 * 30; // 30 days
    $payload = [
        'iss' => 'gdwb-license-server',
        'sub' => $email,
        'aud' => 'gd-workflow-bridge-pro',
        'type' => 'user',
        'iat' => time(),
        'exp' => $exp,
        'jti' => $jti,
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
    $pkey = @openssl_pkey_get_private($privateKey);
    if ($pkey !== false) {
        openssl_sign($signed, $signature, $pkey, OPENSSL_ALGO_SHA256);
    }
    
    if (empty($signature)) {
        http_response_code(500);
        echo json_encode(['error' => 'server_error', 'error_description' => 'token generation failed']);
        exit;
    }

    $token = $signed . '.' . base64UrlEncode($signature);

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'access_token' => $token,
        'token_type' => 'bearer',
        'expires_in' => $exp - time(),
        'user' => [
            'email' => $user['email'],
            'created_at' => $user['created_at'],
        ]
    ]);
    exit;
}

// User login endpoint: POST /api/v1/auth/login or POST /api/v1/token with grant_type=password
if ($method === 'POST' && (preg_match('#/api/v1/auth/login$#', $uri) || 
    (preg_match('#/api/v1/token$#', $uri) && isset($_POST['grant_type']) && $_POST['grant_type'] === 'password'))) {
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    // Support both 'email' and 'username' parameters
    $email = isset($input['email']) ? trim($input['email']) : (isset($input['username']) ? trim($input['username']) : '');
    $password = isset($input['password']) ? $input['password'] : '';

    if (empty($email)) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_request', 'error_description' => 'email/username required']);
        exit;
    }

    if (empty($password)) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_request', 'error_description' => 'password required']);
        exit;
    }

    // Verify credentials
    if (!verify_user_password($email, $password)) {
        http_response_code(401);
        echo json_encode(['error' => 'invalid_grant', 'error_description' => 'Invalid credentials']);
        exit;
    }

    // Get user
    $user = get_user_by_email($email);
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'invalid_grant', 'error_description' => 'User not found']);
        exit;
    }

    // Issue JWT token
    try {
        $jti_raw = random_bytes(16);
    } catch (Throwable $e) {
        $jti_raw = openssl_random_pseudo_bytes(16) ?: uniqid('', true);
    }
    $jti = base64UrlEncode($jti_raw);

    $exp = time() + 86400 * 30; // 30 days
    $payload = [
        'iss' => 'gdwb-license-server',
        'sub' => $email,
        'aud' => 'gd-workflow-bridge-pro',
        'type' => 'user',
        'iat' => time(),
        'exp' => $exp,
        'jti' => $jti,
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
    $pkey = @openssl_pkey_get_private($privateKey);
    if ($pkey !== false) {
        openssl_sign($signed, $signature, $pkey, OPENSSL_ALGO_SHA256);
    }
    
    if (empty($signature)) {
        http_response_code(500);
        echo json_encode(['error' => 'server_error', 'error_description' => 'token generation failed']);
        exit;
    }

    $token = $signed . '.' . base64UrlEncode($signature);

    echo json_encode([
        'success' => true,
        'access_token' => $token,
        'token_type' => 'bearer',
        'expires_in' => $exp - time(),
        'user' => [
            'email' => $user['email'],
            'created_at' => $user['created_at'],
        ]
    ]);
    exit;
}

// User info endpoint: GET /api/v1/userinfo
if ($method === 'GET' && preg_match('#/api/v1/userinfo$#', $uri)) {
    $auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (empty($auth_header) || stripos($auth_header, 'Bearer ') !== 0) {
        http_response_code(401);
        echo json_encode(['error' => 'unauthorized', 'error_description' => 'Missing or invalid authorization header']);
        exit;
    }

    $token = trim(substr($auth_header, 7));
    if (!function_exists('verify_token_signature')) {
        // Fallback to simple parsing if helper missing
        http_response_code(500);
        echo json_encode(['error' => 'server_error', 'error_description' => 'JWT helper missing']);
        exit;
    }

    $res = verify_token_signature($token);
    if (empty($res['verified']) || empty($res['payload']) || !is_array($res['payload'])) {
        http_response_code(401);
        echo json_encode(['error' => 'invalid_token', 'error_description' => 'Invalid or unverified token']);
        exit;
    }
    $payload = $res['payload'];

    if (isset($payload['exp']) && $payload['exp'] < time()) {
        http_response_code(401);
        echo json_encode(['error' => 'token_expired', 'error_description' => 'Token has expired']);
        exit;
    }

    if (($payload['type'] ?? '') !== 'user') {
        http_response_code(403);
        echo json_encode(['error' => 'invalid_token', 'error_description' => 'Not a user token']);
        exit;
    }

    // Check JTI blacklist if available
    if (!empty($payload['jti'])) {
        if (function_exists('redis_blacklist_check') && redis_blacklist_check($payload['jti'])) {
            http_response_code(403);
            echo json_encode(['error' => 'revoked', 'error_description' => 'Token has been revoked']);
            exit;
        }
        if (function_exists('admin_blacklist_check') && admin_blacklist_check($payload['jti'])) {
            http_response_code(403);
            echo json_encode(['error' => 'revoked', 'error_description' => 'Token has been revoked']);
            exit;
        }
    }

    $email = $payload['sub'] ?? '';
    $user = get_user_by_email($email);
    if (!$user) {
        http_response_code(404);
        echo json_encode(['error' => 'user_not_found', 'error_description' => 'User not found']);
        exit;
    }

    echo json_encode([
        'sub' => $user['email'],
        'email' => $user['email'],
        'created_at' => $user['created_at'],
        'updated_at' => $user['updated_at'],
    ]);
    exit;
}

// Change password endpoint: POST /api/v1/auth/change-password
if ($method === 'POST' && preg_match('#/api/v1/auth/change-password$#', $uri)) {
    $auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (empty($auth_header) || stripos($auth_header, 'Bearer ') !== 0) {
        http_response_code(401);
        echo json_encode(['error' => 'unauthorized', 'error_description' => 'Missing authorization header']);
        exit;
    }

    $token = trim(substr($auth_header, 7));
    if (!function_exists('verify_token_signature')) {
        http_response_code(500);
        echo json_encode(['error' => 'server_error', 'error_description' => 'JWT helper missing']);
        exit;
    }

    $res = verify_token_signature($token);
    if (empty($res['verified']) || empty($res['payload']) || !is_array($res['payload'])) {
        http_response_code(401);
        echo json_encode(['error' => 'invalid_token']);
        exit;
    }

    $payload = $res['payload'];
    if (($payload['type'] ?? '') !== 'user' || (isset($payload['exp']) && $payload['exp'] < time())) {
        http_response_code(401);
        echo json_encode(['error' => 'invalid_token']);
        exit;
    }

    // Check JTI revocation
    if (!empty($payload['jti'])) {
        if (function_exists('redis_blacklist_check') && redis_blacklist_check($payload['jti'])) {
            http_response_code(403);
            echo json_encode(['error' => 'revoked']);
            exit;
        }
        if (function_exists('admin_blacklist_check') && admin_blacklist_check($payload['jti'])) {
            http_response_code(403);
            echo json_encode(['error' => 'revoked']);
            exit;
        }
    }

    $email = $payload['sub'] ?? '';

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    $old_password = isset($input['old_password']) ? $input['old_password'] : '';
    $new_password = isset($input['new_password']) ? $input['new_password'] : '';

    if (empty($old_password) || empty($new_password)) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_request', 'error_description' => 'old_password and new_password required']);
        exit;
    }

    if (strlen($new_password) < 6) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_request', 'error_description' => 'new_password must be at least 6 characters']);
        exit;
    }

    if (!verify_user_password($email, $old_password)) {
        http_response_code(401);
        echo json_encode(['error' => 'invalid_grant', 'error_description' => 'Invalid current password']);
        exit;
    }

    if (!update_user_password($email, $new_password)) {
        http_response_code(500);
        echo json_encode(['error' => 'server_error', 'error_description' => 'Failed to update password']);
        exit;
    }

    echo json_encode(['success' => true, 'message' => 'Password updated successfully']);
    exit;
}

// Password reset request endpoint: POST /api/v1/auth/reset-password-request
if ($method === 'POST' && preg_match('#/api/v1/auth/reset-password-request$#', $uri)) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    $email = isset($input['email']) ? trim($input['email']) : '';

    if (empty($email)) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_request', 'error_description' => 'email required']);
        exit;
    }

    // Always return success for security (don't reveal if user exists)
    echo json_encode([
        'success' => true,
        'message' => 'If the email is registered, you will receive a password reset link'
    ]);
    exit;
}
