<?php

require_once __DIR__ . '/../PromptOptimizationStrategy.php';
require_once __DIR__ . '/../PromptOptimizationResult.php';

class GeminiPromptOptimizer implements PromptOptimizationStrategy
{
    public function optimize(array $data, ModelProviderInterface $provider): PromptOptimizationResult
    {
        $prompt = [
            'SYSTEM: You are a helpful assistant.',
            'USER: ' . ($data['message'] ?? ''),
        ];

        foreach ($data['sections'] ?? [] as $section) {
            $content = $section['content'] ?? '';
            if ($content === '') {
                continue;
            }
            $prompt[] = 'CONTEXT ' . ($section['label'] ?? 'Context') . ': ' . $content;
        }

        return new PromptOptimizationResult(implode("\n", $prompt), 'structured', ['provider' => 'gemini', 'style' => 'structured']);
    }

    public function supports(ModelProviderInterface $provider): bool
    {
        $capabilities = $provider->capabilities();
        if (is_array($capabilities) && isset($capabilities['provider'])) {
            return strtolower((string)$capabilities['provider']) === 'gemini';
        }

        $class = get_class($provider);
        return str_contains($class, 'Gemini');
    }
}
