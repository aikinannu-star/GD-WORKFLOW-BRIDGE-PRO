<?php
/**
 * API Gateway Service
 * Routes tenant-aware API traffic to backend microservices.
 */

require_once __DIR__ . '/../lib/ServiceHelpers.php';
require_once __DIR__ . '/../lib/Metrics.php';
require_once __DIR__ . '/../lib/ControlPlaneAuth.php';
require_once __DIR__ . '/../lib/GracefulShutdownManager.php';
require_once __DIR__ . '/AuthHelpers.php';

define('GATEWAY_SERVICE_NAME', 'gateway');
define('GATEWAY_SERVICE_PORT', 8000);

if (!defined('GATEWAY_TEST_MODE')) {
    define('GATEWAY_TEST_MODE', false);
}

if (!defined('GATEWAY_SERVER_LOADED')) {
    define('GATEWAY_SERVER_LOADED', true);

    $GLOBALS['GATEWAY_PROXY_HANDLER'] = null;
    $GLOBALS['GATEWAY_SHUTDOWN_MANAGER'] = new GracefulShutdownManager('gateway');
    $GLOBALS['GATEWAY_SHUTDOWN_MANAGER']->registerSignalHandlers();

    if (!function_exists('setGatewayProxyHandler')) {
        function setGatewayProxyHandler(callable $handler): void
        {
            $GLOBALS['GATEWAY_PROXY_HANDLER'] = $handler;
        }
    }

    if (!function_exists('getGatewayProxyHandler')) {
        function getGatewayProxyHandler(): ?callable
        {
            return $GLOBALS['GATEWAY_PROXY_HANDLER'] ?? null;
        }
    }

    if (!function_exists('buildGatewayBackendBase')) {
        function buildGatewayBackendBase(string $serviceKey, string $composeHost, int $port): string
        {
            $envName = 'GATEWAY_' . strtoupper(str_replace('-', '_', $serviceKey)) . '_BASE';
            if (!empty($_ENV[$envName])) {
                return $_ENV[$envName];
            }

            if (!empty($_ENV['GATEWAY_USE_LOCALHOST']) || !empty(getenv('GATEWAY_USE_LOCALHOST'))) {
                return 'http://127.0.0.1:' . $port;
            }

            return $composeHost;
        }
    }

    if (!function_exists('runGatewayServer')) {
        function runGatewayServer(): array
        {
    error_log('GATEWAY_DEBUG: runGatewayServer called');
    global $registry;
    error_log('GATEWAY_DEBUG: after global registry');

    $method = $_SERVER['REQUEST_METHOD'];
    error_log('GATEWAY_DEBUG: got method='.$method);
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    error_log('GATEWAY_DEBUG: got uri='.$uri);
    $queryString = $_SERVER['QUERY_STRING'] ?? '';
    error_log('GATEWAY_DEBUG: got queryString');

    if (GATEWAY_TEST_MODE) {
        ob_start();
    }
    error_log('GATEWAY_DEBUG: after ob_start if block');

    $registry = [
    // Allow overriding individual backends via environment variables in compose/devcontainer.
    'auth' => buildGatewayBackendBase('auth', 'http://auth-service:8002', 8002),
    'tenant' => buildGatewayBackendBase('tenant', 'http://tenant-service:8009', 8009),
    'cms' => buildGatewayBackendBase('cms', 'http://cms-service:8004', 8004),
    'billing' => buildGatewayBackendBase('billing', 'http://billing-service:8005', 8005),
    'marketplace' => buildGatewayBackendBase('marketplace', 'http://marketplace-service:8006', 8006),
    'media' => buildGatewayBackendBase('media', 'http://media-service:8010', 8010),
    'social' => buildGatewayBackendBase('social', 'http://social-service:8008', 8008),
    'feed' => buildGatewayBackendBase('feed', 'http://feed-service:8011', 8011),
    'realtime' => buildGatewayBackendBase('realtime', 'http://realtime-service:8012', 8012),
    'usage' => buildGatewayBackendBase('usage', 'http://usage-service:8007', 8007),
    'website-builder' => buildGatewayBackendBase('website-builder', 'http://website-builder-service:8013', 8013),
    'mobile-builder' => buildGatewayBackendBase('mobile-builder', 'http://mobile-builder-service:8014', 8014),
    'desktop-builder' => buildGatewayBackendBase('desktop-builder', 'http://desktop-builder-service:8015', 8015),
    'workflow' => buildGatewayBackendBase('workflow', 'http://workflow-service:8016', 8016),
    'assistant' => buildGatewayBackendBase('assistant', 'http://assistant-service:8017', 8017),
    'dispatcher' => buildGatewayBackendBase('dispatcher', 'http://dispatcher-service:8020', 8020),
    'analytics' => buildGatewayBackendBase('analytics', 'http://analytics-service:8018', 8018),
    'deployment' => buildGatewayBackendBase('deployment', 'http://deployment-service:8019', 8019),
    'license' => buildGatewayBackendBase('license', 'http://license-server:8001', 8001),
    // Support legacy/plural route used by some frontends: /api/v1/licenses/*
    // Route legacy `licenses/*` to the license-server for local development.
    'licenses' => buildGatewayBackendBase('license', 'http://license-server:8001', 8001),
];
error_log('GATEWAY_DEBUG: registry assigned');
error_log('GATEWAY_DEBUG: about to enter sendCorsHeaders if block');

if (!function_exists('sendCorsHeaders')) {
    function sendCorsHeaders(): void
    {
        $origin = $_ENV['GATEWAY_CORS_ORIGIN'] ?? '*';
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Tenant-Id, Accept');
        header('Access-Control-Expose-Headers: X-Gateway-Route');
    }
}
error_log('GATEWAY_DEBUG: after sendCorsHeaders if block');

error_log('GATEWAY_DEBUG: about to enter getNormalizedHeaders if block');
if (!function_exists('getNormalizedHeaders')) {
    function getNormalizedHeaders(): array
    {
        $headers = [];
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                $headers[$name] = $value;
            }
            return $headers;
        }

        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $name = str_replace('_', '-', substr($key, 5));
                $headers[$name] = $value;
            }
        }
        return $headers;
    }
}
error_log('GATEWAY_DEBUG: after getNormalizedHeaders if block');

