<?php

require_once __DIR__ . '/../PromptOptimizationStrategy.php';
require_once __DIR__ . '/../PromptOptimizationResult.php';

class LlamaPromptOptimizer implements PromptOptimizationStrategy
{
    public function optimize(array $data, ModelProviderInterface $provider, ?PromptOptimizationResult $previous = null): PromptOptimizationResult
    {
        $basePrompt = $previous?->getPrompt() ?? '';
        $prompt = $basePrompt === '' ? ($data['message'] ?? '') : $basePrompt;
        $lines = preg_split('/\r\n|\n/', $prompt) ?: [];
        $normalized = [];
        $normalized[] = 'System: You are a helpful assistant.';
        $normalized[] = 'User: ' . ($data['message'] ?? '');

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (str_starts_with(trim($line), 'System:') || str_starts_with(trim($line), 'User:')) {
                continue;
            }
            $normalized[] = 'Context: ' . $line;
        }

        $metadata = $previous?->getMetadata() ?? [];
        $metadata['stage'] = 'model-llama';
        return new PromptOptimizationResult(implode("\n", $normalized), 'plain', $metadata);
    }

    public function supports(ModelProviderInterface $provider): bool
    {
        $capabilities = $provider->capabilities();
        if (is_array($capabilities) && isset($capabilities['model'])) {
            $modelName = strtolower((string)$capabilities['model']);
            return str_contains($modelName, 'llama');
        }

        return false;
    }
}
