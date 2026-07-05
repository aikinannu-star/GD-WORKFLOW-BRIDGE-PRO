<?php

require_once __DIR__ . '/OptimizationReport.php';

class PromptOptimizationResult
{
    private string $prompt;
    private string $format;
    private array $metadata;
    private ?OptimizationReport $optimizationReport;

    public function __construct(string $prompt, string $format = 'plain', array $metadata = [], ?OptimizationReport $optimizationReport = null)
    {
        $this->prompt = $prompt;
        $this->format = $format;
        $this->metadata = $metadata;
        $this->optimizationReport = $optimizationReport;
    }

    public function getPrompt(): string
    {
        return $this->prompt;
    }

    public function getFormat(): string
    {
        return $this->format;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getOptimizationReport(): ?OptimizationReport
    {
        return $this->optimizationReport;
    }
}
