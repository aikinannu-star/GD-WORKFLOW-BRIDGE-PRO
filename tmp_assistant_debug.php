<?php
require_once __DIR__ . '/services/assistant/RuntimeBootstrap.php';

$traceProvider = new class implements ModelProviderInterface {
    public array $receivedOptions = [];
    public function chat(string $prompt, array $options = []): array {
        $this->receivedOptions = $options;
        return ['success' => true, 'text' => '{"payload":{"assistant":"ok"}}', 'raw' => null, 'error' => null];
    }
    public function stream(string $prompt, array $options = []): iterable { yield ['text' => '']; }
    public function embeddings(string $input, array $options = []): array { return ['vector' => []]; }
    public function health(): array { return ['status' => 'ok']; }
    public function capabilities(): array { return ['chat' => true, 'stream' => true, 'embeddings' => true, 'health' => true]; }
};

$runtime = RuntimeBootstrap::bootstrap(['dispatcher_plugins_path' => __DIR__ . '/services/dispatcher/plugins', 'model_provider' => $traceProvider]);
$assistantManager = $runtime['assistantManager'];
var_export($assistantManager->listAssistants());
echo "\n";
$result = $assistantManager->handle('support-assistant', [
    'message' => 'Please execute workflow and trace this request',
    'conversationId' => 'test-conv-001',
    'sessionId' => 'test-session',
    'tenantId' => 'tenant-allowed',
    'userId' => 'gateway-client',
]);
var_export($result);
echo "\n";
