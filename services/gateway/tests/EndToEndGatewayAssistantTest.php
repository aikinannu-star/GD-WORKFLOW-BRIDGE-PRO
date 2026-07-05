<?php

define('SERVICE_HELPERS_TEST_MODE', true);
define('GATEWAY_TEST_MODE', true);

require_once __DIR__ . '/../../assistant/ToolInterface.php';
require_once __DIR__ . '/../../assistant/RuntimeBootstrap.php';
require_once realpath(__DIR__ . '/../server.php');

class TraceCaptureProvider implements ModelProviderInterface
{
    public array $receivedOptions = [];

    public function chat(string $prompt, array $options = []): array
    {
        $this->receivedOptions = $options;
        return [
            'success' => true,
            'text' => '{"payload":{"assistant":"ok"}}',
            'raw' => null,
            'error' => null,
        ];
    }

    public function stream(string $prompt, array $options = []): iterable
    {
        yield ['text' => ''];
    }

    public function embeddings(string $input, array $options = []): array
    {
        return ['vector' => []];
    }

    public function health(): array
    {
        return ['status' => 'ok'];
    }

    public function capabilities(): array
    {
        return ['chat' => true, 'stream' => true, 'embeddings' => true, 'health' => true];
    }
}

class TraceCaptureWorkflowTool implements ToolInterface
{
    private $capturedArgs;

    public function __construct(array &$capturedArgs)
    {
        $this->capturedArgs = &$capturedArgs;
    }

    public function id(): string
    {
        return 'workflow_execute';
    }

    public function name(): string
    {
        return 'Trace Capture Workflow Tool';
    }

    public function description(): string
    {
        return 'Captures workflow execution metadata for test validation.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'workflowId' => ['type' => 'string'],
                'input' => ['type' => 'object'],
            ],
            'required' => ['workflowId'],
        ];
    }

    public function execute(array $args): array
    {
        $this->capturedArgs = $args;
        return ['success' => true, 'result' => ['workflow_executed' => true, 'args' => $args], 'error' => null];
    }
}

function parseHeaderLines(array $headerLines): array
{
    $headers = [];
    foreach ($headerLines as $line) {
        $parts = explode(':', $line, 2);
        if (count($parts) === 2) {
            $headers[trim($parts[0])] = trim($parts[1]);
        }
    }
    return $headers;
}

function buildServerHeaders(array $requestHeaders): void
{
    // Clear any previous HTTP_* values
    foreach (array_keys($_SERVER) as $key) {
        if (strpos($key, 'HTTP_') === 0) {
            unset($_SERVER[$key]);
        }
    }
    foreach ($requestHeaders as $name => $value) {
        $serverKey = 'HTTP_' . str_replace('-', '_', strtoupper($name));
        $_SERVER[$serverKey] = $value;
    }
}

function dispatchGatewayRequest(array $request): array
{
    // Clear previous request state
    foreach (['REQUEST_METHOD', 'REQUEST_URI', 'QUERY_STRING', 'REMOTE_ADDR', 'GDWB_RAW_REQUEST_BODY'] as $key) {
        unset($_SERVER[$key]);
    }
    buildServerHeaders($request['headers'] ?? []);
    $_SERVER['REQUEST_METHOD'] = $request['method'];
    $_SERVER['REQUEST_URI'] = $request['uri'];
    $_SERVER['QUERY_STRING'] = $request['query'] ?? '';
    $_SERVER['REMOTE_ADDR'] = $request['remote_addr'] ?? '127.0.0.1';
    $_SERVER['GDWB_RAW_REQUEST_BODY'] = $request['body'] ?? '';

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    try {
        return runGatewayServer();
    } catch (ServiceHelpersTestResponseException $ex) {
        return $ex->response;
    }
}

$traceProvider = new TraceCaptureProvider();
$capturedToolArgs = [];
$capturedHeaders = [];

$testTempBase = sys_get_temp_dir() . '/gdwb_gateway_assistant_test_' . uniqid();
$runtime = RuntimeBootstrap::bootstrap([
    'dispatcher_plugins_path' => __DIR__ . '/../../dispatcher/plugins',
    'model_provider' => $traceProvider,
    'memory_path' => $testTempBase . '/assistant_memory',
    'conversation_path' => $testTempBase . '/assistant_conversations',
    'summaries_path' => $testTempBase . '/assistant_summaries',
]);
$toolRegistry = $runtime['toolRegistry'];
$toolRegistry->registerTool(new TraceCaptureWorkflowTool($capturedToolArgs));
$assistantManager = $runtime['assistantManager'];

