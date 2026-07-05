<?php
require_once __DIR__ . '/../middleware/MiddlewarePipeline.php';
require_once __DIR__ . '/../middleware/TracingMiddleware.php';
require_once __DIR__ . '/../events/RuntimeEventEmitter.php';

$pipeline = new MiddlewarePipeline();
$pipeline->add(new TracingMiddleware());
$result = $pipeline->handle(['input' => 'hello'], function (array $context): array {
    $context['handled'] = true;
    return $context;
});

if (($result['handled'] ?? null) !== true || !in_array('tracing:start', $result['trace'] ?? [], true)) {
    fwrite(STDERR, "Middleware pipeline did not run as expected" . PHP_EOL);
    exit(1);
}

$emitter = new RuntimeEventEmitter();
$events = [];
$emitter->on('workflow.started', function (array $payload) use (&$events): void {
    $events[] = $payload;
});
$emitter->emit('workflow.started', ['workflowId' => 'wf-1']);
if (empty($events)) {
    fwrite(STDERR, "Runtime event emitter did not dispatch events" . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Middleware and event lifecycle test passed" . PHP_EOL);
