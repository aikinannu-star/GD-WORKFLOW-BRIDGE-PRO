<?php

class MemoryAnalytics
{
    private array $metrics = [];

    public function record(string $event, array $payload = []): void
    {
        $this->metrics[$event][] = [
            'timestamp' => (new DateTime('now', new DateTimeZone('UTC')))->format(DateTime::ATOM),
            'payload' => $payload,
        ];
    }

    public function snapshot(): array
    {
        return $this->metrics;
    }
}
