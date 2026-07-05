<?php

require_once __DIR__ . '/ContextSourceInterface.php';
require_once __DIR__ . '/ContextSourceRegistry.php';
require_once __DIR__ . '/ConversationContextSource.php';
require_once __DIR__ . '/MemoryContextSource.php';
require_once __DIR__ . '/SummaryContextSource.php';
require_once __DIR__ . '/WorkflowContextSource.php';
require_once __DIR__ . '/DocumentContextSource.php';
require_once __DIR__ . '/ContextBudgeter.php';
require_once __DIR__ . '/summary/ContextSummarizer.php';
require_once __DIR__ . '/../AssistantContext.php';

class ContextAssembler
{
    private array $sources;
    private ContextBudgeter $budgeter;
    private ContextSummarizer $summarizer;

    public function __construct(?MemoryRetrievalService $memoryRetrievalService = null, array $sources = [], ?ContextSourceRegistry $registry = null, ?ContextBudgeter $budgeter = null, ?ContextSummarizer $summarizer = null)
    {
        $this->budgeter = $budgeter ?? new ContextBudgeter();
        $this->summarizer = $summarizer ?? new ContextSummarizer();

        if (!empty($sources)) {
            $this->sources = $sources;
            return;
        }

        if ($registry !== null) {
            $this->sources = $registry->all();
            return;
        }

        $registry = new ContextSourceRegistry();
        $registry->register('conversation', new ConversationContextSource(), 100);
        $registry->register('memory', new MemoryContextSource($memoryRetrievalService), 80);
        $registry->register('summary', new SummaryContextSource(), 90);
        $registry->register('workflow', new WorkflowContextSource(), 70);
        $registry->register('documents', new DocumentContextSource(), 60);
        $this->sources = $registry->all();
    }

    public function assemble(AssistantContext $context, string $message): array
    {
        $sections = [];
        foreach ($this->sources as $source) {
            $result = $source->collect($context, $message);
            foreach ($result as $section) {
                if (!empty($section['content'])) {
                    $sections[] = [
                        'name' => $section['name'] ?? 'context',
                        'label' => $section['label'] ?? 'Context',
                        'priority' => $section['priority'] ?? 0,
                        'content' => $section['content'],
                        'metadata' => $section['metadata'] ?? [],
                    ];
                }
            }
        }

        usort($sections, static function (array $a, array $b): int {
            return ($b['priority'] <=> $a['priority']);
        });

        $options = [
            'maxTokens' => $context->metadata['contextTokenBudget'] ?? null,
            'reserveTokens' => $context->metadata['contextReserveTokens'] ?? null,
        ];
        $budgeted = $this->budgeter->budget($sections, $message, array_filter($options, static function ($value): bool {
            return $value !== null;
        }));

        $skippedSections = $budgeted['metadata']['skippedSections'] ?? [];
        if (!empty($skippedSections)) {
            $summary = $this->summarizer->summarize($skippedSections, $message, [
                'maxTokens' => $options['maxTokens'] ?? null,
            ]);
            $summarySection = [
                'name' => 'summary-fallback',
                'label' => 'Compressed context',
                'priority' => 55,
                'content' => $summary,
                'metadata' => ['summary' => true],
            ];
            $rebudgeted = $this->budgeter->budget(array_merge($budgeted['sections'], [$summarySection]), $message, array_filter($options, static function ($value): bool {
                return $value !== null;
            }));
            return $rebudgeted['sections'];
        }

        return $budgeted['sections'];
    }
}
