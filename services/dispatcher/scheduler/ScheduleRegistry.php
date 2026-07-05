<?php
class ScheduleRegistry
{
    private $schedules = [];

    public function register(string $type, callable $factory): void
    {
        $this->schedules[$type] = $factory;
    }

    public function create(string $type, array $config): array
    {
        if (!isset($this->schedules[$type])) {
            throw new Exception('unsupported_schedule_type');
        }
        return call_user_func($this->schedules[$type], $config);
    }
}
