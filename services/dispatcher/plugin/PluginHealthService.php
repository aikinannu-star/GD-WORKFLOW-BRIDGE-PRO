<?php

class PluginHealthService
{
    private array $states = [];
    private array $diagnostics = [];

    public function setState(string $pluginId, string $state, ?string $message = null): void
    {
        $this->states[$pluginId] = $state;
        if ($message !== null) {
            $this->diagnostics[$pluginId] = $message;
        }
    }

    public function getState(string $pluginId): ?string
    {
        return $this->states[$pluginId] ?? null;
    }

    public function getDiagnostics(string $pluginId): ?string
    {
        return $this->diagnostics[$pluginId] ?? null;
    }

    public function list(): array
    {
        $result = [];
        foreach ($this->states as $pluginId => $state) {
            $result[$pluginId] = [
                'state' => $state,
                'diagnostic' => $this->diagnostics[$pluginId] ?? null,
            ];
        }
        return $result;
    }

    public function markEnabled(string $pluginId): void
    {
        $this->setState($pluginId, 'enabled');
    }

    public function markFailed(string $pluginId, string $message): void
    {
        $this->setState($pluginId, 'failed', $message);
    }

    public function markQuarantined(string $pluginId, string $message): void
    {
        $this->setState($pluginId, 'quarantined', $message);
    }
}
