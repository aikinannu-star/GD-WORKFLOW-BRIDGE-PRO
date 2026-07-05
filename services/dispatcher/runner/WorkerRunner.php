<?php
require_once __DIR__ . '/../workers/WorkerManager.php';

class WorkerRunner
{
    private $workerManager;
    private $intervalSeconds;
    private $shutdown = false;

    public function __construct(WorkerManager $workerManager, float $intervalSeconds = 1.0)
    {
        $this->workerManager = $workerManager;
        $this->intervalSeconds = $intervalSeconds;
    }

    public function runLoop(int $iterations = 1, ?float $intervalSeconds = null): array
    {
        $results = [];
        $interval = $intervalSeconds === null ? $this->intervalSeconds : $intervalSeconds;

        for ($i = 0; $i < $iterations; $i++) {
            if ($this->shutdown) {
                break;
            }
            $results[] = $this->tick();
            if ($interval > 0 && $i < $iterations - 1) {
                usleep((int) ($interval * 1000000));
            }
        }

        return $results;
    }

    public function tick(): array
    {
        return $this->workerManager->tick();
    }

    public function shutdown(): void
    {
        $this->shutdown = true;
    }
}
