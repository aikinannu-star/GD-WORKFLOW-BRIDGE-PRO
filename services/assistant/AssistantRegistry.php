<?php

class AssistantRegistry
{
    private array $definitions = [];

    public function registerDefinition(string $id, array $def): void
    {
        $this->definitions[$id] = $def;
    }

    public function getDefinition(string $id): ?array
    {
        return $this->definitions[$id] ?? null;
    }

    public function listDefinitions(): array
    {
        return array_keys($this->definitions);
    }
}
