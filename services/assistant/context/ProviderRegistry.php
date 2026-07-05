<?php

require_once __DIR__ . '/ProviderInfo.php';

class ProviderRegistry
{
    /** @var array<string, ProviderInfo> */
    private array $providers = [];

    public function __construct(array $providers = [])
    {
        foreach ($providers as $name => $provider) {
            $this->register($name, $provider);
        }
    }

    public function register(string $name, ProviderInfo|array $provider): void
    {
        $info = $provider instanceof ProviderInfo ? $provider : new ProviderInfo($provider);
        $this->providers[strtolower($name)] = $info;
    }

    public function get(string $name): ?ProviderInfo
    {
        return $this->providers[strtolower((string)$name)] ?? null;
    }

    public function has(string $name): bool
    {
        return $this->get($name) !== null;
    }

    public function all(): array
    {
        return $this->providers;
    }
}
