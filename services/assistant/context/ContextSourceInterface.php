<?php

interface ContextSourceInterface
{
    public function collect(AssistantContext $context, string $message): array;
}
