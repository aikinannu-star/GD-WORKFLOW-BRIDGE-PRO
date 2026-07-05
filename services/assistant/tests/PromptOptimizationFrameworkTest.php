<?php

require_once __DIR__ . '/../context/PromptOptimizationPipeline.php';
require_once __DIR__ . '/../context/PipelineReport.php';
require_once __DIR__ . '/../context/PromptOptimizationStageRegistry.php';
require_once __DIR__ . '/../context/OptimizationReport.php';
require_once __DIR__ . '/../context/PromptContext.php';
require_once __DIR__ . '/../context/ProviderInfo.php';
require_once __DIR__ . '/../context/ModelProfile.php';
require_once __DIR__ . '/../ModelProviderInterface.php';

class TestRegistryProvider implements ModelProviderInterface
{
    public function chat(string $prompt, array $options = []): array { return ['success' => true, 'text' => 'ok']; }
    public function stream(string $prompt, array $options = []): iterable { yield ['success' => true, 'text' => 'ok']; }
    public function embeddings(string $input, array $options = []): array { return ['vector' => []]; }
    public function health(): array { return ['success' => true]; }
    public function capabilities(): array { return ['provider' => 'ollama', 'model' => 'llama3.1:8b', 'modelFamily' => 'llama', 'contextWindow' => 8192, 'supportsTools' => true, 'supportsVision' => false, 'supportsJson' => true, 'supportsEmbeddings' => true]; }
}

class TestRegistryStage implements PromptOptimizationStageInterface
{
    public function supports(AssistantContext $context, ModelProviderInterface $provider, ?ProviderInfo $providerInfo = null, ?ModelProfile $modelProfile = null): bool
    {
        return $providerInfo !== null && $providerInfo->getProvider() === 'ollama';
    }

    public function optimize(PromptContext $prompt): PromptContext
    {
        $prompt->appendContent("\n[registry-stage]");
        $prompt->recordChange('TestRegistryStage', 'registered and applied');
        return $prompt;
    }

    public function priority(): int { return 10; }
}

$registry = new PromptOptimizationStageRegistry();
$registry->register(new TestRegistryStage());

$pipeline = new PromptOptimizationPipeline([], new TestRegistryProvider(), $registry);
$context = new AssistantContext('assistant', 'conversation', 'session', 'tenant', 'user');
$prompt = new PromptContext([
    'instructions' => 'Assistant: help',
    'assistantId' => 'assistant',
    'sessionId' => 'session',
    'userId' => 'user',
    'message' => 'Hello',
    'sections' => [],
    'toolResult' => null,
]);

$result = $pipeline->optimizeContext($prompt, $context);
if (strpos($result->getContent(), '[registry-stage]') === false) {
    echo 'Expected registry-backed stage to be applied' . PHP_EOL;
    exit(1);
}

$activeStages = $registry->getActiveStages($context, new TestRegistryProvider(), new ProviderInfo(['provider' => 'ollama', 'model' => 'llama3.1:8b', 'modelFamily' => 'llama']));
if (count($activeStages) !== 1) {
    echo 'Expected exactly one active stage' . PHP_EOL;
    exit(1);
}

if ($pipeline->getOptimizationReport() === null) {
    echo 'Expected an optimization report to be produced' . PHP_EOL;
    exit(1);
}

if (!($pipeline->getOptimizationReport() instanceof PipelineReport)) {
    echo 'Expected the optimization report to implement PipelineReport' . PHP_EOL;
    exit(1);
}

echo 'Prompt optimization framework test passed' . PHP_EOL;
