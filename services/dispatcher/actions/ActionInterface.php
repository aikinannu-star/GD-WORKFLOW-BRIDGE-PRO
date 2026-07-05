<?php
require_once __DIR__ . '/ActionResult.php';
require_once __DIR__ . '/../runtime/ExecutionContext.php';

interface ActionInterface
{
    public function execute(array $payload, ExecutionContext $context): ActionResult;
}
