<?php

require_once __DIR__ . '/../AssistantContext.php';
require_once __DIR__ . '/../ToolInterface.php';
require_once __DIR__ . '/../ToolRegistry.php';
require_once __DIR__ . '/../ModelProviderInterface.php';
require_once __DIR__ . '/../AssistantRuntime.php';
require_once __DIR__ . '/../RuntimeServiceRegistry.php';
require_once __DIR__ . '/../context/RuntimeExecutionContext.php';
require_once __DIR__ . '/../execution/AssistantExecutionPipeline.php';
require_once __DIR__ . '/../execution/RuntimeExecutionResult.php';
require_once __DIR__ . '/../../dispatcher/events/RuntimeEventEmitter.php';

class PermissionTool implements ToolInterface
{
    public function id(): string { return 'perm_echo'; }
    public function name(): string { return 'Perm Echo'; }
    public function description(): string { return 'Echo with permission requirements'; }
    public function inputSchema(): array { return []; }
    public function execute(array $args): array { return ['success' => true, 'result' => $args]; }
}

class DummyProvider implements ModelProviderInterface
{
    public function chat(string $prompt, array $options = []): array { return ['success' => true, 'text' => 'ok']; }
    public function stream(string $prompt, array $options = []): iterable { yield ['success' => true, 'text' => 'ok']; }
    public function embeddings(string $input, array $options = []): array { return ['vector' => []]; }
    public function health(): array { return ['success' => true]; }
    public function capabilities(): array { return ['provider' => 'ollama', 'model' => 'llama', 'supportsTools' => true]; }
}

$toolRegistry = new ToolRegistry();
$toolRegistry->registerTool(new PermissionTool());
$provider = new DummyProvider();
$serviceRegistry = new RuntimeServiceRegistry($provider, new RuntimeEventEmitter(), $toolRegistry);
$runtime = new AssistantRuntime($serviceRegistry);

// Disallow tools globally
$context = new RuntimeExecutionContext(new AssistantContext('assistant','conv','sess','tenant','user'), null, null, ['message' => 'Run tool'], [], [], [], ['allowTools' => false], null);
$context->setToolPlan(['toolId' => 'perm_echo', 'arguments' => []]);
$result = $runtime->execute($context);
$toolResults = $context->getToolResults();
if (($toolResults['success'] ?? true) !== false) {
    echo 'Expected tool execution to be forbidden when allowTools is false' . PHP_EOL;
    exit(1);
}

// Allow only specific tools
$context2 = new RuntimeExecutionContext(new AssistantContext('assistant','conv','sess','tenant','user'), null, null, ['message' => 'Run tool'], [], [], [], ['allowedToolIds' => ['other_tool']], null);
$context2->setToolPlan(['toolId' => 'perm_echo', 'arguments' => []]);
$result2 = $runtime->execute($context2);
$toolResults2 = $context2->getToolResults();
if (($toolResults2['success'] ?? true) !== false) {
    echo 'Expected tool execution to be forbidden when tool id not in allowedToolIds' . PHP_EOL;
    exit(1);
}

echo 'Tool permission tests passed' . PHP_EOL;
