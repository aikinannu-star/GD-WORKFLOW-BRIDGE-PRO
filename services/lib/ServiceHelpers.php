<?php

class ServiceHelpersTestResponseException extends Exception
{
    public array $response;

    public function __construct(array $response)
    {
        parent::__construct('ServiceHelpers test response');
        $this->response = $response;
    }
}

class ServiceHelpers
{
    public static function dataPath(string $service, string $fileName): string
    {
        $base = getenv('GDWB_DATA_BASE') ?: (__DIR__ . '/../../services/data');
        if (!is_dir($base)) {
            mkdir($base, 0777, true);
        }
        return $base . '/' . $service . '_' . $fileName;
    }

    public static function loadJson(string $service, string $fileName): array
    {
        $path = self::dataPath($service, $fileName);
        if (!file_exists($path)) {
            return [];
        }
        $content = file_get_contents($path);
        return json_decode($content, true) ?? [];
    }

    public static function saveJson(string $service, string $fileName, array $data): bool
    {
        $path = self::dataPath($service, $fileName);
        return file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false;
    }

    public static function sendJson(int $status, array $payload): void
    {
        error_log('ServiceHelpers::sendJson called status=' . $status . ' payload=' . json_encode($payload));
        if (self::isTestMode()) {
            $headers = array_merge(self::buildResponseHeaders(), ['Content-Type: application/json']);
            error_log('ServiceHelpers::sendJson throwing ServiceHelpersTestResponseException');
            throw new ServiceHelpersTestResponseException([
                'status' => $status,
                'headers' => $headers,
                'body' => json_encode($payload),
            ]);
        }

        self::attachTraceHeaders();
        header('Content-Type: application/json');
        http_response_code($status);
        echo json_encode($payload);
        exit;
    }

    public static function sendText(int $status, string $body, string $contentType = 'text/plain; charset=utf-8'): void
    {
        if (self::isTestMode()) {
            $headers = array_merge(self::buildResponseHeaders(), ['Content-Type: ' . $contentType]);
            throw new ServiceHelpersTestResponseException([
                'status' => $status,
                'headers' => $headers,
                'body' => $body,
            ]);
        }

        self::attachTraceHeaders();
        header('Content-Type: ' . $contentType);
        http_response_code($status);
        echo $body;
        exit;
    }

    public static function isTestMode(): bool
    {
        $isTestMode = defined('SERVICE_HELPERS_TEST_MODE') && SERVICE_HELPERS_TEST_MODE === true;
        error_log('ServiceHelpers::isTestMode returning ' . ($isTestMode ? 'true' : 'false'));
        return $isTestMode;
    }

    private static function buildResponseHeaders(): array
    {
        $headers = [];
        $requestId = self::getOrCreateRequestId();
        $headers[] = 'X-Request-Id: ' . $requestId;
        $traceContext = self::getTraceContext();
        $headers[] = 'X-Trace-Id: ' . $traceContext['trace_id'];
        $headers[] = 'X-Span-Id: ' . $traceContext['span_id'];
        if (!empty($traceContext['parent_span_id'])) {
            $headers[] = 'X-Parent-Span-Id: ' . $traceContext['parent_span_id'];
        }
        return $headers;
    }

