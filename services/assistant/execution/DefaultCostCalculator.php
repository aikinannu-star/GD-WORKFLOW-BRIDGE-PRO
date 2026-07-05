<?php

require_once __DIR__ . '/CostCalculatorInterface.php';

class DefaultCostCalculator implements CostCalculatorInterface
{
    private float $defaultPromptRatePer1k; // USD per 1000 prompt tokens
    private float $defaultCompletionRatePer1k; // USD per 1000 completion tokens

    public function __construct(float $promptRatePer1k = 0.0015, float $completionRatePer1k = 0.002)
    {
        $this->defaultPromptRatePer1k = $promptRatePer1k;
        $this->defaultCompletionRatePer1k = $completionRatePer1k;
    }

    public function calculate(array $providerMetadata, ?string $model, int $promptTokens, int $completionTokens, ?string $tenantId = null): array
    {
        $currency = $providerMetadata['pricingProfile']['currency'] ?? 'USD';
        // Allow provider metadata to supply rates
        $promptRate = $providerMetadata['pricingProfile']['prompt_per_1k'] ?? $this->defaultPromptRatePer1k;
        $completionRate = $providerMetadata['pricingProfile']['completion_per_1k'] ?? $this->defaultCompletionRatePer1k;

        $cost = ($promptTokens / 1000.0) * $promptRate + ($completionTokens / 1000.0) * $completionRate;

        return ['estimatedCost' => $cost, 'currency' => $currency, 'source' => isset($providerMetadata['pricingProfile']) ? 'provider_pricing' : 'configured'];
    }
}
