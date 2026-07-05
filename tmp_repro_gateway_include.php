<?php

define('SERVICE_HELPERS_TEST_MODE', true);
define('GATEWAY_TEST_MODE', true);

require_once __DIR__ . '/services/assistant/ToolInterface.php';
require_once __DIR__ . '/services/assistant/RuntimeBootstrap.php';
require_once realpath(__DIR__ . '/services/gateway/server.php');

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

$traceProvider = new TraceCaptureProvider();
$runtime = RuntimeBootstrap::bootstrap([
    'dispatcher_plugins_path' => __DIR__ . '/services/dispatcher/plugins',
    'model_provider' => $traceProvider,
]);
$toolRegistry = $runtime['toolRegistry'];

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

$capturedToolArgs = [];
$toolRegistry->registerTool(new TraceCaptureWorkflowTool($capturedToolArgs));
$assistantManager = $runtime['assistantManager'];

setGatewayProxyHandler(function (string $targetUrl, string $method, array $headers, string $body = null) use ($assistantManager, &$capturedToolArgs) {
    $headerMap = [];
    foreach ($headers as $line) {
        $parts = explode(':', $line, 2);
        if (count($parts) === 2) {
            $headerMap[trim($parts[0])] = trim($parts[1]);
        }
    }

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
    $_SERVER['QUERY_STRING'] = parse_url($targetUrl, PHP_URL_QUERY) ?: '';

    if ($method === 'POST' && $_SERVER['REQUEST_URI'] === '/api/v1/assistant/sessions/test-session/message') {
        $request = json_decode($body ?? '', true) ?: [];
        $result = $assistantManager->handle('support-assistant', [
            'message' => $request['text'] ?? '',
            'conversationId' => $request['conversationId'] ?? 'test-conv',
            'sessionId' => $request['sessionId'] ?? 'test-session',
            'tenantId' => $headerMap['X-Tenant-Id'] ?? 'tenant-allowed',
            'userId' => $headerMap['X-User-Id'] ?? 'gateway-client',
        ]);

        return ['status' => 200, 'headers' => ['Content-Type: application/json'], 'body' => json_encode($result)];
    }

    return ['status' => 404, 'headers' => ['Content-Type: application/json'], 'body' => json_encode(['error' => 'not_found'])];
});

$_SERVER = [
    'REQUEST_METHOD' => 'POST',
    'REQUEST_URI' => '/api/v1/assistant/sessions/test-session/message',
    'QUERY_STRING' => '',
    'REMOTE_ADDR' => '127.0.0.1',
    'GDWB_RAW_REQUEST_BODY' => json_encode([
        'text' => 'Please execute workflow and trace this request',
        'conversationId' => 'test-conv-001',
        'sessionId' => 'test-session',
    ]),
];

$response = runGatewayServer();

var_dump($response);