error_log('GATEWAY_DEBUG: about to enter resolveTargetHost if block');
if (!function_exists('resolveTargetHost')) {
    error_log('GATEWAY_DEBUG: inside resolveTargetHost if block');
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
    error_log('GATEWAY_DEBUG: leaving resolveTargetHost if block');
}

if (!function_exists('canAcceptGatewayRequests')) {
    function canAcceptGatewayRequests(): bool
    {
        return isset($GLOBALS['GATEWAY_SHUTDOWN_MANAGER']) ? $GLOBALS['GATEWAY_SHUTDOWN_MANAGER']->canAcceptRequests() : true;
    }
}

if (!function_exists('handleGatewayRequest')) {
    function handleGatewayRequest(callable $handler): array
    {
        if (!isset($GLOBALS['GATEWAY_SHUTDOWN_MANAGER'])) {
            $GLOBALS['GATEWAY_SHUTDOWN_MANAGER'] = new GracefulShutdownManager('gateway');
            $GLOBALS['GATEWAY_SHUTDOWN_MANAGER']->registerSignalHandlers();
        }

        if (!$GLOBALS['GATEWAY_SHUTDOWN_MANAGER']->canAcceptRequests()) {
            ServiceHelpers::incrementMetric('gateway', 'gateway_requests_rejected_during_shutdown_total');
            ServiceHelpers::sendJson(503, ['error' => 'service_draining', 'message' => 'Gateway service is draining and not accepting new requests.']);
        }

        $GLOBALS['GATEWAY_SHUTDOWN_MANAGER']->beginRequest();
        try {
            return $handler();
        } finally {
            $GLOBALS['GATEWAY_SHUTDOWN_MANAGER']->endRequest();
        }
    }
}

