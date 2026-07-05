<?php
require_once __DIR__ . '/../services/SchedulerService.php';

$service = new SchedulerService();
$schedule = $service->createSchedule('wf-test-1', ['type' => 'daily', 'time' => '09:00']);
if (($schedule['type'] ?? null) !== 'daily') {
    fwrite(STDERR, "Expected daily schedule type" . PHP_EOL);
    exit(1);
}

$list = $service->listSchedules('wf-test-1');
if (count($list) < 1) {
    fwrite(STDERR, "Expected at least one schedule" . PHP_EOL);
    exit(1);
}

$deleted = $service->deleteSchedule($schedule['id']);
if (!$deleted) {
    fwrite(STDERR, "Expected schedule deletion to succeed" . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "SchedulerService test passed" . PHP_EOL);
