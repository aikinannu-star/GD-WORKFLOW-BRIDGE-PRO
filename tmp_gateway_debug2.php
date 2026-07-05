<?php

define('SERVICE_HELPERS_TEST_MODE', true);
define('GATEWAY_TEST_MODE', true);

require_once __DIR__ . '/services/assistant/RuntimeBootstrap.php';
require_once __DIR__ . '/services/gateway/server.php';

$traceProvider = new class implements ModelProviderInterface {
    public array $receivedOptions = [];
    public function chat(string $prompt, array $options = []): array { $this->receivedOptions = $options; return ['success' => true, 'text' => '{"payload":{"assistant":"ok"}}', 'raw' => null, 'error' => null]; }
    public function stream(string $prompt, array $options = []): iterable { yield ['text' => '']; }
    public function embeddings(string $input, array $options = []): array { return ['vector' => []]; }
    public function health(): array { return ['status' => 'ok']; }
    public function capabilities(): array { return ['chat' => true, 'stream' => true, 'embeddings' => true, 'health' => true]; }
};
$runtime = RuntimeBootstrap::bootstrap(['dispatcher_plugins_path' => __DIR__ . '/services/dispatcher/plugins', 'model_provider' => $traceProvider]);
$assistantManager = $runtime['assistantManager'];

setGatewayProxyHandler(function (string $targetUrl, string $method, array $headers, string $body = null) use ($assistantManager) {
    $headerMap = [];
    foreach ($headers as $line) {
        $parts = explode(':', $line, 2);
        if (count($parts) === 2) {
            $headerMap[trim($parts[0])] = trim($parts[1]);
        }
    }
    if ($method === 'POST' && parse_url($targetUrl, PHP_URL_PATH) === '/api/v1/assistant/sessions/test-session/message') {
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
    'HTTP_X_API_KEY' => 'valid-key-1',
    'HTTP_X_TENANT_ID' => 'tenant-allowed',
    'GDWB_RAW_REQUEST_BODY' => json_encode([
        'text' => 'Please execute workflow and trace this request',
        'conversationId' => 'test-conv-001',
        'sessionId' => 'test-session',
    ]),
];
while (ob_get_level() > 0) { ob_end_clean(); }
try {
    $response = runGatewayServer();
    echo "RESPONSE STATUS: {$response['status']}\n";
    echo "RESPONSE BODY:\n";
    echo $response['body'];
    echo "\n";
    echo "DECODED: ";
    var_export(json_decode($response['body'], true));
    echo "\n";
} catch (Exception $e) {
    echo "EXCEPTION: " . get_class($e) . " - " . $e->getMessage() . "\n";
    if ($e instanceof ServiceHelpersTestResponseException) {
        var_export($e->response);
        echo "\n";
    }
}
