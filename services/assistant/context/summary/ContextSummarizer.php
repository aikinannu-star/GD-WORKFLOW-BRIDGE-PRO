<?php

class ContextSummarizer
{
    public function summarize(array $sections, string $message = '', array $options = []): string
    {
        $parts = [];
        foreach ($sections as $section) {
            $content = (string)($section['content'] ?? '');
            if ($content === '') {
                continue;
            }
            $parts[] = trim($content);
        }

        $combined = trim(implode(' ', $parts));
        if ($combined === '') {
            return 'No additional context.';
        }

        $maxTokens = (int)($options['maxTokens'] ?? 32);
        $words = preg_split('/\s+/', $combined) ?: [];
        $words = array_values(array_filter($words, static function ($word): bool {
            return $word !== '';
        }));

        $maxWords = max(8, (int)ceil($maxTokens / 1.3));
        $summaryWords = array_slice($words, 0, $maxWords);
        $summary = implode(' ', $summaryWords);
        if (count($words) > count($summaryWords)) {
            $summary .= '…';
        }

        return 'Summary for request: ' . $message . ' - ' . $summary;
    }
}
