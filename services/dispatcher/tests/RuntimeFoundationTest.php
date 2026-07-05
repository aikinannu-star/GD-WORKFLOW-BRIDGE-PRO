<?php
require_once __DIR__ . '/../workers/WorkerManager.php';
require_once __DIR__ . '/../workers/SchedulerWorker.php';
require_once __DIR__ . '/../runner/WorkerRunner.php';
require_once __DIR__ . '/../actions/ActionRegistry.php';
require_once __DIR__ . '/../actions/LogAction.php';
require_once __DIR__ . '/../actions/DelayAction.php';
require_once __DIR__ . '/../runtime/ExecutionContext.php';

$runner = new WorkerRunner(new WorkerManager([new SchedulerWorker()]), 0.0);
$loopResult = $runner->runLoop(1);
if (!is_array($loopResult)) {
    fwrite(STDERR, "WorkerRunner should return an array" . PHP_EOL);
    exit(1);
}

$registry = new ActionRegistry();
$registry->register('log', new LogAction());
$registry->register('delay', new DelayAction());

$context = new ExecutionContext('workflow-1', 'execution-1', 'tenant-1');
$logResult = $registry->execute('log', ['message' => 'hello'], $context);
if (!$logResult->isSuccess() || ($logResult->getOutput()['message'] ?? null) !== 'hello') {
    fwrite(STDERR, "Action registry log action did not behave as expected" . PHP_EOL);
    exit(1);
}

$delayResult = $registry->execute('delay', ['duration' => 0.0], $context);
if (!$delayResult->isSuccess()) {
    fwrite(STDERR, "Action registry delay action failed" . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Runtime foundation test passed" . PHP_EOL);
