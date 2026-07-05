<?php

class CapabilityRegistry
{
    private array $capabilities = [];

    public function register(string $pluginId, string $type, string $name, array $metadata = []): void
    {
        $this->capabilities[$pluginId][$type][$name] = $metadata;
    }

    public function getCapabilities(string $pluginId): array
    {
        return $this->capabilities[$pluginId] ?? [];
    }

    public function getByType(string $type): array
    {
        $result = [];
        foreach ($this->capabilities as $pluginId => $capabilities) {
            if (isset($capabilities[$type])) {
                foreach ($capabilities[$type] as $name => $metadata) {
                    $result[$pluginId][$name] = $metadata;
                }
            }
        }
        return $result;
    }

    public function hasCapability(string $pluginId, string $type, string $name): bool
    {
        return isset($this->capabilities[$pluginId][$type][$name]);
    }

    public function list(): array
    {
        return $this->capabilities;
    }
}
