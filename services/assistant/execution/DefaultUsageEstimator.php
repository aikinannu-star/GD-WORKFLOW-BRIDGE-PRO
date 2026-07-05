<?php

require_once __DIR__ . '/UsageEstimatorInterface.php';

class DefaultUsageEstimator implements UsageEstimatorInterface
{
    private float $tokensPerWord;

    public function __construct(float $tokensPerWord = 1.3)
    {
        $this->tokensPerWord = $tokensPerWord;
    }

    public function estimate(string $providerName, ?string $model, string $prompt, ?string $completion = null): array
    {
        $promptWords = str_word_count($prompt);
        $promptTokens = (int)ceil($promptWords * $this->tokensPerWord);
        $completionTokens = 0;
        if ($completion !== null) {
            $completionWords = str_word_count($completion);
            $completionTokens = (int)ceil($completionWords * $this->tokensPerWord);
        }
        return [
            'promptTokens' => $promptTokens,
            'completionTokens' => $completionTokens,
            'totalTokens' => $promptTokens + $completionTokens,
            'source' => 'estimated'
        ];
    }
}
