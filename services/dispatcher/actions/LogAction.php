<?php
require_once __DIR__ . '/ActionInterface.php';

class LogAction implements ActionInterface
{
    public function execute(array $payload, ExecutionContext $context): ActionResult
    {
        $message = $payload['message'] ?? $payload['text'] ?? 'logged';
        $context->addLog($message);
        return ActionResult::success(['message' => $message], null, [$message]);
    }
}
