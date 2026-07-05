<?php

require_once __DIR__ . '/ModelProviderInterface.php';

interface AssistantProviderInterface extends ModelProviderInterface
{
    public function generate(string $prompt, array $options = []): array;
}