    private static function attachTraceHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        $requestId = self::getOrCreateRequestId();
        if (!headers_sent()) {
            header('X-Request-Id: ' . $requestId);
        }
        $traceContext = self::getTraceContext();
        if (!headers_sent()) {
            header('X-Trace-Id: ' . $traceContext['trace_id']);
            header('X-Span-Id: ' . $traceContext['span_id']);
            if (!empty($traceContext['parent_span_id'])) {
                header('X-Parent-Span-Id: ' . $traceContext['parent_span_id']);
            }
        }
    }

    public static function getRequestBody(): array
    {
        $body = self::getRawRequestBody();
        return json_decode($body, true) ?? [];
    }

    public static function getRawRequestBody(): string
    {
        if (!empty($_SERVER['GDWB_RAW_REQUEST_BODY'])) {
            return (string)$_SERVER['GDWB_RAW_REQUEST_BODY'];
        }
        return file_get_contents('php://input');
    }

    public static function generateUuid(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function getHeader(string $header)
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $header));
        return $_SERVER[$key] ?? null;
    }

    public static function normalizeTenantId(array $data): ?string
    {
        return $data['tenant_id'] ?? $_GET['tenant_id'] ?? self::getHeader('X-Tenant-Id') ?? null;
    }

    public static function getStandardMetadata(string $service): array
    {
        return [
            'service' => $service,
            'version' => getenv('GDWB_VERSION') ?: '7.3',
            'environment' => getenv('GDWB_ENVIRONMENT') ?: 'local',
            'instance' => gethostname() ?: 'unknown',
            'request_id' => self::getOrCreateRequestId(),
        ];
    }

    public static function getTraceMetadata(): array
    {
        $traceContext = self::getTraceContext();
        return [
            'trace_id' => $traceContext['trace_id'],
            'span_id' => $traceContext['span_id'],
            'parent_span_id' => $traceContext['parent_span_id'],
        ];
    }

    public static function getTenantContext(): ?string
    {
        return self::normalizeTenantId($_SERVER) ?? ($_SERVER['GDWB_TENANT_ID'] ?? null);
    }

    public static function getOrCreateRequestId(): string
    {
        error_log('ServiceHelpers::getOrCreateRequestId called');
        $existing = self::getHeader('X-Request-Id') ?? self::getHeader('X-Correlation-Id');
        error_log('ServiceHelpers::getOrCreateRequestId existing='.(empty($existing)?'empty':'present'));
        if (!empty($existing)) {
            return $existing;
        }
        if (!empty($_SERVER['GDWB_REQUEST_ID'] ?? null)) {
            error_log('ServiceHelpers::getOrCreateRequestId returning cached GDWB_REQUEST_ID');
            return (string)$_SERVER['GDWB_REQUEST_ID'];
        }
        try {
            $id = bin2hex(random_bytes(8));
        } catch (Throwable $e) {
            $id = uniqid('req_', true);
        }
        $_SERVER['GDWB_REQUEST_ID'] = $id;
        error_log('ServiceHelpers::getOrCreateRequestId returning new id='.$id);
        return $id;
    }

    public static function getTraceContext(): array
    {
        $traceId = self::getHeader('Trace-Id') ?? self::getHeader('X-Trace-Id') ?? ($_SERVER['GDWB_TRACE_ID'] ?? null);
        if (empty($traceId)) {
            try {
                $traceId = bin2hex(random_bytes(16));
            } catch (Throwable $e) {
                $traceId = uniqid('trace_', true);
            }
        }
        $spanId = self::getHeader('Span-Id') ?? self::getHeader('X-Span-Id') ?? ($_SERVER['GDWB_SPAN_ID'] ?? null);
        if (empty($spanId)) {
            try {
                $spanId = bin2hex(random_bytes(8));
            } catch (Throwable $e) {
                $spanId = uniqid('span_', true);
            }
        }
        $parentSpanId = self::getHeader('Parent-Span-Id') ?? self::getHeader('X-Parent-Span-Id') ?? ($_SERVER['GDWB_PARENT_SPAN_ID'] ?? null);

        $_SERVER['GDWB_TRACE_ID'] = $traceId;
        $_SERVER['GDWB_SPAN_ID'] = $spanId;
        if (!empty($parentSpanId)) {
            $_SERVER['GDWB_PARENT_SPAN_ID'] = $parentSpanId;
        }

        return [
            'trace_id' => $traceId,
            'span_id' => $spanId,
            'parent_span_id' => $parentSpanId,
        ];
    }

    public static function emitStructuredLog(string $service, string $level, string $message, array $context = []): void
    {
        $traceContext = self::getTraceContext();
        $standardMetadata = self::getStandardMetadata($service);
        $tenantId = self::getTenantContext();
        
        $entry = array_merge($standardMetadata, [
            'timestamp' => gmdate('c'),
            'level' => $level,
            'message' => $message,
            'trace_id' => $traceContext['trace_id'],
            'span_id' => $traceContext['span_id'],
            'parent_span_id' => $traceContext['parent_span_id'],
            'path' => $_SERVER['REQUEST_URI'] ?? null,
            'method' => $_SERVER['REQUEST_METHOD'] ?? null,
        ]);
        
        if (!empty($tenantId)) {
            $entry['tenant_id'] = $tenantId;
        }
        
        $entry = array_merge($entry, $context);
        $path = self::dataPath($service, 'app.log');
        file_put_contents($path, json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    public static function incrementMetric(string $service, string $name, array $labels = [], int $value = 1): void
    {
        self::recordMetric($service, $name, $labels, 'counter', (float)$value);
    }

    public static function observeMetric(string $service, string $name, array $labels = [], float $value = 1.0): void
    {
        self::recordMetric($service, $name, $labels, 'histogram', $value);
    }

    public static function setGauge(string $service, string $name, array $labels = [], float $value = 0.0): void
    {
        self::recordMetric($service, $name, $labels, 'gauge', $value);
    }

    private static function recordMetric(string $service, string $name, array $labels, string $type, float $value): void
    {
        $path = self::dataPath($service, 'metrics.json');
        $metrics = [];
        if (file_exists($path)) {
            $metrics = json_decode((string)file_get_contents($path), true) ?? [];
        }

        if (!isset($metrics[$name]) || !is_array($metrics[$name]) || !isset($metrics[$name]['type'])) {
            $metrics[$name] = ['type' => $type, 'samples' => []];
        }

        $labelKey = self::buildMetricLabelKey($labels);
        $sample = $metrics[$name]['samples'][$labelKey] ?? ['labels' => $labels, 'value' => 0.0, 'sum' => 0.0, 'count' => 0, 'buckets' => []];
        if ($type === 'counter') {
            $sample['value'] = (float)($sample['value'] ?? 0.0) + $value;
        } elseif ($type === 'gauge') {
            $sample['value'] = $value;
        } else {
            $sample['count'] = (int)($sample['count'] ?? 0) + 1;
            $sample['sum'] = (float)($sample['sum'] ?? 0.0) + $value;
            $buckets = self::defaultHistogramBuckets();
            foreach ($buckets as $bucket) {
                if ($value <= $bucket) {
                    $bucketKey = (string)$bucket;
                    $sample['buckets'][$bucketKey] = (int)($sample['buckets'][$bucketKey] ?? 0) + 1;
                }
            }
            $sample['buckets']['+Inf'] = (int)($sample['buckets']['+Inf'] ?? 0) + 1;
        }
        $metrics[$name]['samples'][$labelKey] = $sample;
        file_put_contents($path, json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    private static function buildMetricLabelKey(array $labels): string
    {
        ksort($labels);
        return json_encode($labels);
    }

    private static function defaultHistogramBuckets(): array
    {
        return [0.005, 0.01, 0.025, 0.05, 0.1, 0.2, 0.5, 1.0, 2.5, 5.0, 10.0];
    }

    public static function renderPrometheusMetrics(string $service): string
    {
        $path = self::dataPath($service, 'metrics.json');
        $metrics = [];
        if (file_exists($path)) {
            $metrics = json_decode((string)file_get_contents($path), true) ?? [];
        }
        $lines = [];
        foreach ($metrics as $name => $definition) {
            if (is_array($definition) && isset($definition['type']) && isset($definition['samples'])) {
                $safeName = preg_replace('/[^a-z0-9_]/', '_', strtolower($name));
                $type = in_array($definition['type'], ['histogram', 'gauge'], true) ? $definition['type'] : 'counter';
                $lines[] = '# HELP ' . $safeName . ' Metric for ' . $service;
                $lines[] = '# TYPE ' . $safeName . ' ' . $type;
                foreach ($definition['samples'] as $sample) {
                    $labels = $sample['labels'] ?? [];
                    $labelText = self::formatPrometheusLabels($labels);
                    if ($type === 'histogram') {
                        foreach ($sample['buckets'] ?? [] as $bucket => $count) {
                            $lines[] = $safeName . '_bucket' . $labelText . 'le="' . $bucket . '" ' . (int)$count;
                        }
                        $lines[] = $safeName . '_sum' . $labelText . ' ' . number_format((float)($sample['sum'] ?? 0.0), 6, '.', '');
                        $lines[] = $safeName . '_count' . $labelText . ' ' . (int)($sample['count'] ?? 0);
                    } else {
                        $lines[] = $safeName . $labelText . ' ' . number_format((float)($sample['value'] ?? 0.0), 6, '.', '');
                    }
                }
            } elseif (is_numeric($definition)) {
                $safeName = preg_replace('/[^a-z0-9_]/', '_', strtolower($name));
                $lines[] = '# HELP ' . $safeName . ' Counter metric for ' . $service;
                $lines[] = '# TYPE ' . $safeName . ' counter';
                $lines[] = $safeName . ' ' . (int)$definition;
            }
        }
        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    public static function getOtlpEndpoint(): ?string
    {
        $endpoint = getenv('OTEL_EXPORTER_OTLP_ENDPOINT') ?: getenv('OTEL_EXPORTER_OTLP_ENDPOINT_HTTP');
        if (empty($endpoint)) {
            return null;
        }

        if (!preg_match('#/v1/(traces|metrics)(/)?$#', $endpoint)) {
            $endpoint = rtrim($endpoint, '/') . '/v1/traces';
        }

        return $endpoint;
    }

    public static function getOtlpHeaders(): array
    {
        $headers = ['Content-Type: application/json; charset=utf-8'];
        $headerString = getenv('OTEL_EXPORTER_OTLP_HEADERS') ?: getenv('OTEL_EXPORTER_OTLP_EXPORTER_OTLP_HEADERS');
        if (empty($headerString)) {
            return $headers;
        }

        foreach (preg_split('/[;,]+/', $headerString) as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }
            [$name, $value] = array_pad(array_map('trim', explode('=', $segment, 2)), 2, '');
            if ($name !== '' && $value !== '') {
                $headers[] = $name . ': ' . $value;
            }
        }

        return $headers;
    }

    public static function exportOtlpTrace(string $service, array $spans): bool
    {
        $endpoint = self::getOtlpEndpoint();
        if (empty($endpoint) || empty($spans)) {
            return false;
        }

        $resourceAttributes = [
            ['key' => 'service.name', 'value' => ['stringValue' => $service]],
            ['key' => 'service.version', 'value' => ['stringValue' => getenv('GDWB_VERSION') ?: 'unknown']],
            ['key' => 'deployment.environment', 'value' => ['stringValue' => getenv('GDWB_ENVIRONMENT') ?: 'local']],
        ];

        $spanEntries = [];
        foreach ($spans as $span) {
            if (empty($span['trace_id']) || empty($span['span_id'])) {
                continue;
            }
            $spanEntries[] = [
                'traceId' => strtolower(preg_replace('/[^0-9a-f]/', '', (string)$span['trace_id'])),
                'spanId' => strtolower(preg_replace('/[^0-9a-f]/', '', (string)$span['span_id'])),
                'name' => $span['name'] ?? 'assistant.request',
                'kind' => 1,
                'startTimeUnixNano' => self::toOtlpUnixNano((float)($span['start_time'] ?? microtime(true))),
                'endTimeUnixNano' => self::toOtlpUnixNano((float)($span['end_time'] ?? microtime(true))),
                'attributes' => self::buildOtlpAttributes($span['attributes'] ?? []),
                'status' => ['code' => isset($span['status_code']) ? (int)$span['status_code'] : 0],
            ];
        }

        if (empty($spanEntries)) {
            return false;
        }

        $payload = [
            'resourceSpans' => [[
                'resource' => ['attributes' => $resourceAttributes],
                'scopeSpans' => [[
                    'scope' => ['name' => 'gdwb', 'version' => getenv('GDWB_VERSION') ?: '1.0'],
                    'spans' => $spanEntries,
                ]],
            ]],
        ];

        $body = json_encode($payload);
        if ($body === false) {
            return false;
        }

        $headers = array_merge(self::getOtlpHeaders(), ['Accept: application/json']);
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => 2,
                'ignore_errors' => true,
            ],
        ]);

        try {
            $response = @file_get_contents($endpoint, false, $context);
            return $response !== false;
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function buildOtlpAttributes(array $attributes): array
    {
        $formatted = [];
        foreach ($attributes as $key => $value) {
            if ($key === '') {
                continue;
            }
            if (is_bool($value)) {
                $formatted[] = ['key' => $key, 'value' => ['boolValue' => $value]];
            } elseif (is_int($value)) {
                $formatted[] = ['key' => $key, 'value' => ['intValue' => $value]];
            } elseif (is_float($value)) {
                $formatted[] = ['key' => $key, 'value' => ['doubleValue' => $value]];
            } else {
                $formatted[] = ['key' => $key, 'value' => ['stringValue' => (string)$value]];
            }
        }
        return $formatted;
    }

    private static function toOtlpUnixNano(float $timestamp): string
    {
        return (string)(int)round($timestamp * 1e9);
    }

    private static function formatPrometheusLabels(array $labels): string
    {
        if (empty($labels)) {
            return '';
        }
        ksort($labels);
        $parts = [];
        foreach ($labels as $key => $value) {
            $parts[] = $key . '="' . str_replace('"', '\\"', (string)$value) . '"';
        }
        return '{' . implode(',', $parts) . '}';
    }

    public static function redisConnect(): ?Redis
    {
        if (!class_exists('Redis')) {
            return null;
        }
        $host = $_ENV['GATEWAY_REDIS_HOST'] ?? ($_ENV['REDIS_HOST'] ?? '127.0.0.1');
        $port = (int)($_ENV['GATEWAY_REDIS_PORT'] ?? ($_ENV['REDIS_PORT'] ?? 6379));
        try {
            $r = new Redis();
            if (@$r->connect($host, $port, 1.0)) {
                return $r;
            }
        } catch (Throwable $e) {
            return null;
        }
        return null;
    }

    public static function invalidateGatewayAuthCache(string $userId, string $projectId, array $actions = []): void
    {
        if (empty($userId) || empty($projectId)) {
            return;
        }
        if (empty($actions)) {
            // Derive default action keywords from PermissionService if available
            if (!class_exists('PermissionService')) {
                @include_once __DIR__ . '/PermissionService.php';
            }
            if (class_exists('PermissionService')) {
                try {
                    $actions = PermissionService::getActionKeywords();
                } catch (Throwable $e) {
                    $actions = [];
                }
            }
        }
        if (empty($actions)) {
            return;
        }
        $redis = self::redisConnect();
        if (!$redis) {
            return;
        }
        foreach ($actions as $action) {
            $key = 'gateway:cms:auth:' . sha1('u:' . $userId . ':p:' . $projectId . ':a:' . $action);
            try {
                @$redis->del($key);
            } catch (Throwable $e) {
                // ignore
            }
            // publish invalidation message for observability/subscribers
            try {
                @$redis->publish('gateway:cms:auth:invalidate', json_encode(['user_id' => $userId, 'project_id' => $projectId, 'action' => $action]));
            } catch (Throwable $e) {
                // ignore
            }
        }
    }
}