setGatewayProxyHandler(function (string $targetUrl, string $method, array $headers, string $body = null) use ($assistantManager, &$capturedHeaders) {
    $headerMap = parseHeaderLines($headers);
    $capturedHeaders = $headerMap;

    foreach (['X-Request-Id', 'X-Trace-Id', 'X-Span-Id', 'X-Parent-Span-Id', 'X-Tenant-Id', 'X-User-Id', 'X-API-Key'] as $name) {
        $serverKey = 'HTTP_' . str_replace('-', '_', strtoupper($name));
        if (isset($headerMap[$name])) {
            $_SERVER[$serverKey] = $headerMap[$name];
        } else {
            unset($_SERVER[$serverKey]);
        }
    }

    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['REQUEST_URI'] = parse_url($targetUrl, PHP_URL_PATH) ?: '/';
    $_SERVER['QUERY_STRING'] = parse_url($targetUrl, PHP_URL_QUERY) ?? '';

    if ($method === 'POST' && $_SERVER['REQUEST_URI'] === '/api/v1/assistant/sessions/test-session/message') {
        $request = json_decode($body ?? '', true) ?: [];
        $result = $assistantManager->handle('support-assistant', [
            'message' => $request['text'] ?? '',
            'conversationId' => $request['conversationId'] ?? 'test-conv',
            'sessionId' => $request['sessionId'] ?? 'test-session',
            'tenantId' => $headerMap['X-Tenant-Id'] ?? 'tenant-allowed',
            'userId' => $headerMap['X-User-Id'] ?? 'gateway-client',
        ]);

        return [
            'status' => 200,
            'headers' => ['Content-Type: application/json'],
            'body' => json_encode($result),
        ];
    }

    return [
        'status' => 404,
        'headers' => ['Content-Type: application/json'],
        'body' => json_encode(['error' => 'not_found']),
    ];
});

