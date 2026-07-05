<?php
require_once __DIR__ . '/AuditSubscriber.php';

class SubscriberRegistry
{
    private $subscribers = [];

    public function __construct()
    {
        $this->register(new AuditSubscriber());
    }

    public function register(SubscriberInterface $subscriber): void
    {
        $this->subscribers[] = $subscriber;
    }

    public function getSubscribers(string $eventType): array
    {
        $matched = [];
        foreach ($this->subscribers as $subscriber) {
            if ($subscriber->supports($eventType)) {
                $matched[] = $subscriber;
            }
        }
        return $matched;
    }
}
