<?php

require_once __DIR__ . '/ContextSourceInterface.php';
require_once __DIR__ . '/../AssistantContext.php';

class DocumentContextSource implements ContextSourceInterface
{
    public function collect(AssistantContext $context, string $message): array
    {
        $documents = $context->get('documents');
        if (empty($documents) || !is_array($documents)) {
            return [];
        }

        $lines = [];
        foreach ($documents as $document) {
            if (!is_array($document)) {
                continue;
            }

            $title = $document['title'] ?? 'Document';
            $content = $document['content'] ?? '';
            $lines[] = $title . ': ' . $content;
        }

        if (empty($lines)) {
            return [];
        }

        return [[
            'name' => 'documents',
            'label' => 'Document context',
            'priority' => 60,
            'content' => implode("\n", $lines),
            'metadata' => [],
        ]];
    }
}
