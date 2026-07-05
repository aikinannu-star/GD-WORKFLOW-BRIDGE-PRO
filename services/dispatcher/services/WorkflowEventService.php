<?php
require_once __DIR__ . '/../events/EventBus.php';
require_once __DIR__ . '/EventSubscriptionService.php';

class WorkflowEventService
{
    private $bus;
    private $subscriptionService;

    public function __construct($bus = null, $subscriptionService = null)
    {
        $this->bus = $bus ?: new EventBus();
        $this->subscriptionService = $subscriptionService ?: new EventSubscriptionService($this->bus);
        $this->subscriptionService->subscribeAll();
    }

    public function publish(string $eventType, array $payload = []): array
    {
        return $this->bus->publish($eventType, $payload);
    }

    public function subscribe(string $eventType, callable $handler): void
    {
        $this->bus->subscribe($eventType, $handler);
    }
}
