<?php
/**
 * API Gateway Service
 * Routes tenant-aware API traffic to backend microservices.
 */

require_once __DIR__ . '/../lib/ServiceHelpers.php';

define('SERVICE_NAME', 'gateway');
define('SERVICE_PORT', 8000);

global $method, $uri, $queryString;
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$queryString = $_SERVER['QUERY_STRING'] ?? '';

$registry = [
    'auth' => 'http://127.0.0.1:8002',
    'tenant' => 'http://127.0.0.1:8009',
    'cms' => 'http://127.0.0.1:8004',
    'billing' => 'http://127.0.0.1:8003',
    'marketplace' => 'http://127.0.0.1:8006',
    'media' => 'http://127.0.0.1:8010',
    'social' => 'http://127.0.0.1:8008',
    'feed' => 'http://127.0.0.1:8011',
    'realtime' => 'http://127.0.0.1:8012',
    'usage' => 'http://127.0.0.1:8007',
    'license' => 'http://127.0.0.1:8001',
    // Support legacy/plural route used by some frontends: /api/v1/licenses/*
    // Route legacy `licenses/*` to the license-server for local development.
    'licenses' => 'http://127.0.0.1:8001',
];

function sendCorsHeaders(): void
{
    $origin = $_ENV['GATEWAY_CORS_ORIGIN'] ?? '*';
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Tenant-Id, Accept');
    header('Access-Control-Expose-Headers: X-Gateway-Route');
}

function getNormalizedHeaders(): array
{
    $headers = [];
    foreach (getallheaders() as $name => $value) {
        $headers[$name] = $value;
    }
    return $headers;
}

function resolveTargetHost(string $requestUri, array $registry): ?array
{
    $path = trim($requestUri, '/');
    if (strpos($path, 'api/v1/') !== 0) {
        return null;
    }
    $segments = explode('/', $path);
    $serviceKey = $segments[2] ?? null;
    if (!$serviceKey || !isset($registry[$serviceKey])) {
        return null;
    }
    return [
        'key' => $serviceKey,
        'host' => $registry[$serviceKey],
        'path' => substr($requestUri, strlen('/api/v1/' . $serviceKey)),
    ];
}

// --- Middleware helpers ---
function getRequestId(): string
{
    $rid = ServiceHelpers::getHeader('X-Request-Id') ?? ServiceHelpers::getHeader('X-Correlation-Id');
    if ($rid) {
        return $rid;
    }
    try {
        return bin2hex(random_bytes(12));
    } catch (Exception $e) {
        return uniqid('rid_', true);
    }
}

