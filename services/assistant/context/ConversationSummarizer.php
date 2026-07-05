<?php

require_once __DIR__ . '/../../lib/ServiceHelpers.php';

/**
 * Automatically summarizes conversation segments using the model provider
 */
class ConversationSummarizer
{
    private ModelProviderInterface $modelProvider;
    private ConversationSummaryRepositoryInterface $summaryRepository;
    private TokenEstimator $tokenEstimator;

    public function __construct(
        ModelProviderInterface $modelProvider,
        ConversationSummaryRepositoryInterface $summaryRepository,
        TokenEstimator $tokenEstimator = null
    ) {
        $this->modelProvider = $modelProvider;
        $this->summaryRepository = $summaryRepository;
        $this->tokenEstimator = $tokenEstimator ?? new TokenEstimator();
    }

    /**
     * Summarize a set of messages
     */
    public function summarizeMessages(string $conversationId, array $messagesToSummarize, int $fromIndex, int $toIndex): ?array
    {
        if (empty($messagesToSummarize)) {
            return null;
        }

        // Build summary prompt
        $prompt = $this->buildSummaryPrompt($messagesToSummarize);

        // Call model to generate summary
        try {
            $trace = ServiceHelpers::getTraceMetadata();
            $requestId = ServiceHelpers::getOrCreateRequestId();
            $options = [
                'trace' => $trace,
                'request_id' => $requestId,
                'conversation_id' => $conversationId,
                'tenant_id' => ServiceHelpers::getTenantContext(),
            ];
            $response = $this->modelProvider->chat($prompt, $options);

            $summaryText = $response['text'] ?? $response['content'] ?? '';

            if (empty($summaryText)) {
                return null;
            }

            // Create summary record
            $summary = [
                'conversationId' => $conversationId,
                'fromMessageIndex' => $fromIndex,
                'toMessageIndex' => $toIndex,
                'messageCount' => count($messagesToSummarize),
                'originalTokens' => $this->tokenEstimator->estimateHistoryTokens($messagesToSummarize),
                'summaryTokens' => $this->tokenEstimator->estimateTokens($summaryText),
                'summary' => $summaryText,
                'createdAt' => (new DateTime('now', new DateTimeZone('UTC')))->format(DateTime::ATOM),
            ];

            // Persist summary
            return $this->summaryRepository->save($conversationId, $summary);
        } catch (Exception $e) {
            // Log error and continue without summary
            error_log("Summarization failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Summarize conversation up to a certain point
     */
    public function summarizeConversation(
        string $conversationId,
        array $history,
        int $summarizeFrom = 0,
        int $summarizeTo = null
    ): ?array {
        if (empty($history)) {
            return null;
        }

        $summarizeTo = $summarizeTo ?? (count($history) - 1);

        // Extract messages to summarize
        $messagesToSummarize = array_slice($history, $summarizeFrom, $summarizeTo - $summarizeFrom + 1);

        return $this->summarizeMessages(
            $conversationId,
            $messagesToSummarize,
            $summarizeFrom,
            $summarizeTo
        );
    }

    /**
     * Rebuild conversation from history + summaries
     * Returns a condensed version suitable for context injection
     */
    public function rebuildWithSummaries(string $conversationId, array $history): array
    {
        $summaries = $this->summaryRepository->getAll($conversationId);

        if (empty($summaries)) {
            return $history;
        }

        $result = [];
        $lastIndex = 0;

        // For each summary, add unsummarized messages before it, then the summary
        foreach ($summaries as $summary) {
            $fromIndex = $summary['fromMessageIndex'];
            $toIndex = $summary['toMessageIndex'];

            // Add messages before this summary
            if ($fromIndex > $lastIndex) {
                $result = array_merge(
                    $result,
                    array_slice($history, $lastIndex, $fromIndex - $lastIndex)
                );
            }

            // Add system message representing the summary
            $result[] = [
                'role' => 'system',
                'content' => "Previous conversation summary:\n" . $summary['summary'],
                'metadata' => [
                    'type' => 'summary',
                    'fromMessageIndex' => $fromIndex,
                    'toMessageIndex' => $toIndex,
                    'originalTokens' => $summary['originalTokens'],
                    'summaryTokens' => $summary['summaryTokens'],
                    'compressed' => round(
                        (1 - $summary['summaryTokens'] / $summary['originalTokens']) * 100,
                        1
                    ) . '%',
                ]
            ];

            $lastIndex = $toIndex + 1;
        }

        // Add remaining messages after last summary
        if ($lastIndex < count($history)) {
            $result = array_merge($result, array_slice($history, $lastIndex));
        }

        return $result;
    }

    /**
     * Get optimal summarization points for a conversation
     */
    public function findSummarizationPoints(array $history, int $tokenBudget = 500): array
    {
        $points = [];
        $currentTokens = 0;
        $segmentStart = 0;

        foreach ($history as $index => $message) {
            $messageTokens = $this->tokenEstimator->estimateMessageTokens($message);
            $currentTokens += $messageTokens;

            // If we exceed budget, mark a summarization point
            if ($currentTokens > $tokenBudget && $index > $segmentStart) {
                $points[] = [
                    'fromIndex' => $segmentStart,
                    'toIndex' => $index - 1,
                    'messageCount' => $index - $segmentStart,
                    'tokens' => $currentTokens - $messageTokens,
                ];

                $segmentStart = $index;
                $currentTokens = $messageTokens;
            }
        }

        // Add final segment if not empty
        if ($segmentStart < count($history)) {
            $points[] = [
                'fromIndex' => $segmentStart,
                'toIndex' => count($history) - 1,
                'messageCount' => count($history) - $segmentStart,
                'tokens' => $currentTokens,
            ];
        }

        return $points;
    }

    /**
     * Build a prompt for summarization
     */
    private function buildSummaryPrompt(array $messages): string
    {
        $conversation = "Please summarize the following conversation concisely, preserving key decisions and context:\n\n";

        foreach ($messages as $message) {
            $role = strtoupper($message['role'] ?? 'unknown');
            $content = $message['content'] ?? '';
            $conversation .= "{$role}: {$content}\n";
        }

        $conversation .= "\nProvide a clear, concise summary in 2-3 sentences.";

        return $conversation;
    }
}
