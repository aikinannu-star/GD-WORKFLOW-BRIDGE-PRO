<?php

require_once __DIR__ . '/AssistantExecutionStageInterface.php';

class AssistantExecutionStageRegistry
{
    /** @var array<int, array{name:string, stage:AssistantExecutionStageInterface}> */
    private array $stages = [];

    public function register(AssistantExecutionStageInterface $stage, ?string $name = null): void
    {
        $this->stages[] = [
            'name' => $name ?? get_class($stage),
            'stage' => $stage,
        ];
    }

    public function unregister(string $name): bool
    {
        foreach ($this->stages as $index => $entry) {
            if ($entry['name'] === $name) {
                unset($this->stages[$index]);
                $this->stages = array_values($this->stages);
                return true;
            }
        }

        return false;
    }

    public function getActiveStages(RuntimeExecutionContext $context, ?ModelProviderInterface $provider = null): array
    {
        $active = [];
        foreach ($this->stages as $entry) {
            if ($entry['stage']->supports($context, $provider)) {
                $active[] = $entry;
            }
        }

        usort($active, static function (array $left, array $right): int {
            return $right['stage']->priority() <=> $left['stage']->priority();
        });

        return $active;
    }

    public function getAllStages(): array
    {
        $stages = $this->stages;
        usort($stages, static function (array $left, array $right): int {
            return $right['stage']->priority() <=> $left['stage']->priority();
        });

        return $stages;
    }

    public function getDiagnostics(): array
    {
        return [
            'count' => count($this->stages),
            'stages' => array_map(static function (array $entry): array {
                return [
                    'name' => $entry['name'],
                    'priority' => $entry['stage']->priority(),
                ];
            }, $this->stages),
        ];
    }
}
