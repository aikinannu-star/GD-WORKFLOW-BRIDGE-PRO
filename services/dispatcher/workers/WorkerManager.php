<?php
require_once __DIR__ . '/SchedulerWorker.php';
require_once __DIR__ . '/WorkerRegistry.php';

class WorkerManager
{
    private $workers = [];

    public function __construct($workers = [])
    {
        if ($workers instanceof WorkerRegistry) {
            $this->workers = array_values($workers->all());
            return;
        }

        $this->workers = is_array($workers) ? $workers : [];
    }

    public function register($worker): void
    {
        $this->workers[] = $worker;
    }

    public function tick(): array
    {
        $results = [];
        foreach ($this->workers as $worker) {
            if (!method_exists($worker, 'runOnce')) {
                continue;
            }

            try {
                $results[] = [
                    'worker' => get_class($worker),
                    'result' => $worker->runOnce(),
                ];
            } catch (Throwable $e) {
                $results[] = [
                    'worker' => get_class($worker),
                    'error' => $e->getMessage(),
                ];
            }
        }
        return $results;
    }

    public function runAll(): array
    {
        return $this->tick();
    }
}
