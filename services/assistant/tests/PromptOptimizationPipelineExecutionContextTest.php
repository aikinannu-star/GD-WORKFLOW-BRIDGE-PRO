<?php

require_once __DIR__ . '/../context/PromptOptimizationPipeline.php';
require_once __DIR__ . '/../context/PromptOptimizationStageInterface.php';
require_once __DIR__ . '/../context/PromptContext.php';
require_once __DIR__ . '/../context/ProviderInfo.php';
require_once __DIR__ . '/../context/RuntimeExecutionContext.php';
require_once __DIR__ . '/../../dispatcher/events/RuntimeEventEmitter.php';
require_once __DIR__ . '/../ModelProviderInterface.php';

class TestExecutionContextProvider implements ModelProviderInterface
{
    public function chat(string $prompt, array $options = []): array { return ['success' => true, 'text' => 'ok']; }
    public function stream(string $prompt, array $options = []): iterable { yield ['success' => true, 'text' => 'ok']; }
    public function embeddings(string $input, array $options = []): array { return ['vector' => []]; }
    public function health(): array { return ['success' => true]; }
    public function capabilities(): array
    {
        return [
            'provider' => 'ollama',
            'model' => 'llama3.1:8b',
            'modelFamily' => 'llama',
            'contextWindow' => 8192,
            'supportsTools' => true,
            'supportsVision' => false,
            'supportsJson' => true,
            'supportsEmbeddings' => true,
        ];
    }
}

class TestExecutionContextStage implements PromptOptimizationStageInterface
{
    public function supports(AssistantContext $context, ModelProviderInterface $provider, ?ProviderInfo $providerInfo = null, ?ModelProfile $modelProfile = null): bool
    {
        return $providerInfo !== null && $providerInfo->getProvider() === 'ollama';
    }

    public function optimize(PromptContext $prompt): PromptContext
    {
        $prompt->appendContent("\n[event-stage]");
        $prompt->recordChange('TestExecutionContextStage', 'applied during execution');
        return $prompt;
    }

    public function priority(): int { return 5; }
}

$eventEmitter = new RuntimeEventEmitter();
$capturedEvents = [];
$eventEmitter->on('promptOptimization.pipeline.started', function ($payload) use (&$capturedEvents) {
    $capturedEvents[] = ['started' => $payload];
});
$eventEmitter->on('promptOptimization.pipeline.completed', function ($payload) use (&$capturedEvents) {
    $capturedEvents[] = ['completed' => $payload];
});

$provider = new TestExecutionContextProvider();
$pipeline = new PromptOptimizationPipeline([new TestExecutionContextStage()], $provider, null, null, null, null, null, $eventEmitter);
$runtimeContext = new RuntimeExecutionContext(new AssistantContext('assistant', 'conversation', 'session', 'tenant', 'user'));

$prompt = new PromptContext([
    'instructions' => 'Assistant: help',
    'assistantId' => 'assistant',
    'sessionId' => 'session',
    'userId' => 'user',
    'message' => 'Hello',
    'sections' => [],
    'toolResult' => null,
]);

$result = $pipeline->optimizeContext($prompt, $runtimeContext, $provider);

if (strpos($result->getContent(), '[event-stage]') === false) {
    echo 'Expected stage applied with runtime execution context' . PHP_EOL;
    exit(1);
}

if ($pipeline->report() === null) {
    echo 'Expected optimization report to be available' . PHP_EOL;
    exit(1);
}

if (count($capturedEvents) !== 2) {
    echo 'Expected pipeline start and completed events to be emitted' . PHP_EOL;
    exit(1);
}

echo 'Prompt optimization execution context test passed' . PHP_EOL;
