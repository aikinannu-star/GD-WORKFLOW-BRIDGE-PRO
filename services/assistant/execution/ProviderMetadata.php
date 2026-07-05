<?php

require_once __DIR__ . '/ProviderCapabilities.php';

class ProviderMetadata
{
    public string $providerName;
    public ?string $model;
    private ProviderCapabilities $capabilities;
    public array $pricingProfile = [];
    public ?string $endpoint = null;

    public function __construct(string $providerName, ?string $model = null, ?ProviderCapabilities $capabilities = null, array $pricingProfile = [], ?string $endpoint = null)
    {
        $this->providerName = $providerName;
        $this->model = $model;
        $this->capabilities = $capabilities ?? ProviderCapabilities::forProvider($providerName);
        $this->pricingProfile = $pricingProfile;
        $this->endpoint = $endpoint;
    }

    public function getCapabilities(): ProviderCapabilities
    {
        return $this->capabilities;
    }

    public function setCapabilities(ProviderCapabilities $capabilities): self
    {
        $this->capabilities = $capabilities;
        return $this;
    }

    public static function fromArray(array $arr): ProviderMetadata
    {
        $providerName = $arr['providerName'] ?? 'unknown';
        $model = $arr['model'] ?? null;
        
        // Parse capabilities: if array, convert to ProviderCapabilities; if object/null, use default
        $capabilities = null;
        if (isset($arr['capabilities'])) {
            if (is_array($arr['capabilities'])) {
                $capabilities = ProviderCapabilities::fromArray($arr['capabilities']);
            } elseif ($arr['capabilities'] instanceof ProviderCapabilities) {
                $capabilities = $arr['capabilities'];
            }
        }
        
        return new ProviderMetadata(
            $providerName,
            $model,
            $capabilities,
            $arr['pricingProfile'] ?? [],
            $arr['endpoint'] ?? null
        );
    }

    public function toArray(): array
    {
        return [
            'providerName' => $this->providerName,
            'model' => $this->model,
            'capabilities' => $this->capabilities->toArray(),
            'pricingProfile' => $this->pricingProfile,
            'endpoint' => $this->endpoint,
        ];
    }
}
