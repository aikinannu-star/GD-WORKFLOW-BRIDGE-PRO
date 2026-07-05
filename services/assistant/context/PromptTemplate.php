<?php

class PromptTemplate
{
    public function render(array $data): string
    {
        $parts = [
            $data['instructions'] ?? 'Assistant: process a user message using available tools.',
            'AssistantId: ' . ($data['assistantId'] ?? ''),
            'SessionId: ' . ($data['sessionId'] ?? ''),
            'UserId: ' . ($data['userId'] ?? ''),
            'Message: ' . ($data['message'] ?? ''),
        ];

        foreach ($data['sections'] ?? [] as $section) {
            $label = $section['label'] ?? 'Context';
            $content = $section['content'] ?? '';
            if ($content === '') {
                continue;
            }
            $parts[] = $label . ':';
            $parts[] = $content;
        }

        if (!empty($data['toolResult'])) {
            $parts[] = 'Tool result: ' . json_encode($data['toolResult']);
        }

        return implode("\n", $parts);
    }
}
