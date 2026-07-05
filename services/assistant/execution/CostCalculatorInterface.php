<?php

interface CostCalculatorInterface
{
    /**
     * Calculate estimated cost given usage and provider/model metadata
     * @param array $providerMetadata
     * @param string|null $model
     * @param int $promptTokens
     * @param int $completionTokens
     * @param string|null $tenantId
     * @return array ['estimatedCost'=>float,'currency'=>string,'source'=>string]
     */
    public function calculate(array $providerMetadata, ?string $model, int $promptTokens, int $completionTokens, ?string $tenantId = null): array;
}
