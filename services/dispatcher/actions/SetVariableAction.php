<?php
require_once __DIR__ . '/ActionInterface.php';

class SetVariableAction implements ActionInterface
{
    public function execute(array $payload, ExecutionContext $context): ActionResult
    {
        $name = $payload['name'] ?? $payload['key'] ?? 'value';
        $value = $payload['value'] ?? null;
        $context->setVariable($name, $value);
        return ActionResult::success(['name' => $name, 'value' => $value], null, []);
    }
}
