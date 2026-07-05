<?php

require_once __DIR__ . '/ContextSourceInterface.php';
require_once __DIR__ . '/../AssistantContext.php';

class ConversationContextSource implements ContextSourceInterface
{
    public function collect(AssistantContext $context, string $message): array
    {
        $history = $context->metadata['conversation_history'] ?? [];
        if (empty($history)) {
            return [];
        }

        $entries = array_slice(array_map(static function ($entry): string {
            return trim((string)$entry);
        }, (array)$history), -3);

        return [[
            'name' => 'conversation',
            'label' => 'Recent conversation',
            'priority' => 100,
            'content' => implode("\n", array_filter($entries)),
            'metadata' => [],
        ]];
    }
}
