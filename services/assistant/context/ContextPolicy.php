<?php

/**
 * Defines context window management policies
 * Specifies how conversations should be pruned, summarized, and truncated
 */
class ContextPolicy
{
    public string $name;
    public int $maxHistoryMessages; // Maximum number of messages to keep
    public int $maxContextTokens; // Maximum tokens in context window
    public int $summarizeAfterMessages; // Summarize when exceeding this
    public int $summarizeAfterTokens; // Summarize when exceeding this
    public bool $enableAutoSummarization; // Whether to automatically summarize
    public string $pruneStrategy; // 'oldest-first', 'keep-recent', 'smart-importance'
    public int $minimumSummaryGap; // Minimum messages between summaries
    public bool $keepSystemMessages; // Always preserve system messages
    public bool $keepUserFirstMessage; // Always preserve first user message
    public array $metadata; // Custom metadata

    public function __construct(
        string $name = 'default',
        int $maxHistoryMessages = 100,
        int $maxContextTokens = 4000,
        int $summarizeAfterMessages = 50,
        int $summarizeAfterTokens = 3000,
        bool $enableAutoSummarization = true,
        string $pruneStrategy = 'keep-recent',
        int $minimumSummaryGap = 10,
        bool $keepSystemMessages = true,
        bool $keepUserFirstMessage = true,
        array $metadata = []
    ) {
        $this->name = $name;
        $this->maxHistoryMessages = $maxHistoryMessages;
        $this->maxContextTokens = $maxContextTokens;
        $this->summarizeAfterMessages = $summarizeAfterMessages;
        $this->summarizeAfterTokens = $summarizeAfterTokens;
        $this->enableAutoSummarization = $enableAutoSummarization;
        $this->pruneStrategy = $pruneStrategy;
        $this->minimumSummaryGap = $minimumSummaryGap;
        $this->keepSystemMessages = $keepSystemMessages;
        $this->keepUserFirstMessage = $keepUserFirstMessage;
        $this->metadata = $metadata;
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'maxHistoryMessages' => $this->maxHistoryMessages,
            'maxContextTokens' => $this->maxContextTokens,
            'summarizeAfterMessages' => $this->summarizeAfterMessages,
            'summarizeAfterTokens' => $this->summarizeAfterTokens,
            'enableAutoSummarization' => $this->enableAutoSummarization,
            'pruneStrategy' => $this->pruneStrategy,
            'minimumSummaryGap' => $this->minimumSummaryGap,
            'keepSystemMessages' => $this->keepSystemMessages,
            'keepUserFirstMessage' => $this->keepUserFirstMessage,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Predefined policies for common scenarios
     */
    public static function compact(): self
    {
        return new self(
            name: 'compact',
            maxHistoryMessages: 20,
            maxContextTokens: 2000,
            summarizeAfterMessages: 15,
            summarizeAfterTokens: 1500,
            enableAutoSummarization: true,
            pruneStrategy: 'keep-recent',
            minimumSummaryGap: 5
        );
    }

    public static function balanced(): self
    {
        return new self(
            name: 'balanced',
            maxHistoryMessages: 50,
            maxContextTokens: 4000,
            summarizeAfterMessages: 30,
            summarizeAfterTokens: 3000,
            enableAutoSummarization: true,
            pruneStrategy: 'keep-recent',
            minimumSummaryGap: 10
        );
    }

    public static function generous(): self
    {
        return new self(
            name: 'generous',
            maxHistoryMessages: 200,
            maxContextTokens: 8000,
            summarizeAfterMessages: 100,
            summarizeAfterTokens: 6000,
            enableAutoSummarization: true,
            pruneStrategy: 'keep-recent',
            minimumSummaryGap: 20
        );
    }

    public static function unlimited(): self
    {
        return new self(
            name: 'unlimited',
            maxHistoryMessages: PHP_INT_MAX,
            maxContextTokens: PHP_INT_MAX,
            summarizeAfterMessages: PHP_INT_MAX,
            summarizeAfterTokens: PHP_INT_MAX,
            enableAutoSummarization: false,
            pruneStrategy: 'keep-recent',
            minimumSummaryGap: 0
        );
    }

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'] ?? 'default',
            maxHistoryMessages: $data['maxHistoryMessages'] ?? 100,
            maxContextTokens: $data['maxContextTokens'] ?? 4000,
            summarizeAfterMessages: $data['summarizeAfterMessages'] ?? 50,
            summarizeAfterTokens: $data['summarizeAfterTokens'] ?? 3000,
            enableAutoSummarization: $data['enableAutoSummarization'] ?? true,
            pruneStrategy: $data['pruneStrategy'] ?? 'keep-recent',
            minimumSummaryGap: $data['minimumSummaryGap'] ?? 10,
            keepSystemMessages: $data['keepSystemMessages'] ?? true,
            keepUserFirstMessage: $data['keepUserFirstMessage'] ?? true,
            metadata: $data['metadata'] ?? []
        );
    }
}
