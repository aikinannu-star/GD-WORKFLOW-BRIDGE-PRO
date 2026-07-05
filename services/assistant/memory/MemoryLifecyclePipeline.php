<?php

require_once __DIR__ . '/MemoryRecord.php';
require_once __DIR__ . '/MemoryStore.php';
require_once __DIR__ . '/MemoryLifecycleStageInterface.php';
require_once __DIR__ . '/MemoryAnalytics.php';
require_once __DIR__ . '/../../dispatcher/events/RuntimeEventEmitter.php';

class MemoryLifecyclePipeline
{
    private MemoryStore $memoryStore;
    private array $stages;
    private ?MemoryAnalytics $analytics;
    private ?RuntimeEventEmitter $eventEmitter;

    public function __construct(MemoryStore $memoryStore, array $stages = [], ?MemoryAnalytics $analytics = null, ?RuntimeEventEmitter $eventEmitter = null)
    {
        $this->memoryStore = $memoryStore;
        $this->analytics = $analytics;
        $this->eventEmitter = $eventEmitter;
        $this->stages = $stages !== [] ? $stages : [
            new MemoryNormalizeLifecycleStage(),
            new MemoryConfidenceLifecycleStage(),
            new MemoryPersistLifecycleStage($memoryStore),
        ];
    }

    public function process(MemoryRecord $record, array $context = []): MemoryRecord
    {
        $this->recordAnalytics('memory.lifecycle.started', ['context' => $context, 'record' => $record->toArray()]);
        $this->emit('memory.lifecycle.started', ['context' => $context, 'record' => $record->toArray()]);

        $record->metadata['pipelineContext'] = $context;
        $record->metadata['pipelineStages'] = [];

        foreach ($this->stages as $index => $stage) {
            $record = $stage->process($record, array_merge($context, ['stageIndex' => $index]));
            $record->metadata['pipelineStages'][] = $this->stageName($stage);
            $this->recordAnalytics('memory.lifecycle.stage.completed', [
                'stage' => $this->stageName($stage),
                'context' => $context,
                'record' => $record->toArray(),
            ]);
            $this->emit('memory.lifecycle.stage.completed', [
                'stage' => $this->stageName($stage),
                'context' => $context,
                'record' => $record->toArray(),
            ]);
        }

        $this->recordAnalytics('memory.lifecycle.completed', ['context' => $context, 'record' => $record->toArray()]);
        $this->emit('memory.lifecycle.completed', ['context' => $context, 'record' => $record->toArray()]);

        return $record;
    }

    public function registerStage(MemoryLifecycleStageInterface $stage): void
    {
        $this->stages[] = $stage;
    }

    private function stageName($stage): string
    {
        if (is_object($stage) && method_exists($stage, 'getName')) {
            return $stage->getName();
        }

        return is_object($stage) ? get_class($stage) : 'unknown';
    }

    private function recordAnalytics(string $event, array $payload = []): void
    {
        if ($this->analytics !== null) {
            $this->analytics->record($event, $payload);
        }
    }

    private function emit(string $event, array $payload = []): void
    {
        if ($this->eventEmitter !== null) {
            $this->eventEmitter->emit($event, $payload);
        }
    }
}

class MemoryNormalizeLifecycleStage implements MemoryLifecycleStageInterface
{
    public function process(MemoryRecord $record, array $context = []): MemoryRecord
    {
        $record->content = trim($record->content);
        $record->metadata['normalizedAt'] = (new DateTime('now', new DateTimeZone('UTC')))->format(DateTime::ATOM);
        $record->metadata['normalized'] = true;
        return $record;
    }
}

class MemoryConfidenceLifecycleStage implements MemoryLifecycleStageInterface
{
    public function process(MemoryRecord $record, array $context = []): MemoryRecord
    {
        $record->confidence = max(0.0, min(1.0, $record->confidence));
        $record->metadata['confidenceScoredAt'] = (new DateTime('now', new DateTimeZone('UTC')))->format(DateTime::ATOM);
        return $record;
    }
}

class MemoryPersistLifecycleStage implements MemoryLifecycleStageInterface
{
    private ?MemoryStore $memoryStore;

    public function __construct(?MemoryStore $memoryStore = null)
    {
        $this->memoryStore = $memoryStore;
    }

    public function process(MemoryRecord $record, array $context = []): MemoryRecord
    {
        if ($this->memoryStore === null) {
            return $record;
        }

        $record->metadata['persistedAt'] = (new DateTime('now', new DateTimeZone('UTC')))->format(DateTime::ATOM);
        return $this->memoryStore->add($record);
    }
}
