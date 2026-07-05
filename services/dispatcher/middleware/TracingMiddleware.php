<?php
require_once __DIR__ . '/MiddlewareInterface.php';

class TracingMiddleware implements MiddlewareInterface
{
    public function handle(array $context, callable $next): array
    {
        $context['trace'][] = 'tracing:start';
        $result = $next($context);
        $result['trace'][] = 'tracing:end';
        return $result;
    }
}
