<?php

require_once __DIR__ . '/../context/PromptOptimizationPipeline.php';
require_once __DIR__ . '/../context/PromptOptimizationStageInterface.php';
require_once __DIR__ . '/../context/PromptContext.php';
require_once __DIR__ . '/../context/ProviderInfo.php';
require_once __DIR__ . '/../context/ModelProfile.php';
require_once __DIR__ . '/../ModelProviderInterface.php';

class TestMetadataProvider implements ModelProviderInterface
{
    public function chat(string $prompt, array $options = []): array
    {
        return ['success' => true, 'text' => 'ok'];
    }

    public function stream(string $prompt, array $options = []): iterable
    {
        yield ['success' => true, 'text' => 'ok'];
    }

    public function embeddings(string $input, array $options = []): array
    {
        return ['vector' => []];
    }

    public function health(): array
    {
        return ['success' => true];
    }

    public function capabilities(): array
    {
        return [
            'provider' => 'ollama',
            'model' => 'llama3.1:8b',
            'modelFamily' => 'llama',
            'contextWindow' => 32768,
            'supportsTools' => true,
            'supportsVision' => false,
            'supportsJson' => true,
            'supportsEmbeddings' => true,
        ];
    }
}

class TestPluginStage implements PromptOptimizationStageInterface
{
    public function supports(AssistantContext $context, ModelProviderInterface $provider, ?ProviderInfo $providerInfo = null, ?ModelProfile $modelProfile = null): bool
    {
        return $providerInfo !== null && $providerInfo->getProvider() === 'ollama' && $modelProfile !== null && $modelProfile->getFamily() === 'llama';
    }

    public function optimize(PromptContext $prompt): PromptContext
    {
        $prompt->appendContent("\n[plugin-stage]");
        $prompt->recordChange('TestPluginStage', 'added plugin marker');
        return $prompt;
    }

    public function priority(): int
    {
        return 150;
    }
}

$pipeline = new PromptOptimizationPipeline([], new TestMetadataProvider());
$pipeline->registerStage(new TestPluginStage());

$context = new AssistantContext('assistant', 'conv', 'session', 'tenant', 'user');
$prompt = new PromptContext([
    'instructions' => 'Assistant: help',
    'assistantId' => 'assistant',
    'sessionId' => 'session',
    'userId' => 'user',
    'message' => 'Hello',
    'sections' => [],
    'toolResult' => null,
], null, null);

$result = $pipeline->optimizeContext($prompt, $context);
if (strpos($result->getContent(), '[plugin-stage]') === false) {
    echo 'Expected plugin stage to be applied' . PHP_EOL;
    exit(1);
}

if (count($result->getAuditEntries()) === 0) {
    echo 'Expected pipeline to record stage changes' . PHP_EOL;
    exit(1);
}

echo 'Prompt optimization pipeline refinement test passed' . PHP_EOL;
