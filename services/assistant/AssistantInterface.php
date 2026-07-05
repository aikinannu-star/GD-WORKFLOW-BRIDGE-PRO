<?php

interface AssistantInterface
{
    public function id(): string;
    public function name(): string;
    public function description(): string;
    public function capabilities(): array;
    public function tools(): array;
    public function handleConversation(array $context): array;
}
