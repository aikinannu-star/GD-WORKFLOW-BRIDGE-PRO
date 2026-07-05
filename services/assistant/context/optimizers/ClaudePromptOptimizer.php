<?php

require_once __DIR__ . '/../PromptOptimizationStrategy.php';
require_once __DIR__ . '/../PromptOptimizationResult.php';

class ClaudePromptOptimizer implements PromptOptimizationStrategy
{
    public function optimize(array $data, ModelProviderInterface $provider): PromptOptimizationResult
    {
        $prompt = [
            '<system>You are a helpful assistant.</system>',
            '<user>' . ($data['message'] ?? '') . '</user>',
        ];

        foreach ($data['sections'] ?? [] as $section) {
            $content = $section['content'] ?? '';
            if ($content === '') {
                continue;
            }
            $prompt[] = '<context>' . ($section['label'] ?? 'Context') . ': ' . $content . '</context>';
        }

        return new PromptOptimizationResult(implode("\n", $prompt), 'xml', ['provider' => 'claude', 'style' => 'xml']);
    }

    public function supports(ModelProviderInterface $provider): bool
    {
        $capabilities = $provider->capabilities();
        if (is_array($capabilities) && isset($capabilities['provider'])) {
            return strtolower((string)$capabilities['provider']) === 'claude';
        }

        $class = get_class($provider);
        return str_contains($class, 'Claude');
    }
}