error_log('GATEWAY_DEBUG: after resolveTargetHost if block');

// --- Middleware helpers ---
if (!function_exists('getRequestId')) {
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
}
error_log('GATEWAY_DEBUG: after getRequestId helper block');

if (!function_exists('getClientIp')) {
    function getClientIp(): string
    {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($parts[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
}
error_log('GATEWAY_DEBUG: after getClientIp helper block');

if (!function_exists('logRequest')) {
    function logRequest(array $entry): void
    {
    $path = ServiceHelpers::dataPath('gateway', 'requests.log');
    file_put_contents($path, json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
error_log('GATEWAY_DEBUG: after logRequest helper block');

if (!function_exists('checkRateLimit')) {
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
}
error_log('GATEWAY_DEBUG: after checkRateLimit helper block');

// Delegate API key lookup/validation to AuthHelpers
if (!function_exists('getApiKeyInfo')) {
    function getApiKeyInfo(array $headers): ?array
    {
        return GatewayAuthHelpers::getApiKeyInfoFromHeaders($headers);
    }
}
error_log('GATEWAY_DEBUG: after getApiKeyInfo helper block');

if (!function_exists('getTenantQuota')) {
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
}
error_log('GATEWAY_DEBUG: after getTenantQuota helper block');

if (!function_exists('isPublicService')) {
    function isPublicService(string $serviceKey): bool
    {
    // Treat license and auth services as public (no gateway auth required).
    // Also allow the legacy 'licenses' alias to be public when routed to the
    // license-server so UI calls like /api/v1/licenses/me can work without
    // requiring gateway-level Authorization during local dev.
    $public = ['license', 'licenses', 'auth'];
        return in_array($serviceKey, $public, true);
    }
}
error_log('GATEWAY_DEBUG: after isPublicService helper block');

if (!function_exists('introspectToken')) {
    function introspectToken(string $token): ?array
    {
    global $registry;
    $authBase = $_ENV['GATEWAY_AUTH_BASE'] ?? ($registry['auth'] ?? 'http://auth-service:8002');
    $url = rtrim($authBase, '/') . '/api/v1/auth/introspect';
    $payload = json_encode(['token' => $token]);
    $debugFile = __DIR__ . '/../../services/data/gateway_introspect.log';
    // redact token for logs
    $tredacted = substr($token, 0, 8) . '...';
    @file_put_contents($debugFile, gmdate('c') . " INTROSPECT -> {$url} TOKEN={$tredacted}\n", FILE_APPEND | LOCK_EX);
    @error_log("[gateway] INTROSPECT -> {$url} TOKEN={$tredacted}");
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            @file_put_contents($debugFile, gmdate('c') . " INTROSPECT ERROR: CURL_FAIL {$err}\n", FILE_APPEND | LOCK_EX);
            @error_log("[gateway] INTROSPECT ERROR: CURL_FAIL {$err}");
            return null;
        }
        curl_close($ch);
        @file_put_contents($debugFile, gmdate('c') . " INTROSPECT RESPONSE_CODE={$code} RESP_BODY=" . substr($resp, 0, 1024) . "\n", FILE_APPEND | LOCK_EX);
        @error_log("[gateway] INTROSPECT RESPONSE_CODE={$code} RESP_BODY=" . substr($resp, 0, 1024));
        if ($code !== 200) {
            return null;
        }
        $data = json_decode($resp, true);
        return $data;
    }
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $payload, 'timeout' => 5, 'ignore_errors' => true]]);
    $resp = @file_get_contents($url, false, $ctx);
    @file_put_contents($debugFile, gmdate('c') . " INTROSPECT STREAM_RESP=" . substr($resp ?: '', 0, 1024) . "\n", FILE_APPEND | LOCK_EX);
    @error_log("[gateway] INTROSPECT STREAM_RESP=" . substr($resp ?: '', 0, 1024));
    if ($resp === false) {
        return null;
    }
    $data = json_decode($resp, true);
        return $data;
    }
}
error_log('GATEWAY_DEBUG: after introspectToken helper block');

/**
 * Proxy request to backend service and return structured response
 * returns ['status' => int, 'headers' => array, 'body' => string]
 */
if (!function_exists('proxyToService')) {
    function proxyToService(string $targetUrl, string $method, array $headers, string $body = null): array
    {
        $handler = getGatewayProxyHandler();
        if ($handler !== null) {
            return $handler($targetUrl, $method, $headers, $body);
        }

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
}
error_log('GATEWAY_DEBUG: after proxyToService helper block');

error_log('GATEWAY_DEBUG: about to call sendCorsHeaders');
sendCorsHeaders();
error_log('GATEWAY_DEBUG: after sendCorsHeaders');
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
    // Allow slightly more time for aggregated health checks in local
    // development where many backends may be contacted sequentially.
    if (function_exists('set_time_limit')) {
        @set_time_limit(60);
    }

    $results = [];
    // Use curl_multi to fetch backends concurrently when available to avoid
    // sequential per-host timeouts causing PHP max execution time to be hit.
    if (function_exists('curl_init') && function_exists('curl_multi_init')) {
        $multi = curl_multi_init();
        $handles = [];
        foreach ($registry as $key => $host) {
            $checkUrl = rtrim($host, '/') . '/health';
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $checkUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 2);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_multi_add_handle($multi, $ch);
            $handles[$key] = ['ch' => $ch, 'url' => $checkUrl, 'start' => microtime(true)];
        }

        // Execute handles
        $running = null;
        do {
            $mrc = curl_multi_exec($multi, $running);
            // Wait for activity (max 1s) to avoid busy loop
            curl_multi_select($multi, 1.0);
        } while ($running > 0 && $mrc === CURLM_OK);

        // Collect results
        foreach ($handles as $key => $entry) {
            $ch = $entry['ch'];
            $body = curl_multi_getcontent($ch);
            $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $elapsed = round((microtime(true) - $entry['start']) * 1000);
            $ok = ($statusCode === 200);
            $results[$key] = ['url' => $entry['url'], 'ok' => $ok, 'http_code' => $statusCode, 'time_ms' => $elapsed, 'body' => $body ?: null];
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }
        curl_multi_close($multi);
    } else {
        // Fallback: bounded sequential fetch with a global deadline
        $deadline = microtime(true) + 10; // 10s overall cap
        foreach ($registry as $key => $host) {
            if (microtime(true) > $deadline) {
                $results[$key] = ['url' => rtrim($host, '/') . '/health', 'ok' => false, 'http_code' => 0, 'time_ms' => 0, 'body' => null];
                continue;
            }
            $checkUrl = rtrim($host, '/') . '/health';
            $start = microtime(true);
            $statusCode = 0;
            $body = null;
            $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 2, 'ignore_errors' => true]]);
            $body = @file_get_contents($checkUrl, false, $ctx);
            if (isset($http_response_header) && preg_match('/HTTP\/[0-9\.]+\s+(\d+)/', $http_response_header[0], $m)) {
                $statusCode = (int)$m[1];
            }
            $ok = ($statusCode === 200);
            $elapsed = round((microtime(true) - $start) * 1000);
            $results[$key] = ['url' => $checkUrl, 'ok' => $ok, 'http_code' => $statusCode, 'time_ms' => $elapsed, 'body' => $body ?: null];
        }
    }
    ServiceHelpers::sendJson(200, ['services' => $results, 'gateway_time' => gmdate('c')]);
}

