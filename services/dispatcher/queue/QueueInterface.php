<?php
interface QueueInterface
{
    public function enqueue(array $item): string;
    public function dequeue(): ?array;
    public function ack(string $id): void;
    public function size(): int;
}
