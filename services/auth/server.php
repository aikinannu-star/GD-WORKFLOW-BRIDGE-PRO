<?php
/**
 * Authentication Service
 * Handles user registration, login, multicompany tenant auth, and RBAC tokens.
 */

require_once __DIR__ . '/../lib/ServiceHelpers.php';

// Service metadata
define('SERVICE_NAME', 'auth');
define('SERVICE_PORT', 8002);

$jwtSecret = $_ENV['AUTH_JWT_SECRET'] ?? null;
if (!$jwtSecret) {
    throw new RuntimeException('AUTH_JWT_SECRET environment variable is required. Do not use the default development secret in production.');
}
define('JWT_SECRET', $jwtSecret);

global $method, $uri;
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

$requestId = ServiceHelpers::getOrCreateRequestId();
$traceContext = ServiceHelpers::getTraceContext();
if (!headers_sent()) {
    header('X-Request-Id: ' . $requestId);
    header('X-Trace-Id: ' . $traceContext['trace_id']);
    header('X-Span-Id: ' . $traceContext['span_id']);
    if (!empty($traceContext['parent_span_id'])) {
        header('X-Parent-Span-Id: ' . $traceContext['parent_span_id']);
    }
}
ServiceHelpers::emitStructuredLog('auth', 'info', 'request_received', [
    'request_id' => $requestId,
    'trace_id' => $traceContext['trace_id'],
    'span_id' => $traceContext['span_id'],
    'parent_span_id' => $traceContext['parent_span_id'],
    'client_ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    'method' => $method,
    'path' => $uri,
]);

function jwtEncode(array $payload): string
{
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    $segments = [];
    $segments[] = base64_encode(str_replace(['+', '/', '='], ['-', '_', ''], json_encode($header)));
    $segments[] = base64_encode(str_replace(['+', '/', '='], ['-', '_', ''], json_encode($payload)));
    $signature = hash_hmac('sha256', implode('.', $segments), JWT_SECRET, true);
    $segments[] = base64_encode(str_replace(['+', '/', '='], ['-', '_', ''], $signature));
    return implode('.', $segments);
}

function jwtDecode(string $token): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }
    [$headerB64, $payloadB64, $signatureB64] = $parts;
    $payloadJson = base64_decode(str_replace(['-', '_'], ['+', '/'], $payloadB64));
    return json_decode($payloadJson, true);
}

function jwtVerify(string $token): bool
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return false;
    }
    [$headerB64, $payloadB64, $signatureB64] = $parts;
    $expected = hash_hmac('sha256', $headerB64 . '.' . $payloadB64, JWT_SECRET, true);
    $actual = base64_decode(str_replace(['-', '_'], ['+', '/'], $signatureB64));
    return hash_equals($expected, $actual);
}

function loadUsers(): array
{
    return ServiceHelpers::loadJson('auth', 'users.json');
}

function saveUsers(array $users): bool
{
    return ServiceHelpers::saveJson('auth', 'users.json', $users);
}

function sendError(int $status, string $message): void
{
    $statusClass = $status >= 500 ? '5xx' : ($status >= 400 ? '4xx' : '2xx');
    ServiceHelpers::incrementMetric('auth', 'auth_requests_total', ['method' => $_SERVER['REQUEST_METHOD'] ?? 'GET', 'route' => $_SERVER['REQUEST_URI'] ?? '/', 'status' => $statusClass]);
    ServiceHelpers::incrementMetric('auth', 'auth_errors_total', ['method' => $_SERVER['REQUEST_METHOD'] ?? 'GET', 'route' => $_SERVER['REQUEST_URI'] ?? '/', 'status' => $statusClass]);
    ServiceHelpers::emitStructuredLog('auth', 'warn', 'error_response', ['request_id' => $_SERVER['GDWB_REQUEST_ID'] ?? null, 'status' => $status, 'message' => $message]);
    ServiceHelpers::sendJson($status, ['error' => $message]);
}

function getTenantId(array $body): ?string
{
    $tenant = ServiceHelpers::normalizeTenantId($body);
    if (!$tenant) {
        sendError(400, 'tenant_id is required');
    }
    return $tenant;
}

function createToken(array $user): string
{
    $payload = [
        'iss' => 'gdwb-auth-service',
        'sub' => $user['id'],
        'tenant_id' => $user['tenant_id'],
        'email' => $user['email'],
        'role' => $user['role'],
        'permissions' => $user['permissions'] ?? [],
        // include assigned license metadata when present
        'license_key' => $user['license_key'] ?? ($user['licenseKey'] ?? null),
        'seats' => isset($user['seats']) ? (int)$user['seats'] : null,
        'iat' => time(),
        'exp' => time() + 3600,
    ];
    return jwtEncode($payload);
}

