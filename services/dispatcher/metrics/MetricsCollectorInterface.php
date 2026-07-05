<?php
interface MetricsCollectorInterface
{
    public function increment(string $name, array $attributes = []): void;
    public function snapshot(): array;
}
