<?php

class ProviderInfo
{
    private string $provider;
    private ?string $model;
    private ?string $modelFamily;
    private int $contextWindow;
    private bool $supportsTools;
    private bool $supportsVision;
    private bool $supportsJson;
    private bool $supportsEmbeddings;
    private array $options;

    public function __construct(array $capabilities = [])
    {
        $capabilities = is_array($capabilities) ? $capabilities : [];
        $this->provider = (string)($capabilities['provider'] ?? 'local');
        $this->model = isset($capabilities['model']) ? (string)$capabilities['model'] : null;
        $this->modelFamily = isset($capabilities['modelFamily']) ? (string)$capabilities['modelFamily'] : null;
        $this->contextWindow = isset($capabilities['contextWindow']) ? (int)$capabilities['contextWindow'] : 32768;
        $this->supportsTools = (bool)($capabilities['supportsTools'] ?? $capabilities['supportsToolCalling'] ?? $capabilities['supports_tool_calling'] ?? false);
        $this->supportsVision = (bool)($capabilities['supportsVision'] ?? false);
        $this->supportsJson = (bool)($capabilities['supportsJson'] ?? false);
        $this->supportsEmbeddings = (bool)($capabilities['supportsEmbeddings'] ?? false);
        $this->options = is_array($capabilities['options'] ?? null) ? $capabilities['options'] : [];
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function getModelFamily(): ?string
    {
        return $this->modelFamily;
    }

    public function getContextWindow(): int
    {
        return $this->contextWindow;
    }

    public function supportsTools(): bool
    {
        return $this->supportsTools;
    }

    public function supportsVision(): bool
    {
        return $this->supportsVision;
    }

    public function supportsJson(): bool
    {
        return $this->supportsJson;
    }

    public function supportsEmbeddings(): bool
    {
        return $this->supportsEmbeddings;
    }

    public function getOptions(): array
    {
        return $this->options;
    }
}
