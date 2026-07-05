<?php

require_once __DIR__ . '/../PromptOptimizationStrategy.php';
require_once __DIR__ . '/../PromptOptimizationResult.php';

class GenericPromptOptimizer implements PromptOptimizationStrategy
{
    public function optimize(array $data, ModelProviderInterface $provider, ?PromptOptimizationResult $previous = null): PromptOptimizationResult
    {
        $prompt = [
            $data['instructions'] ?? 'Assistant: process a user message using available tools.',
            'AssistantId: ' . ($data['assistantId'] ?? ''),
            'SessionId: ' . ($data['sessionId'] ?? ''),
            'UserId: ' . ($data['userId'] ?? ''),
            'Message: ' . ($data['message'] ?? ''),
        ];

        foreach ($data['sections'] ?? [] as $section) {
            $content = $section['content'] ?? '';
            if ($content === '') {
                continue;
            }
            $prompt[] = ($section['label'] ?? 'Context') . ':';
            $prompt[] = $content;
        }

        if (!empty($data['toolResult'])) {
            $prompt[] = 'Tool result: ' . json_encode($data['toolResult']);
        }

        return new PromptOptimizationResult(implode("\n", $prompt), 'plain', [
            'provider' => $this->detectProviderName($provider),
            'stage' => 'generic',
        ]);
    }

    public function supports(ModelProviderInterface $provider): bool
    {
        return true;
    }

    private function detectProviderName(ModelProviderInterface $provider): string
    {
        $capabilities = $provider->capabilities();
        if (is_array($capabilities) && isset($capabilities['provider'])) {
            return (string)$capabilities['provider'];
        }

        return 'local';
    }
}
