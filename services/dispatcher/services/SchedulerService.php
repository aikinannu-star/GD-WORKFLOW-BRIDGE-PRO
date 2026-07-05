<?php
require_once __DIR__ . '/../scheduler/FileScheduleRepository.php';
require_once __DIR__ . '/../scheduler/ScheduleRegistry.php';
require_once __DIR__ . '/WorkflowEventService.php';

class SchedulerService
{
    private $repo;
    private $registry;
    private $eventService;

    public function __construct($repo = null, $registry = null, $eventService = null)
    {
        $this->repo = $repo ?: new FileScheduleRepository();
        $this->registry = $registry ?: new ScheduleRegistry();
        $this->eventService = $eventService ?: new WorkflowEventService();
        $this->registerDefaults();
    }

    private function registerDefaults(): void
    {
        $this->registry->register('minute', function ($config) {
            return ['type' => 'minute', 'config' => $config];
        });
        $this->registry->register('hourly', function ($config) {
            return ['type' => 'hourly', 'config' => $config];
        });
        $this->registry->register('daily', function ($config) {
            return ['type' => 'daily', 'config' => $config];
        });
    }

    public function createSchedule(string $workflowId, array $payload): array
    {
        $record = [
            'workflowId' => $workflowId,
            'enabled' => $payload['enabled'] ?? true,
            'type' => $payload['type'] ?? 'daily',
            'time' => $payload['time'] ?? '09:00',
            'timezone' => $payload['timezone'] ?? 'UTC',
        ];
        $record = array_merge($record, $this->registry->create($record['type'], $record));
        return $this->repo->save($record);
    }

    public function listSchedules(string $workflowId): array
    {
        return $this->repo->listByWorkflow($workflowId);
    }

    public function deleteSchedule(string $id): bool
    {
        return $this->repo->delete($id);
    }

    public function runDueSchedules(): array
    {
        $results = [];
        foreach (glob(__DIR__ . '/../../data/schedules/*/*.json') as $file) {
            $schedule = json_decode(file_get_contents($file), true);
            if (!$schedule || empty($schedule['enabled'])) { continue; }
            $results[] = $this->eventService->publish('workflow.triggered', [
                'workflowId' => $schedule['workflowId'] ?? null,
                'trigger' => 'schedule',
                'schedule' => $schedule,
            ]);
        }
        return $results;
    }
}
