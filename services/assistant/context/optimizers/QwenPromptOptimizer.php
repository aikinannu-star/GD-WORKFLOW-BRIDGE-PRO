<?php

require_once __DIR__ . '/../PromptOptimizationStrategy.php';
require_once __DIR__ . '/../PromptOptimizationResult.php';

class QwenPromptOptimizer implements PromptOptimizationStrategy
{
    public function optimize(array $data, ModelProviderInterface $provider, ?PromptOptimizationResult $previous = null): PromptOptimizationResult
    {
        $basePrompt = $previous?->getPrompt() ?? '';
        $prompt = $basePrompt === '' ? ($data['message'] ?? '') : $basePrompt;
        $lines = preg_split('/\r\n|\n/', $prompt) ?: [];
        $normalized = [];
        $normalized[] = 'SYSTEM: You are a helpful assistant.';
        $normalized[] = 'USER: ' . ($data['message'] ?? '');

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (str_starts_with(trim($line), 'SYSTEM:') || str_starts_with(trim($line), 'USER:')) {
                continue;
            }
            $normalized[] = 'CONTEXT: ' . $line;
        }

        $metadata = $previous?->getMetadata() ?? [];
        $metadata['stage'] = 'model-qwen';
        return new PromptOptimizationResult(implode("\n", $normalized), 'plain', $metadata);
    }

    public function supports(ModelProviderInterface $provider): bool
    {
        $capabilities = $provider->capabilities();
        if (is_array($capabilities) && isset($capabilities['model'])) {
            $modelName = strtolower((string)$capabilities['model']);
            return str_contains($modelName, 'qwen');
        }

        return false;
    }
}
