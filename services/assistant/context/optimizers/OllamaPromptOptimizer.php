<?php

require_once __DIR__ . '/../PromptOptimizationStrategy.php';
require_once __DIR__ . '/../PromptOptimizationResult.php';

class OllamaPromptOptimizer implements PromptOptimizationStrategy
{
    public function optimize(array $data, ModelProviderInterface $provider): PromptOptimizationResult
    {
        $prompt = [
            'System: You are a helpful assistant.',
            'User: ' . ($data['message'] ?? ''),
        ];

        foreach ($data['sections'] ?? [] as $section) {
            $content = $section['content'] ?? '';
            if ($content === '') {
                continue;
            }
            $prompt[] = 'Context: ' . ($section['label'] ?? 'Context') . ' - ' . $content;
        }

        return new PromptOptimizationResult(implode("\n", $prompt), 'plain', ['provider' => 'ollama', 'style' => 'plain']);
    }

    public function supports(ModelProviderInterface $provider): bool
    {
        $capabilities = $provider->capabilities();
        if (is_array($capabilities) && isset($capabilities['provider'])) {
            return strtolower((string)$capabilities['provider']) === 'ollama';
        }

        $class = get_class($provider);
        return str_contains($class, 'Ollama');
    }
}
