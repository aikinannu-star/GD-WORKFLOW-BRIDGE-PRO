<?php
require_once __DIR__ . '/../services/WorkflowExecutionService.php';
require_once __DIR__ . '/../queue/FileQueue.php';
require_once __DIR__ . '/../locking/MemoryLockProvider.php';
require_once __DIR__ . '/../metrics/MemoryMetricsCollector.php';
require_once __DIR__ . '/../runtime/ExecutionContext.php';

class ExecutionCoordinator
{
    private $executionService;
    private $queue;
    private $lockStore;
    private $metrics;

    public function __construct($executionService = null, QueueInterface $queue = null, LockProviderInterface $lockStore = null, MetricsCollectorInterface $metrics = null)
    {
        $this->executionService = $executionService ?: new WorkflowExecutionService();
        $this->queue = $queue ?: new FileQueue();
        $this->lockStore = $lockStore ?: new MemoryLockProvider();
        $this->metrics = $metrics ?: new MemoryMetricsCollector();
    }

    public function enqueueExecution(array $workflowRecord, array $input = []): array
    {
        $item = [
            'workflow' => $workflowRecord,
            'input' => $input,
            'queuedAt' => (new DateTime('now', new DateTimeZone('UTC')))->format(DateTime::ATOM),
        ];
        $id = $this->queue->enqueue($item);
        $this->metrics->increment('execution.enqueued');
        return ['queueId' => $id];
    }

    public function processNext(): array
    {
        $item = $this->queue->dequeue();
        if ($item === null) {
            return ['status' => 'empty'];
        }

        $workflowRecord = $item['workflow'] ?? null;
        if (!is_array($workflowRecord)) {
            $this->queue->ack($item['id'] ?? '');
            return ['status' => 'invalid'];
        }

        $workflowId = $workflowRecord['id'] ?? ($workflowRecord['workflow']['id'] ?? 'unknown');
        $lockKey = 'workflow:' . $workflowId;
        if (!$this->lockStore->acquire($lockKey)) {
            $this->queue->ack($item['id'] ?? '');
            return ['status' => 'locked'];
        }

        try {
            $this->metrics->increment('execution.started');
            $result = $this->executionService->execute($workflowRecord, $item['input'] ?? []);
            $this->metrics->increment('execution.completed');
            return ['status' => 'processed', 'result' => $result];
        } finally {
            $this->lockStore->release($lockKey);
            $this->queue->ack($item['id'] ?? '');
        }
    }

    public function getMetrics(): array
    {
        return $this->metrics->snapshot();
    }
}
