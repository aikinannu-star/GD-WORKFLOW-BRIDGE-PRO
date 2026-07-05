<?php

/**
 * Stores and retrieves conversation summaries
 */
interface ConversationSummaryRepositoryInterface
{
    /**
     * Save a summary of a conversation segment
     */
    public function save(string $conversationId, array $summary): array;

    /**
     * Get the most recent summary for a conversation
     */
    public function getLatest(string $conversationId): ?array;

    /**
     * Get all summaries for a conversation (chronological)
     */
    public function getAll(string $conversationId): array;

    /**
     * Get summaries after a certain message index
     */
    public function getSummariesAfter(string $conversationId, int $messageIndex): array;

    /**
     * Delete old summaries (for cleanup)
     */
    public function deleteOlderThan(string $conversationId, string $dateTime): int;
}

/**
 * File-based implementation of ConversationSummaryRepository
 */
class FileConversationSummaryRepository implements ConversationSummaryRepositoryInterface
{
    private string $basePath;

    public function __construct(string $basePath = null)
    {
        $this->basePath = $basePath ?: __DIR__ . '/../../data/assistant/summaries';
        if (!is_dir($this->basePath)) {
            mkdir($this->basePath, 0775, true);
        }
    }

    private function sanitize(string $id): string
    {
        return preg_replace('/[^a-z0-9_\-]/i', '_', $id);
    }

    private function conversationPath(string $id): string
    {
        return $this->basePath . DIRECTORY_SEPARATOR . $this->sanitize($id);
    }

    private function summaryPath(string $conversationId, int $index): string
    {
        $dir = $this->conversationPath($conversationId);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir . DIRECTORY_SEPARATOR . sprintf('summary_%04d.json', $index);
    }

    public function save(string $conversationId, array $summary): array
    {
        $summary['conversationId'] = $conversationId;
        $summary['savedAt'] = $summary['savedAt'] ?? (new DateTime('now', new DateTimeZone('UTC')))->format(DateTime::ATOM);

        // Find next index
        $dir = $this->conversationPath($conversationId);
        $index = 1;
        if (is_dir($dir)) {
            $files = glob($dir . '/summary_*.json');
            if (!empty($files)) {
                usort($files, function ($a, $b) {
                    return filemtime($b) - filemtime($a); // Most recent first
                });
                preg_match('/summary_(\d+)/', basename($files[0]), $matches);
                $index = (int)$matches[1] + 1;
            }
        }

        $path = $this->summaryPath($conversationId, $index);
        file_put_contents($path, json_encode($summary, JSON_PRETTY_PRINT));
        
        return $summary + ['index' => $index];
    }

    public function getLatest(string $conversationId): ?array
    {
        $dir = $this->conversationPath($conversationId);
        if (!is_dir($dir)) {
            return null;
        }

        $files = glob($dir . '/summary_*.json');
        if (empty($files)) {
            return null;
        }

        usort($files, function ($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        $content = file_get_contents($files[0]);
        return json_decode($content, true);
    }

    public function getAll(string $conversationId): array
    {
        $dir = $this->conversationPath($conversationId);
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . '/summary_*.json');
        usort($files, function ($a, $b) {
            return filemtime($a) - filemtime($b); // Oldest first
        });

        $summaries = [];
        foreach ($files as $file) {
            $content = file_get_contents($file);
            $summaries[] = json_decode($content, true);
        }

        return $summaries;
    }

    public function getSummariesAfter(string $conversationId, int $messageIndex): array
    {
        $allSummaries = $this->getAll($conversationId);
        return array_filter($allSummaries, function ($summary) use ($messageIndex) {
            return ($summary['fromMessageIndex'] ?? 0) > $messageIndex;
        });
    }

    public function deleteOlderThan(string $conversationId, string $dateTime): int
    {
        $dir = $this->conversationPath($conversationId);
        if (!is_dir($dir)) {
            return 0;
        }

        $targetTime = strtotime($dateTime);
        $files = glob($dir . '/summary_*.json');
        $deleted = 0;

        foreach ($files as $file) {
            if (filemtime($file) < $targetTime) {
                unlink($file);
                $deleted++;
            }
        }

        return $deleted;
    }
}
