<?php

require_once __DIR__ . '/ContextSourceInterface.php';
require_once __DIR__ . '/../AssistantContext.php';

class SummaryContextSource implements ContextSourceInterface
{
    public function collect(AssistantContext $context, string $message): array
    {
        $summary = $context->metadata['summary'] ?? $context->get('summary');
        if (empty($summary)) {
            return [];
        }

        return [[
            'name' => 'summary',
            'label' => 'Summary',
            'priority' => 90,
            'content' => (string)$summary,
            'metadata' => [],
        ]];
    }
}
