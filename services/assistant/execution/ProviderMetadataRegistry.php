<?php

require_once __DIR__ . '/ProviderMetadata.php';

class ProviderMetadataRegistry
{
    private array $providers = [];

    public function register(ProviderMetadata $metadata): void
    {
        $this->providers[$metadata->providerName] = $metadata;
    }

    public function get(string $providerName): ?ProviderMetadata
    {
        return $this->providers[$providerName] ?? null;
    }

    public function all(): array
    {
        return $this->providers;
    }
}
