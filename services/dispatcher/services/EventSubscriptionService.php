<?php
require_once __DIR__ . '/../events/EventBus.php';
require_once __DIR__ . '/../events/SubscriberRegistry.php';

class EventSubscriptionService
{
    private $bus;
    private $registry;

    public function __construct($bus = null, $registry = null)
    {
        $this->bus = $bus ?: new EventBus();
        $this->registry = $registry ?: new SubscriberRegistry();
    }

    public function subscribeAll(): void
    {
        $this->bus->subscribe('workflow.started', function (array $payload) {
            foreach ($this->registry->getSubscribers('workflow.started') as $subscriber) {
                $subscriber->handle(array_merge($payload, ['eventType' => 'workflow.started']));
            }
            return ['status' => 'ok'];
        });

        $this->bus->subscribe('workflow.completed', function (array $payload) {
            foreach ($this->registry->getSubscribers('workflow.completed') as $subscriber) {
                $subscriber->handle(array_merge($payload, ['eventType' => 'workflow.completed']));
            }
            return ['status' => 'ok'];
        });

        $this->bus->subscribe('workflow.failed', function (array $payload) {
            foreach ($this->registry->getSubscribers('workflow.failed') as $subscriber) {
                $subscriber->handle(array_merge($payload, ['eventType' => 'workflow.failed']));
            }
            return ['status' => 'ok'];
        });

        $this->bus->subscribe('workflow.node.completed', function (array $payload) {
            foreach ($this->registry->getSubscribers('workflow.node.completed') as $subscriber) {
                $subscriber->handle(array_merge($payload, ['eventType' => 'workflow.node.completed']));
            }
            return ['status' => 'ok'];
        });
    }

    public function publish(string $eventType, array $payload = []): array
    {
        return $this->bus->publish($eventType, $payload);
    }
}
