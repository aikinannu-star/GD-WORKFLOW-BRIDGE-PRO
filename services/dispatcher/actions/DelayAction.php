<?php
require_once __DIR__ . '/ActionInterface.php';

class DelayAction implements ActionInterface
{
    public function execute(array $payload, ExecutionContext $context): ActionResult
    {
        $duration = isset($payload['duration']) && is_numeric($payload['duration']) ? (float) $payload['duration'] : 0.0;
        if ($duration > 0) {
            usleep((int) ($duration * 1000000));
        }
        return ActionResult::success(['duration' => $duration], null, []);
    }
}
