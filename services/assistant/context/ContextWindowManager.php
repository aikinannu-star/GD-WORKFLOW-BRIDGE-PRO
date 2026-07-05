<?php

/**
 * Manages conversation context windows with token budgeting, pruning, and summarization
 * Ensures conversations stay within model limits while preserving important context
 */
class ContextWindowManager
{
    private TokenEstimator $tokenEstimator;
    private ConversationSummarizer $summarizer;
    private ContextPolicy $policy;

    public function __construct(
        TokenEstimator $tokenEstimator,
        ConversationSummarizer $summarizer,
        ContextPolicy $policy = null
    ) {
        $this->tokenEstimator = $tokenEstimator;
        $this->summarizer = $summarizer;
        $this->policy = $policy ?? new ContextPolicy();
    }

    /**
     * Apply context management to a conversation history
     * Returns pruned/summarized history suitable for model context
     */
    public function applyContextManagement(string $conversationId, array $history, int $tokenBudget = null): array
    {
        $tokenBudget = $tokenBudget ?? $this->policy->maxContextTokens;

        // Check if pruning is needed
        $currentTokens = $this->tokenEstimator->estimateHistoryTokens($history);

        if ($currentTokens <= $tokenBudget) {
            return $history;
        }

        // Apply context management strategy
        if ($this->policy->enableAutoSummarization) {
            return $this->pruneWithSummarization($conversationId, $history, $tokenBudget);
        } else {
            return $this->pruneWithoutSummarization($history, $tokenBudget);
        }
    }

    /**
     * Prune history by removing old messages
     */
    private function pruneWithoutSummarization(array $history, int $tokenBudget): array
    {
        $result = [];
        $currentTokens = 0;

        // Preserve important messages
        $importantIndices = $this->findImportantMessages($history);

        // Work backwards from most recent
        for ($i = count($history) - 1; $i >= 0; $i--) {
            $message = $history[$i];
            $tokens = $this->tokenEstimator->estimateMessageTokens($message);

            if ($currentTokens + $tokens <= $tokenBudget) {
                array_unshift($result, $message);
                $currentTokens += $tokens;
            } elseif (in_array($i, $importantIndices)) {
                // Force include important messages even if over budget
                array_unshift($result, $message);
                $currentTokens += $tokens;
            }
        }

        return $result;
    }

    /**
     * Prune with summarization of older segments
     */
    private function pruneWithSummarization(string $conversationId, array $history, int $tokenBudget): array
    {
        // First try to summarize older segments
        $summarizationPoints = $this->summarizer->findSummarizationPoints($history, $this->policy->summarizeAfterTokens);

        // Summarize all but the most recent segment
        for ($i = 0; $i < count($summarizationPoints) - 1; $i++) {
            $point = $summarizationPoints[$i];
            $messagesToSummarize = array_slice($history, $point['fromIndex'], $point['toIndex'] - $point['fromIndex'] + 1);

            $this->summarizer->summarizeMessages(
                $conversationId,
                $messagesToSummarize,
                $point['fromIndex'],
                $point['toIndex']
            );
        }

        // Rebuild with summaries
        $withSummaries = $this->summarizer->rebuildWithSummaries($conversationId, $history);

        // If still over budget, prune
        $tokens = $this->tokenEstimator->estimateHistoryTokens($withSummaries);
        if ($tokens > $tokenBudget) {
            return $this->pruneWithoutSummarization($withSummaries, $tokenBudget);
        }

        return $withSummaries;
    }

    /**
     * Find messages that should be preserved during pruning
     */
    private function findImportantMessages(array $history): array
    {
        $important = [];

        foreach ($history as $index => $message) {
            $role = $message['role'] ?? '';
            $content = $message['content'] ?? '';

            // Always keep system messages
            if ($this->policy->keepSystemMessages && $role === 'system') {
                $important[] = $index;
            }

            // Always keep first user message
            if ($this->policy->keepUserFirstMessage && $role === 'user' && $index === 0) {
                $important[] = $index;
            }

            // Keep messages with tool calls
            if (!empty($message['tool_calls'])) {
                $important[] = $index;
            }

            // Keep most recent user message
            if ($role === 'user' && $index === count($history) - 1) {
                $important[] = $index;
            }

            // Keep messages with metadata indicating importance
            if (!empty($message['metadata']['important'])) {
                $important[] = $index;
            }
        }

        return array_unique($important);
    }

    /**
     * Get context statistics
     */
    public function getContextStats(array $history): array
    {
        return [
            'messageCount' => count($history),
            'totalTokens' => $this->tokenEstimator->estimateHistoryTokens($history),
            'estimatedCost' => $this->tokenEstimator->getConversationCost($history),
            'avgTokensPerMessage' => count($history) > 0
                ? round($this->tokenEstimator->estimateHistoryTokens($history) / count($history), 1)
                : 0,
            'oldestMessage' => $history[0]['timestamp'] ?? null,
            'newestMessage' => end($history)['timestamp'] ?? null,
        ];
    }

    /**
     * Check if context management is needed
     */
    public function needsContextManagement(array $history, int $tokenBudget = null): bool
    {
        $tokenBudget = $tokenBudget ?? $this->policy->maxContextTokens;
        $tokens = $this->tokenEstimator->estimateHistoryTokens($history);
        return $tokens > $tokenBudget;
    }

    /**
     * Get recommended next action
     */
    public function getRecommendedAction(array $history, int $tokenBudget = null): ?string
    {
        $tokenBudget = $tokenBudget ?? $this->policy->maxContextTokens;
        $messageCount = count($history);
        $tokens = $this->tokenEstimator->estimateHistoryTokens($history);

        if ($messageCount > $this->policy->maxHistoryMessages) {
            return 'trim_messages';
        }

        if ($tokens > $tokenBudget) {
            if ($this->policy->enableAutoSummarization) {
                return 'summarize_and_trim';
            } else {
                return 'trim_only';
            }
        }

        if ($messageCount > $this->policy->summarizeAfterMessages || $tokens > $this->policy->summarizeAfterTokens) {
            if ($this->policy->enableAutoSummarization) {
                return 'consider_summarization';
            }
        }

        return null;
    }

    /**
     * Update policy
     */
    public function setPolicy(ContextPolicy $policy): void
    {
        $this->policy = $policy;
    }

    /**
     * Get current policy
     */
    public function getPolicy(): ContextPolicy
    {
        return $this->policy;
    }
}
