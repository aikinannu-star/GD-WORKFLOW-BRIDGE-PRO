<?php

/**
 * Estimates token count for text using simple word-based approximation
 * For production, integrate with actual tokenizer (e.g., GPT-3 tokenizer, SentencePiece)
 */
class TokenEstimator
{
    private float $tokensPerWord = 1.3; // Average tokens per word (GPT-3 ~1.3)

    public function __construct(float $tokensPerWord = 1.3)
    {
        $this->tokensPerWord = $tokensPerWord;
    }

    /**
     * Estimate tokens in a string
     */
    public function estimateTokens(string $text): int
    {
        // Simple approximation: count words and multiply by token ratio
        $wordCount = count(preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY));
        return max(1, (int)ceil($wordCount * $this->tokensPerWord));
    }

    /**
     * Estimate tokens in a message object
     */
    public function estimateMessageTokens(array $message): int
    {
        $tokens = 0;
        
        // Content
        if (!empty($message['content'])) {
            $tokens += $this->estimateTokens($message['content']);
        }

        // Role and metadata (rough estimate)
        $tokens += 5;

        // Tool calls if present
        if (!empty($message['tool_calls'])) {
            foreach ((array)$message['tool_calls'] as $call) {
                $tokens += $this->estimateTokens(json_encode($call));
            }
        }

        return $tokens;
    }

    /**
     * Estimate tokens in entire message history
     */
    public function estimateHistoryTokens(array $history): int
    {
        $total = 0;
        foreach ($history as $message) {
            $total += $this->estimateMessageTokens($message);
        }
        return $total;
    }

    /**
     * Calculate space remaining given a budget
     */
    public function getRemainingTokens(array $history, int $budget): int
    {
        $used = $this->estimateHistoryTokens($history);
        return max(0, $budget - $used);
    }

    /**
     * Get token cost estimate for a message (for billing/analytics)
     */
    public function getMessageCost(array $message, float $costPerToken = 0.000015): float
    {
        $tokens = $this->estimateMessageTokens($message);
        return $tokens * $costPerToken;
    }

    /**
     * Get token cost for entire conversation
     */
    public function getConversationCost(array $history, float $costPerToken = 0.000015): float
    {
        $tokens = $this->estimateHistoryTokens($history);
        return $tokens * $costPerToken;
    }
}
