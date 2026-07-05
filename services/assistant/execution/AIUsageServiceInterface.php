<?php

interface AIUsageServiceInterface
{
    /**
     * Estimate token usage for a provider request/response.
     * @param string $providerName
     * @param string|null $model
     * @param string $prompt
     * @param string|null $completion
     * @return array ['promptTokens'=>int,'completionTokens'=>int,'totalTokens'=>int,'source'=>string]
     */
    public function estimateUsage(string $providerName, ?string $model, string $prompt, ?string $completion = null): array;

    /**
     * Calculate cost for provider usage.
     * @param array $providerMetadata
     * @param string|null $model
     * @param int $promptTokens
     * @param int $completionTokens
     * @param string|null $tenantId
     * @return array ['estimatedCost'=>float,'currency'=>string,'source'=>string]
     */
    public function calculateCost(array $providerMetadata, ?string $model, int $promptTokens, int $completionTokens, ?string $tenantId = null): array;

    /**
     * Lookup provider metadata by provider name.
     * @param string $providerName
     * @return ProviderMetadata|null
     */
    public function getProviderMetadata(string $providerName): ?ProviderMetadata;
}
