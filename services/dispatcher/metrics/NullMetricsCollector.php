<?php
require_once __DIR__ . '/MetricsCollectorInterface.php';

class NullMetricsCollector implements MetricsCollectorInterface
{
    public function increment(string $name, array $attributes = []): void
    {
    }

    public function snapshot(): array
    {
        return [];
    }
}