function getClientIp(): string
{
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($parts[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

function logRequest(array $entry): void
{
    $path = ServiceHelpers::dataPath('gateway', 'requests.log');
    file_put_contents($path, json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function checkRateLimit(string $clientKey, ?int $overrideLimit = null): array
{
    $defaultLimit = (int)($_ENV['GATEWAY_RATE_LIMIT_PER_MIN'] ?? 120);
    $limit = $overrideLimit ?? $defaultLimit;
    $window = (int)($_ENV['GATEWAY_RATE_LIMIT_WINDOW_SEC'] ?? 60);
    $now = time();

    // Try Redis-backed counter when available (faster and cross-process safe)
    try {
        if (class_exists('Redis')) {
            $redisHost = $_ENV['GATEWAY_REDIS_HOST'] ?? '127.0.0.1';
            $redisPort = (int)($_ENV['GATEWAY_REDIS_PORT'] ?? 6379);
            $redis = new Redis();
            if (@$redis->connect($redisHost, $redisPort, 1.0)) {
                $key = "gateway:ratelimit:{$clientKey}";
                $count = $redis->incr($key);
                if ($count === 1) {
                    $redis->expire($key, $window);
                    $reset = $now + $window;
                } else {
                    $ttl = $redis->ttl($key);
                    $reset = $now + ($ttl > 0 ? $ttl : $window);
                }
                if ($count > $limit) {
                    return ['ok' => false, 'limit' => $limit, 'remaining' => 0, 'reset' => $reset, 'count' => $count];
                }
                return ['ok' => true, 'limit' => $limit, 'remaining' => max(0, $limit - $count), 'reset' => $reset, 'count' => $count];
            }
        }
    } catch (Throwable $e) {
        // Redis failed - fall back to file-based below
    }

    // File-based fallback (legacy prototype)
    $limits = ServiceHelpers::loadJson('gateway', 'rate_limits.json');
    $record = $limits[$clientKey] ?? ['window_start' => $now, 'count' => 0];
    if ($now - $record['window_start'] >= $window) {
        $record['window_start'] = $now;
        $record['count'] = 0;
    }
    $reset = $record['window_start'] + $window;
    if ($record['count'] + 1 > $limit) {
        $limits[$clientKey] = $record;
        ServiceHelpers::saveJson('gateway', 'rate_limits.json', $limits);
        return ['ok' => false, 'limit' => $limit, 'remaining' => 0, 'reset' => $reset, 'count' => $record['count']];
    }
    $record['count'] += 1;
    $limits[$clientKey] = $record;
    ServiceHelpers::saveJson('gateway', 'rate_limits.json', $limits);
    return ['ok' => true, 'limit' => $limit, 'remaining' => max(0, $limit - $record['count']), 'reset' => $reset, 'count' => $record['count']];
}

function getApiKeyInfo(array $headers): ?array
{
    $lower = array_change_key_case($headers, CASE_LOWER);
    $key = $lower['x-api-key'] ?? $lower['x_api_key'] ?? $lower['xapikey'] ?? null;
    if (!$key) return null;
    $keys = ServiceHelpers::loadJson('gateway', 'api_keys.json');
    // fallback to legacy file name without gateway_ prefix
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

function getTenantQuota(string $tenantId): ?int
{
    // Allow overriding via env var (JSON map)
    $env = $_ENV['GATEWAY_TENANT_QUOTAS_JSON'] ?? '';
    if (!empty($env)) {
        $map = json_decode($env, true);
        if (is_array($map) && isset($map[$tenantId])) {
            $v = $map[$tenantId];
            if (is_int($v)) return $v;
            if (is_array($v) && isset($v['limit'])) return (int)$v['limit'];
        }
    }

    $map = ServiceHelpers::loadJson('gateway', 'tenant_quotas.json');
    // fallback to legacy file name without gateway_ prefix
    if (empty($map)) {
        $fallback = __DIR__ . '/../../services/data/tenant_quotas.json';
        if (file_exists($fallback)) {
            $map = json_decode(file_get_contents($fallback), true) ?? [];
        }
    }
    if (isset($map[$tenantId])) {
        $v = $map[$tenantId];
        if (is_int($v)) return $v;
        if (is_array($v) && isset($v['limit'])) return (int)$v['limit'];
    }

    $default = isset($_ENV['GATEWAY_DEFAULT_TENANT_LIMIT']) ? (int)$_ENV['GATEWAY_DEFAULT_TENANT_LIMIT'] : null;
    return $default;
}

function isPublicService(string $serviceKey): bool
{
    // Treat license and auth services as public (no gateway auth required).
    // Also allow the legacy 'licenses' alias to be public when routed to the
    // license-server so UI calls like /api/v1/licenses/me can work without
    // requiring gateway-level Authorization during local dev.
    $public = ['license', 'licenses', 'auth'];
    return in_array($serviceKey, $public, true);
}

function introspectToken(string $token): ?array
{
    $url = 'http://127.0.0.1:8002/api/v1/auth/introspect';
    $payload = json_encode(['token' => $token]);
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $resp = curl_exec($ch);
        if ($resp === false) {
            curl_close($ch);
            return null;
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200) {
            return null;
        }
        $data = json_decode($resp, true);
        return $data;
    }
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $payload, 'timeout' => 5, 'ignore_errors' => true]]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) {
        return null;
    }
    $data = json_decode($resp, true);
    return $data;
}

/**
 * Proxy request to backend service and return structured response
 * returns ['status' => int, 'headers' => array, 'body' => string]
 */
function proxyToService(string $targetUrl, string $method, array $headers, string $body = null): array
{
    // Use cURL if available, otherwise fall back to PHP streams
    $resultHeaders = [];
    $statusCode = 0;
    $respBody = null;
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $targetUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if ($body !== null && in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return ['status' => 502, 'headers' => [], 'body' => json_encode(['error' => 'bad_gateway', 'message' => $err])];
        }

        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $responseHeaders = substr($response, 0, $headerSize);
        $respBody = substr($response, $headerSize);
        curl_close($ch);

        foreach (explode("\r\n", $responseHeaders) as $line) {
            if (stripos($line, 'Content-Type:') === 0 || stripos($line, 'X-') === 0) {
                $resultHeaders[] = $line;
            }
        }
        return ['status' => $statusCode, 'headers' => $resultHeaders, 'body' => $respBody];
    }

    // Stream fallback
    $opts = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'ignore_errors' => true,
            'timeout' => 15,
        ],
    ];
    $context = stream_context_create($opts);
    $resp = @file_get_contents($targetUrl, false, $context);
    $responseHeaders = $http_response_header ?? [];
    if (!empty($responseHeaders)) {
        if (preg_match('/HTTP\/[0-9\.]+\s+(\d+)/', $responseHeaders[0], $m)) {
            $statusCode = (int)$m[1];
        }
        foreach ($responseHeaders as $line) {
            if (stripos($line, 'Content-Type:') === 0 || stripos($line, 'X-') === 0) {
                $resultHeaders[] = $line;
            }
        }
    }
    return ['status' => ($statusCode ?: 502), 'headers' => $resultHeaders, 'body' => $resp];
}

