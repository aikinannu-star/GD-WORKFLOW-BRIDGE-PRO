<?php

require_once __DIR__ . '/../PromptOptimizationStrategy.php';
require_once __DIR__ . '/../PromptOptimizationResult.php';

class OllamaProviderPromptOptimizer implements PromptOptimizationStrategy
{
    public function optimize(array $data, ModelProviderInterface $provider, ?PromptOptimizationResult $previous = null): PromptOptimizationResult
    {
        $prompt = $previous?->getPrompt() ?? ($data['message'] ?? '');
        $metadata = $previous?->getMetadata() ?? [];
        $metadata['provider'] = 'ollama';
        $metadata['stage'] = 'provider-ollama';
        return new PromptOptimizationResult($prompt, 'plain', $metadata);
    }

    public function supports(ModelProviderInterface $provider): bool
    {
        $capabilities = $provider->capabilities();
        if (is_array($capabilities) && isset($capabilities['provider'])) {
            return strtolower((string)$capabilities['provider']) === 'ollama';
        }

        return false;
    }
}
