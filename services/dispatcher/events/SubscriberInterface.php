<?php
interface SubscriberInterface
{
    public function supports(string $eventType): bool;
    public function handle(array $payload): array;
}
