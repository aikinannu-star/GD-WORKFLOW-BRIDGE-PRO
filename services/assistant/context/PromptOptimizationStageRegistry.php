<?php

require_once __DIR__ . '/PromptOptimizationStageInterface.php';
require_once __DIR__ . '/ProviderInfo.php';
require_once __DIR__ . '/ModelProfile.php';
require_once __DIR__ . '/../AssistantContext.php';
require_once __DIR__ . '/../ModelProviderInterface.php';

class PromptOptimizationStageRegistry
{
    /** @var array<int, array{name:string, stage:PromptOptimizationStageInterface}> */
    private array $stages = [];

    public function __construct(array $stages = [])
    {
        foreach ($stages as $stage) {
            if ($stage instanceof PromptOptimizationStageInterface) {
                $this->register($stage);
            }
        }
    }

    public function register(PromptOptimizationStageInterface $stage, ?string $name = null): void
    {
        $this->stages[] = [
            'name' => $name ?? $this->resolveStageName($stage),
            'stage' => $stage,
        ];
        $this->sort();
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

    public function getStages(): array
    {
        return array_values(array_map(static function (array $entry): PromptOptimizationStageInterface {
            return $entry['stage'];
        }, $this->stages));
    }

    public function getStageDefinitions(): array
    {
        return $this->stages;
    }

    public function getActiveStages(AssistantContext $context, ?ModelProviderInterface $provider = null, ?ProviderInfo $providerInfo = null, ?ModelProfile $modelProfile = null): array
    {
        $active = [];
        foreach ($this->stages as $entry) {
            $stage = $entry['stage'];
            if ($stage->supports($context, $provider ?? new class implements ModelProviderInterface {
                public function chat(string $prompt, array $options = []): array { return ['success' => true, 'text' => '']; }
                public function stream(string $prompt, array $options = []): iterable { yield ['success' => true, 'text' => '']; }
                public function embeddings(string $input, array $options = []): array { return ['vector' => []]; }
                public function health(): array { return ['success' => true]; }
                public function capabilities(): array { return []; }
            }, $providerInfo, $modelProfile)) {
                $active[] = $entry;
            }
        }

        usort($active, static function (array $left, array $right): int {
            $leftStage = $left['stage'];
            $rightStage = $right['stage'];
            return $leftStage->priority() <=> $rightStage->priority();
        });

        return $active;
    }

    public function getDiagnostics(): array
    {
        $diagnostics = [];
        foreach ($this->stages as $entry) {
            $diagnostics[] = [
                'name' => $entry['name'],
                'class' => get_class($entry['stage']),
                'priority' => $entry['stage']->priority(),
            ];
        }

        return $diagnostics;
    }

    private function sort(): void
    {
        usort($this->stages, static function (array $left, array $right): int {
            $leftStage = $left['stage'];
            $rightStage = $right['stage'];
            return $leftStage->priority() <=> $rightStage->priority();
        });
    }

    private function resolveStageName(PromptOptimizationStageInterface $stage): string
    {
        $class = get_class($stage);
        return strtolower(str_replace(['\\', '_'], '-', $class));
    }
}