$scenarios = [
    'valid_api_key' => [
        'request' => [
            'method' => 'POST',
            'uri' => '/api/v1/assistant/sessions/test-session/message',
            'headers' => [
                'X-API-Key' => 'valid-key-1',
                'X-Tenant-Id' => 'tenant-allowed',
            ],
            'body' => json_encode([
                'text' => 'Please execute workflow and trace this request',
                'conversationId' => 'test-conv-001',
                'sessionId' => 'test-session',
            ]),
        ],
        'expectedStatus' => 200,
        'assertions' => function ($response, $payload) use (&$capturedHeaders, $traceProvider, &$capturedToolArgs) {
            if (empty($payload['success'])) {
                fwrite(STDERR, "Valid API key scenario failed: assistant did not succeed\n");
                fwrite(STDERR, "Response body: {$response['body']}\n");
                fwrite(STDERR, "Decoded payload: " . var_export($payload, true) . "\n");
                fwrite(STDERR, "Captured headers: " . var_export($capturedHeaders, true) . "\n");
                exit(1);
            }
            $requiredTrace = ['X-Trace-Id', 'X-Request-Id'];
            foreach ($requiredTrace as $header) {
                if (empty($capturedHeaders[$header])) {
                    fwrite(STDERR, "Gateway did not forward required header {$header}\n");
                    exit(1);
                }
            }
            if ($traceProvider->receivedOptions['trace']['trace_id'] !== $capturedHeaders['X-Trace-Id']) {
                fwrite(STDERR, "Trace ID mismatch through provider\n");
                exit(1);
            }
            if ($traceProvider->receivedOptions['request_id'] !== $capturedHeaders['X-Request-Id']) {
                fwrite(STDERR, "Request ID mismatch through provider\n");
                exit(1);
            }
            if ($traceProvider->receivedOptions['assistant_id'] !== 'support-assistant') {
                fwrite(STDERR, "Provider assistant_id mismatch\n");
                exit(1);
            }
            if ($traceProvider->receivedOptions['conversation_id'] !== 'test-conv-001') {
                fwrite(STDERR, "Provider conversation_id mismatch\n");
                exit(1);
            }
            if ($traceProvider->receivedOptions['workflow_id'] !== 'default') {
                fwrite(STDERR, "Provider workflow_id mismatch\n");
                exit(1);
            }
            if (empty($capturedToolArgs['__execution_id'])) {
                fwrite(STDERR, "Tool did not receive execution_id\n");
                exit(1);
            }
            if ($capturedToolArgs['__trace']['trace_id'] !== $capturedHeaders['X-Trace-Id']) {
                fwrite(STDERR, "Tool trace_id mismatch\n");
                exit(1);
            }
            if ($capturedToolArgs['__request_id'] !== $capturedHeaders['X-Request-Id']) {
                fwrite(STDERR, "Tool request_id mismatch\n");
                exit(1);
            }
        },
    ],
    'revoked_api_key' => [
        'request' => [
            'method' => 'POST',
            'uri' => '/api/v1/assistant/sessions/test-session/message',
            'headers' => [
                'X-API-Key' => 'revoked-key',
                'X-Tenant-Id' => 'tenant-denied',
            ],
            'body' => json_encode(['text' => 'Any request']),
        ],
        'expectedStatus' => 401,
    ],
    'expired_api_key' => [
        'request' => [
            'method' => 'POST',
            'uri' => '/api/v1/assistant/sessions/test-session/message',
            'headers' => [
                'X-API-Key' => 'expired-key',
                'X-Tenant-Id' => 'tenant-allowed',
            ],
            'body' => json_encode(['text' => 'Any request']),
        ],
        'expectedStatus' => 401,
    ],
    'tenant_mismatch' => [
        'request' => [
            'method' => 'POST',
            'uri' => '/api/v1/assistant/sessions/test-session/message',
            'headers' => [
                'X-API-Key' => 'valid-key-1',
                'X-Tenant-Id' => 'other-tenant',
            ],
            'body' => json_encode(['text' => 'Any request']),
        ],
        'expectedStatus' => 403,
    ],
    'unauthorized_tool' => [
        'request' => [
            'method' => 'POST',
            'uri' => '/api/v1/assistant/sessions/test-session/message',
            'headers' => [
                'X-API-Key' => 'valid-key-2',
                'X-Tenant-Id' => 'tenant-denied',
            ],
            'body' => json_encode([
                'text' => 'Please execute workflow',
                'conversationId' => 'test-conv-unauth',
                'sessionId' => 'test-session',
            ]),
        ],
        'expectedStatus' => 200,
        'assertions' => function ($response, $payload) {
            if (is_array($payload) && empty($payload['success']) && strpos(json_encode($payload), 'tool_forbidden') !== false) {
                return;
            }
            if (is_string($response['body']) && strpos($response['body'], 'tool_forbidden') !== false) {
                return;
            }
            fwrite(STDERR, "Unauthorized tool scenario did not return the expected tool-forbidden response\n");
            exit(1);
        },
    ],
    'unknown_route' => [
        'request' => [
            'method' => 'POST',
            'uri' => '/api/v1/assistant/unknown/path',
            'headers' => [
                'X-API-Key' => 'valid-key-1',
                'X-Tenant-Id' => 'tenant-allowed',
            ],
            'body' => json_encode(['text' => 'Any request']),
        ],
        'expectedStatus' => 404,
    ],
];

foreach ($scenarios as $name => $scenario) {
    $capturedToolArgs = [];
    $traceProvider->receivedOptions = [];
    $capturedHeaders = [];

    $response = dispatchGatewayRequest($scenario['request']);
    if ($response['status'] !== $scenario['expectedStatus']) {
        fwrite(STDERR, "Scenario {$name} failed: expected status {$scenario['expectedStatus']}, got {$response['status']}\n");
        fwrite(STDERR, "Body: {$response['body']}\n");
        exit(1);
    }

    if (!empty($scenario['assertions']) && is_callable($scenario['assertions'])) {
        $payload = json_decode($response['body'], true);
        $scenario['assertions']($response, $payload);
    }

    fwrite(STDOUT, "Scenario {$name} passed\n");
}

fwrite(STDOUT, "Gateway assistant integration suite passed\n");
exit(0);
