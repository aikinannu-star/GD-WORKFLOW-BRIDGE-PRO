<?php
require_once __DIR__ . '/../workers/SchedulerWorker.php';

$worker = new SchedulerWorker();
$result = $worker->runOnce();
if (!is_array($result)) {
    fwrite(STDERR, "Expected scheduler worker to return an array" . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "SchedulerWorker test passed" . PHP_EOL);