function getUserFromToken(string $token): ?array
{
    if (!jwtVerify($token)) {
        return null;
    }
    $payload = jwtDecode($token);
    if (!$payload || ($payload['exp'] ?? 0) < time()) {
        return null;
    }
    return $payload;
}

if ($method === 'GET' && in_array($uri, ['/health', '/health/', '/readyz', '/readyz/'], true)) {
    ServiceHelpers::emitStructuredLog('auth', 'info', 'health_check', ['request_id' => $requestId]);
    ServiceHelpers::sendJson(200, [
        'status' => 'ok',
        'service' => SERVICE_NAME,
        'version' => '1.0.0',
        'request_id' => $requestId,
        'time' => gmdate('c'),
    ]);
}

if ($method === 'GET' && in_array($uri, ['/metrics', '/metrics/'], true)) {
    ServiceHelpers::emitStructuredLog('auth', 'info', 'metrics_requested', ['request_id' => $requestId]);
    ServiceHelpers::sendText(200, ServiceHelpers::renderPrometheusMetrics('auth'));
}

if ($method === 'POST' && $uri === '/api/v1/auth/register') {
    $input = ServiceHelpers::getRequestBody();
    $tenantId = getTenantId($input);
    $email = filter_var($input['email'] ?? '', FILTER_VALIDATE_EMAIL);
    $password = $input['password'] ?? '';
    if (!$email || !$password) {
        ServiceHelpers::incrementMetric('auth', 'auth_errors_total');
        ServiceHelpers::emitStructuredLog('auth', 'warn', 'register_validation_failed', ['request_id' => $requestId, 'tenant_id' => $tenantId]);
        sendError(400, 'email and password are required');
    }

    $users = loadUsers();
    foreach ($users as $user) {
        if ($user['email'] === $email && $user['tenant_id'] === $tenantId) {
            ServiceHelpers::incrementMetric('auth', 'auth_errors_total');
            ServiceHelpers::emitStructuredLog('auth', 'warn', 'register_conflict', ['request_id' => $requestId, 'email' => $email, 'tenant_id' => $tenantId]);
            sendError(409, 'User already exists');
        }
    }

    $userId = ServiceHelpers::generateUuid();
    $role = 'admin';
    $tenantUsers = array_filter($users, fn($u) => $u['tenant_id'] === $tenantId);
    if (count($tenantUsers) > 0) {
        $role = 'editor';
    }

    $newUser = [
        'id' => $userId,
        'tenant_id' => $tenantId,
        'email' => $email,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'role' => $role,
        'permissions' => ['read', 'write', 'deploy'],
        'created_at' => gmdate('c'),
    ];
    $users[] = $newUser;
    saveUsers($users);

    // Auto-issue a free license when signing up for dev convenience
    // Call license server purchase endpoint in simulate mode to create a free license
    $license_key = null;
    try {
        $licenseUrl = 'http://127.0.0.1:8001/api/v1/purchase';
        $payload = json_encode(['plan' => 'free', 'site' => '', 'simulate' => true]);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $licenseUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp !== false && $code >= 200 && $code < 300) {
            $j = json_decode($resp, true);
            if (!empty($j['license_key'])) $license_key = $j['license_key'];
        }
    } catch (Throwable $e) {
        // ignore failures - signup still succeeds
    }

    if ($license_key) {
        $newUser['license_key'] = $license_key;
        $newUser['seats'] = 1;
        $users[count($users) - 1] = $newUser;
        saveUsers($users);
    }

    $token = createToken($newUser);
    ServiceHelpers::incrementMetric('auth', 'auth_requests_total', ['method' => $method, 'route' => $uri, 'status' => '2xx']);
    ServiceHelpers::emitStructuredLog('auth', 'info', 'register_completed', ['request_id' => $requestId, 'trace_id' => $traceContext['trace_id'], 'span_id' => $traceContext['span_id'], 'parent_span_id' => $traceContext['parent_span_id'], 'email' => $email, 'tenant_id' => $tenantId]);
    ServiceHelpers::sendJson(201, ['success' => true, 'token' => $token, 'user' => ['id' => $userId, 'email' => $email, 'role' => $role, 'tenant_id' => $tenantId, 'license_key' => $license_key]]);
}

