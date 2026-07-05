<?php
require_once __DIR__ . '/MetricsCollectorInterface.php';

class MemoryMetricsCollector implements MetricsCollectorInterface
{
    private $data = [];

    public function increment(string $name, array $attributes = []): void
    {
        $this->data[$name] = ($this->data[$name] ?? 0) + 1;
    }

    public function snapshot(): array
    {
        return $this->data;
    }
}
