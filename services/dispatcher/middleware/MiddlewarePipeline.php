<?php
require_once __DIR__ . '/MiddlewareInterface.php';

class MiddlewarePipeline
{
    private $middlewares = [];

    public function add(MiddlewareInterface $middleware): void
    {
        $this->middlewares[] = $middleware;
    }

    public function handle(array $context, callable $handler): array
    {
        $index = 0;
        $next = null;
        $next = function (array $currentContext) use (&$index, $handler, &$next): array {
            if ($index < count($this->middlewares)) {
                $middleware = $this->middlewares[$index++];
                return $middleware->handle($currentContext, $next);
            }
            return $handler($currentContext);
        };

        return $next($context);
    }
}