if ($uri === '/health' || $uri === '/health/') {
    ServiceHelpers::sendJson(200, [
        'status' => 'ok',
        'service' => GATEWAY_SERVICE_NAME,
        'version' => '1.0.0',
        'time' => gmdate('c'),
        'services' => array_keys($registry),
    ]);
}

$requestId = ServiceHelpers::getOrCreateRequestId();
error_log('GATEWAY_DEBUG: after getOrCreateRequestId, requestId='.$requestId);
$traceContext = ServiceHelpers::getTraceContext();
error_log('GATEWAY_DEBUG: after getTraceContext');
if (!headers_sent()) {
    header('X-Request-Id: ' . $requestId);
    header('X-Trace-Id: ' . $traceContext['trace_id']);
    header('X-Span-Id: ' . $traceContext['span_id']);
    if (!empty($traceContext['parent_span_id'])) {
        header('X-Parent-Span-Id: ' . $traceContext['parent_span_id']);
    }
}
ServiceHelpers::emitStructuredLog('gateway', 'info', 'request_received', [
    'request_id' => $requestId,
    'trace_id' => $traceContext['trace_id'],
    'span_id' => $traceContext['span_id'],
    'parent_span_id' => $traceContext['parent_span_id'],
    'client_ip' => getClientIp(),
    'method' => $method,
    'path' => $uri,
]);

