<?php

interface UsageEstimatorInterface
{
    /**
     * Estimate token usage for a provider request/response
     * @param string $providerName
     * @param string|null $model
     * @param string $prompt
     * @param string|null $completion
     * @return array ['promptTokens'=>int,'completionTokens'=>int,'totalTokens'=>int]
     */
    public function estimate(string $providerName, ?string $model, string $prompt, ?string $completion = null): array;
}
