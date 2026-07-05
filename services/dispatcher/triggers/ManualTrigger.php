<?php
require_once __DIR__ . '/TriggerInterface.php';

class ManualTrigger implements TriggerInterface
{
    public function supports(string $type): bool
    {
        return $type === 'manual';
    }

    public function execute(array $trigger, array $context): array
    {
        return [
            'status' => 'ok',
            'trigger' => 'manual',
            'context' => $context,
        ];
    }
}
