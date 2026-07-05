<?php
require_once __DIR__ . '/QueueInterface.php';

class InMemoryQueue implements QueueInterface
{
    private $items = [];

    public function enqueue(array $item): string
    {
        $id = $item['id'] ?? bin2hex(random_bytes(8));
        $item['id'] = $id;
        $this->items[] = $item;
        return $id;
    }

    public function dequeue(): ?array
    {
        if (empty($this->items)) {
            return null;
        }
        return array_shift($this->items);
    }

    public function ack(string $id): void
    {
    }

    public function size(): int
    {
        return count($this->items);
    }
}
