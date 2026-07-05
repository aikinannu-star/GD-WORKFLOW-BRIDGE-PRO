<?php

require_once __DIR__ . '/ContextSourceInterface.php';
require_once __DIR__ . '/../AssistantContext.php';
require_once __DIR__ . '/../memory/MemoryRetrievalService.php';

class MemoryContextSource implements ContextSourceInterface
{
    private ?MemoryRetrievalService $memoryRetrievalService;

    public function __construct(?MemoryRetrievalService $memoryRetrievalService = null)
    {
        $this->memoryRetrievalService = $memoryRetrievalService;
    }

    public function collect(AssistantContext $context, string $message): array
    {
        if ($this->memoryRetrievalService === null || empty($context->userId)) {
            return [];
        }

        $memories = $this->memoryRetrievalService->retrieve($context->userId, $context->tenantId ?? 'default', $message, 3);
        if (empty($memories)) {
            return [];
        }

        $lines = array_map(static function ($memory): string {
            return '- ' . $memory->content;
        }, $memories);

        return [[
            'name' => 'memory',
            'label' => 'Relevant memories',
            'priority' => 80,
            'content' => implode("\n", $lines),
            'metadata' => [],
        ]];
    }
}
