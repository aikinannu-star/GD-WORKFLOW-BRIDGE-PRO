<?php

/**
 * ProviderCapabilities — Declarative feature flags for AI providers
 * 
 * Enables capability-based routing in the pipeline instead of provider-specific conditionals.
 * Example: Instead of `if ($provider === 'openai')`, use `if ($capabilities->supportsToolCalling())`
 */
class ProviderCapabilities
{
    // Core capabilities
    private bool $supportsToolCalling = false;
    private bool $reportsRealTokenUsage = false;
    private bool $supportsStreaming = false;

    // Extended capabilities
    private bool $supportsVision = false;
    private bool $supportsEmbeddings = false;
    private bool $reportsCost = false;
    private bool $reportsLatency = false;

    // Provider version info
    private ?string $apiVersion = null;
    private ?string $modelVersion = null;

    /**
     * Factory: Create from array (from registry/config)
     */
    public static function fromArray(array $data): self
    {
        $cap = new self();
        $cap->supportsToolCalling = $data['supportsToolCalling'] ?? $data['supportsTools'] ?? false;
        $cap->reportsRealTokenUsage = $data['reportsRealTokenUsage'] ?? false;
        $cap->supportsStreaming = $data['supportsStreaming'] ?? false;
        $cap->supportsVision = $data['supportsVision'] ?? false;
        $cap->supportsEmbeddings = $data['supportsEmbeddings'] ?? false;
        $cap->reportsCost = $data['reportsCost'] ?? false;
        $cap->reportsLatency = $data['reportsLatency'] ?? false;
        $cap->apiVersion = $data['apiVersion'] ?? null;
        $cap->modelVersion = $data['modelVersion'] ?? null;
        return $cap;
    }

    /**
     * Factory: Create with defaults for common providers
     */
    public static function forProvider(string $providerName): self
    {
        return match ($providerName) {
            'openai' => self::openai(),
            'anthropic' => self::anthropic(),
            'azure_openai' => self::azureOpenai(),
            'cohere' => self::cohere(),
            'local' => self::local(),
            'custom' => self::custom(),
            default => self::custom(),
        };
    }

    // Predefined capability profiles
    public static function openai(): self
    {
        $cap = new self();
        $cap->supportsToolCalling = true;
        $cap->reportsRealTokenUsage = true;
        $cap->supportsStreaming = true;
        $cap->supportsVision = true;
        $cap->supportsEmbeddings = false; // Via separate API
        $cap->reportsCost = true;
        $cap->reportsLatency = true;
        $cap->apiVersion = '2024-06';
        return $cap;
    }

    public static function anthropic(): self
    {
        $cap = new self();
        $cap->supportsToolCalling = true;
        $cap->reportsRealTokenUsage = true;
        $cap->supportsStreaming = true;
        $cap->supportsVision = true;
        $cap->supportsEmbeddings = false;
        $cap->reportsCost = true;
        $cap->reportsLatency = true;
        return $cap;
    }

    public static function azureOpenai(): self
    {
        $cap = new self();
        $cap->supportsToolCalling = true;
        $cap->reportsRealTokenUsage = true;
        $cap->supportsStreaming = true;
        $cap->supportsVision = true;
        $cap->supportsEmbeddings = false;
        $cap->reportsCost = true;
        $cap->reportsLatency = true;
        return $cap;
    }

    public static function cohere(): self
    {
        $cap = new self();
        $cap->supportsToolCalling = true;
        $cap->reportsRealTokenUsage = true;
        $cap->supportsStreaming = true;
        $cap->supportsVision = false;
        $cap->supportsEmbeddings = true;
        $cap->reportsCost = false;
        $cap->reportsLatency = true;
        return $cap;
    }

    public static function local(): self
    {
        $cap = new self();
        $cap->supportsToolCalling = false;
        $cap->reportsRealTokenUsage = false;
        $cap->supportsStreaming = false;
        $cap->supportsVision = false;
        $cap->supportsEmbeddings = false;
        $cap->reportsCost = false;
        $cap->reportsLatency = true;
        return $cap;
    }

    public static function custom(): self
    {
        // Most conservative defaults — no assumptions about custom providers
        return new self();
    }

    // Builder pattern for fluent configuration
    public function withToolCalling(bool $value = true): self { $this->supportsToolCalling = $value; return $this; }
    public function withRealTokenUsage(bool $value = true): self { $this->reportsRealTokenUsage = $value; return $this; }
    public function withStreaming(bool $value = true): self { $this->supportsStreaming = $value; return $this; }
    public function withVision(bool $value = true): self { $this->supportsVision = $value; return $this; }
    public function withEmbeddings(bool $value = true): self { $this->supportsEmbeddings = $value; return $this; }
    public function withCostReporting(bool $value = true): self { $this->reportsCost = $value; return $this; }
    public function withLatencyReporting(bool $value = true): self { $this->reportsLatency = $value; return $this; }
    public function withApiVersion(?string $version): self { $this->apiVersion = $version; return $this; }
    public function withModelVersion(?string $version): self { $this->modelVersion = $version; return $this; }

    // Getters for capability checks
    public function supportsToolCalling(): bool { return $this->supportsToolCalling; }
    public function supportsTools(): bool { return $this->supportsToolCalling; }
    public function reportsRealTokenUsage(): bool { return $this->reportsRealTokenUsage; }
    public function supportsStreaming(): bool { return $this->supportsStreaming; }
    public function supportsVision(): bool { return $this->supportsVision; }
    public function supportsEmbeddings(): bool { return $this->supportsEmbeddings; }
    public function reportsCost(): bool { return $this->reportsCost; }
    public function reportsLatency(): bool { return $this->reportsLatency; }
    public function getApiVersion(): ?string { return $this->apiVersion; }
    public function getModelVersion(): ?string { return $this->modelVersion; }

    /**
     * Export to array for persistence/transmission
     */
    public function toArray(): array
    {
        return [
            'supportsToolCalling' => $this->supportsToolCalling,
            'supportsTools' => $this->supportsToolCalling,
            'reportsRealTokenUsage' => $this->reportsRealTokenUsage,
            'supportsStreaming' => $this->supportsStreaming,
            'supportsVision' => $this->supportsVision,
            'supportsEmbeddings' => $this->supportsEmbeddings,
            'reportsCost' => $this->reportsCost,
            'reportsLatency' => $this->reportsLatency,
            'apiVersion' => $this->apiVersion,
            'modelVersion' => $this->modelVersion,
        ];
    }
}
