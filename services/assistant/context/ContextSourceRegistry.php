<?php

require_once __DIR__ . '/ContextSourceInterface.php';

class ContextSourceRegistry
{
    private array $sources = [];

    public function register(string $name, ContextSourceInterface $source, int $priority = 0): void
    {
        $this->sources[$name] = [
            'source' => $source,
            'priority' => $priority,
        ];
    }

    public function has(string $name): bool
    {
        return isset($this->sources[$name]);
    }

    public function get(string $name): ?ContextSourceInterface
    {
        return $this->sources[$name]['source'] ?? null;
    }

    public function all(): array
    {
        $sources = $this->sources;
        uasort($sources, static function (array $a, array $b): int {
            return ($b['priority'] <=> $a['priority']);
        });

        return array_values(array_map(static function (array $entry): ContextSourceInterface {
            return $entry['source'];
        }, $sources));
    }
}
