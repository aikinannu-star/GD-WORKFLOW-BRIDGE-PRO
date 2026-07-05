<?php
require_once __DIR__ . '/../services/SchedulerService.php';
require_once __DIR__ . '/../services/WorkflowTriggerService.php';

class SchedulerWorker
{
    private $schedulerService;
    private $triggerService;

    public function __construct($schedulerService = null, $triggerService = null)
    {
        $this->schedulerService = $schedulerService ?: new SchedulerService();
        $this->triggerService = $triggerService ?: new WorkflowTriggerService();
    }

    public function runOnce(): array
    {
        $results = [];
        $schedules = $this->loadSchedules();
        foreach ($schedules as $schedule) {
            if (empty($schedule['enabled'])) { continue; }
            if ($this->isDue($schedule)) {
                $results[] = $this->triggerService->trigger($schedule['workflowId'], 'manual', ['source' => 'scheduler', 'schedule' => $schedule]);
            }
        }
        return $results;
    }

    private function loadSchedules(): array
    {
        $base = __DIR__ . '/../../data/schedules';
        if (!is_dir($base)) { return []; }
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'json') {
                $files[] = json_decode(file_get_contents($file->getPathname()), true);
            }
        }
        return array_values(array_filter($files));
    }

    private function isDue(array $schedule): bool
    {
        $type = $schedule['type'] ?? 'daily';
        $now = new DateTime('now', new DateTimeZone($schedule['timezone'] ?? 'UTC'));
        switch ($type) {
            case 'minute':
                return true;
            case 'hourly':
                return $now->format('i') === '00';
            case 'daily':
                return $now->format('H:i') === ($schedule['time'] ?? '09:00');
            default:
                return false;
        }
    }
}
