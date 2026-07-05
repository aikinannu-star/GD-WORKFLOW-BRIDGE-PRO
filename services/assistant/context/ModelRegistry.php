<?php

require_once __DIR__ . '/ModelProfile.php';

class ModelRegistry
{
    /** @var array<string, ModelProfile> */
    private array $models = [];

    public function __construct(array $models = [])
    {
        foreach ($models as $name => $model) {
            $this->register($name, $model);
        }
    }

    public function register(string $name, ModelProfile|array $model): void
    {
        $profile = $model instanceof ModelProfile ? $model : new ModelProfile($model);
        $this->models[strtolower($name)] = $profile;
    }

    public function get(string $name): ?ModelProfile
    {
        return $this->models[strtolower((string)$name)] ?? null;
    }

    public function has(string $name): bool
    {
        return $this->get($name) !== null;
    }

    public function all(): array
    {
        return $this->models;
    }
}
