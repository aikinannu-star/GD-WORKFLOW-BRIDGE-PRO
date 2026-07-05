<?php

class ContextBudgeter
{
    private int $defaultMaxTokens;
    private int $defaultReserveTokens;

    public function __construct(int $defaultMaxTokens = 4000, int $defaultReserveTokens = 256)
    {
        $this->defaultMaxTokens = $defaultMaxTokens;
        $this->defaultReserveTokens = $defaultReserveTokens;
    }

    public function budget(array $sections, string $message = '', array $options = []): array
    {
        $maxTokens = (int)($options['maxTokens'] ?? $this->defaultMaxTokens);
        $reserveTokens = (int)($options['reserveTokens'] ?? $this->defaultReserveTokens);
        $messageTokens = $this->estimateTokens($message);
        $availableTokens = max(0, $maxTokens - $reserveTokens - $messageTokens);

        $budgetedSections = [];
        $skippedSections = [];
        $remainingTokens = $availableTokens;

        foreach ($sections as $section) {
            $content = (string)($section['content'] ?? '');
            if ($content === '') {
                continue;
            }

            $sectionTokens = $this->estimateTokens($content);
            $score = $this->scoreSection($section);
            $kept = false;
            $trimmed = false;
            $allocatedTokens = 0;

            if ($remainingTokens <= 0) {
                $kept = false;
            } elseif ($sectionTokens <= $remainingTokens) {
                $kept = true;
                $allocatedTokens = $sectionTokens;
            } elseif ($score >= 80) {
                $trimmedContent = $this->trimContentToBudget($content, $remainingTokens);
                $trimmedTokens = $this->estimateTokens($trimmedContent);
                if ($trimmedTokens > 0 && $trimmedTokens <= $remainingTokens) {
                    $content = $trimmedContent;
                    $sectionTokens = $trimmedTokens;
                    $kept = true;
                    $trimmed = true;
                    $allocatedTokens = $trimmedTokens;
                }
            }

            if ($kept) {
                $remainingTokens -= $allocatedTokens;
                $budgetedSection = $section;
                $budgetedSection['content'] = $content;
                $budgetedSection['metadata'] = array_merge($section['metadata'] ?? [], [
                    'budget' => [
                        'kept' => true,
                        'estimatedTokens' => $sectionTokens,
                        'allocatedTokens' => $allocatedTokens,
                        'trimmed' => $trimmed,
                        'priorityScore' => $score,
                    ],
                ]);
                $budgetedSections[] = $budgetedSection;
            } else {
                $skippedSections[] = $section;
                $budgetedSection = $section;
                $budgetedSection['metadata'] = array_merge($section['metadata'] ?? [], [
                    'budget' => [
                        'kept' => false,
                        'estimatedTokens' => $sectionTokens,
                        'allocatedTokens' => 0,
                        'trimmed' => false,
                        'priorityScore' => $score,
                    ],
                ]);
                $budgetedSections[] = $budgetedSection;
            }
        }

        $keptSections = array_values(array_filter($budgetedSections, static function (array $section): bool {
            return (bool)($section['metadata']['budget']['kept'] ?? false);
        }));

        return [
            'sections' => $keptSections,
            'metadata' => [
                'maxTokens' => $maxTokens,
                'reserveTokens' => $reserveTokens,
                'messageTokens' => $messageTokens,
                'availableTokens' => $availableTokens,
                'remainingTokens' => $remainingTokens,
                'skippedSections' => $skippedSections,
            ],
        ];
    }

    private function estimateTokens(string $text): int
    {
        $text = trim($text);
        if ($text === '') {
            return 0;
        }

        $words = preg_split('/\s+/', $text) ?: [];
        $wordCount = count(array_filter($words, static function ($word): bool {
            return $word !== '';
        }));

        return max(1, (int)ceil($wordCount * 1.3));
    }

    private function trimContentToBudget(string $content, int $maxTokens): string
    {
        $words = preg_split('/\s+/', trim($content)) ?: [];
        $maxWords = max(8, (int)ceil($maxTokens / 1.3));
        if (count($words) <= $maxWords) {
            return trim($content);
        }

        $trimmed = array_slice($words, 0, $maxWords);
        return implode(' ', $trimmed) . '…';
    }

    private function scoreSection(array $section): float
    {
        $priority = (float)($section['priority'] ?? 0);
        $relevance = (float)($section['metadata']['relevance'] ?? 0);
        $recency = (float)($section['metadata']['recency'] ?? 0);
        return $priority + ($relevance * 10.0) + ($recency * 5.0);
    }
}
