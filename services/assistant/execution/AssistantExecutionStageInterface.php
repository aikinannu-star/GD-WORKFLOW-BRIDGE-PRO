<?php

interface AssistantExecutionStageInterface
{
    public function supports(RuntimeExecutionContext $context, ?ModelProviderInterface $provider = null): bool;

    public function execute(RuntimeExecutionContext $context): RuntimeExecutionContext;

    public function priority(): int;
}
