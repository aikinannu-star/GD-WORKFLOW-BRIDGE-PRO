<?php

require_once __DIR__ . '/ProviderRegistry.php';
require_once __DIR__ . '/RuntimeExecutionContext.php';
require_once __DIR__ . '/../ModelProviderInterface.php';

class ProviderRouter
{
    private ProviderRegistry $registry;
    private array $providers = [];

    public function __construct(?ProviderRegistry $registry = null)
    {
        $this->registry = $registry ?? new ProviderRegistry();
    }

    public function register(string $name, ModelProviderInterface $provider): void
    {
        $this->providers[strtolower($name)] = $provider;
    }

    public function unregister(string $name): bool
    {
        $key = strtolower($name);
        if (isset($this->providers[$key])) {
            unset($this->providers[$key]);
            return true;
        }

        return false;
    }

    public function has(string $name): bool
    {
        return isset($this->providers[strtolower($name)]);
    }

    public function resolve(string $name): ?ModelProviderInterface
    {
        return $this->providers[strtolower($name)] ?? null;
    }

    public function route(RuntimeExecutionContext $context): ?ModelProviderInterface
    {
        $providerInfo = $context->getProviderInfo();
        if ($providerInfo === null) {
            return null;
        }

        return $this->resolve($providerInfo->getProvider());
    }

    public function listProviders(): array
    {
        return $this->providers;
    }
}
