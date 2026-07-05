<?php

require_once __DIR__ . '/../PromptOptimizationStrategy.php';
require_once __DIR__ . '/../PromptOptimizationResult.php';

class LocalPromptOptimizer implements PromptOptimizationStrategy
{
    public function optimize(array $data, ModelProviderInterface $provider): PromptOptimizationResult
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

        return new PromptOptimizationResult(implode("\n", $prompt), 'plain', ['provider' => 'local', 'style' => 'plain']);
    }

    public function supports(ModelProviderInterface $provider): bool
    {
        return true;
    }
}