sendCorsHeaders();
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Serve license OpenAPI directly from disk for discovery
if ($uri === '/api/v1/license/openapi.yaml' || $uri === '/api/v1/license/openapi.yml') {
    $file = __DIR__ . '/../../license-server/openapi.yaml';
    if (file_exists($file)) {
        header('Content-Type: application/x-yaml');
        echo file_get_contents($file);
        exit;
    }
    ServiceHelpers::sendJson(404, ['error' => 'openapi_not_found']);
}

// Aggregated health check for all registered services
if ($uri === '/health/services' || $uri === '/health/aggregate') {
    $results = [];
    foreach ($registry as $key => $host) {
        $checkUrl = rtrim($host, '/') . '/health';
        $start = microtime(true);
        $statusCode = 0;
        $body = null;
        // prefer curl if available
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $checkUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3);
            $body = curl_exec($ch);
            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        } else {
            $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 3, 'ignore_errors' => true]]);
            $body = @file_get_contents($checkUrl, false, $ctx);
            if (isset($http_response_header) && preg_match('/HTTP\/[0-9\.]+\s+(\d+)/', $http_response_header[0], $m)) {
                $statusCode = (int)$m[1];
            }
        }
        $ok = ($statusCode === 200);
        $elapsed = round((microtime(true) - $start) * 1000);
        $results[$key] = ['url' => $checkUrl, 'ok' => $ok, 'http_code' => $statusCode, 'time_ms' => $elapsed, 'body' => $body ?: null];
    }
    ServiceHelpers::sendJson(200, ['services' => $results, 'gateway_time' => gmdate('c')]);
}

if ($uri === '/health' || $uri === '/health/') {
    ServiceHelpers::sendJson(200, [
        'status' => 'ok',
        'service' => SERVICE_NAME,
        'version' => '1.0.0',
        'time' => gmdate('c'),
        'services' => array_keys($registry),
    ]);
}

$target = resolveTargetHost($uri, $registry);
if ($target === null) {
    ServiceHelpers::sendJson(404, ['error' => 'route_not_found', 'path' => $uri]);
}

$hostBase = rtrim($target['host'], '/');
$fullUrl = $hostBase . $uri;
// Some backend services expose their own /api/v1/* paths (e.g. license-server exposes
// /api/v1/jwks, /api/v1/introspect, etc). When the gateway receives requests like
// /api/v1/license/jwks we should also attempt forwarding to the backend's
// /api/v1/<rest> path (strip the service key). Construct an alternate URL that
// preserves the /api/v1 prefix on the backend.
$altUrl = $hostBase . '/api/v1' . ($target['path'] ?: '/');
if ($queryString !== '') {
    $fullUrl .= '?' . $queryString;
    if (strpos($altUrl, '?') === false) {
        $altUrl .= ($queryString !== '' ? ('?' . $queryString) : '');
    }
}

// --- Middleware: request id, rate limit, auth, logging ---
$startTime = microtime(true);
$incomingHeaders = getNormalizedHeaders();
$clientIp = getClientIp();
$requestId = getRequestId();

// Determine API key / tenant / ip bucket for rate limiting
$apiKeyInfo = getApiKeyInfo($incomingHeaders);
$user = null;
$bucket = null;
$limitOverride = null;

