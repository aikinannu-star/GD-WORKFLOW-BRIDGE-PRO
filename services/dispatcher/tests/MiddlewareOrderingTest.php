<?php
require_once __DIR__ . '/../middleware/MiddlewarePipeline.php';
require_once __DIR__ . '/../middleware/MiddlewareInterface.php';

class RecordingMiddleware implements MiddlewareInterface
{
    private string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function handle(array $context, callable $next): array
    {
        $context['trace'][] = $this->name . ':before';
        $result = $next($context);
        $result['trace'][] = $this->name . ':after';
        return $result;
    }
}

class ShortCircuitMiddleware implements MiddlewareInterface
{
    public function handle(array $context, callable $next): array
    {
        $context['trace'][] = 'shortcircuit:before';
        if (!empty($context['shortCircuit'])) {
            $context['trace'][] = 'shortcircuit:stopped';
            return $context;
        }

        $result = $next($context);
        $context['trace'][] = 'shortcircuit:after';
        return $result;
    }
}

class CleanupMiddleware implements MiddlewareInterface
{
    public array $events = [];

    public function handle(array $context, callable $next): array
    {
        $this->events[] = 'cleanup:before';
        try {
            return $next($context);
        } finally {
            $this->events[] = 'cleanup:after';
        }
    }
}

$pipeline = new MiddlewarePipeline();
$pipeline->add(new RecordingMiddleware('alpha'));
$pipeline->add(new RecordingMiddleware('beta'));

$result = $pipeline->handle(['trace' => []], function (array $context): array {
    $context['trace'][] = 'handler';
    $context['handled'] = true;
    return $context;
});

$expectedOrder = ['alpha:before', 'beta:before', 'handler', 'beta:after', 'alpha:after'];
if (($result['handled'] ?? null) !== true || ($result['trace'] ?? []) !== $expectedOrder) {
    fwrite(STDERR, "Middleware execution order was not preserved\n");
    fwrite(STDERR, json_encode($result['trace'] ?? []) . "\n");
    exit(1);
}

$shortCircuitPipeline = new MiddlewarePipeline();
$shortCircuitPipeline->add(new RecordingMiddleware('first'));
$shortCircuitPipeline->add(new ShortCircuitMiddleware());
$shortCircuitPipeline->add(new RecordingMiddleware('second'));

$shortCircuitResult = $shortCircuitPipeline->handle(['trace' => [], 'shortCircuit' => true], function (array $context): array {
    $context['trace'][] = 'handler';
    return $context;
});

if (($shortCircuitResult['trace'] ?? []) !== ['first:before', 'shortcircuit:before', 'shortcircuit:stopped', 'first:after']) {
    fwrite(STDERR, "Short-circuit middleware did not stop downstream execution\n");
    fwrite(STDERR, json_encode($shortCircuitResult['trace'] ?? []) . "\n");
    exit(1);
}

$cleanupMiddleware = new CleanupMiddleware();
$cleanupPipeline = new MiddlewarePipeline();
$cleanupPipeline->add($cleanupMiddleware);

try {
    $cleanupPipeline->handle(['trace' => []], function (array $context): array {
        $context['trace'][] = 'handler';
        throw new RuntimeException('boom');
    });
    fwrite(STDERR, "Exception from the handler should have propagated\n");
    exit(1);
} catch (RuntimeException $e) {
    if ($e->getMessage() !== 'boom') {
        fwrite(STDERR, "Exception propagation was not preserved\n");
        exit(1);
    }

    if ($cleanupMiddleware->events !== ['cleanup:before', 'cleanup:after']) {
        fwrite(STDERR, "Cleanup middleware did not run on handler failure\n");
        fwrite(STDERR, json_encode($cleanupMiddleware->events) . "\n");
        exit(1);
    }
}

fwrite(STDOUT, "Middleware ordering and failure propagation test passed\n");
