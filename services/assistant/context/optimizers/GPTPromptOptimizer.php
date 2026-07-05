<?php

require_once __DIR__ . '/../PromptOptimizationStrategy.php';
require_once __DIR__ . '/../PromptOptimizationResult.php';

class GPTPromptOptimizer implements PromptOptimizationStrategy
{
    public function optimize(array $data, ModelProviderInterface $provider): PromptOptimizationResult
    {
        $prompt = [
            'You are a helpful assistant.',
            'AssistantId: ' . ($data['assistantId'] ?? ''),
            'SessionId: ' . ($data['sessionId'] ?? ''),
            'UserId: ' . ($data['userId'] ?? ''),
            'User message: ' . ($data['message'] ?? ''),
        ];

        foreach ($data['sections'] ?? [] as $section) {
            $content = $section['content'] ?? '';
            if ($content === '') {
                continue;
            }
            $prompt[] = '### ' . ($section['label'] ?? 'Context');
            $prompt[] = $content;
        }

        if (!empty($data['toolResult'])) {
            $prompt[] = '### Tool result';
            $prompt[] = json_encode($data['toolResult']);
        }

        return new PromptOptimizationResult(implode("\n", $prompt), 'markdown', ['provider' => 'gpt', 'style' => 'markdown']);
    }

    public function supports(ModelProviderInterface $provider): bool
    {
        $capabilities = $provider->capabilities();
        if (is_array($capabilities) && isset($capabilities['provider'])) {
            $providerName = strtolower((string)$capabilities['provider']);
            return in_array($providerName, ['gpt', 'openai', 'chatgpt'], true);
        }

        $class = get_class($provider);
        return str_contains($class, 'GPT') || str_contains($class, 'OpenAI');
    }
}
