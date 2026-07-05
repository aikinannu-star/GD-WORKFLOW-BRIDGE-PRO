<?php

require_once __DIR__ . '/../AssistantContext.php';
require_once __DIR__ . '/../ModelProviderInterface.php';
require_once __DIR__ . '/../RuntimeServiceRegistry.php';
require_once __DIR__ . '/../context/ProviderRouter.php';
require_once __DIR__ . '/../context/RuntimeExecutionContext.php';
require_once __DIR__ . '/../execution/AssistantExecutionPipeline.php';
require_once __DIR__ . '/../../dispatcher/events/RuntimeEventEmitter.php';

class ToolLessProvider implements ModelProviderInterface
{
    public function chat(string $prompt, array $options = []): array { return ['success' => true, 'text' => 'tool-less']; }
    public function stream(string $prompt, array $options = []): iterable { yield ['success' => true, 'text' => 'tool-less']; }
    public function embeddings(string $input, array $options = []): array { return ['vector' => []]; }
    public function health(): array { return ['success' => true]; }
    public function capabilities(): array
    {
        return [
            'provider' => 'local',
            'model' => 'local',
            'modelFamily' => 'local',
            'contextWindow' => 1024,
            'supportsToolCalling' => false,
        ];
    }
}

class ToolCapableProvider implements ModelProviderInterface
{
    public function chat(string $prompt, array $options = []): array { return ['success' => true, 'text' => 'tool-capable']; }
    public function stream(string $prompt, array $options = []): iterable { yield ['success' => true, 'text' => 'tool-capable']; }
    public function embeddings(string $input, array $options = []): array { return ['vector' => []]; }
    public function health(): array { return ['success' => true]; }
    public function capabilities(): array
    {
        return [
            'provider' => 'ollama',
            'model' => 'llama',
            'modelFamily' => 'llama',
            'contextWindow' => 8192,
            'supportsToolCalling' => true,
        ];
    }
}

$router = new ProviderRouter();
$router->register('local', new ToolLessProvider());
$router->register('ollama', new ToolCapableProvider());

$serviceRegistry = new RuntimeServiceRegistry(null, new RuntimeEventEmitter(), null, null, null, null, $router);
$providerRoutingStage = new ProviderRoutingStage($serviceRegistry->providerServices());

$context = new RuntimeExecutionContext(
    new AssistantContext('assistant', 'conversation', 'session', 'tenant', 'user'),
    null,
    null,
    ['message' => 'Run workflow'],
    [],
    [],
    [],
    [],
    null
);
$context->setToolPlan(['toolId' => 'workflow_execute', 'arguments' => ['workflowId' => 'default', 'input' => ['query' => 'test']]]);

$context = $providerRoutingStage->execute($context);

if (!($context->getProvider() instanceof ToolCapableProvider)) {
    echo 'Expected provider routing to select a tool-capable provider when tool support is required' . PHP_EOL;
    exit(1);
}

$providerInfo = $context->getProviderInfo();
if ($providerInfo === null || !$providerInfo->supportsTools()) {
    echo 'Expected provider info to expose supported tools capability after routing' . PHP_EOL;
    exit(1);
}

echo 'Provider routing capability test passed' . PHP_EOL;