if ($method === 'POST' && $uri === '/api/v1/auth/login') {
    $input = ServiceHelpers::getRequestBody();
    $tenantId = getTenantId($input);
    $email = filter_var($input['email'] ?? '', FILTER_VALIDATE_EMAIL);
    $password = $input['password'] ?? '';
    if (!$email || !$password) {
        ServiceHelpers::incrementMetric('auth', 'auth_errors_total');
        ServiceHelpers::emitStructuredLog('auth', 'warn', 'login_validation_failed', ['request_id' => $requestId, 'tenant_id' => $tenantId]);
        sendError(400, 'email and password are required');
    }

    $users = loadUsers();
    $user = null;
    foreach ($users as $candidate) {
        if ($candidate['email'] === $email && $candidate['tenant_id'] === $tenantId) {
            $user = $candidate;
            break;
        }
    }
    if (!$user || !password_verify($password, $user['password_hash'])) {
        ServiceHelpers::incrementMetric('auth', 'auth_errors_total');
        ServiceHelpers::emitStructuredLog('auth', 'warn', 'login_failed', ['request_id' => $requestId, 'email' => $email, 'tenant_id' => $tenantId]);
        sendError(401, 'Invalid credentials');
    }

    $token = createToken($user);
    ServiceHelpers::incrementMetric('auth', 'auth_requests_total', ['method' => $method, 'route' => $uri, 'status' => '2xx']);
    ServiceHelpers::emitStructuredLog('auth', 'info', 'login_completed', ['request_id' => $requestId, 'trace_id' => $traceContext['trace_id'], 'span_id' => $traceContext['span_id'], 'parent_span_id' => $traceContext['parent_span_id'], 'email' => $user['email'], 'tenant_id' => $user['tenant_id']]);
    ServiceHelpers::sendJson(200, ['success' => true, 'token' => $token, 'user' => ['id' => $user['id'], 'email' => $user['email'], 'role' => $user['role'], 'tenant_id' => $user['tenant_id']]]);
}

if ($method === 'POST' && $uri === '/api/v1/auth/introspect') {
    $input = ServiceHelpers::getRequestBody();
    $token = $input['token'] ?? null;
    if (!$token) {
        sendError(400, 'token required');
    }
    $debugFile = __DIR__ . '/../../services/data/auth_introspect.log';
    $debugDir = dirname($debugFile);
    if (!is_dir($debugDir)) {
        @mkdir($debugDir, 0777, true);
    }
    $tredacted = is_string($token) && strlen($token) > 8 ? substr($token, 0, 8) . '...' : 'null';
    @file_put_contents($debugFile, gmdate('c') . " INTROSPECT RECEIVED TOKEN={$tredacted}\n", FILE_APPEND | LOCK_EX);
    @error_log("[auth] INTROSPECT RECEIVED TOKEN={$tredacted}");
    $payload = getUserFromToken($token);
    if (!$payload) {
        @file_put_contents($debugFile, gmdate('c') . " INTROSPECT INVALID TOKEN={$tredacted}\n", FILE_APPEND | LOCK_EX);
        @error_log("[auth] INTROSPECT INVALID TOKEN={$tredacted}");
        sendError(401, 'Token invalid or expired');
    }
    // Include license metadata if present in token payload so downstream
    // services (gateway/backend) can forward it without separate lookups.
    $userOut = [
        'id' => $payload['sub'],
        'email' => $payload['email'] ?? null,
        'role' => $payload['role'] ?? null,
        'tenant_id' => $payload['tenant_id'] ?? null,
    ];
    if (!empty($payload['license_key'])) $userOut['license_key'] = $payload['license_key'];
    if (!empty($payload['seats'])) $userOut['seats'] = (int)$payload['seats'];
    @file_put_contents($debugFile, gmdate('c') . " INTROSPECT OK user=" . ($userOut['id'] ?? '') . " tenant=" . ($userOut['tenant_id'] ?? '') . "\n", FILE_APPEND | LOCK_EX);
    @error_log("[auth] INTROSPECT OK user=" . ($userOut['id'] ?? '') . " tenant=" . ($userOut['tenant_id'] ?? ''));
    ServiceHelpers::sendJson(200, ['success' => true, 'valid' => true, 'user' => $userOut]);
}

if ($method === 'POST' && $uri === '/api/v1/auth/refresh') {
    $input = ServiceHelpers::getRequestBody();
    $token = $input['token'] ?? null;
    if (!$token) {
        sendError(400, 'token required');
    }
    $payload = getUserFromToken($token);
    if (!$payload) {
        sendError(401, 'Token invalid or expired');
    }
    $users = loadUsers();
    $user = array_values(array_filter($users, fn($u) => $u['id'] === $payload['sub']))[0] ?? null;
    if (!$user) {
        sendError(404, 'User not found');
    }
    $newToken = createToken($user);
    ServiceHelpers::sendJson(200, ['success' => true, 'token' => $newToken]);
}

if ($method === 'GET' && $uri === '/api/v1/auth/users') {
    $users = loadUsers();
    ServiceHelpers::sendJson(200, ['users' => array_map(function ($user) {
        return ['id' => $user['id'], 'email' => $user['email'], 'tenant_id' => $user['tenant_id'], 'role' => $user['role']];
    }, $users)]);
}

http_response_code(404);
ServiceHelpers::sendJson(404, ['error' => 'not_found']);
