<?php

require_once __DIR__ . '/../PromptOptimizationStrategy.php';
require_once __DIR__ . '/../PromptOptimizationResult.php';

class VllmProviderPromptOptimizer implements PromptOptimizationStrategy
{
    public function optimize(array $data, ModelProviderInterface $provider, ?PromptOptimizationResult $previous = null): PromptOptimizationResult
    {
        $prompt = $previous?->getPrompt() ?? ($data['message'] ?? '');
        $metadata = $previous?->getMetadata() ?? [];
        $metadata['provider'] = 'vllm';
        $metadata['stage'] = 'provider-vllm';
        return new PromptOptimizationResult($prompt, 'plain', $metadata);
    }

    public function supports(ModelProviderInterface $provider): bool
    {
        $capabilities = $provider->capabilities();
        if (is_array($capabilities) && isset($capabilities['provider'])) {
            return strtolower((string)$capabilities['provider']) === 'vllm';
        }

        return false;
    }
}