// For protected services, enforce auth first so we can apply per-tenant quotas
// Allow public (unauthenticated) access to billing webhook routes so
// external payment providers can POST callbacks via the gateway.
$skipAuthForWebhook = ($target['key'] === 'billing' && preg_match('#^/api/v1/billing/webhooks#', $uri));
if (!isPublicService($target['key']) && !$skipAuthForWebhook) {
    $lower = array_change_key_case($incomingHeaders, CASE_LOWER);
    $authHeader = $incomingHeaders['Authorization'] ?? $lower['authorization'] ?? null;
    if (!$authHeader) {
        ServiceHelpers::sendJson(401, ['error' => 'unauthorized', 'message' => 'Authorization header required', 'request_id' => $requestId]);
    }
    if (!preg_match('/Bearer\s+(\S+)/i', $authHeader, $m)) {
        ServiceHelpers::sendJson(401, ['error' => 'unauthorized', 'message' => 'Invalid Authorization header', 'request_id' => $requestId]);
    }
    $token = $m[1];
    $introspect = introspectToken($token);
    $valid = $introspect['valid'] ?? ($introspect['success'] ?? false);
    if (!$introspect || !$valid) {
        ServiceHelpers::sendJson(401, ['error' => 'unauthorized', 'message' => 'Token invalid or expired', 'request_id' => $requestId]);
    }
    $user = $introspect['user'] ?? null;

    if ($apiKeyInfo) {
        $bucket = 'apikey:' . ($apiKeyInfo['id'] ?? $apiKeyInfo['key'] ?? 'unknown');
        $limitOverride = isset($apiKeyInfo['limit']) ? (int)$apiKeyInfo['limit'] : null;
    } elseif (!empty($user['tenant_id'])) {
        $bucket = 'tenant:' . $user['tenant_id'];
        $limitOverride = getTenantQuota($user['tenant_id']);
    } else {
        $bucket = 'ip:' . $clientIp;
    }

} else {
    // Public service: use API key if present, otherwise fall back to IP-based bucket
    if ($apiKeyInfo) {
        $bucket = 'apikey:' . ($apiKeyInfo['id'] ?? $apiKeyInfo['key'] ?? 'unknown');
        $limitOverride = isset($apiKeyInfo['limit']) ? (int)$apiKeyInfo['limit'] : null;
    } else {
        $bucket = 'ip:' . $clientIp;
    }
}

// Apply rate limit against chosen bucket
$rate = checkRateLimit($bucket, $limitOverride);
if (!$rate['ok']) {
    header('X-RateLimit-Limit: ' . $rate['limit']);
    header('X-RateLimit-Remaining: ' . $rate['remaining']);
    header('X-RateLimit-Reset: ' . $rate['reset']);
    ServiceHelpers::sendJson(429, ['error' => 'rate_limited', 'message' => 'Rate limit exceeded', 'request_id' => $requestId, 'bucket' => $bucket]);
}

// Build forwarded headers
$forwardHeaders = [];
foreach ($incomingHeaders as $name => $value) {
    if (strtolower($name) === 'host') {
        continue;
    }
    $forwardHeaders[] = "$name: $value";
}
$forwardHeaders[] = 'X-Request-Id: ' . $requestId;
if ($user && is_array($user)) {
    if (!empty($user['id'])) {
        $forwardHeaders[] = 'X-User-Id: ' . $user['id'];
    }
    if (!empty($user['tenant_id'])) {
        $forwardHeaders[] = 'X-Tenant-Id: ' . $user['tenant_id'];
    }
    // Forward license metadata when available so backends can act without
    // performing their own token introspection.
    if (!empty($user['license_key'])) {
        $forwardHeaders[] = 'X-User-License-Key: ' . $user['license_key'];
    }
    if (isset($user['seats'])) {
        $forwardHeaders[] = 'X-User-Seats: ' . (int)$user['seats'];
    }
}


$body = file_get_contents('php://input');

// Try full original URI first, fall back to trimmed service path if backend 404s.
$attemptUrls = [$fullUrl];
if ($altUrl !== $fullUrl) {
    $attemptUrls[] = $altUrl;
}
$result = null;
$usedUrl = null;
foreach ($attemptUrls as $u) {
    $result = proxyToService($u, $method, $forwardHeaders, $body);
    $usedUrl = $u;
    if (($result['status'] ?? 0) !== 404) {
        break;
    }
}

// Propagate selected headers from backend and attach gateway metadata
foreach ($result['headers'] as $line) {
    header($line);
}
header('X-Gateway-Route: ' . ($usedUrl ?? ''));
header('X-Request-Id: ' . $requestId);
header('X-RateLimit-Limit: ' . $rate['limit']);
header('X-RateLimit-Remaining: ' . $rate['remaining']);
header('X-RateLimit-Reset: ' . $rate['reset']);

http_response_code($result['status']);
echo $result['body'];

// Log request
$logEntry = [
    'id' => $requestId,
    'method' => $method,
    'path' => $uri,
    'service' => $target['key'],
    'client_ip' => $clientIp,
    'status' => $result['status'],
    'time_ms' => round((microtime(true) - $startTime) * 1000),
    'time' => gmdate('c'),
];
if ($user) {
    $logEntry['user'] = ['id' => $user['id'] ?? null, 'email' => $user['email'] ?? null];
}
logRequest($logEntry);
exit;
