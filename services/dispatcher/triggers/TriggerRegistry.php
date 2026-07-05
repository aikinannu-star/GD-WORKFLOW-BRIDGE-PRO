<?php
require_once __DIR__ . '/ManualTrigger.php';

class TriggerRegistry
{
    private $triggers = [];

    public function __construct()
    {
        $this->register(new ManualTrigger());
    }

    public function register(TriggerInterface $trigger): void
    {
        $this->triggers[] = $trigger;
    }

    public function resolve(string $type): ?TriggerInterface
    {
        foreach ($this->triggers as $trigger) {
            if ($trigger->supports($type)) {
                return $trigger;
            }
        }
        return null;
    }
}
