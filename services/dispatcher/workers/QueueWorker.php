<?php
require_once __DIR__ . '/../queue/ExecutionQueue.php';

class QueueWorker
{
    private $queue;
    private $processor;

    public function __construct(ExecutionQueue $queue = null, callable $processor = null)
    {
        $this->queue = $queue ?: new ExecutionQueue();
        $this->processor = $processor;
    }

    public function runOnce(): array
    {
        $item = $this->queue->dequeue();
        if ($item === null) {
            return ['processed' => 0];
        }

        if ($this->processor !== null) {
            $result = call_user_func($this->processor, $item);
            $this->queue->ack($item['id'] ?? '');
            return ['processed' => 1, 'result' => $result];
        }

        $this->queue->ack($item['id'] ?? '');
        return ['processed' => 1, 'item' => $item];
    }
}
