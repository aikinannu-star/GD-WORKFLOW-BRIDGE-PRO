<?php
class EventBus
{
    private $subscribers = [];

    public function subscribe(string $eventType, callable $handler): void
    {
        $this->subscribers[$eventType][] = $handler;
    }

    public function publish(string $eventType, array $payload = []): array
    {
        $results = [];
        foreach ($this->subscribers[$eventType] ?? [] as $handler) {
            $results[] = $handler($payload);
        }
        return $results;
    }
}
