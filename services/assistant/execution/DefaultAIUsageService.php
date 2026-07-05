<?php

require_once __DIR__ . '/AIUsageServiceInterface.php';
require_once __DIR__ . '/UsageEstimatorInterface.php';
require_once __DIR__ . '/CostCalculatorInterface.php';
require_once __DIR__ . '/ProviderMetadataRegistry.php';
require_once __DIR__ . '/ProviderMetadata.php';

class DefaultAIUsageService implements AIUsageServiceInterface
{
    private UsageEstimatorInterface $usageEstimator;
    private CostCalculatorInterface $costCalculator;
    private ProviderMetadataRegistry $metadataRegistry;

    public function __construct(UsageEstimatorInterface $usageEstimator, CostCalculatorInterface $costCalculator, ProviderMetadataRegistry $metadataRegistry)
    {
        $this->usageEstimator = $usageEstimator;
        $this->costCalculator = $costCalculator;
        $this->metadataRegistry = $metadataRegistry;
    }

    public function estimateUsage(string $providerName, ?string $model, string $prompt, ?string $completion = null): array
    {
        $providerMetadata = $this->metadataRegistry->get($providerName);

        if ($providerMetadata !== null && ($providerMetadata->capabilities['reportsRealTokenUsage'] ?? false)) {
            return ['promptTokens' => 0, 'completionTokens' => 0, 'totalTokens' => 0, 'source' => 'provider'];
        }

        $estimation = $this->usageEstimator->estimate($providerName, $model, $prompt, $completion);
        return [
            'promptTokens' => $estimation['promptTokens'],
            'completionTokens' => $estimation['completionTokens'],
            'totalTokens' => $estimation['totalTokens'],
            'source' => $estimation['source'] ?? 'estimated',
        ];
    }

    public function calculateCost(array $providerMetadata, ?string $model, int $promptTokens, int $completionTokens, ?string $tenantId = null): array
    {
        $metadata = $providerMetadata;
        if (empty($metadata['pricingProfile']) && isset($metadata['providerName'])) {
            $registryMetadata = $this->metadataRegistry->get($metadata['providerName']);
            if ($registryMetadata !== null) {
                $metadata = array_merge($metadata, $registryMetadata->toArray());
            }
        }

        return $this->costCalculator->calculate($metadata, $model, $promptTokens, $completionTokens, $tenantId);
    }

    public function getProviderMetadata(string $providerName): ?ProviderMetadata
    {
        return $this->metadataRegistry->get($providerName);
    }

    public function registerProviderMetadata(ProviderMetadata $providerMetadata): void
    {
        $this->metadataRegistry->register($providerMetadata);
    }
}
