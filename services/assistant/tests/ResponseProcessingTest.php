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

class SimpleTool implements ToolInterface
{
    public function id(): string { return 'simple'; }
    public function name(): string { return 'Simple'; }
    public function description(): string { return 'Simple tool'; }
    public function inputSchema(): array { return []; }
    public function execute(array $args): array { return ['success' => true, 'result' => ['echo' => $args]]; }
}

class JsonProvider implements ModelProviderInterface
{
    public function chat(string $prompt, array $options = []): array { return ['success' => true, 'text' => json_encode(['payload' => ['message' => 'from provider']])]; }
    public function stream(string $prompt, array $options = []): iterable { yield ['success' => true, 'text' => 'ok']; }
    public function embeddings(string $input, array $options = []): array { return ['vector' => []]; }
    public function health(): array { return ['success' => true]; }
    public function capabilities(): array { return ['provider' => 'local', 'model' => 'm', 'supportsTools' => true]; }
}

$toolRegistry = new ToolRegistry();
$toolRegistry->registerTool(new SimpleTool());
$provider = new JsonProvider();
$serviceRegistry = new RuntimeServiceRegistry($provider, new RuntimeEventEmitter(), $toolRegistry);
$runtime = new AssistantRuntime($serviceRegistry);

// Case 1: provider JSON payload
$context1 = new RuntimeExecutionContext(new AssistantContext('assistant','conv','sess','tenant','user'), null, null, ['message' => 'Hello'], [], [], [], [], null);
$result1 = $runtime->execute($context1);
if ($result1->getFinalResponse() !== json_encode(['message' => 'from provider'])) {
    echo 'Expected final response to be provider payload' . PHP_EOL;
    exit(1);
}

// Case 2: tool results preferred
$context2 = new RuntimeExecutionContext(new AssistantContext('assistant','conv','sess','tenant','user'), null, null, ['message' => 'Run tool'], [], [], [], [], null);
$context2->setToolPlan(['toolId' => 'simple', 'arguments' => ['a' => 1]]);
$result2 = $runtime->execute($context2);
if (strpos($result2->getFinalResponse(), 'echo') === false) {
    echo 'Expected final response to include tool result' . PHP_EOL;
    exit(1);
}

echo 'Response processing tests passed' . PHP_EOL;