if ($method === 'GET' && in_array($uri, ['/health', '/health/', '/readyz', '/readyz/'], true)) {
    ServiceHelpers::emitStructuredLog('gateway', 'info', 'health_check', ['request_id' => $requestId]);
    ServiceHelpers::sendJson(200, ['status' => 'ok', 'service' => GATEWAY_SERVICE_NAME, 'request_id' => $requestId, 'time' => gmdate('c')]);
}

if ($method === 'GET' && in_array($uri, ['/metrics', '/metrics/'], true)) {
    ServiceHelpers::emitStructuredLog('gateway', 'info', 'metrics_requested', ['request_id' => $requestId]);
    ServiceHelpers::sendText(200, ServiceHelpers::renderPrometheusMetrics('gateway'));
}

return handleGatewayRequest(function () use ($method, $uri, $queryString, $registry, $requestId, $traceContext) {
    $target = resolveTargetHost($uri, $registry);
if ($target === null) {
    ServiceHelpers::incrementMetric('gateway', 'gateway_requests_total', ['method' => $method, 'route' => $uri, 'status' => '404']);
    ServiceHelpers::incrementMetric('gateway', 'gateway_errors_total', ['method' => $method, 'route' => $uri, 'status' => '404']);
    ServiceHelpers::emitStructuredLog('gateway', 'warn', 'route_not_found', ['request_id' => $requestId, 'trace_id' => $traceContext['trace_id'], 'span_id' => $traceContext['span_id'], 'path' => $uri]);
    ServiceHelpers::sendJson(404, ['error' => 'route_not_found', 'path' => $uri]);
}

$hostBase = rtrim($target['host'], '/');
$servicePath = $target['path'] ?: '/';
$attemptUrls = [
    $hostBase . $uri,
    $hostBase . $servicePath,
    $hostBase . '/api/v1' . $servicePath,
];
if ($queryString !== '') {
    foreach ($attemptUrls as &$candidate) {
        if (strpos($candidate, '?') === false) {
            $candidate .= '?' . $queryString;
        }
    }
    unset($candidate);
}

// --- Middleware: request id, rate limit, auth, logging ---
$startTime = microtime(true);
$incomingHeaders = getNormalizedHeaders();
$clientIp = getClientIp();
$requestId = ServiceHelpers::getOrCreateRequestId();

// Determine API key / tenant / ip bucket for rate limiting
$apiKeyInfo = getApiKeyInfo($incomingHeaders);
$user = null;
$bucket = null;
$limitOverride = null;
$skipAuthForWebhook = false;

    error_log('GATEWAY_DEBUG: apiKeyInfo='.json_encode($apiKeyInfo).' target='.$target['key']);
if (!isPublicService($target['key']) && !$skipAuthForWebhook) {
    $lower = array_change_key_case($incomingHeaders, CASE_LOWER);
    $authHeader = $incomingHeaders['Authorization'] ?? $lower['authorization'] ?? null;

    $tenantHeader = ServiceHelpers::getHeader('X-Tenant-Id') ?? $lower['x-tenant-id'] ?? null;

    // If Authorization header missing, allow API key fallback for service-to-service or client keys
    if (empty($authHeader) && $apiKeyInfo) {
        $check = GatewayAuthHelpers::apiKeyAllowedForService($apiKeyInfo, $target['key']);
        if (empty($check['ok'])) {
            ServiceHelpers::emitStructuredLog('gateway', 'warn', 'gateway.auth.failed', [
                'event' => 'gateway.auth.failed.' . ($check['error'] ?? 'invalid_key'),
                'request_id' => $requestId,
                'trace_id' => $traceContext['trace_id'],
                'tenant_id' => $apiKeyInfo['tenant_id'] ?? null,
                'api_key_id' => $apiKeyInfo['id'] ?? $apiKeyInfo['key'] ?? null,
                'target_service' => $target['key'],
                'http_method' => $method,
                'status' => $check['status'] ?? 401,
            ]);
            ServiceHelpers::incrementMetric('gateway', 'gateway_auth_failed_total', ['service' => $target['key'], 'reason' => $check['error'] ?? 'invalid_key']);
            $status = $check['status'] ?? 401;
            if ($status === 403) {
                ServiceHelpers::sendJson(403, ['error' => $check['error'] ?? 'forbidden', 'message' => $check['message'] ?? 'forbidden', 'request_id' => $requestId]);
            }
            ServiceHelpers::sendJson(401, ['error' => $check['error'] ?? 'unauthorized', 'message' => $check['message'] ?? 'unauthorized', 'request_id' => $requestId]);
        }

        ServiceHelpers::emitStructuredLog('gateway', 'info', 'gateway.auth.success', [
            'event' => 'gateway.auth.success',
            'request_id' => $requestId,
            'trace_id' => $traceContext['trace_id'],
            'tenant_id' => $apiKeyInfo['tenant_id'] ?? null,
            'api_key_id' => $apiKeyInfo['id'] ?? $apiKeyInfo['key'] ?? null,
            'target_service' => $target['key'],
            'http_method' => $method,
            'status' => 200,
        ]);
        ServiceHelpers::incrementMetric('gateway', 'gateway_auth_success_total', ['service' => $target['key']]);

        // Treat API key as an authenticated principal
        $user = [
            'id' => $apiKeyInfo['id'] ?? ($apiKeyInfo['key'] ?? null),
            'tenant_id' => $apiKeyInfo['tenant_id'] ?? null,
            'email' => $apiKeyInfo['owner'] ?? null,
            'scopes' => $apiKeyInfo['scopes'] ?? [],
        ];
    } else {
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
    }

    if ($apiKeyInfo && !empty($tenantHeader) && !empty($apiKeyInfo['tenant_id']) && $tenantHeader !== $apiKeyInfo['tenant_id']) {
        error_log('GATEWAY_DEBUG: tenant mismatch - apiKeyTenant=' . $apiKeyInfo['tenant_id'] . ' headerTenant=' . $tenantHeader);
        ServiceHelpers::emitStructuredLog('gateway', 'warn', 'gateway.auth.failed', [
            'event' => 'gateway.auth.failed.tenant',
            'request_id' => $requestId,
            'trace_id' => $traceContext['trace_id'],
            'tenant_id' => $tenantHeader,
            'api_key_id' => $apiKeyInfo['id'] ?? $apiKeyInfo['key'] ?? null,
            'target_service' => $target['key'],
            'http_method' => $method,
            'status' => 403,
        ]);
        error_log('GATEWAY_DEBUG: about to call incrementMetric');
        ServiceHelpers::incrementMetric('gateway', 'gateway_auth_failed_total', ['service' => $target['key'], 'reason' => 'tenant_mismatch']);
        error_log('GATEWAY_DEBUG: about to call sendJson');
        ServiceHelpers::sendJson(403, ['error' => 'tenant_mismatch', 'message' => 'API key tenant does not match request tenant', 'request_id' => $requestId]);
        error_log('GATEWAY_DEBUG: after sendJson (should not reach here!)');
    }

    // If API key is present and $user lacks tenant, prefer tenant from API key
    if ($apiKeyInfo && empty($user['tenant_id'])) {
        if (!isset($user) || !is_array($user)) {
            $user = [];
        }
        if (!empty($apiKeyInfo['tenant_id'])) {
            $user['tenant_id'] = $apiKeyInfo['tenant_id'];
        }
    }

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
        $check = GatewayAuthHelpers::apiKeyAllowedForService($apiKeyInfo, $target['key']);
        if (empty($check['ok'])) {
            ServiceHelpers::emitStructuredLog('gateway', 'warn', 'gateway.auth.failed', [
                'event' => 'gateway.auth.failed.' . ($check['error'] ?? 'invalid_key'),
                'request_id' => $requestId,
                'trace_id' => $traceContext['trace_id'],
                'tenant_id' => $apiKeyInfo['tenant_id'] ?? null,
                'api_key_id' => $apiKeyInfo['id'] ?? $apiKeyInfo['key'] ?? null,
                'target_service' => $target['key'],
                'http_method' => $method,
                'status' => $check['status'] ?? 401,
            ]);
            ServiceHelpers::incrementMetric('gateway', 'gateway_auth_failed_total', ['service' => $target['key'], 'reason' => $check['error'] ?? 'invalid_key']);
            $status = $check['status'] ?? 401;
            if ($status === 403) {
                ServiceHelpers::sendJson(403, ['error' => $check['error'] ?? 'forbidden', 'message' => $check['message'] ?? 'forbidden', 'request_id' => $requestId]);
            }
            ServiceHelpers::sendJson(401, ['error' => $check['error'] ?? 'unauthorized', 'message' => $check['message'] ?? 'unauthorized', 'request_id' => $requestId]);
        }
        ServiceHelpers::emitStructuredLog('gateway', 'info', 'gateway.auth.success', [
            'event' => 'gateway.auth.success',
            'request_id' => $requestId,
            'trace_id' => $traceContext['trace_id'],
            'tenant_id' => $apiKeyInfo['tenant_id'] ?? null,
            'api_key_id' => $apiKeyInfo['id'] ?? $apiKeyInfo['key'] ?? null,
            'target_service' => $target['key'],
            'http_method' => $method,
            'status' => 200,
        ]);
        ServiceHelpers::incrementMetric('gateway', 'gateway_auth_success_total', ['service' => $target['key']]);

        $bucket = 'apikey:' . ($apiKeyInfo['id'] ?? $apiKeyInfo['key'] ?? 'unknown');
        $limitOverride = isset($apiKeyInfo['limit']) ? (int)$apiKeyInfo['limit'] : null;
        // Expose tenant from API key for public routes where useful
        if (!empty($apiKeyInfo['tenant_id'])) {
            $user = $user ?: [];
            $user['tenant_id'] = $apiKeyInfo['tenant_id'];
        }
        if (!empty($tenantHeader) && !empty($apiKeyInfo['tenant_id']) && $tenantHeader !== $apiKeyInfo['tenant_id']) {
            ServiceHelpers::emitStructuredLog('gateway', 'warn', 'gateway.auth.failed', [
                'event' => 'gateway.auth.failed.tenant',
                'request_id' => $requestId,
                'trace_id' => $traceContext['trace_id'],
                'tenant_id' => $tenantHeader,
                'api_key_id' => $apiKeyInfo['id'] ?? $apiKeyInfo['key'] ?? null,
                'target_service' => $target['key'],
                'http_method' => $method,
                'status' => 403,
            ]);
            ServiceHelpers::incrementMetric('gateway', 'gateway_auth_failed_total', ['service' => $target['key'], 'reason' => 'tenant_mismatch']);
            ServiceHelpers::sendJson(403, ['error' => 'tenant_mismatch', 'message' => 'API key tenant does not match request tenant', 'request_id' => $requestId]);
        }
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
$forwardHeaders[] = 'X-Trace-Id: ' . $traceContext['trace_id'];
$forwardHeaders[] = 'X-Span-Id: ' . $traceContext['span_id'];
if (!empty($traceContext['parent_span_id'])) {
    $forwardHeaders[] = 'X-Parent-Span-Id: ' . $traceContext['parent_span_id'];
}
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


$body = $_SERVER['GDWB_RAW_REQUEST_BODY'] ?? file_get_contents('php://input');

// Try the original gateway path first, then the service-relative path, and
// finally the backend's /api/v1/* form if the backend expects that convention.
$result = null;
$usedUrl = null;
foreach ($attemptUrls as $u) {
    $result = proxyToService($u, $method, $forwardHeaders, $body);
    $usedUrl = $u;
    if (($result['status'] ?? 0) !== 404) {
        break;
    }
}

ServiceHelpers::emitStructuredLog('gateway', 'info', 'gateway.request.forwarded', [
    'event' => 'gateway.request.forwarded',
    'request_id' => $requestId,
    'trace_id' => $traceContext['trace_id'],
    'span_id' => $traceContext['span_id'],
    'parent_span_id' => $traceContext['parent_span_id'],
    'service' => $target['key'],
    'target_url' => $usedUrl,
    'status' => $result['status'] ?? 0,
    'http_method' => $method,
    'latency_ms' => round((microtime(true) - $startTime) * 1000),
]);
ServiceHelpers::incrementMetric('gateway', 'gateway_requests_forwarded_total', ['service' => $target['key']]);

// Propagate selected headers from backend and attach gateway metadata
foreach ($result['headers'] as $line) {
    header($line);
}
header('X-Gateway-Route: ' . ($usedUrl ?? ''));
header('X-Request-Id: ' . $requestId);
header('X-Trace-Id: ' . $traceContext['trace_id']);
header('X-Span-Id: ' . $traceContext['span_id']);
if (!empty($traceContext['parent_span_id'])) {
    header('X-Parent-Span-Id: ' . $traceContext['parent_span_id']);
}
header('X-RateLimit-Limit: ' . $rate['limit']);
header('X-RateLimit-Remaining: ' . $rate['remaining']);
header('X-RateLimit-Reset: ' . $rate['reset']);

$statusClass = $result['status'] >= 500 ? '5xx' : ($result['status'] >= 400 ? '4xx' : '2xx');
ServiceHelpers::incrementMetric('gateway', 'gateway_requests_total', ['method' => $method, 'route' => $uri, 'status' => $statusClass]);
if ($result['status'] >= 400) {
    ServiceHelpers::incrementMetric('gateway', 'gateway_errors_total', ['method' => $method, 'route' => $uri, 'status' => $statusClass]);
}
ServiceHelpers::observeMetric('gateway', 'gateway_request_duration_seconds', ['method' => $method, 'route' => $uri, 'status' => $statusClass], (microtime(true) - $startTime));

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
ServiceHelpers::emitStructuredLog('gateway', $result['status'] >= 400 ? 'warn' : 'info', 'request_completed', [
    'event' => 'gateway.request.completed',
    'request_id' => $requestId,
    'trace_id' => $traceContext['trace_id'],
    'span_id' => $traceContext['span_id'],
    'parent_span_id' => $traceContext['parent_span_id'],
    'service' => $target['key'],
    'status' => $result['status'],
    'latency_ms' => round((microtime(true) - $startTime) * 1000),
]);

    error_log('runGatewayServer tail GATEWAY_TEST_MODE=' . (GATEWAY_TEST_MODE ? 'true' : 'false'));
    if (GATEWAY_TEST_MODE) {
        $body = ob_get_clean();
        error_log('runGatewayServer returning from test mode');
        return ['status' => $result['status'] ?? 200, 'headers' => headers_list(), 'body' => $body];
    }

    if (!GATEWAY_TEST_MODE) {
        error_log('runGatewayServer exiting in non-test mode');
        exit;
    }

    $body = ob_get_clean();
    error_log('runGatewayServer returning final response');
    return ['status' => $result['status'] ?? 200, 'headers' => headers_list(), 'body' => $body];
});
}
}

if (!GATEWAY_TEST_MODE) {
    runGatewayServer();
}
}
