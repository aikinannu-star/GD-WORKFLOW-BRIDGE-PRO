<?php
interface TriggerInterface
{
    public function supports(string $type): bool;
    public function execute(array $trigger, array $context): array;
}
